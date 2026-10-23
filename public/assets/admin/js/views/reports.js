/* ==========================================================================
   View: Reports (#/reports) — generated from the system instead of separate
   spreadsheets. Each card exports its numbers as CSV.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  var list = { program: "", decision: "passed" };

  /* Each report returns { rows: [{label, sub, value, display, title}], csv: [header, rows] } */
  var REPORTS = {
    perProgram: {
      title: "Applicants per program", desc: "By first-choice program",
      build: function () {
        var by = ADM.countBy(function (a) { return a.programs[0]; });
        var rows = ADM.state.programs.map(function (p) { return { label: p.code, sub: p.name, value: by[p.code] || 0, title: p.name + ": " + (by[p.code] || 0) + " applicants" }; })
          .sort(function (x, y) { return y.value - x.value; });
        return { rows: rows, csv: [["Program code", "Program", "Applicants (1st choice)", "Slots"], ADM.state.programs.map(function (p) { return [p.code, p.name, by[p.code] || 0, p.slots]; })] };
      }
    },
    status: {
      title: "Status breakdown", desc: "Where every applicant is right now",
      build: function () {
        var by = ADM.countBy(ADM.statusOf), total = ADM.state.applicants.length;
        var rows = ADM.STATUS_ORDER.map(function (st) { var n = by[st] || 0; return { label: ADM.STATUSES[st].label, value: n, display: n + " · " + ADM.ui.pct(n, total) + "%" }; });
        return { rows: rows, csv: [["Status", "Applicants", "Share"], rows.map(function (r) { return [r.label, r.value, ADM.ui.pct(r.value, total) + "%"]; })] };
      }
    },
    documents: {
      title: "Document completion", desc: "Verified uploads among applicants who must submit each one",
      build: function () {
        var rows = ADM.DOC_TYPES.map(function (d) {
          var need = 0, ok = 0, up = 0;
          ADM.state.applicants.forEach(function (a) {
            if (!ADM.requiredDocs(a).some(function (x) { return x.key === d.key; })) return;
            need++;
            var doc = a.docs[d.key];
            if (doc && doc.status !== "missing") up++;
            if (doc && doc.status === "verified") ok++;
          });
          return { label: d.title, value: ADM.ui.pct(ok, need), display: ADM.ui.pct(ok, need) + "%", sub: ok + " verified · " + up + " uploaded · " + need + " required", title: d.title + ": " + ok + " of " + need + " verified", raw: [d.title, need, up, ok] };
        });
        return { rows: rows, max: 100, csv: [["Document", "Required from", "Uploaded", "Verified", "Verified %"], rows.map(function (r) { return r.raw.concat([r.display]); })] };
      }
    },
    attendance: {
      title: "Exam attendance", desc: "Present examinees per completed session",
      build: function () {
        var rows = ADM.sortedSessions().filter(ADM.sessionPast).map(function (s) {
          var roster = ADM.sessionRoster(s.id);
          var p = roster.filter(function (a) { return a.exam.attendance === "present"; }).length;
          var ab = roster.filter(function (a) { return a.exam.attendance === "absent"; }).length;
          return { label: ADM.sessionLabel(s), sub: s.venue, value: p, display: p + "/" + roster.length, title: p + " present, " + ab + " absent of " + roster.length, raw: [s.date, ADM.BATCHES[s.batch].label, s.venue, roster.length, p, ab] };
        });
        return { rows: rows, max: Math.max.apply(null, ADM.state.sessions.map(function (s) { return s.capacity; })), csv: [["Date", "Batch", "Venue", "Scheduled", "Present", "Absent"], rows.map(function (r) { return r.raw; })] };
      }
    },
    passRate: {
      title: "Pass rate", desc: "Passed among recorded results, by first-choice program",
      build: function () {
        var rows = ADM.state.programs.map(function (p) {
          var rec = ADM.state.applicants.filter(function (a) { return a.result && a.programs[0] === p.code; });
          var ok = rec.filter(function (a) { return a.result.decision === "passed"; }).length;
          return { label: p.code, sub: rec.length + " results", value: ADM.ui.pct(ok, rec.length), display: rec.length ? ADM.ui.pct(ok, rec.length) + "%" : "—", title: p.name + ": " + ok + " of " + rec.length + " passed", raw: [p.code, p.name, rec.length, ok, rec.length - ok] };
        });
        return { rows: rows, max: 100, csv: [["Program code", "Program", "Results recorded", "Passed", "Did not pass", "Pass rate"], rows.map(function (r) { return r.raw.concat([r.display]); })] };
      }
    },
    medical: {
      title: "Medical clearance", desc: "Applicants who passed, by medical status",
      build: function () {
        var by = {}, total = 0;
        ADM.state.applicants.forEach(function (a) { var st = ADM.medStatus(a); if (st === null) return; by[st] = (by[st] || 0) + 1; total++; });
        var rows = ["none", "pending", "resubmit", "cleared"].map(function (k) { var n = by[k] || 0; return { label: ADM.MED_STATUS[k].label, value: n, display: n + " · " + ADM.ui.pct(n, total) + "%" }; });
        return { rows: rows, csv: [["Medical status", "Applicants"], rows.map(function (r) { return [r.label, r.value]; })] };
      }
    },
    residency: {
      title: "Residency", desc: "Pasig residents by district, and non-residents",
      build: function () {
        var by = ADM.countBy(function (a) { return a.resident ? "d" + a.district : "non"; }), total = ADM.state.applicants.length;
        var rows = [["d1", "Pasig · District 1"], ["d2", "Pasig · District 2"], ["non", "Non-resident"]].map(function (k) { var n = by[k[0]] || 0; return { label: k[1], value: n, display: n + " · " + ADM.ui.pct(n, total) + "%" }; });
        return { rows: rows, csv: [["Residency", "Applicants"], rows.map(function (r) { return [r.label, r.value]; })] };
      }
    }
  };

  function daily() {
    var rows = [];
    for (var i = 29; i >= 0; i--) {
      var d = new Date(AO.today().getTime() - i * 86400000), iso = AO.toISO(d);
      var n = ADM.state.applicants.filter(function (a) { return a.submittedAt && a.submittedAt.slice(0, 10) === iso; }).length;
      rows.push({ iso: iso, label: d.toLocaleDateString("en-US", { month: "short", day: "numeric" }), value: n, tick: i % 5 === 0, title: AO.fmt(iso, { weekday: "short", month: "short", day: "numeric" }) + ": " + n + " submitted" });
    }
    return rows;
  }

  function card(key, wide) {
    var r = REPORTS[key], data = r.build();
    return '<div class="' + (wide ? "col-12" : "col-lg-6") + '" data-reveal><section class="card-soft h-100 report-card"><div class="panel-heading"><div><h2>' + esc(r.title) + "</h2><p>" + esc(r.desc) + "</p></div>" +
      '<button class="icon-button" type="button" data-export="' + key + '" aria-label="Export ' + esc(r.title) + ' as CSV" data-bs-toggle="tooltip" data-bs-title="Export CSV">' + icon("download") + "</button></div>" +
      (data.rows.length ? ADM.ui.hbars(data.rows, data.max) : ADM.ui.empty("chart", "No data yet")) + "</section></div>";
  }

  function passList() {
    return ADM.state.applicants.filter(function (a) {
      return a.result && a.result.decision === list.decision && (!list.program || a.programs[0] === list.program);
    }).sort(function (x, y) { return x.programs[0].localeCompare(y.programs[0]) || x.lastName.localeCompare(y.lastName); });
  }

  function render($el) {
    var ui = ADM.ui;
    var days = daily();
    var people = passList();

    $el.html(
      '<div class="page-title" data-reveal><h1>Reports</h1></div>' +

      '<div class="row g-3">' +
      '<div class="col-12" data-reveal><section class="card-soft"><div class="panel-heading"><div><h2>Daily submissions</h2><p>Applications submitted per day, last 30 days</p></div>' +
      '<button class="icon-button" type="button" data-export="daily" aria-label="Export daily submissions as CSV" data-bs-toggle="tooltip" data-bs-title="Export CSV">' + icon("download") + "</button></div>" +
      ui.columns(days, "Daily submissions for the last 30 days") + "</section></div>" +
      card("perProgram") + card("status") + card("documents") + card("attendance") + card("passRate") + card("residency") + card("medical", true) +

      '<div class="col-12" data-reveal><section class="table-card"><div class="table-toolbar"><div><strong>Passed and did not pass, per program</strong><small class="d-block text-muted">Includes results not yet released</small></div>' +
      '<div class="segmented segmented-sm ms-auto" role="radiogroup" aria-label="Decision">' +
      '<input type="radio" class="btn-check" name="list-decision" id="ld-passed" value="passed"' + (list.decision === "passed" ? " checked" : "") + '><label for="ld-passed">Passed</label>' +
      '<input type="radio" class="btn-check" name="list-decision" id="ld-failed" value="failed"' + (list.decision === "failed" ? " checked" : "") + '><label for="ld-failed">Did not pass</label></div>' +
      '<select class="form-select" id="list-program" aria-label="Program"><option value="">All programs</option>' +
      ADM.state.programs.map(function (p) { return '<option value="' + p.code + '"' + (list.program === p.code ? " selected" : "") + ">" + esc(p.code) + "</option>"; }).join("") + "</select>" +
      '<button class="btn btn-ghost" type="button" data-export="list">' + icon("download") + "Export</button></div>" +
      (people.length ? '<div class="table-responsive table-scroll"><table class="table data-table"><thead><tr><th>Program</th><th>Applicant</th><th>Session</th><th>Released</th></tr></thead><tbody>' +
        people.map(function (a) {
          return '<tr><td><span class="code-chip">' + esc(a.programs[0]) + '</span></td><td><a class="plain-link" href="#/applicants/' + esc(a.id) + '">' + ui.applicantCell(a) + "</a></td><td>" + esc(ADM.sessionLabel(ADM.session(a.exam.sessionId))) + "</td><td>" + (a.result.released ? "Yes" : '<span class="text-muted">Not yet</span>') + "</td></tr>";
        }).join("") + "</tbody></table></div>" : ui.empty("users", "No applicants in this list")) +
      "</section></div></div>"
    );
  }

  $(document).on("change", "input[name=list-decision]", function () { list.decision = this.value; ADM.refresh(); });
  $(document).on("change", "#list-program", function () { list.program = this.value; ADM.refresh(); });

  $(document).on("click", "#view-reports [data-export]", function () {
    var key = $(this).data("export"), stamp = AO.toISO(AO.today());
    if (key === "daily") {
      ADM.downloadCSV("daily-submissions-" + stamp + ".csv", ["Date", "Submitted"], daily().map(function (r) { return [r.iso, r.value]; }));
    } else if (key === "list") {
      ADM.downloadCSV((list.decision === "passed" ? "passed" : "did-not-pass") + "-" + (list.program || "all") + "-" + stamp + ".csv",
        ["Program", "Application ID", "Surname", "Given name", "Session", "Released"],
        passList().map(function (a) { return [a.programs[0], a.id, a.lastName, a.firstName, ADM.sessionLabel(ADM.session(a.exam.sessionId)), a.result.released ? "Yes" : "No"]; }));
    } else {
      var csv = REPORTS[key].build().csv;
      ADM.downloadCSV(key.replace(/[A-Z]/g, function (c) { return "-" + c.toLowerCase(); }) + "-" + stamp + ".csv", csv[0], csv[1]);
    }
    AO.showToast("Report exported.");
  });

  ADM.views.reports = { title: "Reports", render: render };
})(jQuery);
