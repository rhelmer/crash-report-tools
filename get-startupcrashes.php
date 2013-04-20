#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script creates reports on startup crashes.

//TODO:
/*
<smooney> KaiRo: is there a way to flag a signature if it has over 50% startup crashes. Also, can we get a count of the total startup crashes - I wanted to get a %  of total crashes.
<smooney> Just tell me if I am asking for too much stuff :-)
<KaiRo> smooney: flagging a signature for over 50% isn't easy in how I'm gathering info
<smooney> that's fine...forget that
<KaiRo> smooney: totals should be possible
<smooney> The % would be nice if that's doable...the way we show for flash issues
*/

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
                       'channel'=>'esr',
                       'version'=>'17.0',
                       'version_regex'=>'17\.0.*',
                       'version_display'=>'17 ESR',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'release',
                       'version'=>'19.0',
                       'version_regex'=>'19\.0.*',
                       'version_display'=>'19',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'release',
                       'version'=>'20.0',
                       'version_regex'=>'20\.0.*',
                       'version_display'=>'20',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'beta',
                       'version'=>'21.0',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'release',
                       'version'=>'20.0',
                       'version_regex'=>'20\.0.*',
                       'version_display'=>'20',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'beta',
                       'version'=>'21.0',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'nightly',
                      ),
                );

