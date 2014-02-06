#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves explosiveness stats for crash signatures.

// *** non-commandline handling ***

if (php_sapi_name() != 'cli') {
  // not commandline, assume apache and output own source
  header('Content-Type: text/plain; charset=utf8');
  print(file_get_contents($_SERVER['SCRIPT_FILENAME']));
  exit;
}

include_once('datautils.php');

// *** script settings ***

// turn on error reporting in the script output
ini_set('display_errors', 1);

// make sure new files are set to -rw-r--r-- permissions
umask(022);

// set default time zone - right now, always the one the server is in!
date_default_timezone_set('America/Los_Angeles');


// *** data gathering variables ***

// products to gather data from
$products = array('Firefox', 'MetroFirefox', 'Fennec', 'FennecAndroid');

// products and channels to gather data per-type from
$prodchannels = array('Firefox' => array('release', 'beta'),
                      'FennecAndroid' => array('release', 'beta'));

// for how many days back to get the data
$backlog_days = 15;

// *** URLs and paths ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }


// *** code start ***

// Get start and end dates
$day_start = date('Y-m-d', strtotime($backlog_days.' days ago'));
$day_end = date('Y-m-d', strtotime('yesterday'));

if (file_exists($fdbsecret)) {
  $dbsecret = json_decode(file_get_contents($fdbsecret), true);
  if (!is_array($dbsecret) || !count($dbsecret)) {
    print('ERROR: No DB secrets found, aborting!'."\n");
    exit(1);
  }
  $db_conn = pg_pconnect('host='.$dbsecret['host']
                         .' port='.$dbsecret['port']
                         .' dbname=breakpad'
                         .' user='.$dbsecret['user']
                         .' password='.$dbsecret['password']);
  if (!$db_conn) {
    print('ERROR: DB connection failed, aborting!'."\n");
    exit(1);
  }
  // For info on what data can be accessed, see also
  // http://socorro.readthedocs.org/en/latest/databasetabledesc.html
  // For the DB schema, see
  // https://github.com/mozilla/socorro/blob/master/sql/schema.sql
}
else {
  // Won't work! (Set just for documenting what fields are in the file.)
  $dbsecret = array('host' => 'host.m.c', 'port' => '6432',
                    'user' => 'analyst', 'password' => 'foo');
  print('ERROR: No DB secrets found, aborting!'."\n");
  exit(1);
}

foreach ($products as $product) {
  $fproddata = $product.'-daily.json';

  if (file_exists($fproddata)) {
    print('Read stored '.$product.' daily data'."\n");
    $proddata = json_decode(file_get_contents($fproddata), true);
  }
  else {
    $proddata = array();
  }

  // Get all active versions for that product.
  $versions = array();
  $ver_query =
    'SELECT version_string '
    .'FROM product_versions '
    ."WHERE product_name = '".$product."'"
    ." AND sunset_date > '".$day_start."';";
  $ver_result = pg_query($db_conn, $ver_query);
  if (!$ver_result) {
    print('--- ERROR: versions query failed!'."\n");
  }
  else {
    while ($ver_row = pg_fetch_array($ver_result)) {
      $versions[] = $ver_row['version_string'];
    }
  }

  print('Fetch daily data for '.$product.' '.implode(', ', $versions)."\n");
  // See https://bugzilla.mozilla.org/show_bug.cgi?id=733489#c1
  // for example queries to get /daily numbers.

  /* Query for numbers that exactly match /daily (only matching major OSes):
    SELECT adu_date, product_name as Product, version_string as Version,
          SUM(adjusted_crashes) as Crashes, SUM(adu_count) as ADU
    FROM product_os_crash_ratio
    WHERE product_name = 'Firefox'
          and version_string = '17.0'
          and adu_date between '2012-12-24' and '2013-01-02'
          and os_name in ('Windows', 'Mac OS X', 'Linux')
    GROUP BY adu_date, Product, Version
    ORDER BY adu_date desc;
  */

  $maxday = null;

  $db_query = 'SELECT adu_date, version_string as version, '
              .'adjusted_crashes as crashes, adu_count as adu '
              .'FROM product_crash_ratio '
              ."WHERE product_name = '".$product."' "
              ."AND version_string IN ('".implode("','", $versions)."') "
              ."AND adu_date BETWEEN '".$day_start."' AND '".$day_end."' "
              .'ORDER BY adu_date DESC, version DESC;';

  $result = pg_query($db_conn, $db_query);
  if (!$result) {
    print('--- ERROR: query failed!'."\n");
  }

  while ($row = pg_fetch_array($result)) {
    $ver = $row['version'];
    $day = $row['adu_date'];
    $crashes = intval($row['crashes']);
    $adu = intval($row['adu']);
    if ($crashes || $adu) {
      $proddata[$ver][$day] = array('crashes' => $crashes,
                                    'adu' => $adu);
    }
    if (is_null($maxday) || $maxday < $day) { $maxday = $day; }
  }
  if ($maxday < $day_end) {
    print('--- ERROR: Last day retrieved is '.$maxday.' while yesterday was '.$day_end.'!'."\n");
  }
  file_put_contents($fproddata, json_encode($proddata));
}

