/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

var gBzAPIBase = "https://api-dev.bugzilla.mozilla.org/latest/";
var gBzBase = "https://bugzilla.mozilla.org/";

var gStats = {
  count: 0,
  components: {},
  open: 0,
  fixed: 0,
};

var gDebug, gLog, gProgLine, gBzListURL, gBzInput;

window.onload = function() {
  gDebug = document.getElementById("debug");
  gLog = document.getElementById("debugLog");
  gProgLine = document.getElementById("progressLine");

  gBzInput = document.getElementById("bzURL");

  var query_string = location.search;
  if (!query_string) {
    var curDate = new Date();
    var lastWeek = new Date(); lastWeek.setDate(lastWeek.getDate() - 7);
    query_string = "?keywords=crash&chfield=%5BBug%20creation%5D&chfieldfrom=" + makeISODate(lastWeek) + "&chfieldto=" + makeISODate(curDate);
  }
  gBzInput.value = gBzBase + "buglist.cgi" + query_string;
  document.getElementById("bzLink").href = gBzInput.value;
  document.getElementById("permaLink").href = query_string;

  document.getElementById("analyzeForm").onsubmit = runAnalysis;
  gBzInput.oninput = function() {
    document.getElementById("bzAnalyze").disabled = false;
    document.getElementById("repLinks").classList.add("hidden");
  };
  // If people have linked to a specific report, run it directly.
  if (location.search) { runAnalysis(); }
}

function runAnalysis() {
  document.getElementById("bzAnalyze").disabled = true;
  var bz_strip_regex = new RegExp("^" + gBzBase + "buglist.cgi\\?");
  var list_query = gBzInput.value.replace(bz_strip_regex, "");
  // Causes us to reload this pag: location.search = list_query;
  var list_url = gBzAPIBase + "bug?" + list_query + "&include_fields=id,product,component,status,resolution,creator,assigned_to,creation_time";

  gBzListURL = gBzBase + "buglist.cgi?" + list_query;
  document.getElementById("bzLink").href = gBzListURL;
  if (location.host) {
    document.getElementById("permaLink").href = location.protocol + "//" + location.host + location.pathname + "?" + list_query;
  }
  else { // In this case, we know the browser also has no working search string but let's give them something.
    document.getElementById("permaLink").href = "?" + list_query;
  }
  document.getElementById("repLinks").classList.remove("hidden");

  gProgLine.textContent = "Fetching bug list...";

  // Get bug list.
  fetchList(list_url,
    function(aData) {
      if (aData) {
        processData(aData.bugs);
      }
      else {
        gProgLine.textContent = "ERROR - couldn't find bug list!"
      }
    }
  );
  return false;
}

function processData(aBugData) {
  var db = document.getElementById("dashboard");
  var comp, trow, cell;
  gProgLine.textContent = "processing...";
  for (var i = 0; i <= aBugData.length - 1; i++) {
    comp = aBugData[i].product + " > " + aBugData[i].component;
    // Bump bug counts (globally and per component - care that latter exists).
    gStats.count++;
    if (gStats.components[comp]) {
      gStats.components[comp].count++;
    }
    else {
      gStats.components[comp] = {
        product: aBugData[i].product,
        component: aBugData[i].component,
        count: 1,
        open: 0,
        fixed: 0,
      };
    }
    // Bump open/fixed counts based on resolution.
    if (!aBugData[i].resolution) {
      gStats.open++;
      gStats.components[comp].open++;
    }
    else if (aBugData[i].resolution == "FIXED") {
      gStats.fixed++;
      gStats.components[comp].fixed++;
    }
  }
  gProgLine.classList.add("hidden");

  // Display output.
  document.getElementById("totalLine").classList.remove("hidden");
  document.getElementById("totalNum").textContent = gStats.count;
  document.getElementById("totalOpen").textContent = (100 * gStats.open / gStats.count).toFixed(0);
  document.getElementById("totalFixed").textContent = (100 * gStats.fixed / gStats.count).toFixed(0);

  var compSorted = Object.keys(gStats.components).sort(
    function (a, b) { return gStats.components[b].count - gStats.components[a].count; }
  );

  if (compSorted.length) {
    document.getElementById("compTbl").classList.remove("hidden");
    var tbody = document.getElementById("compTblBody");
    for (var i = 0; i <= compSorted.length - 1; i++) {
      comp = gStats.components[compSorted[i]];
      trow = document.createElement("tr");
      cell = document.createElement("td");
      link = document.createElement("a");
      link.href = gBzListURL + "&product=" + encodeURIComponent(comp.product) + "&component=" + encodeURIComponent(comp.component);
      link.appendChild(document.createTextNode(compSorted[i]));
      cell.appendChild(link);
      trow.appendChild(cell);
      cell = document.createElement("td");
      cell.classList.add("pct");
      cell.appendChild(document.createTextNode((100 * comp.count / gStats.count).toFixed(1) + "%"));
      cell.setAttribute("title", comp.count + " bugs in component");
      trow.appendChild(cell);
      cell = document.createElement("td");
      cell.classList.add("pct");
      cell.appendChild(document.createTextNode((100 * comp.open / comp.count).toFixed(0) + "%"));
      cell.setAttribute("title", comp.open + " open bugs");
      trow.appendChild(cell);
      cell = document.createElement("td");
      cell.classList.add("pct");
      cell.appendChild(document.createTextNode((100 * comp.fixed / comp.count).toFixed(0) + "%"));
      cell.setAttribute("title", comp.fixed + " fixed bugs");
      trow.appendChild(cell);
      tbody.appendChild(trow);
    }
  }
}

function fetchList(aURL, aCallback) {
  var XHR = new XMLHttpRequest();
  XHR.onreadystatechange = function() {
    if (XHR.readyState == 4) {/*
      gLog.appendChild(document.createElement("li"))
          .appendChild(document.createTextNode(aURL + " - " + XHR.status +
                                               " " + XHR.statusText));*/
    }
    if (XHR.readyState == 4 && XHR.status == 200 && XHR.responseText != null) {
      aCallback(JSON.parse(XHR.responseText));
    } else if (XHR.readyState == 4 && XHR.status != 200) {
      // fetched the wrong page or network error...
      aCallback(null);
    }
  };
  XHR.open("GET", aURL);
  XHR.setRequestHeader("Accept", "application/json");
  try {
    XHR.send();
  }
  catch (e) {
    aCallback(null);
  }
}

function makeISODate(aDate) {
  // ISO date in format YYYY-MM-DD
  // Note that .getUTCMonth() returns a number between 0 and 11 (0 for January)!
  return aDate.getUTCFullYear() + "-" +
         (aDate.getUTCMonth() < 9 ? "0" : "") + (aDate.getUTCMonth() + 1 ) + "-" +
         (aDate.getUTCDate() < 10 ? "0" : "") + aDate.getUTCDate();
}