// maximum uptime that is counted as startup (seconds)
$max_uptime = 60;

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';

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

  $sdfile = $prdvershort.'.startup.json';
  $fsumpages = 'summarypages.json';
  $fwebsum = $prdverfile.'.startupsummary.html';

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

  if (!count($pv_ids)) {
    print('--- ERROR: no versions found in DB for '.$prdverdisplay.'!'."\n");
    continue;
  }

  if (file_exists($sdfile)) {
    print('Read stored data'."\n");
    $startupdata = json_decode(file_get_contents($sdfile), true);
  }
  else {
    $startupdata = array();
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Startup: Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $ftotal = $prdvershort.'-total.csv';
    $fdata = $prdvershort.'-startup.json';
    $fpages = 'pages.json';
    $fweb = $anadir.'.'.$prdverfile.'.startup.html';

    // get startup data for the product
    $anafdata = $anadir.'/'.$fdata;
    if (!file_exists($anafdata)) {
      print('Getting '.$prdverdisplay.' startup data'."\n");

      $rep_query =
        'SELECT COUNT(*) as cnt, process_type, signature '
        .'FROM reports_clean LEFT JOIN signatures'
        .' ON (reports_clean.signature_id=signatures.signature_id) '
        .'WHERE product_version_id IN ('.implode(',', $pv_ids).') '
        ." AND utc_day_is(date_processed, '".$anadir."')"
        .' AND EXTRACT(EPOCH FROM uptime) <= '.$max_uptime.' '
        .'GROUP BY process_type, signature '
        .'ORDER BY cnt DESC;';

      $rep_result = pg_query($db_conn, $rep_query);
      if (!$rep_result) {
        print('--- ERROR: Reports/signatures query failed!'."\n");
        continue;
      }

      $scrashes = array('all' => 0);
      $anacrashes = array();
      while ($rep_row = pg_fetch_array($rep_result)) {
        $ptype = strtolower($rep_row['process_type']);
        $anacrashes[] = array('sig' => $rep_row['signature'],
                              'process_type' => $ptype,
                              'count' => $rep_row['cnt']);

        if (!in_array($ptype, array_keys($scrashes))) {
          $scrashes[$ptype] = 0;
        }
        $scrashes[$ptype] += $rep_row['cnt'];
      }
      file_put_contents($anafdata,
                        json_encode(array('anacrashes'=>$anacrashes,
                                          'scrashes'=>$scrashes)));
    }
    else {
      print('Read stored '.$prdverdisplay.' startup data'."\n");
      $sdata = json_decode(file_get_contents($anafdata), true);
      $anacrashes = $sdata['anacrashes'];
      $scrashes = $sdata['scrashes'];
    }

    if (!array_key_exists($anadir, $startupdata) || (filemtime($sdfile) < filemtime($anafdata))) {
      // get total crash count
      $anaftotal = $anadir.'/'.$ftotal;
      if (!file_exists($anaftotal)) {
        print('Getting total crash count'."\n");
        $total_query =
          'SELECT COUNT(*) as cnt '
          .'FROM reports_clean '
          .'WHERE product_version_id IN ('.implode(',', $pv_ids).') '
          ." AND utc_day_is(date_processed, '".$anadir."');";

        $total_result = pg_query($db_conn, $total_query);
        if (!$total_result) {
          print('--- ERROR: Total query failed!'."\n");
          continue;
        }
        $total_row = pg_fetch_array($total_result);
        $anatotal = $total_row['cnt'];
        file_put_contents($anaftotal, $anatotal);
      }
      else {
        $anatotal = intval(file_get_contents($anaftotal));
      }

      $startupdata[$anadir] = array('startup' => $scrashes,
                                    'total' => $anatotal);

      ksort($startupdata); // sort by date (key), ascending

      file_put_contents($sdfile, json_encode($startupdata));
    }

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) && count($anacrashes)) {
      // create out an HTML page
      print('Writing HTML output'."\n");
      $doc = new DOMDocument('1.0', 'utf-8');
      $doc->formatOutput = true; // we want a nice output

      $root = $doc->appendChild($doc->createElement('html'));
      $head = $root->appendChild($doc->createElement('head'));
      $title = $head->appendChild($doc->createElement('title',
          $anadir.' '.$prdverdisplay.' Startup Crash Report'));

      $style = $head->appendChild($doc->createElement('style'));
      $style->setAttribute('type', 'text/css');
      $style->appendChild($doc->createCDATASection(
          '.sig {'."\n"
          .'  font-size: small;'."\n"
          .'}'."\n"
          .'.num, .pct {'."\n"
          .'  text-align: right;'."\n"
          .'}'."\n"
          .'.cent {'."\n"
          .'  text-align: center;'."\n"
          .'}'."\n"
          .'.light {'."\n"
          .'  color: #808080;'."\n"
          .'}'."\n"
      ));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$prdverdisplay.' Startup Crash Report'));

      // description
      $para = $body->appendChild($doc->createElement('p',
          'All signatures of crashes occurring less than '.$max_uptime
          .' seconds after launch on '.$prdverdisplay.'.'));

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', 'Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Process'));
      $th = $tr->appendChild($doc->createElement('th', 'Count'));

      // signatures rows
      foreach ($anacrashes as $anacrash) {
        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td'));
        if (!strlen($anacrash['sig'])) {
          $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
          $link->setAttribute('href', $url_nullsiglink);
        }
        elseif ($anacrash['sig'] == '\N') {
          $td->appendChild($doc->createTextNode('(processing failure - "'.$anacrash['sig'].'")'));
        }
        else {
          // common case, useful signature
          $link = $td->appendChild($doc->createElement('a',
              htmlentities($anacrash['sig'], ENT_COMPAT, 'UTF-8')));
          $link->setAttribute('href', $url_siglinkbase.rawurlencode($anacrash['sig']));
        }
        $td->setAttribute('class', 'sig');
        $td = $tr->appendChild($doc->createElement('td', $anacrash['process_type']));
        if ($anacrash['process_type'] == 'browser') {
          $td->setAttribute('class', 'cent');
        }
        else {
          $td->setAttribute('class', 'light cent');
        }
        $td = $tr->appendChild($doc->createElement('td', $anacrash['count']));
        $td->setAttribute('class', 'num');
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
        array('product' => $rep['product'],
              'channel' => $channel,
              'version' => $ver,
              'report' => 'startup',
              'report_sub' => null,
              'display_ver' => $prdverdisplay,
              'display_rep' => 'Startup Crash Report');
      file_put_contents($anafpages, json_encode($pages));
    }
  }
  // debug only line
  // print_r($anacrashes);

  if (count($startupdata) &&
      (!file_exists($fwebsum) || (filemtime($fwebsum) < filemtime($sdfile)))) {
    // create out an HTML page
    print('Writing HTML output'."\n");
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $prdverdisplay.' Startup Summary Report'));

      $style = $head->appendChild($doc->createElement('style'));
      $style->setAttribute('type', 'text/css');
      $style->appendChild($doc->createCDATASection(
          '.num, .pct {'."\n"
          .'  text-align: right;'."\n"
          .'}'."\n"
      ));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $prdverdisplay.' Startup Summary Report'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Daily sums of browser and other process startup crash reports '
        .'(and percentage of total daily crashes) on '.$prdverdisplay.','
        .' where crashes occurring less than '.$max_uptime
        .' seconds after launch are considered startup.'));

    $table = $body->appendChild($doc->createElement('table'));
    $table->setAttribute('border', '1');

    // table head
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'Date'));
    $th->setAttribute('rowspan', '2');
    $th = $tr->appendChild($doc->createElement('th', 'Browser Process'));
    $th->setAttribute('colspan', '2');
    $th = $tr->appendChild($doc->createElement('th', 'Other Processes'));
    $th->setAttribute('colspan', '2');
    $th = $tr->appendChild($doc->createElement('th', 'All'));
    $th->setAttribute('colspan', '2');
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'absoute'));
    $th = $tr->appendChild($doc->createElement('th', '% of total'));
    $th = $tr->appendChild($doc->createElement('th', 'absolute'));
    $th = $tr->appendChild($doc->createElement('th', '% of total'));
    $th = $tr->appendChild($doc->createElement('th', 'absolute'));
    $th = $tr->appendChild($doc->createElement('th', '% of total'));

    $lastdate = null;
    foreach (array_reverse($startupdata) as $date=>$sd) {
      if (is_null($lastdate) || ($date > $lastdate)) { $lastdate = $date; }
      $all_count = array_sum($sd['startup']);
      $browser_count = intval(@$sd['startup']['browser']);
      $other_count = $all_count - $browser_count;
      $browser_rate = $sd['total'] ? $browser_count / $sd['total'] : 0;
      $other_rate = $sd['total'] ? $other_count / $sd['total'] : 0;
      $all_rate = $sd['total'] ? $all_count / $sd['total'] : 0;

      $tr = $table->appendChild($doc->createElement('tr'));
      $td = $tr->appendChild($doc->createElement('td', $date));
      $td = $tr->appendChild($doc->createElement('td', $browser_count));
      $td->setAttribute('class', 'num');
      $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $browser_rate).'%'));
      $td->setAttribute('class', 'pct');
      $td = $tr->appendChild($doc->createElement('td', $other_count));
      $td->setAttribute('class', 'num');
      $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $other_rate).'%'));
      $td->setAttribute('class', 'pct');
      $td = $tr->appendChild($doc->createElement('td', $all_count));
      $td->setAttribute('class', 'num');
      $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $all_rate).'%'));
      $td->setAttribute('class', 'pct');
    }

    $doc->saveHTMLFile($fwebsum);

    // add the page to the summary pages index
    if (file_exists($fsumpages)) {
      $sumpages = json_decode(file_get_contents($fsumpages), true);
    }
    else {
      $sumpages = array();
    }
    $sumpages[$fwebsum] =
      array('product' => $rep['product'],
            'channel' => $channel,
            'version' => $ver,
            'report' => 'startup',
            'report_sub' => null,
            'last_date' => $lastdate,
            'display_ver' => $prdverdisplay,
            'display_rep' => 'Startup Summary Report');
    file_put_contents($fsumpages, json_encode($sumpages));
  }
  print("\n");
}

// *** helper functions ***

?>
