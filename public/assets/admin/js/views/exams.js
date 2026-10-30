/* ==========================================================================
   Views: Entrance exams (#/exams) and session roster (#/exams/:sessionId)
   Batch A sits in the morning, Batch B in the afternoon. On exam day the
   roster is used to check each examinee's printed permit and application
   form, then mark attendance.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  var sessionId = null;

  function when(s) {
    var d = AO.daysUntil(s.date);
    return d === 0 ? "today" : d > 0 ? "upcoming" : "done";
  }

  function sessionCard(s) {
    var roster = ADM.sessionRoster(s.id);
    var used = roster.length;
    var present = roster.filter(function (a) { return a.exam.attendance === "present"; }).length;
    var absent = roster.filter(function (a) { return a.exam.attendance === "absent"; }).length;
    var w = when(s);
    var foot = w === "upcoming" ? (s.capacity - used) + " seats left" : present + " present · " + absent + " absent" + (used - present - absent ? " · " + (used - present - absent) + " unmarked" : "");
    return '<a class="session-card is-' + w + '" href="#/exams/' + s.id + '">' +
      '<span class="ticket-date"><span>' + AO.fmt(s.date, { month: "short" }) + "</span><strong>" + AO.parseDate(s.date).getDate() + "</strong></span>" +
      '<span class="sc-main"><span class="sc-top"><strong>' + esc(ADM.BATCHES[s.batch].label) + "</strong>" + (w === "today" ? '<span class="tag tag-review">Today</span>' : "") + "</span>" +
      "<small>" + AO.fmt(s.date, { weekday: "long" }) + " · " + esc(ADM.BATCHES[s.batch].time) + "</small><small>" + icon("pin") + esc(s.venue) + "</small>" +
      '<span class="seat-bar" title="' + used + " of " + s.capacity + ' seats"><span style="--w:' + ADM.ui.pct(used, s.capacity) + '%"></span></span>' +
      '<span class="sc-foot"><b>' + used + "/" + s.capacity + "</b> seats · " + esc(foot) + "</span></span></a>";
  }

  /* ==========================================================================
     SESSIONS
     ========================================================================== */
  function renderSessions($el) {
    var all = ADM.sortedSessions();
    var groups = [
      ["today", "Today", all.filter(function (s) { return when(s) === "today"; })],
      ["upcoming", "Upcoming", all.filter(function (s) { return when(s) === "upcoming"; })],
      ["done", "Completed", all.filter(function (s) { return when(s) === "done"; }).reverse()]
    ];
    $el.html(
      '<div class="page-title page-title-inline" data-reveal><div><h1>Entrance exams</h1>' +
      '</div>' +
      '<button class="btn btn-brand" type="button" id="new-session">' + icon("plus") + "New session</button></div>" +
      '<div class="row g-3" data-reveal>' +
      '<div class="col-lg-8"><section class="card-soft h-100"><h3 class="card-title">Batches</h3><div class="batch-row">' +
      Object.keys(ADM.BATCHES).map(function (k) {
        return '<div class="batch"><span class="batch-letter">' + k + "</span><div><strong>" + esc(ADM.BATCHES[k].label) + "</strong><small>" + esc(ADM.BATCHES[k].time) + "</small></div></div>";
      }).join("") + "</div>" +
      '<p class="text-muted small mt-3 mb-0">Examinees without their printed permit and application form may not be admitted to the exam room.</p></section></div>' +
      '<div class="col-lg-4"><section class="card-soft h-100"><h3 class="card-title">Examinees bring</h3><ul class="kit-list">' +
      ADM.EXAM_KIT.map(function (k) { return "<li>" + icon("check") + esc(k) + "</li>"; }).join("") + "</ul></section></div></div>" +
      groups.map(function (g) {
        if (!g[2].length) return "";
        return '<section data-reveal><h2 class="group-title">' + g[1] + "<span>" + g[2].length + "</span></h2>" +
          '<div class="session-grid">' + g[2].map(sessionCard).join("") + "</div></section>";
      }).join("")
    );
  }

  $(document).on("click", "#new-session", function () {
    var $form = $("#session-form");
    $form.find(".is-invalid").each(function () { AO.clearError($(this)); });
    var next = new Date(AO.today().getTime() + 7 * 86400000);
    $("#session-date").val(AO.toISO(next)).attr("min", AO.toISO(new Date(AO.today().getTime() + 86400000)));
    $("#session-venue").val("");
    $("#session-capacity").val(30);
    ADM.ui.modal("sessionModal").show();
  });
  $("#session-form").on("submit", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    if (AO.daysUntil($("#session-date").val()) < 1) { AO.setError($("#session-date"), "Pick a date after today."); return; }
    var s = ADM.actions.createSession($("#session-date").val(), $("#session-batch").val(), $("#session-venue").val().trim(), +$("#session-capacity").val());
    if (!s) { AO.setError($("#session-venue"), "This batch already exists at this venue on that date."); return; }
    ADM.ui.modal("sessionModal").hide();
    ADM.refresh();
    AO.showToast(ADM.sessionLabel(s) + " created.");
  });

  /* ==========================================================================
     ROSTER
     ========================================================================== */
  function renderRoster($el, id) {
    var ui = ADM.ui;
    var s = ADM.session(id);
    sessionId = id;
    if (!s) { $el.html('<section class="card-soft">' + ui.empty("calendar", "Session not found") + "</section>"); return; }
    var roster = ADM.sessionRoster(id).sort(function (x, y) { return x.lastName.localeCompare(y.lastName); });
    var w = when(s);
    var open = w !== "upcoming";             // checking opens on exam day
    var c = { permit: 0, form: 0, present: 0, absent: 0 };
    roster.forEach(function (a) {
      if (a.exam.permitChecked) c.permit++;
      if (a.exam.formChecked) c.form++;
      if (a.exam.attendance) c[a.exam.attendance]++;
    });
    var unmarked = roster.length - c.present - c.absent;
    // Drag an examinee onto another session (jQuery UI Draggable + Droppable); the handle also opens the session picker on click
    var canMove = ["admission", "admission_head", "super_admin"].indexOf(ADM.STAFF.roleKey) !== -1;
    var targets = canMove ? ADM.sortedSessions().filter(function (t) { return !ADM.sessionPast(t) && t.id !== id; }) : [];
    function movable(a) { return targets.length && !a.result && a.exam.attendance !== "present"; }
    var anyMovable = roster.some(movable);

    $el.html(
      '<a class="back-link" href="#/exams" data-reveal>' + icon("arrowL") + "All sessions</a>" +
      '<section class="record-hero" data-reveal><span class="ticket-date ticket-lg"><span>' + AO.fmt(s.date, { month: "short" }) + "</span><strong>" + AO.parseDate(s.date).getDate() + "</strong></span>" +
      '<div class="record-id"><p class="eyebrow">' + (w === "today" ? "Exam day · today" : w === "upcoming" ? "Upcoming session" : "Completed session") + "</p>" +
      "<h1>" + esc(ADM.BATCHES[s.batch].label) + " · " + AO.fmt(s.date, { weekday: "long", month: "long", day: "numeric" }) + "</h1>" +
      '<p class="record-meta"><span>' + icon("clock") + esc(ADM.BATCHES[s.batch].time) + "</span><span>" + icon("pin") + esc(s.venue) + "</span><span>" + roster.length + " of " + s.capacity + " seats</span>" +
      (open ? "<span>" + c.present + " present · " + c.absent + " absent</span>" : "") + "</p></div>" +
      '<div class="record-actions"><button class="btn btn-ghost" type="button" id="print-roster">' + icon("printer") + "Print roster</button>" +
      (open && unmarked ? '<button class="btn btn-ghost" type="button" id="mark-absent">' + icon("x") + "Mark " + unmarked + " unmarked absent</button>" : "") +
      (open && c.present ? '<a class="btn btn-brand" href="#/results" id="go-results">' + icon("award") + "Record results</a>" : "") + "</div></section>" +

      (!open ? '<div class="info-note" data-reveal>' + icon("lock") + "<p>Permit checks and attendance open on exam day. Print the roster to bring to the venue.</p></div>" : "") +

      (anyMovable ? '<section class="move-strip" data-reveal><p>' + icon("grip") + "Drag an examinee by the handle onto a session to move them there, or click the handle to choose.</p>" +
        '<div class="drop-zones">' + targets.map(function (t) {
          var left = t.capacity - ADM.sessionRoster(t.id).length;
          return '<div class="drop-zone' + (left > 0 ? "" : " full") + '" data-drop-session="' + esc(t.id) + '"><span class="dz-date">' + AO.fmt(t.date, { month: "short", day: "numeric" }) + "</span>" +
            "<strong>" + esc(ADM.BATCHES[t.batch].label) + "</strong><small>" + esc(t.venue) + "</small><small>" + (left > 0 ? left + " seats left" : "Full") + "</small></div>";
        }).join("") + "</div></section>" : "") +

      '<section class="table-card" data-reveal>' +
      // Exam day: select several rows (jQuery UI Selectable) and mark them at once
      (open && roster.some(function (a) { return !a.result; }) ? '<div class="bulk-bar" id="bulk-bar"><span class="bulk-count" id="bulk-count">' + icon("grid") + "Drag across rows, or Ctrl+click, to select several examinees</span>" +
        '<span class="bulk-actions"><button class="btn btn-ghost btn-sm" type="button" data-bulk="checks" disabled>' + icon("check") + "Permit and form checked</button>" +
        '<button class="btn btn-ghost btn-sm" type="button" data-bulk="present" disabled>Present</button>' +
        '<button class="btn btn-ghost btn-sm" type="button" data-bulk="absent" disabled>Absent</button>' +
        '<button class="btn btn-ghost btn-sm" type="button" data-bulk="all">Select all</button>' +
        '<button class="btn btn-ghost btn-sm" type="button" data-bulk="none" hidden>Clear</button></span></div>' : "") +
      (roster.length ? '<div class="table-responsive"><table class="table data-table roster"><thead><tr>' + (anyMovable ? '<th class="drag-col"><span class="visually-hidden">Move</span></th>' : "") + '<th>#</th><th>Examinee</th><th>Permit no.</th><th>1st choice</th><th class="text-center">Permit</th><th class="text-center">Form</th><th>Attendance</th></tr></thead><tbody>' +
        roster.map(function (a, i) {
          var locked = !open || !!a.result;
          var both = a.exam.permitChecked && a.exam.formChecked;
          return "<tr" + (movable(a) ? ' data-movable="' + esc(a.id) + '"' : "") + (!locked ? ' data-select-id="' + esc(a.id) + '"' : "") + ">" +
            (anyMovable ? '<td class="drag-col">' + (movable(a) ? '<button class="drag-handle" type="button" data-move-exam="' + esc(a.id) + '" title="Drag to another session, or click to choose" aria-label="Move ' + esc(ADM.fullName(a)) + ' to another session">' + icon("grip") + "</button>" : "") + "</td>" : "") +
            '<td class="text-muted">' + (i + 1) + "</td>" +
            '<td><a href="#/applicants/' + esc(a.id) + '" class="plain-link">' + ui.applicantCell(a) + "</a></td>" +
            '<td class="mono">' + esc(a.exam.permit) + "</td>" +
            '<td><span class="code-chip">' + esc(a.programs[0]) + "</span></td>" +
            '<td class="text-center"><input class="form-check-input" type="checkbox" data-check="permitChecked" data-id="' + a.id + '" aria-label="Permit checked for ' + esc(ADM.fullName(a)) + '"' + (a.exam.permitChecked ? " checked" : "") + (locked ? " disabled" : "") + "></td>" +
            '<td class="text-center"><input class="form-check-input" type="checkbox" data-check="formChecked" data-id="' + a.id + '" aria-label="Application form checked for ' + esc(ADM.fullName(a)) + '"' + (a.exam.formChecked ? " checked" : "") + (locked ? " disabled" : "") + "></td>" +
            '<td><div class="att-toggle" role="group" aria-label="Attendance for ' + esc(ADM.fullName(a)) + '">' +
            '<button type="button" class="att present' + (a.exam.attendance === "present" ? " on" : "") + '" data-att="present" data-id="' + a.id + '"' + (locked || !both ? " disabled" : "") + (both || locked ? "" : ' title="Check the permit and form first"') + ">" + icon("check") + "Present</button>" +
            '<button type="button" class="att absent' + (a.exam.attendance === "absent" ? " on" : "") + '" data-att="absent" data-id="' + a.id + '"' + (locked ? " disabled" : "") + ">Absent</button></div>" +
            (a.result ? '<small class="d-block text-muted mt-1">Result recorded</small>' : "") + "</td></tr>";
        }).join("") + "</tbody></table></div>"
        : ui.empty("users", "No examinees yet", "Approve applicants for this session from their record.")) +
      "</section>"
    );
    if (anyMovable) enableMoves($el);
    $el.find(".roster tbody").selectable({
      filter: "tr[data-select-id]", cancel: "input, button, a, label",
      stop: syncBulk, unselected: syncBulk
    });
  }

  function picked() { return $("#view-session tr.ui-selected").map(function () { return ADM.applicant($(this).data("select-id")); }).get(); }
  function syncBulk() {
    var n = picked().length;
    $("#bulk-count").html(icon("grid") + (n ? "<strong>" + n + "</strong> selected" : "Drag across rows, or Ctrl+click, to select several examinees"));
    $("#bulk-bar").toggleClass("has-selection", n > 0).find("[data-bulk=checks], [data-bulk=present], [data-bulk=absent]").prop("disabled", !n);
    $("#bulk-bar [data-bulk=none]").prop("hidden", !n);
  }
  $(document).on("click", "#bulk-bar [data-bulk]", function () {
    var kind = $(this).data("bulk"), $rows = $("#view-session tr[data-select-id]");
    if (kind === "all" || kind === "none") { $rows.toggleClass("ui-selected", kind === "all"); syncBulk(); return; }
    var list = picked(), skipped = 0;
    list.forEach(function (a) {
      if (kind === "checks") { ADM.actions.setCheck(a, "permitChecked", true); ADM.actions.setCheck(a, "formChecked", true); }
      else if (!ADM.actions.setAttendance(a, kind)) skipped++;
    });
    ADM.refresh();
    var done = list.length - skipped;
    AO.showToast((kind === "checks" ? done + " marked permit and form checked" : done + " marked " + kind) + (skipped ? ". " + skipped + " skipped: check their permit and form first." : "."));
  });

  // A drop asks first (drops can slip); the session picker already is the confirmation
  function moveExaminee(a, sid, confirmed) {
    var t = ADM.session(sid);
    function go() {
      if (ADM.actions.reschedule(a, sid)) { ADM.refresh(); AO.showToast(ADM.fullName(a) + " moved to " + ADM.sessionLabel(t) + "."); }
      else AO.showToast(ADM.sessionLabel(t) + " is full.");
    }
    if (confirmed) { go(); return; }
    ADM.ui.confirm({ title: "Move " + ADM.fullName(a) + "?", text: "Their exam moves to " + ADM.sessionLabel(t) + ". Permit checks and attendance for this session are cleared.", ok: "Move", icon: "refresh" }, go);
  }
  function enableMoves($el) {
    $el.find("tr[data-movable]").draggable({
      handle: ".drag-handle", cancel: "", appendTo: "body", revert: "invalid", revertDuration: 180, cursor: "grabbing", cursorAt: { left: 18, top: 18 }, zIndex: 2000,
      helper: function () { var a = ADM.applicant($(this).data("movable")); return $('<div class="drag-chip">' + icon("user") + esc(ADM.fullName(a)) + "</div>"); },
      start: function () { $(this).addClass("is-dragging"); },
      stop: function () { $(this).removeClass("is-dragging"); }
    });
    $el.find(".drop-zone:not(.full)").droppable({
      accept: "tr[data-movable]", tolerance: "pointer", classes: { "ui-droppable-active": "is-ready", "ui-droppable-hover": "is-over" },
      drop: function (e, ui) { moveExaminee(ADM.applicant(ui.draggable.data("movable")), $(this).data("drop-session")); }
    });
  }
  $(document).on("click", "#view-session [data-move-exam]", function () {
    var a = ADM.applicant($(this).data("move-exam"));
    ADM.ui.askSession(a, "reschedule", function (sid) { moveExaminee(a, sid, true); });
  });

  $(document).on("change", "#view-session [data-check]", function () {
    var a = ADM.applicant($(this).data("id"));
    ADM.actions.setCheck(a, $(this).data("check"), this.checked);
    if (!this.checked && a.exam.attendance === "present") ADM.actions.setAttendance(a, null);
    ADM.refresh();
  });
  $(document).on("click", "#view-session [data-att]", function () {
    var a = ADM.applicant($(this).data("id"));
    var v = $(this).data("att");
    ADM.actions.setAttendance(a, a.exam.attendance === v ? null : v);
    ADM.refresh();
  });
  $(document).on("click", "#print-roster", function () { ADM.ui.printRoster(ADM.session(sessionId)); });
  $(document).on("click", "#mark-absent", function () {
    var list = ADM.sessionRoster(sessionId).filter(function (a) { return !a.exam.attendance && !a.result; });
    ADM.ui.confirm({ title: "Mark " + list.length + " examinees absent?", text: "Use this once the exam has started. Absent examinees can be rescheduled from their record.", ok: "Mark absent", icon: "x" }, function () {
      list.forEach(function (a) { ADM.actions.setAttendance(a, "absent"); });
      ADM.refresh();
      AO.showToast(list.length + " marked absent.");
    });
  });
  $(document).on("click", "#go-results", function () { ADM.views.results.forSession(sessionId); });

  ADM.views.exams = { title: "Entrance exams", render: renderSessions };
  ADM.views.session = {
    title: function (id) { var s = ADM.session(id); return s ? ADM.sessionLabel(s) : "Exam session"; },
    render: renderRoster
  };
})(jQuery);
