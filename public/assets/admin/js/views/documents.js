/* ==========================================================================
   Views: Document review
   #/documents      list of applicants with documents waiting (names only),
                    oldest submission first
   #/documents/:id  that applicant's files: view, verify or send back
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;
  var esc = AO.esc, icon = AO.icon;
  var f = { q: "", sort: "oldest", page: 1 };

  function waiting(a) { return a.submittedAt && !a.exam && ADM.docSummary(a).pending > 0; }

  function queue() {
    return ADM.state.applicants.filter(function (a) {
      if (!waiting(a)) return false;
      return !f.q || (ADM.fullName(a) + " " + a.id + " " + a.lrn).toLowerCase().indexOf(f.q.toLowerCase()) !== -1;
    }).sort(function (x, y) {
      return f.sort === "newest" ? (x.submittedAt < y.submittedAt ? 1 : -1) : (x.submittedAt < y.submittedAt ? -1 : 1);
    });
  }

  /* ==========================================================================
     LIST
     ========================================================================== */
  function renderList($el) {
    var ui = ADM.ui;
    var list = queue();
    f.page = ui.clampPage(f.page, list.length);
    var rows = list.slice((f.page - 1) * ADM.PAGE_SIZE, f.page * ADM.PAGE_SIZE);

    $el.html(
      '<div class="page-title" data-reveal><h1>Document review</h1></div>' +
      '<section class="table-card" data-reveal><div class="table-toolbar">' +
      '<div class="search-field">' + icon("search") + '<input type="search" class="form-control" id="doc-q" placeholder="Search name, ID or LRN" aria-label="Search applicant" value="' + esc(f.q) + '"></div>' +
      '<select class="form-select" id="doc-sort" aria-label="Sort"><option value="oldest"' + (f.sort === "oldest" ? " selected" : "") + '>Oldest first</option><option value="newest"' + (f.sort === "newest" ? " selected" : "") + ">Newest first</option></select>" +
      "</div>" +
      (rows.length ?
        '<div class="table-responsive"><table class="table data-table"><thead><tr><th>Applicant</th><th>Submitted</th><th>To review</th><th><span class="visually-hidden">Open</span></th></tr></thead><tbody>' +
        rows.map(function (a) {
          var s = ADM.docSummary(a);
          var wd = ADM.workingDaysSince(a.submittedAt);
          return '<tr class="row-link" data-href="#/documents/' + esc(a.id) + '" tabindex="0">' +
            "<td>" + ui.applicantCell(a) + "</td>" +
            "<td>" + AO.fmt(a.submittedAt) + '<small class="d-block' + (wd > ADM.REVIEW_SLA_DAYS ? " text-danger-soft" : " text-muted") + '">' + wd + " working day" + (wd === 1 ? "" : "s") + " waiting</small></td>" +
            "<td>" + s.pending + " file" + (s.pending === 1 ? "" : "s") + "</td>" +
            '<td class="text-end">' + icon("arrowR") + "</td></tr>";
        }).join("") + "</tbody></table></div>" + ui.pager(list.length, f.page)
        : ui.empty("check", f.q ? "Nothing matches" : "All caught up", f.q ? "Clear the search to see everyone." : "No applicant has documents waiting for review.")) +
      "</section>"
    );
  }

  /* ==========================================================================
     ONE APPLICANT'S FILES
     ========================================================================== */
  // Documents or Profile: staff compare the files with the details the applicant typed
  var reviewTab = "documents", reviewId = null;
  function nextInQueue(after) { return queue().filter(function (a) { return a.id !== after; })[0]; }

  function renderReview($el, id) {
    var ui = ADM.ui;
    var a = ADM.applicant(id);
    var back = '<a class="back-link" href="#/documents" data-reveal>' + icon("arrowL") + "Document review</a>";
    if (!a || !a.submittedAt) {
      $el.html(back + '<section class="card-soft">' + ui.empty("search", "Nothing to review", "This applicant hasn't submitted documents.") + "</section>");
      return;
    }
    var s = ADM.docSummary(a);
    var editable = !a.exam;
    var next = nextInQueue(a.id);
    if (reviewId !== id) { reviewTab = "documents"; reviewId = id; }
    var outcome = "";
    if (!s.pending && ADM.canApprove(a)) {
      outcome = '<div class="rq-outcome ok">' + icon("check") + "<span><strong>All " + s.total + " documents verified.</strong> Approve them for the entrance exam to issue the permit.</span>" +
        '<button class="btn btn-brand btn-sm" type="button" data-rq-approve="' + esc(a.id) + '">' + icon("calendar") + "Approve for exam</button></div>";
    } else if (!s.pending && s.resubmit) {
      outcome = '<div class="rq-outcome warn">' + icon("flag") + "<span><strong>Sent back.</strong> " + s.resubmit + " document" + (s.resubmit === 1 ? "" : "s") + " to fix. The applicant comes back to the list after uploading again.</span></div>";
    }

    $el.html(back +
      '<nav class="tabs" role="tablist" data-reveal>' + [["documents", "Documents"], ["profile", "Profile"]].map(function (t) {
        return '<button type="button" role="tab" class="tab' + (reviewTab === t[0] ? " active" : "") + '" aria-selected="' + (reviewTab === t[0]) + '" data-review-tab="' + t[0] + '">' + t[1] + "</button>";
      }).join("") + "</nav>" +
      '<section class="table-card" data-reveal><div class="table-toolbar">' +
      '<div class="who">' + ui.avatar(a) + '<span><strong>' + esc(ADM.fullName(a)) + '</strong><small>' + esc(a.id) + " · submitted " + AO.fmt(a.submittedAt) + "</small></span></div>" +
      '<div class="d-flex gap-2 ms-auto flex-wrap">' +
      '<span class="text-muted small align-self-center">' + s.verified + " of " + s.total + " verified</span>" +
      (editable && s.pending ? '<button class="btn btn-ok btn-sm" type="button" data-rq-verify-all="' + esc(a.id) + '">' + icon("check") + "Verify all " + s.pending + "</button>" : "") +
      (!s.pending && next ? '<a class="btn btn-ghost btn-sm" href="#/documents/' + esc(next.id) + '">Next applicant' + icon("arrowR") + "</a>" : "") +
      "</div></div>" +
      (reviewTab === "documents" ? outcome + '<ul class="review-list">' + ui.docItems(a, editable) + "</ul></section>"
        : "</section>" + '<div class="mt-3" data-reveal>' + ADM.views.applicant.profile(a) + "</div>")
    );
  }

  /* ==========================================================================
     EVENTS
     ========================================================================== */
  function afterChange(a, msg) {
    ADM.refresh();
    var s = ADM.docSummary(a);
    AO.showToast(!s.pending && ADM.canApprove(a) ? ADM.fullName(a) + " is fully verified. Approve them for the exam." : msg);
  }

  $(document).on("click", "#view-docreview [data-review-tab]", function () { reviewTab = $(this).data("review-tab"); ADM.refresh(); });

  var t;
  $(document).on("input", "#doc-q", function () {
    var v = this.value; clearTimeout(t);
    t = setTimeout(function () { f.q = v.trim(); f.page = 1; ADM.refresh(); $("#doc-q").trigger("focus")[0].setSelectionRange(v.length, v.length); }, 250);
  });
  $(document).on("change", "#doc-sort", function () { f.sort = this.value; f.page = 1; ADM.refresh(); });
  $(document).on("click", "#view-documents [data-page]", function () { f.page = +$(this).data("page"); ADM.refresh(); });

  $(document).on("click", "#view-docreview [data-doc-verify], #docModal [data-q-verify]", function () {
    var a = ADM.applicant($(this).data("id")), key = $(this).data("doc-verify") || $(this).data("q-verify");
    if (ADM.actions.verifyDoc(a, key)) afterChange(a, "Document verified.");
  });
  $(document).on("click", "#view-docreview [data-doc-resubmit], #docModal [data-q-resubmit]", function () {
    var a = ADM.applicant($(this).data("id")), key = $(this).data("doc-resubmit") || $(this).data("q-resubmit");
    ADM.ui.askRemark(a, key, function (remark) {
      if (ADM.actions.resubmitDoc(a, key, remark)) afterChange(a, "Sent back to " + a.firstName + " with your remark.");
    });
  });
  $(document).on("click", "#view-docreview [data-doc-reject]", function () {
    var a = ADM.applicant($(this).data("id")), key = $(this).data("doc-reject");
    ADM.ui.askRemark(a, key, function (remark) {
      if (ADM.actions.rejectDoc(a, key, remark)) afterChange(a, "Document rejected. " + a.firstName + " was told why.");
    });
  });
  $(document).on("click", "#view-docreview [data-doc-view]", function () {
    var a = ADM.applicant($(this).data("id")), key = $(this).data("doc-view");
    var doc = a.docs[key];
    var id = ' data-id="' + esc(a.id) + '"';
    ADM.ui.previewDoc(a, key, '<button type="button" class="btn btn-ghost me-auto" data-bs-dismiss="modal">Close</button>' +
      (!a.exam && doc.status !== "resubmit" ? '<button type="button" class="btn btn-warn" data-q-resubmit="' + key + '"' + id + ' data-bs-dismiss="modal">' + icon("flag") + "Resubmit</button>" : "") +
      (!a.exam && doc.status !== "verified" ? '<button type="button" class="btn btn-ok" data-q-verify="' + key + '"' + id + ' data-bs-dismiss="modal">' + icon("check") + "Verify</button>" : ""));
  });
  $(document).on("click", "[data-rq-verify-all]", function () {
    var a = ADM.applicant($(this).data("rq-verify-all"));
    var n = ADM.actions.verifyAllPending(a);
    afterChange(a, n + " document" + (n === 1 ? "" : "s") + " verified.");
  });
  $(document).on("click", "[data-rq-approve]", function () {
    var a = ADM.applicant($(this).data("rq-approve"));
    ADM.ui.askSession(a, "approve", function (sid) {
      if (ADM.actions.approveForExam(a, sid)) {
        ADM.refresh();
        AO.showToast(ADM.fullName(a) + " approved. Exam permit " + a.exam.permit + " issued.");
      } else AO.showToast("That session is full. Choose another.");
    });
  });

  ADM.views.documents = { title: "Document review", render: renderList };
  ADM.views.docreview = {
    title: function (id) { var a = ADM.applicant(id); return a ? ADM.fullName(a) : "Document review"; },
    render: renderReview
  };
})(jQuery);
