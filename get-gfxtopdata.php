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

// for how many days back to get the data
$backlog_days = 0;

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

for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
  $anaday = date('Y-m-d', $anatime);
  $anadir = $anaday;
  print('Devices: Looking at GFX data for '.$anaday."\n");
  if (!file_exists($anadir)) { mkdir($anadir); }

  $fgfxdata = 'gfxdata.json';
  $fpages = 'pages.json';
  $fwebmask = '%s.gfxdata.html';
  $fweb = sprintf($fwebmask, $anadir);

  $anafgfxdata = $anadir.'/'.$fgfxdata;
  if (!file_exists($anafgfxdata)) {
    // Need to fetch whole JSON object becasue of bug 898072.
    // Look into history to find nicer code to use when that is fixed.
    $raw_query =
      "SELECT raw_crash "
      .'FROM raw_crashes '
      ."WHERE utc_day_is(date_processed, '".$anaday."');";

    $raw_result = pg_query($db_conn, $raw_query);
    if (!$raw_result) {
      print('--- ERROR: Raw crash query failed for '.$anaday.'!'."\n");
    }

    $gd = array('total_crashes' => 0,
                'full' => array(),
                'adapters' => array());
    while ($raw_row = pg_fetch_array($raw_result)) {
      $raw_crash_data = json_decode($raw_row['raw_crash'], true);
      $gfxdata = array('vendorID' => array_key_exists('AdapterVendorID', $raw_crash_data) ? $raw_crash_data['AdapterVendorID'] : '0x0',
                       'adapterID' => array_key_exists('AdapterDeviceID', $raw_crash_data) ? $raw_crash_data['AdapterDeviceID'] : '0x0',
                       'subsysID' => array_key_exists('AdapterSubsysID', $raw_crash_data) ? $raw_crash_data['AdapterSubsysID'] : '',
                       'driverVer' => array_key_exists('AdapterDriverVersion', $raw_crash_data) ? $raw_crash_data['AdapterDriverVersion'] : '');

      $full_gfx_id = $gfxdata['vendorID'].'::'.$gfxdata['adapterID'].'::'
                      .$gfxdata['subsysID'].'::'.$gfxdata['driverVer'];
      $gfx_adapter = $gfxdata['vendorID'].'::'.$gfxdata['adapterID'];

      if (array_key_exists($full_gfx_id, $gd['full'])) {
        $gd['full'][$full_gfx_id]['count']++;
      }
      else {
        $gd['full'][$full_gfx_id] = $gfxdata;
        $gd['full'][$full_gfx_id]['count'] = 1;
      }
      if (array_key_exists($gfx_adapter, $gd['adapters'])) {
        $gd['adapters'][$gfx_adapter]['count']++;
      }
      else {
        $gd['adapters'][$gfx_adapter] = array('vendorID' => $gfxdata['vendorID'],
                                              'adapterID' => $gfxdata['adapterID']);
        $gd['adapters'][$gfx_adapter]['count'] = 1;
      }
      $gd['total_crashes']++;
    }

    // sort both arrays by count
    foreach (array('full', 'adapters') as $aname) {
      uasort($dd[$aname], 'count_compare');
      uasort($dd[$aname], 'count_compare');
    }

    file_put_contents($anafgfxdata, json_encode($gd));
  }
  else {
    $gd = json_decode(file_get_contents($anafgfxdata), true);
  }

  // debug only line
  print_r($gd); continue;

/*
  $anafweb = $anadir.'/'.$fweb;
  if (!file_exists($anafweb) && $curdd['total_crashes']) {
    // create out an HTML page
    print('Writing'.($type == 'week'?' weekly':' daily').' HTML output'."\n");
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $anadir.' '.$prdverdisplay.' GFX Devices Report'));

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
        $anadir.' '.$prdverdisplay.' GFX Devices Report'));

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
*/
}

// *** helper functions ***

// Comparison function using .count member (reverse sort!)
function count_compare($a, $b) {
  if ($a['.count'] == $b['.count']) { return 0; }
  return ($a['.count'] > $b['.count']) ? -1 : 1;
}

?>
