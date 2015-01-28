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
    'win81' => array('display_name' => 'Windows 8.1',
                    'os_name' => 'Windows',
                    'major_version' => 6,
                    'minor_version' => 3,
                    'show_other_os' => true,
                    'products' => array('Firefox')),
    'win10' => array('display_name' => 'Windows 10',
                    'os_name' => 'Windows',
                    'major_version' => 6,
                    'minor_version' => 4,
                    'show_other_os' => true,
                    'products' => array('Firefox')),
    'mavericks' => array('display_name' => 'Mavericks',
                         'os_name' => 'Mac OS X',
                         'major_version' => 10,
                         'minor_version' => 9,
                         'show_other_os' => true,
                         'products' => array('Firefox')),
    'yosemite' => array('display_name' => 'Yosemite',
                        'os_name' => 'Mac OS X',
                        'major_version' => 10,
                        'minor_version' => 10,
                        'show_other_os' => true,
                        'products' => array('Firefox')),
    'x86' => array('display_name' => 'x86',
                   'wherex' => " AND reports_clean.architecture='x86'",
                   'products' => array('FennecAndroid')),
    'lollipop' => array('display_name' => 'Lollipop',
                   'include_raw_table' => true,
                   'wherex' => " AND reports_clean.process_type='plugin' AND raw_crashes.raw_crash->>'Android_Version'='21 (REL)'",
                   'products' => array('FennecAndroid')),
    'GMP' => array('display_name' => 'GMP',
                   'include_raw_table' => true,
                   'wherex' => " AND reports_clean.process_type='plugin' AND raw_crashes.raw_crash->>'GMPPlugin'='1'",
                   'products' => array('Firefox')));

// for how many days back to get the data
$backlog_days = 7;

// how many top crashes to list
$top_x = 100;

