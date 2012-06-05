#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves crash stats for builds.

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

// channels
$channels = array('beta' => '14\.0',
                  'release' => '13\.0(\.\d)?',
                  'aurora' => '15\.0a2',
                  'nightly' => '16\.0a1');

// products
$products = array('Firefox','Fennec','FennecAndroid');

// how many days back to look at
$backlog_days = 7;

// notes for specific builds
$notes = array('Firefox-5.0-20110427143820' => '5.0b1',
               'Firefox-5.0-20110517192056' => '5.0b2',
               'Firefox-5.0-20110527093235' => '5.0b3',
               'Firefox-5.0-20110603100923' => '5.0b4',
               'Firefox-5.0-20110608151458' => '5.0b5',
               'Firefox-5.0-20110613165758' => '5.0b6',
               'Firefox-5.0-20110614174314' => '5.0b7',
               'Firefox-6.0-20110705195857' => '6.0b1',
               'Firefox-6.0-20110713171652' => '6.0b2',
               'Firefox-6.0-20110721152715' => '6.0b3',
               'Firefox-6.0-20110729080751' => '6.0b4',
               'Firefox-6.0-20110804030150' => '6.0b5',
               'Firefox-7.0-20110816154714' => '7.0b1',
               'Firefox-7.0-20110824172139' => '7.0b2',
               'Firefox-7.0-20110830100616' => '7.0b3',
               'Firefox-7.0-20110902161802' => '7.0b4',
               'Firefox-7.0-20110908135051' => '7.0b5',
               'Firefox-7.0-20110916091512' => '7.0b6',
               'Firefox-8.0-20110928060149' => '8.0b1',
               'Firefox-8.0-20111006182035' => '8.0b2',
               'Firefox-8.0-20111011182523' => '8.0b3',
               'Firefox-8.0-20111019081014' => '8.0b4',
               'Firefox-8.0-20111026191032' => '8.0b5',
               'Firefox-8.0-20111102223350' => '8.0b6',
               'Firefox-9.0-20111109112850' => '9.0b1',
               'Firefox-9.0-20111116091359' => '9.0b2',
               'Firefox-9.0-20111122192043' => '9.0b3',
               'Firefox-9.0-20111130065942' => '9.0b4',
               'Firefox-9.0-20111206234556' => '9.0b5',
               'Firefox-9.0-20111212185108' => '9.0b6',
               'Firefox-10.0-20111221135037' => '10.0b1',
               'Firefox-10.0-20111228055358' => '10.0b2',
               'Firefox-10.0-20120104111456' => '10.0b3',
               'Firefox-10.0-20120111092507' => '10.0b4',
               'Firefox-10.0-20120118081945' => '10.0b5',
               'Firefox-10.0-20120123235200' => '10.0b6',
               'Firefox-11.0-20120201153158' => '11.0b1',
               'Firefox-11.0-20120208012847' => '11.0b2',
               'Firefox-11.0-20120215222917' => '11.0b3',
               'Firefox-11.0-20120222074758' => '11.0b4',
               'Firefox-11.0-20120228210006' => '11.0b5',
               'Firefox-11.0-20120305181207' => '11.0b6',
               'Firefox-11.0-20120308162450' => '11.0b7',
               'Firefox-11.0-20120310173008' => '11.0b8',
               'Firefox-12.0-20120314195616' => '12.0b1',
               'Firefox-12.0-20120321033733' => '12.0b2',
               'Firefox-12.0-20120328051619' => '12.0b3',
               'Firefox-12.0-20120403211507' => '12.0b4',
               'Firefox-12.0-20120411064248' => '12.0b5',
               'Firefox-12.0-20120417165043' => '12.0b6',
               'Firefox-13.0-20120425123149' => '13.0b1',
               'Firefox-13.0-20120501201020' => '13.0b2',
               'Firefox-13.0-20120509070325' => '13.0b3',
               'Firefox-13.0-20120516113045' => '13.0b4',
               'Firefox-13.0-20120523114940' => '13.0b5',
               'Firefox-13.0-20120528154913' => '13.0b6',
               'Firefox-13.0-20120531155942' => '13.0b7',
               'Firefox-13.0-20120601045813' => 'official',
               'Firefox-12.0-20120420145725' => 'official',
               'Firefox-11.0-20120312181643' => 'official',
               'Firefox-10.0.5-20120531185831' => 'ESR',
               'Firefox-10.0.4-20120420145309' => 'ESR',
               'Firefox-10.0.3-20120309135702' => 'ESR',
               'Firefox-10.0.2-20120216092139' => 'ESR',
               'Firefox-10.0.2-20120215223356' => 'official',
               'Firefox-10.0.1-20120208062825' => 'ESR',
               'Firefox-10.0.1-20120208060813' => 'official',
               'Firefox-10.0-20120130064731' => 'ESR',
               'Firefox-10.0-20120129021758' => 'official',
               'Firefox-9.0.1-20111220165912' => 'official',
               'Firefox-9.0-20111216140209' => 'official',
               'Firefox-8.0.1-20111120135848' => 'official',
               'Firefox-8.0-20111104165243' => 'official',
               'Firefox-7.0.1-20110928134238' => 'official',
               'Firefox-7.0-20110922153450' => 'official',
               'Firefox-6.0.2-20110902133214' => 'official',
               'Firefox-6.0.1-20110830092941' => 'official',
               'Firefox-6.0-20110811165603' => 'official',
               'Firefox-5.0-20110615151330' => 'official',
               'Firefox-5.0.1-20110707182747' => 'official',
               'Firefox-4.0.1-20110413222027' => 'official',
               'Firefox-4.0-20110318052756' => 'official',
              );

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_csvbase = $on_moz_server?'/mnt/crashanalysis/crash_analysis/'
                             :'http://people.mozilla.com/crash_analysis/';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

