<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// This script contains utility functions used by multiple other scripts.


// Function to safely escape variables handed to awk
function awk_quote($string) {
  return strtr(preg_replace("/([\]\[^$.*?+{}\/\\\\()|])/", '\\\\$1', $string),
               array('`'=>'\140',"'"=>'\047'));
}

// Function to calculate square of value - mean
function arr_mean($array) { return array_sum($array) / count($array); }

// Function to calculate square of value - mean
function dist_square($x, $mean) { return pow($x - $mean, 2); }

// Function to calculate standard deviation (uses sist_square)
function arr_stddev($array, $mean = null) {
  // square root of sum of squares devided by N-1
  if (is_null($mean)) { $mean = arr_mean($array); }
  return sqrt(array_sum(array_map("dist_square", $array,
                                  array_fill(0, count($array), $mean))) /
              (count($array) - 1));
}

?>
