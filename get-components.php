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
                       'version'=>'9.0a1',
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

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fcsv = date('Ymd', $anatime).'-pub-crashdata.csv';
    $frawdata = $prdshort.$dashver.'-components-raw.csv';
    $fcompdata = $prdshort.$dashver.'-components.json';
    $fweb = $anadir.'.'.$prd.$dotver.'.components.html';

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
      print('Getting raw '.$rep['product'].$spcver.' components data'."\n");
      // simplified from http://people.mozilla.org/~chofmann/crash-stats/top-crash+also-found-in40.sh
      // some parts from that split into total and crashcount blocks, though
      // $7 is product, $8 is version, $20 is topmost_filename
      $cmd = 'awk \'-F\t\' \'$7 ~ /^'.$rep['product'].'$/'
              .(strlen($ver)?' && $8 ~ /^'.(isset($rep['version_regex'])?$rep['version_regex']:awk_quote($ver, '/')).'$/':'')
              .' {printf "%s\n",$20}\'';
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
        if (preg_match('/^hg:([^:]+):([^:]+):([^:]+)$/', $rawline, $regs)) {
          $repo = $regs[1]; // currently ignorable, e.g. 'hg.mozilla.org/mozilla-central'
          $path = $regs[2]; // *the meat*, e.g. 'dom/plugins/ipc/PluginInstanceChild.cpp'
          $rev = $regs[3]; // hg revision, e.g. 'f41df039db03'
          if (preg_match('/^([^\/]+)(.*)$/', $path, $pregs)) {
            if (array_key_exists($pregs[1], $cd['tree'])) {
              $cd['tree'][$pregs[1]]['.count']++;
            }
            else {
              $cd['tree'][$pregs[1]] = array('.count' => 1,
                                             '.files' => array());
            }
            if (array_key_exists($pregs[2], $cd['tree'][$pregs[1]]['.files'])) {
              $cd['tree'][$pregs[1]]['.files'][$pregs[2]]['.count']++;
            }
            else {
              $cd['tree'][$pregs[1]]['.files'][$pregs[2]]['.count'] = 1;
            }
          }
          else {
            if (array_key_exists('.unknown', $cd['tree'])) {
              $cd['tree']['.unknown']['.count']++;
            }
            else {
              $cd['tree']['.unknown'] = array('.count' => 1,
                                              '.files' => array());
            }
            if (array_key_exists($path, $cd['tree']['.unknown']['.files'])) {
              $cd['tree']['.unknown']['.files'][$path]['.count']++;
            }
            else {
              $cd['tree']['.unknown']['.files'][$path]['.count'] = 1;
            }
          }
        }
        elseif (preg_match('/^F_?\d+_+/', $rawline, $regs)) {
          if (array_key_exists('.flash', $cd['tree'])) {
            $cd['tree']['.flash']['.count']++;
          }
          else {
            $cd['tree']['.flash']['.count'] = 1;
          }
        }
        else {
          if (array_key_exists('.nonhg', $cd['tree'])) {
            $cd['tree']['.nonhg']['.count']++;
          }
          else {
            $cd['tree']['.nonhg'] = array('.count' => 1,
                                          '.files' => array());
          }
          if (array_key_exists($rawline, $cd['tree']['.nonhg']['.files'])) {
            $cd['tree']['.nonhg']['.files'][$rawline]['.count']++;
          }
          else {
            $cd['tree']['.nonhg']['.files'][$rawline]['.count'] = 1;
          }
        }
      }
      ksort($cd); // sort by date (key), ascending

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
          $anadir.' '.$rep['product'].$spcver.' Crash Components Report'));
      $script = $head->appendChild($doc->createElement('script'));
      $script->setAttribute('type', 'text/javascript');
      $script->appendChild($doc->createCDATASection(
          'function toggleVisibility(aClass) {'."\n"
          .'  // state to set it to!'."\n"
          .'  var topElem = document.getElementById(aClass);'."\n"
          .'  var open = topElem.className != "toplevel-open";'."\n"
          .'  if (open) {'."\n"
          .'    topElem.className = "toplevel-open";'."\n"
          .'    topElem.textContent = "-";'."\n"
          .'  }'."\n"
          .'  else {'."\n"
          .'    topElem.className = "toplevel-closed";'."\n"
          .'    topElem.textContent = "+";'."\n"
          .'  }'."\n"
          .'  var rows = document.getElementsByClassName(aClass);'."\n"
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
          .'}'."\n"
          .'.toplevel-closed {'."\n"
          .'  background-color: #FFDDDD;'."\n"
          .'}'."\n"
      ));

      $body = $root->appendChild($doc->createElement('body'));
      $h1 = $body->appendChild($doc->createElement('h1',
          $anadir.' '.$rep['product'].$spcver.' Crash Components Report'));

      // description
      $para = $body->appendChild($doc->createElement('p',
          'Splitting all reports '
          .' on '.$rep['product'].$spcver
          .' by the file location of their topmost frame.'));

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
        $td = $tr->appendChild($doc->createElement('td', $path));
        if (array_key_exists('.files', $pdata)) {
          $td = $tr->appendChild($doc->createElement('td', '+'));
          $td->setAttribute('id', $classname);
          $td->setAttribute('class', 'toplevel-closed');
          $td->setAttribute('onclick', 'toggleVisibility("'.$classname.'");');
        }
        else {
          $td->setAttribute('colspan', '2');
        }
        $td = $tr->appendChild($doc->createElement('td', $pdata['.count']));
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', 100 * $pdata['.count'] / $cd['total']).'%'));
        if (array_key_exists('.files', $pdata)) {
          foreach ($pdata['.files'] as $fname=>$fdata) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $tr->setAttribute('class', $classname);
            $tr->setAttribute('style', 'display: none;');
            $td = $tr->appendChild($doc->createElement('td'));
            $td = $tr->appendChild($doc->createElement('td', $fname));
            $td = $tr->appendChild($doc->createElement('td', $fdata['.count']));
            $td = $tr->appendChild($doc->createElement('td',
                sprintf('%.1f', 100 * $fdata['.count'] / $cd['total']).'%'));
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

// Function to safely escape variables handed to awk
function awk_quote($string) {
  return strtr(preg_replace("/([\]\[^$.*?+{}\\\\()|])/", '\\\\$1', $string),
               array('`'=>'\140',"'"=>'\047'));
}

?>
