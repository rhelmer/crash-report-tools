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

// Flash versions to gather reports for.

$flash_versions = array('11.2.202.235',
                        '11.3.300.257',
                        '11.3.300.262',
                        '11.3.300.265',
                        '11.3.300.268',
                        '11.4.400.231');

// for how many days back to get the data
$backlog_days = 7;

// how many top crashes to list
$top_x = 100;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';

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

foreach ($flash_versions as $fver) {
  $product = 'Firefox';
  $prd = 'firefox';
  $prdshort = 'ff';
  $fvdash = str_replace('.', '-', $fver);

  $fvdfile = $prdshort.'.flash.'.$fvdash.'.json';

  if (file_exists($fvdfile)) {
    print('Read stored data'."\n");
    $flashverdata = json_decode(file_get_contents($fvdfile), true);
  }
  else {
    $flashverdata = array();
  }

  $fv_ids = array();
  $fv_query =
    'SELECT flash_version_id '
    .'FROM flash_versions '
    ."WHERE flash_version = '".$fver."'"
    .';';
  $fv_result = pg_query($db_conn, $fv_query);
  if (!$fv_result) {
    print('--- ERROR: Flash version query failed!'."\n");
  }
  while ($fv_row = pg_fetch_array($fv_result)) {
    $fv_ids[] = $fv_row['flash_version_id'];
  }

  if (!count($fv_ids)) {
    print('--- ERROR: no versions found in DB for Flash '.$fver.'!'."\n");
    break;
  }

  $pv_ids = array();
  $pv_query =
    'SELECT product_version_id '
    .'FROM product_versions '
    ."WHERE product_name = '".$product."'"
    .';';
  $pv_result = pg_query($db_conn, $pv_query);
  if (!$pv_result) {
    print('--- ERROR: product version query failed!'."\n");
  }
  while ($pv_row = pg_fetch_array($pv_result)) {
    $pv_ids[] = $pv_row['product_version_id'];
  }

  if (!count($pv_ids)) {
    print('--- ERROR: no versions found in DB for '.$product.'!'."\n");
    break;
  }

  $throttle_ids = array();
  $throttle_query =
    'SELECT product_version_id '
    .'FROM product_versions '
    ."WHERE build_type = 'Release' AND product_name = '".$product."'"
    .';';
  $throttle_result = pg_query($db_conn, $throttle_query);
  if ($throttle_result) {
    while ($throttle_row = pg_fetch_array($throttle_result)) {
      $throttle_ids[] = $throttle_row['product_version_id'];
    }
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at Flash version data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fpages = 'pages.json';
    $fweb = $anadir.'.'.$prd.'.flash.'.$fvdash.'.html';

    if (!array_key_exists($anadir, $flashverdata)) {
      print('Fetch data for Flash '.$fver."\n");

      $rep_query =
        'SELECT COUNT(*) as cnt, signature, signatures.signature_id '
        .'FROM reports_clean LEFT JOIN signatures'
        .' ON (reports_clean.signature_id=signatures.signature_id) '
        .'WHERE flash_version_id IN ('.implode(',', $fv_ids).')'
        .' AND product_version_id IN ('.implode(',', $pv_ids).')'
        ." AND utc_day_is(date_processed, '".$anadir."')"
        .'GROUP BY signatures.signature_id, signature '
        .'ORDER BY cnt DESC;';

      $rep_result = pg_query($db_conn, $rep_query);
      if (!$rep_result) {
        print('--- ERROR: Flash version query failed!'."\n");
      }

      $fvd = array('sigs' => array(), 'total' => 0);
      while ($rep_row = pg_fetch_array($rep_result)) {
        $fvd['total'] += $rep_row['cnt'];
        $fvd['sigs'][] = array('sig' => $rep_row['signature'],
                               'cnt' => $rep_row['cnt']);
      }
      $flashverdata[$anadir] = $fvd;

      ksort($flashverdata); // sort by date (key), ascending

      file_put_contents($fvdfile, json_encode($flashverdata));
    }

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) &&
        count($flashverdata[$anadir]) && $flashverdata[$anadir]['total']) {
      // create out an HTML page
      print('Writing HTML output'."\n");
      $doc = new DOMDocument('1.0', 'utf-8');
      $doc->formatOutput = true; // we want a nice output

      $root = $doc->appendChild($doc->createElement('html'));
      $head = $root->appendChild($doc->createElement('head'));
      $title = $head->appendChild($doc->createElement('title',
          $anadir.' Flash '.$fver.' Report'));

      $style = $head->appendChild($doc->createElement('style'));
      $style->setAttribute('type', 'text/css');
      $style->appendChild($doc->createCDATASection(
          '.sig {'."\n"
          .'  font-size: small;'."\n"
          .'}'."\n"
          .'.num, .pct {'."\n"
          .'  text-align: right;'."\n"
          .'}'."\n"
      ));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' Flash '.$fver.' Report'));

      $fvd = $flashverdata[$anadir];

      // description
      $para = $body->appendChild($doc->createElement('p',
          'Top '.$top_x.' crashes on '.$anadir.' containing Flash '.$fver
          .' in the list of loaded modules, in all versions of '.$product.'.'));

      $para = $body->appendChild($doc->createElement('p',
          'Percentage is in relation to the total in this Flash version.'));

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', '#'));
      $th->setAttribute('title', 'Rank');
      $th = $tr->appendChild($doc->createElement('th', 'Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Count'));
      $th = $tr->appendChild($doc->createElement('th', 'Pct'));

      $rank = 0;
      foreach ($fvd['sigs'] as $data) {
        $pct = $fvd['total'] ?
               $data['cnt'] / $fvd['total'] : 0;
        $sigdisplay = preg_replace('/_+\.*$/', '', $data['sig']);

        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td', ++$rank));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td'));
        $td->setAttribute('class', 'sig');
        if (!strlen($data['sig'])) {
          $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
          $link->setAttribute('href', $url_nullsiglink);
        }
        elseif ($data['sig'] == '\N') {
          $td->appendChild($doc->createTextNode('(processing failure - "'.$data['sig'].'")'));
        }
        else {
          // common case, useful signature
          $link = $td->appendChild($doc->createElement('a', htmlentities($sigdisplay)));
          $link->setAttribute('href', $url_siglinkbase.rawurlencode($data['sig']));
        }
        $td = $tr->appendChild($doc->createElement('td', $data['cnt']));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', 100 * $pct).'%'));
        $td->setAttribute('class', 'num');
        if ($rank >= $top_x) { break; }
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
        array('product' => $product,
              'channel' => '',
              'version' => '',
              'report' => 'flash',
              'report_sub' => $fver,
              'display_ver' => '',
              'display_rep' => 'Flash '.$fver.' Report');
      file_put_contents($anafpages, json_encode($pages));
    }

    print("\n");
  }
  // debug only line
  // print_r($flashverdata);
}

// *** helper functions ***

?>
