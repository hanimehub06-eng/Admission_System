/* ==========================================================================
   View: Accounts (#/accounts) — super admin only. Create and enable or
   disable admission personnel, clinic and college dean accounts.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  var rows = [], colleges = [], loaded = false, created = null;
  // Admission personnel only: the panel shows them just this task's screens
  var TASKS = { "": "All screens", personnel: "Admission Personnel", proctor: "Exam Proctor", exam_office: "Exam Office" };

  function load() {
    AO.api("GET", "/api/admin/accounts").done(function (res) { rows = res.data; loaded = true; ADM.refresh(); });
  }

  function render($el) {
    if (!loaded) { $el.html('<div class="page-title"><h1>Accounts</h1></div><p class="text-muted">Loading…</p>'); load(); return; }
    var groups = ["admission", "clinic", "college_dean"];
    $el.html(
      '<div class="page-title" data-reveal><h1>Accounts</h1><p>Admission personnel, clinic and college dean accounts. Disabled accounts cannot sign in.</p></div>' +
      (created ? '<div class="info-note" data-reveal>' + icon("lock") + "<p><strong>" + esc(created.email) + "</strong> created. Temporary password (shown once): <code>" + esc(created.password) + "</code></p></div>" : "") +
      '<section class="card-soft mb-3" data-reveal><form id="account-form" class="row g-2 align-items-end" novalidate>' +
      '<div class="col-md-2"><label class="form-label" for="acc-name">Name</label><input class="form-control" id="acc-name" required></div>' +
      '<div class="col-md-2"><label class="form-label" for="acc-email">Email</label><input class="form-control" id="acc-email" type="email" required></div>' +
      '<div class="col-md-2"><label class="form-label" for="acc-employee">EMS employee no.</label><input class="form-control" id="acc-employee" placeholder="26-00001" inputmode="numeric"></div>' +
      '<div class="col-md-2"><label class="form-label" for="acc-role">Role</label><select class="form-select" id="acc-role"><option value="admission">Admission personnel</option><option value="clinic">Clinic</option><option value="college_dean">College dean</option></select></div>' +
      '<div class="col-md-2"><label class="form-label" for="acc-college">College code</label><input class="form-control text-uppercase" id="acc-college" placeholder="Deans only"></div>' +
      '<div class="col-md-2"><button class="btn btn-brand w-100" type="submit">' + icon("plus") + "Create</button></div>" +
      '<div class="invalid-feedback d-block" id="acc-error"></div></form></section>' +
      groups.map(function (g) {
        var list = rows.filter(function (r) { return r.role === g; });
        return '<section class="table-card mb-3" data-reveal><div class="table-toolbar"><strong>' + esc(list[0] ? list[0].role_label : { admission: "Admission personnel", clinic: "University Clinic", college_dean: "College Dean" }[g]) + "</strong></div>" +
          (list.length ? '<div class="table-responsive"><table class="table data-table"><thead><tr><th>Name</th><th>Email</th><th>Employee no.</th><th>College</th><th>Last sign-in</th>' + (g === "admission" ? "<th>Task</th>" : "") + '<th></th></tr></thead><tbody>' +
            list.map(function (r) {
              return "<tr><td>" + esc(r.name) + "</td><td>" + esc(r.email) + "</td><td>" + esc(r.employee_no || "") + "</td><td>" + esc(r.college || "") + '</td><td class="text-muted">' + (r.last_login_at ? esc(ADM.ui.dt(r.last_login_at)) : "Never") + "</td>" +
                (g === "admission" ? '<td><select class="form-select form-select-sm" data-account-task="' + r.id + '" aria-label="Task for ' + esc(r.name) + '">' +
                  $.map(TASKS, function (label, k) { return '<option value="' + k + '"' + ((r.task || "") === k ? " selected" : "") + ">" + label + "</option>"; }).join("") + "</select></td>" : "") +
                '<td class="text-end"><button class="btn btn-ghost btn-sm" data-toggle-account="' + r.id + '" data-active="' + (r.active ? 1 : 0) + '">' + (r.active ? "Disable" : "Enable") + "</button></td></tr>";
            }).join("") + "</tbody></table></div>" : ADM.ui.empty("users", "No accounts yet")) + "</section>";
      }).join("")
    );
  }

  $(document).on("submit", "#account-form", function (e) {
    e.preventDefault();
    var body = { name: $("#acc-name").val().trim(), email: $("#acc-email").val().trim(), employee_no: $("#acc-employee").val().trim() || null, role: $("#acc-role").val(), college: $("#acc-college").val().trim() || null };
    AO.api("POST", "/api/admin/accounts", body)
      .done(function (res) { created = { email: body.email, password: res.data.temporary_password }; loaded = false; ADM.refresh(); })
      .fail(function (xhr) { $("#acc-error").text(AO.apiError(xhr)); });
  });
  $(document).on("change", "[data-account-task]", function () {
    var $sel = $(this), id = $sel.data("account-task");
    AO.api("PATCH", "/api/admin/accounts/" + id + "/task", { task: $sel.val() || null })
      .done(function (res) {
        rows.forEach(function (r) { if (r.id === id) r.task = res.data.task; });
        AO.showToast(res.data.task ? "Task assigned: " + TASKS[res.data.task] + "." : "Task removed: all screens.");
      })
      .fail(function (xhr) { AO.showToast(AO.apiError(xhr)); loaded = false; ADM.refresh(); });
  });
  $(document).on("click", "[data-toggle-account]", function () {
    AO.api("PATCH", "/api/admin/accounts/" + $(this).data("toggle-account") + "/active", { active: !+$(this).data("active") })
      .done(function () { loaded = false; created = null; ADM.refresh(); })
      .fail(function (xhr) { AO.showToast(AO.apiError(xhr)); });
  });

  ADM.views.accounts = { title: "Accounts", render: render };
})(jQuery);
