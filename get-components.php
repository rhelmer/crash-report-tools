#!/usr/bin/php
<?php

// get-components.php 0.1
// analyze crashes by component
//
// (c) Robert Kaiser <kairo@kairo.at>
// This script can be used and modified free by anyone, as long as the copyright line stays intact.
// There is no warranty in any way that this script does not harm your system.

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

// set default time zone - right now, always the one the server is in!
date_default_timezone_set('America/Los_Angeles');


// *** data gathering variables ***

// reports to gather. fields:
//   product - product name
//   version - empty is all versions

$reports = array(array('product'=>'Firefox',
                       'version'=>'7.0',
                       'version_regex'=>'7\.0(\.\d)?',
                       'version_display'=>'7',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'beta',
                       'version'=>'8.0',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'Fennec',
                       'version'=>'7.0',
                       'version_regex'=>'7\.0(\.\d)?',
                       'version_display'=>'7',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'beta',
                       'version'=>'8.0',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'aurora',
                      ),
                );

// maximum shown signatures per component
$max_shown_sigs = 10;

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
  $prdvershort = (($prd == 'firefox')?'ff':(($prd == 'fennec')?'fn':$prd))
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
    $frawdata = $prdvershort.'-components-raw.csv';
    $fcompdata = $prdvershort.'-components.json';
    $fweb = $anadir.'.'.$prdverfile.'.components.html';

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

    // get components data for the product
    $anafrawdata = $anadir.'/'.$frawdata;
    if (!file_exists($anafrawdata)) {
      print('Getting raw '.$prdverdisplay.' components data'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $1 is signature, $7 is product, $8 is version, $20 is topmost_filename, $29 is release_channel
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
              .(strlen($channel)?' && $29 ~ /^'.awk_quote($channel, '/').'$/':'')
              .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
              .' {printf "%s;%s\n",$1,$20}\'';
      if ($on_moz_server) {
        shell_exec('gunzip --stdout '.$anafcsvgz.' | '.$cmd.' > '.$anafrawdata);
      }
      else {
        shell_exec($cmd.' '.$anafcsv.' > '.$anafrawdata);
      }
    }

    // get summarized component data
    $anafcompdata = $anadir.'/'.$fcompdata;
    if (!file_exists($anafcompdata)) {
      print('Getting summarized component data'."\n");
      $anarawdata = explode("\n", file_get_contents($anafrawdata));
      $cd = array('total' => 0,
                  'tree' => array());
      foreach ($anarawdata as $rawline) {
        $cd['total']++;
        if (preg_match('/^(.*);hg:([^:]+):([^:]+):([^:]+)$/', $rawline, $regs)) {
          $sig = $regs[1]; // signature
          $repo = $regs[2]; // currently ignorable, e.g. 'hg.mozilla.org/mozilla-central'
          $path = $regs[3]; // *the meat*, e.g. 'dom/plugins/ipc/PluginInstanceChild.cpp'
          $rev = $regs[4]; // hg revision, e.g. 'f41df039db03'
          if (preg_match('/^obj\-[a-z]+\/([^\/]+)(.*)$/', $path, $oregs) ||
              preg_match('/^([^\/]+)(.*)$/', $path, $pregs)) {
            $objdir = isset($oregs[1]); // is this in the objdir?
            $toplevel = $objdir?$oregs[1]:$pregs[1]; // toplevel directory
            $subfile = $objdir?'(objdir)'.$oregs[2]:$pregs[2]; // file path inside the toplevel
          }
          else { // should never ever be hit
            $toplevel = '.unknown';
            $subfile = $path;
          }
        }
        elseif (preg_match('/^(.*);F_?\d+_+/', $rawline, $regs)) {
          $sig = $regs[1]; // signature
          $toplevel = '.flash';
          $subfile = null;
        }
        elseif (preg_match('/^(.*);(.*)$/', $rawline, $regs)) { // always matches
          $sig = $regs[1]; // signature
          $path = $regs[2]; // should be a file path of some kind
          if (preg_match('/^(\.\.\/)+([^\/]+)(.*)$/', $path, $pregs)) { // relative paths give modules
            $toplevel = $pregs[2]; // toplevel directory
            $subfile = $pregs[3]; // file path inside the toplevel
          }
          else { // absolute paths --> "unknown"
            $toplevel = '.unknown';
            $subfile = $path;
          }
        }
        // add fields / bump counts
        addCount($cd['tree'], $toplevel);
        if ($cd['tree'][$toplevel]['.count'] == 1) {
          if (!is_null($subfile)) {
            $cd['tree'][$toplevel]['.files'] = array();
          }
          $cd['tree'][$toplevel]['.sigs'] = array();
        }
        if (!is_null($subfile)) {
          addCount($cd['tree'][$toplevel]['.files'], $subfile);
        }
        addCount($cd['tree'][$toplevel]['.sigs'], $sig);
      }

      uasort($cd['tree'], 'count_compare'); // sort by count, highest-first
      foreach ($cd['tree'] as $path=>$pdata) {
        if (array_key_exists('.files', $pdata)) {
          uasort($cd['tree'][$path]['.files'], 'count_compare'); // sort by count, highest-first
        }
        if (array_key_exists('.sigs', $pdata)) {
          uasort($cd['tree'][$path]['.sigs'], 'count_compare'); // sort by count, highest-first
        }
      }

      file_put_contents($anafcompdata, json_encode($cd));
    }
    else {
      $cd = json_decode(file_get_contents($anafcompdata), true);
    }

    // debug only line
    //print_r($cd);

    $anafweb = $anadir.'/'.$fweb;
    if (!file_exists($anafweb) && $cd['total']) {
      // create out an HTML page
      print('Writing HTML output'."\n");
      $doc = new DOMDocument('1.0', 'utf-8');
      $doc->formatOutput = true; // we want a nice output

      $root = $doc->appendChild($doc->createElement('html'));
      $head = $root->appendChild($doc->createElement('head'));
      $title = $head->appendChild($doc->createElement('title',
          $anadir.' '.$prdverdisplay.' Crash Components Report'));
      $script = $head->appendChild($doc->createElement('script'));
      $script->setAttribute('type', 'text/javascript');
      $script->appendChild($doc->createCDATASection(
          'function toggleVisibility(aClass) {'."\n"
          .'  // state to set it to!'."\n"
          .'  var topElem = document.getElementById("top_" + aClass);'."\n"
          .'  var open = topElem.className != "toplevel-open";'."\n"
          .'  if (open) {'."\n"
          .'    topElem.className = "toplevel-open";'."\n"
          .'    topElem.textContent = "-";'."\n"
          .'  }'."\n"
          .'  else {'."\n"
          .'    topElem.className = "toplevel-closed";'."\n"
          .'    topElem.textContent = "+";'."\n"
          .'  }'."\n"
          .'  var rows = document.getElementsByClassName("cat_" + aClass);'."\n"
          .'  for (var i = 0; i < rows.length; ++i) {'."\n"
          .'    if (open)'."\n"
          .'      rows[i].style.display = "table-row";'."\n"
          .'    else'."\n"
          .'      rows[i].style.display = "none";'."\n"
          .'  }'."\n"
          .'}'."\n"
      ));
      $style = $head->appendChild($doc->createElement('style'));
      $style->setAttribute('type', 'text/css');
      $style->appendChild($doc->createCDATASection(
          '.toplevel-open {'."\n"
          .'  background-color: #DDFFDD;'."\n"
          .'  text-align: center;'."\n"
          .'}'."\n"
          .'.toplevel-closed {'."\n"
          .'  background-color: #FFDDDD;'."\n"
          .'  text-align: center;'."\n"
          .'}'."\n"
          .'.sig {'."\n"
          .'  font-size: small;'."\n"
          .'}'."\n"
          .'.num, .pct {'."\n"
          .'  text-align: right;'."\n"
          .'}'."\n"
      ));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$prdverdisplay.' Crash Components Report'));

      // description
      $para = $body->appendChild($doc->createElement('p',
          'Splitting all reports  on '.$prdverdisplay
          .' by the file location of their topmost frame.'
          .' (Actually, it\'s the topmost frame on the stack which we can'
          .' derive a file location for, which does not necessarily correspond'
          .' exactly to the frame(s) used in the signature.)'));

      $header = $body->appendChild($doc->createElement('h2', 'Sums &amp; Files'));
      $header->setAttribute('id', 'files');

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', 'Toplevel'));
      $th = $tr->appendChild($doc->createElement('th', 'Path'));
      $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
      $th = $tr->appendChild($doc->createElement('th', '%'));

      foreach ($cd['tree'] as $path=>$pdata) {
        $classname = str_replace('.', '_', $path);
        $tr = $table->appendChild($doc->createElement('tr'));
        $td = $tr->appendChild($doc->createElement('td'));
        $link = $td->appendChild($doc->createElement('a', $path));
        $link->setAttribute('href', '#'.$path);
        if (array_key_exists('.files', $pdata)) {
          $td = $tr->appendChild($doc->createElement('td', '+'));
          $td->setAttribute('id', 'top_'.$classname);
          $td->setAttribute('class', 'toplevel-closed');
          $td->setAttribute('onclick', 'toggleVisibility("'.$classname.'");');
        }
        else {
          $td->setAttribute('colspan', '2');
        }
        $td = $tr->appendChild($doc->createElement('td', $pdata['.count']));
        $td->setAttribute('class', 'num');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', 100 * $pdata['.count'] / $cd['total']).'%'));
        $td->setAttribute('class', 'pct');
        if (array_key_exists('.files', $pdata)) {
          foreach ($pdata['.files'] as $fname=>$fdata) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $tr->setAttribute('class', 'cat_'.$classname);
            $tr->setAttribute('style', 'display: none;');
            $td = $tr->appendChild($doc->createElement('td'));
            $td = $tr->appendChild($doc->createElement('td',
                strlen($fname)?$fname:'(empty)'));
            $td = $tr->appendChild($doc->createElement('td', $fdata['.count']));
            $td->setAttribute('class', 'num');
            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $fdata['.count'] / $cd['total']).'%'));
            $td->setAttribute('class', 'pct');
          }
        }
      }

      $header = $body->appendChild($doc->createElement('h2',
          'Top '.$max_shown_sigs.' Signatures Per Component'));
      $header->setAttribute('id', 'sigs');

      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('border', '1');

      // table head
      $tr = $table->appendChild($doc->createElement('tr'));
      $th = $tr->appendChild($doc->createElement('th', '#'));
      $th = $tr->appendChild($doc->createElement('th', 'Signature'));
      $th = $tr->appendChild($doc->createElement('th', 'Crashes'));
      $th = $tr->appendChild($doc->createElement('th', '%'));

      foreach ($cd['tree'] as $path=>$pdata) {
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', $path));
        $th->setAttribute('colspan', '4');
        $th->setAttribute('id', $path);
        if (array_key_exists('.sigs', $pdata)) {
          $count = 1;
          foreach ($pdata['.sigs'] as $sname=>$sdata) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td', $count));
            $td->setAttribute('class', 'num');
            $td = $tr->appendChild($doc->createElement('td'));
            $td->setAttribute('class', 'sig');
            if (!strlen($sname)) {
              $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
              $link->setAttribute('href', $url_nullsiglink);
            }
            elseif ($sname == '\N') {
              $td->appendChild($doc->createTextNode('(processing failure - "'.$sname.'")'));
            }
            else {
              // common case, useful signature
              $link = $td->appendChild($doc->createElement('a', htmlentities($sname)));
              $link->setAttribute('href', $url_siglinkbase.rawurlencode($sname));
            }
            $td = $tr->appendChild($doc->createElement('td', $sdata['.count']));
            $td->setAttribute('class', 'num');
            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $sdata['.count'] / $cd['total']).'%'));
            $td->setAttribute('class', 'pct');
            $count++;
            if ($count > $max_shown_sigs) { break; }
          }
        }
      }

      $doc->saveHTMLFile($anafweb);
    }

    print("\n");
  }
  // debug only line
  // print_r($flashdata);
}

// *** helper functions ***

// Comparison function using .count member (reverse sort!)
function count_compare($a, $b) {
  if ($a['.count'] == $b['.count']) { return 0; }
  return ($a['.count'] > $b['.count']) ? -1 : 1;
}

// Function to safely escape variables handed to awk
function awk_quote($string) {
  return strtr(preg_replace("/([\]\[^$.*?+{}\\\\()|])/", '\\\\$1', $string),
               array('`'=>'\140',"'"=>'\047'));
}

// Function to bump the counter of an element or initialize it
function addCount(&$basevar, $sub) {
  if (array_key_exists($sub, $basevar)) {
    $basevar[$sub]['.count']++;
  }
  else {
    $basevar[$sub]['.count'] = 1;
  }
}
?>
