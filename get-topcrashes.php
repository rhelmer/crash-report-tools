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

// reports to process

$reports = array(
    'win8' => array('display_name' => 'Windows 8',
                    'os_name' => 'Windows',
                    'major_version' => 6,
                    'minor_version' => 2,
                    'show_other_os' => true,
                    'products' => array('Firefox')),
    'mtlion' => array('display_name' => 'Mountain Lion',
                      'os_name' => 'Mac OS X',
                      'major_version' => 10,
                      'minor_version' => 8,
                      'show_other_os' => true,
                      'products' => array('Firefox')),
    'armv6' => array('display_name' => 'ARMv6',
                     'include_reports_table' => true,
                     'wherex' => " AND reports.os_version ~ 'armv6l$'"),
                     'show_other_os' => false,
                     'products' => array('FennecAndroid'));

// for how many days back to get the data
$backlog_days = 0;

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

foreach ($reports as $rname=>$rep) {
  $rep_wherex = $rep['wherex'];
  if (array_key_exists('os_name', $rep)) {
    $os_ids = array();
    $os_query =
      'SELECT os_version_id '
      .'FROM os_versions '
      ."WHERE os_name = '".$rep['os_name']."'"
      .(array_key_exists('major_version', $rep)
        ?' AND major_version = '.$rep['major_version']
        :'')
      .(array_key_exists('minor_version', $rep)
        ?' AND minor_version = '.$rep['minor_version']
        :'')
      .';';
    $os_result = pg_query($db_conn, $os_query);
    if (!$os_result) {
      print('--- ERROR: OS version query failed!'."\n");
    }
    while ($os_row = pg_fetch_array($os_result)) {
      $os_ids[] = $os_row['os_version_id'];
    }
    $rep_wherex .= ' AND reports_clean.os_version_id IN ('.implode(',', $os_ids).')';
  }

  foreach ($products as $product) {
    $ver_query =
      'SELECT version_string, product_version_id '
      .'FROM product_versions '
      ."WHERE product_name = '".$product."'"
      ." AND featured_version = 't';";

    $ver_result = pg_query($db_conn, $ver_query);
    if (!$ver_result) {
      print('--- ERROR: version query failed!'."\n");
      break;
    }
    while ($ver_row = pg_fetch_array($ver_result)) {
      $pv_ids = array($ver_row['product_version_id']);
      $ver = $ver_row['version_string'];
      $channel = '';

      $prd = strtolower($rep['product']);
      $prdvershort = (($prd == 'firefox')?'ff':(($prd == 'fennec')?'fn':(($prd == 'fennecandroid')?'fna':$prd)))
                    .(strlen($channel)?'-'.$channel:'')
                    .(strlen($ver)?'-'.$ver:'');
      $prdverfile = $prd
                    .(strlen($channel)?'.'.$channel:'')
                    .(strlen($ver)?'.'.$ver:'');
      $prdverdisplay = $rep['product']
                      .(strlen($channel)?' '.ucfirst($channel):'')
                      .(strlen($ver)?' '.(isset($rep['version_display'])?$rep['version_display']:$ver):'');

      $fdfile = $prdvershort.'.'.$rname.'.topcrash.json';

      for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
        $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
        $anadir = date('Y-m-d', $anatime);
        print('Looking at '.$prdverdisplay.' on '.$rep['display_name'].' data for '.$anadir."\n");
        if (!file_exists($anadir)) { mkdir($anadir); }

        $ftcdata = $prdvershort.'-'.$rname.'-topcrash.json';
        $fpages = 'pages.json';
        $fweb = $anadir.'.'.$prdverfile.'.'.$rname.'.topcrash.html';

        $anaftcdata = $anadir.'/'.$ftcdata;
        if (!file_exists($anaftcdata)) {
          $rep_query =
            'SELECT COUNT(*) as cnt, reports_clean.signature, signatures.signature_id '
            .'FROM '
            .($rep['include_reports_table']?
                '(reports_clean LEFT JOIN reports'
                .' ON (reports_clean.uuid=reports.uuid))'
              :'reports_clean')
            .' LEFT JOIN signatures'
            .' ON (reports_clean.signature_id=signatures.signature_id) '
            .' WHERE reports_clean.product_version_id IN ('.implode(',', $pv_ids).') '
            .$rep_wherex
            ." AND utc_day_is(reports_clean.date_processed, '".$anadir."')"
            .'GROUP BY signatures.signature_id, reports_clean.signature '
            .'ORDER BY cnt DESC;';

          $rep_result = pg_query($db_conn, $rep_query);
          if (!$rep_result) {
            print('--- ERROR: Main report query failed!'."\n");
          }

          $tcd = array('sigs' => array(), 'total' => 0);
          while ($rep_row = pg_fetch_array($rep_result)) {
            $tcd['total'] += $rep_row['cnt'];
            $tcd['sigs'][] = array('sig' => $rep_row['signature'],
                                   'cnt' => $rep_row['cnt']);
          }
          ksort($tcd); // sort by date (key), ascending

          file_put_contents($anaftcdata, json_encode($tcd));
        }
        else {
          $tcd = json_decode(file_get_contents($anaftcdata), true);
        }

        $anafweb = $anadir.'/'.$fweb;
        if (!file_exists($anafweb) &&
            count($tcd) && $tcd['total']) {
          // create out an HTML page
          print('Writing HTML output'."\n");
          $doc = new DOMDocument('1.0', 'utf-8');
          $doc->formatOutput = true; // we want a nice output

          $show_other_os = false; // array_key_exists('show_other_os', $rep) && ($rep['show_other_os'] == true);

          $root = $doc->appendChild($doc->createElement('html'));
          $head = $root->appendChild($doc->createElement('head'));
          $title = $head->appendChild($doc->createElement('title',
              $anadir.' '.$prdverdisplay.' on '.$rep['display_name'].' Top Crash Report'));

          $style = $head->appendChild($doc->createElement('style'));
          $style->setAttribute('type', 'text/css');
          $style->appendChild($doc->createCDATASection(
              '.sig {'."\n"
              .'  font-size: small;'."\n"
              .'}'."\n"
              .'.num, .pct {'."\n"
              .'  text-align: right;'."\n"
              .'}'."\n"
              .($show_other_os?
                  '.otherver.some {'."\n"
                  .'  font-size: small;'."\n"
                  .'}'."\n"
                  .'.otherver.none {'."\n"
                  .'  font-weight: bold;'."\n"
                  .'}'."\n"
              :'')
          ));

          $body = $root->appendChild($doc->createElement('body'));
          $h1 = $body->appendChild($doc->createElement('h1',
              $anadir.' '.$prdverdisplay.' on '.$rep['display_name'].' Top Crash Report'));

          $fvd = $flashverdata[$anadir];

          // description
          $para = $body->appendChild($doc->createElement('p',
              'Top '.$top_x.' crashes of '.$prdverdisplay.' on '.$rep['display_name'].'.'));

          $para = $body->appendChild($doc->createElement('p',
              'Percentage is in relation to the total for '.$prdverdisplay.' on '.$rep['display_name'].'.'));

          $table = $body->appendChild($doc->createElement('table'));
          $table->setAttribute('border', '1');

          // table head
          $tr = $table->appendChild($doc->createElement('tr'));
          $th = $tr->appendChild($doc->createElement('th', '#'));
          $th->setAttribute('title', 'Rank');
          $th = $tr->appendChild($doc->createElement('th', 'Signature'));
          $th = $tr->appendChild($doc->createElement('th', 'Count'));
          $th = $tr->appendChild($doc->createElement('th', 'Pct'));
          $th->setAttribute('title', 'Percentage out of all crashes for '.$prdverdisplay.' on '.$rep['display_name']);
          if ($show_other_os) {
            $th = $tr->appendChild($doc->createElement('th', 'Other Versions'));
            $th->setAttribute('title', 'Other versions this signatures appears in');
          }

          $rank = 0;
          foreach ($tcd['sigs'] as $data) {
            $pct = $tcd['total'] ?
                  $data['cnt'] / $tcd['total'] : 0;

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
              $link = $td->appendChild($doc->createElement('a', htmlentities($sigdisplay)));
              $link->setAttribute('href', $url_siglinkbase.rawurlencode($data['sig']));
            }
            $td = $tr->appendChild($doc->createElement('td', $data['cnt']));
            $td->setAttribute('class', 'num');
            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $pct).'%'));
            $td->setAttribute('class', 'num');
            if ($show_other_os) {
              $td = $tr->appendChild($doc->createElement('td',
                  count($data['other_flash_ver'])?implode(', ', $data['other_flash_ver']):'None'));
              $td->setAttribute('class', 'otherver '.(count($data['other_flash_ver'])?'some':'none'));
            }
            // Make sure the table doesn't get too long.
            if ($rank >= $top_x) { break; }
          }

          $doc->saveHTMLFile($anafweb);
/*
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
                  'channel' => $channel,
                  'version' => $ver,
                  'report' => 'topcrash',
                  'report_sub' => $rname,
                  'display_ver' => $prdverdisplay,
                  'display_rep' => $rep['display_name'].' Top Crash Report');
          file_put_contents($anafpages, json_encode($pages));
*/
        }

        print("\n");
      }
    }
  }
}

// *** helper functions ***

?>
