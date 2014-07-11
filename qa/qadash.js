/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this file,
 * You can obtain one at http://mozilla.org/MPL/2.0/. */

// See http://dygraphs.com/ for graphs documentation.

var gCountIDs = {iter: [], train: [], static: []};

var gDataPath = "../../qa/";
// for local debugging
//gDataPath = "../socorro/qa/";

var gBzAPIPath = "https://bugzilla.mozilla.org/bzapi/";
var gBzBasePath = "https://bugzilla.mozilla.org/";

var gProducts = {
  fx: {name: 'Firefox', abbr: 'Fx'},
  core: {name: 'Core', abbr: 'Core'},
  tkit: {name: 'Toolkit', abbr: 'Toolkit'},
  fennec: {name: 'Firefox for Android', abbr: 'Android'},
  loop: {name: 'Loop', abbr: 'Loop'}
};

var iterqueries = {
  total: {desc: 'Total bugs in iteration'},
  verifiable: {desc: 'Total bugs that can be verified'},
  verifydone: {desc: 'Verification done'},
  verifyneeded: {desc: 'Verification needed'},
  contactneeded: {desc: 'QA contact needed'},
  verifytriage: {desc: 'Verification +/- assessment needed'}
};

var trainqueries = {
  verifydone: {desc: 'Verification done'},
  verifyneeded: {desc: 'Verification needed'},
  verifytriage: {desc: 'Verification +/- assessment needed'}
};

var staticqueries = {
  nonTMfixed: {desc: 'Fixed in last 7 days without Target Milestone'},
  needURLs: {desc: 'Crash URLs needed'},
  qawanted: {desc: 'QA investigation wanted'},
  stepswanted: {desc: 'Steps to reproduce wanted'},
  windowwanted: {desc: 'Regression window wanted'}
};

window.onload = function() {
  // Create iteration list.
  document.getElementById("footer_itermeta").setAttribute("href", gDataPath + "qa.itermeta.json");
  document.getElementById("footer_trainmeta").setAttribute("href", gDataPath + "qa.trainmeta.json");
  document.getElementById("footer_staticmeta").setAttribute("href", gDataPath + "qa.staticmeta.json");
  document.getElementById("footer_bugdata").setAttribute("href", gDataPath + "qa.bugdata.json");
  fetchFile(gDataPath + "qa.itermeta.json", "json", listIterData);
  fetchFile(gDataPath + "qa.trainmeta.json", "json", listTrainData);
  fetchFile(gDataPath + "qa.staticmeta.json", "json", listStaticData);
}

function listIterData(aData) {
  var iterData = document.getElementById("iterdata");
  if (aData) {
    var today = makeISODayString(Date.now());
    for (var iteration in aData) {
      var dtStart = new Date(aData[iteration].start);
      var dtEnd = new Date(aData[iteration].end);
      var showEnd = makeISODayString(new Date(dtEnd.getFullYear(), dtEnd.getMonth(), dtEnd.getDate() + 7));
      if (aData[iteration].start <= today && showEnd >= today) {
        var iterDuration = Math.round((dtEnd - dtStart) / 86400000);
        var iterDay = Math.ceil((Date.now() - dtStart) / 86400000);
        // Create main line for iteration description.
        var iterElement = document.createElement("li");
        iterData.appendChild(iterElement);
        var itername = document.createElement("span");
        itername.classList.add("itername");
        itername.textContent = iteration;
        iterElement.appendChild(itername);
        iterElement.appendChild(document.createTextNode(" "));
        var completed = document.createElement("span");
        completed.classList.add("itercompleted");
        if (iterDay <= iterDuration) {
          completed.textContent = "(day " + iterDay + " of " + iterDuration + ")";
        }
        else if ((iterDay - iterDuration) == 1) {
          completed.textContent = "(ended yesterday)";
        }
        else {
          completed.textContent = "(ended " + (iterDay - iterDuration) + " days ago)";
        }
        iterElement.appendChild(completed);
        // List queries for that iteration.
        var iterList = document.createElement("ul");
        iterList.classList.add("queries");
        iterElement.appendChild(iterList);
        for (var qtype in iterqueries) {
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
          var count_id = "count_iter_" + iteration + "_" + qtype;
          gCountIDs.iter.push(count_id);
          count.classList.add("bugcount");
          count.setAttribute("id", count_id);
          count.setAttribute("data-query", aData[iteration].queries[qtype]);
          count.textContent = "…";
          qItem.appendChild(count);
          iterList.appendChild(qItem);
        }
      }
    }
    updateCounts('iter');
  }
  else {
    // ERROR! We're screwed!
    var iterElement = document.createElement("li");
    iterData.appendChild(iterElement);
    iterElement.appendChild(document.createTextNode("Error loading iteration query data."));
  }
}

