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

// daily reports to gather info from.
//   product as key, array of versions as value

$daily = array(
    'Firefox' => array('10.0.1esr', '10.0.2esr', '10.0.3esr', '10.0.4esr', '10.0.5esr',
                       '10.0', '10.0.1', '10.0.2',
                       '11.0b1', '11.0b2', '11.0b3', '11.0b4', '11.0b5', '11.0b6', '11.0b7', '11.0b8', '11.0',
                       '12.0a2', '12.0b1', '12.0b2', '12.0b3', '12.0b4', '12.0b5', '12.0b6', '12.0',
                       '13.0a1', '13.0a2', '13.0b1', '13.0b2', '13.0b3', '13.0b4', '13.0b5', '13.0b6', '13.0b7', '13.0', '13.0.1',
                       '14.0a1', '14.0a2', '14.0b6', '14.0b7', '14.0b8', '14.0b9', '14.0b10', '14.0b11', '14.0b12',
                       '15.0a1', '15.0a2',
                       '16.0a1'),
    'Fennec' => array('10.0.3esr', '10.0.4esr', '10.0.5esr',
                      '10.0', '10.0.1', '10.0.2',
                      '11.0b1', '11.0b2', '11.0b3', '11.0b4', '11.0b5', '11.0b6',
                      '12.0a2', '12.0b1', '12.0b2', '12.0b3', '12.0b4', '12.0b5', '12.0b6',
                      '13.0a1', '13.0a2', '13.0b1', '13.0b2', '13.0b3',
                      '14.0a1', '14.0a2', '14.0b1', '14.0b2', '14.0b6', '14.0b7', '14.0b8', '14.0b10', '14.0b11', '14.0b12', '14.0',
                      '15.0a1', '15.0a2',
                      '16.0a1'),
    'FennecAndroid' => array('11.0b1', '11.0b2', '12.0a2',
                             '13.0a1', '13.0a2',
                             '14.0a1', '14.0a2', '14.0b1', '14.0b2', '14.0b3', '14.0b4', '14.0b5', '14.0b6', '14.0b7', '14.0b8', '14.0b10', '14.0b11', '14.0b12', '14.0',
                             '15.0a1', '15.0a2',
                             '16.0a1'),
);

// for how many days back to get the data
$backlog_days = 20;

// *** URLs and paths ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }


// *** code start ***

// Get start and end dates
$day_start = date('Y-m-d', strtotime('15 days ago'));
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

foreach ($daily as $product=>$versions) {
  $fproddata = $product.'-daily.json';

  if (file_exists($fproddata)) {
    print('Read stored '.$product.' daily data'."\n");
    $proddata = json_decode(file_get_contents($fproddata), true);
  }
  else {
    $proddata = array();
  }

  print('Fetch daily data for '.$product.' '.implode(', ', $versions)."\n");
  // See https://bugzilla.mozilla.org/show_bug.cgi?id=733489#c1
  // for example queries to get /daily numbers.

  /* Query for numbers that exactly match /daily (only matching major OSes):
    SELECT adu_date, product_name as Product, version_string as Version,
          SUM(adjusted_crashes) as Crashes, SUM(adu_count) as ADU
    FROM product_os_crash_ratio
    WHERE product_name = 'Firefox'
          and version_string = '11.0'
          and adu_date between '2012-04-20' and '2012-04-26'
          and os_name in ('Windows', 'Mac OS X', 'Linux')
    GROUP BY adu_date, Product, Version
    ORDER BY adu_date desc;
  */

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
    if ($crashes && $adu) {
      $proddata[$ver][$day] = array('crashes' => $crashes,
                                    'adu' => $adu);
    }
  }
  file_put_contents($fproddata, json_encode($proddata));
}
?>
