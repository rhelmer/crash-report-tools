#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves explosiveness stats for crash signatures.

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

// set higher memory limit so we can process large SQL results
ini_set('memory_limit', '512M');


// *** data gathering variables ***

// reports to gather. fields:
//   product - product name
//   version - empty is all versions
//   fake_adu - no ADUs known, fake the value
//   mincount - minimum count of crashes per day to analyze

$reports = array(array('product'=>'Firefox',
                       'version'=>'24',
                       'version_regex'=>'24\..*', // keep around for ESR
                       'fake_adu'=>false,
                       'mincount'=>50,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'27',
                       'version_regex'=>'27\..*',
                       'fake_adu'=>false,
                       'mincount'=>50,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'28',
                       'version_regex'=>'28\..*',
                       'fake_adu'=>false,
                       'mincount'=>100,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'29',
                       'version_regex'=>'29\..*',
                       'fake_adu'=>false,
                       'mincount'=>40,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'30',
                       'version_regex'=>'30\..*',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'31',
                       'version_regex'=>'31\..*',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'version'=>'32',
                       'version_regex'=>'32\..*',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'release',
                       'fake_adu'=>false,
                       'mincount'=>130,
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'beta',
                       'fake_adu'=>false,
                       'mincount'=>60,
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'aurora',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'Firefox',
                       'channel'=>'nightly',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'FennecAndroid',
                       'version'=>'28',
                       'version_regex'=>'28\..*',
                       'fake_adu'=>false,
                       'mincount'=>40,
                      ),
                 array('product'=>'FennecAndroid',
                       'version'=>'29',
                       'version_regex'=>'29\..*',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'FennecAndroid',
                       'version'=>'30',
                       'version_regex'=>'30\..*',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'release',
                       'fake_adu'=>false,
                       'mincount'=>80,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'beta',
                       'fake_adu'=>false,
                       'mincount'=>10,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'aurora',
                       'fake_adu'=>false,
                       'mincount'=>6,
                      ),
                 array('product'=>'FennecAndroid',
                       'channel'=>'nightly',
                       'fake_adu'=>false,
                       'mincount'=>6,
                      ),
                );

// for how many days back to get the data
$backlog_days = 20;

// *** explosiveness tuning ***

$exp_vars = array(
  // minimum of crashes/ADU to use as "dist" values
  'clamp_1' => 30,
  'clamp_3' => 15,
  // limit for explosiveness output to trigger warning
  'limit_1' => 2,
  'limit_3' => 2,
);

