#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves stats comparing flash versions in hangs and crashes.

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

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';
$url_replinkbase = 'https://crash-stats.mozilla.com/report/index/';
$url_buglinkbase = 'https://bugzilla.mozilla.org/show_bug.cgi?id=';


if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

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

for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
  $anadir = date('Y-m-d', $anatime);

  print('B2G: Looking at on-device crash data for '.$anadir."\n");
  if (!file_exists($anadir)) { mkdir($anadir); }

  $fbcdata = 'b2g-crashdata.json';
  $fbtc = 'b2g-topcrashes.json';
  $fpages = 'pages.json';
  $fweb = $anadir.'.b2g.crashes.html';

  $anafbcdata = $anadir.'/'.$fbcdata;
  if (!file_exists($anafbcdata)) {
    $rep_query =
      'SELECT version,build,release_channel,'
      .'process_type,signature,date_processed,uuid '
      .'FROM reports '
      ."WHERE product='B2G' AND os_name='Android' AND utc_day_is(date_processed, '".$anadir."') "
      .'ORDER BY version DESC, build DESC, date_processed DESC;';

    $rep_result = pg_query($db_conn, $rep_query);
    if (!$rep_result) {
      print('--- ERROR: Main report query failed!'."\n");
    }

    $bcd = array('list' => array(), 'total' => 0);
    while ($rep_row = pg_fetch_array($rep_result)) {
      $raw_query =
        "SELECT raw_crash->>'Android_Manufacturer' as manufacturer, "
        ."raw_crash->>'Android_Model' as model, "
        ."raw_crash->>'B2G_OS_Version' as b2g_ver "
        .'FROM raw_crashes '
        ."WHERE uuid='".$rep_row['uuid']."';";

      $raw_result = pg_query($db_conn, $raw_query);
      if (!$raw_result) {
        print('--- ERROR: Raw crash query failed!'."\n");
      }
      $rep_row += pg_fetch_array($raw_result);
      $rep_row['device'] = $rep_row['manufacturer'].' '.$rep_row['model'];

      $bugs = array();
      $bug_query =
        'SELECT bug_id, status, resolution, short_desc '
        .'FROM bug_associations LEFT JOIN bugs'
        .' ON (bug_associations.bug_id=bugs.id) '
        ."WHERE signature = '".pg_escape_string($rep_row['signature'])."';";

      $bug_result = pg_query($db_conn, $bug_query);
      if (!$bug_result) {
        print('--- ERROR: Bug associations query failed!'."\n");
      }
      while ($bug_row = pg_fetch_array($bug_result)) {
        $bugs[$bug_row['bug_id']] = array('status' => $bug_row['status'],
                                          'resolution' => $bug_row['resolution'],
                                          'short_desc' => $bug_row['short_desc']);
      }
      $rep_row['bugs'] = $bugs;

      $bcd['list'][] = $rep_row;
      $bcd['total']++;
    }

    file_put_contents($anafbcdata, json_encode($bcd));
  }
  else {
    $bcd = json_decode(file_get_contents($anafbcdata), true);
  }

  $anafbtc = $anadir.'/'.$fbtc;
  if (!file_exists($anafbtc)) {
    $btc = array();
    foreach ($bcd['list'] as $crash) {
      $report = $crash['version'].'::'.$crash['release_channel'];
      $device = strlen($crash['device'])?$crash['device']:'unknown';
      $buildday = substr($crash['build'], 0, 8);
      $ptype = strlen($crash['process_type'])?$crash['process_type']:'gecko';
      addCount($btc, $report);
      if ($btc[$report]['.count'] == 1) {
        $btc[$report]['version'] = $crash['version'];
        $btc[$report]['release_channel'] = $crash['release_channel'];
        $btc[$report]['.sigs'] = array();
      }
      addCount($btc[$report]['.sigs'], $crash['signature']);
      if ($btc[$report]['.sigs'][$crash['signature']]['.count'] == 1) {
        $btc[$report]['.sigs'][$crash['signature']]['bugs'] = $crash['bugs'];
      }
      if (array_key_exists('devices', $btc[$report]['.sigs'][$crash['signature']])) {
        if (!in_array($device, $btc[$report]['.sigs'][$crash['signature']]['devices'])) {
          $btc[$report]['.sigs'][$crash['signature']]['devices'][] = $device;
        }
      }
      else {
        $btc[$report]['.sigs'][$crash['signature']]['devices'] = array($device);
      }
      if (array_key_exists('bdates', $btc[$report]['.sigs'][$crash['signature']])) {
        if (!in_array($buildday, $btc[$report]['.sigs'][$crash['signature']]['bdates'])) {
          $btc[$report]['.sigs'][$crash['signature']]['bdates'][] = $buildday;
        }
      }
      else {
        $btc[$report]['.sigs'][$crash['signature']]['bdates'] = array($buildday);
      }
      if (array_key_exists('proctypes', $btc[$report]['.sigs'][$crash['signature']])) {
        if (!in_array($ptype, $btc[$report]['.sigs'][$crash['signature']]['proctypes'])) {
          $btc[$report]['.sigs'][$crash['signature']]['proctypes'][] = $ptype;
        }
      }
      else {
        $btc[$report]['.sigs'][$crash['signature']]['proctypes'] = array($ptype);
      }
    }

    krsort($btc); // sort by key (version::channel) in reverse order
    foreach ($btc as $report=>$rdata) {
      if (array_key_exists('.sigs', $rdata)) {
        uasort($btc[$report]['.sigs'], 'count_compare'); // sort by count, highest-first
        foreach ($rdata['.sigs'] as $sig=>$sdata) {
          sort($btc[$report]['.sigs'][$sig]['devices']);
          rsort($btc[$report]['.sigs'][$sig]['bdates']);
          sort($btc[$report]['.sigs'][$sig]['proctypes']);
        }
      }
    }

    file_put_contents($anafbtc, json_encode($btc));
  }
  else {
    $btc = json_decode(file_get_contents($anafbtc), true);
  }

  $anafweb = $anadir.'/'.$fweb;
  if (!file_exists($anafweb) &&
      count($bcd) && $bcd['total']) {
    // create out an HTML page
    print('Writing HTML output'."\n");
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $anadir.' B2G Crashes Report'));

    $style = $head->appendChild($doc->createElement('style'));
    $style->setAttribute('type', 'text/css');
    $style->appendChild($doc->createCDATASection(
        '.sig, .time {'."\n"
        .'  font-size: small;'."\n"
        .'}'."\n"
        .'.buildid > .timepart {'."\n"
        .'  color: GrayText;'."\n"
        .'}'."\n"
        .'.device.unagi {'."\n"
        .'}'."\n"
        .'.device.otoro {'."\n"
        .'}'."\n"
        .'.device.unknown {'."\n"
        .'  color: GrayText;'."\n"
        .'}'."\n"
        .'.ptype.gecko {'."\n"
        .'}'."\n"
        .'.ptype.content {'."\n"
        .'  color: GrayText;'."\n"
        .'}'."\n"
        .'.bug {'."\n"
        .'  font-size: small;'."\n"
        .'  empty-cells: show;'."\n"
        .'}'."\n"
        .'.resolved {'."\n"
        .'  text-decoration: line-through;'."\n"
        .'}'."\n"
        .'.num, .pct {'."\n"
        .'  text-align: right;'."\n"
        .'}'."\n"
    ));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $anadir.' B2G Crashes Report'));

    $list = $body->appendChild($doc->createElement('ul'));
    $item = $list->appendChild($doc->createElement('li'));
    $link = $item->appendChild($doc->createElement('a',
        'Top Crashes By Signature'));
    $link->setAttribute('href', '#tcbs');
    $tlist = $item->appendChild($doc->createElement('ul'));
    foreach ($btc as $report=>$rdata) {
      $titem = $tlist->appendChild($doc->createElement('li'));
      $tlink = $titem->appendChild($doc->createElement('a',
          $rdata['version'].' '.$rdata['release_channel']));
      $tlink->setAttribute('href', '#'.$rdata['version'].$rdata['release_channel']);
    }
    $list = $body->appendChild($doc->createElement('ul'));
    $item = $list->appendChild($doc->createElement('li'));
    $link = $item->appendChild($doc->createElement('a',
        'Raw Crash List'));
    $link->setAttribute('href', '#crashlist');

    $h2 = $body->appendChild($doc->createElement('h2',
        'Top Crashes By Signature'));
    $link->setAttribute('id', 'tcbs');

    foreach ($btc as $report=>$rdata) {
      $h3 = $body->appendChild($doc->createElement('h3',
          $rdata['version'].' '.$rdata['release_channel']));
      $link->setAttribute('id', $rdata['version'].$rdata['release_channel']);

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', 'Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Devices'));
      $th = $tr->appendChild($doc->createElement('th', 'Build Dates'));
      $th = $tr->appendChild($doc->createElement('th', 'Process Types'));
      $th = $tr->appendChild($doc->createElement('th', 'Count'));
      $th = $tr->appendChild($doc->createElement('th', 'Pct'));
      $th = $tr->appendChild($doc->createElement('th', 'Bugs'));

      foreach ($rdata['.sigs'] as $sig=>$sdata) {
        $pct = $rdata['.count'] ?
               $sdata['.count'] / $rdata['.count'] : 0;

        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td'));
        $td->setAttribute('class', 'sig');
        if (!strlen($sig)) {
          $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
          $link->setAttribute('href', $url_nullsiglink);
        }
        elseif ($sig == '\N') {
          $td->appendChild($doc->createTextNode('(processing failure - "'.$sig.'")'));
        }
        else {
          // common case, useful signature
          $sigdisplay = preg_replace('/_+\.*$/', '', $sig);
          $link = $td->appendChild($doc->createElement('a',
              htmlentities($sigdisplay, ENT_COMPAT, 'UTF-8')));
          $link->setAttribute('href', $url_siglinkbase.rawurlencode($sig));
        }
        $td = $tr->appendChild($doc->createElement('td', implode(', ', $sdata['devices'])));
        $td = $tr->appendChild($doc->createElement('td', implode(', ', $sdata['bdates'])));
        $td = $tr->appendChild($doc->createElement('td', implode(', ', $sdata['proctypes'])));
        $td = $tr->appendChild($doc->createElement('td', $sdata['.count']));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', 100 * $pct).'%'));
        $td->setAttribute('class', 'pct');
        $td = $tr->appendChild($doc->createElement('td'));
        if (array_key_exists('bugs', $sdata) && count($sdata['bugs'])) {
          foreach ($sdata['bugs'] as $bug => $bugdata) {
            if (strlen($td->textContent)) {
              $td->appendChild($doc->createTextNode(', '));
            }
            $link = $td->appendChild($doc->createElement('a', $bug));
            $link->setAttribute('href', $url_buglinkbase.$bug);
            $link->setAttribute('title',
                $bugdata['status'].' '.$bugdata['resolution'].' - '
                .htmlentities($bugdata['short_desc'], ENT_COMPAT, 'UTF-8'));
            if ($bugdata['status'] == 'RESOLVED' || $bugdata['status'] == 'VERIFIED') {
              $link->setAttribute('class', 'bug resolved');
            }
            else {
              $link->setAttribute('class', 'bug');
            }
          }
        }
        else {
          $td->appendChild($doc->createTextNode('-'));
        }
      }
    }

    $h2 = $body->appendChild($doc->createElement('h2',
        'Raw Crash List'));
    $link->setAttribute('id', 'crashlist');

    // description
    $para = $body->appendChild($doc->createElement('p',
        'List of all '.$bcd['total'].' crashes for B2G on actual devices.'
        .' (Times in the "Crash" column are UTC and link to the detailed crash'
        .' reports on Socorro.)'));

    $table = $body->appendChild($doc->createElement('table'));
    $table->setAttribute('border', '1');

    // table head
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'Ver'));
    $th = $tr->appendChild($doc->createElement('th', 'Build ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Channel'));
    $th = $tr->appendChild($doc->createElement('th', 'B2G-Ver'));
    $th = $tr->appendChild($doc->createElement('th', 'Crash'));
    $th = $tr->appendChild($doc->createElement('th', 'Device'));
    $th = $tr->appendChild($doc->createElement('th', 'Process'));
    $th = $tr->appendChild($doc->createElement('th', 'Signature'));
    $th = $tr->appendChild($doc->createElement('th', 'Bugs'));

    foreach ($bcd['list'] as $crash) {
      $tr = $table->appendChild($doc->createElement('tr'));
      $td = $tr->appendChild($doc->createElement('td', $crash['version']));
      $td->setAttribute('class', 'version');
      $td = $tr->appendChild($doc->createElement('td'));
      $td->setAttribute('class', 'buildid');
      $span = $td->appendChild($doc->createElement('span', substr($crash['build'], 0, 8)));
      $span->setAttribute('class', 'datepart');
      $span = $td->appendChild($doc->createElement('span', substr($crash['build'], 8)));
      $span->setAttribute('class', 'timepart');
      $td = $tr->appendChild($doc->createElement('td', $crash['release_channel']));
      $td->setAttribute('class', 'channel');
      $td = $tr->appendChild($doc->createElement('td', $crash['b2g_ver']));
      $td->setAttribute('class', 'version');
      $td = $tr->appendChild($doc->createElement('td'));
      $td->setAttribute('class', 'time');
      $link = $td->appendChild($doc->createElement('a',
          gmdate('H:i:s', strtotime($crash['date_processed']))));
      $link->setAttribute('href', $url_replinkbase.$crash['uuid']);
      $device = strlen($crash['device'])?$crash['device']:'unknown';
      $td = $tr->appendChild($doc->createElement('td', $device));
      $td->setAttribute('class', 'device '.$device);
      $ptype = strlen($crash['process_type'])?$crash['process_type']:'gecko';
      $td = $tr->appendChild($doc->createElement('td', $ptype));
      $td->setAttribute('class', 'ptype '.$ptype);
      $td = $tr->appendChild($doc->createElement('td'));
      $td->setAttribute('class', 'sig');
      if (!strlen($crash['signature'])) {
        $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
        $link->setAttribute('href', $url_nullsiglink);
      }
      elseif ($crash['signature'] == '\N') {
        $td->appendChild($doc->createTextNode('(processing failure - "'.$crash['signature'].'")'));
      }
      else {
        // common case, useful signature
        $sigdisplay = preg_replace('/_+\.*$/', '', $crash['signature']);
        $link = $td->appendChild($doc->createElement('a',
            htmlentities($sigdisplay, ENT_COMPAT, 'UTF-8')));
        $link->setAttribute('href', $url_siglinkbase.rawurlencode($crash['signature']));
      }
      $td = $tr->appendChild($doc->createElement('td'));
      if (array_key_exists('bugs', $crash) && count($crash['bugs'])) {
        foreach ($crash['bugs'] as $bug => $bugdata) {
          if (strlen($td->textContent)) {
            $td->appendChild($doc->createTextNode(', '));
          }
          $link = $td->appendChild($doc->createElement('a', $bug));
          $link->setAttribute('href', $url_buglinkbase.$bug);
          $link->setAttribute('title',
              $bugdata['status'].' '.$bugdata['resolution'].' - '
              .htmlentities($bugdata['short_desc'], ENT_COMPAT, 'UTF-8'));
          if ($bugdata['status'] == 'RESOLVED' || $bugdata['status'] == 'VERIFIED') {
            $link->setAttribute('class', 'bug resolved');
          }
          else {
            $link->setAttribute('class', 'bug');
          }
        }
      }
      else {
        $td->appendChild($doc->createTextNode('-'));
      }
    }

    $doc->saveHTMLFile($anafweb);

    // add the page to the pages index
    $anafpages = $anadir.'/'.$fpages;
    if (file_exists($anafpages)) {
      $pages = json_decode(file_get_contents($anafpages), true);
    }
    else {
      $pages = array();
    }
    $pages[$fweb] =
      array('product' => 'B2G',
            'channel' => null,
            'version' => null,
            'report' => 'b2gcrash',
            'report_sub' => null,
            'display_ver' => 'B2G',
            'display_rep' => 'Crashes Report');
    file_put_contents($anafpages, json_encode($pages));
  }
}
print("\n");

// *** helper functions ***

// Comparison function using .count member (reverse sort!)
function count_compare($a, $b) {
  if ($a['.count'] == $b['.count']) { return 0; }
  return ($a['.count'] > $b['.count']) ? -1 : 1;
}

// Function to bump the counter of an element or initialize it
function addCount(&$basevar, $sub, $addnum = 1) {
  if (array_key_exists($sub, $basevar)) {
    $basevar[$sub]['.count'] += $addnum;
  }
  else {
    $basevar[$sub]['.count'] = $addnum;
  }
}
?>
