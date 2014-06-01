#!/usr/bin/php
<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script retrieves stats on weekly Socorro bugs.

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

// Start date to calculate stats for - always goes up until current week
$startdate = time() - 14.5 * 86400; //strtotime('2009-01-01');


$fake_counts = false; //true; // DEBUG only!

// *** URLs ***

$on_moz_server = file_exists('/mnt/crashanalysis/rkaiser/');
$outdir = 'socorro-bugs';

if ($on_moz_server) { chdir('/mnt/crashanalysis/rkaiser/'); }
else { chdir('/mnt/mozilla/projects/socorro/'); }

// *** code start ***

// get current day
$curtime = time();

// make sure our output dir exists
if (!file_exists($outdir)) { mkdir($outdir); }

$bdfile = $outdir.'/socorro.bugdata.json';
$fweb = '%s.weeklybugs.html';

if (file_exists($bdfile)) {
  print('Reading stored Socorro bug data'."\n");
  $bugdata = json_decode(file_get_contents($bdfile), true);
}
else {
  $bugdata = array();
}

$calcweeks = array();

if ($startdate < strtotime('1998-01-01') || $startdate > $curtime) {
  // if the argument results in no useful date, calculate this and last two weeks
  $startdate = $curtime - 14.5 * 86400;
}

// first get start time of given week
$btime = getdate($startdate);
// week start day (0=sun,1=mon,...,6=sat; default to 1)
$wsd = 1;
// start time: difference of week day number and week start day before today
$dayoffset = -($btime['wday'] - $wsd); if ($dayoffset > 0) { $dayoffset -= 7; }
$starttime = strtotime($btime['year'].'-'.$btime['mon'].'-'.$btime['mday'].' '.$dayoffset.' day');
while ($starttime < ($curtime - 12*3600)) {
  // start time of following week
  $dayoffset += 7;
  $nw_starttime = strtotime($btime['year'].'-'.$btime['mon'].'-'.$btime['mday'].' '.$dayoffset.' day');

  $calcweeks[] = array('start'=>$starttime, 'end'=>$nw_starttime);

  // set startttime for next possible week
  $starttime = $nw_starttime;
}

// get stats for requested weeks
foreach ($calcweeks as $wkdef) {
  $weekstart = date('Y-m-d', $wkdef['start']);
  print('Fetching Socorro bug data for week of '.$weekstart."\n");

  $weekdata = array();
  foreach (array('new','fixed','triaged') as $querytype) {
    $bugzilla_url = getPeriodBugURL($querytype, $wkdef['start'], $wkdef['end']);
    $bugcount = getBugCount($bugzilla_url);
    if ($bugcount !== false) {
      $weekdata['count_'.$querytype] = $bugcount;
    }
  }

  if (count($weekdata)) {
    $weekdata['time_update'] = time();
    $bugdata[$weekstart] = $weekdata;
  }
  else {
    print('ERROR: No data!'."\n");
  }

  ksort($bugdata); // sort by date (key), ascending

  file_put_contents($bdfile, json_encode($bugdata));
}

