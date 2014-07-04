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
$tmfile = $outdir.'/qa.trainmeta.json';

if (file_exists($bdfile)) {
  print('Reading stored QA bug data'."\n");
  $bugdata = json_decode(file_get_contents($bdfile), true);
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

if (file_exists($tmfile)) {
  print('Reading stored QA train queries'."\n");
  $trainmetastore = json_decode(file_get_contents($tmfile), true);
}
else {
  $trainmetastore = array();
}

$products = array('Firefox', 'Core', 'Toolkit', 'Firefox for Android', 'Loop');
// Words that exclude bugs from queries (except Firefox, Firefox for Android products)
$excludewords = 'b2g,gaia,homescreen,sms,dialer,flame';

$dailygroups = array('FxIteration', 'FirefoxNonIter', 'CoreNonIter', 'ToolkitNonIter');
$dailyqueries = array('fixed', 'verified', 'reopened');

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

$trains =
    array('30' => array('start'   => '2014-02-03',
                        'aurora'  => '2014-03-17',
                        'beta'    => '2014-04-28',
                        'release' => '2014-06-10',
                        'end'     => '2014-07-22'),
          '31' => array('start'   => '2014-03-17',
                        'aurora'  => '2014-04-28',
                        'beta'    => '2014-06-09',
                        'release' => '2014-07-22',
                        'end'     => '2014-09-02'),
          '32' => array('start'   => '2014-04-28',
                        'aurora'  => '2014-06-09',
                        'beta'    => '2014-07-21',
                        'release' => '2014-09-02',
                        'end'     => '2014-08-05'),
          '33' => array('start'   => '2014-06-09',
                        'aurora'  => '2014-07-21',
                        'beta'    => '2014-09-01',
                        'release' => '2014-10-14',
                        'end'     => '2014-11-25'),
          '34' => array('start'   => '2014-07-21',
                        'aurora'  => '2014-09-01',
                        'beta'    => '2014-10-13',
                        'release' => '2014-11-25',
                        'end'     => '2015-01-06'),
    );
$trainqueries = array('verifydone', 'verifyneeded', 'verifytriage');

$staticqueries = array('nonTMfixed', 'needURLs', 'qawanted', 'stepswanted', 'windowwanted');

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
  foreach ($dailyqueries as $querytype) {
    foreach ($dailygroups as $group) {
      $bugquery = getDailyBugQuery($querytype, $group, $anaday);
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
print('Fetching bug counts for right now, calling it '.$anaday.' EOD'."\n");

print('Firefox iteration');
if (!array_key_exists($anaday, $bugdata) ||
    !array_key_exists('fxiter', $bugdata[$anaday])) {
  $bugdata[$anaday]['fxiter']['time_update'] = time();
}
foreach ($iterations as $iteration=>$iterdata) {
  // Record data up to 7 days past the end of the iteration.
  $maxday = date('Y-m-d', strtotime($iterdata['end'].' +7 day'));
  if (($iterdata['start'] <= $anaday) && ($maxday >= $anaday)) {
    print('|'.$iteration);
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
      if (($bugcount !== false) &&
          !array_key_exists($iqtype, $bugdata[$anaday]['fxiter'][$iteration])) {
        $bugdata[$anaday]['fxiter'][$iteration][$iqtype] = $bugcount;
      }
      print('.');
    }
  }
}
print("\n");

print('Trains');
if (!array_key_exists('train', $bugdata[$anaday])) {
  $bugdata[$anaday]['train']['time_update'] = time();
}
foreach ($trains as $train=>$traindata) {
  // Record data up to 7 days past the end of the release.
  $maxday = date('Y-m-d', strtotime($traindata['end'].' +7 day'));
  if (($traindata['start'] <= $anaday) && ($maxday >= $anaday)) {
    print('|'.$train);
    $is_on_trunk = ($traindata['aurora'] > $anaday);
    if (!array_key_exists($train, $bugdata[$anaday]['train'])) {
      $bugdata[$anaday]['train'][$train] = array();
    }
    if (!array_key_exists($train, $trainmetastore)) {
      $trainmetastore[$train] = $traindata;
    }
    else {
      $trainmetastore[$train] = array_merge($trainmetastore[$train], $traindata);
    }
    foreach ($products as $prod) {
      print(':');
      if (!array_key_exists($prod, $bugdata[$anaday]['train'][$train])) {
        $bugdata[$anaday]['train'][$train][$prod] = array();
      }
      foreach ($trainqueries as $tqtype) {
        $bugquery = getTrainQuery($tqtype, $prod, $train, $is_on_trunk);
        $trainmetastore[$train]['queries'][$prod][$tqtype] = $bugquery;
        //$buglist_url = $bugzilla_url.'buglist.cgi?'.$bugquery;
        //print("\n".$buglist_url."\n");
        $bugcount = getBugCount($bugquery);
        if (($bugcount !== false) &&
            !array_key_exists($tqtype, $bugdata[$anaday]['train'][$train][$prod])) {
          $bugdata[$anaday]['train'][$train][$prod][$tqtype] = $bugcount;
        }
        print('.');
      }
    }
  }
}
print("\n");

print('Static queries');
if (!array_key_exists('static', $bugdata[$anaday])) {
  $bugdata[$anaday]['static']['time_update'] = time();
}
foreach ($staticqueries as $sqtype) {
  $bugquery = getStaticQuery($sqtype);
  //$buglist_url = $bugzilla_url.'buglist.cgi?'.$bugquery;
  //print("\n".$buglist_url."\n");
  $bugcount = getBugCount($bugquery);
  if (($bugcount !== false) &&
      !array_key_exists($sqtype, $bugdata[$anaday]['static'])) {
    $bugdata[$anaday]['static'][$sqtype] = $bugcount;
  }
  print('.');
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

ksort($trainmetastore); // sort by date (key), ascending
file_put_contents($tmfile, json_encode($trainmetastore));
// debug only line
//print_r($trainmetastore);

// *** helper functions ***
function getDailyBugQuery($type, $group, $date) {
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
      // verification "possible", i.e. wanted at some point
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

function getTrainQuery($type, $product, $train, $is_on_trunk) {
  $query = 'product='.rawurlencode($product);
  if (!preg_match('/^Firefox/', $product)) {
    $query .= '&f1=short_desc&o1=nowordssubstr&v1='.rawurlencode($GLOBALS['excludewords']);
    $query .= '&f2=status_whiteboard&o2=nowordssubstr&v2='.rawurlencode($GLOBALS['excludewords']);
  }
  switch ($type) {
    case 'verifydone':
      // verification done#
      if ($is_on_trunk) {
        $query .= '&target_milestone='.rawurlencode('Firefox '.$train);
        $query .= '&target_milestone=mozilla'.rawurlencode($train);
        $query .= '&resolution=FIXED';
        $query .= '&bug_status=VERIFIED';
      }
      else {
        $query .= '&f3=cf_status_firefox'.rawurlencode($train);
        $query .= '&o3=substring&v3=verified';
      }
      break;
    case 'verifyneeded':
      // fixed (or disabled), needing verification
      if ($is_on_trunk) {
        $query .= '&target_milestone='.rawurlencode('Firefox '.$train);
        $query .= '&target_milestone=mozilla'.rawurlencode($train);
        $query .= '&resolution=FIXED';
        $query .= '&bug_status=RESOLVED';
      }
      else {
        $query .= '&f3=cf_status_firefox'.rawurlencode($train);
        $query .= '&o3=regexp&v3='.rawurlencode('^(fixed|disabled)');
      }
      $query .= '&f4=OP&j4=OR';
      $query .= '&f5=status_whiteboard&o5=substring&v5='.rawurlencode('[qa+]');
      $query .= '&f6=cf_qa_whiteboard&o6=substring&v6='.rawurlencode('[qa+]');
      $query .= '&f7=keywords&o7=anywords&v7=verifyme';
      break;
    case 'verifytriage':
      // verification assessment missing, needs triage (qa? tag)
      if ($is_on_trunk) {
        $query .= '&target_milestone='.rawurlencode('Firefox '.$train);
        $query .= '&target_milestone=mozilla'.rawurlencode($train);
        $query .= '&resolution=FIXED&resolution=---';
        $query .= '&f3=bug_status&o3=notequals&v3=VERIFIED';
      }
      else {
        $query .= '&f3=cf_status_firefox'.rawurlencode($train);
        $query .= '&o3=regexp&v3='.rawurlencode('^(affected|fixed|disabled)');
      }
      $query .= '&f4=OP&j4=OR';
      $query .= '&f5=status_whiteboard&o5=substring&v5='.rawurlencode('[qa?]');
      $query .= '&f6=cf_qa_whiteboard&o6=substring&v6='.rawurlencode('[qa?]');
      break;
    default:
      break;
  }
  return $query;
}

function getStaticQuery($type) {
  switch ($type) {
    case 'nonTMfixed':
      // Fixed in last 7 days without a target milestone
      $query = 'target_milestone=---';
      foreach ($GLOBALS['products'] as $prod) {
        $query .= '&product='.rawurlencode($prod);
      }
      $query .= '&bug_status=RESOLVED';
      $query .= '&chfield=resolution&chfieldfrom=-7d&chfieldto=Now&chfieldvalue=FIXED';
      break;
    case 'needURLs':
      // bugs with needURLs keyword set
    case 'qawanted':
      // bugs with qawanted keyword set
    case 'stepswanted':
      // bugs with steps-wanted keyword set
    case 'windowwanted':
      // bugs with regressionwindow-wanted keyword set
      if ($type == 'stepswanted') { $keyword = 'steps-wanted'; }
      elseif ($type == 'windowwanted') { $keyword = 'regressionwindow-wanted'; }
      else { $keyword = $type; }
      $query = 'resolution=---';
      $query .= '&keywords_type=allwords&keywords='.rawurlencode($keyword);
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
