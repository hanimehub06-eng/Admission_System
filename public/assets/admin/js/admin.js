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
  var ROUTES = { dashboard: "dashboard", applicants: "applicants", documents: "documents", exams: "exams", results: "results", medical: "medical", reports: "reports", settings: "settings", audit: "audit" };
  var DETAIL = { applicants: "applicant", exams: "session", documents: "docreview" };   // #/applicants/:id → view "applicant"
  var current = { view: null, param: null };

  /* ==========================================================================
     SIGN IN (Laravel API, JWT with the admission or clinic role)
     ========================================================================== */
  function enterApp() {
    $("#auth-screen").prop("hidden", true);
    $("#app").prop("hidden", false);
    $("#sidebar-name").text(ADM.STAFF.name);
    $("#sidebar-avatar").text(ADM.STAFF.initials);
    if (!location.hash) location.replace("#/dashboard");
    route();
  }

  function leaveApp() {
    AO.setToken(null);
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
    var base = ROUTES[parts[0]] ? parts[0] : "dashboard";
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

  /* ==========================================================================
     BOOT
     ========================================================================== */
  AO.paintIcons();
  AO.initTooltips(document);
  // Still signed in in this tab: reload the state and go straight in
  if (AO.getToken()) ADM.load().done(enterApp).fail(function () { AO.setToken(null); });
});