if (count($bugdata)) {
  $firstyear = date('o', strtotime(min(array_keys($bugdata))));
  $lastyear = date('o', strtotime(max(array_keys($bugdata))));
  print('Data found for '.$firstyear.' through '.$lastyear.', writing output...'."\n");

  for ($year = $firstyear; $year <= $lastyear; $year++) {
    $yearfweb = $outdir.'/'.sprintf($fweb, $year);

    // create out an HTML page
    print('Writing HTML output for '.$year."\n");
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->formatOutput = true; // we want a nice output

    $cgraph = array('scale_x'=>10, 'height'=>300,
                    'offset_x'=>2, 'offset_y'=>2,
                    'rows'=>array(array('tblcolumn'=>2, 'scale'=>100, 'stack'=>false, 'fill'=>true,
                                        'color'=>'rgba(255, 127, 0, .5)'),
                                  array('tblcolumn'=>3, 'scale'=>100, 'stack'=>false, 'fill'=>true,
                                        'color'=>'rgba(0, 204, 0, .5)'),
                                  array('tblcolumn'=>4, 'scale'=>100, 'stack'=>true, 'fill'=>true,
                                        'color'=>'rgba(0, 0, 255, .5)')));

    $root = $doc->appendChild($doc->createElement('html'));
    $head = $root->appendChild($doc->createElement('head'));
    $title = $head->appendChild($doc->createElement('title',
        'Weekly Socorro Bugs - '.$year));

    $body = $root->appendChild($doc->createElement('body'));
    $h1 = $body->appendChild($doc->createElement('h1',
        'Weekly Socorro Bugs - '.$year));

    // navigation
    $pgnav = $doc->createDocumentFragment();
    $pnav = $pgnav->appendChild($doc->createElement('p', 'Years'.': '));
    $pnav->setAttribute('class', 'pages');
    if ($year > $firstyear) {
      // first page
      $link = $pnav->appendChild($doc->createElement('a', '|&lt;'));
      $link->setAttribute('href', sprintf($fweb, $firstyear));
      $link->setAttribute('class', 'pagefirstlast');
      // previous page
      if ($year > $firstyear + 1) {
        $pnav->appendChild($doc->createTextNode(' '));
        $link = $pnav->appendChild($doc->createElement('a', '&lt;&lt;'));
        $link->setAttribute('href', sprintf($fweb, $year - 1));
        $link->setAttribute('class', 'pagebkfwd');
      }
    }
    for ($i = $firstyear; $i <= $lastyear; $i++) {
      $pnav->appendChild($doc->createTextNode(' '));
      if ($i == $year) {
        $pnum = $pnav->appendChild($doc->createElement('span', '['));
        $pnum->setAttribute('class', 'pagenum curpage');
      }
      else {
        $pnum = $pnav->appendChild($doc->createElement('a'));
        $pnum->setAttribute('href', sprintf($fweb, $i));
        $pnum->setAttribute('class', 'pagenum');
      }
      $pnum->appendChild($doc->createTextNode($i));
      if ($i == $year) {
        $pnum->appendChild($doc->createTextNode(']'));
      }
    }
    if ($year < $lastyear) {
      // next page
      if ($year < $lastyear - 1) {
        $pnav->appendChild($doc->createTextNode(' '));
        $link = $pnav->appendChild($doc->createElement('a', '&gt;&gt;'));
        $link->setAttribute('href', sprintf($fweb, $year+1));
      }
      // last page
      $pnav->appendChild($doc->createTextNode(' '));
      $link = $pnav->appendChild($doc->createElement('a', '&gt;|'));
      $link->setAttribute('href', sprintf($fweb, $lastyear));
      $link->setAttribute('class', 'pagefirstlast');
    }
    $body->appendChild($pgnav->cloneNode(true));

    if (count($cgraph)) {
      $canvas = $body->appendChild($doc->createElement('canvas'));
      $canvas->setAttribute('id', 'graphcanvas');
    }

    $bugqueries = array('new', 'fixed', 'triaged');

    // extract weeks belonging to this year
    $yeardata = array();
    foreach ($bugdata as $weekstart=>$weekdata) {
      if (date('o', strtotime($weekstart)) == $year) {
        $yeardata[$weekstart] = $weekdata;
      }
    }
    if (count($yeardata)) {
      $body->appendChild($doc->createElement('p',
          sprintf('Weekly statistics on Socorro Bugzilla activity in %s:', $year)));
      $table = $body->appendChild($doc->createElement('table'));
      $table->setAttribute('id', 'weeklybugs');
      $table->setAttribute('class', 'border');
      $thead = $table->appendChild($doc->createElement('thead'));
      $trow = $thead->appendChild($doc->createElement('tr'));
      $trow->appendChild($doc->createElement('th', 'Week'));
      $trow->appendChild($doc->createElement('th', 'Start'));
      $trow->appendChild($doc->createElement('th', 'End'));
      $th = $trow->appendChild($doc->createElement('th', 'New'));
      $th->setAttribute('style', 'background-color: '.$cgraph['rows'][0]['color'].';');
      $th = $trow->appendChild($doc->createElement('th', 'Fixed'));
      $th->setAttribute('style', 'background-color: '.$cgraph['rows'][1]['color'].';');
      $th = $trow->appendChild($doc->createElement('th', 'Triaged'));
      $th->setAttribute('style', 'background-color: '.$cgraph['rows'][2]['color'].';');

      $tbody = $table->appendChild($doc->createElement('tbody'));
      foreach ($yeardata as $weekstart=>$weekdata) {
        $week_start = strtotime($weekstart);
        $week_end = strtotime(date('Y-m-d', $week_start).' +7 day');
        $trow = $tbody->appendChild($doc->createElement('tr'));
        $td = $trow->appendChild($doc->createElement('th', date('W/o', $week_start)));
        $td->setAttribute('title', intval(date('W', $week_start)));
        $td->setAttribute('class', 'week');
        $td = $trow->appendChild($doc->createElement('td', date('Y-m-d', $week_start)));
        $td->setAttribute('title', date('Y-m-d', $week_start));
        $td->setAttribute('class', 'startdate small');
        $td = $trow->appendChild($doc->createElement('td', date('Y-m-d', $week_end - 1)));
        $td->setAttribute('title', date('Y-m-d', $week_end - 1));
        $td->setAttribute('class', 'enddate small dis');
        foreach ($bugqueries as $querytype) {
          $td = $trow->appendChild($doc->createElement('td'));
          $td->setAttribute('class', 'num');
          $link = $td->appendChild($doc->createElement('a',
              is_null($weekdata['count_'.$querytype])?'No data':$weekdata['count_'.$querytype]));
          $link->setAttribute('href', getPeriodBugURL($querytype, $week_start, $week_end));
        }
      }
    }
    else {
      $body->appendChild($doc->createElement('p', 'No bug statistics found.'));
    }

    if (count($cgraph)) {
      $weeksperyear = date('W', strtotime($year.'-12-28')); // 28th of December is always in the last week
      $script = $head->appendChild($doc->createElement('script'));
      $script->setAttribute('type', 'text/javascript');
      $graph =
           'function canvasDraw() {'."\n"
          .' var canvas = document.getElementById("graphcanvas");'."\n"
          .' if (document.getElementById("weeklybugs")) { canvas.style.display=""; }'."\n"
          .' else { canvas.style.display="none"; return; }'."\n"
          .' var context = canvas.getContext("2d");'."\n"
          .' canvas.width = '.($weeksperyear*$cgraph['scale_x']+4).';'."\n"
          .' canvas.height = '.($cgraph['height']+4).';'."\n"
          .' var gx = '.$cgraph['offset_x'].'; var gy = '.$cgraph['offset_y'].';'."\n"
          .' var gwd = '.$weeksperyear.'; var ght = '.$cgraph['height'].';'."\n"
          .' context.setTransform(1, 0, 0, -1, 0, canvas.height);'."\n"
          .' context.fillStyle = "rgba(214, 204, 189, 1.0)";'."\n"
          .' context.fillRect(0, 0, canvas.width, canvas.height);'."\n"
          .' context.fillStyle = "rgba(236, 240, 248, 1.0)";'."\n"
          .' context.fillRect(gx, gy, gwd*'.$cgraph['scale_x'].', ght);'."\n"
          .' var tbody = document.getElementById("weeklybugs").getElementsByTagName("tbody")[0];'."\n"
          .' var trows = tbody.getElementsByTagName("tr");'."\n"
          .' var linewid = '.$cgraph['scale_x'].';'."\n"
          .' var gridcolor = "rgba(127, 127, 127, .25)";'."\n";
      foreach ($cgraph['rows'] as $idx=>$row) {
        $graph .=
           ' var gval'.$idx.';'."\n"
          .' var gcolor'.$idx.' = "'.$row['color'].'";'."\n"
          .' var gscale'.$idx.' = '.$row['scale'].'/ght;'."\n";
      }
      $graph .=
           ' var dt_yearstart = new Date('.$year.', 0, 1);'."\n"
          .' var dt_current, dtparts, ix;'."\n"
          .' var lasttop = 0;'."\n"
          .' for (var i = 0; i < tbody.children.length; i++) {'."\n"
          .'  wk_current = parseInt(trows[i].getElementsByTagName("th")[0].getAttribute("title")) - 1;'."\n"
          .'  ix = gx + wk_current * '.$cgraph['scale_x'].';'."\n"
          .'  sdtparts = trows[i].getElementsByTagName("td")[0].getAttribute("title").split("-");'."\n"
          .'  edtparts = trows[i].getElementsByTagName("td")[1].getAttribute("title").split("-");'."\n"
          .'  if ((edtparts[1] > sdtparts[1]) && (edtparts[1] % 3 == 1)) {'."\n"
          .'    context.fillStyle = gridcolor;'."\n"
          .'    context.fillRect(ix, gy, 1, ght);'."\n"
          .'  }'."\n";
      foreach ($cgraph['rows'] as $idx=>$row) {
        if (!$row['stack']) { $graph .= '  lasttop = 0;'."\n"; }
        $graph .=
           '  gval'.$idx.' = parseInt'
          .'(trows[i].getElementsByTagName("td")['.$row['tblcolumn'].'].getElementsByTagName("a")[0].firstChild.nodeValue);'."\n"
          .'  context.fillStyle = gcolor'.$idx.';'."\n"
          .'  context.fillRect(ix, gy + '.($row['fill']?'lasttop':'gval'.$idx.'/gscale'.$idx.' - 1')
          .', linewid, '.($row['fill']?'gval'.$idx.'/gscale'.$idx:'1').');'."\n"
          .'  lasttop = gval'.$idx.'/gscale'.$idx.';'."\n";
      }
      $graph .=
           ' }'."\n"
          .'}'."\n";
      $script->appendChild($doc->createCDATASection($graph));
      $scriptelem = $body->appendChild($doc->createElement('script', 'canvasDraw();'));
      $scriptelem->setAttribute('type', 'text/javascript');
    }
    $style = $head->appendChild($doc->createElement('style'));
    $style->setAttribute('type', 'text/css');
    $style->appendChild($doc->createCDATASection(
        'body {'."\n"
        .' background: #FFFFFF;'."\n"
        .' color: #000000;'."\n"
        .' font-family: sans-serif;'."\n"
        .'}'."\n"
        .'.small {'."\n"
        .' font-size: small;'."\n"
        .'}'."\n"
        .'.dis { color: #808080; }'."\n"
        .'th { font-size: small; }'."\n"
        .'table.border {'."\n"
        .' border-spacing: 0px;'."\n"
        .' border-collapse: collapse;'."\n"
        .' empty-cells: show;'."\n"
        .' border-left: 1px solid #404040;'."\n"
        .' border-top: 1px solid #404040;'."\n"
        .'}'."\n"
        .'table.border th, table.border td {'."\n"
        .' border-bottom: 1px solid #404040;'."\n"
        .' border-right: 1px solid #404040;'."\n"
        .'}'."\n"
        .'table.border td {'."\n"
        .' padding-left: 3px;'."\n"
        .' padding-right: 3px;'."\n"
        .'}'."\n"
        .'td.num {'."\n"
        .' text-align: right;'."\n"
        .'}'."\n"
        .'p.pages .pagenum.curpage, p.pages #curpage {'."\n"
        .' font-weight: bold; color: #000000;'."\n"
        .'}'."\n"
    ));


    $body->appendChild($pgnav);

    $doc->saveHTMLFile($yearfweb);
  }

  print("\n");
}
// debug only line
// print_r($flashdata);

