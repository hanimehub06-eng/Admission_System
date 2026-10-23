/* ==========================================================================
   View: Audit log (#/audit) — read-only trail of every change.
   Admission personnel can search and export it, never edit or delete it.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  var f = { q: "", who: "", page: 1 };
  var SIZE = 20;

  function rows() {
    return ADM.auditTrail().filter(function (r) {
      if (f.who && r.by !== f.who) return false;
      if (f.q && (r.text + " " + r.target).toLowerCase().indexOf(f.q.toLowerCase()) === -1) return false;
      return true;
    });
  }

  function render($el) {
    var ui = ADM.ui;
    var all = ADM.auditTrail();
    var people = {}; all.forEach(function (r) { people[r.by] = 1; });
    var list = rows();
    f.page = ui.clampPage(f.page, list.length, SIZE);
    var page = list.slice((f.page - 1) * SIZE, f.page * SIZE);

    $el.html(
      '<div class="page-title page-title-inline" data-reveal><div><h1>Audit log</h1>' +
      '</div>' +
      '<button class="btn btn-ghost" type="button" id="export-audit">' + icon("download") + "Export CSV</button></div>" +
      '<div class="info-note" data-reveal>' + icon("lock") + "<p><strong>Read-only.</strong> Audit entries can't be edited or deleted by admission personnel.</p></div>" +
      '<section class="table-card" data-reveal><div class="table-toolbar">' +
      '<div class="search-field">' + icon("search") + '<input type="search" class="form-control" id="audit-q" placeholder="Search actions or applicants" aria-label="Search the audit log" value="' + esc(f.q) + '"></div>' +
      '<select class="form-select" id="audit-who" aria-label="Filter by person"><option value="">Everyone</option>' +
      Object.keys(people).sort().map(function (p) { return '<option' + (f.who === p ? " selected" : "") + ">" + esc(p) + "</option>"; }).join("") + "</select></div>" +
      (page.length ? '<div class="table-responsive"><table class="table data-table"><thead><tr><th style="width:190px">When</th><th style="width:150px">By</th><th>Action</th><th>Record</th></tr></thead><tbody>' +
        page.map(function (r) {
          return '<tr><td class="text-muted">' + esc(ui.dt(r.at)) + "</td><td>" + esc(r.by) + "</td><td>" + esc(r.text) + "</td><td>" +
            (r.applicantId ? '<a class="text-link" href="#/applicants/' + esc(r.applicantId) + '">' + esc(r.target) + "</a>" : '<span class="text-muted">' + esc(r.target) + "</span>") + "</td></tr>";
        }).join("") + "</tbody></table></div>" + ui.pager(list.length, f.page, SIZE)
        : ui.empty("history", "No matching entries")) +
      "</section>"
    );
  }

  var t;
  $(document).on("input", "#audit-q", function () {
    var v = this.value; clearTimeout(t);
    t = setTimeout(function () { f.q = v.trim(); f.page = 1; ADM.refresh(); $("#audit-q").trigger("focus")[0].setSelectionRange(v.length, v.length); }, 250);
  });
  $(document).on("change", "#audit-who", function () { f.who = this.value; f.page = 1; ADM.refresh(); });
  $(document).on("click", "#view-audit [data-page]", function () { f.page = +$(this).data("page"); ADM.refresh(); });
  $(document).on("click", "#export-audit", function () {
    ADM.downloadCSV("audit-log-" + AO.toISO(AO.today()) + ".csv", ["When", "By", "Action", "Record"], rows().map(function (r) { return [r.at.replace("T", " "), r.by, r.text, r.target]; }));
  });

  ADM.views.audit = { title: "Audit log", render: render };
})(jQuery);
