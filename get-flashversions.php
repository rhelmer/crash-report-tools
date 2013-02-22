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

// set higher memory limit so we can process large JSON files
ini_set('memory_limit', '1024M');


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

// Flash versions to gather reports for.

$flash_versions = array('11.2.202.235',
                        '11.4.402.287',
                        '11.5.502.149',
                        '11.6.602.168',
                        '11.6.602.170');

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
    continue;
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
    continue;
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
    $anadir = $anaday;
    print('Looking at Flash version data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fpages = 'pages.json';
    $fweb = $anadir.'.'.$prd.'.flash.'.$fvdash.'.html';

    if (!array_key_exists($anadir, $flashverdata) || in_array($anadir, $force_dates)) {
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
        $other_fv = array();
        $other_query =
          'SELECT flash_version '
          .'FROM reports_clean LEFT JOIN flash_versions'
          .' ON (reports_clean.flash_version_id=flash_versions.flash_version_id) '
          .'WHERE signature_id = '.$rep_row['signature_id']
          .' AND reports_clean.flash_version_id NOT IN ('.implode(',', $fv_ids).')'
          .' AND product_version_id IN ('.implode(',', $pv_ids).')'
          ." AND utc_day_is(date_processed, '".$anadir."') "
          .'GROUP BY flash_version '
          .'ORDER BY flash_version DESC;';

        $other_result = pg_query($db_conn, $other_query);
        if (!$other_result) {
          print('--- ERROR: Other Flash versions query failed!'."\n");
        }
        while ($other_row = pg_fetch_array($other_result)) {
          $other_fv[] = $other_row['flash_version'];
        }

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

        $fvd['total'] += $rep_row['cnt'];
        $fvd['sigs'][] = array('sig' => $rep_row['signature'],
                               'cnt' => $rep_row['cnt'],
                               'bugs' => $bugs,
                               'other_flash_ver' => $other_fv);
      }
      $flashverdata[$anadir] = $fvd;

      ksort($flashverdata); // sort by date (key), ascending

      file_put_contents($fvdfile, json_encode($flashverdata));
    }

    $anafweb = $anadir.'/'.$fweb;
    if ((!file_exists($anafweb) || in_array($anadir, $force_dates)) &&
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
          .'.bug {'."\n"
          .'  font-size: small;'."\n"
          .'  empty-cells: show;'."\n"
          .'}'."\n"
          .'.resolved {'."\n"
          .'  text-decoration: line-through;'."\n"
          .'}'."\n"
          .'.otherver.some {'."\n"
          .'  font-size: small;'."\n"
          .'}'."\n"
          .'.otherver.none {'."\n"
          .'  font-weight: bold;'."\n"
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
          'Percentage is in relation to the total in this Flash version, &quot;Other Versions&quot; lists other Flash versions this signatures appears in on that day.'));

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', '#'));
      $th->setAttribute('title', 'Rank');
      $th = $tr->appendChild($doc->createElement('th', 'Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Count'));
      $th = $tr->appendChild($doc->createElement('th', 'Pct'));
      $th->setAttribute('title', 'Percentage out of all crashes with this Flash version');
      $th = $tr->appendChild($doc->createElement('th', 'Bugs'));
      $th = $tr->appendChild($doc->createElement('th', 'Other Versions'));
      $th->setAttribute('title', 'Other Flash versions this signatures appears in');

      $rank = 0;
      foreach ($fvd['sigs'] as $data) {
        $pct = $fvd['total'] ?
               $data['cnt'] / $fvd['total'] : 0;

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
          $sigdisplay = preg_replace('/_+\.*$/', '', $data['sig']);
          $link = $td->appendChild($doc->createElement('a',
              htmlentities($sigdisplay, ENT_COMPAT, 'UTF-8')));
          $link->setAttribute('href', $url_siglinkbase.rawurlencode($data['sig']));
        }
        $td = $tr->appendChild($doc->createElement('td', $data['cnt']));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', 100 * $pct).'%'));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td'));
        if (array_key_exists('bugs', $data) && count($data['bugs'])) {
          foreach ($data['bugs'] as $bug => $bugdata) {
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
        $td = $tr->appendChild($doc->createElement('td',
            count($data['other_flash_ver'])?implode(', ', $data['other_flash_ver']):'None'));
        $td->setAttribute('class', 'otherver '.(count($data['other_flash_ver'])?'some':'none'));
        // Make sure the table doesn't get too long.
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
              'channel' => null,
              'version' => null,
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
