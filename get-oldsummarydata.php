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

require('vendor/autoload.php');
$s3 = Aws\S3\S3Client::factory(array('region' => 'us-west-2'));
$bucket = getenv('S3_BUCKET')?: die('No "S3_BUCKET" config var in found in env!');

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
$enddate = strtotime('2012-01-01');

// We only support Release and Beta!
$channels = array('Release','Beta');

// *** URLs and paths ***

$url_csvbase = 'http://people.mozilla.com/crash_analysis/';

// *** code start ***

foreach ($channels as $channel) {
  $fprodtypedata = 'Firefox-'.strtolower($channel).'-old-bytype.json';

  // We only support Release and Beta!
  $ver_regex = ($channel == 'Release')?'/^\d+(\.\d+)+$/':'/^\d+(\.\d+)+b\d+$/';
  $max_build_age = getMaxBuildAge(strtolower($channel), true);

  if ($s3->doesObjectExist($bucket, $fprodtypedata)) {
    print('Read stored Firefox '.$channel.' summary data'."\n");
    $result = $s3->getObject(array(
        'Bucket' => $bucket,
        'Key'    => $fprodtypedata));
    $prodtypedata = json_decode($result['Body'], true);
  }
  else {
    $prodtypedata = array();
  }

  for ($anatime = $startdate; $anatime <= $enddate; $anatime = strtotime(date('Y-m-d', $anatime).' +1 day')) {
    $anaday = date('Y-m-d', $anatime);
    print('Looking at data for '.$anaday."\n");

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';

    // Make sure we have the crashdata csv.
    $anafcsv = $fcsv;
    if (!$s3->doesObjectExist($bucket, $anafcsv)) {
      print('Fetching '.$anafcsv.' from the web'."\n");
      $webcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
      if (copy($webcsvgz, $anafcsv.'.gz')) { shell_exec('gzip -d '.$anafcsv.'.gz'); }
    }
    if (!$s3->doesObjectExist($bucket, $anafcsv)) {
      print($anafcsv.' does not exist!'."\n");
      continue;
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
            .' {printf ",%s,%s,%s,%s,%s,%s\n",$7,$8,$9,$25,($23!="\\\\N"),$29}\'';
      $return = shell_exec($cmd.' '.$anafcsv.' | sort | uniq -c');

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
          $release_channel = trim($fields[6]);
          if ($ptype == '\\N' || $ptype =='') { $ptype = 'browser'; }
          if ((strlen($release_channel)?($release_channel == strtolower($channel)):preg_match($ver_regex, $version)) &&
              $builddate > $min_builddate && (!$is_hang || $ptype == 'plugin')) {
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
  $s3->upload($bucket, $fprodtypedata, json_encode($prodtypedata), 'public-read',
      array('params' => array('ContentType'=>'application/json')));
}

// *** helper functions ***


?>
