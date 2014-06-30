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
$imfile = $outdir.'/qa.itermeta.json';

if (file_exists($bdfile)) {
  print('Reading stored QA bug data'."\n");
  $bugdata = json_decode(file_get_contents($bdfile), true);
  // See if there's data to migrate and do so if needed.
  foreach ($bugdata as $anaday=>$data) {
    if (array_key_exists('FxIteration_fixed', $data)) {
      // Migrate everything
      $bugdata[$anaday]['FxIteration']['fixed'] = $bugdata[$anaday]['FxIteration_fixed'];
      unset($bugdata[$anaday]['FxIteration_fixed']);
      $bugdata[$anaday]['FirefoxNonIter']['fixed'] = $bugdata[$anaday]['FirefoxNonIter_fixed'];
      unset($bugdata[$anaday]['FirefoxNonIter_fixed']);
      $bugdata[$anaday]['CoreNonIter']['fixed'] = $bugdata[$anaday]['CoreNonIter_fixed'];
      unset($bugdata[$anaday]['CoreNonIter_fixed']);
      $bugdata[$anaday]['ToolkitNonIter']['fixed'] = $bugdata[$anaday]['ToolkitNonIter_fixed'];
      unset($bugdata[$anaday]['ToolkitNonIter_fixed']);

      $bugdata[$anaday]['FxIteration']['verified'] = $bugdata[$anaday]['FxIteration_verified'];
      unset($bugdata[$anaday]['FxIteration_verified']);
      $bugdata[$anaday]['FirefoxNonIter']['verified'] = $bugdata[$anaday]['FirefoxNonIter_verified'];
      unset($bugdata[$anaday]['FirefoxNonIter_verified']);
      $bugdata[$anaday]['CoreNonIter']['verified'] = $bugdata[$anaday]['CoreNonIter_verified'];
      unset($bugdata[$anaday]['CoreNonIter_verified']);
      $bugdata[$anaday]['ToolkitNonIter']['verified'] = $bugdata[$anaday]['ToolkitNonIter_verified'];
      unset($bugdata[$anaday]['ToolkitNonIter_verified']);

      $bugdata[$anaday]['FxIteration']['reopened'] = $bugdata[$anaday]['FxIteration_reopened'];
      unset($bugdata[$anaday]['FxIteration_reopened']);
      $bugdata[$anaday]['FirefoxNonIter']['reopened'] = $bugdata[$anaday]['FirefoxNonIter_reopened'];
      unset($bugdata[$anaday]['FirefoxNonIter_reopened']);
      $bugdata[$anaday]['CoreNonIter']['reopened'] = $bugdata[$anaday]['CoreNonIter_reopened'];
      unset($bugdata[$anaday]['CoreNonIter_reopened']);
      $bugdata[$anaday]['ToolkitNonIter']['reopened'] = $bugdata[$anaday]['ToolkitNonIter_reopened'];
      unset($bugdata[$anaday]['ToolkitNonIter_reopened']);
    }
    else {
      // This will break out of the migration loop
      // on the first day that no data for migration is found.
      // As we consider that either all of the file needs migration or nothing,
      // this should be good enough.
      break;
    }
  }
}
else {
  $bugdata = array();
}
if (file_exists($imfile)) {
  print('Reading stored QA iteration queries'."\n");
  $itermetastore = json_decode(file_get_contents($imfile), true);
}
else {
  $itermetastore = array();
}

$buggroups = array('FxIteration', 'FirefoxNonIter', 'CoreNonIter', 'ToolkitNonIter');

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

$iterations =
    array('33.2' => array('start' => '2014-06-24',
                          'end' => '2014-07-08'),
          '33.3' => array('start' => '2014-07-08',
                          'end' => '2014-07-22'),
          '34.1' => array('start' => '2014-07-22',
                          'end' => '2014-08-05'),
          '34.2' => array('start' => '2014-08-05',
                          'end' => '2014-08-19'),
          '34.3' => array('start' => '2014-08-19',
                          'end' => '2014-09-02'),
    );

$iterqueries = array('total', 'verifiable', 'verifydone',
                     'verifyneeded', 'contactneeded', 'verifytriage');

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
  print('Fetching QA bug data for '.$anaday);

  $bugdata[$anaday]['time_update'] = time();
  foreach ($bugqueries as $querytype) {
    foreach ($buggroups as $group) {
      $bugquery = getBugQuery($querytype, $group, $anaday);
      //$buglist_url = $bugzilla_url.'buglist.cgi?'.$bugquery;
      //print("\n".$buglist_url."\n");
      $bugcount = getBugCount($bugquery);
      if ($bugcount !== false) {
        $bugdata[$anaday][$group][$querytype] = $bugcount;
      }
      print('.');
    }
  }
  print("\n");
}