function listTrainData(aData) {
  var trainData = document.getElementById("traindata");
  if (aData) {
    var today = makeISODayString(Date.now());
    var trainHeader = document.createElement("thead");
    trainData.appendChild(trainHeader);
    var trainRow = document.createElement("tr");
    trainHeader.appendChild(trainRow);
    var emptyCell = document.createElement("td");
    emptyCell.setAttribute("colspan", 2);
    trainRow.appendChild(emptyCell);
    for (var prod in gProducts) {
      var prodCell = document.createElement("th");
      prodCell.classList.add("product");
      prodCell.textContent = gProducts[prod].abbr;
      trainRow.appendChild(prodCell);
    }
    for (var train in aData) {
      var dtStart = new Date(aData[train].start);
      var dtEnd = new Date(aData[train].end);
      var showEnd = makeISODayString(new Date(dtEnd.getFullYear(), dtEnd.getMonth(), dtEnd.getDate() + 7));
      if (aData[train].start <= today && showEnd >= today) {
        // Create block for train data.
        var trainBody = document.createElement("tbody");
        trainData.appendChild(trainBody);
        var trainRow = document.createElement("tr");
        trainBody.appendChild(trainRow);
        var trainname = document.createElement("th");
        trainname.classList.add("trainname");
        trainname.setAttribute("rowspan", Object.keys(trainqueries).length);
        trainname.textContent = train;
        trainRow.appendChild(trainname);
        // List queries for that train.
        var rowCount = 1;
        for (var qtype in trainqueries) {
          if (rowCount > 1) {
            trainRow = document.createElement("tr");
            trainBody.appendChild(trainRow);
          }
          var qItem = document.createElement("td");
          // Link to buglist.
          qItem.textContent = trainqueries[qtype].desc;
          trainRow.appendChild(qItem);
          for (var prod in gProducts) {
            var qItem = document.createElement("td");
            qItem.classList.add("num");
            // Link to buglist.
            var link = document.createElement("a");
            link.setAttribute("href", gBzBasePath + "buglist.cgi?" +
                                      aData[train].queries[gProducts[prod].name][qtype]);
            link.setAttribute("target", "_blank");
            qItem.appendChild(link);
            // Display bug count, placeholder will be replaced async by updateCounts().
            var count_id = "count_train_" + train + "_" + prod + "_" + qtype;
            gCountIDs.train.push(count_id);
            link.classList.add("bugcount");
            link.setAttribute("id", count_id);
            link.setAttribute("data-query", aData[train].queries[gProducts[prod].name][qtype]);
            link.textContent = "…";
            qItem.appendChild(link);
            trainRow.appendChild(qItem);
          }
          rowCount++;
        }
      }
    }
    updateCounts('train');
  }
  else {
    // ERROR! We're screwed!
    var trainRow = document.createElement("tr");
    var trainCell = document.createElement("td");
    trainData.appendChild(trainRow);
    trainRow.appendChild(trainCell);
    trainCell.appendChild(document.createTextNode("Error loading train query data."));
  }
}

function listStaticData(aData) {
  var staticData = document.getElementById("staticdata");
  if (aData) {
    // List static queries.
    staticData.classList.add("queries");
    for (var qtype in staticqueries) {
      var qItem = document.createElement("li");
      // Link to buglist.
      var link = document.createElement("a");
      link.setAttribute("href", gBzBasePath + "buglist.cgi?" + aData.queries[qtype]);
      link.setAttribute("target", "_blank");
      link.textContent = staticqueries[qtype].desc;
      qItem.appendChild(link);
      qItem.appendChild(document.createTextNode(": "));
      // Display bug count, placeholder will be replaced async by updateCounts().
      var count = document.createElement("span");
      var count_id = "count_static_" + qtype;
      gCountIDs.static.push(count_id);
      count.classList.add("bugcount");
      count.setAttribute("id", count_id);
      count.setAttribute("data-query", aData.queries[qtype]);
      count.textContent = "…";
      qItem.appendChild(count);
      staticData.appendChild(qItem);
    }
    updateCounts('static');
  }
  else {
    // ERROR! We're screwed!
    var staticElement = document.createElement("li");
    staticData.appendChild(staticElement);
    staticElement.appendChild(document.createTextNode("Error loading static query data."));
  }
}

function updateCounts(aType) {
  // Update bug counts.
  for (i = 0; i < gCountIDs[aType].length; i++) {
    var count = document.getElementById(gCountIDs[aType][i]);
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