$fadu = 'build-adu.json';
$adudata = file_exists($fadu)?json_decode(file_get_contents($fadu), true):array();

for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
  $anadir = date('Y-m-d', $anatime);
  print('Looking at data for '.$anadir."\n");
  if (!file_exists($anadir)) { mkdir($anadir); }

  $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';
  $fbcs = 'buildcrashes.csv';
  $fbcrcnt = 'buildcrashcounts.json';
  $fweb = $anadir.'.buildcrashes.html';

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

  // get list of wanted info for the crashes
  $anafbcs = $anadir.'/'.$fbcs;
  if (!file_exists($anafbcs)) {
    print('Getting info for crashes'."\n");
    // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
    // some parts from that split into total and crashcount blocks, though
    // $7 is product, $8 is version, $9 is build ID, $23 is hang id, $25 is process type
    $cmd = 'awk \'-F\t\' \' {printf "\t%s;%s;%s;%s;%s\n",$7,$8,$9,($23!="\\\\N"),$25}\'';
    if ($on_moz_server) {
      shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafbcs);
    }
    else {
      shell_exec($cmd.' '.$anafcsv.' > '.$anafbcs);
    }
  }

  // get build list with counts per build
  $anafbcrcnt = $anadir.'/'.$fbcrcnt;
  if (!file_exists($anafbcrcnt)) {
    print('Getting a list of build crash totals'."\n");
    $cmd = 'cat '.$anafbcs.' | sort | uniq -c';
    $builds = array();
    $types = array();
    $buildlist = explode("\n", shell_exec($cmd));
    foreach ($buildlist as $buildline) {
      if (preg_match('/^\s*(\d+)\s+(.*);(.*);(.*);(.*);(.*)$/', $buildline, $regs)) {
        $idx = $regs[2].'-'.$regs[3].'-'.$regs[4]; // e.g. Firefox-5.0-20110517192056
        if (!array_key_exists($idx, $builds)) {
          $builds[$idx] = array('product' => $regs[2],
                                'version' => $regs[3],
                                'buildid' => $regs[4]);
        }
        if ($idx != 'product-version-build') {
          $type = ($regs[6]=='\\N')?'browser':$regs[6];
          $sum_id = 'num_'.($regs[5]?'hang':'crash').'_'.$type;
          $builds[$idx][$sum_id] = $regs[1];
          if (!in_array($type, $types)) { $types[] = $type; }
        }
      }
    }
    sort($types);
    foreach ($builds as $idx=>$builddata) {
      $totals = array('all' => 0, 'hang' => 0, 'crash' => 0);
      foreach ($types as $type) {
        $totals[$type] = 0;
      }
      foreach (array('hang','crash') as $issue) {
        foreach ($types as $type) {
          $sum_id = 'num_'.$issue.'_'.$type;
          $builds[$idx][$sum_id] = intval(@$builddata[$sum_id]);
          $totals['all'] += $builds[$idx][$sum_id];
          $totals[$issue] += $builds[$idx][$sum_id];
          $totals[$type] += $builds[$idx][$sum_id];
        }
        $builds[$idx]['num_'.$issue] = $totals[$issue];
      }
      foreach ($types as $type) {
        $builds[$idx]['num_'.$type] = $totals[$type];
      }
      $builds[$idx]['num_total'] = $totals['all'];
    }
    file_put_contents($anafbcrcnt, json_encode($builds));
  }
  else {
    $builds = json_decode(file_get_contents($anafbcrcnt), true);
  }

  $anafweb = $anadir.'/'.$fweb;
  if (!file_exists($anafweb)) {
    // create out an HTML page
    print('Write HTML output'."\n");

    $cbuilds = array();
    foreach ($builds as $idx=>$builddata) {
      foreach ($products as $product) {
        foreach ($channels as $channel=>$regex) {
          if (($builddata['product'] == $product) &&
              (preg_match('/^'.$regex.'$/', $builddata['version'], $regs))) {
            $cbuilds[$product][$channel][$idx] = $builddata;
            unset($builds[$idx]);
          }
        }
      }
    }

    $buildadu = array();
    $reladu = array();
    $calcadu = array();
    if (@$adudata[$anadir]) {
      foreach ($adudata[$anadir] as $badu) {
        $idx = $badu['product'].'-'.$badu['version'].'-'.$badu['buildid'];
        $buildadu[$idx] = $badu['adu'];
        $reladu[$idx] = array_key_exists('release-adu', $badu)?$badu['release-adu']:0;
        if (array_key_exists('throttle', $badu)) {
          $calcadu[$idx] = $buildadu[$idx] * $badu['throttle'];
        }
        elseif ($reladu[$idx] && array_key_exists('release-throttle', $badu)) {
          $calcadu[$idx] = ($buildadu[$idx] - $reladu[$idx]) +
                           $reladu[$idx] * $badu['release-throttle'];
        }
        else {
          $calcadu[$idx] = $buildadu[$idx];
        }
      }
    }

    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $anadir.' Crashes / Build'));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $anadir.' Crashes / Build'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Total crashes seen on '.$anadir
        .' per different build '
        .'(product + version + Build ID combination).'));

    $channels['other'] = '';
    $list = $body->appendChild($doc->createElement('ul'));
    foreach ($products as $product) {
      foreach ($channels as $channel=>$regexp) {
        $item = $list->appendChild($doc->createElement('li'));
        $link = $item->appendChild($doc->createElement('a',
            $product.' '.$channel));
        $link->setAttribute('href', '#'.$product.'-'.$channel);
      }
    }

    foreach ($products as $product) {
      foreach ($channels as $channel=>$regexp) {
        $listbuilds = ($channel!='other')?@$cbuilds[$product][$channel]:$builds;
        $h2 = $body->appendChild($doc->createElement('h2',
            $product.' '.$channel));
        $h2->setAttribute('id', $product.'-'.$channel);

        if (count($listbuilds)) {
          $table = $body->appendChild($doc->createElement('table'));
          $table->setAttribute('border', '1');

          // table head
          $tr = $table->appendChild($doc->createElement('tr'));
          $th = $tr->appendChild($doc->createElement('th', 'Product'));
          $th = $tr->appendChild($doc->createElement('th', 'Version'));
          $th = $tr->appendChild($doc->createElement('th', 'Build ID'));
          $th = $tr->appendChild($doc->createElement('th', 'Notes'));
          $fields = array();
          foreach ($builds['product-version-build'] as $gfld=>$gdataone) {
            if (preg_match('/^num_([^_]*)$/', $gfld, $regs)) {
              $fields[] = $gfld;
              $th = $tr->appendChild($doc->createElement('th', $regs[1]));
            }
          }
          $th = $tr->appendChild($doc->createElement('th', 'normalized'));
          $th->setAttribute('title',
              'total minus half of all hangs (as hangs always come in pairs)');

          // signatures rows
          foreach ($listbuilds as $idx=>$builddata) {
            if (preg_match('/^'.preg_quote($product).'-/', $idx)) {
              $tr = $table->appendChild($doc->createElement('tr'));
              $td = $tr->appendChild($doc->createElement('td',
                  htmlentities($builddata['product'], ENT_COMPAT, 'UTF-8')));
              $td = $tr->appendChild($doc->createElement('td',
                  htmlentities($builddata['version'], ENT_COMPAT, 'UTF-8')));
              $td = $tr->appendChild($doc->createElement('td',
                  htmlentities($builddata['buildid'], ENT_COMPAT, 'UTF-8')));
              $td = $tr->appendChild($doc->createElement('td',
                  htmlentities(@$notes[$idx], ENT_COMPAT, 'UTF-8')));
              if (@$buildadu[$idx]) {
                if (@$notes[$idx]) { $td->appendChild($doc->createElement('br')); }
                if ($buildadu[$idx] > 10000000) { // 10M
                  $adu_out = sprintf('%d', $buildadu[$idx]/1000000).'M';
                }
                elseif ($buildadu[$idx] > 1000000) { // 1M
                  $adu_out = sprintf('%.1f', $buildadu[$idx]/1000000).'M';
                }
                elseif ($buildadu[$idx] > 10000) { // 10k
                  $adu_out = sprintf('%d', $buildadu[$idx]/1000).'k';
                }
                elseif ($buildadu[$idx] > 1000) { // 1k
                  $adu_out = sprintf('%.1f', $buildadu[$idx]/1000).'k';
                }
                else {
                  $adu_out = sprintf('%d', $buildadu[$idx]);
                }

                $small = $td->appendChild($doc->createElement('small',
                    $adu_out.' ADU'));
                $small->setAttribute('style', 'color:GrayText;');
                if ($reladu[$idx]) {
                  $small->setAttribute('title',
                      sprintf('%d', 100 * $reladu[$idx] / $buildadu[$idx])
                      .'% on release');
                }
              }
              foreach ($fields as $fld) {
                $sfld = str_replace('num_', '', $fld);
                $ptype = !in_array($sfld, array('hang','crash','total'))?$sfld:'any';
                $htype = in_array($sfld, array('hang','crash'))?$sfld:'any';

                $td = $tr->appendChild($doc->createElement('td'));
                $td->setAttribute('align', 'right');
                $link = $td->appendChild($doc->createElement('a', $builddata[$fld]));
                $link->setAttribute('href',
                    'https://crash-stats.mozilla.com/query/query?product='.$builddata['product']
                    .'&version=All'
  //                  .'&version='.$builddata['product'].'%3A'.$builddata['version']    // would be correct but is broken on Socorro, see bug 679328
                    .'&range_value=1&range_unit=days&&date='.$anadir.'+23%3A59%3A59'
                    .'&query_type=contains&query=&reason='
                    .'&build_id='.$builddata['buildid']
                    .'&process_type='.$ptype.'&hang_type='.$htype
                    .'&do_query=1');
                if (@$buildadu[$idx]) {
                  $td->appendChild($doc->createElement('br'));
                  $small = $td->appendChild($doc->createElement('small',
                      sprintf('%.3f', $builddata[$fld]*100/$calcadu[$idx])));
                  $small->setAttribute('title', 'per 100 ADU');
                  $small->setAttribute('style', 'color:GrayText;');
                }
              }
              $norm_total = $builddata['num_total'] - intval($builddata['num_hang']/2);
              $td = $tr->appendChild($doc->createElement('td', $norm_total));
              $td->setAttribute('align', 'right');
              if (@$buildadu[$idx]) {
                $td->appendChild($doc->createElement('br'));
                $small = $td->appendChild($doc->createElement('small',
                    sprintf('%.3f', $norm_total*100/$calcadu[$idx])));
                $small->setAttribute('title', 'per 100 ADU');
                $small->setAttribute('style', 'color:GrayText;');
              }
            }
          }
        }
        else {
          $body->appendChild($doc->createElement('p', 'No data found.'));
        }
      }
    }

    $doc->saveHTMLFile($anafweb);
  }
  print("\n");
}

// *** helper functions ***

?>