$anaday = date('Y-m-d', strtotime(date('Y-m-d', $curtime).' -1 day'));
print('Fetching Firefox iteration bug counts for right now, calling it '.$anaday.' EOD');
if (!array_key_exists('fxiter', $bugdata[$anaday])) {
  $bugdata[$anaday]['fxiter']['time_update'] = time();
}
foreach ($iterations as $iteration=>$iterdata) {
  if (($iterdata['start'] <= $anaday) && ($iterdata['end'] >= $anaday)) {
    if (!array_key_exists($iteration, $bugdata[$anaday]['fxiter'])) {
      $bugdata[$anaday]['fxiter'][$iteration] = array();
    }
    if (!array_key_exists($iteration, $itermetastore)) {
      $itermetastore[$iteration] = $iterdata;
    }
    else {
      $itermetastore[$iteration] = array_merge($itermetastore[$iteration], $iterdata);
    }
    foreach ($iterqueries as $iqtype) {
      $bugquery = getIterQuery($iqtype, $iteration);
      $itermetastore[$iteration]['queries'][$iqtype] = $bugquery;
      //$buglist_url = $bugzilla_url.'buglist.cgi?'.$bugquery;
      //print("\n".$buglist_url."\n");
      $bugcount = getBugCount($bugquery);
      if (($bugcount !== false) && !array_key_exists($iqtype, $bugdata[$anaday]['fxiter'][$iteration])) {
        $bugdata[$anaday]['fxiter'][$iteration][$iqtype] = $bugcount;
      }
      print('.');
    }
  }
}
print("\n");

ksort($bugdata); // sort by date (key), ascending
file_put_contents($bdfile, json_encode($bugdata));
// debug only line
//print_r($bugdata);

ksort($itermetastore); // sort by date (key), ascending
file_put_contents($imfile, json_encode($itermetastore));
// debug only line
//print_r($itermetastore);

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
      $query .= '&f1=OP&j1=OR';
      $query .= '&f2=cf_fx_iteration&o2=notequals&v2=---';
      $query .= '&f3=status_whiteboard&o3=regexp&v3='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
      break;
    case 'FirefoxNonIter':
      // in the Firefox product, but not on the iteration
      $query .= '&product=Firefox';
      $query .= '&f1=cf_fx_iteration&o1=equals&v1=---';
      $query .= '&f2=status_whiteboard&o2=notregexp&v2='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
      break;
    case 'CoreNonIter':
      // in the Core product, but not on the Firefox iteration
      $query .= '&product=Core';
      $query .= '&f1=cf_fx_iteration&o1=equals&v1=---';
      $query .= '&f2=status_whiteboard&o2=notregexp&v2='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
     break;
    case 'ToolkitNonIter':
      // in the Toolkit product, but not on the Firefox iteration
      $query .= '&product=Toolkit';
      $query .= '&f1=cf_fx_iteration&o1=equals&v1=---';
      $query .= '&f2=status_whiteboard&o2=notregexp&v2='.rawurlencode('s=(it|[0-9][0-9]\.[1-3])');
      break;
    default:
      break;
  }
  return $query;
}

function getIterQuery($type, $iteration) {
  $query = 'f1=OP&j1=OR';
  $query .= '&f2=cf_fx_iteration&o2=equals&v2='.rawurlencode($iteration);
  $query .= '&f3=status_whiteboard&o3=substring&v3='.rawurlencode('s='.$iteration);
  $query .= '&f4=CP';
  switch ($type) {
    case 'total':
      // total bugs
      break;
    case 'verifiable':
      // fixed, needing verification
      $query .= '&f5=OP&j5=OR';
      $query .= '&f6=status_whiteboard&o6=substring&v6='.rawurlencode('[qa+]');
      $query .= '&f7=cf_qa_whiteboard&o7=substring&v7='.rawurlencode('[qa+]');
      break;
    case 'verifydone':
      // verification done
      $query .= '&resolution=FIXED';
      $query .= '&bug_status=VERIFIED';
      break;
    case 'verifyneeded':
      // fixed, needing verification
      $query .= '&resolution=FIXED';
      $query .= '&bug_status=RESOLVED';
      $query .= '&f5=OP&j5=OR';
      $query .= '&f6=status_whiteboard&o6=substring&v6='.rawurlencode('[qa+]');
      $query .= '&f7=cf_qa_whiteboard&o7=substring&v7='.rawurlencode('[qa+]');
      break;
    case 'contactneeded':
      // QA contact is empty but the bug needs verification, so contact is needed
      $query .= '&f5=qa_contact&o5=isempty';
      $query .= '&f6=OP&j6=OR';
      $query .= '&f7=cf_qa_whiteboard&o7=substring&v7='.rawurlencode('[qa+]');
      $query .= '&f8=status_whiteboard&o8=substring&v8='.rawurlencode('[qa+]');
      break;
    case 'verifytriage':
      // verification assessment missing, needs triage (qa? or no QA tag)
      $query .= '&f5=OP&j5=OR';
      $query .= '&f6=status_whiteboard&o6=substring&v6='.rawurlencode('[qa?]');
      $query .= '&f7=cf_qa_whiteboard&o7=substring&v7='.rawurlencode('[qa?]');
      $query .= '&f8=OP';
      $query .= '&f9=status_whiteboard&o9=notsubstring&v9='.rawurlencode('[qa');
      $query .= '&f10=cf_qa_whiteboard&o10=notsubstring&v10='.rawurlencode('[qa');
      $query .= '&f11=CP';
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
