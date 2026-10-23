/* ==========================================================================
   PLP Online Admission — Shared front-end core (jQuery 3.7 + Bootstrap 5.3)
   Loaded before assets/applicant/js/applicant.js and the admin scripts.
   Exposes window.AO: icons, storage, formatting, toast, form validation,
   loading buttons, and the sidebar / reveal behaviour shared by both portals.
   ========================================================================== */
(function ($) {
  "use strict";

  /* ==========================================================================
     ICONS (inline SVG, inherit currentColor)
     ========================================================================== */
  var ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/>',
    file: '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
    folder: '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2.5h8.5A1.5 1.5 0 0 1 21 9v9.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c1.2-3.4 3.6-5 6.5-5s5.3 1.6 6.5 5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 15c1.9.6 3 2.3 3.5 5"/>',
    idcard: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.7-1.4 1.8-2 3.2-2s2.5.6 3.2 2M14.5 10h4M14.5 13.5h3"/>',
    stamp: '<path d="M9 3h6l-1 6h-4z"/><path d="M4 13h16v4H4zM6 21h12"/>',
    shield: '<path d="M12 3l8 3v6c0 4.5-3.3 7.8-8 9-4.7-1.2-8-4.5-8-9V6z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
    scroll: '<path d="M7 3h11a2 2 0 0 1 2 2v2h-4"/><path d="M16 7v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2h10"/><path d="M7 3a2 2 0 0 0-2 2v12M9 8h4M9 12h4"/>',
    camera: '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
    wallet: '<path d="M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a2 2 0 0 1 2-2h11v3"/><path d="M15.5 13.5h.01"/>',
    book: '<path d="M4 5a2 2 0 0 1 2-2h13v15H6a2 2 0 0 0-2 2z"/><path d="M4 20a2 2 0 0 0 2 1h13"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>',
    pencil: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    award: '<circle cx="12" cy="9" r="6"/><path d="M8.5 14 7 21l5-3 5 3-1.5-7"/>',
    logout: '<path d="M15 4h4v16h-4"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    arrowR: '<path d="M5 12h14M13 6l6 6-6 6"/>',
    arrowL: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
    upload: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v4h16v-4"/>',
    send: '<path d="M21 3 10 14"/><path d="M21 3l-7 18-4-7-7-4z"/>',
    refresh: '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/>',
    trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    eyeOff: '<path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    pin: '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
    lock: '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    help: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>',
    ticket: '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v8a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2z"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
    // Admission office
    grid: '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
    chart: '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
    calendar: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    inbox: '<path d="M3 13l2.5-8h13L21 13v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/><path d="M3 13h5l1.5 2.5h5L16 13h5"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7 7 0 0 0-2-1.2L14 3h-4l-.5 2.6a7 7 0 0 0-2 1.2l-2.4-1-2 3.4 2 1.6A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.6 2 3.4 2.4-1a7 7 0 0 0 2 1.2L10 21h4l.5-2.6a7 7 0 0 0 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2z"/>',
    history: '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5M12 8v4l3 2"/>',
    download: '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 20h16"/>',
    printer: '<path d="M7 8V3h10v5"/><rect x="3" y="8" width="18" height="9" rx="2"/><path d="M7 14h10v7H7z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    x: '<path d="M6 6l12 12M18 6 6 18"/>',
    alert: '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
    clipboard: '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1M9 11h6M9 15h4"/>',
    flag: '<path d="M5 21V4M5 4h11l-2 4 2 4H5"/>',
    filter: '<path d="M4 5h16l-6 7.5V19l-4 2v-8.5z"/>'
  };
  var STAR = '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 1.5c.7 5.6 3.4 8.6 10.5 10.5-7.1 1.9-9.8 4.9-10.5 10.5-.7-5.6-3.4-8.6-10.5-10.5C8.6 10.1 11.3 7.1 12 1.5z"/></svg>';

  function icon(name) {
    if (name === "star") return STAR;
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || "") + "</svg>";
  }
  function paintIcons(root) {
    $(root || document).find("[data-icon]").each(function () {
      if (!this.firstElementChild) this.innerHTML = icon(this.getAttribute("data-icon"));
    });
  }

  /* ==========================================================================
     HELPERS
     ========================================================================== */
  function store(kind) { try { return kind === "session" ? window.sessionStorage : window.localStorage; } catch (e) { return null; } }
  function readJSON(kind, key) { try { var s = store(kind); var v = s && s.getItem(key); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
  function writeJSON(kind, key, value) { try { var s = store(kind); if (s) s.setItem(key, JSON.stringify(value)); } catch (e) { /* storage blocked */ } }
  function removeKey(kind, key) { try { var s = store(kind); if (s) s.removeItem(key); } catch (e) { /* storage blocked */ } }

  function esc(str) { return String(str == null ? "" : str).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function parseDate(iso) { var p = iso.slice(0, 10).split("-"); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function today() { var d = new Date(); return new Date(d.getFullYear(), d.getMonth(), d.getDate()); }
  function toISO(d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
  function fmt(iso, opts) { return parseDate(iso).toLocaleDateString("en-US", opts || { month: "short", day: "numeric", year: "numeric" }); }
  function daysUntil(iso) { return Math.round((parseDate(iso) - today()) / 86400000); }
  function fileSize(bytes) { return bytes > 1048576 ? (bytes / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(bytes / 1024)) + " KB"; }
  function reducedMotion() { return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches; }

  /* Count a number up or down inside an element */
  function animateCount($el, to) {
    var from = parseInt($el.text(), 10) || 0;
    if (from === to || reducedMotion()) { $el.text(to); return; }
    $({ v: from }).animate({ v: to }, { duration: 700, easing: "swing", step: function (now) { $el.text(Math.round(now)); }, complete: function () { $el.text(to); } });
  }

  /* ---------- Toast (Bootstrap; needs #portal-toast in the page) ---------- */
  var toast = null;
  function showToast(message) {
    var el = document.getElementById("portal-toast");
    if (!el) return;
    toast = toast || bootstrap.Toast.getOrCreateInstance(el, { delay: 3200 });
    $("#toast-message").text(message);
    toast.show();
  }

  /* ---------- Button ripple ---------- */
  $(document).on("pointerdown", ".btn", function (e) {
    if (reducedMotion()) return;
    var rect = this.getBoundingClientRect();
    var size = Math.max(rect.width, rect.height);
    $('<span class="ripple"></span>').css({ width: size, height: size, left: e.clientX - rect.left - size / 2, top: e.clientY - rect.top - size / 2 })
      .appendTo(this).on("animationend", function () { $(this).remove(); });
  });

  /* ---------- Loading state on a button ---------- */
  function withLoading($btn, ms, done) {
    $btn.addClass("loading").prop("disabled", true);
    setTimeout(function () { $btn.removeClass("loading").prop("disabled", false); done(); }, ms);
  }

  /* ---------- Show / hide password ---------- */
  $(document).on("click", ".pw-toggle", function () {
    var $input = $("#" + $(this).data("target"));
    var show = $input.attr("type") === "password";
    $input.attr("type", show ? "text" : "password");
    $(this).attr({ "aria-pressed": show, "aria-label": show ? "Hide password" : "Show password" }).html(icon(show ? "eyeOff" : "eye"));
  });

  /* ==========================================================================
     VALIDATION (Bootstrap .is-invalid + .invalid-feedback)
     ========================================================================== */
  function labelFor(el) {
    var txt = $("label[for='" + el.id + "']").first().clone().children().remove().end().text().trim();
    return txt || "This field";
  }
  function setError($input, msg) {
    var errId = $input.attr("id") + "-error";
    $input.addClass("is-invalid").attr({ "aria-invalid": "true", "aria-describedby": errId });
    $("#" + errId).text(msg).addClass("d-block");
  }
  function clearError($input) {
    $input.removeClass("is-invalid").removeAttr("aria-invalid");
    $("#" + $input.attr("id") + "-error").text("").removeClass("d-block");
  }
  function messageFor(el) {
    var v = el.validity;
    if (el.type === "checkbox" && v.valueMissing) return "Please tick this box to continue.";
    if (v.valueMissing) return labelFor(el) + " is required.";
    if (v.typeMismatch && el.type === "email") return "Enter a valid email, like name@email.com.";
    if (v.patternMismatch) return el.getAttribute("data-msg-pattern") || "Check the format of this field.";
    if (v.rangeUnderflow || v.rangeOverflow || v.stepMismatch) return el.getAttribute("data-msg-range") || "Enter a value in the allowed range.";
    return "Check this field.";
  }
  function validateFields($scope) {
    var ok = true;
    $scope.find("input, select, textarea").filter(":not([type=file]):not([readonly])").each(function () {
      if (!this.id) return;
      if (this.minLength > 0 && this.value && this.value.length < this.minLength) { setError($(this), "Use at least " + this.minLength + " characters."); ok = false; return; }
      if (!this.checkValidity()) { setError($(this), messageFor(this)); ok = false; } else clearError($(this));
    });
    if (!ok) $scope.find(".is-invalid").first().trigger("focus");
    return ok;
  }
  $(document).on("input change", ".is-invalid", function () { if (this.checkValidity()) clearError($(this)); });

  /* ==========================================================================
     SHELL: sidebar, nav indicator, reveal, tooltips
     ========================================================================== */
  /* Each nav with an indicator tracks its own active link; hidden when none */
  function moveNavIndicator() {
    $(".nav-indicator").each(function () {
      var $active = $(this).parent().find(".nav-link.active");
      $(this).css("opacity", $active.length ? 1 : 0);
      if ($active.length) $(this).css("transform", "translateY(" + $active[0].offsetTop + "px)");
    });
  }
  function closeSidebar() {
    $("#sidebar").removeClass("open");
    $("#sidebar-backdrop").removeClass("show");
    $(".menu-toggle").attr("aria-expanded", "false");
  }
  function revealView($view) {
    $view.find("[data-reveal]").each(function (i) {
      $(this).removeClass("reveal-in");
      void this.offsetWidth;
      $(this).css("--d", (i * 80) + "ms").addClass("reveal-in");
    });
  }
  function initTooltips(root) {
    $(root).find('[data-bs-toggle="tooltip"]').each(function () { bootstrap.Tooltip.getOrCreateInstance(this); });
  }

  /* ==========================================================================
     API (Laravel, JWT bearer token kept for this browser tab only)
     ========================================================================== */
  var TOKEN_KEY = "admitone.token";
  function getToken() { var t = readJSON("session", TOKEN_KEY); return t && t.token; }
  function setToken(token) { if (token) writeJSON("session", TOKEN_KEY, { token: token }); else removeKey("session", TOKEN_KEY); }

  /* data: plain object (sent as JSON) or FormData (file upload). Returns a jqXHR. */
  function api(method, url, data) {
    var form = typeof FormData !== "undefined" && data instanceof FormData;
    var token = getToken();
    return $.ajax({
      url: url, method: method, dataType: "json",
      headers: $.extend({ Accept: "application/json" }, token ? { Authorization: "Bearer " + token } : {}),
      data: form ? data : (data == null ? undefined : JSON.stringify(data)),
      contentType: form ? false : "application/json", processData: false
    }).fail(function (xhr) {
      if (xhr.status === 401 && token) { setToken(null); $(document).trigger("ao:signedout"); }
    });
  }

  /* The message to show for a failed call: the server's, or a generic one */
  function apiError(xhr) {
    var r = xhr && xhr.responseJSON;
    if (r && r.errors) { var first = Object.keys(r.errors)[0]; if (first) return r.errors[first][0]; }
    if (r && r.message) return r.message;
    if (xhr && xhr.status === 0) return "Can't reach the server. Check your connection and try again.";
    return "Something went wrong. Please try again.";
  }

  /* Loading state on a button while a call runs */
  function busy($btn, promise) {
    $btn.addClass("loading").prop("disabled", true);
    return promise.always(function () { $btn.removeClass("loading").prop("disabled", false); });
  }

  $(document).on("click", ".menu-toggle", function () {
    var open = !$("#sidebar").hasClass("open");
    $("#sidebar").toggleClass("open", open);
    $("#sidebar-backdrop").toggleClass("show", open);
    $(this).attr("aria-expanded", open ? "true" : "false");
  });
  $(document).on("click", "#sidebar-backdrop", closeSidebar);
  $(window).on("resize", moveNavIndicator);

  window.AO = {
    icon: icon, STAR: STAR, paintIcons: paintIcons,
    readJSON: readJSON, writeJSON: writeJSON, removeKey: removeKey,
    esc: esc, parseDate: parseDate, today: today, toISO: toISO, fmt: fmt, daysUntil: daysUntil,
    fileSize: fileSize, reducedMotion: reducedMotion, animateCount: animateCount,
    showToast: showToast, withLoading: withLoading,
    setError: setError, clearError: clearError, validateFields: validateFields,
    moveNavIndicator: moveNavIndicator, closeSidebar: closeSidebar, revealView: revealView, initTooltips: initTooltips,
    api: api, apiError: apiError, busy: busy, getToken: getToken, setToken: setToken
  };
})(jQuery);
