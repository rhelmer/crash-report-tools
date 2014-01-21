/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

//var gListQuery = "keywords=crash&chfield=%5BBug%20creation%5D&chfieldfrom=2013-01-01&chfieldto=2013-01-08";
var gListQuery = "keywords=crash&chfield=%5BBug%20creation%5D&chfieldfrom=2013-01-01&chfieldto=2013-12-31";

var gBzAPIBase = "https://api-dev.bugzilla.mozilla.org/latest/";
var gBzBase = "https://bugzilla.mozilla.org/";

var gStats = {
  count: 0,
  components: {},
  open: 0,
  fixed: 0,
};

var gDebug, gLog, gProgLine, gBzListURL;


window.onload = function() {
  gDebug = document.getElementById("debug");
  gLog = document.getElementById("debugLog");
  gProgLine = document.getElementById("progressLine");

  var list_url = gBzAPIBase + "bug?" + gListQuery + "&include_fields=id,product,component,status,resolution,creator,assigned_to,creation_time";

  gBzListURL = gBzBase + "buglist.cgi?" + gListQuery;
  var bzLink = document.getElementById("bzURL");
  bzLink.href = gBzListURL;
  bzLink.textContent = gListQuery;
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
      trow.appendChild(cell);
      cell = document.createElement("td");
      cell.classList.add("pct");
      cell.appendChild(document.createTextNode((100 * comp.open / comp.count).toFixed(0) + "%"));
      trow.appendChild(cell);
      cell = document.createElement("td");
      cell.classList.add("pct");
      cell.appendChild(document.createTextNode((100 * comp.fixed / comp.count).toFixed(0) + "%"));
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
