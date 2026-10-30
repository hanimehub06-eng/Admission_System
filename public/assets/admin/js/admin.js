/* ==========================================================================
/* ==========================================================================
   PLP Online Admission — Admission Office: shell, sign-in and router
   Loaded last. Views register themselves on ADM.views (see views/*.js):
     ADM.views.name = { title, nav, render($section, param) }
   Routes are hash based (#/applicants/AD-2026-08011) so Back works.
   ========================================================================== */
$(function () {
  "use strict";

  var ADM = window.ADM;

  // Hash → view name; the second segment is passed to render() as param
  var ROUTES = { dashboard: "dashboard", applicants: "applicants", documents: "documents", exams: "exams", results: "results", interviews: "interviews", medical: "medical", reports: "reports", settings: "settings", audit: "audit", college: "college", evaluation: "evaluation", evaluated: "evaluated", questions: "questions", accounts: "accounts", check: "check", returned: "returned", approved: "approved" };

  /* Role-based access (use-case diagrams). One portal, three main roles;
     the server enforces the same limits on every request.
     - Admin: the whole system (dashboard, every admissions screen, the
       Clinic and College Dean modules, admission dates, audit log, reports)
     - Admission Personnel: slot setup, applicants, documents, entrance exam,
       results, interviews, medical
     - College Dean: applicant list, evaluation, results/referral (own college)
     - Clinic nurse: medical files to check, sent back, approved */
  var ACCESS = {
    admission_head: ["dashboard", "applicants", "documents", "exams", "results", "interviews", "evaluated", "medical", "reports", "settings", "audit"],
    super_admin: ["dashboard", "applicants", "documents", "exams", "results", "interviews", "evaluated", "medical", "reports", "settings", "audit", "accounts"],
    admission: ["applicants", "documents", "exams", "results", "interviews", "medical", "settings"],
    college_dean: ["college", "evaluation", "questions", "evaluated"],
    clinic: ["check", "returned", "approved"],
    proctor: ["exams"],
    read_only: ["dashboard", "applicants", "reports"]
  };
  /* Task: the Super Admin assigns each Admission Personnel account a task
     (Accounts), and the menu shows only its screens. No task: every screen of
     the role. A UI filter only: the server still enforces the role. */
  var TASKS = {
    personnel: { label: "Admission Personnel", screens: ["applicants", "documents"] },
    proctor: { label: "Exam Proctor", screens: ["exams", "reports"] },
    exam_office: { label: "Exam Office", screens: ["results"] }
  };
  function task() {
    var t = ADM.STAFF.task;
    return ADM.STAFF.roleKey === "admission" && TASKS[t] ? t : null;
  }
  function allowed() {
    var t = task();
    if (t && TASKS[t].screens) return TASKS[t].screens;
    return ACCESS[ADM.STAFF.roleKey] || ["dashboard"];
  }
  function home() { return "#/" + allowed()[0]; }
  var DETAIL = { applicants: "applicant", exams: "session", documents: "docreview", check: "medreview", returned: "medreview", approved: "medreview" };   // #/applicants/:id → view "applicant"
  var current = { view: null, param: null };

  /* ==========================================================================
     SIGN IN (Laravel API, JWT with a staff role)
     ========================================================================== */
  function enterApp() {
    $("#auth-screen").prop("hidden", true);
    $("#app").prop("hidden", false);
    $("#sidebar-name").text(ADM.STAFF.name);
    $("#sidebar-avatar").text(ADM.STAFF.initials);
    $("#sidebar-role").text(ADM.STAFF.role);
    var dean = ADM.STAFF.roleKey === "college_dean", clinic = ADM.STAFF.roleKey === "clinic";
    $("#sidebar-section").text(dean ? "College dean" + (ADM.dean.college ? " · " + ADM.dean.college.code : "") : clinic ? "University clinic" : ADM.STAFF.roleKey === "super_admin" ? "Super admin" : ADM.STAFF.roleKey === "admission_head" ? "Admin" : "Admission office");
    $("#super-chip").prop("hidden", ADM.STAFF.roleKey !== "super_admin");
    $(".crumb-root").text(dean ? "College dean" : clinic ? "University clinic" : ADM.STAFF.roleKey === "super_admin" ? "Super admin" : "Admission office");
    applyAccess();
    if (!location.hash) location.replace(home());
    route();
  }

  // Only this role's (or task's) screens in the menu; "More" only if it has any
  function applyAccess() {
    $(".sidebar .nav-link[data-nav]").each(function () { $(this).prop("hidden", allowed().indexOf($(this).data("nav")) === -1); });
    $("#sidebar-more").prop("hidden", !$("#sidebar-more .nav-link:not([hidden])").length);
    $("#quick-search").prop("hidden", allowed().indexOf("applicants") === -1);
    $(".sidebar .brand").attr("href", home());
    var t = task();
    $("#task-chip").prop("hidden", !t);
    $("#task-chip-label").text(t ? "Task: " + TASKS[t].label : "");
  }

  function leaveApp() {
    AO.setToken(null);
    $(".modal.show").each(function () { bootstrap.Modal.getInstance(this).hide(); });   // e.g. a floating document window
    $(".tooltip").remove();
    $("#app").prop("hidden", true);
    $("#auth-screen").prop("hidden", false);
    $("#admin-password").val("");
    window.scrollTo(0, 0);
  }

  $("#admin-signin-form").on("submit", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    var email = $("#admin-email").val().trim();
    var login = AO.api("POST", "/api/auth/login", { email: email, password: $("#admin-password").val(), portal: "office" })
      .then(function (res) {
        // UI pending: there is no 2FA code screen yet (see PROGRESS.md)
        if (res.data.two_factor_required) return $.Deferred().reject({ responseJSON: { message: "Two-factor sign-in is on for this account, but this screen can't ask for the code yet." } });
        AO.setToken(res.data.token);
        return ADM.load();
      });
    AO.busy($("#admin-signin-submit"), login)
      .done(enterApp)
      .fail(function (xhr) { AO.setToken(null); AO.setError($("#admin-email"), AO.apiError(xhr)); });
  });

  $(".logout-button").on("click", function () {
    AO.api("POST", "/api/auth/logout");
    leaveApp();
    AO.showToast("You've been signed out.");
  });
  // Token expired or revoked
  $(document).on("ao:signedout", function () { if (!$("#app").prop("hidden")) { leaveApp(); AO.showToast("Your session ended. Sign in again."); } });

  /* ==========================================================================
     ROUTER
     ========================================================================== */
  function parseHash() {
    var parts = location.hash.replace(/^#\/?/, "").split("/").map(decodeURIComponent);
    var base = ROUTES[parts[0]] && allowed().indexOf(parts[0]) !== -1 ? parts[0] : allowed()[0];
    var param = parts[1] || null;
    return { view: param && DETAIL[base] ? DETAIL[base] : ROUTES[base], nav: base, param: param };
  }

  function route() {
    if ($("#app").prop("hidden")) return;
    var r = parseHash();
    var view = ADM.views[r.view];
    var $section = $("#view-" + r.view);
    var changed = current.view !== r.view || current.param !== r.param;
    current = r;

    $(".tooltip").remove();
    $(".view-section").removeClass("active");
    $section.addClass("active");
    $(".sidebar-nav .nav-link").removeClass("active").filter('[data-nav="' + r.nav + '"]').addClass("active");
    if ($("#sidebar-more .nav-link.active").length) $("#sidebar-more").prop("open", true);
    AO.moveNavIndicator();
    AO.closeSidebar();

    view.render($section, r.param);
    $("#page-breadcrumb").text(typeof view.title === "function" ? view.title(r.param) : view.title);
    document.title = $("#page-breadcrumb").text() + " · PLP Admission Office";
    AO.paintIcons($section);
    AO.initTooltips($section);
    ADM.ui.refreshCounts();
    if (changed) { AO.revealView($section); window.scrollTo({ top: 0, behavior: "auto" }); }
  }

  /* Re-render the open view after a change, keeping scroll position */
  ADM.refresh = function () {
    var y = window.scrollY;
    $(".tooltip").remove();
    var view = ADM.views[current.view];
    var $section = $("#view-" + current.view);
    view.render($section, current.param);
    AO.paintIcons($section);
    AO.initTooltips($section);
    ADM.ui.refreshCounts();
    window.scrollTo(0, y);
  };
  ADM.go = function (hash) { if (location.hash === hash) route(); else location.hash = hash; };

  $(window).on("hashchange", route);

  /* Quick search in the top bar: exact ID / LRN opens the record, else filters the list */
  $("#quick-search").on("submit", function (e) {
    e.preventDefault();
    var q = $("#quick-search-input").val().trim();
    if (!q) return;
    var exact = ADM.state.applicants.filter(function (a) { return a.id.toLowerCase() === q.toLowerCase() || a.lrn === q; })[0];
    $("#quick-search-input").val("").trigger("blur");
    if (exact) { ADM.go("#/applicants/" + exact.id); return; }
    ADM.views.applicants.search(q);
    ADM.go("#/applicants");
  });

  // Live results while typing (AJAX, jQuery UI autocomplete); Enter without picking one still runs the search above
  $("#quick-search-input").autocomplete({
    minLength: 2, delay: 250, position: { my: "left top+6", at: "left bottom" },
    source: function (req, res) {
      AO.api("GET", "/api/admin/applicants/search?q=" + encodeURIComponent(req.term))
        .done(function (r) { res(r.data.length ? r.data : [{ empty: true }]); })
        .fail(function () { res([]); });
    },
    focus: function (e) { e.preventDefault(); },   // keep what was typed while arrowing through results
    select: function (e, ui) {
      e.preventDefault();
      if (ui.item.empty) return;
      $(this).val("").trigger("blur");
      ADM.go("#/applicants/" + ui.item.id);
    }
  }).autocomplete("instance")._renderItem = function ($ul, item) {
    if (item.empty) return $('<li class="ui-state-disabled">').append('<div class="ac-empty">No applicant matches</div>').appendTo($ul);
    var st = ADM.STATUSES[item.status];
    return $("<li>").append('<div class="ac-item"><span class="ac-main">' + AO.esc(item.name) + '</span><span class="ac-sub">' + AO.esc(item.id) + (item.lrn ? " · LRN " + AO.esc(item.lrn) : "") + "</span>" +
      (st ? '<span class="status-pill ' + st.pill + '">' + AO.esc(st.label) + "</span>" : "") + "</div>").appendTo($ul);
  };

  /* ==========================================================================
     BOOT
     ========================================================================== */
  AO.paintIcons();
  AO.initTooltips(document);
  // Still signed in in this tab: reload the state and go straight in
  if (AO.getToken()) ADM.load().done(enterApp).fail(function () { AO.setToken(null); });
});
