/* ==========================================================================
   PLP Online Admission — University Clinic (nurse) portal
   The nurse only sees applicants' medical requirements: the server sends a
   medical-only state (GET /api/clinic/state): no profile, exam, score or
   document data. They send a file back with a remark, or approve the
   applicant. The approval shows on the admission office's Medical page as
   "Approved".
   Needs core.js, admin-store.js (state shape, medStatus) and admin-ui.js
   (modals, preview). Routes: #/check, #/returned, #/approved and #/review/:id.
   ========================================================================== */
$(function () {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;

  var LISTS = {
    check: { title: "To check", lede: "Applicants who passed and sent their medical requirements. Open one to check the files, then approve or send a file back.", status: "pending", empty: ["check", "Nothing to check", "New medical files show up here as applicants send them."] },
    returned: { title: "Sent back", lede: "Applicants you asked to replace a file. They come back to To check once they send their files again.", status: "resubmit", empty: ["flag", "Nothing sent back", ""] },
    approved: { title: "Approved", lede: "Applicants you approved. The admission office sees these as Approved, and the applicant's admission is complete.", status: "cleared", empty: ["shield", "No approvals yet", ""] }
  };
  var current = { view: null, param: null };
  var f = { q: "", page: 1 };

  /* ==========================================================================
     ACTIONS (the nurse's only changes). The server saves them and writes the
     audit trail; its copy of the applicant replaces ours.
     ========================================================================== */
  function send(method, path, data, a) {
    return AO.api(method, "/api/clinic/applicants/" + encodeURIComponent(a.id) + "/medical/" + path, data)
      .done(function (res) { if (res && res.data) ADM.replaceApplicant(a, res.data); })
      .fail(function (xhr) { if (xhr.status !== 401) AO.showToast(AO.apiError(xhr)); });
  }
  var actions = {
    // PATCH /api/clinic/applicants/:id/medical/:key { status: "resubmit", remark }
    sendBack: function (a, key, remark) { return send("PATCH", encodeURIComponent(key), { status: "resubmit", remark: remark }, a); },
    // POST /api/clinic/applicants/:id/medical/approve → the office sees "Approved"
    approve: function (a) { return send("POST", "approve", {}, a); }
  };

  /* ==========================================================================
     DATA
     ========================================================================== */
  function inList(name) {
    return ADM.state.applicants.filter(function (a) {
      if (ADM.medStatus(a) !== LISTS[name].status) return false;
      return !f.q || (ADM.fullName(a) + " " + a.id).toLowerCase().indexOf(f.q.toLowerCase()) !== -1;
    });
  }
  function latest(a, field) {
    return ADM.MED_TYPES.reduce(function (m, t) { var file = ADM.fileOf(a, t.key); return file && file[field] && file[field] > m ? file[field] : m; }, "");
  }
  function sentAt(a) { return (a.medical && a.medical.submittedAt) || latest(a, "uploadedAt"); }
  function decidedAt(a) { return latest(a, "reviewedAt"); }
  function age(a) {
    if (!a.birthday) return null;
    var b = AO.parseDate(a.birthday), t = AO.today();
    return t.getFullYear() - b.getFullYear() - (t < new Date(t.getFullYear(), b.getMonth(), b.getDate()) ? 1 : 0);
  }
  function fileChips(a) {
    return ADM.MED_TYPES.map(function (m) {
      var file = ADM.fileOf(a, m.key);
      var st = file ? file.status : "missing";
      return '<span class="med-chip st-' + st + '" title="' + esc(m.title + ": " + ADM.DOC_STATUS[st].label) + '">' + (st === "verified" ? icon("check") : st === "resubmit" ? icon("flag") : "") + esc(m.title.split(" (")[0]) + "</span>";
    }).join("");
  }

  /* ==========================================================================
     VIEWS
     ========================================================================== */
  function renderList($el, name) {
    var ui = ADM.ui, L = LISTS[name];
    var when = name === "check" ? sentAt : decidedAt;
    var list = inList(name).sort(function (x, y) {
      return name === "check" ? (when(x) < when(y) ? -1 : 1)   // oldest first, so no one waits too long
        : (when(x) < when(y) ? 1 : -1);                         // newest decisions first
    });
    f.page = ui.clampPage(f.page, list.length);
    var rows = list.slice((f.page - 1) * ADM.PAGE_SIZE, f.page * ADM.PAGE_SIZE);

    var table = !rows.length ? ui.empty(L.empty[0], L.empty[1], f.q ? "Clear the search to see everyone." : L.empty[2])
      : '<div class="table-responsive"><table class="table data-table"><thead><tr><th>Applicant</th><th>Medical files</th><th>' + (name === "check" ? "Sent" : name === "approved" ? "Approved" : "Sent back") + '</th><th><span class="visually-hidden">Open</span></th></tr></thead><tbody>' +
        rows.map(function (a) {
          var at = when(a);
          return '<tr class="row-link" data-href="#/review/' + esc(a.id) + '" tabindex="0"><td>' + ui.applicantCell(a) + "</td>" +
            '<td><div class="med-chips">' + fileChips(a) + "</div></td>" +
            "<td>" + (at ? AO.fmt(at) + '<small class="d-block text-muted">' + ui.relDays(at) + "</small>" : "—") + "</td>" +
            '<td class="text-end">' + (name === "check" ? '<span class="btn btn-ghost btn-sm">' + icon("eye") + "Check</span>" : icon("arrowR")) + "</td></tr>";
        }).join("") + "</tbody></table></div>";

    $el.html(
      '<div class="page-title" data-reveal><p class="eyebrow">Medical files</p><h1>' + esc(L.title) + '</h1><p class="lede">' + esc(L.lede) + "</p></div>" +
      '<section class="table-card" data-reveal><div class="table-toolbar">' +
      '<div class="search-field">' + icon("search") + '<input type="search" class="form-control" id="nurse-q" placeholder="Search applicant or ID" aria-label="Search applicant" value="' + esc(f.q) + '"></div>' +
      '<span class="text-muted small ms-auto">' + esc(ADM.MED_TYPES.map(function (m) { return m.title; }).join(" · ")) + "</span></div>" +
      table + (rows.length ? ui.pager(list.length, f.page) : "") + "</section>"
    );
  }

  function listOf(a) { var st = ADM.medStatus(a); return st === "cleared" ? "approved" : st === "resubmit" ? "returned" : "check"; }

  function renderReview($el, id) {
    var ui = ADM.ui;
    var a = ADM.applicant(id);
    var st = a && ADM.medStatus(a);
    if (!a || st === null || st === "none") {
      $el.html('<section class="card-soft">' + ui.empty("search", "No medical files for this ID", "The applicant hasn't sent medical requirements yet.") + '<div class="text-center pb-2"><a class="btn btn-ghost" href="#/check">Back to the list</a></div></section>');
      return;
    }
    var back = listOf(a);
    var approved = st === "cleared";
    var canApprove = ADM.canApproveMedical(a);
    var missing = ADM.MED_TYPES.filter(function (m) { return !ADM.fileOf(a, m.key); }).length;
    var years = age(a);

    var primary = approved ? '<span class="tag tag-verified">' + icon("check") + "Approved · sent to the admission office</span>"
      : '<button class="btn btn-ok" type="button" id="nurse-approve"' + (canApprove ? "" : " disabled") + ">" + icon("check") + "Approve</button>";
    var note = approved ? "Approved " + ui.dt(decidedAt(a)) + ". The admission office sees this applicant as Approved."
      : st === "resubmit" ? "A file was sent back. You can approve once the applicant replaces it and sends their files again."
        : missing ? missing + " file" + (missing === 1 ? " is" : "s are") + " still missing."
          : "Open each file. If everything is in order, approve. If not, send that file back with a remark.";

    $el.html(
      '<a class="back-link" href="#/' + back + '" data-reveal>' + icon("arrowL") + esc(LISTS[back].title) + "</a>" +
      '<section class="record-hero" data-reveal>' + ui.avatar(a, "avatar-xl") +
      '<div class="record-id"><p class="eyebrow">' + esc(a.id) + "</p><h1>" + esc(ADM.fullName(a)) + "</h1>" +
      '<p class="record-meta">' + (a.sex ? "<span>" + esc(a.sex) + "</span>" : "") + (years !== null ? "<span>" + years + " years old</span>" : "") +
      '<span class="tag ' + ADM.MED_STATUS[st].tag + '">' + (approved ? icon("check") : "") + esc(ADM.MED_STATUS[st].label) + "</span></p></div>" +
      '<div class="record-actions">' + primary + "</div></section>" +
      '<section class="table-card" data-reveal><div class="table-toolbar"><div><strong>Medical requirements</strong>' +
      '<small class="d-block text-muted">' + esc(note) + "</small></div></div>" +
      '<ul class="review-list">' + ADM.MED_TYPES.map(function (m) {
        var file = ADM.fileOf(a, m.key) || { status: "missing" };
        var has = file.status !== "missing";
        return '<li class="review-item status-' + file.status + '"><span class="doc-icon tone-3">' + icon(m.icon) + "</span>" +
          '<div class="review-main"><div class="doc-title-row"><h3>' + esc(m.title) + "</h3>" + ui.docTag(file.status) + '<span class="cond-note">' + esc(m.note) + "</span></div>" +
          (has ? '<p class="review-file">' + esc(file.file) + " · " + AO.fileSize(file.size) + " · sent " + ui.relDays(file.uploadedAt).toLowerCase() + "</p>" : '<p class="review-file">Not sent yet</p>') +
          (file.remark ? '<p class="review-remark">' + icon("flag") + esc(file.remark) + "</p>" : "") +
          (file.reviewedBy ? '<p class="review-by">' + (file.status === "verified" ? "Approved" : "Sent back") + " by " + esc(file.reviewedBy) + " · " + esc(ui.dt(file.reviewedAt)) + "</p>" : "") + "</div>" +
          '<div class="review-actions">' +
          (has ? '<button class="btn btn-ghost btn-sm" type="button" data-n-view="' + m.key + '">' + icon("eye") + "View</button>" : "") +
          (has && !approved && file.status === "pending" && st === "pending" ? '<button class="btn btn-warn btn-sm" type="button" data-n-back="' + m.key + '">' + icon("flag") + "Send back</button>" : "") +
          "</div></li>";
      }).join("") + "</ul></section>"
    );
  }

  /* ==========================================================================
     SIGN IN (Laravel API, JWT with the clinic role)
     ========================================================================== */
  function enterApp() {
    $("#auth-screen").prop("hidden", true);
    $("#app").prop("hidden", false);
    $("#sidebar-name").text(ADM.STAFF.name);
    $("#sidebar-avatar").text(ADM.STAFF.initials);
    $("#period-chip").text(ADM.state.settings.period || "");
    if (!location.hash) location.replace("#/check");
    current = { view: null, param: null };
    route();
  }
  function leaveApp() {
    AO.setToken(null);
    $(".tooltip").remove();
    $("#app").prop("hidden", true);
    $("#auth-screen").prop("hidden", false);
    $("#nurse-password").val("");
    window.scrollTo(0, 0);
  }
  $("#nurse-signin-form").on("submit", function (e) {
    e.preventDefault();
    if (!AO.validateFields($(this))) return;
    var login = AO.api("POST", "/api/auth/login", { email: $("#nurse-email").val().trim(), password: $("#nurse-password").val(), portal: "clinic" })
      .then(function (res) {
        // UI pending: there is no 2FA code screen yet (see PROGRESS.md)
        if (res.data.two_factor_required) return $.Deferred().reject({ responseJSON: { message: "Two-factor sign-in is on for this account, but this screen can't ask for the code yet." } });
        AO.setToken(res.data.token);
        return ADM.load();
      });
    AO.busy($("#nurse-signin-submit"), login)
      .done(enterApp)
      .fail(function (xhr) { AO.setToken(null); AO.setError($("#nurse-email"), AO.apiError(xhr)); });
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
    if (parts[0] === "review" && parts[1]) return { view: "review", param: parts[1] };
    return { view: "list", param: LISTS[parts[0]] ? parts[0] : "check" };
  }

  function draw() {
    var $section = $("#view-" + current.view);
    $(".tooltip").remove();
    if (current.view === "review") renderReview($section, current.param); else renderList($section, current.param);
    AO.paintIcons($section);
    AO.initTooltips($section);
    refreshCounts();
    var a = current.view === "review" && ADM.applicant(current.param);
    var nav = current.view === "list" ? current.param : a ? listOf(a) : "check";
    $(".sidebar-nav .nav-link").removeClass("active").filter('[data-nav="' + nav + '"]').addClass("active");
    AO.moveNavIndicator();
  }

  function route() {
    if ($("#app").prop("hidden")) return;
    var r = parseHash();
    var changed = current.view !== r.view || current.param !== r.param;
    if (changed && r.view === "list") { f.q = ""; f.page = 1; }
    current = r;

    var $section = $("#view-" + r.view);
    $(".view-section").removeClass("active");
    $section.addClass("active");
    AO.closeSidebar();
    draw();

    var a = r.view === "review" && ADM.applicant(r.param);
    $("#page-breadcrumb").text(r.view === "review" ? (a ? ADM.fullName(a) : "Applicant") : LISTS[r.param].title);
    document.title = $("#page-breadcrumb").text() + " · PLP University Clinic";
    if (changed) { AO.revealView($section); window.scrollTo({ top: 0, behavior: "auto" }); }
  }

  /* Redraw after a change, keeping scroll position */
  ADM.refresh = function () { var y = window.scrollY; draw(); window.scrollTo(0, y); };

  function refreshCounts() {
    var n = { pending: 0, resubmit: 0, cleared: 0 };
    ADM.state.applicants.forEach(function (a) { var st = ADM.medStatus(a); if (st in n) n[st]++; });
    $("#nav-count-check").text(n.pending).toggleClass("full", n.pending === 0);
    $("#nav-count-returned").text(n.resubmit).toggleClass("full", n.resubmit === 0);
    $("#nav-count-approved").text(n.cleared).toggleClass("full", n.cleared === 0);
  }

  $(window).on("hashchange", route);

  /* ==========================================================================
     EVENTS
     ========================================================================== */
  function go(hash) { if (location.hash === hash) route(); else location.hash = hash; }
  function reviewed() { return ADM.applicant(current.param); }

  $(document).on("click", "#view-list .row-link", function () { go($(this).data("href")); });
  $(document).on("keydown", "#view-list .row-link", function (e) { if (e.key === "Enter") go($(this).data("href")); });
  $(document).on("click", "#view-list [data-page]", function () { f.page = +$(this).data("page"); ADM.refresh(); });

  var t;
  $(document).on("input", "#nurse-q", function () {
    var v = this.value; clearTimeout(t);
    t = setTimeout(function () { f.q = v.trim(); f.page = 1; ADM.refresh(); $("#nurse-q").trigger("focus")[0].setSelectionRange(v.length, v.length); }, 250);
  });

  function sendBack(a, key) {
    ADM.ui.askRemark(a, key, function (remark) {
      actions.sendBack(a, key, remark).done(function () { ADM.refresh(); AO.showToast("Sent back to " + a.firstName + " with your remark."); });
    });
  }
  $(document).on("click", "[data-n-back]", function () { sendBack(reviewed(), $(this).data("n-back")); });
  $(document).on("click", "[data-n-view]", function () {
    var a = reviewed(), key = $(this).data("n-view");
    var file = ADM.fileOf(a, key);
    ADM.ui.previewDoc(a, key, '<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Close</button>' +
      (file.status === "pending" && ADM.medStatus(a) === "pending" ? '<button type="button" class="btn btn-warn" data-n-back="' + key + '" data-bs-dismiss="modal">' + icon("flag") + "Send back</button>" : ""));
  });

  $(document).on("click", "#nurse-approve", function () {
    var a = reviewed();
    ADM.ui.confirm({ title: "Approve " + a.firstName + "'s medical requirements?", text: "Your approval goes to the admission office, where " + ADM.fullName(a) + " shows as Approved and their admission is complete. This can't be undone here.", ok: "Approve", icon: "check" }, function () {
      actions.approve(a).done(function () { ADM.refresh(); AO.showToast("Approved. The admission office can see it now."); });
    });
  });

  /* ==========================================================================
     BOOT
     ========================================================================== */
  AO.paintIcons();
  AO.initTooltips(document);
  // Still signed in in this tab: reload the state and go straight in
  if (AO.getToken()) ADM.load().done(enterApp).fail(function () { AO.setToken(null); });
});
