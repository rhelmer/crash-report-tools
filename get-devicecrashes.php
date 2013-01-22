#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script creates reports on crashes per mobile device.

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

$reports = array(array('product'=>'FennecAndroid',
                       'channel'=>'release',
                       'version'=>'18.0',
                       'version_regex'=>'18\.0.*',
                       'version_display'=>'18',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'beta',
                       'version'=>'19.0',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'nightly',
                       'weekly'=>true,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'aurora',
                       'weekly'=>true,
                      ),
                );

// ignorable (as not actionable) app note fields that result in unknown devices
$ignore_unknown_notes = array(
  '\\N',
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'.",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'.",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'. | xpcom_runtime_abort(###",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'. | xpcom_runtime_abort(###",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'. | WebGL? GL Context? GL Context+ | WebGL+",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'. | WebGL? GL Context? GL Context+ WebGL+",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Imagination Technologies'.",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Imagination Technologies'.",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Imagination Technologies'. | xpcom_runtime_abort(###",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'NVIDIA'.",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'NVIDIA'.",
  "EGL? EGL+ AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'NVIDIA'. | xpcom_runtime_abort(###",
  "WebGL? EGL? EGL+ | GL Context? GL Context+ | WebGL+",
  "WebGL? EGL? EGL+ | GL Context? GL Context+ | WebGL+ | xpcom_runtime_abort(###",
  "xpcom_runtime_abort(###",
);