// *** URLs and paths ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$url_algolink = 'https://wiki.mozilla.org/CrashKill/Plan/Explosive';
$url_siglinkbase = 'https://crash-stats.mozilla.com/report/list?signature=';
$url_nullsiglink = 'https://crash-stats.mozilla.com/report/list?missing_sig=EMPTY_STRING';
$url_buglinkbase = 'https://bugzilla.mozilla.org/show_bug.cgi?id=';

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

  $crdata = array();
  $adu = array();
  $virtual_adu = array(); // reduced for throttling
  $total = array();
  $sigcnt = array();

  $max_build_age = getMaxBuildAge($channel, true);
  if (!$rep['fake_adu']) {
    $first_day = date('Y-m-d', strtotime(date('Y-m-d', $curtime).' -'.($backlog_days + 1).' day'));
    $adu_query =
      "SELECT SUM(CASE
                  WHEN product_versions.build_type = 'Release' AND product_versions.product_name = 'Firefox'
                  THEN product_adu.adu_count / 10 ELSE product_adu.adu_count END) as v_adu,
              SUM(adu_count) as adu, adu_date "
      .'FROM product_adu JOIN product_versions'
      .' ON (product_adu.product_version_id=product_versions.product_version_id)'
      ."WHERE product_versions.product_name = '".$rep['product']."'"
      .(strlen($ver)
        ?' AND product_versions.release_version '.(isset($rep['version_regex'])
                                  ?"~ '^".$rep['version_regex']."$'"
                                  :"= '".$ver."'")
        :'')
      .(strlen($channel)
        ?" AND product_versions.build_type='".$channel."'"
         ." AND product_versions.is_rapid_beta='f'"
         .(($rep['product'] == 'Firefox')?" AND major_version!='3.6'":'')  // 3.6 has ADI but no crashes and disturbs the stats.
         ." AND product_adu.adu_date < (product_versions.build_date + interval '".$max_build_age."')"
        :'')
      ." AND product_adu.adu_date >= '".$first_day."' "
      .'GROUP BY product_adu.adu_date '
      .'ORDER BY product_adu.adu_date ASC;';

    $adu_result = pg_query($db_conn, $adu_query);
    if (!$adu_result) {
      print('--- ERROR: ADU query failed!'."\n");
    }

    while ($adu_row = pg_fetch_array($adu_result)) {
      $adu[$adu_row['adu_date']] = $adu_row['adu'];
      $virtual_adu[$adu_row['adu_date']] = $adu_row['v_adu'];
    }
  }

  for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
    $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
    $anadir = date('Y-m-d', $anatime);
    print('Explosiveness: Looking at '.$prdverdisplay.' data for '.$anadir."\n");
    if (!file_exists($anadir)) { mkdir($anadir); }

    $fsigcnt = $prdvershort.'-sigcount.csv';
    $ftotal = $prdvershort.'-total.csv';
    $fadu = $prdvershort.'-adu.csv';
    $fexpdata = $prdvershort.'-expdata.json';
    $fpages = 'pages.json';
    $fweb = $anadir.'.'.$prdverfile.'.explosiveness.html';

    if (!array_key_exists($anadir, $adu) && $rep['fake_adu']) {
      $adu[$anadir] = 1000000;
      $virtual_adu[$anadir] = 1000000;
    }

    $rep_query =
      'SELECT COUNT(*) as cnt, signatures.signature '
      .'FROM reports_clean LEFT JOIN product_versions'
      .' ON (reports_clean.product_version_id=product_versions.product_version_id) '
      .' LEFT JOIN signatures'
      .' ON (reports_clean.signature_id=signatures.signature_id)'
      ."WHERE product_versions.product_name = '".$rep['product']."'"
      .(strlen($ver)
        ?' AND product_versions.release_version '.(isset($rep['version_regex'])
                                  ?"~ '^".$rep['version_regex']."$'"
                                  :"= '".$ver."'")
        :'')
      .(strlen($channel)
        ?" AND product_versions.build_type='".$channel."'"
          ." AND product_versions.is_rapid_beta='f'"
          .(($rep['product'] == 'Firefox')?" AND major_version!='3.6'":'')  // 3.6 has ADI but no crashes and disturbs the stats.
          ." AND reports_clean.date_processed < (product_versions.build_date + interval '".$max_build_age."')"
        :'')
      ." AND utc_day_is(reports_clean.date_processed, '".$anadir."')"
      .'GROUP BY signatures.signature '
      .'ORDER BY cnt DESC;';

    $rep_result = pg_query($db_conn, $rep_query);
    if (!$rep_result) {
      print('--- ERROR: Reports/signatures query failed!'."\n");
    }

    $sigcnt[$anadir] = pg_num_rows($rep_result);
    $total[$anadir] = 0;
    $sigs = array();
    $tcranks = array();
    $tcrank = 1;
    while ($rep_row = pg_fetch_array($rep_result)) {
      $sig = $rep_row['signature'];
      $sigidx = md5($sig);
      $sigs[$sigidx] = $sig;
      $crdata[$sigidx][$anadir] = $rep_row['cnt'];
      $total[$anadir] += $rep_row['cnt'];
      $tcranks[$sigidx] = $tcrank++;
    }

    // Save total crash count (if needed).
    $anaftotal = $anadir.'/'.$ftotal;
    if (!file_exists($anaftotal)) {
      print('Saving total crash count'."\n");
      file_put_contents($anaftotal, $total[$anadir]);
    }

    // Save signature count (if needed).
    $anafsigcnt = $anadir.'/'.$fsigcnt;
    if (!file_exists($anafsigcnt)) {
      print('Saving signature count'."\n");
      file_put_contents($anafsigcnt, $sigcnt[$anadir]);
    }

    // Save ADUs (if needed).
    $anafadu = $anadir.'/'.$fadu;
    if (intval(@$adu[$anadir]) && !$rep['fake_adu'] &&
        (!file_exists($anafadu) || !filesize($anafadu))) {
      print('Saving ADU count'."\n");
      file_put_contents($anafadu, $adu[$anadir]);
    }

    // get explosiveness
    if (intval(@$virtual_adu[$anadir]) && intval(@$total[$anadir]) &&
        ($daysback < $backlog_days - 8)) {
      $anafexpdata = $anadir.'/'.$fexpdata;
      if (!file_exists($anafexpdata)) {
        // get topcrasher list with counts per signature
        print('Calculating explosiveness'."\n");

        $exp = array();
        $dayset = array($anadir);
        $aduset = array($virtual_adu[$anadir]);
        $totalset = array($total[$anadir] / $virtual_adu[$anadir]);
        for ($i = 1; $i < 11; $i++) {
          $prevdir = date('Y-m-d',
                          strtotime(date('Y-m-d', $anatime).' -'.$i.' day'));
          $dayset[] = $prevdir;
          $aduset[] = array_key_exists($prevdir, $virtual_adu) ? $virtual_adu[$prevdir] : 0;
          $totalset[] = array_key_exists($prevdir, $virtual_adu) ?
                        $total[$prevdir] / $virtual_adu[$prevdir] :
                        0;
        }
        $exp_total = get_explosiveness($totalset, $aduset, $exp_vars);
        $exp_total['dataset'] = $totalset;

        // loop over signatures over a certain threshold
        foreach ($sigs as $sigidx=>$sig) {
          if (intval(@$crdata[$sigidx][$anadir]) > $rep['mincount']) {
            $dataset = array($crdata[$sigidx][$anadir] / $virtual_adu[$anadir]);
            $rawcountset = array($crdata[$sigidx][$anadir]);
            // get crash counts for previous days if not yet in the array
            for ($i = 1; $i < 11; $i++) {
              $dataset[] = array_key_exists($dayset[$i], $virtual_adu) ?
                           intval(@$crdata[$sigidx][$dayset[$i]]) /
                             $virtual_adu[$dayset[$i]] :
                           0;
              $rawcountset[] = intval(@$crdata[$sigidx][$dayset[$i]]);
            }

            // Fetch bugs associated with that signature.
            $bugs = array();
            $bug_query =
              'SELECT bug_id, status, resolution, short_desc '
              .'FROM bug_associations LEFT JOIN bugs'
              .' ON (bug_associations.bug_id=bugs.id) '
              ."WHERE signature = '".pg_escape_string($sig)."';";

            $bug_result = pg_query($db_conn, $bug_query);
            if (!$bug_result) {
              print('--- ERROR: Bug associations query failed!'."\n");
            }
            while ($bug_row = pg_fetch_array($bug_result)) {
              $bugs[$bug_row['bug_id']] = array('status' => $bug_row['status'],
                                                'resolution' => $bug_row['resolution'],
                                                'short_desc' => $bug_row['short_desc']);
            }

            // Now that we have the data, calculate explosiveness!
            $exp[$sigidx] = get_explosiveness($dataset, $aduset, $exp_vars);
            $exp[$sigidx]['sig'] = $sig;
            $exp[$sigidx]['bugs'] = $bugs;
            $exp[$sigidx]['dataset'] = $dataset;
            $exp[$sigidx]['rawcountset'] = $rawcountset;
            $exp[$sigidx]['tcrank'] = $tcranks[$sigidx];
            print('.');
          }
        }
        print("\n");

        uasort($exp, 'expmax_compare'); // sort by max, highest-first

        file_put_contents($anafexpdata,
                          json_encode(array('exp'=>$exp,
                                            'exp_total'=> $exp_total,
                                            'dayset'=> $dayset)));
      }
      else {
        print('Reading stored explosiveness'."\n");
        $edata = json_decode(file_get_contents($anafexpdata), true);
        $exp = $edata['exp'];
        $exp_total = $edata['exp_total'];
        $dayset = $edata['dayset'];
      }

      $anafweb = $anadir.'/'.$fweb;
      if (!file_exists($anafweb)) {
        // create out an HTML page
        print('Writing HTML output'."\n");
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true; // we want a nice output

        $root = $doc->appendChild($doc->createElement('html'));
        $head = $root->appendChild($doc->createElement('head'));
        $title = $head->appendChild($doc->createElement('title',
            $anadir.' '.$prdverdisplay.' Explosiveness Report'));

        $style = $head->appendChild($doc->createElement('style'));
        $style->setAttribute('type', 'text/css');
        $style->appendChild($doc->createCDATASection(
            '.num {'."\n"
            .'  text-align: right;'."\n"
            .'}'."\n"
            .'.datelabel,'."\n"
            .'.sig {'."\n"
            .'  font-size: small;'."\n"
            .'}'."\n"
            .'.bug {'."\n"
            .'  font-size: small;'."\n"
            .'  empty-cells: show;'."\n"
            .'}'."\n"
            .'.resolved {'."\n"
            .'  text-decoration: line-through;'."\n"
            .'}'."\n"
            .'.explosive1 > .totallabel,'."\n"
            .'.explosive3 > .totallabel,'."\n"
            .'.explosive1 > .sig,'."\n"
            .'.explosive3 > .sig {'."\n"
            .'  font-weight: bold;'."\n"
            .'}'."\n"
            .'.explosive1 > .exp1,'."\n"
            .'.explosive3 > .exp3 {'."\n"
            .'  font-weight: bold;'."\n"
            .'  color: red;'."\n"
            .'}'."\n"
        ));

        $body = $root->appendChild($doc->createElement('body'));
        $h1 = $body->appendChild($doc->createElement('h1',
            $anadir.' '.$prdverdisplay.' Explosiveness Report'));

        // description
        $para = $body->appendChild($doc->createElement('p',
            'All signatures with more than '.$rep['mincount']
            .' crashes on '.$prdverdisplay.'*,'
            .' ordered by explosiveness (max of the two values),'
            .' with topcrash rank noted.'));
        $para->appendChild($doc->createElement('br'));
        $para->appendChild($doc->createTextNode('See the '));
        $link = $para->appendChild($doc->createElement('a',
            'explosiveness wiki page'));
        $link->setAttribute('href', $url_algolink);
        $para->appendChild($doc->createTextNode(
            ' for more information on the algorithm used.'));

        $table = $body->appendChild($doc->createElement('table'));
        $table->setAttribute('border', '1');

        // table head
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', 'TC #'));
        $th->setAttribute('title', 'top-crash rank');
        $th->setAttribute('rowspan', '2');
        $th = $tr->appendChild($doc->createElement('th', 'Signature'));
        $th->setAttribute('rowspan', '2');
        $th = $tr->appendChild($doc->createElement('th', 'Bug(s)'));
        $th->setAttribute('rowspan', '2');
        $th = $tr->appendChild($doc->createElement('th', 'Explosiveness'));
        $th->setAttribute('colspan', '2');
        $th = $tr->appendChild($doc->createElement('th',
            'Data (total crashes'.($rep['fake_adu']?'':' / 1M ADU').')'));
        $th->setAttribute('colspan', '11');
        $tr = $table->appendChild($doc->createElement('tr'));
        $th = $tr->appendChild($doc->createElement('th', '1-day'));
        $th = $tr->appendChild($doc->createElement('th', '3-day'));
        for ($i = 0; $i < 11; $i++) {
          $th = $tr->appendChild($doc->createElement('th', $dayset[$i]));
          $th->setAttribute('class', 'datelabel');
        }

        // total crashes row
        $tr = $table->appendChild($doc->createElement('tr'));
        $classes = array();
        if ($exp_total['warn_1']) { $classes[] = 'explosive1'; }
        if ($exp_total['warn_3']) { $classes[] = 'explosive3'; }
        if (count($classes)) {
          $tr->setAttribute('class', implode(' ', $classes));
        }
        $td = $tr->appendChild($doc->createElement('td', 'Total crashes'));
        $td->setAttribute('colspan', '3');
        $td->setAttribute('class', 'totallabel');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', $exp_total['explosiveness_1'])));
        $td->setAttribute('class', 'num exp1');
        $td = $tr->appendChild($doc->createElement('td',
            sprintf('%.1f', $exp_total['explosiveness_3'])));
        $td->setAttribute('class', 'num exp3');
        foreach ($exp_total['dataset'] as $i=>$c_per_adu) {
          $td = $tr->appendChild($doc->createElement('td',
              sprintf($rep['fake_adu']?'%d':'%.3f', $c_per_adu * pow(10, 6))));
          $td->setAttribute('class', 'num');
          if (!$rep['fake_adu']) {
            $td->appendChild($doc->createElement('br'));
            $td->appendChild($doc->createElement('small',
                '('.formatValue(@$total[$dayset[$i]], null, 'kMG').'/'
                .formatValue(@$adu[$dayset[$i]], null, 'kMG').')'));
          }
        }

        // signatures rows
        foreach ($exp as $expdata) {
          $tr = $table->appendChild($doc->createElement('tr'));
          $classes = array();
          if ($expdata['warn_1']) { $classes[] = 'explosive1'; }
          if ($expdata['warn_3']) { $classes[] = 'explosive3'; }
          if (count($classes)) {
            $tr->setAttribute('class', implode(' ', $classes));
          }
          $td = $tr->appendChild($doc->createElement('td', $expdata['tcrank']));
          $td->setAttribute('class', 'num');
          $td = $tr->appendChild($doc->createElement('td'));
          $td->setAttribute('class', 'sig');
          if (!strlen($expdata['sig'])) {
            $link = $td->appendChild($doc->createElement('a', '(empty signature)'));
            $link->setAttribute('href', $url_nullsiglink);
          }
          elseif ($expdata['sig'] == '\N') {
            $td->appendChild($doc->createTextNode('(processing failure - "'.$expdata['sig'].'")'));
          }
          else {
            // common case, useful signature
            $link = $td->appendChild($doc->createElement('a',
                htmlentities($expdata['sig'], ENT_COMPAT, 'UTF-8')));
            $link->setAttribute('href', $url_siglinkbase.rawurlencode($expdata['sig']));
          }
          $td = $tr->appendChild($doc->createElement('td'));
          if (array_key_exists('bugs', $expdata) && count($expdata['bugs'])) {
            foreach ($expdata['bugs'] as $bug => $bugdata) {
              if (strlen($td->textContent)) {
                $td->appendChild($doc->createTextNode(', '));
              }
              $link = $td->appendChild($doc->createElement('a', $bug));
              $link->setAttribute('href', $url_buglinkbase.$bug);
              $link->setAttribute('title',
                  $bugdata['status'].' '.$bugdata['resolution'].' - '
                  .htmlentities($bugdata['short_desc'], ENT_COMPAT, 'UTF-8'));
              if ($bugdata['status'] == 'RESOLVED' || $bugdata['status'] == 'VERIFIED') {
                $link->setAttribute('class', 'bug resolved');
              }
              else {
                $link->setAttribute('class', 'bug');
              }
            }
          }
          else {
            $td->appendChild($doc->createTextNode('-'));
          }
          $td = $tr->appendChild($doc->createElement('td',
              sprintf('%.1f', $expdata['explosiveness_1'])));
          $td->setAttribute('class', 'num exp1');
          $td = $tr->appendChild($doc->createElement('td',
              sprintf('%.1f', $expdata['explosiveness_3'])));
          $td->setAttribute('class', 'num exp3');
          foreach ($expdata['dataset'] as $idx=>$c_per_adu) {
            $td = $tr->appendChild($doc->createElement('td',
                sprintf($rep['fake_adu']?'%d':'%.3f', $c_per_adu * pow(10,6))));
            $td->setAttribute('class', 'num');
            $td->setAttribute('title', $expdata['rawcountset'][$idx].' crashes');
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
        $pages[$fweb] =
          array('product' => $rep['product'],
                'channel' => $channel,
                'version' => $ver,
                'report' => 'explosive',
                'report_sub' => null,
                'display_ver' => $prdverdisplay,
                'display_rep' => 'Explosiveness Report');
        file_put_contents($anafpages, json_encode($pages));
      }
    }
    elseif (!intval(@$total[$anadir])) {
      print('--- ERROR: No ADU data found for '.$anadir.' and '.$prdverdisplay.'!'."\n");
    }
    elseif (!intval(@$virtual_adu[$anadir])) {
      print('--- ERROR: No ADU data found for '.$anadir.' and '.$prdverdisplay.'!'."\n");
    }
  }
  print("\n");
}

