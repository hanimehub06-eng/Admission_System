/* ==========================================================================
   PLP Online Admission — Admission Office: configuration, demo data and state
   Needs assets/shared/js/core.js (window.AO). Exposes window.ADM.
   State comes from the Laravel API (GET /api/admin/state); every change goes
   through admin-actions.js, which sends it to the API.
   ========================================================================== */
(function ($) {
  "use strict";

  /* ==========================================================================
     CONFIG (edit to match the official admission period)
     ========================================================================== */
  // Filled from the signed-in account when the state loads
  var STAFF = { name: "", initials: "", role: "", email: "" };
  // <body data-role="nurse"> is the clinic portal (resources/views/nurse.blade.php):
  // it loads a medical-only state from /api/clinic instead of /api/admin.
  var ROLE = document.body.getAttribute("data-role") === "nurse" ? "nurse" : "admin";
  var API = ROLE === "nurse" ? "/api/clinic" : "/api/admin";
  var REVIEW_SLA_DAYS = 3;        // working days to review a new application
  var PASSING_SCORE = 60;         // internal; scores are never shown to applicants
  var PAGE_SIZE = 12;

  var STATUSES = {
    draft: { label: "Draft", pill: "draft" },
    submitted: { label: "Submitted", pill: "review" },
    resubmit: { label: "Requires resubmission", pill: "resubmit" },
    for_exam: { label: "For exam", pill: "exam" },
    passed: { label: "Passed", pill: "admitted" },
    failed: { label: "Did not pass", pill: "failed" }
  };
  var STATUS_ORDER = ["draft", "submitted", "resubmit", "for_exam", "passed", "failed"];

  var DOC_STATUS = {
    missing: { label: "Not uploaded", tag: "tag-missing" },
    pending: { label: "For review", tag: "tag-review" },
    verified: { label: "Verified", tag: "tag-verified" },
    resubmit: { label: "Resubmit", tag: "tag-resubmit" }
  };

  // Freshmen documentary requirements. "when" decides who must upload it.
  // Same list and order as the server (AdmissionSetupSeeder::ADMISSION_REQUIREMENTS)
  // and the applicant portal's document cards.
  var DOC_TYPES = [
    { key: "applicantId", title: "Applicant's government-issued or school ID", when: "all", icon: "idcard" },
    { key: "parentId", title: "Parent's government-issued ID", when: "all", icon: "users" },
    { key: "affidavit", title: "Notarized affidavit of guardianship", when: "guardian", icon: "stamp", note: "Applicants with a guardian" },
    { key: "form137", title: "CTC of Form 137 — \"For Evaluation Purposes Only\"", when: "graduate", icon: "file", note: "SHS graduates" },
    { key: "form138", title: "CTC of Grade 11 Form 138", when: "grade12", icon: "stamp", note: "Ongoing Grade 12" },
    { key: "reportCard", title: "Grade 12 Form 138 (latest grading period)", when: "grade12", icon: "file", note: "Ongoing Grade 12" },
    { key: "goodMoral", title: "Certificate of Good Moral Character", when: "all", icon: "shield" },
    { key: "psa", title: "PSA Birth Certificate", when: "all", icon: "scroll" },
    { key: "photo", title: "2 passport-size photos, white background with nameplate", when: "all", icon: "camera" }
  ];
  var PHYSICAL_ITEMS = ["Long brown envelope with plastic envelope"];

  // Medical requirements: the last step, only for applicants who passed and
  // have their result released. Placeholder list: replace with the clinic's.
  var MED_TYPES = [
    { key: "medCert", title: "Medical certificate", note: "Signed by a licensed physician", icon: "clipboard" },
    { key: "xray", title: "Chest X-ray result (PA view)", note: "With the radiologist's reading", icon: "file" },
    { key: "labs", title: "Laboratory results (CBC and urinalysis)", note: "Official laboratory copy", icon: "file" }
  ];
  var MED_STATUS = {
    none: { label: "Not submitted", tag: "tag-missing" },
    pending: { label: "For checking", tag: "tag-review" },
    resubmit: { label: "Resubmit", tag: "tag-resubmit" },
    cleared: { label: "Approved", tag: "tag-verified" }   // the clinic approved every medical file
  };
  var MED_REMARKS = [
    "File is blurry or unreadable. Upload a clearer copy.",
    "Result is older than 3 months. Submit a recent one.",
    "Missing the physician's signature and license number.",
    "Wrong file uploaded for this requirement."
  ];
  var EXAM_KIT = ["Water bottle", "Two (2) Mongol No. 2 pencils", "Glue, eraser, sharpener", "Two (2) black ballpens", "Two (2) passport-size photos with name tag", "Valid ID / school ID", "Printed exam permit and application form"];

  var REMARK_PRESETS = [
    "Image is blurry or unreadable. Upload a clearer scan.",
    "Wrong document uploaded.",
    "Document is incomplete. Include every page.",
    "Missing the remark \"For Evaluation Purposes Only\".",
    "Photo needs a white background and a nameplate.",
    "ID is expired. Upload a valid ID."
  ];

  // Strand → suggested programs (shown to applicants as guidance)
  var STRAND_SUGGEST = {
    "STEM": ["BSCS", "BSIT", "BSN", "BSPSY"],
    "ABM": ["BSA", "BSBA", "BSHM"],
    "HUMSS": ["ABCOMM", "BSPSY", "BSED", "BEED"],
    "GAS": ["BSBA", "BEED", "ABCOMM"],
    "TVL – ICT": ["BSIT", "BSIS", "BSCS"],
    "TVL – HE": ["BSHM", "BSBA"],
    "Arts and Design": ["ABCOMM", "BSIT"],
    "Sports": ["BSED", "BEED"]
  };
  var STRANDS = Object.keys(STRAND_SUGGEST);

  var BARANGAYS = {
    1: ["Bagong Ilog", "Bagong Katipunan", "Bambang", "Buting", "Caniogan", "Kalawaan", "Kapasigan", "Kapitolyo", "Malinao", "Oranbo", "Palatiw", "Pineda", "Sagad", "San Antonio", "San Joaquin", "San Jose", "San Nicolas", "Santa Cruz", "Santa Rosa", "Santo Tomas", "Sumilang", "Ugong"],
    2: ["Dela Paz", "Manggahan", "Maybunga", "Pinagbuhatan", "Rosario", "San Miguel", "Santa Lucia", "Santolan"]
  };

  var BATCHES = {
    A: { label: "Batch A", time: "8:00 AM – 12:00 NN" },
    B: { label: "Batch B", time: "1:00 PM – 5:00 PM" }
  };

  /* ==========================================================================
     STATE
     ========================================================================== */
  var state = { version: 2, programs: [], sessions: [], applicants: [], settings: { deadline: AO.toISO(AO.today()), period: "", inactiveDocs: [] }, audit: [] };

  /* Load everything the panel shows. The state object is updated in place,
     so code holding ADM.state keeps working. */
  function load() {
    return AO.api("GET", API + "/state").done(function (res) {
      var d = res.data;
      $.extend(STAFF, d.staff);
      ["version", "programs", "sessions", "applicants", "settings", "audit"].forEach(function (k) { state[k] = d[k]; });
      invalidate();
    });
  }
  // The server saves every change as it is made
  function save() { }
  // "Reset demo data" reloads what the server has
  function reset() { return load(); }

  /* Swap in the server's copy of one applicant after an action */
  function replaceApplicant(a, fresh) {
    Object.keys(a).forEach(function (k) { if (!(k in fresh)) delete a[k]; });
    $.extend(a, fresh);
    invalidate();
  }

  function nowISO() { var d = new Date(); return AO.toISO(d) + "T" + String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0"); }

  /* Append to an applicant's history (their audit trail) */
  function logApplicant(a, text) { a.history.push({ at: nowISO(), by: STAFF.name, text: text }); }
  /* Append to the office-wide audit log (not tied to one applicant) */
  function logOffice(text) { state.audit.push({ at: nowISO(), by: STAFF.name, text: text }); }

  /* ==========================================================================
     DERIVED VALUES
     ========================================================================== */
  function requiredDocs(a) {
    var inactive = (state && state.settings && state.settings.inactiveDocs) || [];
    return DOC_TYPES.filter(function (d) {
      if (inactive.indexOf(d.key) !== -1) return false;
      if (d.when === "guardian") return a.hasGuardian;
      if (d.when === "graduate") return a.applicantType === "graduate";
      if (d.when === "grade12") return a.applicantType === "grade12";
      return true;
    });
  }

  function docSummary(a) {
    var req = requiredDocs(a);
    var s = { total: req.length, verified: 0, pending: 0, resubmit: 0, missing: 0 };
    req.forEach(function (d) { var st = (a.docs[d.key] || { status: "missing" }).status; s[st] = (s[st] || 0) + 1; });
    return s;
  }

  function statusOf(a) {
    if (!a.submittedAt) return "draft";
    if (a.result) return a.result.decision;
    if (a.exam) return "for_exam";
    if (docSummary(a).resubmit) return "resubmit";
    return "submitted";
  }

  /* Working days between a date and today (Mon–Fri) */
  function workingDaysSince(iso) {
    var d = AO.parseDate(iso), t = AO.today(), n = 0;
    while (d < t) { d.setDate(d.getDate() + 1); var w = d.getDay(); if (w !== 0 && w !== 6) n++; }
    return n;
  }
  /* Medical clearance: null when it doesn't apply yet (not a released pass).
     "pending" = sent to the clinic nurse; "cleared" = the nurse approved. */
  function medStatus(a) {
    if (!a.result || a.result.decision !== "passed" || !a.result.released) return null;
    var med = a.medical || {};
    var files = med.files || {};
    var st = MED_TYPES.map(function (m) { return files[m.key] ? files[m.key].status : "missing"; });
    if (st.every(function (x) { return x === "verified"; })) return "cleared";
    if (st.indexOf("resubmit") !== -1) return "resubmit";
    if (med.submittedAt) return "pending";
    // Not sent (again) yet: replacing a file the nurse sent back, or still uploading
    return MED_TYPES.some(function (m) { return files[m.key] && files[m.key].reviewedAt; }) ? "resubmit" : "none";
  }
  /* The clinic can approve once every file is in and none is waiting on a resubmission */
  function canApproveMedical(a) {
    var files = (a.medical && a.medical.files) || {};
    return medStatus(a) === "pending" && MED_TYPES.every(function (m) { return files[m.key] && files[m.key].status !== "resubmit"; });
  }

  /* Who approved and when: the last review stamp on the medical files */
  function medApproval(a) {
    if (medStatus(a) !== "cleared") return null;
    return MED_TYPES.reduce(function (m, t) {
      var f = a.medical.files[t.key];
      return f.reviewedAt && (!m.at || f.reviewedAt > m.at) ? { by: f.reviewedBy || "University clinic", at: f.reviewedAt } : m;
    }, { by: "University clinic", at: "" });
  }

  /* Find a requirement (document or medical) and the applicant's file for it */
  function findType(key) { return DOC_TYPES.concat(MED_TYPES).filter(function (d) { return d.key === key; })[0]; }
  function isMedical(key) { return MED_TYPES.some(function (m) { return m.key === key; }); }
  function fileOf(a, key) { return isMedical(key) ? (a.medical && a.medical.files[key]) : a.docs[key]; }
  /* Where to fetch an uploaded file. Medical files are confidential: clinic portal only. */
  function fileUrl(a, key) {
    var id = encodeURIComponent(a.id), k = encodeURIComponent(key);
    return isMedical(key) ? "/api/clinic/applicants/" + id + "/medical/" + k + "/file" : "/api/admin/applicants/" + id + "/documents/" + k + "/file";
  }

  function isOverdue(a) { return statusOf(a) === "submitted" && docSummary(a).pending > 0 && workingDaysSince(a.submittedAt) > REVIEW_SLA_DAYS; }
  function canApprove(a) { var s = docSummary(a); return statusOf(a) === "submitted" && s.verified === s.total; }

  function fullName(a, lastFirst) {
    return lastFirst ? a.lastName + ", " + a.firstName + (a.middleName ? " " + a.middleName.charAt(0) + "." : "") : [a.firstName, a.middleName, a.lastName].filter(Boolean).join(" ");
  }
  function initials(a) { return (a.firstName.charAt(0) + a.lastName.charAt(0)).toUpperCase(); }
  function residency(a) { return a.resident ? "Pasig · District " + a.district : "Non-resident"; }

  /* Lookups by id/code go through an index instead of scanning every applicant
     (a screen with many sessions would otherwise scan the whole list per session).
     Rebuilt lazily after any change: load, a server copy, or an action. */
  var idx = null;
  function invalidate() { idx = null; }
  function index() {
    if (idx) return idx;
    idx = { programs: {}, sessions: {}, applicants: {}, rosters: {} };
    state.programs.forEach(function (p) { idx.programs[p.code] = p; });
    state.sessions.forEach(function (s) { idx.sessions[s.id] = s; });
    state.applicants.forEach(function (a) {
      idx.applicants[a.id] = a;
      if (a.exam) (idx.rosters[a.exam.sessionId] = idx.rosters[a.exam.sessionId] || []).push(a);
    });
    return idx;
  }

  function program(code) { return index().programs[code] || { code: code, name: code, slots: 0 }; }
  function session(id) { return index().sessions[id]; }
  function sessionLabel(s) { return BATCHES[s.batch].label + " · " + AO.fmt(s.date, { weekday: "short", month: "short", day: "numeric" }); }
  function sessionRoster(id) { return (index().rosters[id] || []).slice(); }
  function sessionPast(s) { return AO.parseDate(s.date) <= AO.today(); }
  function applicant(id) { return index().applicants[id]; }

  function sortedSessions() {
    return state.sessions.slice().sort(function (x, y) { return x.date === y.date ? (x.batch < y.batch ? -1 : 1) : (x.date < y.date ? -1 : 1); });
  }

  function countBy(fn) {
    var out = {};
    state.applicants.forEach(function (a) { var k = fn(a); out[k] = (out[k] || 0) + 1; });
    return out;
  }

  /* Office-wide audit: applicant histories + office entries, newest first */
  function auditTrail() {
    var rows = state.audit.map(function (e) { return $.extend({ target: "Admission office" }, e); });
    state.applicants.forEach(function (a) {
      a.history.forEach(function (h) { rows.push($.extend({ target: fullName(a) + " · " + a.id, applicantId: a.id }, h)); });
    });
    return rows.sort(function (x, y) { return x.at < y.at ? 1 : x.at > y.at ? -1 : 0; });
  }

  /* ---------- CSV export ---------- */
  function downloadCSV(name, header, rows) {
    var cell = function (v) { v = String(v == null ? "" : v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
    var csv = [header].concat(rows).map(function (r) { return r.map(cell).join(","); }).join("\r\n");
    var blob = new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8" });
    var url = URL.createObjectURL(blob);
    $("<a>").attr({ href: url, download: name }).appendTo("body")[0].click();
    setTimeout(function () { URL.revokeObjectURL(url); $("a[download='" + name + "']").remove(); }, 500);
  }

  window.ADM = {
    ROLE: ROLE, API: API, STAFF: STAFF, REVIEW_SLA_DAYS: REVIEW_SLA_DAYS, PASSING_SCORE: PASSING_SCORE, PAGE_SIZE: PAGE_SIZE,
    STATUSES: STATUSES, STATUS_ORDER: STATUS_ORDER, DOC_STATUS: DOC_STATUS, DOC_TYPES: DOC_TYPES,
    PHYSICAL_ITEMS: PHYSICAL_ITEMS, EXAM_KIT: EXAM_KIT, REMARK_PRESETS: REMARK_PRESETS,
    MED_TYPES: MED_TYPES, MED_STATUS: MED_STATUS, MED_REMARKS: MED_REMARKS, medStatus: medStatus, canApproveMedical: canApproveMedical, medApproval: medApproval,
    findType: findType, isMedical: isMedical, fileOf: fileOf, fileUrl: fileUrl,
    STRAND_SUGGEST: STRAND_SUGGEST, STRANDS: STRANDS, BARANGAYS: BARANGAYS, BATCHES: BATCHES,
    state: state, load: load, invalidate: invalidate, save: save, reset: reset, replaceApplicant: replaceApplicant, nowISO: nowISO, logApplicant: logApplicant, logOffice: logOffice,
    requiredDocs: requiredDocs, docSummary: docSummary, statusOf: statusOf, workingDaysSince: workingDaysSince,
    isOverdue: isOverdue, canApprove: canApprove, fullName: fullName, initials: initials, residency: residency,
    program: program, session: session, sessionLabel: sessionLabel, sessionRoster: sessionRoster, sessionPast: sessionPast,
    sortedSessions: sortedSessions, applicant: applicant, countBy: countBy, auditTrail: auditTrail, downloadCSV: downloadCSV,
    views: {}
  };
})(jQuery);
