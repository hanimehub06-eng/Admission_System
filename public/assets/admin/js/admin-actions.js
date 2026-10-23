/* ==========================================================================
   PLP Online Admission — Admission Office: actions
   Every change an admission officer makes goes through here. Each action
   updates the screen at once, then sends the change to the Laravel API,
   which saves it and writes the audit trail. The server's copy replaces the
   local one; if the server refuses, its message is shown and the state is
   reloaded so the screen matches the server again.
   Needs admin-store.js. Adds ADM.actions.
   ========================================================================== */
(function ($) {
  "use strict";

  var ADM = window.ADM;

  function docTitle(key) { return ADM.findType(key).title; }

  function stamp(doc) { doc.reviewedBy = ADM.STAFF.name; doc.reviewedAt = ADM.nowISO(); }

  /* ---------- Sending changes ---------- */
  // One at a time, in order: e.g. "permit checked" must be saved before "present"
  var queue = $.Deferred().resolve().promise();

  function reload() { return ADM.load().done(function () { if (ADM.refresh) ADM.refresh(); }); }

  function send(method, path, data, a) {
    ADM.invalidate();                          // the action just changed local data
    var run = function () {
      return AO.api(method, "/api/admin/" + path, data)
        .done(function (res) {
          if (a && res && res.data && res.data.id === a.id) ADM.replaceApplicant(a, res.data);
        })
        .fail(function (xhr) {
          if (xhr.status === 401) return;          // signed out: admin.js shows the sign-in screen
          AO.showToast(AO.apiError(xhr));
          reload();
        });
    };
    queue = queue.then(run, run);
    return queue;
  }
  function applicantPath(a, rest) { return "applicants/" + encodeURIComponent(a.id) + "/" + rest; }

  ADM.reload = reload;

  ADM.actions = {
    verifyDoc: function (a, key) {
      var doc = a.docs[key];
      if (!doc || doc.status === "missing") return false;
      doc.status = "verified";
      delete doc.remark;
      stamp(doc);
      ADM.logApplicant(a, "Verified \"" + docTitle(key) + "\"");
      send("PATCH", applicantPath(a, "documents/" + key), { status: "verified" }, a);
      return true;
    },

    resubmitDoc: function (a, key, remark) {
      var doc = a.docs[key];
      if (!doc || doc.status === "missing") return false;
      doc.status = "resubmit";
      doc.remark = remark;
      stamp(doc);
      ADM.logApplicant(a, "Marked \"" + docTitle(key) + "\" as requires resubmission: " + remark);
      send("PATCH", applicantPath(a, "documents/" + key), { status: "resubmit", remark: remark }, a);
      return true;
    },

    // Verify every document that is waiting for review
    verifyAllPending: function (a) {
      var n = 0;
      ADM.requiredDocs(a).forEach(function (d) {
        var doc = a.docs[d.key];
        if (doc && doc.status === "pending") { doc.status = "verified"; stamp(doc); n++; }
      });
      if (n) {
        ADM.logApplicant(a, "Verified " + n + " document" + (n === 1 ? "" : "s"));
        send("POST", applicantPath(a, "documents/verify-pending"), {}, a);
      }
      return n;
    },

    // Issues the exam permit
    approveForExam: function (a, sessionId) {
      if (!ADM.canApprove(a)) return false;
      var s = ADM.session(sessionId);
      if (!s || ADM.sessionRoster(s.id).length >= s.capacity) return false;
      a.exam = { sessionId: s.id, permit: "EX-27-" + a.id.slice(-5), attendance: null, permitChecked: false, formChecked: false };
      ADM.logApplicant(a, "Approved for the entrance exam · " + ADM.sessionLabel(s));
      send("POST", applicantPath(a, "approve"), { sessionId: s.id }, a);
      return true;
    },

    // Move an absent examinee to another session
    reschedule: function (a, sessionId) {
      var s = ADM.session(sessionId);
      if (!a.exam || a.result || !s || ADM.sessionRoster(s.id).length >= s.capacity) return false;
      a.exam.sessionId = s.id;
      a.exam.attendance = null;
      a.exam.permitChecked = a.exam.formChecked = false;
      ADM.logApplicant(a, "Rescheduled the entrance exam to " + ADM.sessionLabel(s));
      send("POST", applicantPath(a, "reschedule"), { sessionId: s.id }, a);
      return true;
    },

    setCheck: function (a, field, value) {
      if (!a.exam || a.result) return;
      a.exam[field] = value;
      var body = {};
      body[field] = value;
      send("PATCH", applicantPath(a, "exam"), body, a);
    },
    setAttendance: function (a, value) {
      if (!a.exam || a.result) return false;
      if (value === "present" && !(a.exam.permitChecked && a.exam.formChecked)) return false;
      a.exam.attendance = value;
      ADM.logApplicant(a, value === "present" ? "Marked present at the exam venue (permit and form checked)" : value === "absent" ? "Marked absent from the entrance exam" : "Cleared exam attendance");
      send("PATCH", applicantPath(a, "exam"), { attendance: value }, a);
      return true;
    },

    // Score stays internal: it is never sent to the applicant
    recordResult: function (a, score, decision) {
      if (!a.exam || a.exam.attendance !== "present" || (a.result && a.result.released)) return false;
      var changed = !!a.result;
      a.result = { score: score, decision: decision, recordedBy: ADM.STAFF.name, recordedAt: ADM.nowISO(), released: false };
      ADM.logApplicant(a, (changed ? "Updated" : "Recorded") + " exam result: " + (decision === "passed" ? "Passed" : "Did not pass"));
      send("POST", applicantPath(a, "result"), { score: score, decision: decision }, a);
      return true;
    },

    // Notifies the applicants
    releaseResults: function (list) {
      var released = [];
      list.forEach(function (a) {
        if (a.result && !a.result.released) {
          a.result.released = true;
          a.result.releasedAt = ADM.nowISO();
          if (a.result.decision === "passed" && !a.medical) a.medical = { files: {} };
          ADM.logApplicant(a, "Released the exam result to the applicant");
          released.push(a);
        }
      });
      var n = released.length;
      if (n) {
        ADM.logOffice("Released " + n + " exam result" + (n === 1 ? "" : "s") + " through the system");
        send("POST", "results/release", { ids: released.map(function (a) { return a.id; }) }).done(function (res) {
          (res.data || []).forEach(function (fresh) { var a = ADM.applicant(fresh.id); if (a) ADM.replaceApplicant(a, fresh); });
        });
      }
      return n;
    },

    // Medical requirements are checked and approved in the clinic portal
    // (assets/nurse/js/nurse.js, /api/clinic). The office sees them read-only.

    createSession: function (date, batch, venue, capacity) {
      var dup = ADM.state.sessions.filter(function (s) { return s.date === date && s.batch === batch && s.venue.toLowerCase() === venue.toLowerCase(); })[0];
      if (dup) return null;
      var max = ADM.state.sessions.reduce(function (m, s) { return Math.max(m, +s.id.slice(1)); }, 0);
      var s = { id: "S" + (max + 1), date: date, batch: batch, venue: venue, capacity: capacity };
      ADM.state.sessions.push(s);
      ADM.logOffice("Created exam session " + ADM.sessionLabel(s) + " at " + venue + " (" + capacity + " seats)");
      // The server assigns the real id: take it before anyone is added to the session
      send("POST", "exam-sessions", { date: date, batch: batch, venue: venue, capacity: capacity }).done(function (res) {
        if (res && res.data) { s.id = res.data.id; ADM.invalidate(); }
      });
      return s;
    },

    setSlots: function (code, slots) {
      var p = ADM.program(code);
      if (p.slots === slots) return;
      ADM.logOffice("Changed " + code + " slots from " + p.slots + " to " + slots);
      p.slots = slots;
      send("PATCH", "programs/" + encodeURIComponent(code), { slots: slots });
    },
    addProgram: function (code, name, slots) {
      code = code.toUpperCase();
      if (ADM.state.programs.some(function (p) { return p.code === code; })) return false;
      ADM.state.programs.push({ code: code, name: name, slots: slots });
      ADM.logOffice("Added program " + code + " · " + name + " (" + slots + " slots)");
      send("POST", "programs", { code: code, name: name, slots: slots });
      return true;
    },

    toggleRequirement: function (key, active) {
      var list = ADM.state.settings.inactiveDocs;
      var i = list.indexOf(key);
      if (active && i !== -1) list.splice(i, 1);
      if (!active && i === -1) list.push(key);
      ADM.logOffice((active ? "Required" : "Stopped requiring") + " \"" + docTitle(key) + "\"");
      send("PATCH", "requirements/" + encodeURIComponent(key), { active: active });
    },
    setDeadline: function (iso) {
      if (ADM.state.settings.deadline === iso) return;
      ADM.logOffice("Changed the application deadline to " + AO.fmt(iso, { month: "long", day: "numeric", year: "numeric" }));
      ADM.state.settings.deadline = iso;
      send("PATCH", "admission-period", { deadline: iso });
    }
  };
})(jQuery);
