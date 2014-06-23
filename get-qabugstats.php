#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves stats on weekly crash bugs.

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

// *** deal with arguments ***
$php_self = array_shift($argv);
$force_dates = array();
if (count($argv)) {
  foreach ($argv as $date) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) &&
        date('Y-m-d', strtotime($date)) == $date) {
      $force_dates[] = $date;
    }
  }
}
if (count($force_dates)) {
  print('Forcing update for the following dates: '.implode(', ', $force_dates)."\n\n");
}

// *** data gathering variables ***

// for how many days back to get the data
$backlog_days = 2;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$outdir = 'qa';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

$bugzilla_url = 'https://bugzilla.mozilla.org/';
$bzapi_url = 'https://bugzilla.mozilla.org/bzapi/';

// *** code start ***

// get current day
$curtime = time();

// make sure our output dir exists
if (!file_exists($outdir)) { mkdir($outdir); }

$bdfile = $outdir.'/qa.bugdata.json';

if (file_exists($bdfile)) {
  print('Reading stored QA bug data'."\n");
  $bugdata = json_decode(file_get_contents($bdfile), true);
}
else {
  $bugdata = array();
}

$queryinfo = array('fixed' =>    array('title' => 'Fixed',
                                       'desc' => 'Bugs marked as FIXED',
                                       'color' => 'rgba(0, 204, 0, .5)'),
                   'verified' => array('title' => 'Verified',
                                       'desc' => 'Bugs marked as VERIFIED',
                                       'color' => 'rgba(0, 0, 255, .5)'),
                   'reopened' => array('title' => 'Reopened',
                                       'desc' => 'Bugs marked as REOPENED',
                                       'color' => ''),
                  );

$bugqueries = array_keys($queryinfo);

$buggroups = array('FxIteration', 'FirefoxNonIter', 'CoreNonIter', 'ToolkitNonIter');

$days_to_analyze = array();
for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $days_to_analyze[] = date('Y-m-d', strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day'));
}
foreach ($force_dates as $anaday) {
  if (!in_array($anaday, $days_to_analyze)) {
    $days_to_analyze[] = $anaday;
  }
}
foreach ($days_to_analyze as $anaday) {
  print('Fetching crash bug data for '.$anaday);

  $daydata = array();
  foreach ($bugqueries as $querytype) {
    foreach ($buggroups as $group) {
      $bugquery = getBugQuery($querytype, $group, $anaday);
      //$buglist_url = $bugzilla_url.'buglist.cgi?'.$bugquery;
      //print($buglist_url."\n");
      $bugcount = getBugCount($bugquery);
      if ($bugcount !== false) {
        $daydata[$group.'_'.$querytype] = $bugcount;
      }
      print('.');
    }
  }
  print("\n");

  if (count($daydata)) {
    $daydata['time_update'] = time();
    $bugdata[$anaday] = $daydata;
  }
  else {
    print('ERROR: No data!'."\n");
  }

  ksort($bugdata); // sort by date (key), ascending

  file_put_contents($bdfile, json_encode($bugdata));
}
// debug only line
//print_r($bugdata);

// *** helper functions ***
function getBugQuery($type, $group, $date) {
  $ymd_start = $date.' 00:00:00';
  $ymd_end = date('Y-m-d H:i:s', strtotime($ymd_start.' +1 day'));

  $query = 'query_format=advanced';
  switch ($type) {
    case 'fixed':
      // fixed bugs
      $query .= '&chfield=resolution';
      $query .= '&chfieldvalue=FIXED';
      $query .= '&chfieldfrom='.rawurlencode($ymd_start);
      $query .= '&chfieldto='.rawurlencode($ymd_end);
      break;
    case 'verified':
      // verified (fixed) bugs
      $query .= '&resolution=FIXED';
      $query .= '&chfield=bug_status';
      $query .= '&chfieldvalue=VERIFIED';
      $query .= '&chfieldfrom='.rawurlencode($ymd_start);
      $query .= '&chfieldto='.rawurlencode($ymd_end);
      break;
    case 'reopened':
      // reopened bugs
      $query .= '&chfield=bug_status';
      $query .= '&chfieldvalue=REOPENED';
      $query .= '&chfieldfrom='.rawurlencode($ymd_start);
      $query .= '&chfieldto='.rawurlencode($ymd_end);
      break;
    default:
      break;
  }
  switch ($group) {
    case 'FxIteration':
      // within the Firefox iteration planning
      $query .= '&status_whiteboard_type=regexp';
      $query .= '&status_whiteboard='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
      break;
    case 'FirefoxNonIter':
      // in the Firefox product, but not on the iteration
      $query .= '&product=Firefox';
      $query .= '&status_whiteboard_type=notregexp';
      $query .= '&status_whiteboard='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
      break;
    case 'CoreNonIter':
      // in the Core product, but not on the Firefox iteration
      $query .= '&product=Core';
      $query .= '&status_whiteboard_type=notregexp';
      $query .= '&status_whiteboard='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
     break;
    case 'ToolkitNonIter':
      // in the Toolkit product, but not on the Firefox iteration
      $query .= '&product=Toolkit';
      $query .= '&status_whiteboard_type=notregexp';
      $query .= '&status_whiteboard='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
      break;
    default:
      break;
  }
  return $query;
}

function getBugCount($query) {
  $list_json = file_get_contents($GLOBALS['bzapi_url'].'count?'.$query);
  if ($list_json) {
    $list_info = json_decode($list_json, true);
    return $list_info['data'];
  }
  return false;
}

?>
