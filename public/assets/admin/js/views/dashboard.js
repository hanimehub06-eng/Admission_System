/* ==========================================================================
   View: Dashboard — a welcome banner, what needs the office today (one card
   per task), and the admission pipeline at a glance. Detailed numbers and
   charts live under More → Reports.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  // Each staff member's own card order (jQuery UI Sortable), kept in this browser
  function orderKey() { return "admitone.dashOrder." + ADM.STAFF.email; }

  function render($el) {
    var apps = ADM.state.applicants;
    var toReview = apps.filter(function (a) { return a.submittedAt && !a.exam && ADM.docSummary(a).pending; });
    var overdue = toReview.filter(function (a) { return ADM.workingDaysSince(a.submittedAt) > ADM.REVIEW_SLA_DAYS; }).length;
    var ready = apps.filter(ADM.canApprove);
    var toRecord = apps.filter(function (a) { return a.exam && a.exam.attendance === "present" && !a.result; });
    var toRelease = apps.filter(function (a) { return a.result && !a.result.released; });
    var today = ADM.sortedSessions().filter(function (s) { return s.date === AO.toISO(AO.today()); });
    var h = new Date().getHours();
    var left = AO.daysUntil(ADM.state.settings.deadline);

    // { key, n, label, detail, href, icon, tone, attrs }; only tasks with something to do are shown
    var tasks = [
      { key: "review", n: toReview.length, label: "Documents to review", detail: overdue ? overdue + " past the " + ADM.REVIEW_SLA_DAYS + "-day target" : "Oldest first", href: "#/documents", icon: "folder", tone: overdue ? "warn" : "green" },
      { key: "ready", n: ready.length, label: "Ready to schedule", detail: "All documents verified", href: "#/applicants", icon: "calendar", tone: "blue", attrs: ' data-status-link="ready"' },
      { key: "record", n: toRecord.length, label: "Results to record", detail: "Took the exam", href: "#/results", icon: "pencil", tone: "gold" },
      { key: "release", n: toRelease.length, label: "Results to release", detail: "Recorded, not yet released", href: "#/results", icon: "send", tone: "gold" }
    ].filter(function (t) { return t.n > 0; });
    today.forEach(function (s) {
      tasks.unshift({ key: "exam-" + s.id, n: ADM.sessionRoster(s.id).length, label: "Exam today · " + ADM.BATCHES[s.batch].label, detail: s.venue, href: "#/exams/" + s.id, icon: "ticket", tone: "blue" });
    });
    // The saved order first; cards it doesn't know yet (today's exams) stay on top
    var saved = AO.readJSON("local", orderKey()) || [];
    tasks = tasks.map(function (t, i) { return { t: t, i: i, r: saved.indexOf(t.key) }; })
      .sort(function (x, y) { return x.r - y.r || x.i - y.i; }).map(function (x) { return x.t; });

    // Where applicants are in the admission process
    var stages = [
      { label: "Applied", icon: "inbox", n: apps.filter(function (a) { return a.submittedAt; }).length },
      { label: "Documents verified", icon: "folder", n: apps.filter(function (a) { return a.exam || ADM.canApprove(a); }).length },
      { label: "Took the exam", icon: "pencil", n: apps.filter(function (a) { return a.result || (a.exam && a.exam.attendance === "present"); }).length },
      { label: "Passed", icon: "award", n: apps.filter(function (a) { return a.result && a.result.decision === "passed"; }).length },
      { label: "Admitted", icon: "shield", n: apps.filter(function (a) { return ADM.medStatus(a) === "cleared"; }).length }
    ];
    var top = Math.max(stages[0].n, 1);

    $el.html(
      '<section class="dash-hero" data-reveal>' +
      '<div class="dash-hero-text"><p class="dash-date">' + esc(new Date().toLocaleDateString("en-US", { weekday: "long", month: "long", day: "numeric", year: "numeric" })) + "</p>" +
      "<h1>" + (h < 12 ? "Good morning" : h < 18 ? "Good afternoon" : "Good evening") + ", " + esc(ADM.STAFF.name.split(" ")[0]) + "</h1>" +
      "<p>" + (tasks.length ? tasks.length + " thing" + (tasks.length === 1 ? "" : "s") + " need" + (tasks.length === 1 ? "s" : "") + " your attention today." : "You're all caught up. Nothing is waiting on the office.") + "</p></div>" +
      '<div class="dash-deadline">' + icon("clock") + "<span>Application deadline<strong>" + AO.fmt(ADM.state.settings.deadline, { month: "long", day: "numeric" }) + "</strong>" +
      "<em>" + (left > 0 ? left + " days left" : left === 0 ? "Closes today" : "Closed") + "</em></span></div></section>" +

      '<div class="dash-section-head" data-reveal><h2>Needs you today</h2>' +
      (tasks.length > 1 ? '<span class="dash-hint">' + icon("grip") + "Drag the cards to reorder" + (saved.length ? ' · <button class="text-link" type="button" id="dash-order-reset">Reset</button>' : "") + "</span>" : "") + "</div>" +
      (tasks.length ? '<div class="task-grid" id="task-grid" data-reveal>' + tasks.map(function (t) {
        return '<a class="task-card tone-' + t.tone + '" href="' + t.href + '" data-key="' + esc(t.key) + '"' + (t.attrs || "") + ">" +
          '<span class="task-icon">' + icon(t.icon) + "</span>" +
          '<span class="task-text"><strong>' + esc(t.label) + "</strong><small>" + esc(t.detail) + "</small></span>" +
          '<span class="task-count">' + t.n + "</span>" + icon("arrowR") + "</a>";
      }).join("") + "</div>"
        : '<section class="card-soft" data-reveal>' + ADM.ui.empty("check", "All caught up", "Nothing is waiting on the office right now.") + "</section>") +

      '<div class="dash-section-head" data-reveal><h2>Admission pipeline</h2><a class="text-link" href="#/reports">Reports' + icon("arrowR") + "</a></div>" +
      '<section class="card-soft pipeline" data-reveal><ol>' + stages.map(function (s, i) {
        return '<li><span class="pipe-icon">' + icon(s.icon) + "</span>" +
          '<strong class="pipe-n">' + s.n + "</strong><span class=\"pipe-label\">" + esc(s.label) + "</span>" +
          '<span class="pipe-bar"><span style="--w:' + (s.n / top * 100).toFixed(1) + '%"></span></span>' +
          (i < stages.length - 1 ? '<span class="pipe-arrow" aria-hidden="true">' + icon("arrowR") + "</span>" : "") + "</li>";
      }).join("") + "</ol></section>"
    );
    // A short move (under 8px) still counts as a click on the card. The dragged copy lives on <body>:
    // the grid keeps a transform from its reveal animation, which would offset it from the pointer
    $el.find("#task-grid").sortable({
      items: ".task-card", distance: 8, helper: "clone", appendTo: "body", tolerance: "pointer", cursor: "grabbing", placeholder: "task-card task-placeholder", forcePlaceholderSize: true,
      stop: function () {
        AO.writeJSON("local", orderKey(), $(this).find(".task-card").map(function () { return $(this).data("key"); }).get());
        $el.find(".dash-hint").html(icon("grip") + 'Drag the cards to reorder · <button class="text-link" type="button" id="dash-order-reset">Reset</button>');
      }
    });
    // The cards sit in a CSS grid: compare left/right as well as up/down (Sortable only does that for floated items)
    var $grid = $el.find("#task-grid");
    if ($grid.length) $grid.sortable("instance")._isFloating = function () { return true; };
  }
  $(document).on("click", "#dash-order-reset", function () { AO.removeKey("local", orderKey()); ADM.refresh(); });

  $(document).on("click", "[data-status-link]", function () { ADM.views.applicants.filterStatus($(this).data("status-link")); });

  ADM.views.dashboard = { title: "Dashboard", render: render };
})(jQuery);
