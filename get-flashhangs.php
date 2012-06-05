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

// reports to gather. fields:
//   product - product name
//   version - empty is all versions

$reports = array(array('product'=>'Firefox',
                       'version'=>'4plus',
                       'version_regex'=>'([4-9]|[0-9][0-9])\..*',
                       'version_display'=>'4+',
                      ),
                 array('product'=>'Firefox',
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
  $fwebsum = $prdverfile.'.flashsummary.html';

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

    $fweb = $anadir.'.'.$prdverfile.'.flashhangs.html';

    if (!array_key_exists($anadir, $flashdata)) {
      print('Fetch Flash/hang data for '.$prdverdisplay."\n");

      $rep_query =
        'SELECT COUNT(*) as cnt, flash_version, LENGTH(hang_id)>0 as is_hang '
        .'FROM reports_clean LEFT JOIN flash_versions'
        .' ON (reports_clean.flash_version_id=flash_versions.flash_version_id) '
        .'WHERE product_version_id IN ('.implode(',', $pv_ids).') '
        ." AND utc_day_is(date_processed, '".$anadir."')"
        .'GROUP BY flash_version, is_hang '
        .'ORDER BY cnt DESC;';

      $rep_result = pg_query($db_conn, $rep_query);
      if (!$rep_result) {
        print('--- ERROR: Flash version query failed!'."\n");
      }

      $fd = array('total' => array('hang' => 0, 'crash' => 0),
                  'total_flash' => array('hang' => 0, 'crash' => 0),
                  'full' => array('hang' => array(), 'crash' => array()),
                  'main' => array('hang' => array(), 'crash' => array()));
      while ($rep_row = pg_fetch_array($rep_result)) {
        $htype = $rep_row['is_hang']?'hang':'crash';
        $fver = preg_match('/^\d/', $rep_row['flash_version'])?$rep_row['flash_version']:'';
        if (preg_match('/^(\d+\.\d+)/', $fver, $fvregs)) {
          $fvshort = $fvregs[1];
        }
        else {
          $fvshort = $fver;
        }
        if (array_key_exists($fver, $fd['full'][$htype])) {
          $fd['full'][$htype][$fver] += $rep_row['cnt'];
        }
        else {
          $fd['full'][$htype][$fver] = $rep_row['cnt'];
        }
        if (array_key_exists($fvshort, $fd['main'][$htype])) {
          $fd['main'][$htype][$fvshort] += $rep_row['cnt'];
        }
        else {
          $fd['main'][$htype][$fvshort] = $rep_row['cnt'];
        }
        $fd['total'][$htype] += $rep_row['cnt'];
        if (strlen($fver)) { $fd['total_flash'][$htype] += $rep_row['cnt']; }
      }
      $flashdata[$anadir] = $fd;

      ksort($flashdata); // sort by date (key), ascending

      file_put_contents($fdfile, json_encode($flashdata));
    }

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) && $flashdata[$anadir]['total_flash']['hang']) {
      // create out an HTML page
      print('Writing HTML output'."\n");
      $doc = new DOMDocument('1.0', 'utf-8');
      $doc->formatOutput = true; // we want a nice output

      $root = $doc->appendChild($doc->createElement('html'));
      $head = $root->appendChild($doc->createElement('head'));
      $title = $head->appendChild($doc->createElement('title',
          $anadir.' '.$prdverdisplay.' Flash Hang Report'));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$prdverdisplay.' Flash Hang Report'));

      $fd = $flashdata[$anadir];

      // description
      $para = $body->appendChild($doc->createElement('p',
          'All Flash versions reported in hangs '
          .' on '.$prdverdisplay.','
          .' compared to how often that Flash version appears in crashes.'));

      $para = $body->appendChild($doc->createElement('p',
          $fd['total_flash']['hang'].' ('
          .sprintf('%.1f', 100 *
                           $fd['total_flash']['hang'] /
                           $fd['total']['hang']).'%) '
          .' of all hangs and '
          .$flashdata[$anadir]['total_flash']['crash'].' ('
          .sprintf('%.1f', 100 *
                           $fd['total_flash']['crash'] /
                           $fd['total']['crash']).'%) '
          .' of all crashes have a Flash version reported, '
          .' only those are included in the report.'));

      foreach (array('main' => 'Grouped Versions',
                     'full' => 'Full Versions')
               as $fvertype=>$title) {

        $h2 = $body->appendChild($doc->createElement('h2', $title));

        $table = $body->appendChild($doc->createElement('table'));
        $table->setAttribute('border', '1');

        // table head
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', 'Version'));
        $th = $tr->appendChild($doc->createElement('th', 'Hangs'));
        $th = $tr->appendChild($doc->createElement('th', '+/-'));
        $th = $tr->appendChild($doc->createElement('th', 'Crashes'));

        foreach ($fd[$fvertype]['hang'] as $fver=>$num) {
          if (strlen($fver)) {
            $hang_rate = $fd['total_flash']['hang']?
                         $num / $fd['total_flash']['hang']:0;
            $cnum = intval(@$fd[$fvertype]['crash'][$fver]);
            $crash_rate = $fd['total_flash']['crash']?
                          $cnum / $fd['total_flash']['crash']:0;

            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td', $fver));

            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $hang_rate).'%'));
            $td->setAttribute('align', 'right');
            $td->setAttribute('title', $num.' hangs');

            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%+.1f', 100 * ($hang_rate - $crash_rate)).'%'));
            $td->setAttribute('align', 'right');
            if ($hang_rate > $crash_rate + .02) {
              $td->setAttribute('style', 'color: red;');
            }
            elseif ($hang_rate > $crash_rate - .02) {
              $td->setAttribute('style', 'color: gray;');
            }
            else {
              $td->setAttribute('style', 'color: black;');
            }

            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $crash_rate).'%'));
            $td->setAttribute('align', 'right');
            $td->setAttribute('title', $cnum.' crashes');
          }
        }
        foreach ($fd[$fvertype]['crash'] as $fver=>$cnum) {
          $crash_rate = $fd['total_flash']['crash']?
                        $cnum / $fd['total_flash']['crash']:0;
          if (!intval(@$fd[$fvertype]['hang'][$fver]) && $crash_rate > 0.0005) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td', $fver));

            $td = $tr->appendChild($doc->createElement('td', '&nbsp;'));
            $td = $tr->appendChild($doc->createElement('td', '&nbsp;'));

            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $crash_rate).'%'));
            $td->setAttribute('align', 'right');
            $td->setAttribute('title', $cnum.' crashes');
          }
        }
      }

      $doc->saveHTMLFile($anafweb);
    }

    print("\n");
  }
  // debug only line
  // print_r($flashdata);

  if (count($flashdata) &&
      (!file_exists($fwebsum) || (filemtime($fwebsum) < filemtime($fdfile)))) {
    // create out an HTML page
    print('Writing HTML output'."\n");
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $prdverdisplay.' Flash Summary Report'));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $prdverdisplay.' Flash Summary Report'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Daily sums of crash and hang reports on '.$prdverdisplay.','
        .' that contain a Flash version, compared to daily total reports.'));

    $para = $body->appendChild($doc->createElement('p',
        'Columns: Flash hangs and crashes in absolute numbers and % of total'
        .' hangs/crashes, sum of both as percentage of total sum of reports.'
        .' For hangs, half of all reports are 100% as reports come in pairs.'));

    $table = $body->appendChild($doc->createElement('table'));
    $table->setAttribute('border', '1');

    // table head
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'Date'));
    $th->setAttribute('rowspan', '2');
    $th = $tr->appendChild($doc->createElement('th', 'Hangs'));
    $th->setAttribute('colspan', '2');
    $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
    $th->setAttribute('colspan', '2');
    $th = $tr->appendChild($doc->createElement('th', 'Sum'));
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'absoute'));
    $th = $tr->appendChild($doc->createElement('th', '% of hangs'));
    $th = $tr->appendChild($doc->createElement('th', 'absolute'));
    $th = $tr->appendChild($doc->createElement('th', '% of crashes'));
    $th = $tr->appendChild($doc->createElement('th', '% of total'));

    foreach (array_reverse($flashdata) as $date=>$fd) {
      $total_hang_pairs = $fd['total']['hang'] / 2;
      $hang_rate = $fd['total']['hang']
                   ? $fd['total_flash']['hang'] / $total_hang_pairs
                   : 0;
      $crash_rate = $fd['total']['crash']
                   ? $fd['total_flash']['crash'] / $fd['total']['crash']
                   : 0;
      $total_rate = $fd['total']['crash'] + $total_hang_pairs
                    ? ($fd['total_flash']['crash'] + $fd['total_flash']['hang'])
                    / ($fd['total']['crash'] + $total_hang_pairs)
                    : 0;
      if ($total_rate) {
        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td', $date));
        $td = $tr->appendChild($doc->createElement('td',
                  $fd['total_flash']['hang']));
        $td->setAttribute('style', 'text-align:right;');
        $td = $tr->appendChild($doc->createElement('td',
                  sprintf('%.1f', 100 * $hang_rate).'%'));
        $td->setAttribute('style', 'text-align:right;');
        $td = $tr->appendChild($doc->createElement('td',
                  $fd['total_flash']['crash']));
        $td->setAttribute('style', 'text-align:right;');
        $td = $tr->appendChild($doc->createElement('td',
                  sprintf('%.1f', 100 * $crash_rate).'%'));
        $td->setAttribute('style', 'text-align:right;');
        $td = $tr->appendChild($doc->createElement('td',
                  sprintf('%.1f', 100 * $total_rate).'%'));
        $td->setAttribute('style', 'text-align:right;');
      }
    }

    $doc->saveHTMLFile($fwebsum);
  }
}

// *** helper functions ***

?>
