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

  print('Looking at B2G data for '.$anadir."\n");
  if (!file_exists($anadir)) { mkdir($anadir); }

  $fbcdata = 'b2g-crashdata.json';
  $fpages = 'pages.json';
  $fweb = $anadir.'.b2g.crashes.html';

  $anafbcdata = $anadir.'/'.$fbcdata;
  if (!file_exists($anafbcdata)) {
    $rep_query =
      'SELECT version,build,'
      ."CASE WHEN os_version LIKE '%unagi%' THEN 'unagi' ELSE CASE WHEN os_version LIKE '%otoro%' THEN 'otoro' ELSE 'unknown' END END as device,"
      .'process_type,signature,date_processed,uuid '
      .'from reports '
      ."WHERE product='B2G' AND os_name='Android' AND utc_day_is(date_processed, '2012-11-05') "
      .'ORDER BY version DESC, build DESC;';

    $rep_result = pg_query($db_conn, $rep_query);
    if (!$rep_result) {
      print('--- ERROR: Main report query failed!'."\n");
    }

    $bcd = array('list' => array(), 'total' => 0);
    while ($rep_row = pg_fetch_array($rep_result)) {
      $bcd['list'][] = $rep_row;
      $bcd['total']++;
    }

    file_put_contents($anafbcdata, json_encode($bcd));
  }
  else {
    $bcd = json_decode(file_get_contents($anafbcdata), true);
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
        '.sig {'."\n"
        .'  font-size: small;'."\n"
        .'}'."\n"
        .'.buildid.timepart {'."\n"
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
    ));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $anadir.' B2G Crashes Report'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'List of all '.$bcd['total'].' crashes for B2G on actual devices.'));

    $table = $body->appendChild($doc->createElement('table'));
    $table->setAttribute('border', '1');

    // table head
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'Ver'));
    $th = $tr->appendChild($doc->createElement('th', 'Build ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Device'));
    $th = $tr->appendChild($doc->createElement('th', 'Process'));
    $th = $tr->appendChild($doc->createElement('th', 'Signature'));
    $th = $tr->appendChild($doc->createElement('th', 'Time'));

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
      $td = $tr->appendChild($doc->createElement('td', $crash['device']));
      $td->setAttribute('class', 'device '.$crash['device']);
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
      $td->setAttribute('class', 'time');
      $link = $td->appendChild($doc->createElement('a',
          date('H:i:s', strtotime($crash['date_processed']))));
      $link->setAttribute('href', $url_replinkbase.$crash['uuid']);
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

  print("\n");
}

// *** helper functions ***

?>
