#!/usr/bin/php
<?php

// get-buildadus.php 0.1
// retrieve ADU stats for builds
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

// notes for specific builds
$notes = array('Firefox-5.0-20110427143820' => '5.0b1',
               'Firefox-5.0-20110517192056' => '5.0b2',
               'Firefox-5.0-20110527093235' => '5.0b3',
               'Firefox-4.0.1-20110413222027' => 'official',
               'Firefox-4.0-20110318052756' => 'official',
              );
/*
select Crossjoin
({[Products].[Firefox].[5.0].[5.0]},
 {[Build Number].[2011].[04].[27].[20110427143820],
  [Build Number].[2011].[05].[17].[20110517192056],
  [Build Number].[2011].[05].[27].[20110527093235]}) ON COLUMNS,
NON EMPTY Hierarchize
(Union
 ({[Date].[2011].[5],[Date].[2011].[5].Children,
   [Date].[2011].[6], [Date].[2011].[6].Children})) ON ROWS
from [BlockList Analysis]


select Crossjoin
({[Products].[Firefox].[4.0].[4.0],
  [Products].[Firefox].[4.0].[4.0.1]},
 {[Build Number].[2011].[03].[18].[20110318052756],
  [Build Number].[2011].[04].[13].[20110413222027]}) ON COLUMNS,
NON EMPTY
{[Date].[2011].[5], [Date].[2011].[5].Children,
 [Date].[2011].[6], [Date].[2011].[6].Children} ON ROWS
from [BlockList Analysis]

select Crossjoin
({[Products].[Firefox].[6.0].[6.0],
  [Products].[Firefox].[7.0].[7.0]},
 {[Build Number].[2011].[07].[05].[20110705195857],
  [Build Number].[2011].[07].[13].[20110713171652],
  [Build Number].[2011].[07].[21].[20110721152715],
  [Build Number].[2011].[07].[29].[20110729080751],
  [Build Number].[2011].[08].[04].[20110804030150],
  [Build Number].[2011].[08].[11].[20110811165603],
  [Build Number].[2011].[08].[16].[20110816154714]}) ON COLUMNS,
NON EMPTY
{[Date].[2011].[8], [Date].[2011].[8].Children,
 [Date].[2011].[9], [Date].[2011].[9].Children} ON ROWS
from [BlockList Analysis]

select Crossjoin
({[Products].[Firefox].[5.0].[5.0]},
 {[Channels].[nightly],[Channels].[aurora],[Channels].[beta],[Channels].[release]}) ON COLUMNS,
NON EMPTY
{[Date].[2011].[7], [Date].[2011].[7].Children} ON ROWS
from [BlockList Analysis]
where [Build Number].[2011].[06].[15].[20110615151330]

select Crossjoin
({[Products].[Firefox].[4.0].[4.0.1]},
 {[Channels].[nightly],[Channels].[aurora],[Channels].[beta],[Channels].[release]}) ON COLUMNS,
NON EMPTY
{[Date].[2011].[7], [Date].[2011].[7].Children} ON ROWS
from [BlockList Analysis]
where [Build Number].[2011].[04].[13].[20110413222027]

*/

// *** URLs ***

$url_csvbase = 'http://people.mozilla.com/crash_analysis/';
$path_outputbase = '/home/robert/git-kairo/testbed/socorro/';

// *** code start ***

// get current day
$curtime = time();

$fadu = 'build-adu.json';
$adudata = file_exists($fadu)?json_decode(file_get_contents($fadu), true):array();

// *** helper functions ***

// Function to safely escape variables handed to awk
function awk_quote($string) {
  return strtr(preg_replace("/([\]\[^$.*?+{}\\\\()|])/", '\\\\$1', $string),
               array('`'=>'\140',"'"=>'\047'));
}

?>
