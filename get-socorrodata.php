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
    'Firefox' => array('10.0.1esr', '10.0.2esr', '10.0.3esr', '10.0.4esr',
                       '10.0', '10.0.1', '10.0.2',
                       '11.0b1', '11.0b2', '11.0b3', '11.0b4', '11.0b5', '11.0b6', '11.0b7', '11.0b8', '11.0',
                       '12.0a2', '12.0b1', '12.0b2', '12.0b3', '12.0b4', '12.0b5', '12.0b6', '12.0',
                       '13.0a1', '13.0a2', '13.0b1',
                       '14.0a1', '14.0a2',
                       '15.0a1'),
    'Fennec' => array('10.0.3esr', '10.0.4esr',
                      '10.0', '10.0.1', '10.0.2',
                      '11.0b1', '11.0b2', '11.0b3', '11.0b4', '11.0b5', '11.0b6',
                      '12.0a2', '12.0b1', '12.0b2', '12.0b3', '12.0b4', '12.0b5', '12.0b6',
                      '13.0a1', '13.0a2', '13.0b1',
                      '14.0a1', '14.0a2',
                      '15.0a1'),
    'FennecAndroid' => array('11.0b1', '11.0b2', '12.0a2',
                             '13.0a1', '13.0a2',
                             '14.0a1', '14.0a2',
                             '15.0a1'),
);

// for how many days back to get the data
$backlog_days = 20;

// *** URLs and paths ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
// First %s is product, second is version.
$url_daily_mask = 'https://crash-stats.mozilla.com/daily?p=%s&v[]=%s&csv=1';
//https://crash-stats.mozilla.com/daily?p=Firefox&v[]=10.0.1&os[]=Windows&os[]=Mac&os[]=Linux&date_start=2012-02-02&date_end=2012-02-16&form_selection=by_version&csv=1&hang_type=any

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }


// *** code start ***

// Get current day.
$curtime = time();

if (file_exists($fdbsecret)) {
  $dbsecret = json_decode(file_get_contents($fdbsecret), true);
  // For info on what data can be accessed, see also
  // http://socorro.readthedocs.org/en/latest/databasetabledesc.html
}
else {
  // Won't work! (Set just for documenting what fields are in the file.)
  $dbsecret = array("host" => "host.m.c", "port" => "6432",
                    "user" => "analyst", "password" => "foo");
  print('ERROR: No DB secrests found, aborting!'."\n");
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

  foreach ($versions as $ver) {
    print('Fetch daily data for '.$product.' '.$ver."\n");
    $dailycsv = file(sprintf($url_daily_mask, $product, $ver));
    foreach ($dailycsv as $csvline) {
      $fields = explode(',', $csvline);
      if ($fields[0] == 'Date') {
        // This is the first line, check if this is the right data
        if ($fields[1] != $product.' '.$ver.' Crashes') {
          print('--- ERROR: Got '.$fields[1].' instead!'."\n");
          break;
        }
      }
      elseif (preg_match('/^\d+-\d+-\d+$/', $fields[0])) {
        $day = $fields[0];
        $crashes = intval($fields[1]);
        $adu = intval($fields[2]);
        if ($crashes && $adu) {
          $proddata[$ver][$day] = array('crashes' => $crashes,
                                        'adu' => $adu);
        }
      }
    }
  }
  file_put_contents($fproddata, json_encode($proddata));
}

?>
