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

// products and channels to cover
$prodchan = array('Firefox' => array('release', 'beta', 'aurora', 'nightly', 'esr'),
                  'Fennec' => array('esr'),
                  'FennecAndroid' => array('release', 'beta', 'aurora', 'nightly'),
                  'B2G' => array('beta', 'aurora', 'nightly'),
                  'WebappRuntime' => array('release', 'beta', 'aurora', 'nightly'),
                  'WebappRuntimeMobile' => array('beta', 'aurora', 'nightly'),
                  'MetroFirefox' => array('beta', 'aurora', 'nightly'));

// how many days back to look at
$backlog_days = 7;

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');

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

for ($daysback = $backlog_days + 1; $daysback > 0; $daysback--) {
  $anatime = strtotime(date('Y-m-d', $curtime).' -'.$daysback.' day');
  $anadir = date('Y-m-d', $anatime);
  print('Builds: Looking at per-build crash data for '.$anadir."\n");
  if (!file_exists($anadir)) { mkdir($anadir); }

  $fpages = 'pages.json';
  $fweb = $anadir.'.buildcrashes.html';

  $anafweb = $anadir.'/'.$fweb;
  if (!file_exists($anafweb)) {
    // create out an HTML page
    print('Writing HTML output'."\n");

    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        $anadir.' Crashes / Build'));

    $style = $head->appendChild($doc->createElement('style'));
    $style->setAttribute('type', 'text/css');
    $style->appendChild($doc->createCDATASection(
        '.num {'."\n"
        .'  text-align: right;'."\n"
        .'}'."\n"
        .'.buildadu, .buildrate {'."\n"
        .'  color: GrayText;'."\n"
        .'}'."\n"
    ));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        $anadir.' Crashes / Build'));

    // description
    $para = $body->appendChild($doc->createElement('p',
        'Total crashes seen on '.$anadir
        .' per different build '
        .'(product + version + Build ID combination).'));

    $list = $body->appendChild($doc->createElement('ul'));
    foreach ($prodchan as $product=>$channels) {
      $channels[] = 'other';
      foreach ($channels as $channel) {
        $item = $list->appendChild($doc->createElement('li'));
        $link = $item->appendChild($doc->createElement('a',
            $product.' '.ucfirst($channel)));
        $link->setAttribute('href', '#'.$product.'-'.$channel);
      }
    }

    foreach ($prodchan as $product=>$channels) {
      $channels[] = 'other';
      // get most recent major verion
      $pid_query =
        'SELECT productid '
        .'FROM product_productid_map '
        ."WHERE product_name = '".$product."';";

      $pid_result = pg_query($db_conn, $pid_query);
      if (!$pid_result) {
        print('--- ERROR: Product ID query failed!'."\n");
        continue;
      }
      else {
        $pid_row = pg_fetch_array($pid_result);
        $productid = $pid_row['productid'];
      }
      $regular_pv_ids = array();
      foreach ($channels as $channel) {
        $pvdata = array();
        if ($channel != 'other') {
          $mver = array();
          // get featured major verion(s)
          $feat_query =
            'SELECT major_version '
            .'FROM product_versions '
            ."WHERE product_name = '".$product."'"
            ." AND build_type_enum = '".$channel."'"
            ." AND featured_version = 't';";

          $feat_result = pg_query($db_conn, $feat_query);
          if (!$feat_result) {
            print('--- ERROR: featured query failed!'."\n");
          }
          else {
            while ($feat_row = pg_fetch_array($feat_result)) {
              $mver[] = $feat_row['major_version'];
            }
          }

          if (!count($mver)) {
            // get most recent major verion
            $last_query =
              'SELECT major_version '
              .'FROM product_versions '
              ."WHERE product_name = '".$product."'"
              ." AND build_type_enum = '".$channel."' "
              ."ORDER BY build_date DESC LIMIT 1;";

            $last_result = pg_query($db_conn, $last_query);
            if (!$feat_result) {
              print('--- ERROR: Reports/signatures query failed!'."\n");
            }
            else {
              while ($last_row = pg_fetch_array($last_result)) {
                $mver[] = $last_row['major_version'];
              }
            }
          }
          if (!count($mver)) {
            print('--- ERROR: no version found for '.$product.' '.ucfirst($channel).'!'."\n");
            continue;
          }

          $pv_ids = array();
          $pv_query =
            'SELECT product_version_id, release_version, version_string, build_type '
            .'FROM product_versions '
            ."WHERE product_name = '".$product."'"
            ." AND major_version IN ('".implode("','", $mver)."')"
            ." AND build_type_enum = '".$channel."';";
          $pv_result = pg_query($db_conn, $pv_query);
          if (!$pv_result) {
            print('--- ERROR: product version query failed!'."\n");
          }
          else {
            while ($pv_row = pg_fetch_array($pv_result)) {
              $pv_ids[] = $pv_row['product_version_id'];
              $regular_pv_ids[] = $pv_row['product_version_id'];
              $pvdata[$pv_row['product_version_id']] = $pv_row;
            }
          }
        }
        else {
          $pv_ids = array();
          $pv_query =
            'SELECT product_version_id, release_version, version_string, build_type '
            .'FROM product_versions '
            ."WHERE product_name = '".$product."'"
            ." AND sunset_date > '".$anadir."'"
            .' AND product_version_id NOT IN ('.implode(',', $regular_pv_ids).');';
          $pv_result = pg_query($db_conn, $pv_query);
          if (!$pv_result) {
            print('--- ERROR: product version query failed!'."\n");
          }
          else {
            while ($pv_row = pg_fetch_array($pv_result)) {
              $pv_ids[] = $pv_row['product_version_id'];
              $pvdata[$pv_row['product_version_id']] = $pv_row;
            }
          }
        }

        if (!count($pv_ids)) {
          print('--- ERROR: no product versions found for '.$product.' '.ucfirst($channel).'!'."\n");
          continue;
        }

        $rep_query =
          'SELECT COUNT(*) as cnt, build, product_version_id,'
          ." CASE WHEN hang_id IS NULL THEN 'crash' ELSE 'hang' END as crash_type,"
          .' process_type '
          .'FROM reports_clean '
          .'WHERE product_version_id IN ('.implode(',', $pv_ids).')'
          ." AND utc_day_is(date_processed, '".$anadir."') "
          .'GROUP BY build, product_version_id, crash_type, process_type '
          .'ORDER BY build ASC;';

        $rep_result = pg_query($db_conn, $rep_query);
        if (!$rep_result) {
          print('--- ERROR: Reports/signatures query failed!'."\n");
          continue;
        }

        $listbuilds = array();
        $buildadu = array();
        $categories = array('crash'=>0, 'hang'=>0, 'browser'=>0);
        while ($rep_row = pg_fetch_array($rep_result)) {
          $idx = $rep_row['build'].'-'.$rep_row['product_version_id'];
          if (!array_key_exists($idx, $listbuilds)) {
            $listbuilds[$idx] = array('build' => $rep_row['build'],
                                      'pvid' => $rep_row['product_version_id'],
                                      'cnt' => array('total' => 0,
                                                     'norm_total' => 0));

            $adu_channel = strtolower($pvdata[$rep_row['product_version_id']]['build_type']);
            $adu_version =
                ($adu_channel == 'esr') ?
                str_replace('esr', '',
                            $pvdata[$rep_row['product_version_id']]['release_version']) :
                $pvdata[$rep_row['product_version_id']]['release_version'];

            $adu_query =
              'SELECT SUM(adi_count) as adu '
              .'FROM raw_adi '
              ."WHERE product_guid = '{".trim($productid, '{}')."}'" // As a workaround, foo@bar IDs get wrapped in {} as well.
              ." AND update_channel = '".$adu_channel."'"
              ." AND product_version = '".$adu_version."'"
              ." AND build = '".$rep_row['build']."'"
              ." AND date = '".$anadir."';";

            $adu_result = pg_query($db_conn, $adu_query);
            if (!$adu_result) {
              print('--- ERROR: ADU query failed!'."\n");
            }
            else {
              $adu_row = pg_fetch_array($adu_result);
              if (intval(@$adu_row['adu'])) {
                $buildadu[$idx] = $adu_row['adu'];
              }
            }
          }
          $ptype = strtolower($rep_row['process_type']);
          if (!array_key_exists($rep_row['crash_type'], $listbuilds[$idx]['cnt'])) {
            $listbuilds[$idx]['cnt'][$rep_row['crash_type']] = 0;
          }
          $listbuilds[$idx]['cnt'][$rep_row['crash_type']] += $rep_row['cnt'];
          if (!array_key_exists($ptype, $listbuilds[$idx]['cnt'])) {
            $listbuilds[$idx]['cnt'][$ptype] = 0;
          }
          $listbuilds[$idx]['cnt'][$ptype] += $rep_row['cnt'];
          $listbuilds[$idx]['cnt']['total'] += $rep_row['cnt'];
          if ($ptype != 'browser' || $rep_row['crash_type'] != 'hang') {
            $listbuilds[$idx]['cnt']['norm_total'] += $rep_row['cnt'];
          }
          $categories[$rep_row['crash_type']] += $rep_row['cnt'];
          if (!array_key_exists($ptype, $categories)) {
            $categories[$ptype] = 0;
          }
          $categories[$ptype] += $rep_row['cnt'];
        }

        $h2 = $body->appendChild($doc->createElement('h2',
            $product.' '.ucfirst($channel)));
        $h2->setAttribute('id', $product.'-'.$channel);

        if ($channel == 'other') {
          $body->appendChild($doc->createElement('p',
              'Only known-by-Socorro builds of currently active versions are listed.'));
        }

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
          foreach ($categories as $cat=>$cnt) {
            if ($cnt) {
              $fields[] = $cat;
              $th = $tr->appendChild($doc->createElement('th', $cat));
            }
          }
          $fields[] = 'total';
          $th = $tr->appendChild($doc->createElement('th', 'total'));
          $th = $tr->appendChild($doc->createElement('th', 'normalized'));
          $th->setAttribute('title',
              'total minus half of all hangs (as hangs always come in pairs)');

          // signatures rows
          foreach ($listbuilds as $idx=>$builddata) {
            $tr = $table->appendChild($doc->createElement('tr'));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($product, ENT_COMPAT, 'UTF-8')));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($pvdata[$builddata['pvid']]['version_string'], ENT_COMPAT, 'UTF-8')));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities($builddata['build'], ENT_COMPAT, 'UTF-8')));
            $td = $tr->appendChild($doc->createElement('td',
                htmlentities(@$notes[$idx], ENT_COMPAT, 'UTF-8')));
            if (@$buildadu[$idx]) {
              if (@$notes[$idx]) { $td->appendChild($doc->createElement('br')); }
              $small = $td->appendChild($doc->createElement('small',
                  formatValue($buildadu[$idx], null, 'kMG').' ADI'));
              $small->setAttribute('class', 'buildadu');
            }
            foreach ($fields as $fld) {
              $ptype = !in_array($fld, array('hang','crash','total'))?$fld:'';
              $htype = in_array($fld, array('hang','crash'))?$fld:'';

              $td = $tr->appendChild($doc->createElement('td'));
              $td->setAttribute('class', 'num');
              $link = $td->appendChild($doc->createElement('a', intval(@$builddata['cnt'][$fld])));
              $link->setAttribute('href',
                  'https://crash-stats.mozilla.com/search/?product='.$product
                  .'&version='.$pvdata[$builddata['pvid']]['release_version']
                  .'&build_id='.$builddata['build']
                  .'&release_channel='.($channel == 'other'?'':$channel)
                  .'&process_type='.$ptype.'&hang_type='.$htype
                  .'&date=%3E%3D'.$anadir.'&date=%3C'.date('Y-m-d', strtotime($anadir.' +1 day'))
                  .'&_facets=signature#facet-signature');
              if (@$buildadu[$idx]) {
                $td->appendChild($doc->createElement('br'));
                $small = $td->appendChild($doc->createElement('small',
                    print_rate(intval(@$builddata['cnt'][$fld]), $buildadu[$idx],
                               strtolower($pvdata[$builddata['pvid']]['build_type']),
                               $product)));
                $small->setAttribute('title', 'per 100 ADI');
                $small->setAttribute('class', 'buildrate');
              }
            }
            $td = $tr->appendChild($doc->createElement('td', $builddata['cnt']['norm_total']));
            $td->setAttribute('class', 'num');
            if (@$buildadu[$idx]) {
              $td->appendChild($doc->createElement('br'));
              $small = $td->appendChild($doc->createElement('small',
                  print_rate($builddata['cnt']['norm_total'], $buildadu[$idx],
                             strtolower($pvdata[$builddata['pvid']]['build_type']),
                             $product)));
              $small->setAttribute('title', 'per 100 ADI');
              $small->setAttribute('class', 'buildrate');
            }
          }
        }
        else {
          $body->appendChild($doc->createElement('p', 'No data found.'));
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
    $pages[$fweb] =
      array('product' => null,
            'channel' => null,
            'version' => null,
            'report' => 'buildcrashes',
            'report_sub' => null,
            'display_ver' => '',
            'display_rep' => 'Crashes / Build');
    file_put_contents($anafpages, json_encode($pages));
  }
}
print("\n");

// *** helper functions ***

// Function to print crash rates
function print_rate($count, $adu, $channel, $product) {
  $t_factor = ($channel == 'release' && $product == 'Firefox') ? 10 : 1;
  return sprintf('%.3f', $count * $t_factor * 100 / $adu);
}
?>
