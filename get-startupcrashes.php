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
                       'channel'=>'release',
                       'version'=>'9.0',
                       'version_regex'=>'9\.0.*',
                       'version_display'=>'9',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'release',
                       'version'=>'10.0',
                       'version_regex'=>'10\.0.*',
                       'version_display'=>'10',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'beta',
                       'version'=>'11.0',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'release',
                       'version'=>'9.0',
                       'version_regex'=>'9\.0.*',
                       'version_display'=>'9',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'release',
                       'version'=>'10.0',
                       'version_regex'=>'10\.0.*',
                       'version_display'=>'10',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'beta',
                       'version'=>'10.0',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'beta',
                       'version'=>'11.0',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'beta',
                       'version'=>'11.0',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'aurora',
                      ),
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

  $sdfile = $prdvershort.'.startup.json';
  $fwebsum = $prdverfile.'.startupsummary.html';

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
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';
    $fdata = $prdvershort.'-startup.csv';
    $ftotal = $prdvershort.'-total.csv';
    $fweb = $anadir.'.'.$prdverfile.'.startup.html';

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
    $anafdata = $anadir.'/'.$fdata;
    if (!file_exists($anafdata)) {
      print('Getting '.$prdverdisplay.' startup data'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $1 is signature, $7 is product, $8 is version, $17 is uptime_seconds, $25 is process type, $29 is release_channel
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
              .(strlen($channel)?' && $29 ~ /^'.awk_quote($channel, '/').'$/':'')
              .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
              .' && $17 <= '.$max_uptime
              .' {printf "%s;%s\n",$25,$1}\'';
      if ($on_moz_server) {
        shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' | sort | uniq -c | sort -nr > '.$anafdata);
      }
      else {
        shell_exec($cmd.' '.$anafcsv.' | sort | uniq -c | sort -nr > '.$anafdata);
      }
    }

    $scrashes = array('all' => 0);
    $anacrashes = array();
    foreach (explode("\n", shell_exec('cat '.$anafdata)) as $crashline) {
      if (preg_match('/^\s*(\d+)\s+([^;]*);(.*)$/', $crashline, $regs)) {
        $ptype = (strlen($regs[2]) && $regs[2] != '\\N')?$regs[2]:'browser';
        $anacrashes[] = array('sig' => $regs[3],
                              'process_type' => $ptype,
                              'count' => $regs[1]);

        if (!in_array($ptype, array_keys($scrashes))) {
          $scrashes[$ptype] = 0;
        }
        $scrashes[$ptype] += $regs[1];
      }
    }

    if (!array_key_exists($anadir, $startupdata) || (filemtime($sdfile) < filemtime($anafdata))) {
      // get total crash count
      $anaftotal = $anadir.'/'.$ftotal;
      if (!file_exists($anaftotal)) {
        print('Getting total crash count'."\n");
        $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
                .(strlen($channel)?' && $29 ~ /^'.awk_quote($channel, '/').'$/':'')
                .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
                .' {printf "%s\n",$1}\'';
        if ($on_moz_server) {
          shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' | wc -l > '.$anaftotal);
        }
        else {
          shell_exec($cmd.' '.$anafcsv.' | wc -l > '.$anaftotal);
        }
      }
      $anatotal = intval(file_get_contents($anaftotal));

      $startupdata[$anadir] = array('startup' => $scrashes,
                                    'total' => $anatotal);

      ksort($startupdata); // sort by date (key), ascending

      file_put_contents($sdfile, json_encode($startupdata));
    }

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) && count($anacrashes)) {
      // create out an HTML page
      print('Write HTML output'."\n");
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
          $link = $td->appendChild($doc->createElement('a', htmlentities($anacrash['sig'])));
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
    }

    print("\n");
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

    foreach (array_reverse($startupdata) as $date=>$sd) {
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
  }
}

// *** helper functions ***

?>