// *** helper functions ***

// Function to calculate explosiveness values
function get_explosiveness($dataset, $aduset, $exp_vars) {
  $exp_out = array();
  $baseset_1 = array_slice($dataset, 1, 10);
  $avgADU_1 = max(arr_mean(array_slice($aduset, 1, 10)), 1);
  $base_1 = arr_mean($baseset_1);
  // maximum of the clamp/ADU and the distance of the max value to the mean
  $dist_1 = max($exp_vars['clamp_1'] / $avgADU_1, max($baseset_1) - $base_1);
  $data_1 = $dataset[0];
  $exp_out['explosiveness_1'] = ($data_1 - $base_1) / $dist_1;
  $exp_out['warn_1'] = ($exp_out['explosiveness_1'] > $exp_vars['limit_1']);

  $baseset_3 = array_slice($dataset, 3, 10);
  $avgADU_3 = max(arr_mean(array_slice($aduset, 3, 10)), 1);
  $base_3 = arr_mean($baseset_3);
  // maximum of the clamp/ADU and the standard deviation of the mean
  $dist_3 = max($exp_vars['clamp_3'] / $avgADU_3, arr_stddev($baseset_3, $base_3));
  $data_3 = arr_mean(array_slice($dataset, 0, 3));
  $exp_out['explosiveness_3'] = ($data_3 - $base_3) / $dist_3;
  $exp_out['warn_3'] = ($exp_out['explosiveness_3'] > $exp_vars['limit_3']);

  $exp_out['exp_max'] = max($exp_out['explosiveness_1'],
                            $exp_out['explosiveness_3']);
  return $exp_out;
}

// Comparison function using ex_max member (reverse sort!)
function expmax_compare($a, $b) {
  if ($a['exp_max'] == $b['exp_max']) { return 0; }
  return ($a['exp_max'] > $b['exp_max']) ? -1 : 1;
}

?>
