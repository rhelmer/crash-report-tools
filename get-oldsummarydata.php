#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves old summary crash data from CSV files.

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

$startdate = strtotime('2010-02-01');
$enddate = strtotime('2010-02-01');

// We only support Release and Beta!
$channels = array('Release');

// *** URLs and paths ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_csvbase = $on_moz_server?'/mnt/crashanalysis/crash_analysis/'
                             :'http://people.mozilla.com/crash_analysis/';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

foreach ($channels as $channel) {
  $fprodtypedata = 'Firefox-'.strtolower($channel).'-old-bytype.json';

  // We only support Release and Beta!
  $ver_regex = ($channel == 'Release')?'/^\d+(\.\d+)+$/':'/^\d+(\.\d+)+b\d+$/';
  $max_build_age = ($channel == 'Release')?'9 weeks':'3 weeks';

  if (file_exists($fprodtypedata)) {
    print('Read stored Firefox '.$channel.' summary data'."\n");
    $prodtypedata = json_decode(file_get_contents($fprodtypedata), true);
  }
  else {
    $prodtypedata = array();
  }

  for ($anatime = $startdate; $anatime <= $enddate; $anatime = strtotime(date('Y-m-d', $anatime).' +1 day')) {
    $anaday = date('Y-m-d', $anatime);
    print('Looking at data for '.$anaday."\n");

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';

    // Make sure we have the crashdata csv.
    if ($on_moz_server) {
      $anafcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
      if (!file_exists($anafcsvgz)) { break; }
    }
    else {
      $anafcsv = $fcsv;
      if (!file_exists($anafcsv)) {
        print('Fetching '.$anafcsv.' from the web'."\n");
        $webcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
        if (copy($webcsvgz, $anafcsv.'.gz')) { shell_exec('gzip -d '.$anafcsv.'.gz'); }
      }
      if (!file_exists($anafcsv)) { break; }
    }

    $min_builddate = strtotime(date('Y-m-d', $anatime).' -'.$max_build_age);

    // Get data for the product and channel.
    if (!array_key_exists($anaday, $prodtypedata)) {
      $prodtypedata[$anaday] = array();

      print('Getting Firefox '.$channel.' data for '.$anaday."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $7 is product, $8 is version, $9 is build, $23 is hang id, $25 is process_type, $29 is release_channel
      $cmd = 'awk \'-F\t\' \'$7 ~ /^Firefox$/'
//             .' && $29 ~ /^'.awk_quote($channel, '/')
            .' {printf ",%s,%s,%s,%s,%s\n",$7,$8,$9,$25,($23!="\\\\N")}\'';
      if ($on_moz_server) {
        $return = shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' | sort | uniq -c');
      }
      else {
        $return = shell_exec($cmd.' '.$anafcsv.' | sort | uniq -c');
      }

      // Filter and sum up the data.
      foreach (explode("\n", $return) as $line) {
        $fields = explode(',', $line);
        $count = intval($fields[0]);
        if ($count) {
          $product = trim($fields[1]);
          $version = trim($fields[2]);
          $builddate = strtotime(substr(trim($fields[3]), 0, 4).'-'.substr(trim($fields[3]), 4, 2).'-'.substr(trim($fields[3]), 6, 2));
          $ptype = trim($fields[4]);
          $is_hang = strlen($ptype)?(!!intval($fields[5])):false;
          if ($ptype == '\\N' || $ptype =='') { $ptype = 'browser'; }
          if (preg_match($ver_regex, $version) && $builddate > $min_builddate && (!$is_hang || $ptype == 'plugin')) {
            $type = ucfirst($ptype);
            // Create a type that matches what Socorro has internally nowadays.
            if ($ptype == 'plugin') { $type = ($is_hang?'Hang ':'OOP ').$type; }
            // Add info to array.
            if (!array_key_exists('crashes', $prodtypedata[$anaday])) {
              $prodtypedata[$anaday]['crashes'] = array();
            }
            if (!array_key_exists($type, $prodtypedata[$anaday]['crashes'])) {
              $prodtypedata[$anaday]['crashes'][$type] = $count;
            }
            else {
              $prodtypedata[$anaday]['crashes'][$type] += $count;
            }
            if (!array_key_exists('versions', $prodtypedata[$anaday])) {
              $prodtypedata[$anaday]['versions'] = array($version);
            }
            elseif (!in_array($version, $prodtypedata[$anaday]['versions'])) {
              $prodtypedata[$anaday]['versions'][] = $version;
            }
          }
        }
      }
    }
  }
  file_put_contents($fprodtypedata, json_encode($prodtypedata));
}

// *** helper functions ***


?>