// *** helper functions ***
function getPeriodBugURL($scheme, $date_start, $date_end = null) {
  $ymd_start = date('Y-m-d H:i:s', $date_start);
  if (is_null($date_end)) {
    $date_end = strtotime($ymd_start.' +7 day');
  }
  $ymd_end = date('Y-m-d H:i:s', $date_end);

  $bugzilla_url = 'https://bugzilla.mozilla.org/buglist.cgi?';
  $bugzilla_url .= 'field0-0-0=longdesc&type0-0-0=equals&value0-0-0=Socorro';
  $bugzilla_url .= '&field0-0-1=product&type0-0-1=equals&value0-0-1=Socorro';
  switch ($scheme) {
    case 'new':
      // new bugs
      $bugzilla_url .= '&chfieldfrom='.rawurlencode($ymd_start);
      $bugzilla_url .= '&chfieldto='.rawurlencode($ymd_end).'&chfield=%5BBug%20creation%5D';
      break;
    case 'fixed':
      // fixed bugs
      $bugzilla_url .= '&resolution=FIXED';
      $bugzilla_url .= '&chfieldfrom='.rawurlencode($ymd_start);
      $bugzilla_url .= '&chfieldto='.rawurlencode($ymd_end).'&chfield=resolution';
      break;
    case 'triaged':
      // triaged (resolved non-fixed) bugs
      $bugzilla_url .= '&resolution=INVALID&resolution=WONTFIX&resolution=DUPLICATE';
      $bugzilla_url .= '&resolution=WORKSFORME&resolution=INCOMPLETE&resolution=EXPIRED';
      $bugzilla_url .= '&resolution=MOVED';
      $bugzilla_url .= '&chfieldfrom='.rawurlencode($ymd_start);
      $bugzilla_url .= '&chfieldto='.rawurlencode($ymd_end).'&chfield=resolution';
      break;
    case 'default':
      $bugzilla_url = '';
      break;
  }
  return $bugzilla_url;
}

function getBugCount($listurl) {
  if (preg_match('/buglist\.cgi(\?.*)$/', $listurl, $regs)) {
    $list_json = file_get_contents('https://api-dev.bugzilla.mozilla.org/latest/count'.$regs[1]);
    if ($list_json) {
      $list_info = json_decode($list_json, true);
      return $list_info['data'];
    }
  }
  return false;
}


?>
