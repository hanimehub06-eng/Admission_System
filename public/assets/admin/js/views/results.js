/* ==========================================================================
   View: Results (#/results) — record Passed / Did not pass, then release.
   Scores stay internal. Results are released only through the system.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  var f = { tab: "record", session: "", program: "", page: 1 };

  var TABS = {
    record: { label: "To record", test: function (a) { return a.exam && a.exam.attendance === "present" && !a.result; } },
    release: { label: "Ready to release", test: function (a) { return a.result && !a.result.released; } },
    released: { label: "Released", test: function (a) { return a.result && a.result.released; } }
  };

  function inTab(key) {
    return ADM.state.applicants.filter(function (a) {
      if (!TABS[key].test(a)) return false;
      if (f.session && a.exam.sessionId !== f.session) return false;
      if (f.program && a.programs[0] !== f.program) return false;
      return true;
    }).sort(function (x, y) { return x.lastName.localeCompare(y.lastName); });
  }

  function render($el) {
    var ui = ADM.ui;
    var list = inTab(f.tab);
    f.page = ui.clampPage(f.page, list.length);
    var rows = list.slice((f.page - 1) * ADM.PAGE_SIZE, f.page * ADM.PAGE_SIZE);
    var sessions = ADM.sortedSessions().filter(ADM.sessionPast);
    var passedIn = list.filter(function (a) { return a.result && a.result.decision === "passed"; }).length;

    // Passed (recorded) per program vs slots
    var perProgram = ADM.state.programs.map(function (p) {
      var n = ADM.state.applicants.filter(function (a) { return a.result && a.result.decision === "passed" && a.programs[0] === p.code; }).length;
      return { label: p.code, value: n, display: n + "/" + p.slots, title: p.name + ": " + n + " passed of " + p.slots + " slots" };
    });

    $el.html(
      '<div class="page-title" data-reveal><h1>Results</h1>' +
      '</div>' +
      '<div class="row g-3"><div class="col-xl-8 d-flex flex-column gap-3">' +
      '<nav class="tabs" role="tablist" data-reveal>' + Object.keys(TABS).map(function (k) {
        return '<button type="button" role="tab" class="tab' + (f.tab === k ? " active" : "") + '" aria-selected="' + (f.tab === k) + '" data-rtab="' + k + '">' + TABS[k].label + " <span>" + inTab(k).length + "</span></button>";
      }).join("") + "</nav>" +
      '<section class="table-card" data-reveal><div class="table-toolbar">' +
      '<select class="form-select" id="res-session" aria-label="Filter by session"><option value="">All sessions</option>' +
      sessions.map(function (s) { return '<option value="' + s.id + '"' + (f.session === s.id ? " selected" : "") + ">" + esc(ADM.sessionLabel(s)) + "</option>"; }).join("") + "</select>" +
      '<select class="form-select" id="res-program" aria-label="Filter by program"><option value="">All programs</option>' +
      ADM.state.programs.map(function (p) { return '<option value="' + p.code + '"' + (f.program === p.code ? " selected" : "") + ">" + esc(p.code) + "</option>"; }).join("") + "</select>" +
      (f.tab === "release" && list.length ? '<button class="btn btn-brand ms-auto" type="button" id="release-all">' + icon("send") + "Release " + list.length + " result" + (list.length === 1 ? "" : "s") + "</button>" : "") +
      (f.tab === "released" && list.length ? '<button class="btn btn-ghost ms-auto" type="button" id="export-results">' + icon("download") + "Export CSV</button>" : "") +
      "</div>" +
      (f.tab !== "record" && list.length ? '<p class="table-note">' + passedIn + " passed · " + (list.length - passedIn) + " did not pass</p>" : "") +
      (rows.length ? '<div class="table-responsive"><table class="table data-table"><thead><tr><th>Examinee</th><th>Session</th><th>1st choice</th>' +
        (f.tab === "record" ? "" : "<th>Decision</th><th>Score <span class=\"text-muted fw-normal\">(internal)</span></th>") + '<th class="text-end">Action</th></tr></thead><tbody>' +
        rows.map(function (a) {
          var s = ADM.session(a.exam.sessionId);
          return "<tr>" +
            '<td><a href="#/applicants/' + esc(a.id) + '" class="plain-link">' + ui.applicantCell(a) + "</a></td>" +
            "<td>" + esc(ADM.sessionLabel(s)) + "</td>" +
            '<td><span class="code-chip">' + esc(a.programs[0]) + "</span></td>" +
            (f.tab === "record" ? "" : "<td>" + ui.pill(a.result.decision) + '</td><td><span class="internal">' + a.result.score + "</span></td>") +
            '<td class="text-end">' + (f.tab === "released" ? '<span class="text-muted small">' + esc(AO.fmt((a.result.releasedAt || a.result.recordedAt))) + "</span>"
              : '<button class="btn ' + (f.tab === "record" ? "btn-brand" : "btn-ghost") + ' btn-sm" type="button" data-res-record="' + a.id + '">' + icon(f.tab === "record" ? "award" : "pencil") + (f.tab === "record" ? "Record" : "Edit") + "</button>") +
            "</td></tr>";
        }).join("") + "</tbody></table></div>" + ui.pager(list.length, f.page)
        : ui.empty(f.tab === "record" ? "check" : "award", f.tab === "record" ? "Nothing to record" : f.tab === "release" ? "Nothing waiting to release" : "No released results yet",
          f.tab === "record" ? "Results can be recorded once examinees are marked present." : "")) +
      "</section></div>" +
      '<div class="col-xl-4" data-reveal><section class="card-soft"><div class="panel-heading"><div><h2>Passed per program</h2><p>Recorded passes against available slots</p></div></div>' +
      ui.hbars(perProgram, Math.max.apply(null, ADM.state.programs.map(function (p) { return p.slots; }))) + "</section>" +
      '<div class="info-note mt-3">' + icon("lock") + "<p>Release results only through the system, never by phone, paper, or private message. Applicants who passed get their acceptance letter on release.</p></div></div></div>"
    );
  }

  $(document).on("click", "[data-rtab]", function () { f.tab = $(this).data("rtab"); f.page = 1; ADM.refresh(); });
  $(document).on("change", "#res-session", function () { f.session = this.value; f.page = 1; ADM.refresh(); });
  $(document).on("change", "#res-program", function () { f.program = this.value; f.page = 1; ADM.refresh(); });
  $(document).on("click", "#view-results [data-page]", function () { f.page = +$(this).data("page"); ADM.refresh(); });

  $(document).on("click", "[data-res-record]", function () {
    var a = ADM.applicant($(this).data("res-record"));
    ADM.ui.askResult(a, function (score, decision) {
      if (ADM.actions.recordResult(a, score, decision)) { ADM.refresh(); AO.showToast("Result saved for " + ADM.fullName(a) + "."); }
    });
  });

  $(document).on("click", "#release-all", function () {
    var list = inTab("release");
    var passed = list.filter(function (a) { return a.result.decision === "passed"; }).length;
    ADM.ui.confirm({
      title: "Release " + list.length + " result" + (list.length === 1 ? "" : "s") + "?",
      text: passed + " passed and " + (list.length - passed) + " did not pass. Each applicant is notified and sees Passed or Did not pass on their status page. This can't be undone.",
      ok: "Release results"
    }, function () {
      var n = ADM.actions.releaseResults(list);
      f.tab = "released"; f.page = 1;
      ADM.refresh();
      AO.showToast(n + " result" + (n === 1 ? "" : "s") + " released.");
    });
  });

  $(document).on("click", "#export-results", function () {
    var list = inTab("released");
    ADM.downloadCSV("released-results-" + AO.toISO(AO.today()) + ".csv",
      ["Application ID", "Surname", "Given name", "1st choice", "Session", "Decision", "Released"],
      list.map(function (a) { return [a.id, a.lastName, a.firstName, a.programs[0], ADM.sessionLabel(ADM.session(a.exam.sessionId)), ADM.STATUSES[a.result.decision].label, (a.result.releasedAt || a.result.recordedAt).slice(0, 10)]; }));
  });

  ADM.views.results = {
    title: "Results",
    render: render,
    forSession: function (id) { f.session = id; f.tab = "record"; f.page = 1; }
  };
})(jQuery);
