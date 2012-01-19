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

$reports = array(array('product'=>'Fennec',
                       'channel'=>'release',
                       'version'=>'8.0',
                       'version_regex'=>'8\.0.*',
                       'version_display'=>'8',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'release',
                       'version'=>'9.0',
                       'version_regex'=>'9\.0.*',
                       'version_display'=>'9',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'beta',
                       'version'=>'10.0',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'aurora',
                      ),
                );

// ignorable (as not actionable) app note fields that result in unknown devices
$ignore_unknown_notes = array(
  '\\N',
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'.",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Imagination Technologies'.",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'NVIDIA'.",
  "EGL? EGL+ | AdapterVendorID: , AdapterDeviceID: . | AdapterDescription: 'Android'. | WebGL? GL Context? GL Context+ | WebGL+",
  "WebGL? EGL? EGL+ | GL Context? GL Context+ | WebGL+",
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

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

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

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';
    $frawdata = $prdvershort.'-devices-raw.csv';
    $fdevdata = $prdvershort.'-devices.json';
    $fweb = $anadir.'.'.$prdverfile.'.devices.html';

    // make sure we have the crashdata csv
    if ($on_moz_server) {
      $anafcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
      if (!file_exists($anafcsvgz)) { break; }
    }
    else {
      $anafcsv = $anadir.'/'.$fcsv;
      if (!file_exists($anafcsv)) {
        print('Fetching '.$anafcsv.' from the web'."\n");
        $webcsvgz = $url_csvbase.date('Ymd', $anatime).'/'.$fcsv.'.gz';
        if (copy($webcsvgz, $anafcsv.'.gz')) { shell_exec('gzip -d '.$anafcsv.'.gz'); }
      }
      if (!file_exists($anafcsv)) { break; }
    }

    // get startup data for the product
    $anafrawdata = $anadir.'/'.$frawdata;
    if (!file_exists($anafrawdata)) {
      print('Getting raw '.$prdverdisplay.' device data'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $1 is signature, $7 is product, $8 is version, $26 is app_notes, $29 is release_channel
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
              .(strlen($channel)?' && $29 ~ /^'.awk_quote($channel, '/').'$/':'')
              .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
              .' {printf "%s!!!%s\n",$1,$26}\'';
      if ($on_moz_server) {
        shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafrawdata);
      }
      else {
        shell_exec($cmd.' '.$anafcsv.' > '.$anafrawdata);
      }
    }

    // get summarized device data
    $anafdevdata = $anadir.'/'.$fdevdata;
    if (!file_exists($anafdevdata)) {
      print('Getting summarized device data'."\n");
      $dd = array('total_crashes' => 0,
                  'devices' => array());
      foreach (explode("\n", shell_exec('cat '.$anafrawdata)) as $crashline) {
        if (!strlen($crashline)) { break; }
        list($sig, $appnotes) = explode('!!!', $crashline);
        if (preg_match("/Model: '.*', Product: '(.*)', Manufacturer: '(.*)', Hardware: '.*'/", $appnotes, $regs)) {
          $devname = ucfirst($regs[2].' '.$regs[1]);
          $andver = null;
        }
        elseif (preg_match('/^\s*([^\|]+ [^\|]+) \| [^:]+:(\d\.[^\/]+|AOSP)\/[^:]+:/', $appnotes, $regs)) {
          $devname = ucfirst($regs[1]);
          $andver = $regs[2];
        }
        elseif (preg_match('/^\s*([^\|]+ [^\|]+) \| (unknown|xxxxxx)/', $appnotes, $regs)) {
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
        $devname = str_replace('Sony Sony', 'Sony', $devname);
        $devname = str_replace('Dell Inc. Dell', 'Dell', $devname);
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

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) && $dd['total_crashes']) {
      // create out an HTML page
      print('Write HTML output'."\n");
      $doc = new DOMDocument('1.0', 'utf-8');
      $doc->formatOutput = true; // we want a nice output

      $root = $doc->appendChild($doc->createElement('html'));
      $head = $root->appendChild($doc->createElement('head'));
      $title = $head->appendChild($doc->createElement('title',
          $anadir.' '.$prdverdisplay.' Device Crash Report'));

      $style = $head->appendChild($doc->createElement('style'));
      $style->setAttribute('type', 'text/css');
      $style->appendChild($doc->createCDATASection(
          '.sig {'."\n"
          .'  font-size: small;'."\n"
          .'}'."\n"
          .'.num, .pct {'."\n"
          .'  text-align: right;'."\n"
          .'}'."\n"
          .'tr.devheader {'."\n"
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
          $anadir.' '.$prdverdisplay.' Device Crash Report'));

      // description
      $para = $body->appendChild($doc->createElement('p',
          'All signatures of crashes listed by the devices '
          .' they seem to be happening on.'));

      $para = $body->appendChild($doc->createElement('p',
          'Total crashes analyzed in this report: '.$dd['total_crashes']));

      $header = $body->appendChild($doc->createElement('h2', 'Overview'));
      $header->setAttribute('id', 'files');

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', 'Device'));
      $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
      $th = $tr->appendChild($doc->createElement('th', '%'));

      // create a list of all device to be sorted by crash totals
      $devtotals = array();
      foreach ($dd['devices'] as $devname=>$devdata) {
        $devtotals[$devname] = $devdata['crashes'];
      }
      arsort($devtotals);

      foreach ($devtotals as $devname=>$count) {
        $idstring = 'dev-'.strtolower(str_replace(array(' ', '.'), '_', $devname));
        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td'));
        $link = $td->appendChild($doc->createElement('a', htmlentities($devname)));
        $link->setAttribute('href', '#'.$idstring);
        $td = $tr->appendChild($doc->createElement('td', $count));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', 100 * $count / $dd['total_crashes']).'%'));
        $td->setAttribute('class', 'pct');
      }

      $header = $body->appendChild($doc->createElement('h2',
          'Top Signatures Per Device'));
      $header->setAttribute('id', 'sigs');

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      foreach ($dd['devices'] as $devname=>$devdata) {
        $idstring = 'dev-'.strtolower(str_replace(array(' ', '.'), '_', $devname));

        $tr = $table->appendChild($doc->createElement('tr'));
        $tr->setAttribute('id', $idstring);
        $tr->setAttribute('class', 'devheader');
        $th = $tr->appendChild($doc->createElement('th', htmlentities($devname)));
        $th->setAttribute('colspan', 2);

        $tr = $table->appendChild($doc->createElement('tr'));
        $tr->setAttribute('class', 'subheader');
        $td = $tr->appendChild($doc->createElement('td',
            'Android versions: '.(count($devdata['android_versions'])?implode(', ',$devdata['android_versions']):'unknown')));
        $td->setAttribute('colspan', 2);

        // signatures rows
        foreach ($devdata['signatures'] as $sig=>$count) {
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
            $link = $td->appendChild($doc->createElement('a', htmlentities($sig)));
            $link->setAttribute('href', $url_siglinkbase.rawurlencode($sig));
          }
          $td->setAttribute('class', 'sig');
          $td = $tr->appendChild($doc->createElement('td', $count));
          $td->setAttribute('class', 'num');
        }
      }

      $doc->saveHTMLFile($anafweb);
    }
    print("\n");
  }
  // debug only line
  // print_r($dd);
}

// *** helper functions ***
// Function to safely escape variables handed to awk
function awk_quote($string) {
  return strtr(preg_replace("/([\]\[^$.*?+{}\\\\()|])/", '\\\\$1', $string),
               array('`'=>'\140',"'"=>'\047'));
}
?>
