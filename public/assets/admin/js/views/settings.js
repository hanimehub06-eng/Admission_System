/* ==========================================================================
   View: Programs & requirements (#/settings) — keep programs, slots and
   requirements up to date before each admission period.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;

  var CAN = [
    "Review applications and verify documents",
    "Schedule entrance exams and keep rosters",
    "Record and release results through the system",
    "Update programs, slots and requirements",
    "Generate reports",
    "See medical approvals sent by the clinic nurse"
  ];
  var CANNOT = [
    "Check or approve medical files (clinic nurse only)",
    "Create, delete, or change user accounts and roles",
    "Run backups or change security settings",
    "Edit or delete audit log entries"
  ];

  function render($el) {
    var ui = ADM.ui;
    var st = ADM.state;
    var inactive = st.settings.inactiveDocs;
    var firstChoice = ADM.countBy(function (a) { return a.programs[0]; });
    var passed = ADM.countBy(function (a) { return a.result && a.result.decision === "passed" ? a.programs[0] : "-"; });

    $el.html(
      '<div class="page-title" data-reveal><h1>Programs &amp; requirements</h1>' +
      '</div>' +

      '<section class="table-card" data-reveal><div class="table-toolbar"><div><strong>Programs and slots</strong><small class="d-block text-muted">' + esc(st.settings.period) + "</small></div>" +
      '<button class="btn btn-ghost ms-auto" type="button" id="add-program">' + icon("plus") + "Add program</button></div>" +
      '<div class="table-responsive"><table class="table data-table"><thead><tr><th>Code</th><th>Program</th><th>1st-choice applicants</th><th>Passed vs slots</th><th style="width:130px">Slots</th></tr></thead><tbody>' +
      st.programs.map(function (p) {
        var pass = passed[p.code] || 0;
        return '<tr><td><span class="code-chip">' + esc(p.code) + "</span></td><td>" + esc(p.name) + "</td><td>" + (firstChoice[p.code] || 0) + "</td>" +
          '<td><span class="doc-meter wide' + (pass > p.slots ? " over" : "") + '"><span style="--w:' + Math.min(100, ui.pct(pass, p.slots)) + '%"></span></span><small>' + pass + " of " + p.slots + (pass > p.slots ? " · over capacity" : "") + "</small></td>" +
          '<td><input class="form-control form-control-sm slot-input" type="number" min="1" max="2000" value="' + p.slots + '" data-code="' + esc(p.code) + '" aria-label="Slots for ' + esc(p.code) + '"></td></tr>';
      }).join("") + "</tbody></table></div></section>" +

      '<div class="row g-3">' +
      '<div class="col-xl-7" data-reveal><section class="card-soft h-100"><div class="panel-heading mb-2"><div><h2>Documentary requirements</h2><p>Freshmen uploads. Conditional items apply only to the applicants noted.</p></div></div>' +
      '<ul class="req-list">' + ADM.DOC_TYPES.map(function (d) {
        var on = inactive.indexOf(d.key) === -1;
        return '<li><span class="doc-icon tone-3">' + icon(d.icon) + '</span><div><strong>' + esc(d.title) + "</strong><small>" + esc(d.note || "All applicants") + "</small></div>" +
          '<div class="form-check form-switch ms-auto"><input class="form-check-input" type="checkbox" role="switch" id="req-' + d.key + '" data-req="' + d.key + '"' + (on ? " checked" : "") + '><label class="visually-hidden" for="req-' + d.key + '">Require ' + esc(d.title) + "</label></div></li>";
      }).join("") +
      ADM.PHYSICAL_ITEMS.map(function (p) { return '<li class="physical"><span class="doc-icon tone-1">' + icon("folder") + "</span><div><strong>" + esc(p) + "</strong><small>Physical item, submitted in person</small></div></li>"; }).join("") +
      "</ul>" +
      '<h3 class="card-title mt-4">Medical requirements</h3><p class="text-muted small mb-2">Last step, for applicants who passed. Submitted after the result is released.</p><ul class="req-list">' +
      ADM.MED_TYPES.map(function (m) { return '<li><span class="doc-icon tone-2">' + icon(m.icon) + "</span><div><strong>" + esc(m.title) + "</strong><small>" + esc(m.note) + "</small></div></li>"; }).join("") +
      "</ul></section></div>" +

      '<div class="col-xl-5 d-flex flex-column gap-3" data-reveal>' +
      '<section class="card-soft"><h3 class="card-title">Admission period</h3><form id="period-form" class="row g-2 align-items-end" novalidate>' +
      '<div class="col"><label class="form-label" for="deadline">Application deadline</label><input class="form-control" type="date" id="deadline" value="' + st.settings.deadline + '" required><div class="invalid-feedback" id="deadline-error"></div></div>' +
      '<div class="col-auto"><button class="btn btn-brand" type="submit">Save</button></div></form>' +
      '<dl class="kv kv-compact mt-3"><div><dt>Review target</dt><dd>' + ADM.REVIEW_SLA_DAYS + " working days</dd></div><div><dt>Passing score</dt><dd>" + ADM.PASSING_SCORE + ' / 100 <small class="text-muted">' + icon("lock") + "internal</small></dd></div></dl></section>" +

      '<section class="card-soft"><h3 class="card-title">Your access · ' + esc(ADM.STAFF.role) + "</h3>" +
      '<ul class="access-list">' + CAN.map(function (c) { return '<li class="can">' + icon("check") + esc(c) + "</li>"; }).join("") +
      CANNOT.map(function (c) { return '<li class="cannot">' + icon("lock") + esc(c) + "</li>"; }).join("") + "</ul>" +
      '<p class="text-muted small mb-0 mt-2">Accounts, roles, backups and security settings are handled by the system administrator.</p></section>' +

      '<section class="card-soft demo-card"><h3 class="card-title">Demo data</h3><p class="text-muted small">This prototype keeps its records in this browser. Reset to start over with fresh sample applicants.</p>' +
      '<button class="btn btn-ghost" type="button" id="reset-demo">' + icon("refresh") + "Reset demo data</button></section>" +
      "</div></div>"
    );
  }

  $(document).on("change", ".slot-input", function () {
    var v = parseInt(this.value, 10);
    if (!(v >= 1 && v <= 2000)) { this.value = ADM.program($(this).data("code")).slots; AO.showToast("Slots must be between 1 and 2,000."); return; }
    ADM.actions.setSlots($(this).data("code"), v);
    ADM.refresh();
    AO.showToast($(this).data("code") + " slots updated.");
  });

  $(document).on("change", "[data-req]", function () {
    ADM.actions.toggleRequirement($(this).data("req"), this.checked);
    ADM.refresh();
    AO.showToast(this.checked ? "Requirement turned on." : "Requirement turned off for this period.");
  });

  $(document).on("submit", "#period-form", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    ADM.actions.setDeadline($("#deadline").val());
    ADM.refresh();
    AO.showToast("Application deadline saved.");
  });

  $(document).on("click", "#add-program", function () {
    $("#program-form")[0].reset();
    $("#program-form .is-invalid").each(function () { AO.clearError($(this)); });
    ADM.ui.modal("programModal").show();
  });
  $("#program-form").on("submit", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    if (!ADM.actions.addProgram($("#program-code").val().trim(), $("#program-name").val().trim(), +$("#program-slots").val())) {
      AO.setError($("#program-code"), "A program with this code already exists."); return;
    }
    ADM.ui.modal("programModal").hide();
    ADM.refresh();
    AO.showToast("Program added.");
  });

  $(document).on("click", "#reset-demo", function () {
    ADM.ui.confirm({ title: "Reset demo data?", text: "Every change made in this browser is replaced with fresh sample applicants.", ok: "Reset", icon: "refresh" }, function () {
      ADM.reset();
      ADM.go("#/dashboard");
      AO.showToast("Demo data reset.");
    });
  });

  ADM.views.settings = { title: "Programs & requirements", render: render };
})(jQuery);
