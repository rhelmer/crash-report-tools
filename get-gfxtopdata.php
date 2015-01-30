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

require('vendor/autoload.php');
$s3 = Aws\S3\S3Client::factory(array('region' => 'us-west-2'));
$bucket = getenv('S3_BUCKET')?: die('No "S3_BUCKET" config var in found in env!');

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

// *** code start ***

// get current day
$curtime = time();

$db_url = getenv('DATABASE_URL')?:
        die('No "DATABASE_URL " config var in found in env!');
$dbopts = parse_url($db_url);
$db_conn = pg_pconnect('host='.$dbopts['host']
                       .' dbname='.ltrim($dbopts["path"],'/')
                       .' user='.$dbopts['user']
                       .' password='.$dbopts['pass']);
if (!$db_conn) {
  print('ERROR: DB connection failed, aborting!'."\n");
  exit(1);
}
// For info on what data can be accessed, see also
// http://socorro.readthedocs.org/en/latest/databasetabledesc.html
// For the DB schema, see
// https://github.com/mozilla/socorro/blob/master/sql/schema.sql

for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
  $anaday = date('Y-m-d', $anatime);
  $anadir = $anaday;
  print('Devices: Looking at GFX data for '.$anaday."\n");

  $fgfxdata = 'gfxdata.json';
  $fpages = 'pages.json';
  $fwebmask = '%s.gfxdata.html';
  $fweb = sprintf($fwebmask, $anadir);

  $anafgfxdata = $anadir.'/'.$fgfxdata;
  if (!$s3->doesObjectExist($bucket, $anafgfxdata)) {
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

      // We sometimes get 4-digit hex IDs without a leading 0x, add it to make sums work better.
      if (preg_match('/^[0-9a-f]{4}$/', $gfxdata['vendorID'])) {
        $gfxdata['vendorID'] = '0x'.$gfxdata['vendorID'];
      }
      if (preg_match('/^[0-9a-f]{4}$/', $gfxdata['adapterID'])) {
        $gfxdata['adapterID'] = '0x'.$gfxdata['adapterID'];
      }

      $full_gfx_id = $gfxdata['vendorID'].'::'.$gfxdata['adapterID'].'::'
                      .$gfxdata['subsysID'].'::'.$gfxdata['driverVer'];
      $gfx_adapter = $gfxdata['vendorID'].'::'.$gfxdata['adapterID'];

      if (array_key_exists($full_gfx_id, $gd['full'])) {
        $gd['full'][$full_gfx_id]['.count']++;
      }
      else {
        $gd['full'][$full_gfx_id] = $gfxdata;
        $gd['full'][$full_gfx_id]['.count'] = 1;
      }
      if (array_key_exists($gfx_adapter, $gd['adapters'])) {
        $gd['adapters'][$gfx_adapter]['.count']++;
      }
      else {
        $gd['adapters'][$gfx_adapter] = array('vendorID' => $gfxdata['vendorID'],
                                              'adapterID' => $gfxdata['adapterID']);
        $gd['adapters'][$gfx_adapter]['.count'] = 1;
      }
      $gd['total_crashes']++;
    }

    // sort both arrays by count
    foreach (array('full', 'adapters') as $aname) {
      uasort($gd[$aname], 'count_compare');
      uasort($gd[$aname], 'count_compare');
    }

    $s3->upload($bucket, $anafgfxdata, json_encode($gd), 'public-read',
        array('params' => array('ContentType'=>'application/json')));
  }
  else {
    $result = $s3->getObject(array(
        'Bucket' => $bucket,
        'Key'    => $anafgfxdata));
    $gd = json_decode($result['Body'], true);
  }

  // debug only line
  // print_r($gd); continue;

  $anafweb = $anadir.'/'.$fweb;
  if (!$s3->doesObjectExist($bucket, $anafweb) && $gd['total_crashes']) {
    // create out an HTML page
    print('Writing HTML output'."\n");
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $anadir.' GFX Devices Report'));

    $style = $head->appendChild($doc->createElement('style'));
    $style->setAttribute('type', 'text/css');
    $style->appendChild($doc->createCDATASection(
        '.num, .pct {'."\n"
        .'  text-align: right;'."\n"
        .'}'."\n"
    ));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $anadir.' GFX Devices Report'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Graphics device/driver information from all crashes of all products during that day.'));

    $para = $body->appendChild($doc->createElement('p',
        'Total crashes analyzed in this report: '.$gd['total_crashes']));

    $list = $body->appendChild($doc->createElement('ul'));
    foreach (array('adapters' => 'Adapters',
                   'full' => 'Subsystems and Drivers')
             as $name=>$title) {
      $item = $list->appendChild($doc->createElement('li'));
      $link = $item->appendChild($doc->createElement('a', $title));
      $link->setAttribute('href', '#'.$name);
    }

    $section = $body->appendChild($doc->createElement('section'));
    $section->setAttribute('id', 'adapters');

    $header = $section->appendChild($doc->createElement('h2', 'Adapters'));

    $table = $section->appendChild($doc->createElement('table'));
    $table->setAttribute('border', '1');

    // table head
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'Vendor ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Adapter ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
    $th = $tr->appendChild($doc->createElement('th', '%'));

    foreach ($gd['adapters'] as $ad_id=>$adinfo) {
      $idstring = 'ad-'.sanitize_name($ad_id);
      $tr = $table->appendChild($doc->createElement('tr'));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['vendorID']));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['adapterID']));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['.count']));
      $td->setAttribute('class', 'num');
      $td = $tr->appendChild($doc->createElement('td',
          sprintf('%.1f', 100 * $adinfo['.count'] / $gd['total_crashes']).'%'));
      $td->setAttribute('class', 'pct');
    }

    $section = $body->appendChild($doc->createElement('section'));
    $section->setAttribute('id', 'full');

    $header = $section->appendChild($doc->createElement('h2', 'Subsystems and Drivers'));

    $table = $section->appendChild($doc->createElement('table'));
    $table->setAttribute('border', '1');

    // table head
    $tr = $table->appendChild($doc->createElement('tr'));
    $th = $tr->appendChild($doc->createElement('th', 'Vendor ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Adapter ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Subsys ID'));
    $th = $tr->appendChild($doc->createElement('th', 'Driver Version'));
    $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
    $th = $tr->appendChild($doc->createElement('th', '%'));

    foreach ($gd['full'] as $ad_id=>$adinfo) {
      $idstring = 'full-'.sanitize_name($ad_id);
      $tr = $table->appendChild($doc->createElement('tr'));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['vendorID']));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['adapterID']));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['subsysID']));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['driverVer']));
      $td = $tr->appendChild($doc->createElement('td', $adinfo['.count']));
      $td->setAttribute('class', 'num');
      $td = $tr->appendChild($doc->createElement('td',
          sprintf('%.1f', 100 * $adinfo['.count'] / $gd['total_crashes']).'%'));
      $td->setAttribute('class', 'pct');
    }

    $s3->upload($bucket, $anafweb, $doc->saveHTML(), 'public-read',
        array('params' => array('ContentType'=>'text/html')));

    // add the page to the pages index
    $anafpages = $anadir.'/'.$fpages;
    if ($s3->doesObjectExist($bucket, $anafpages)) {
      $result = $s3->getObject(array(
          'Bucket' => $bucket,
          'Key'    => $anafpages));
      $pages = json_decode($result['Body'], true);

    }
    else {
      $pages = array();
    }
    $pages[$fweb] =
      array('product' => null,
            'channel' => null,
            'version' => null,
            'report' => 'gfxdata',
            'report_sub' => null,
            'display_ver' => '',
            'display_rep' => 'GFX Devices Report');
    $s3->upload($bucket, $anafpages, json_encode($pages), 'public-read',
        array('params' => array('ContentType'=>'application/json')));
  }
}

// *** helper functions ***

// Comparison function using .count member (reverse sort!)
function count_compare($a, $b) {
  if ($a['.count'] == $b['.count']) { return 0; }
  return ($a['.count'] > $b['.count']) ? -1 : 1;
}

?>
