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
                       'version'=>'4plus',
                       'version_regex'=>'([4-9]|[0-9][0-9])\..*',
                       'version_display'=>'4+',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'12.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'11.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'10.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'12.0a2',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'13.0a1',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'13.0a2',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'14.0a1',
                      ),
                );

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_csvbase = $on_moz_server?'/mnt/crashanalysis/crash_analysis/'
                             :'http://people.mozilla.com/crash_analysis/';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';
$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

foreach ($reports as $rep) {
  $ver = $rep['version'];
  $dashver = strlen($ver)?'-'.$ver:$ver;
  $dotver = strlen($ver)?'.'.$ver:$ver;
  $spcver = strlen($ver)?' '.(isset($rep['version_display'])?$rep['version_display']:$ver):$ver;

  $prd = strtolower($rep['product']);
  $prdshort = ($prd == 'firefox')?'ff':(($prd == 'fennec')?'fn':$prd);

  $fdfile = $prdshort.$dashver.'.flashhang.json';
  if (file_exists($fdfile)) {
    print('Read stored data'."\n");
    $flashdata = json_decode(file_get_contents($fdfile), true);
  }
  else {
    $flashdata = array();
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';
    $fhdplugin = $prdshort.$dashver.'-flashhangdetails-plugin.csv';
    $fhdbrowser = $prdshort.$dashver.'-flashhangdetails-browser.csv';
    $fhdrawdata = $prdshort.$dashver.'-flashhangdetails-raw.json';
    $fhddata = $prdshort.$dashver.'-flashhangdetails.json';
    $fweb = $anadir.'.'.$prd.$dotver.'.flashhangdetails.html';

    // Make sure we have flashdata.
    if (!array_key_exists($anadir, $flashdata)) { break; }

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

    // Get plugin sides of flash hangs for the product.
    $anafhdplugin = $anadir.'/'.$fhdplugin;
    if (!file_exists($anafhdplugin)) {
      print('Getting '.$rep['product'].$spcver.' plugin Flash hang data'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $1 is signature, $7 is product, $8 is version, $22 is flash version, $23 is hang id, $25 is process type
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
              .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
              .' && $22 ~ /^1/ && $23 !~ /^\\\\N$/ && $25 ~ /^plugin$/'
              .' {printf "%s;%s;%s\n",$1,$23,$22}\'';
      if ($on_moz_server) {
        shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafhdplugin);
      }
      else {
        shell_exec($cmd.' '.$anafcsv.' > '.$anafhdplugin);
      }
    }

    // Get browser sides of flash hangs for the product.
    $anafhdbrowser = $anadir.'/'.$fhdbrowser;
    if (!file_exists($anafhdbrowser)) {
      print('Getting '.$rep['product'].$spcver.' browser Flash hang data'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $1 is signature, $7 is product, $8 is version, $22 is flash version, $23 is hang id, $25 is process type
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
              .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
              .' && $25 !~ /^plugin$/'
              .' {printf "%s;%s\n",$1,$23}\'';
      if ($on_moz_server) {
        shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafhdbrowser);
      }
      else {
        shell_exec($cmd.' '.$anafcsv.' > '.$anafhdbrowser);
      }
    }

    // Get summarized list with counts per flash version and hang/crash.
    $anafhdrawdata = $anadir.'/'.$fhdrawdata;
    if (!file_exists($anafhdrawdata)) {
      print('Combine plugin and browser data'."\n");
      // Read browser data into an array.
      $bsigs = array();
      $anabrowser = explode("\n", file_get_contents($anafhdbrowser));
      foreach ($anabrowser as $bline) {
        if (preg_match('/^(.*);(.*)$/', $bline, $regs)) {
          $bsigs[$regs[2]] = $regs[1];
        }
      }

      $anaplugin = explode("\n", file_get_contents($anafhdplugin));
      $rawhangdata = array();
      foreach ($anaplugin as $pluginline) {
        if (preg_match('/^(.*);(.*);(.*)$/', $pluginline, $regs)) {
          $plugin_sig = $regs[1];
          $hangid = $regs[2];
          $flash_ver = $regs[3];
          $browser_sig = array_key_exists($hangid, $bsigs)?$bsigs[$hangid]:null;

          $rawhangdata[$hangid] = array('plugin' => $plugin_sig,
                                        'browser' => $browser_sig,
                                        'flash-ver' => $flash_ver);
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
          $anadir.' '.$rep['product'].$spcver.' Flash Hang Details Report'));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$rep['product'].$spcver.' Flash Hang Details Report'));

      $fd = $flashdata[$anadir];

      // description
      $para = $body->appendChild($doc->createElement('p',
          'Flash hangs by plugin + browser signatures '
          .' on '.$rep['product'].$spcver.','
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
