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
                       'version'=>'13.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'12.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'11.0',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'13.0a2',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'14.0a1',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'14.0a2',
                      ),
                 array('product'=>'Firefox',
                       'version'=>'15.0a1',
                      ),
                );

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_csvbase = $on_moz_server?'/mnt/crashanalysis/crash_analysis/'
                             :'http://people.mozilla.com/crash_analysis/';

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
  $fwebsum = $prd.$dotver.'.flashsummary.html';

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
    $frawdata = $prdshort.$dashver.'-flashhangs-raw.csv';
    $fhangdata = $prdshort.$dashver.'-flashhangs.csv';
    $fweb = $anadir.'.'.$prd.$dotver.'.flashhangs.html';

    if (!array_key_exists($anadir, $flashdata) || !file_exists($anadir.'/'.$fhangdata)) {
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

      // get flash hang data for the product
      $anafrawdata = $anadir.'/'.$frawdata;
      if (!file_exists($anafrawdata)) {
        print('Getting raw '.$rep['product'].$spcver.' Flash/hang data'."\n");
        // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
        // some parts from that split into total and crashcount blocks, though
        // $7 is product, $8 is version, $22 is flash version, $23 is hang id
        $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
               .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
               .' {printf "\t%s;%s\n",($23!="\\\\N"),$22}\'';
        if ($on_moz_server) {
          shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafrawdata);
        }
        else {
          shell_exec($cmd.' '.$anafcsv.' > '.$anafrawdata);
        }
      }

      // get summarized list with counts per flash version and hang/crash
      $anafhangdata = $anadir.'/'.$fhangdata;
      if (!file_exists($anafhangdata)) {
        print('Getting a summary data list'."\n");
        $cmd = 'cat '.$anafrawdata.' | sort | uniq -c | sort -nr > '.$anafhangdata;
        shell_exec($cmd);
      }

      // fill data array with summarized data
      print('Generating sums and structuring data'."\n");
      $anaflashdata = explode("\n", file_get_contents($anafhangdata));
      $fd = array('total' => array('hang' => 0, 'crash' => 0),
                  'total_flash' => array('hang' => 0, 'crash' => 0),
                  'full' => array('hang' => array(), 'crash' => array()),
                  'main' => array('hang' => array(), 'crash' => array()));
      foreach ($anaflashdata as $flashline) {
        if (preg_match('/^\s*(\d+)\s+(.*);(.*)$/', $flashline, $regs)) {
          $htype = $regs[2]?'hang':'crash';
          $fver = intval($regs[3])?$regs[3]:'';
          if (preg_match('/^(\d+\.\d+)/', $fver, $fvregs)) {
            $fvshort = $fvregs[1];
          }
          else {
            $fvshort = $fver;
          }
          if (array_key_exists($fver, $fd['full'][$htype])) {
            $fd['full'][$htype][$fver] += $regs[1];
          }
          else {
            $fd['full'][$htype][$fver] = $regs[1];
          }
          if (array_key_exists($fvshort, $fd['main'][$htype])) {
            $fd['main'][$htype][$fvshort] += $regs[1];
          }
          else {
            $fd['main'][$htype][$fvshort] = $regs[1];
          }
          $fd['total'][$htype] += $regs[1];
          if (strlen($fver)) { $fd['total_flash'][$htype] += $regs[1]; }
        }
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
          $anadir.' '.$rep['product'].$spcver.' Flash Hang Report'));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$rep['product'].$spcver.' Flash Hang Report'));

      $fd = $flashdata[$anadir];

      // description
      $para = $body->appendChild($doc->createElement('p',
          'All Flash versions reported in hangs '
          .' on '.$rep['product'].$spcver.','
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
        $rep['product'].$spcver.' Flash Summary Report'));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $rep['product'].$spcver.' Flash Summary Report'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Daily sums of crash and hang reports on '.$rep['product'].$spcver.','
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
