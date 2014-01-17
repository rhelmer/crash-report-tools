/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

var gListQuery = "keywords=crash&chfield=%5BBug%20creation%5D&chfieldfrom=2013-01-01&chfieldto=2013-01-08"

var gStats = {
  count: 0,
  components: {},
  open: 0,
  fixed: 0,
};

var gDebug, gLog;


window.onload = function() {
  gDebug = document.getElementById("debug");
  gLog = document.getElementById("debugLog");

  var list_url = "https://api-dev.bugzilla.mozilla.org/latest/bug?" + gListQuery + "&include_fields=id,product,component,status,resolution,creator,assigned_to,creation_time";

  // Get bug list.
  fetchList(list_url,
    function(aData) {
      if (aData) {
        document.getElementById("bzURL").textContent = list_url;
        processData(aData.bugs);
      }
      else {
        document.getElementById("bzURL").textContent = "ERROR - couldn't find bug list!"
      }
    }
  );
}

function processData(aBugData) {
  var db = document.getElementById("dashboard");
  var par, comp;
  for (var i = 0; i <= aBugData.length - 1; i++) {
    gStats.count++;
    if (!aBugData[i].resolution) { gStats.open++; }
    else if (aBugData[i].resolution == "FIXED") { gStats.fixed++; }
    comp = aBugData[i].product + " > " + aBugData[i].component;
    if (gStats.components[comp]) {
      gStats.components[comp].count++;
    }
    else {
      gStats.components[comp] = {
        count: 1,
      };
    }
  }
  par = document.createElement("p");
  par.appendChild(document.createTextNode("Total Bugs: " + gStats.count));
  db.appendChild(par);
  par = document.createElement("p");
  par.appendChild(document.createTextNode((100 * gStats.open / gStats.count).toFixed(0) + "% open, " +
                                          (100 * gStats.fixed / gStats.count).toFixed(0) + "% fixed."));
  db.appendChild(par);
  for (var component in gStats.components) {
    par = document.createElement("p");
    par.appendChild(document.createTextNode(component + ": " + (100 * gStats.components[component].count / gStats.count).toFixed(1) + "%"));
    db.appendChild(par);
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