// uncomment for backfilling
// $day_start = '2011-01-01';

foreach ($prodchannels as $product=>$channels) {
  foreach ($channels as $channel) {
    $fprodtypedata = $product.'-'.$channel.'-bytype.json';

    if (file_exists($fprodtypedata)) {
      print('Read stored '.$product.' '.ucfirst($channel).' per-type data'."\n");
      $prodtypedata = json_decode(file_get_contents($fprodtypedata), true);
    }
    else {
      $prodtypedata = array();
    }

    print('Fetch per-type daily data for '.$product.' '.ucfirst($channel)."\n");
    /* Query for numbers per crash type for "recent releases":
      SELECT product_versions.product_name,product_versions.version_string,crashes_by_user.report_date,crash_types.crash_type,SUM(report_count) AS crashes, SUM(adu) AS adi
      FROM crashes_by_user JOIN product_versions ON (crashes_by_user.product_version_id=product_versions.product_version_id) JOIN crash_types ON (crashes_by_user.crash_type_id=crash_types.crash_type_id)
      WHERE product_versions.product_name = 'Firefox' AND product_versions.build_type='Release' AND crashes_by_user.report_date<(product_versions.build_date + interval '9 weeks') AND crashes_by_user.report_date BETWEEN '2013-10-22' AND '2013-10-30'
      GROUP BY product_versions.product_name,product_versions.version_string,crashes_by_user.report_date,crash_types.crash_type
      ORDER BY report_date DESC;
    */

    $maxday = null;

    $max_build_age = getMaxBuildAge($channel, true);

    $db_query = 'SELECT crashes_by_user.report_date, crash_types.crash_type, '
                .'SUM(crashes_by_user.report_count) AS crashes, SUM(crashes_by_user.adu) AS adi, '
                ."string_agg(product_versions.version_string,',') as versions "
                .'FROM crashes_by_user JOIN product_versions'
                .' ON (crashes_by_user.product_version_id=product_versions.product_version_id)'
                .' JOIN crash_types ON (crashes_by_user.crash_type_id=crash_types.crash_type_id) '
                ."WHERE product_versions.product_name = '".$product."'"
                ." AND product_versions.build_type='".$channel."'"
                ." AND product_versions.is_rapid_beta='f'"
                .(($product == 'Firefox')?" AND major_version!='3.6'":'')  // 3.6 has ADI but no crashes and disturbs the stats.
                ." AND crashes_by_user.report_date < (product_versions.build_date + interval '".$max_build_age."')"
                ." AND crashes_by_user.report_date BETWEEN '".$day_start."' AND '".$day_end."' "
                .'GROUP BY crashes_by_user.report_date, crash_types.crash_type '
                .'ORDER BY crashes_by_user.report_date ASC;';

    $result = pg_query($db_conn, $db_query);
    if (!$result) {
      print('--- ERROR: query failed!'."\n");
    }

    while ($row = pg_fetch_array($result)) {
      $day = $row['report_date'];
      $type = $row['crash_type'];
      $crashes = intval($row['crashes']) * (($product == 'Firefox' && $channel == 'release') ? 10 : 1);
      $adi = intval($row['adi']);
      // The SQL query can give us the same version multiple times, so we take
      // out redundant elements with array_unique and then do a primitive sort
      // for re-setting array indexes and giving a consistent output.
      $versions = array_unique(explode(',', $row['versions']));
      sort($versions);
      if ($crashes || $adi) {
        $prodtypedata[$day]['versions'] = array_values($versions);
        $prodtypedata[$day]['adi'] = $adi;
        if (!array_key_exists('crashes', $prodtypedata[$day])) {
          $prodtypedata[$day]['crashes'] = array();
        }
        if ($crashes && ($type != 'Hang Browser')) {
          $prodtypedata[$day]['crashes'][$type] = $crashes;
        }
      }
      if (is_null($maxday) || $maxday < $day) { $maxday = $day; }
    }
    if ($maxday < $day_end) {
      print('--- ERROR: Last day retrieved is '.$maxday.' while yesterday was '.$day_end.'!'."\n");
    }
    file_put_contents($fprodtypedata, json_encode($prodtypedata));
  }
}
print("\n");
?>
