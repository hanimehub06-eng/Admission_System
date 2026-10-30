/* ==========================================================================
   Views: Applicants list (#/applicants) and applicant record (#/applicants/:id)
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;

  // List filters survive navigation within the session
  var f = { q: "", status: "all", program: "", residency: "", sort: "newest", page: 1 };
  var tab = "profile";              // open tab on the record view
  var nextTab = null;               // tab to open when a list row asks for one
  var lastId = null;

  /* Quick filters beyond the six statuses */
  var EXTRA = {
    overdue: { label: "Overdue review", test: ADM.isOverdue },
    ready: { label: "Ready to schedule", test: ADM.canApprove }
  };

  function matches(a) {
    if (f.status !== "all") {
      if (EXTRA[f.status]) { if (!EXTRA[f.status].test(a)) return false; }
      else if (ADM.statusOf(a) !== f.status) return false;
    }
    if (f.program && a.programs[0] !== f.program) return false;
    if (f.residency === "d1" && !(a.resident && a.district === 1)) return false;
    if (f.residency === "d2" && !(a.resident && a.district === 2)) return false;
    if (f.residency === "non" && a.resident) return false;
    if (f.q) {
      var q = f.q.toLowerCase();
      var hay = (a.firstName + " " + a.middleName + " " + a.lastName + " " + a.id + " " + a.lrn + " " + a.email).toLowerCase();
      if (hay.indexOf(q) === -1) return false;
    }
    return true;
  }

  function filtered() {
    var list = ADM.state.applicants.filter(matches);
    var key = function (a) { return a.submittedAt || a.createdAt; };
    list.sort(function (x, y) {
      if (f.sort === "name") return x.lastName.localeCompare(y.lastName) || x.firstName.localeCompare(y.firstName);
      return f.sort === "oldest" ? (key(x) < key(y) ? -1 : 1) : (key(x) < key(y) ? 1 : -1);
    });
    return list;
  }

  /* ==========================================================================
     LIST
     ========================================================================== */
  function renderList($el) {
    var ui = ADM.ui;
    var all = ADM.state.applicants;
    var by = ADM.countBy(ADM.statusOf);
    var list = filtered();
    f.page = ui.clampPage(f.page, list.length);
    var rows = list.slice((f.page - 1) * ADM.PAGE_SIZE, f.page * ADM.PAGE_SIZE);

    var chip = function (key, label, count) {
      return '<button type="button" class="filter-chip' + (f.status === key ? " active" : "") + '" data-status="' + key + '">' + esc(label) + "<span>" + count + "</span></button>";
    };

    $el.html(
      '<div class="page-title page-title-inline" data-reveal><div><h1>Applicants</h1>' +
      '</div>' +
      '<button class="btn btn-ghost" type="button" id="export-applicants">' + icon("download") + "Export CSV</button></div>" +

      '<div class="chip-row" data-reveal role="group" aria-label="Filter by status">' +
      chip("all", "All", all.length) +
      ADM.STATUS_ORDER.map(function (st) { return chip(st, ADM.STATUSES[st].label, by[st] || 0); }).join("") +
      chip("overdue", EXTRA.overdue.label, all.filter(EXTRA.overdue.test).length) +
      chip("ready", EXTRA.ready.label, all.filter(EXTRA.ready.test).length) + "</div>" +

      '<section class="table-card" data-reveal>' +
      '<div class="table-toolbar">' +
      '<div class="search-field">' + icon("search") + '<input type="search" class="form-control" id="app-q" placeholder="Search name, ID, LRN or email" aria-label="Search applicants" value="' + esc(f.q) + '"></div>' +
      '<select class="form-select" id="app-program" aria-label="Filter by first-choice program"><option value="">All programs</option>' +
      ADM.state.programs.map(function (p) { return '<option value="' + p.code + '"' + (f.program === p.code ? " selected" : "") + ">" + esc(p.code) + "</option>"; }).join("") + "</select>" +
      '<select class="form-select" id="app-residency" aria-label="Filter by residency">' +
      [["", "All residency"], ["d1", "Pasig · District 1"], ["d2", "Pasig · District 2"], ["non", "Non-resident"]].map(function (o) { return '<option value="' + o[0] + '"' + (f.residency === o[0] ? " selected" : "") + ">" + o[1] + "</option>"; }).join("") + "</select>" +
      '<select class="form-select" id="app-sort" aria-label="Sort">' +
      [["newest", "Newest first"], ["oldest", "Oldest first"], ["name", "Name A–Z"]].map(function (o) { return '<option value="' + o[0] + '"' + (f.sort === o[0] ? " selected" : "") + ">" + o[1] + "</option>"; }).join("") + "</select>" +
      "</div>" +
      (rows.length ?
        '<div class="table-responsive"><table class="table data-table"><thead><tr><th>Applicant</th><th>1st choice</th><th>Residency</th><th>Documents</th><th>Status</th><th>Submitted</th><th><span class="visually-hidden">Open</span></th></tr></thead><tbody>' +
        rows.map(function (a) {
          var s = ADM.docSummary(a);
          return '<tr class="row-link" data-href="#/applicants/' + esc(a.id) + '" tabindex="0">' +
            "<td>" + ui.applicantCell(a) + "</td>" +
            '<td><span class="code-chip">' + esc(a.programs[0]) + "</span></td>" +
            "<td>" + esc(ADM.residency(a)) + "</td>" +
            '<td><span class="doc-meter" title="' + s.verified + " of " + s.total + ' verified"><span style="--w:' + ui.pct(s.verified, s.total) + '%"></span></span><small>' + s.verified + "/" + s.total + (s.resubmit ? ' · <b class="text-danger-soft">' + s.resubmit + " to redo</b>" : "") + "</small></td>" +
            "<td>" + ui.statusPill(a) + (ADM.isOverdue(a) ? ' <span class="late-dot" title="Past the review target">Overdue</span>' : "") + "</td>" +
            "<td>" + (a.submittedAt ? AO.fmt(a.submittedAt) + "<small class=\"d-block text-muted\">" + ui.relDays(a.submittedAt) + "</small>" : '<span class="text-muted">Not yet</span>') + "</td>" +
            '<td class="text-end">' + icon("arrowR") + "</td></tr>";
        }).join("") + "</tbody></table></div>" + ui.pager(list.length, f.page)
        : ui.empty("search", "No applicants match", "Try a different search or clear the filters.")) +
      "</section>"
    );
  }

  var searchTimer;
  $(document).on("input", "#app-q", function () {
    var v = this.value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function () {
      f.q = v.trim(); f.page = 1; ADM.refresh();
      var $q = $("#app-q"); $q.trigger("focus")[0].setSelectionRange(v.length, v.length);
    }, 250);
  });
  $(document).on("change", "#app-program", function () { f.program = this.value; f.page = 1; ADM.refresh(); });
  $(document).on("change", "#app-residency", function () { f.residency = this.value; f.page = 1; ADM.refresh(); });
  $(document).on("change", "#app-sort", function () { f.sort = this.value; ADM.refresh(); });
  $(document).on("click", "#view-applicants .filter-chip", function () { f.status = $(this).data("status"); f.page = 1; ADM.refresh(); });
  $(document).on("click", "#view-applicants [data-page]", function () { f.page = +$(this).data("page"); ADM.refresh(); });
  $(document).on("click", ".row-link", function (e) { if (!$(e.target).closest("a, button, input").length) { nextTab = $(this).data("open-tab") || null; ADM.go($(this).data("href")); } });
  $(document).on("keydown", ".row-link", function (e) { if (e.key === "Enter") { nextTab = $(this).data("open-tab") || null; ADM.go($(this).data("href")); } });

  $(document).on("click", "#export-applicants", function () {
    var list = filtered();
    ADM.downloadCSV("applicants-" + AO.toISO(AO.today()) + ".csv",
      ["Application ID", "LRN", "Surname", "Given name", "Middle name", "Sex", "Birthday", "Email", "Contact number", "Pasig resident", "District", "Barangay", "Address", "Type of school", "Last school attended", "Strand", "1st choice", "2nd choice", "3rd choice", "Status", "Documents verified", "Submitted"],
      list.map(function (a) {
        var s = ADM.docSummary(a);
        return [a.id, a.lrn, a.lastName, a.firstName, a.middleName, a.sex, a.birthday, a.email, a.phone, a.resident ? "Yes" : "No", a.district || "", a.barangay, a.address, a.schoolType, a.lastSchool, a.strand, a.programs[0], a.programs[1], a.programs[2],
          ADM.STATUSES[ADM.statusOf(a)].label, s.verified + "/" + s.total, a.submittedAt ? a.submittedAt.slice(0, 10) : ""];
      }));
    AO.showToast("Exported " + list.length + " applicants.");
  });

  /* ==========================================================================
     RECORD
     ========================================================================== */
  function kv(label, value) { return "<div><dt>" + esc(label) + "</dt><dd>" + (value === "" || value == null ? '<span class="text-muted">—</span>' : value) + "</dd></div>"; }

  function profileTab(a) {
    var suggested = ADM.STRAND_SUGGEST[a.strand] || [];
    return '<div class="row g-3">' +
      '<div class="col-lg-6"><section class="card-soft h-100"><h3 class="card-title">Basic information</h3><dl class="kv">' +
      kv("LRN", '<span class="mono">' + esc(a.lrn) + "</span>") + kv("Surname", esc(a.lastName)) + kv("Given name", esc(a.firstName)) + kv("Middle name", esc(a.middleName)) +
      kv("Sex", esc(a.sex)) + kv("Birthday", AO.fmt(a.birthday, { month: "long", day: "numeric", year: "numeric" })) +
      kv("Contact number", esc(a.phone)) + kv("Email", esc(a.email)) + "</dl></section></div>" +
      '<div class="col-lg-6 d-flex flex-column gap-3"><section class="card-soft"><h3 class="card-title">Residency</h3><dl class="kv">' +
      kv("Residency status", a.resident ? "Pasig resident" : "Non-resident") +
      (a.resident ? kv("District", "District " + a.district) + kv("Barangay", esc(a.barangay)) : "") +
      kv("Address", esc(a.address)) + "</dl></section>" +
      '<section class="card-soft"><h3 class="card-title">Education</h3><dl class="kv">' +
      kv("Type of school", esc(a.schoolType)) + kv("Last school attended", esc(a.lastSchool)) + kv("Strand", esc(a.strand)) +
      kv("Applicant type", a.applicantType === "graduate" ? "SHS graduate" : "Ongoing Grade 12") +
      kv("Guardian", a.hasGuardian ? "Has a guardian (affidavit required)" : "Parent") + "</dl></section></div>" +
      '<div class="col-12"><section class="card-soft"><h3 class="card-title">Program choices</h3><ol class="choice-list">' +
      a.programs.map(function (c, i) {
        return "<li><span class=\"choice-rank\">" + ["1st", "2nd", "3rd"][i] + '</span><span class="code-chip">' + esc(c) + "</span><span>" + esc(ADM.program(c).name) + "</span>" +
          (suggested.indexOf(c) !== -1 ? '<span class="tag tag-verified">Fits strand</span>' : "") + "</li>";
      }).join("") + "</ol>" +
      '<p class="suggest-note">' + icon("star") + "Suggested for " + esc(a.strand) + ": " + suggested.map(function (c) { return '<span class="code-chip">' + esc(c) + "</span>"; }).join(" ") + "</p></section></div></div>";
  }

  function docsTab(a) {
    var ui = ADM.ui;
    var editable = !a.exam && a.submittedAt;
    var s = ADM.docSummary(a);
    return '<section class="table-card">' +
      '<div class="table-toolbar"><div><strong>' + s.verified + " of " + s.total + " verified</strong>" +
      '<small class="d-block text-muted">' + (editable ? "Write a clear remark whenever a document needs resubmission." : a.exam ? "Documents are locked once the applicant is approved for the exam." : "The applicant hasn't submitted yet.") + "</small></div>" +
      (editable && s.pending ? '<button class="btn btn-ghost ms-auto" type="button" id="verify-all">' + icon("check") + "Verify all " + s.pending + " for review</button>" : "") + "</div>" +
      '<ul class="review-list">' + ui.docItems(a, editable) + "</ul>" +
      '<div class="physical-note">' + icon("folder") + "<span>Collected in person: " + esc(ADM.PHYSICAL_ITEMS.join(", ")) + ".</span></div></section>";
  }

  function examTab(a) {
    var ui = ADM.ui;
    if (!a.exam) {
      return '<section class="card-soft">' + ui.empty("calendar", "Not scheduled yet",
        ADM.canApprove(a) ? "All documents are verified. Approve the applicant to give them an exam session and permit." : "The applicant can be scheduled once every document is verified.") +
        (ADM.canApprove(a) ? '<div class="text-center pb-2"><button class="btn btn-brand" type="button" data-approve>' + icon("calendar") + "Approve for exam</button></div>" : "") + "</section>";
    }
    var s = ADM.session(a.exam.sessionId);
    var att = a.exam.attendance;
    var r = a.result;
    return '<div class="row g-3"><div class="col-lg-6"><section class="card-soft h-100"><h3 class="card-title">Exam schedule</h3>' +
      '<div class="exam-ticket"><div class="ticket-date"><span>' + AO.fmt(s.date, { month: "short" }) + "</span><strong>" + AO.parseDate(s.date).getDate() + "</strong></div>" +
      '<div class="ticket-info"><strong>' + esc(ADM.BATCHES[s.batch].label) + " · " + AO.fmt(s.date, { weekday: "long", month: "long", day: "numeric" }) + "</strong>" +
      "<span>" + icon("clock") + esc(ADM.BATCHES[s.batch].time) + "</span><span>" + icon("pin") + esc(s.venue) + "</span><span>" + icon("ticket") + "Permit " + esc(a.exam.permit) + "</span></div></div>" +
      '<dl class="kv mt-3">' + kv("Permit checked", a.exam.permitChecked ? "Yes" : "No") + kv("Application form checked", a.exam.formChecked ? "Yes" : "No") +
      kv("Attendance", att === "present" ? '<span class="tag tag-verified">' + icon("check") + "Present</span>" : att === "absent" ? '<span class="tag tag-resubmit">Absent</span>' : "Not recorded") + "</dl>" +
      '<div class="d-flex gap-2 flex-wrap mt-3"><a class="btn btn-ghost btn-sm" href="#/exams/' + s.id + '">' + icon("clipboard") + "Open roster</a>" +
      (!r && att !== "present" ? '<button class="btn btn-ghost btn-sm" type="button" data-reschedule>' + icon("refresh") + "Reschedule</button>" : "") + "</div></section></div>" +
      '<div class="col-lg-6"><section class="card-soft h-100"><h3 class="card-title">Result</h3>' +
      (r ? '<dl class="kv">' + kv("Decision", ui.pill(r.decision)) +
        kv("Score", '<span class="internal">' + r.score + " / 100</span> " + '<small class="text-muted">' + icon("lock") + "internal</small>") +
        kv("Recorded", esc(r.recordedBy) + " · " + esc(ui.dt(r.recordedAt))) +
        kv("Released to applicant", r.released ? "Yes · " + esc(ui.dt(r.releasedAt || r.recordedAt)) : "Not yet") + "</dl>" +
        (!r.released ? '<div class="d-flex gap-2 flex-wrap mt-3"><button class="btn btn-ghost btn-sm" type="button" data-record>' + icon("pencil") + "Edit result</button>" +
          '<button class="btn btn-brand btn-sm" type="button" data-release>' + icon("send") + "Release result</button></div>" : "")
        : att === "present" ? ui.empty("award", "Result not recorded", "Record Passed or Did not pass. The score stays internal.") + '<div class="text-center pb-2"><button class="btn btn-brand" type="button" data-record>' + icon("award") + "Record result</button></div>"
          : ui.empty("clock", "Waiting for the exam", att === "absent" ? "The applicant missed the exam. Reschedule them to a new session." : "Mark attendance on the exam day roster first.")) +
      "</section></div></div>";
  }

  function medicalTab(a) {
    var ui = ADM.ui;
    var st = ADM.medStatus(a);
    if (st === null) {
      return '<section class="card-soft">' + ui.empty("shield", "Not yet", "Medical requirements open once the applicant passes and the result is released.") + "</section>";
    }
    var ap = ADM.medApproval(a);
    var note = st === "cleared" ? "Approved by " + ap.by + (ap.at ? " · " + ui.dt(ap.at) : "") + ". This applicant's admission is complete."
      : st === "none" ? "The applicant hasn't sent their medical requirements to the clinic yet."
        : st === "resubmit" ? "The clinic nurse sent a file back. Waiting for the applicant to replace it."
          : "With the clinic nurse. Their approval shows up here once they send it.";
    return '<section class="table-card">' +
      '<div class="table-toolbar"><div><strong>Clinic decision</strong> <span class="tag ' + ADM.MED_STATUS[st].tag + '">' + (st === "cleared" ? icon("check") : "") + esc(ADM.MED_STATUS[st].label) + "</span>" +
      '<small class="d-block text-muted">' + esc(note) + "</small></div>" +
      '<span class="text-muted small ms-auto">' + icon("lock") + " Read-only · " + (ADM.STAFF.roleKey === "super_admin" ? "you can open these files as Super Admin (each opening is logged)" : "files are confidential to the university clinic") + "</span></div>" +
      '<ul class="review-list">' + ADM.MED_TYPES.map(function (m) {
        var f = ADM.fileOf(a, m.key) || { status: "missing" };
        var has = f.status !== "missing";
        return '<li class="review-item status-' + f.status + '"><span class="doc-icon tone-3">' + icon(m.icon) + "</span>" +
          '<div class="review-main"><div class="doc-title-row"><h3>' + esc(m.title) + "</h3>" + ui.docTag(f.status) + '<span class="cond-note">' + esc(m.note) + "</span></div>" +
          (has ? '<p class="review-file">' + esc(f.file) + " · " + AO.fileSize(f.size) + " · uploaded " + ui.relDays(f.uploadedAt).toLowerCase() + "</p>" : '<p class="review-file">Not uploaded yet</p>') +
          (f.remark ? '<p class="review-remark">' + icon("flag") + esc(f.remark) + "</p>" : "") +
          (f.reviewedBy ? '<p class="review-by">' + (f.status === "verified" ? "Verified" : "Reviewed") + " by " + esc(f.reviewedBy) + " · " + esc(ui.dt(f.reviewedAt)) + "</p>" : "") + "</div>" +
          (has && ADM.STAFF.roleKey === "super_admin" ? '<button class="btn btn-ghost btn-sm" type="button" data-med-view="' + m.key + '">' + icon("eye") + "View</button>" : "") +
          "</div></li>";
      }).join("") + "</ul></section>";
  }

  function historyTab(a) {
    var ui = ADM.ui;
    var items = a.history.slice().sort(function (x, y) { return x.at < y.at ? 1 : -1; });
    return '<section class="card-soft"><div class="panel-heading mb-3"><div><h2>History of changes</h2><p>Read-only. Every action on this record is kept here and in the audit log.</p></div></div>' +
      '<ol class="timeline">' + items.map(function (h) {
        return '<li><span class="tl-dot"></span><div><p>' + esc(h.text) + "</p><small>" + esc(h.by) + " · " + esc(ui.dt(h.at)) + "</small></div></li>";
      }).join("") + "</ol></section>";
  }

  function renderRecord($el, id) {
    var ui = ADM.ui;
    var a = ADM.applicant(id);
    if (!a) { $el.html('<section class="card-soft">' + ui.empty("search", "Applicant not found", "Check the application ID.") + '<div class="text-center pb-2"><a class="btn btn-ghost" href="#/applicants">Back to applicants</a></div></section>'); return; }
    if (lastId !== id) { tab = nextTab || "profile"; lastId = id; }
    nextTab = null;

    var st = ADM.statusOf(a);
    var s = ADM.docSummary(a);
    var med = ADM.medStatus(a);
    var tabs = [["profile", "Profile"], ["documents", "Documents"], ["exam", "Exam &amp; result"]];
    if (med !== null) tabs.push(["medical", "Medical"]);
    tabs.push(["history", "History"]);
    if (tab === "medical" && med === null) tab = "profile";
    var bodies = { profile: profileTab, documents: docsTab, exam: examTab, medical: medicalTab, history: historyTab };

    // Primary action depends on where the applicant is
    var primary = "";
    if (ADM.canApprove(a)) primary = '<button class="btn btn-brand" type="button" data-approve>' + icon("calendar") + "Approve for exam</button>";
    else if (st === "submitted" && s.pending) primary = '<a class="btn btn-brand" href="#/documents/' + esc(a.id) + '">' + icon("folder") + "Review " + s.pending + " document" + (s.pending === 1 ? "" : "s") + "</a>";
    else if (a.exam && a.exam.attendance === "present" && !a.result) primary = '<button class="btn btn-brand" type="button" data-record>' + icon("award") + "Record result</button>";
    else if (a.result && !a.result.released) primary = '<button class="btn btn-brand" type="button" data-release>' + icon("send") + "Release result</button>";

    $el.html(
      '<a class="back-link" href="#/applicants" data-reveal>' + icon("arrowL") + "All applicants</a>" +
      '<section class="record-hero" data-reveal>' + ui.avatar(a, "avatar-xl") +
      '<div class="record-id"><h1>' + esc(ADM.fullName(a)) + "</h1>" +
      '<p class="record-meta"><span class="mono">' + esc(a.id) + "</span>" + ui.statusPill(a) +
      (ADM.isOverdue(a) ? '<span class="late-dot">Overdue</span>' : "") + "</p></div>" +
      '<div class="record-actions"><button class="icon-button" type="button" data-print-form aria-label="Print application form" data-bs-toggle="tooltip" data-bs-title="Print form">' + icon("printer") + "</button>" + primary + "</div></section>" +
      '<nav class="tabs" role="tablist" data-reveal>' + tabs.map(function (t) {
        return '<button type="button" role="tab" class="tab' + (tab === t[0] ? " active" : "") + '" aria-selected="' + (tab === t[0]) + '" data-tab="' + t[0] + '">' + t[1] + "</button>";
      }).join("") + "</nav>" +
      '<div class="tab-body" data-reveal>' + bodies[tab](a) + "</div>"
    );
  }

  function currentApplicant() { return ADM.applicant(lastId); }

  $(document).on("click", "#view-applicant [data-tab]", function () { tab = $(this).data("tab"); ADM.refresh(); });
  $(document).on("click", "#view-applicant [data-print-form]", function () { ADM.ui.printApplicationForm(currentApplicant()); });

  $(document).on("click", "#view-applicant [data-doc-view]", function () {
    var a = currentApplicant(), key = $(this).data("doc-view");
    var editable = !a.exam && a.docs[key].status !== "verified";
    ADM.ui.previewDoc(a, key, '<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>' +
      (editable && !a.exam ? '<button type="button" class="btn btn-warn" data-modal-resubmit="' + key + '">' + icon("flag") + 'Resubmit</button><button type="button" class="btn btn-ok" data-modal-verify="' + key + '">' + icon("check") + "Verify</button>" : ""));
  });
  $(document).on("click", "#view-applicant [data-med-view]", function () { ADM.ui.previewDoc(currentApplicant(), $(this).data("med-view")); });
  $(document).on("click", "[data-modal-verify]", function () { ADM.ui.modal("docModal").hide(); verify(currentApplicant(), $(this).data("modal-verify")); });
  $(document).on("click", "[data-modal-resubmit]", function () {
    var key = $(this).data("modal-resubmit");
    ADM.ui.modal("docModal").hide();
    $("#docModal").one("hidden.bs.modal", function () { resubmit(currentApplicant(), key); });
  });

  function verify(a, key) {
    if (ADM.actions.verifyDoc(a, key)) {
      ADM.refresh();
      AO.showToast(ADM.canApprove(a) ? "All documents verified. Ready to approve for the exam." : "Document verified.");
    }
  }
  function resubmit(a, key) {
    ADM.ui.askRemark(a, key, function (remark) {
      if (ADM.actions.resubmitDoc(a, key, remark)) { ADM.refresh(); AO.showToast("Sent back to the applicant with your remark."); }
    });
  }
  $(document).on("click", "#view-applicant [data-doc-verify]", function () { verify(currentApplicant(), $(this).data("doc-verify")); });
  $(document).on("click", "#view-applicant [data-doc-resubmit]", function () { resubmit(currentApplicant(), $(this).data("doc-resubmit")); });
  $(document).on("click", "#view-applicant [data-doc-reject]", function () {
    var a = currentApplicant(), key = $(this).data("doc-reject");
    ADM.ui.askRemark(a, key, function (remark) {
      if (ADM.actions.rejectDoc(a, key, remark)) { ADM.refresh(); AO.showToast("Document rejected. The applicant was told why."); }
    });
  });
  $(document).on("click", "#verify-all", function () {
    var a = currentApplicant();
    var n = ADM.actions.verifyAllPending(a);
    ADM.refresh();
    AO.showToast(n + " document" + (n === 1 ? "" : "s") + " verified.");
  });

  $(document).on("click", "#view-applicant [data-approve]", function () {
    var a = currentApplicant();
    ADM.ui.askSession(a, "approve", function (sid) {
      if (ADM.actions.approveForExam(a, sid)) { tab = "exam"; ADM.refresh(); AO.showToast("Approved. Exam permit " + a.exam.permit + " issued."); }
      else AO.showToast("That session is full. Choose another.");
    });
  });
  $(document).on("click", "#view-applicant [data-reschedule]", function () {
    var a = currentApplicant();
    ADM.ui.askSession(a, "reschedule", function (sid) {
      if (ADM.actions.reschedule(a, sid)) { ADM.refresh(); AO.showToast("Exam moved to " + ADM.sessionLabel(ADM.session(sid)) + "."); }
    });
  });
  $(document).on("click", "#view-applicant [data-record]", function () {
    var a = currentApplicant();
    ADM.ui.askResult(a, function (score, decision) {
      if (ADM.actions.recordResult(a, score, decision)) { tab = "exam"; ADM.refresh(); AO.showToast("Result saved. Release it when the batch is ready."); }
    });
  });
  $(document).on("click", "#view-applicant [data-release]", function () {
    var a = currentApplicant();
    ADM.ui.confirm({ title: "Release this result?", text: ADM.fullName(a) + " will see \"" + (a.result.decision === "passed" ? "Passed" : "Did not pass") + "\" on their status page" + (a.result.decision === "passed" ? " with their acceptance letter" : "") + ". This can't be undone.", ok: "Release result" }, function () {
      ADM.actions.releaseResults([a]);
      ADM.refresh();
      AO.showToast("Result released to the applicant.");
    });
  });

  ADM.views.applicants = {
    title: "Applicants",
    render: renderList,
    search: function (q) { f.q = q; f.status = "all"; f.page = 1; },
    filterStatus: function (st) { f.status = st; f.page = 1; }
  };
  /* Medical tab: read-only. The clinic nurse checks and approves in the Clinic module (views/clinic.js). */

  ADM.views.applicant = {
    title: function (id) { var a = ADM.applicant(id); return a ? ADM.fullName(a) : "Applicant"; },
    render: renderRecord,
    profile: profileTab   // also shown beside the files on Document verification
  };
})(jQuery);
