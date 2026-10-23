/* ==========================================================================
   PLP Online Admission — Admission Office: UI helpers shared by the views
   Pills, tags, KPI tiles, bar charts, pager, empty states, the reusable
   modals (remark, approve, result, confirm, preview) and printing.
   Needs core.js, admin-store.js, admin-actions.js. Adds ADM.ui.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var icon = AO.icon, esc = AO.esc;

  /* ---------- Small pieces ---------- */
  function pill(status, extra) {
    var s = ADM.STATUSES[status];
    return '<span class="status-pill ' + s.pill + '">' + s.label + (extra ? " · " + esc(extra) : "") + "</span>";
  }
  function statusPill(a) {
    var st = ADM.statusOf(a);
    var extra = (st === "passed" || st === "failed") && !a.result.released ? "not released" : "";
    return pill(st, extra);
  }
  function docTag(status) {
    var d = ADM.DOC_STATUS[status];
    return '<span class="tag ' + d.tag + '">' + (status === "verified" ? icon("check") : "") + d.label + "</span>";
  }
  function avatar(a, cls) { return '<span class="avatar ' + (cls || "") + '">' + esc(ADM.initials(a)) + "</span>"; }

  function dt(iso) {
    var d = new Date(iso.length > 10 ? iso.replace(" ", "T") : iso + "T00:00");
    return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) + " · " + d.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" });
  }
  function relDays(iso) {
    var n = -AO.daysUntil(iso.slice(0, 10));
    return n <= 0 ? "Today" : n === 1 ? "Yesterday" : n + " days ago";
  }
  function pct(n, d) { return d ? Math.round(n / d * 100) : 0; }

  function applicantCell(a) {
    return '<div class="who">' + avatar(a, "avatar-sm") + '<span><strong>' + esc(ADM.fullName(a, true)) + "</strong><small>" + esc(a.id) + "</small></span></div>";
  }

  function kpi(o) {
    var tag = o.href ? "a" : "div";
    return "<" + tag + ' class="kpi' + (o.tone ? " kpi-" + o.tone : "") + '"' + (o.href ? ' href="' + o.href + '"' : "") + (o.status ? ' data-status-link="' + o.status + '"' : "") + ">" +
      '<span class="kpi-icon">' + icon(o.icon) + "</span>" +
      '<span class="kpi-label">' + esc(o.label) + "</span>" +
      '<strong class="kpi-value">' + esc(o.value) + "</strong>" +
      (o.sub ? '<span class="kpi-sub">' + esc(o.sub) + "</span>" : "") + "</" + tag + ">";
  }

  function empty(ic, title, text) {
    return '<div class="empty-state"><span class="empty-icon">' + icon(ic) + "</span><h3>" + esc(title) + "</h3>" + (text ? "<p>" + esc(text) + "</p>" : "") + "</div>";
  }

  /* ---------- One applicant's required documents, with review actions ----------
     Used by the applicant record and the grouped Document review queue.
     Buttons carry data-doc-* (document key) and data-id (application ID). */
  function docItems(a, editable) {
    return ADM.requiredDocs(a).map(function (d) {
      var doc = a.docs[d.key] || { status: "missing" };
      var has = doc.status !== "missing";
      var id = ' data-id="' + esc(a.id) + '"';
      return '<li class="review-item status-' + doc.status + '"><span class="doc-icon tone-3">' + icon(d.icon) + "</span>" +
        '<div class="review-main"><div class="doc-title-row"><h3>' + esc(d.title) + "</h3>" + docTag(doc.status) + (d.note ? '<span class="cond-note">' + esc(d.note) + "</span>" : "") + "</div>" +
        (has ? '<p class="review-file">' + esc(doc.file) + " · " + AO.fileSize(doc.size) + " · uploaded " + relDays(doc.uploadedAt).toLowerCase() + "</p>" : '<p class="review-file">Not uploaded yet</p>') +
        (doc.remark ? '<p class="review-remark">' + icon("flag") + esc(doc.remark) + "</p>" : "") +
        (doc.reviewedBy ? '<p class="review-by">' + esc(doc.status === "verified" ? "Verified" : "Reviewed") + " by " + esc(doc.reviewedBy) + " · " + esc(dt(doc.reviewedAt)) + "</p>" : "") + "</div>" +
        '<div class="review-actions">' +
        (has ? '<button class="btn btn-ghost btn-sm" type="button" data-doc-view="' + d.key + '"' + id + ">" + icon("eye") + "View</button>" : "") +
        (editable && has && doc.status !== "verified" ? '<button class="btn btn-ok btn-sm" type="button" data-doc-verify="' + d.key + '"' + id + ">" + icon("check") + "Verify</button>" : "") +
        (editable && has && doc.status !== "resubmit" ? '<button class="btn btn-warn btn-sm" type="button" data-doc-resubmit="' + d.key + '"' + id + ">" + icon("flag") + "Resubmit</button>" : "") +
        "</div></li>";
    }).join("");
  }

  /* ---------- Charts (single series, one hue, direct labels) ---------- */
  // rows: [{ label, sub, value, display, title }]; max defaults to the largest value
  function hbars(rows, max) {
    max = max || Math.max.apply(null, rows.map(function (r) { return r.value; }).concat([1]));
    return '<ul class="hbars">' + rows.map(function (r) {
      return '<li title="' + esc(r.title || (r.label + ": " + (r.display != null ? r.display : r.value))) + '">' +
        '<span class="hb-label">' + esc(r.label) + (r.sub ? "<small>" + esc(r.sub) + "</small>" : "") + "</span>" +
        '<span class="hb-track"><span class="hb-fill" style="--w:' + (r.value / max * 100).toFixed(1) + '%"></span></span>' +
        '<span class="hb-value">' + esc(r.display != null ? r.display : r.value) + "</span></li>";
    }).join("") + "</ul>";
  }

  // rows: [{ label, value, title, tick }] — vertical columns for a time series
  function columns(rows, label) {
    var max = Math.max.apply(null, rows.map(function (r) { return r.value; }).concat([1]));
    var peak = rows.reduce(function (m, r, i) { return r.value > rows[m].value ? i : m; }, 0);
    return '<div class="cols" role="img" aria-label="' + esc(label) + '">' +
      '<div class="cols-plot">' + rows.map(function (r, i) {
        return '<span class="cc" style="--h:' + (r.value / max * 100).toFixed(1) + '%" data-bs-toggle="tooltip" data-bs-title="' + esc(r.title) + '">' +
          (i === peak || i === rows.length - 1 ? '<em class="cc-val">' + r.value + "</em>" : "") +
          '<span class="cc-bar"></span></span>';
      }).join("") + "</div>" +
      '<div class="cols-axis">' + rows.map(function (r) { return "<span>" + (r.tick ? esc(r.label) : "") + "</span>"; }).join("") + "</div></div>";
  }

  /* ---------- Pager ---------- */
  function pager(total, page, size) {
    size = size || ADM.PAGE_SIZE;
    var pages = Math.max(1, Math.ceil(total / size));
    var from = total ? (page - 1) * size + 1 : 0;
    var to = Math.min(total, page * size);
    return '<div class="pager"><span>' + from + "–" + to + " of " + total + "</span>" +
      '<div class="pager-btns"><button class="icon-button" type="button" data-page="' + (page - 1) + '"' + (page <= 1 ? " disabled" : "") + ' aria-label="Previous page">' + icon("arrowL") + "</button>" +
      "<span>Page " + page + " of " + pages + "</span>" +
      '<button class="icon-button" type="button" data-page="' + (page + 1) + '"' + (page >= pages ? " disabled" : "") + ' aria-label="Next page">' + icon("arrowR") + "</button></div></div>";
  }
  function clampPage(page, total, size) { return Math.min(Math.max(1, page), Math.max(1, Math.ceil(total / (size || ADM.PAGE_SIZE)))); }

  /* ==========================================================================
     MODALS
     ========================================================================== */
  function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
  /* Show a modal, waiting for any other one to finish closing first */
  function open(id) {
    var $other = $(".modal:visible").not("#" + id);
    if ($other.length) $other.one("hidden.bs.modal", function () { modal(id).show(); });
    else modal(id).show();
  }

  /* ---------- Confirm ---------- */
  var confirmCb = null;
  function confirm(o, cb) {
    confirmCb = cb;
    $("#confirmModalTitle").text(o.title);
    $("#confirm-text").text(o.text);
    $("#confirm-ok .btn-label").text(o.ok || "Confirm");
    $("#confirm-icon").html(icon(o.icon || "send"));
    open("confirmModal");
  }
  $("#confirm-ok").on("click", function () {
    var $btn = $(this);
    AO.withLoading($btn, 500, function () { modal("confirmModal").hide(); if (confirmCb) confirmCb(); });
  });

  /* ---------- Requires resubmission remark ---------- */
  var remarkCb = null;
  function askRemark(a, key, cb) {
    remarkCb = cb;
    var d = ADM.findType(key);
    var f = ADM.fileOf(a, key);
    $("#remark-doc").html("<strong>" + esc(d.title) + "</strong> · " + esc(ADM.fullName(a)) + " (" + esc(a.id) + ")");
    $("#remark-text").val((f && f.remark) || "");
    AO.clearError($("#remark-text"));
    $("#remark-presets").html((ADM.isMedical(key) ? ADM.MED_REMARKS : ADM.REMARK_PRESETS).map(function (r) { return '<button type="button" class="chip-btn">' + esc(r) + "</button>"; }).join(""));
    open("remarkModal");
  }
  $(document).on("click", "#remark-presets .chip-btn", function () { $("#remark-text").val($(this).text()).trigger("input").trigger("focus"); });
  $("#remark-form").on("submit", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    modal("remarkModal").hide();
    if (remarkCb) remarkCb($("#remark-text").val().trim());
  });

  /* ---------- Approve / reschedule: choose a session ---------- */
  var approveCb = null;
  function askSession(a, mode, cb) {
    approveCb = cb;
    var current = a.exam && a.exam.sessionId;
    var options = ADM.sortedSessions().filter(function (s) { return !ADM.sessionPast(s) && s.id !== current; });
    $("#approveModalTitle").text(mode === "reschedule" ? "Reschedule the entrance exam" : "Approve for the entrance exam");
    $("#approve-form .btn-brand .btn-label").text(mode === "reschedule" ? "Move to this session" : "Approve and schedule");
    $("#approve-who").html("<strong>" + esc(ADM.fullName(a)) + "</strong> · " + esc(a.id) + " · 1st choice " + esc(a.programs[0]));
    $("#approve-error").text("").removeClass("d-block");
    var firstOpen = true;
    $("#approve-sessions").html(options.length ? options.map(function (s) {
      var used = ADM.sessionRoster(s.id).length, full = used >= s.capacity;
      var checked = !full && firstOpen; if (checked) firstOpen = false;
      return '<label class="session-option' + (full ? " full" : "") + '"><input type="radio" name="approve-session" value="' + s.id + '"' + (full ? " disabled" : "") + (checked ? " checked" : "") + ">" +
        '<span class="so-date"><small>' + AO.fmt(s.date, { month: "short" }) + "</small><strong>" + AO.parseDate(s.date).getDate() + "</strong></span>" +
        '<span class="so-main"><strong>' + esc(ADM.BATCHES[s.batch].label) + " · " + esc(ADM.BATCHES[s.batch].time) + "</strong><small>" + esc(s.venue) + "</small></span>" +
        '<span class="so-seats">' + (full ? "Full" : (s.capacity - used) + " seats left") + "</span></label>";
    }).join("") : '<p class="text-muted mb-0">No upcoming sessions. Create one under Entrance exams first.</p>');
    open("approveModal");
  }
  $("#approve-form").on("submit", function (e) {
    e.preventDefault();
    var id = $("input[name=approve-session]:checked").val();
    if (!id) { $("#approve-error").text("Choose a session with free seats.").addClass("d-block"); return; }
    modal("approveModal").hide();
    if (approveCb) approveCb(id);
  });

  /* ---------- Record result ---------- */
  var resultCb = null;
  function askResult(a, cb) {
    resultCb = cb;
    $("#result-who").html("<strong>" + esc(ADM.fullName(a)) + "</strong> · " + esc(a.id) + " · permit " + esc(a.exam.permit));
    $("#result-score").val(a.result ? a.result.score : "");
    AO.clearError($("#result-score"));
    $("input[name=decision]").prop("checked", false);
    if (a.result) $("#decision-" + a.result.decision).prop("checked", true);
    updateResultHint();
    open("resultModal");
  }
  function updateResultHint() {
    var v = $("#result-score").val();
    if (v === "" || isNaN(+v)) { $("#result-hint").text("Passing score: " + ADM.PASSING_SCORE + "."); return; }
    var suggested = +v >= ADM.PASSING_SCORE ? "passed" : "failed";
    $("#result-hint").text("Passing score is " + ADM.PASSING_SCORE + ", so this suggests " + (suggested === "passed" ? "Passed" : "Did not pass") + ".");
    if (!$("input[name=decision]:checked").length || $("#result-form").data("auto")) {
      $("#decision-" + suggested).prop("checked", true);
      $("#result-form").data("auto", true);
    }
  }
  $("#result-score").on("input", updateResultHint);
  $("input[name=decision]").on("change", function () { $("#result-form").data("auto", false); });
  $("#resultModal").on("hidden.bs.modal", function () { $("#result-form").data("auto", false); });
  $("#result-form").on("submit", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    var decision = $("input[name=decision]:checked").val();
    if (!decision) { $("#result-hint").text("Choose Passed or Did not pass."); return; }
    modal("resultModal").hide();
    if (resultCb) resultCb(+$("#result-score").val(), decision);
  });

  /* ---------- Document preview ---------- */
  var previewUrl = null;
  // The file comes from GET /api/admin/applicants/:id/documents/:key/file with the
  // bearer token, then shows in an <img> or <iframe>. Medical files: clinic only.
  function loadPreview(a, key) {
    if (previewUrl) { URL.revokeObjectURL(previewUrl); previewUrl = null; }
    var $slot = $("#doc-preview [data-doc-file]");
    fetch(ADM.fileUrl(a, key), {
      headers: { Authorization: "Bearer " + AO.getToken() }
    }).then(function (r) {
      if (!r.ok) throw r;
      return r.blob();
    }).then(function (blob) {
      previewUrl = URL.createObjectURL(blob);
      $slot.replaceWith(blob.type.indexOf("image/") === 0
        ? '<img src="' + previewUrl + '" alt="" style="max-width:100%;max-height:60vh;display:block;margin:12px auto 0">'
        : '<iframe src="' + previewUrl + '" title="Document" style="width:100%;height:60vh;border:0;margin-top:12px"></iframe>');
    }).catch(function (r) {
      $slot.text(r && r.status === 403 ? "You don't have access to this file." : "The file couldn't be loaded.");
    });
  }

  function previewDoc(a, key, actionsHTML) {
    var d = ADM.findType(key);
    var doc = ADM.fileOf(a, key);
    $("#docModalTitle").text(d.title);
    $("#doc-preview").html(
      '<div class="doc-sheet"><span class="doc-sheet-icon">' + icon(d.icon) + "</span>" +
      "<strong>" + esc(doc.file) + "</strong><span>" + AO.fileSize(doc.size) + " · uploaded " + esc(dt(doc.uploadedAt)) + "</span>" +
      "<p data-doc-file>Loading the file…</p></div>" +
      '<dl class="kv kv-compact mt-3"><div><dt>Applicant</dt><dd>' + esc(ADM.fullName(a)) + " · " + esc(a.id) + "</dd></div>" +
      "<div><dt>Status</dt><dd>" + docTag(doc.status) + "</dd></div>" +
      (doc.remark ? "<div><dt>Remark</dt><dd>" + esc(doc.remark) + "</dd></div>" : "") +
      (doc.reviewedBy ? "<div><dt>Reviewed</dt><dd>" + esc(doc.reviewedBy) + " · " + esc(dt(doc.reviewedAt)) + "</dd></div>" : "") + "</dl>");
    $("#doc-preview-actions").html(actionsHTML || '<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>').prop("hidden", false);
    open("docModal");
    loadPreview(a, key);
  }

  /* ==========================================================================
     PRINTING
     ========================================================================== */
  function printHTML(html) {
    $("#print-area").html(html);
    window.print();
  }
  $(window).on("afterprint", function () { $("#print-area").empty(); });

  function printHead(title, sub) {
    return '<header class="print-head"><div><strong>Pamantasan ng Lungsod ng Pasig</strong><span>Admission Office · ' + esc(ADM.state.settings.period) + "</span></div>" +
      "<div><h1>" + esc(title) + "</h1>" + (sub ? "<span>" + esc(sub) + "</span>" : "") + "</div></header>";
  }

  function printApplicationForm(a) {
    var row = function (k, v) { return "<tr><th>" + esc(k) + "</th><td>" + esc(v || "—") + "</td></tr>"; };
    printHTML(printHead("Application form", a.id) +
      '<table class="print-table kv-table">' +
      row("LRN", a.lrn) + row("Name", a.lastName + ", " + a.firstName + " " + (a.middleName || "")) + row("Sex", a.sex) +
      row("Birthday", AO.fmt(a.birthday, { month: "long", day: "numeric", year: "numeric" })) + row("Contact number", a.phone) + row("Email", a.email) +
      row("Residency", a.resident ? "Pasig resident · District " + a.district + " · Brgy. " + a.barangay : "Non-resident") + row("Address", a.address) +
      row("Type of school", a.schoolType) + row("Last school attended", a.lastSchool) + row("Strand", a.strand) +
      row("Applicant type", a.applicantType === "graduate" ? "SHS graduate" : "Ongoing Grade 12") +
      row("1st choice", ADM.program(a.programs[0]).name) + row("2nd choice", ADM.program(a.programs[1]).name) + row("3rd choice", ADM.program(a.programs[2]).name) +
      (a.exam ? row("Exam permit", a.exam.permit + " · " + ADM.sessionLabel(ADM.session(a.exam.sessionId))) : "") +
      "</table>" + '<p class="print-foot">Printed ' + esc(dt(ADM.nowISO())) + " by " + esc(ADM.STAFF.name) + "</p>");
  }

  function printRoster(s) {
    var list = ADM.sessionRoster(s.id).sort(function (x, y) { return x.lastName.localeCompare(y.lastName); });
    printHTML(printHead("Exam roster · " + ADM.sessionLabel(s), ADM.BATCHES[s.batch].time + " · " + s.venue) +
      '<table class="print-table"><thead><tr><th>#</th><th>Applicant</th><th>ID</th><th>Permit</th><th>1st choice</th><th>Permit ✓</th><th>Form ✓</th><th>Signature</th></tr></thead><tbody>' +
      list.map(function (a, i) {
        return "<tr><td>" + (i + 1) + "</td><td>" + esc(ADM.fullName(a, true)) + "</td><td>" + esc(a.id) + "</td><td>" + esc(a.exam.permit) + "</td><td>" + esc(a.programs[0]) + "</td><td></td><td></td><td></td></tr>";
      }).join("") + "</tbody></table>" +
      '<p class="print-foot">' + list.length + " of " + s.capacity + " seats · Bring: " + esc(ADM.EXAM_KIT.join(", ")) + "</p>");
  }

  /* ---------- Sidebar counts: only work waiting on the office ---------- */
  function refreshCounts() {
    var pendingDocs = 0, toRecord = 0, unreleased = 0;
    ADM.state.applicants.forEach(function (a) {
      if (a.submittedAt && !a.exam && ADM.docSummary(a).pending) pendingDocs++;   // applicants with documents waiting
      if (a.exam && a.exam.attendance === "present" && !a.result) toRecord++;
      if (a.result && !a.result.released) unreleased++;
    });
    $("#nav-count-documents").text(pendingDocs).toggleClass("full", pendingDocs === 0);
    $("#nav-count-results").text(toRecord + unreleased).toggleClass("full", toRecord + unreleased === 0);
  }

  ADM.ui = {
    pill: pill, statusPill: statusPill, docTag: docTag, avatar: avatar, dt: dt, relDays: relDays, pct: pct,
    applicantCell: applicantCell, docItems: docItems, kpi: kpi, empty: empty, hbars: hbars, columns: columns,
    pager: pager, clampPage: clampPage, modal: modal, confirm: confirm,
    askRemark: askRemark, askSession: askSession, askResult: askResult, previewDoc: previewDoc,
    printHTML: printHTML, printApplicationForm: printApplicationForm, printRoster: printRoster, refreshCounts: refreshCounts
  };
})(jQuery);
