#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script looks at details of Flash hangs.

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

// set higher memory limit so we can combine a lot of plugin and browser data
ini_set('memory_limit', '256M');

// make sure new files are set to -rw-r--r-- permissions
umask(022);

// set default time zone - right now, always the one the server is in!
date_default_timezone_set('America/Los_Angeles');


// *** data gathering variables ***

// reports to gather. fields:
//   product - product name
//   version - empty is all versions

$reports = array(array('product'=>'Firefox',
                       'version'=>'14.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'13.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'12.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'14.0a2',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'15.0a1',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'15.0a2',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'16.0a1',
                      ),
                );

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';
$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';

// File storing the DB access data - including password!
$fdbsecret = '/home/rkaiser/.socorro-prod-dbsecret.json';

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

foreach ($reports as $rep) {
  $channel = array_key_exists('channel', $rep)?$rep['channel']:'';
  $ver = array_key_exists('version', $rep)?$rep['version']:'';
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

  $fdfile = $prdvershort.'.flashhang.json';
  if (file_exists($fdfile)) {
    print('Read stored data'."\n");
    $flashdata = json_decode(file_get_contents($fdfile), true);
  }
  else {
    $flashdata = array();
  }

  $pv_ids = array();
  $pv_query =
    'SELECT product_version_id '
    .'FROM product_versions '
    ."WHERE product_name = '".$rep['product']."'"
    .(strlen($ver)
      ?' AND release_version '.(isset($rep['version_regex'])
                                ?"~ '^".$rep['version_regex']."$'"
                                :"= '".$ver."'")
      :'')
    .(strlen($channel)?" AND build_type = '".ucfirst($channel)."'":'')
    .';';
  $pv_result = pg_query($db_conn, $pv_query);
  if (!$pv_result) {
    print('--- ERROR: product version query failed!'."\n");
  }
  while ($pv_row = pg_fetch_array($pv_result)) {
    $pv_ids[] = $pv_row['product_version_id'];
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fhdrawdata = $prdvershort.'-flashhangdetails-raw.json';
    $fhddata = $prdvershort.'-flashhangdetails.json';
    $fweb = $anadir.'.'.$prdverfile.'.flashhangdetails.html';

    // Make sure we have flashdata.
    if (!array_key_exists($anadir, $flashdata)) { break; }

    // Get summarized list with counts per flash version and hang/crash.
    $anafhdrawdata = $anadir.'/'.$fhdrawdata;
    if (!file_exists($anafhdrawdata)) {
      print('Getting combined '.$prdverdisplay.' plugin and browser data'."\n");
      $rep_query =
        'SELECT plug.hang_id as hang_id, flash_version,'
        .' plug.signature as plugin_sig, brwsr.signature as browser_sig '
        .'FROM'
        .' (SELECT signature, hang_id, flash_version_id'
        .' FROM reports_clean LEFT JOIN signatures'
        .' ON (reports_clean.signature_id=signatures.signature_id)'
        .' WHERE product_version_id IN ('.implode(',', $pv_ids).') '
        ." AND utc_day_is(date_processed, '".$anadir."')"
        ." AND LENGTH(hang_id)>0 AND process_type = 'plugin') as plug "
        .'LEFT JOIN'
        .' (SELECT signature, hang_id'
        .' FROM reports_clean LEFT JOIN signatures'
        .' ON (reports_clean.signature_id=signatures.signature_id)'
        .' WHERE product_version_id IN ('.implode(',', $pv_ids).') '
        ." AND utc_day_is(date_processed, '".$anadir."')"
        ." AND LENGTH(hang_id)>0 AND process_type = 'Browser') as brwsr "
        .'ON (plug.hang_id=brwsr.hang_id) '
        .'LEFT JOIN flash_versions'
        .' ON (plug.flash_version_id=flash_versions.flash_version_id);';

      $rep_result = pg_query($db_conn, $rep_query);
      if (!$rep_result) {
        print('--- ERROR: Flash version query failed!'."\n");
      }

      $rawhangdata = array();
      while ($rep_row = pg_fetch_array($rep_result)) {
        // filter out the odd [blank] versions that seem to appear
        if (preg_match('/^\d/', $rep_row['flash_version'])) {
          $rawhangdata[$rep_row['hang_id']] =
            array('plugin' => $rep_row['plugin_sig'],
                  'browser' => $rep_row['browser_sig'],
                  'flash-ver' => $rep_row['flash_version']);
        }
      }
      file_put_contents($anafhdrawdata, json_encode($rawhangdata));
    }
    else {
      $rawhangdata = json_decode(file_get_contents($anafhdrawdata), true);
    }

    // get summarized list with counts per flash version and hang/crash
    $anafhddata = $anadir.'/'.$fhddata;
    if (!file_exists($anafhddata)) {
      print('Summarize Flash hang data'."\n");
      $hangdata = array();
      foreach ($rawhangdata as $hangentry) {
        $sig_id = bin2hex(hash('md5', $hangentry['plugin'].' ----- '.$hangentry['browser']));

        // Care that entries in the array exist.
        if (!array_key_exists($sig_id, $hangdata)) {
          $hangdata[$sig_id] = array('plugin' => $hangentry['plugin'],
                                     'browser' => $hangentry['browser'],
                                     'count' => 0,
                                     'count_flash' => array());
        }
        if (!array_key_exists($hangentry['flash-ver'], $hangdata[$sig_id]['count_flash'])) {
          $hangdata[$sig_id]['count_flash'][$hangentry['flash-ver']] = 0;
        }

        // Increase counters.
        $hangdata[$sig_id]['count']++;
        $hangdata[$sig_id]['count_flash'][$hangentry['flash-ver']]++;
      }

      uasort($hangdata, 'count_compare'); // sort by count, highest-first
      foreach ($hangdata as $sig_id=>$hangentry) {
        arsort($hangdata[$sig_id]['count_flash']); // sort by value, highest-first
      }

      file_put_contents($anafhddata, json_encode($hangdata));
    }
    else {
      $hangdata = json_decode(file_get_contents($anafhddata), true);
    }

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) && count($hangdata)) {
      // create out an HTML page
      print('Writing HTML output'."\n");
      $doc = new DOMDocument('1.0', 'utf-8');
      $doc->formatOutput = true; // we want a nice output

      $root = $doc->appendChild($doc->createElement('html'));
      $head = $root->appendChild($doc->createElement('head'));
      $title = $head->appendChild($doc->createElement('title',
          $anadir.' '.$prdverdisplay.' Flash Hang Details Report'));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$prdverdisplay.' Flash Hang Details Report'));

      $fd = $flashdata[$anadir];

      // description
      $para = $body->appendChild($doc->createElement('p',
          'Flash hangs by plugin + browser signatures '
          .' on '.$prdverdisplay.','
          .' with data on affected Flash versions.'));

      $para = $body->appendChild($doc->createElement('p',
          $fd['total_flash']['hang'].' ('
          .sprintf('%.1f', 100 *
                           $fd['total_flash']['hang'] /
                           $fd['total']['hang']).'%) '
          .' of all hangs in that version have a Flash version reported.'));

      $fver = array();
      foreach ($fd['full']['hang'] as $fv=>$fvcnt) {
        if (strlen($fv)) {
          $fver[] = $fv.' ('.sprintf('%.1f', 100 * $fvcnt / $fd['total_flash']['hang']).'%)';
        }
      }
      $para = $body->appendChild($doc->createElement('p',
        'The following Flash versions appear in those: '.implode(', ', $fver)));

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', 'Plugin Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Browser Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Count'));
      $th = $tr->appendChild($doc->createElement('th', 'Flash Versions'));

      foreach ($hangdata as $hangentry) {
        $tr = $table->appendChild($doc->createElement('tr'));

        $td = $tr->appendChild($doc->createElement('td'));
        $td->setAttribute('style', 'font-size: small;');
        if (!strlen($hangentry['plugin'])) {
          $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
          $link->setAttribute('href', $url_nullsiglink.'&process_type=plugin&hang_type=hang');
        }
        elseif ($hangentry['plugin'] == '\N') {
          $td->appendChild($doc->createTextNode('(processing failure - "'.$hangentry['plugin'].'")'));
        }
        else {
          // common case, useful signature
          $link = $td->appendChild($doc->createElement('a',
              htmlentities(preg_replace('/^hang \|\s*/', '', $hangentry['plugin']))));
          $link->setAttribute('href', $url_siglinkbase.rawurlencode($hangentry['plugin']));
        }

        $td = $tr->appendChild($doc->createElement('td'));
        $td->setAttribute('style', 'font-size: small;');
        if (is_null($hangentry['browser'])) {
          $td->appendChild($doc->createTextNode('(no report found)'));
        }
        elseif (!strlen($hangentry['browser'])) {
          $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
          $link->setAttribute('href', $url_nullsiglink.'&process_type=browser&hang_type=hang');
        }
        elseif ($hangentry['browser'] == '\N') {
          $td->appendChild($doc->createTextNode('(processing failure - "'.$hangentry['browser'].'")'));
        }
        else {
          // common case, useful signature
          $link = $td->appendChild($doc->createElement('a',
              htmlentities(preg_replace('/^hang \|\s*/', '', $hangentry['browser']))));
          $link->setAttribute('href', $url_siglinkbase.rawurlencode($hangentry['browser']));
        }

        $td = $tr->appendChild($doc->createElement('td', $hangentry['count']));
        $td->setAttribute('align', 'right');

        $fver = array();
        foreach ($hangentry['count_flash'] as $fv=>$fvcnt) {
          $fver[] = $fv.' ('.sprintf('%.1f', 100 * $fvcnt / $hangentry['count']).'%)';
        }
        $td = $tr->appendChild($doc->createElement('td', implode(', ', $fver)));
      }

      $doc->saveHTMLFile($anafweb);
    }

    print("\n");
  }
  // debug only line
  // print_r($flashdata);
}

// *** helper functions ***

// Comparison function using count member (reverse sort!)
function count_compare($a, $b) {
  if ($a['count'] == $b['count']) { return 0; }
  return ($a['count'] > $b['count']) ? -1 : 1;
}

?>