// *** URLs ***

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';
$url_buglinkbase = 'https://bugzilla.mozilla.org/show_bug.cgi?id=';

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
  $rep_wherex = array_key_exists('wherex', $rep)?$rep['wherex']:'';
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
      continue;
    }
    while ($os_row = pg_fetch_array($os_result)) {
      $os_ids[] = $os_row['os_version_id'];
    }
    if (!count($os_ids)) {
      print('--- ERROR: No matching OS version found!'."\n");
      continue;
    }
    $rep_wherex .= ' AND reports_clean.os_version_id IN ('.implode(',', $os_ids).')';
  }

  foreach ($rep['products'] as $product) {
    $ver_query =
      'SELECT build_date, version_string, product_version_id,'
      .' major_version, is_rapid_beta '
      .'FROM product_versions '
      ."WHERE product_name = '".$product."'"
      ." AND featured_version = 't';";

    $ver_result = pg_query($db_conn, $ver_query);
    if (!$ver_result) {
      print('--- ERROR: version query failed!'."\n");
      continue;
    }
    while ($ver_row = pg_fetch_array($ver_result)) {
      $pv_ids = array($ver_row['product_version_id']);
      $ver = $ver_row['version_string'];
      $channel = '';

      $min_date = $ver_row['build_date'];

      $prd = strtolower($product);
      $prdvershort = (($prd == 'firefox')?'ff':(($prd == 'fennec')?'fn':(($prd == 'fennecandroid')?'fna':$prd)))
                    .(strlen($channel)?'-'.$channel:'')
                    .(strlen($ver)?'-'.$ver:'');
      $prdverfile = $prd
                    .(strlen($channel)?'.'.$channel:'')
                    .(strlen($ver)?'.'.$ver:'');
      $prdverdisplay = $product
                      .(strlen($channel)?' '.ucfirst($channel):'')
                      .(strlen($ver)?' '.(isset($rep['version_display'])?$rep['version_display']:$ver):'');

      $fdfile = $prdvershort.'.'.$rname.'.topcrash.json';

      for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
        $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
        $anadir = date('Y-m-d', $anatime);
        if ($min_date > $anadir) {
          continue;
        }
        print('TCBS: Looking at '.$prdverdisplay.' on '.$rep['display_name'].' data for '.$anadir."\n");
        if (!file_exists($anadir)) { mkdir($anadir); }

        $ftcdata = $prdvershort.'-'.$rname.'-topcrash.json';
        $fpages = 'pages.json';
        $fweb = $anadir.'.'.$prdverfile.'.'.$rname.'.topcrash.html';

        $anaftcdata = $anadir.'/'.$ftcdata;
        if (!file_exists($anaftcdata)) {
          $all_pv_ids = $pv_ids;
          if ($ver_row['is_rapid_beta'] == 't') {
            // Rapid beta rolls up 2 weeks of builds since the current date.
            $rpbeta_stdate = date('Y-m-d', strtotime($anadir.' -14 day'));
            $beta_query =
            'SELECT product_version_id '
            .'FROM product_versions WHERE '
            ."product_name = 'Firefox' AND build_type = 'beta'"
            ." AND major_version = '".$ver_row['major_version']."'"
            ." AND build_date BETWEEN '".$rpbeta_stdate."' AND '".$anadir."';";

            $beta_result = pg_query($db_conn, $beta_query);
            if (!$beta_result) {
              print('--- ERROR: rapid beta version query failed!'."\n");
              continue;
            }
            while ($beta_row = pg_fetch_array($beta_result)) {
              $all_pv_ids[] = $beta_row['product_version_id'];
            }
          }

          $rep_query =
            'SELECT COUNT(*) as cnt, signatures.signature, signatures.signature_id '
            .'FROM '
            .((array_key_exists('include_reports_table', $rep) && $rep['include_reports_table'])?
               '(reports_clean LEFT JOIN reports'
               .' ON (reports_clean.uuid=reports.uuid))'
              :((array_key_exists('include_raw_table', $rep) && $rep['include_raw_table'])?
                 '(reports_clean LEFT JOIN raw_crashes'
                 .' ON (reports_clean.uuid=raw_crashes.uuid::text))'
                :'reports_clean'))
            .' LEFT JOIN signatures'
            .' ON (reports_clean.signature_id=signatures.signature_id) '
            .' WHERE reports_clean.product_version_id IN ('.implode(',', $all_pv_ids).') '
            .$rep_wherex
            ." AND utc_day_is(reports_clean.date_processed, '".$anadir."')"
            .((array_key_exists('include_reports_table', $rep) && $rep['include_reports_table'])?
                " AND utc_day_is(reports.date_processed, '".$anadir."')"
              :'')
            .' GROUP BY signatures.signature_id, signatures.signature '
            .'ORDER BY cnt DESC;';

          $rep_result = pg_query($db_conn, $rep_query);
          if (!$rep_result) {
            print('--- ERROR: Main report query failed!'."\n");
          }

          $tcd = array('sigs' => array(), 'total' => 0);
          while ($rep_row = pg_fetch_array($rep_result)) {
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

            $tcd['total'] += $rep_row['cnt'];
            $tcd['sigs'][] = array('sig' => $rep_row['signature'],
                                   'cnt' => $rep_row['cnt'],
                                   'bugs' => $bugs);
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
              .'.bug {'."\n"
              .'  font-size: small;'."\n"
              .'  empty-cells: show;'."\n"
              .'}'."\n"
              .'.resolved {'."\n"
              .'  text-decoration: line-through;'."\n"
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
          $th = $tr->appendChild($doc->createElement('th', 'Bugs'));
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
              $link = $td->appendChild($doc->createElement('a',
                  htmlentities($sigdisplay, ENT_COMPAT, 'UTF-8')));
              $link->setAttribute('href', $url_siglinkbase.rawurlencode($data['sig']));
            }
            $td = $tr->appendChild($doc->createElement('td', $data['cnt']));
            $td->setAttribute('class', 'num');
            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $pct).'%'));
            $td->setAttribute('class', 'pct');
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
            if ($show_other_os) {
              $td = $tr->appendChild($doc->createElement('td',
                  count($data['other_flash_ver'])?implode(', ', $data['other_flash_ver']):'None'));
              $td->setAttribute('class', 'otherver '.(count($data['other_flash_ver'])?'some':'none'));
            }
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
                  'channel' => $channel,
                  'version' => $ver,
                  'report' => 'topcrash',
                  'report_sub' => $rname,
                  'display_ver' => $prdverdisplay,
                  'display_rep' => $rep['display_name'].' Top Crash Report');
          file_put_contents($anafpages, json_encode($pages));
        }
      }
      print("\n");
    }
  }
}

// *** helper functions ***

?>
