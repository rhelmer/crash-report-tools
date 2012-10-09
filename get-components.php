#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script analyzes crashes by component.

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
                       'channel'=>'esr',
                       'version'=>'10.0',
                       'version_regex'=>'10\.0.*',
                       'version_display'=>'10 ESR',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'release',
                       'version'=>'15.0',
                       'version_regex'=>'15\.0.*',
                       'version_display'=>'15',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'release',
                       'version'=>'16.0',
                       'version_regex'=>'16\.0.*',
                       'version_display'=>'16',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'beta',
                       'version'=>'16.0',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'beta',
                       'version'=>'17.0',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'nightly',
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'aurora',
                      ),
                 array('product'=>'Fennec',
                       'channel'=>'esr',
                       'version'=>'10.0',
                       'version_regex'=>'10\.0.*',
                       'version_display'=>'10 ESR',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'release',
                       'version'=>'15.0',
                       'version_regex'=>'15\.0.*',
                       'version_display'=>'15',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'release',
                       'version'=>'16.0',
                       'version_regex'=>'16\.0.*',
                       'version_display'=>'16',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'beta',
                       'version'=>'16.0',
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'beta',
                       'version'=>'17.0',
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

// maximum shown signatures per component
$max_shown_sigs = 10;

// for how many days back to get the data
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
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
    break;
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Looking at data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fcompdata = $prdvershort.'-components.json';
    $fpages = 'pages.json';
    $fwebmask = '%s.'.$prdverfile.'.components.html';
    $fweb = sprintf($fwebmask, $anadir);
    $fwebweek = $anadir.'.'.$prdverfile.'.components.weekly.html';

    $anafcompdata = $anadir.'/'.$fcompdata;
    if (!file_exists($anafcompdata)) {
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

      print('Getting summarized component data'."\n");
      $cd = array('total' => 0,
                  'tree' => array());
      while ($rep_row = pg_fetch_array($rep_result)) {
        $sig = $rep_row['signature'];
        $crash_id = $rep_row['uuid'];

        // get topmost filename for this crash ID (using day for performance)
        $fname_query =
          'SELECT topmost_filenames '
          .'FROM reports '
          ."WHERE uuid='".$crash_id."'"
          ." AND utc_day_is(date_processed, '".$anadir."');";

        $fname_result = pg_query($db_conn, $fname_query);
        if (!$fname_result) {
          print('--- ERROR: topmost_filenames query failed!'."\n");
          $topmost_filenames = '';
        }
        else {
          $fname_row = pg_fetch_array($fname_result);
          $topmost_filenames = $fname_row['topmost_filenames'];
        }

        $toplevel = ''; $subfile = null;
        $cd['total']++;

        if (preg_match('/^hg:([^:]+):([^:]+):([^:]+)$/', $topmost_filenames, $regs)) {
          $repo = $regs[1]; // currently ignorable, e.g. 'hg.mozilla.org/mozilla-central'
          $path = $regs[2]; // *the meat*, e.g. 'dom/plugins/ipc/PluginInstanceChild.cpp'
          $rev = $regs[3]; // hg revision, e.g. 'f41df039db03'
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
        elseif (preg_match('/^F_?\d+_+/', $topmost_filenames, $regs)) {
          $toplevel = '.flash';
          $subfile = null;
        }
        elseif (preg_match('/^e:\\\\fp_win_nf\\\\/', $topmost_filenames, $regs)) {
          $toplevel = '.flash';
          $subfile = $path;
        }
        elseif (preg_match('/^(\.\.\/)+([^\/]+)(.*)$/', $topmost_filenames, $pregs)) { // relative paths give modules
          $toplevel = $pregs[2]; // toplevel directory
          $subfile = $pregs[3]; // file path inside the toplevel
        }
        else { // absolute paths --> "unknown"
          $toplevel = '.unknown';
          $subfile = $topmost_filenames;
        }
        if (!strlen($sig) || !strlen($toplevel)) {
          print('--- ERROR: entry without signature or toplevel! (crash ID: '.$crash_id.')'."\n");
          break;
        }
        // Apply some additional filtering for cases where we obviously get it wrong.
        if (($toplevel != '.flash') && (preg_match('/.+@0x[0-9a-f]+$/', $sig))) {
          // Signature in a plain library is not our code.
          if (preg_match('/npswf[0-9_]+.dll/', $sig) ||
              preg_match('/libflashplayer\.so/', $sig) ||
              preg_match('/flash asset.x\d\d\@0x/i', $sig)) {
            $subfile = null;
            $toplevel = '.flash';
          }
          elseif ($toplevel != '.unknown') {
            $subfile = '>'.$toplevel.$subfile;
            $toplevel = '.unknown';
          }
        }
        // Signatures starting in mozilla::plugins belong into dom/plugins.
        elseif (preg_match('/^(hang \| )?mozilla::plugins::/', $sig)) {
          $subfile = '>'.$toplevel.$subfile;
          $toplevel = 'dom/plugins';
        }
        // Signatures starting in mozalloc_abort leading to mozilla::plugins belong into dom/plugins.
        elseif (preg_match('/^(hang \| )?mozalloc_abort.*\| mozilla::plugins::/', $sig)) {
          $subfile = '>'.$toplevel.$subfile;
          $toplevel = 'dom/plugins';
        }
        // Signatures starting in mozalloc_abort leading to mozilla::ipc belong into ipc.
        elseif (preg_match('/^mozalloc_abort.*\| mozilla::ipc::/', $sig)) {
          $subfile = '>'.$toplevel.$subfile;
          $toplevel = 'ipc';
        }
        // Signatures starting in Java belong into a separate Java module.
        elseif (preg_match('/^[jJ]ava(_org|\.lang\.)/', $sig)) {
          $subfile = '>'.$toplevel.$subfile;
          $toplevel = '.java';
        }
        // Signatures starting in android. belong into the Java module as well.
        elseif (preg_match('/^android\./', $sig)) {
          $subfile = '>'.$toplevel.$subfile;
          $toplevel = '.java';
        }
        // Those dirs need to be subcategorized by their direct subdirs.
        $subcat_tls = array('db', 'extensions', 'media', 'modules');
        if (in_array($toplevel, $subcat_tls) && preg_match('/^\/([^\/]+)(\/.*)$/', $subfile, $regs)) {
          $subfile = $regs[2];
          $toplevel = $toplevel.'/'.$regs[1];
        }
        // Some toplevels need to be subcategorized with special (regex) filters.
        $subfilters = array();
        // Actual dom/plugins code should go in the same group as mozilla::plugins hangs.
        $subfilters['dom'] = '/^\/(plugins)(\/.*)$/';
        // Filter out crashreporter, as those are probably wrongly categorized.
        $subfilters['toolkit'] = '/^\/(crashreporter)(\/.*)$/';
        // widget/ always has a src/ layer in between.
        $subfilters['widget'] = '/^\/(src\/[^\/]+)(\/.*)$/';
        foreach ($subfilters as $sub_tl => $sub_filter) {
          if (($toplevel == $sub_tl) && preg_match($sub_filter, $subfile, $regs)) {
            $subfile = $regs[2];
            $toplevel = $toplevel.'/'.$regs[1];
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

    $webreports = array('day' => $fweb);
    if (@$rep['weekly']) { $webreports['week'] = $fwebweek; }

    foreach ($webreports as $type=>$fwebcur) {
      $anafweb = $anadir.'/'.$fwebcur;
      if ($type == 'week') {
        if (!file_exists($anafweb)) {
          // assemble 7-day "weekly" overview
          print('Calculating weekly data'."\n");
          $curcd = $cd;
          for ($pastday = 1; $pastday < 7; $pastday++) {
            $pasttime = strtotime($anadir.' -'.$pastday.' day');
            $pastdir = date('Y-m-d', $pasttime);
            print('Adding '.$pastdir);
            $pastfcompdata = $pastdir.'/'.$fcompdata;
            if (file_exists($pastfcompdata)) {
              // Load that data and merge it into $curcd.
              $pastcd = json_decode(file_get_contents($pastfcompdata), true);
              $curcd['total'] += $pastcd['total'];
              foreach ($pastcd['tree'] as $path=>$pdata) {
                print(':');
                addCount($curcd['tree'], $path, $pdata['.count']);
                if (array_key_exists('.files', $pdata)) {
                  if (!array_key_exists('.files', $curcd['tree'][$path])) {
                    print('.');
                    $curcd['tree'][$path]['.files'] = array();
                  }
                  foreach ($pdata['.files'] as $fname=>$fdata) {
                    print('.');
                    addCount($curcd['tree'][$path]['.files'], $fname, $fdata['.count']);
                  }
                }
                if (!array_key_exists('.sigs', $curcd['tree'][$path])) {
                  print('.');
                  $curcd['tree'][$path]['.sigs'] = array();
                }
                foreach ($pdata['.sigs'] as $sname=>$sdata) {
                  print('.');
                  addCount($curcd['tree'][$path]['.sigs'], $sname, $sdata['.count']);
                }
              }
              print("\n");
            }
            else { print(' - '.$pastfcompdata.' not found.'."\n"); }
          }
          if ($curcd['total'] == $cd['total']) {
            // Not more than what the day had, so set to 0 which omits creating an HTML.
            $curcd['total'] = 0;
          }
          else {
            uasort($curcd['tree'], 'count_compare'); // sort by count, highest-first
            foreach ($curcd['tree'] as $path=>$pdata) {
              if (array_key_exists('.files', $pdata)) {
                uasort($curcd['tree'][$path]['.files'], 'count_compare'); // sort by count, highest-first
              }
              if (array_key_exists('.sigs', $pdata)) {
                uasort($curcd['tree'][$path]['.sigs'], 'count_compare'); // sort by count, highest-first
              }
            }
          }
        }
      }
      else {
        // single-day data
        $curcd = $cd;
      }

      if (!file_exists($anafweb) && $curcd['total']) {
        // create out an HTML page
        print('Writing'.($type == 'week'?' weekly':' daily').' HTML output'."\n");
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true; // we want a nice output

        $root = $doc->appendChild($doc->createElement('html'));
        $head = $root->appendChild($doc->createElement('head'));
        $title = $head->appendChild($doc->createElement('title',
            $anadir.' '.$prdverdisplay.($type == 'week'?' Weekly':'')
            .' Crash Components Report'));
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
            .'tr.sigheader {'."\n"
            .'  background: #EEEEAA;'."\n"
            .'}'."\n"
            .'tr.sigheader:target {'."\n"
            .'  background: #EECCAA;'."\n"
            .'}'."\n"
        ));

        $body = $root->appendChild($doc->createElement('body'));
        $h1 = $body->appendChild($doc->createElement('h1',
            $anadir.' '.$prdverdisplay.($type == 'week'?' Weekly':'')
            .' Crash Components Report'));

        // description
        $para = $body->appendChild($doc->createElement('p',
            'Splitting all reports on '.$prdverdisplay
            .' by the file location of their topmost frame.'
            .' (Actually, it\'s the topmost frame on the stack which we can'
            .' derive a file location for, which does not necessarily correspond'
            .' exactly to the frame(s) used in the signature.)'));

        $para = $body->appendChild($doc->createElement('p',
            'Total crashes analyzed in this report: '.$curcd['total']
            .($type == 'week'?' - covering 7 days up to and including '.$anadir.'.':'')));

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

        foreach ($curcd['tree'] as $path=>$pdata) {
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
              sprintf('%.1f', 100 * $pdata['.count'] / $curcd['total']).'%'));
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
                  sprintf('%.1f', 100 * $fdata['.count'] / $curcd['total']).'%'));
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

        foreach ($curcd['tree'] as $path=>$pdata) {
          $tr = $table->appendChild($doc->createElement('tr'));
          $tr->setAttribute('id', $path);
          $tr->setAttribute('class', 'sigheader');
          $th = $tr->appendChild($doc->createElement('th', $path));
          $th->setAttribute('colspan', '2');
          $th = $tr->appendChild($doc->createElement('td', $pdata['.count']));
          $th->setAttribute('class', 'num');
          $th = $tr->appendChild($doc->createElement('td',
              sprintf('%.1f', 100 * $pdata['.count'] / $curcd['total']).'%'));
          $th->setAttribute('class', 'pct');
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
                $link = $td->appendChild($doc->createElement('a',
                    htmlentities($sname, ENT_COMPAT, 'UTF-8')));
                $link->setAttribute('href', $url_siglinkbase.rawurlencode($sname));
              }
              $td = $tr->appendChild($doc->createElement('td', $sdata['.count']));
              $td->setAttribute('class', 'num');
              $td = $tr->appendChild($doc->createElement('td',
                  sprintf('%.1f', 100 * $sdata['.count'] / $curcd['total']).'%'));
              $td->setAttribute('class', 'pct');
              $count++;
              if ($count > $max_shown_sigs) { break; }
            }
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
                'report' => 'components',
                'report_sub' => $type,
                'display_ver' => $prdverdisplay,
                'display_rep' => ($type == 'week'?'Weekly ':'')
                                 .'Crash Components Report');
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

// Function to bump the counter of an element or initialize it
function addCount(&$basevar, $sub, $addnum = 1) {
  if (array_key_exists($sub, $basevar)) {
    $basevar[$sub]['.count'] += $addnum;
  }
  else {
    $basevar[$sub]['.count'] = $addnum;
  }
}
?>