// maximum uptime that is counted as startup (seconds)
$max_uptime = 60;

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_csvbase = $on_moz_server?'/mnt/crashanalysis/crash_analysis/'
                             :'http://people.mozilla.com/crash_analysis/';
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

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fdevdata = $prdvershort.'-devices.json';
    $fpages = 'pages.json';
    $fwebmask = '%s.'.$prdverfile.'.devices.html';
    $fweb = sprintf($fwebmask, $anadir);
    $fwebweek = $anadir.'.'.$prdverfile.'.devices.weekly.html';

    $anafdevdata = $anadir.'/'.$fdevdata;
    if (!file_exists($anafdevdata)) {
      // get all crash IDs and signatures for the selected versions
      $rep_query =
        'SELECT uuid, signature '
        .'FROM reports_clean LEFT JOIN signatures'
        .' ON (reports_clean.signature_id=signatures.signature_id) '
        .'WHERE product_version_id IN ('.implode(',', $pv_ids).') '
        ." AND utc_day_is(date_processed, '".$anadir."');";

      $rep_result = pg_query($db_conn, $rep_query);
      if (!$rep_result) {
        print('--- ERROR: Reports/signatures query failed!'."\n");
      }

      print('Getting summarized device data'."\n");
      $dd = array('total_crashes' => 0,
                  'devices' => array());
      while ($rep_row = pg_fetch_array($rep_result)) {
        $sig = $rep_row['signature'];
        $crash_id = $rep_row['uuid'];

        // get app notes for this crash ID (using day for performance)
        $notes_query =
          'SELECT app_notes '
          .'FROM reports '
          ."WHERE uuid='".$crash_id."'"
          ." AND utc_day_is(date_processed, '".$anadir."');";

        $notes_result = pg_query($db_conn, $notes_query);
        if (!$notes_result) {
          print('--- ERROR: app_notes query failed!'."\n");
          $appnotes = '';
        }
        else {
          $notes_row = pg_fetch_array($notes_result);
          $appnotes = $notes_row['app_notes'];
        }

        if (preg_match("/Model: '(.*)', Product: '.*', Manufacturer: '(.*)', Hardware: '.*'.*\n[^:\s]+:(\d\.[^\/\s]+|AOSP)\/[^:\s]+:[^\s]*keys/", $appnotes, $regs)) {
          $devname = ucfirst($regs[2].' '.$regs[1]);
          $andver = $regs[3];
        }
        elseif (preg_match("/Model: '(.*)', Product: '.*', Manufacturer: '(.*)', Hardware: '.*'/", $appnotes, $regs)) {
          $devname = ucfirst($regs[2].' '.$regs[1]);
          $andver = null;
        }
        elseif (preg_match("/ -- Model: (.*), Product: .*, Manufacturer: (.*), Hardware: .*'/", $appnotes, $regs)) {
          $devname = ucfirst($regs[2].' '.$regs[1]);
          $andver = null;
        }
        elseif (preg_match("/([^\|]+ [^\|]+)\n[^:\s]+:(\d\.[^\/\s]+|AOSP)\/[^:\s]+:[^\s]*keys/", $appnotes, $regs)) {
          $devname = ucfirst($regs[1]);
          $andver = $regs[2];
        }
        elseif (preg_match("/([^\|]+ [^\|]+)\n(unknown|xxxxxx)/", $appnotes, $regs)) {
          $devname = ucfirst($regs[1]);
        }
        else {
          $devname = 'unknown';
          $andver = null;
          if (!in_array($appnotes, $ignore_unknown_notes)) {
            print('*** unknown device - notes: '.$appnotes."\n");
          }
        }
        // reduce dubled vendor names in device names
        $devname = str_replace('HTC HTC', 'HTC', $devname);
        $devname = str_replace('Samsung SAMSUNG-', 'Samsung ', $devname);
        $devname = str_replace('SAMSUNG SAMSUNG-', 'Samsung ', $devname);
        $devname = str_replace('Acer Acer', 'Acer ', $devname);
        $devname = str_replace('Asus ASUS', 'ASUS ', $devname);
        $devname = str_replace('Sony Sony', 'Sony', $devname);
        $devname = str_replace('HUAWEI HUAWEI', 'HUAWEI', $devname);
        $devname = str_replace('Hp HP', 'HP', $devname);
        $devname = str_replace('Dell Inc. Dell', 'Dell', $devname);
        $devname = str_replace('Archos ARCHOS', 'Archos', $devname);
        $devname = str_replace('MID MID', 'MID', $devname);
        $devname = str_replace('MEDION MEDION', 'Medion', $devname);
        $devname = str_replace('Amazon Amazon ', 'Amazon ', $devname);
        $devname = str_replace('Unknown Amazon ', 'Amazon ', $devname);
        if (!array_key_exists($devname, $dd['devices'])) {
          $dd['devices'][$devname] = array('android_versions' => array(),
                                           'signatures' => array(),
                                           'crashes' => 0);
        }
        if ($andver && !in_array($andver, $dd['devices'][$devname]['android_versions'])) {
          $dd['devices'][$devname]['android_versions'][] = $andver;
        }
        if (in_array($sig, array_keys($dd['devices'][$devname]['signatures']))) {
          $dd['devices'][$devname]['signatures'][$sig]++;
        }
        else {
          $dd['devices'][$devname]['signatures'][$sig] = 1;
        }
        $dd['devices'][$devname]['crashes']++;
        $dd['total_crashes']++;
      }

      // sort devices alphabetically, signatures by count
      ksort($dd['devices']);
      foreach ($dd['devices'] as $devname=>$devdata) {
        sort($dd['devices'][$devname]['android_versions']);
        arsort($dd['devices'][$devname]['signatures']);
      }

      file_put_contents($anafdevdata, json_encode($dd));
    }
    else {
      $dd = json_decode(file_get_contents($anafdevdata), true);
    }

    // debug only line
    // print_r($dd);

    $webreports = array('day' => $fweb);
    if (@$rep['weekly']) { $webreports['week'] = $fwebweek; }

    foreach ($webreports as $type=>$fwebcur) {
      $anafweb = $anadir.'/'.$fwebcur;
      if ($type == 'week') {
        if (!file_exists($anafweb)) {
          // assemble 7-day "weekly" overview
          print('Calculating weekly data'."\n");
          $curdd = $dd;
          for ($pastday = 1; $pastday < 7; $pastday++) {
            $pasttime = strtotime($anadir.' -'.$pastday.' day');
            $pastdir = date('Y-m-d', $pasttime);
            print('Adding '.$pastdir);
            $pastfdevdata = $pastdir.'/'.$fdevdata;
            if (file_exists($pastfdevdata)) {
              // Load that data and merge it into $curcd.
              $pastdd = json_decode(file_get_contents($pastfdevdata), true);
              $curdd['total_crashes'] += $pastdd['total_crashes'];
              foreach ($pastdd['devices'] as $devname=>$devdata) {
                print(':');
                if (array_key_exists($devname, $curdd['devices'])) {
                  print('.');
                  $curdd['devices'][$devname]['android_versions'] += $devdata['android_versions'];
                  $curdd['devices'][$devname]['crashes'] += $devdata['crashes'];
                  foreach ($devdata['signatures'] as $sig=>$count) {
                    if (array_key_exists($sig, $curdd['devices'][$devname]['signatures'])) {
                      $curdd['devices'][$devname]['signatures'][$sig] += $count;
                    }
                    else {
                      $curdd['devices'][$devname]['signatures'][$sig] = $count;
                    }
                  }
                }
                else {
                  print('.');
                  $dd['devices'][$devname] = $devdata;
                }
              }
              print("\n");
            }
            else { print(' - '.$pastfdevdata.' not found.'."\n"); }
          }
          if ($curdd['total_crashes'] == $dd['total_crashes']) {
            // Not more than what the day had, so set to 0 which omits creating an HTML.
            $curdd['total_crashes'] = 0;
          }
          else {
            // sort devices alphabetically, signatures by count
            ksort($curdd['devices']);
            foreach ($curdd['devices'] as $devname=>$devdata) {
              sort($curdd['devices'][$devname]['android_versions']);
              arsort($curdd['devices'][$devname]['signatures']);
            }
          }
        }
      }
      else {
        // single-day data
        $curdd = $dd;
      }

      if (!file_exists($anafweb) && $curdd['total_crashes']) {
        // create out an HTML page
        print('Writing'.($type == 'week'?' weekly':' daily').' HTML output'."\n");
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true; // we want a nice output

        $root = $doc->appendChild($doc->createElement('html'));
        $head = $root->appendChild($doc->createElement('head'));
        $title = $head->appendChild($doc->createElement('title',
            $anadir.' '.$prdverdisplay.($type == 'week'?' Weekly':'')
            .' Device Crash Report'));

        $style = $head->appendChild($doc->createElement('style'));
        $style->setAttribute('type', 'text/css');
        $style->appendChild($doc->createCDATASection(
            '.sig {'."\n"
            .'  font-size: small;'."\n"
            .'  text-align: left;'."\n"
            .'}'."\n"
            .'.num, .pct {'."\n"
            .'  text-align: right;'."\n"
            .'}'."\n"
            .'tr.devheader, tr.sigheader {'."\n"
            .'  background: #EEEEAA;'."\n"
            .'}'."\n"
            .'tr.devheader:target {'."\n"
            .'  background: #EECCAA;'."\n"
            .'}'."\n"
            .'tr.subheader {'."\n"
            .'  background: #FFFFCC;'."\n"
            .'  color: #808080;'."\n"
            .'  font-size: small;'."\n"
            .'}'."\n"
        ));

        $body = $root->appendChild($doc->createElement('body'));
        $h1 = $body->appendChild($doc->createElement('h1',
            $anadir.' '.$prdverdisplay.($type == 'week'?' Weekly':'')
            .' Device Crash Report'));

        // description
        $para = $body->appendChild($doc->createElement('p',
            'All signatures of crashes listed by the devices '
            .' they seem to be happening on.'));

        $para = $body->appendChild($doc->createElement('p',
            'Total crashes analyzed in this report: '.$curdd['total_crashes']
            .($type == 'week'?' - covering 7 days up to and including '.$anadir.'.':'')));

        $list = $body->appendChild($doc->createElement('ul'));
        foreach (array('overview' => 'Device Overview',
                       'devices' => 'Top Signatures Per Device',
                       'sigs' => 'Devices Per Signature')
                 as $name=>$title) {
          $item = $list->appendChild($doc->createElement('li'));
          $link = $item->appendChild($doc->createElement('a', $title));
          $link->setAttribute('href', '#'.$name);
        }

        $section = $body->appendChild($doc->createElement('section'));
        $section->setAttribute('id', 'bydevice');

        $header = $section->appendChild($doc->createElement('h2', 'Device Overview'));
        $header->setAttribute('id', 'overview');

        $table = $section->appendChild($doc->createElement('table'));
        $table->setAttribute('border', '1');

        // table head
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', 'Device'));
        $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
        $th = $tr->appendChild($doc->createElement('th', '%'));

        // create a list of all devices to be sorted by crash totals
        $devtotals = array();
        foreach ($curdd['devices'] as $devname=>$devdata) {
          $devtotals[$devname] = $devdata['crashes'];
        }
        arsort($devtotals);

        // variable for by-signature device stats
        $ddbysig = array();

        foreach ($devtotals as $devname=>$count) {
          $idstring = 'dev-'.sanitize_name($devname);
          $tr = $table->appendChild($doc->createElement('tr'));
          $td = $tr->appendChild($doc->createElement('td'));
          $link = $td->appendChild($doc->createElement('a', htmlentities($devname, ENT_COMPAT, 'UTF-8')));
          $link->setAttribute('href', '#'.$idstring);
          $td = $tr->appendChild($doc->createElement('td', $count));
          $td->setAttribute('class', 'num');
          $td = $tr->appendChild($doc->createElement('td',
              sprintf('%.1f', 100 * $count / $curdd['total_crashes']).'%'));
          $td->setAttribute('class', 'pct');
        }

        $header = $section->appendChild($doc->createElement('h2',
            'Top Signatures Per Device'));
        $header->setAttribute('id', 'devices');

        $table = $section->appendChild($doc->createElement('table'));
        $table->setAttribute('border', '1');

        foreach ($curdd['devices'] as $devname=>$devdata) {
          $idstring = 'dev-'.sanitize_name($devname);

          $tr = $table->appendChild($doc->createElement('tr'));
          $tr->setAttribute('id', $idstring);
          $tr->setAttribute('class', 'devheader');
          $th = $tr->appendChild($doc->createElement('th', htmlentities($devname, ENT_COMPAT, 'UTF-8')));
          $th->setAttribute('colspan', 2);

          $tr = $table->appendChild($doc->createElement('tr'));
          $tr->setAttribute('class', 'subheader');
          $td = $tr->appendChild($doc->createElement('td',
              'Android versions: '.(count($devdata['android_versions'])?implode(', ',$devdata['android_versions']):'unknown')));
          $td->setAttribute('colspan', 2);

          // signatures rows
          foreach ($devdata['signatures'] as $sig=>$count) {
            // add element to by-signature array
            if (!array_key_exists($sig, $ddbysig)) {
              $ddbysig[$sig] = array('devices' => array(), '.count' => 0);
            }
            $ddbysig[$sig]['devices'][$devname] = $count;
            $ddbysig[$sig]['.count'] += $count;

            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td'));
            if (!strlen($sig)) {
              $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
              $link->setAttribute('href', $url_nullsiglink);
            }
            elseif ($sig == '\N') {
              $td->appendChild($doc->createTextNode('(processing failure - "'.$sig.'")'));
            }
            else {
              // common case, useful signature
              $link = $td->appendChild($doc->createElement('a',
                  htmlentities($sig, ENT_COMPAT, 'UTF-8')));
              $link->setAttribute('href', $url_siglinkbase.rawurlencode($sig));
            }
            $td->setAttribute('class', 'sig');
            $td = $tr->appendChild($doc->createElement('td', $count));
            $td->setAttribute('class', 'num');
          }
        }

        $section = $body->appendChild($doc->createElement('section'));
        $section->setAttribute('id', 'bysig');

        // sort signatures and devices by count
        uasort($ddbysig, 'count_compare'); // sort by count, highest-first
        foreach ($ddbysig as $sig=>$sigdata) {
          arsort($ddbysig[$sig]['devices']);
        }

        $header = $section->appendChild($doc->createElement('h2',
            'Devices Per Signature'));
        $header->setAttribute('id', 'sigs');

        $table = $section->appendChild($doc->createElement('table'));
        $table->setAttribute('border', '1');

        foreach ($ddbysig as $sig=>$sigdata) {
          $tr = $table->appendChild($doc->createElement('tr'));
          $tr->setAttribute('class', 'sigheader');
          $th = $tr->appendChild($doc->createElement('th'));
          $th->setAttribute('class', 'sig');
          if (!strlen($sig)) {
            $link = $th->appendChild($doc->createElement('a', '(empty signature)'));
            $link->setAttribute('href', $url_nullsiglink);
          }
          elseif ($sig == '\N') {
            $th->appendChild($doc->createTextNode('(processing failure - "'.$sig.'")'));
          }
          else {
            // common case, useful signature
            $link = $th->appendChild($doc->createElement('a',
                htmlentities($sig, ENT_COMPAT, 'UTF-8')));
            $link->setAttribute('href', $url_siglinkbase.rawurlencode($sig));
          }
          $th = $tr->appendChild($doc->createElement('th', $sigdata['.count']));
          $th->setAttribute('class', 'num');

          // signatures rows
          foreach ($sigdata['devices'] as $devname=>$count) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($devname, ENT_COMPAT, 'UTF-8')));
            $td->setAttribute('class', 'devname');
            $td = $tr->appendChild($doc->createElement('td', $count));
            $td->setAttribute('class', 'num');
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
        $pages[$fwebcur] =
          array('product' => $rep['product'],
                'channel' => $channel,
                'version' => $ver,
                'report' => 'devices',
                'report_sub' => $type,
                'display_ver' => $prdverdisplay,
                'display_rep' => ($type == 'week'?'Weekly ':'')
                                 .'Device Crash Report');
        file_put_contents($anafpages, json_encode($pages));
      }
    }

    print("\n");
  }
}

// *** helper functions ***

// Comparison function using .count member (reverse sort!)
function count_compare($a, $b) {
  if ($a['.count'] == $b['.count']) { return 0; }
  return ($a['.count'] > $b['.count']) ? -1 : 1;
}


?>
