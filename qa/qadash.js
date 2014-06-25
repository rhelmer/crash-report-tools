/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// See http://dygraphs.com/ for graphs documentation.

var gCountIDs = [];

var gDataPath = "../../qa/";
// for local debugging
//gDataPath = "../socorro/qa/";

var gBzAPIPath = "https://bugzilla.mozilla.org/bzapi/";
var gBzBasePath = "https://bugzilla.mozilla.org/";

var iterqueries = {
  total: {desc: 'Total bugs in iteration'},
  verifyneeded: {desc: 'Verification needed'},
  verifydone: {desc: 'Verification done'},
  contactneeded: {desc: 'QA contact needed'},
  verifytriage: {desc: 'Verification +/- assessment needed'}
};

window.onload = function() {
  // Create iteration list.
  fetchFile(gDataPath + "qa.itermeta.json", "json", listCurrentData);
}

function listCurrentData(aData) {
  var curData = document.getElementById("currentdata");
  if (aData) {
    var today = makeISODayString(Date.now());
    for (var iteration in aData) {
      var dtStart = new Date(aData[iteration].start);
      var dtEnd = new Date(aData[iteration].end);
      if (aData[iteration].start <= today && aData[iteration].end >= today) {
        var iterDuration = Math.round((dtEnd - dtStart) / 86400000);
        var iterDay = Math.ceil((Date.now() - dtStart) / 86400000);
        // Create main line for iteration description.
        var iterElement = document.createElement("li");
        curData.appendChild(iterElement);
        var itername = document.createElement("span");
        itername.classList.add("itername");
        itername.textContent = iteration;
        iterElement.appendChild(itername);
        iterElement.appendChild(document.createTextNode(" "));
        var completed = document.createElement("span");
        completed.classList.add("itercompleted");
        completed.textContent = "(day " + iterDay + " of " + iterDuration + ")";
        iterElement.appendChild(completed);
        // List queries for that iteration.
        var iterList = document.createElement("ul");
        iterList.classList.add("queries");
        iterElement.appendChild(iterList);
        for (var qtype in aData[iteration].queries) {
          var qItem = document.createElement("li");
          // Link to buglist.
          var link = document.createElement("a");
          link.setAttribute("href", gBzBasePath + "buglist.cgi?" + aData[iteration].queries[qtype]);
          link.setAttribute("target", "_blank");
          link.textContent = iterqueries[qtype].desc;
          qItem.appendChild(link);
          qItem.appendChild(document.createTextNode(": "));
          // Display bug count, placeholder will be replaced async by updateCounts().
          var count = document.createElement("span");
          var count_id = "count_" + iteration + "_" + qtype;
          gCountIDs.push(count_id);
          count.classList.add("bugcount");
          count.setAttribute("id", count_id);
          count.setAttribute("data-query", aData[iteration].queries[qtype]);
          count.textContent = "…";
          qItem.appendChild(count);
          iterList.appendChild(qItem);
        }
      }
    }
    updateCounts();
  }
  else {
    // ERROR! We're screwed!
    var iterElement = document.createElement("li");
    curData.appendChild(iterElement);
    iterElement.appendChild(document.createTextNode("Error loading iteration query."));
  }
}

function updateCounts() {
  // Update bug counts.
  for (i = 0; i < gCountIDs.length; i++) {
    var count = document.getElementById(gCountIDs[i]);
    fetchFile(gBzAPIPath + "count?" + count.dataset.query, "json",
        function(aData, aCount) {
          if (aData)
            aCount.textContent = aData.data;
          else
            console.log("Getting count failed for: " + aCount.id);
        },
        count
    );
  }
}

function fetchFile(aURL, aFormat, aCallback, aCallbackForwards) {
  var XHR = new XMLHttpRequest();
  XHR.onreadystatechange = function() {
    if (XHR.readyState == 4) {/*
      gLog.appendChild(document.createElement("li"))
          .appendChild(document.createTextNode(aURL + " - " + XHR.status +
                                               " " + XHR.statusText));*/
    }
    if (XHR.readyState == 4 && XHR.status == 200) {
      // so far so good
      if (XHR.responseXML != null && aFormat == "xml" &&
          XHR.responseXML.getElementById('test').firstChild.data)
        aCallback(aXHR.responseXML.getElementById('test').firstChild.data, aCallbackForwards);
      else if (XHR.responseText != null && aFormat == "json")
        aCallback(JSON.parse(XHR.responseText), aCallbackForwards);
      else
        aCallback(XHR.responseText, aCallbackForwards);
    } else if (XHR.readyState == 4 && XHR.status != 200) {
      // fetched the wrong page or network error...
      aCallback(null, aCallbackForwards);
    }
  };
  XHR.open("GET", aURL);
  if (aFormat == "json") { XHR.setRequestHeader("Accept", "application/json"); }
  else if (aFormat == "xml") { XHR.setRequestHeader("Accept", "application/xml"); }
  try {
    XHR.send();
  }
  catch (e) {
    aCallback(null, aCallbackForwards);
  }
}

function makeISODayString(aTimestamp) {
  // ISO date format is YYYY-MM-DD
  var tsDate = new Date(aTimestamp);
  // Note that .getUTCMonth() returns a number between 0 and 11 (0 for January)!
  return tsDate.getUTCFullYear() + "-" +
         (tsDate.getUTCMonth() < 9 ? "0" : "") + (tsDate.getUTCMonth() + 1 ) + "-" +
         (tsDate.getUTCDate() < 10 ? "0" : "") + tsDate.getUTCDate();
}
