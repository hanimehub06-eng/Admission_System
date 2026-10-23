# PLP Online Admission System (OAS) — Backend Development Plan

**Source:** *Solution Design Document for the PLP Online Admission System*, v0.1 (updated draft, received 2026-10-20; the version table still says 0.1 / 10-17-2026)
**Stack (paper §7.2):** Laravel (PHP) on one Amazon EC2 server · Nginx communication layer → PHP-FPM · MySQL 8 · JWT + 2FA + RBAC · existing HTML/CSS/JS/Bootstrap/jQuery frontend (AJAX carries the JWT)
**External systems (paper §9):** Document Management System (DMS) = system of record for all files and records · Enrollment System reads admitted applicants **from the DMS**
**Scope of this plan:** Backend only. **No UI changes.**

---

## What changed from the previous plan

The updated paper widens the scope considerably. Summary for the team:

| Area | Previous plan | Updated plan (per new paper) |
|---|---|---|
| Requirement IDs | FR-01…FR-13, NFR-01…NFR-03 | **FR-01…FR-17, NFR-01…NFR-09** (renumbered; see §9) |
| Roles | Applicant, Staff, Admin | **Applicant, Admission Personnel, College Dean, Clinic, System Administrator** |
| File storage | Private Laravel disk | **Files go straight to the DMS** (sync upload); OAS keeps only DMS document IDs |
| Record storage | MySQL only | MySQL is the **working copy**; every record is also synced to the DMS (queued) |
| Enrollment | Not covered | OAS marks applicants **Admitted** in the DMS; Enrollment reads from the DMS |
| Medical (was FR-13) | Deferred (D5) | **In scope** as FR-10, verified by Clinic staff |
| Notifications | In-app only (D4) | **Email** via Gmail SMTP with Brevo failover (FR-12) |
| Interview | — | **New** FR-17: interview schedule and outcome, recorded by the College Dean |
| Acceptance | Letter only | Letter **+ slot acceptance before a deadline, automatic forfeit** (FR-09) |
| Exam permit | PDF | PDF **with QR code**; staff scan it for attendance (FR-07) |
| Security | JWT + RBAC | **+ 2FA** (staff always; applicants on new devices and slot acceptance), TLS 1.2+ |
| Admin duties | Undecided owner (D7) | **System Administrator** role (admin pages + SSH) |
| Document checks | Manual only (D3) | Manual is the baseline; automated pre-checks are FR-16 (**Could**) |
| Ops | Not covered | Nightly encrypted backups to Google Drive, Laravel Pulse, Log Viewer, UptimeRobot, GitHub Actions |
| Online exam | Out of scope | **Still out of scope** (exam is on paper, in person; §3.4) |

---

## 0. Instructions for the Implementing Agent

This file is meant to be read by Claude inside the project. Before writing any code:

1. **Inspect the project first.** Find the existing UI (views, HTML/JS, routes, forms) and any existing backend. Read it; don't assume.
2. **Do Phase 0 (UI audit) and produce the matrix before building.** Cover all five roles.
3. **Only wire backend to UI that exists.** If a screen exists for a feature (including admin, Clinic or Dean screens), build the backend for it. If none exists, build API-only, as stated per phase.
4. **Never modify UI files** (markup, CSS, client-side JS, layout, text). If something looks wrong or incomplete, report it; don't fix it.
5. **The entrance exam is pen-and-paper, taken at a venue.** The system schedules it, issues permits, records attendance and results; it never delivers or scores an exam.
6. **The DMS API is not final** (paper §9.3: "to be agreed with the DMS team"). Code against the `DmsClient` interface (§4) with a fake driver until the real contract is available.
7. **Where the paper contradicts itself, use the default in §3** and don't guess further.

---

## 1. Ground Rules

1. **Backend-only.** The existing UI is incomplete. We only connect backend logic to UI parts that **already exist**. We do not add, remove, restyle, or re-layout any page, form, button, or component.
2. **UI is the contract.** Where a UI element exists, the backend adapts to it (field names, form structure, expected JSON keys, URLs).
3. **No UI = no wiring.** Features with no existing UI are built as API-only and listed in §9 as "backend ready, UI pending".
4. **Do not invent unresolved scope.** Open decisions (§3) are built behind flags, config values or stubs.
5. **Security and privacy rules are non-negotiable:**
   - Exam scores and interview grades are **never** sent to applicants (§8.3, Restricted class).
   - Medical files can be opened **only by Clinic** staff (NFR-05).
   - Admission Personnel cannot manage accounts/roles, run backups, change security settings, or edit/delete audit entries (§7.1).
   - Nobody can edit or delete audit-log entries (FR-15, NFR-08).
   - Passwords, 2FA secrets and tokens stay in the OAS and are **never** sent to the DMS (§8.3).
   - Applicants include minors: treat their data as children's personal data (§8.3).

---

## 2. Phase 0 — Existing UI Audit (do this first)

| Step | Output |
|---|---|
| 0.1 Confirm how the UI talks to the server (paper says static HTML + jQuery AJAX carrying a JWT; verify) | Integration style (§6) |
| 0.2 List every existing page per role: Applicant, Admission Personnel, College Dean, Clinic, System Administrator | Page inventory |
| 0.3 For each page: forms (field `name`s), buttons/actions, AJAX calls, tables, expected data shape, hard-coded/mock data | UI contract sheet |
| 0.4 Mark each element **Complete / Partial / Placeholder / Missing** | Wiring matrix |
| 0.5 Map each element to an FR/NFR (new numbering) | Traceability FR-01…FR-17 |
| 0.6 Note any UI that would break a rule in §1.5 (e.g., shows a score) | Discrepancy list |

**Deliverable:** `UI_BACKEND_MATRIX` (*Page → UI element → Role → FR → endpoint → status*). Phases 2–13 only wire endpoints that have a Complete/Partial row.

| Page | UI element | Role | Needs | FR/NFR | Endpoint | Status |
|---|---|---|---|---|---|---|
| e.g. Register | Registration form | Applicant | Create account, send verification email | FR-01 | `POST /api/auth/register` | To do |

---

## 3. Open Decisions That Affect the Backend

Rows marked **✅** are settled (by the team on 2026-10-17 or by the updated paper). Rows marked **⚠ Superseded** were team decisions that the updated paper now overrides; confirm with the team that the paper is the final word.

| # | Issue (paper ref.) | Default for build | Status |
|---|---|---|---|
| D1 | Is the exam taken online? (§3.4) | **No.** Pen-and-paper at a venue. Build scheduling, QR permit, check-in, result entry. No question bank, timer or auto-scoring | ✅ Confirmed by team and paper |
| D2 | Suggested-programs rules (FR-02) | Paper still gives no rules. `ProgramSuggestionService` returns open programs with remaining slots; only that class changes when rules are defined | ✅ Stub confirmed |
| D3 | Automated document checks (FR-16, *Could*) | **Manual review is the baseline.** File-type/size/MIME validation and malware scan are built (they're validation, not review). Blur detection and OCR are deferred (Phase 14) | ✅ Manual baseline |
| D4 | Notification channel (FR-12) | **Email**, queued and throttled, Gmail SMTP with Brevo failover. In-app notification list only if a UI for it exists | ⚠ Superseded (was in-app only) |
| D5 | Medical requirements (FR-10) | **In scope.** Clinic verifies each item, can flag suspected falsification; medical verified → Admitted | ⚠ Superseded (was deferred) |
| D6 | File types/size; upload vs physical (FR-03, §9.1) | PDF/JPG/PNG, 5 MB (config). **Both**: file is uploaded **and** a photocopy is handed in; staff mark *Photocopy received* per document | ✅ Per paper |
| D7 | Who does account admin, backups, security settings (§4, §10.2) | **System Administrator** role (admin pages + SSH). If admin pages exist in the UI, wire them; otherwise artisan commands + seeded sysadmin | ✅ Role defined; UI existence to check |
| D8 | Application status set (FR-05, FR-08, FR-09, FR-10, FR-17) | Paper defines stages but no single status list. Default in §3.1 below | Needs confirmation |
| D9 | Freshmen only? Migration of old records? | Freshmen only; no migration | Needs confirmation |
| D10 | Retention, PIA, AWS region (§8.3) | Placeholders in paper. Soft deletes; retention values in config; nothing hard-coded | Needs PLP input |
| D11 | Where MySQL runs: same EC2 (Exec. summary) vs Amazon RDS in private subnet (§7.2 diagram) | **Same EC2 server** (budget-first; RDS is the documented upgrade). Connection is `.env`-only, so switching is config | Paper contradicts itself |
| D12 | Enrollment link: "no direct OAS–Enrollment connection" (§9) vs "student number returned and shown to applicant" (FR-11) | **DMS-only.** OAS sets the Admitted flag in the DMS. A `student_number` field and an API-key callback endpoint are built behind a flag (`ENROLLMENT_CALLBACK_ENABLED=false`) | Paper contradicts itself |
| D13 | Role list: Exec. summary says "four roles" but lists three; §4 lists five; §10.2 matrix lists four | Build **five**: `applicant`, `admission_personnel`, `college_dean`, `clinic`, `sysadmin` | Paper inconsistent |
| D14 | Interview (FR-17): which programs, and does it come before the acceptance letter? FR-09 says the letter follows a *Passed* (exam) result | Default order: exam Passed → interview (if the program requires one, flag `programs.requires_interview`) → acceptance letter. Admission Personnel schedule; Dean records grade + Passed/Failed | Needs confirmation |
| D15 | DMS API contract (§9.3 "proposed") | `DmsClient` interface with `fake` and `http` drivers; endpoints per §9.3; Idempotency-Key = event ID | Waiting on DMS team |
| D16 | Slot acceptance deadline (FR-09) | Configurable per admission period (`acceptance_window_days`); scheduler forfeits unaccepted offers after the deadline (slots are only taken on acceptance) | Needs value from PLP |
| D17 | Exam scheduling: staff-assigned or applicant self-scheduled? | Staff assign by default; self-schedule behind a flag, both enforce capacity | Needs confirmation |
| D18 | Load target: 10,000+ **concurrent** users at p95 < 2 s (NFR-02) on one small EC2 | Build for it (caching, queues, indexes), load-test early and report the real ceiling; the figure may need to become "registered" not "concurrent" | Needs confirmation |
| D19 | NFR numbering: §10.3 still uses old NFR-01/02/03 labels | Plan uses the §5.2 numbering (NFR-04 security, NFR-08 audit, etc.) | Paper to fix |

### 3.1 Default status set (D8)

Kept in **one** place (`ApplicationStatus` enum + `ApplicationStatusService`) so stages can be renamed or re-ordered without touching callers.

```
Draft → Submitted ⇄ Requires Resubmission
Submitted → For Exam → Did Not Pass                     (final)
                    → Passed Exam → For Interview → Interview Failed   (final)
                                  ↘ (no interview required) ↘
                                    Offered (acceptance letter issued, deadline set)
Offered → Slot Accepted → For Medical → Admitted        (final; synced to DMS for Enrollment)
Offered → Forfeited                                     (final; offer closed)
```

- Applicants see stage names and the released result only (Passed / Did Not Pass; interview Passed / Failed), never scores or grades.
- Every transition writes `application_status_history`, an audit entry, a DMS event, and (where listed in Phase 12) an email.

---

## 4. Architecture

```
Browser (existing UI, unchanged) — AJAX + JWT
   │ HTTPS 443
   ▼
Nginx (communication layer): TLS 1.2+, static files, /api → PHP-FPM,
   5 MB upload cap, rate limits, IP allowlist for partner callbacks, access logs
   ▼
Laravel (EC2)
 ├─ routes/api.php
 ├─ Middleware: auth:api (JWT) → 2fa → role:… → throttle ; api-key + ip-allowlist for system clients
 ├─ Controllers (thin) → Form Requests
 ├─ Services: ApplicationStatusService, SlotService, ExamService, InterviewService,
 │            AcceptanceService, MedicalService, ProgramSuggestionService
 ├─ Policies: own-record, college-scoped (Dean), medical-only (Clinic)
 ├─ Integrations: DmsClient (fake | http), MalwareScanner (ClamAV | null)
 ├─ Events + Listeners: audit, DMS record sync, email
 ├─ Queue worker (Supervisor) + Scheduler (cron): emails, DMS sync, slot forfeit, exam reminders, cleanup
 └─ MySQL 8 (working copy) ── every record also synced to DMS
External: DMS (REST + X-API-Key), Gmail SMTP / Brevo, Google Drive (backups), UptimeRobot
```

**Key principles**
- Thin controllers; rules in services.
- All status changes go through `ApplicationStatusService`.
- **Outbox pattern for the DMS:** each state change writes a `dms_outbox` row in the same DB transaction; a queued job delivers it with `Idempotency-Key = event_id` and retries. A DMS outage never blocks staff work except file uploads, which are synchronous by design (§9.1).
- **Files never live in the OAS.** Uploads stream to the DMS after validation + malware scan; OAS stores `dms_document_id` and version. Downloads use 5-minute DMS links, requested through an authorized OAS route.
- Response shapes follow the Phase 0 contract sheet. Restricted fields (score, grade, 2FA secret) are excluded at the resource layer.

**Packages (suggested):** `tymon/jwt-auth`, `spatie/laravel-permission`, `pragmarx/google2fa-laravel` (staff TOTP), `barryvdh/laravel-dompdf` + `simplesoftwareio/simple-qrcode` (permit, form, letter), `maatwebsite/excel` (FR-13 export), `spatie/laravel-backup` (INT-05), `laravel/pulse`, `opcodesio/log-viewer`, ClamAV via a small scanner wrapper. Audit: custom append-only table.

---

## 5. Database Design

Paper §8.1 still says the entity list is not final; this is the **proposed schema**. Finalize the ERD at the end of Phase 1.

| Table | Key fields | Notes / FR |
|---|---|---|
| `users` | id, email (unique), email_verified_at, password, is_active, two_factor_secret (encrypted), two_factor_enabled | FR-01, NFR-04 |
| `roles` / `permissions` | via spatie | 5 roles (D13) |
| `trusted_devices` | user_id, device_hash, last_used_at | Applicant 2FA on new devices (NFR-04) |
| `two_factor_challenges` | user_id, code_hash, purpose (login/slot_acceptance), expires_at | Email codes for applicants |
| `api_clients` | name, key_hash, allowed_ips, is_active | System callers (DMS/Enrollment callbacks); managed by sysadmin |
| `colleges` | name, code, dean_user_id | Dean scoping (§10.2) |
| `programs` | college_id, name, code, slots_total, slots_filled, is_open, requires_interview | FR-14, D14 |
| `admission_periods` | label, start/end, document_deadline, acceptance_window_days, is_active | FR-14, D16 |
| `applicants` | user_id, lrn (unique), surname, given_name, middle_name, sex, birthday, contact_no, residency_status, district, barangay, address, school_type, last_school, strand, possible_duplicate_of | FR-01 (duplicates flagged by name + birthdate, not blocked) |
| `applications` | applicant_id, admission_period_id, status, submitted_at, locked_at, offered_at, acceptance_deadline, accepted_at, forfeited_at, admitted_at, student_number (nullable, D12) | FR-02, FR-05, FR-09 |
| `application_program_choices` | application_id, program_id, rank 1–3 | FR-02; unique (application, rank) |
| `requirements` | name, description, category (`admission`/`medical`), is_required, applies_to rule | FR-14, FR-10 |
| `application_documents` | application_id, requirement_id, dms_document_id, dms_version, original_name, mime, size, scan_status, status (Pending / Approved / Requires Resubmission), remark, reviewed_by, reviewed_at, photocopy_received_at, photocopy_received_by, falsification_flag, falsification_note | FR-03, FR-04, FR-10, §9.1 |
| `exam_schedules` | admission_period_id, batch (A morning / B afternoon), date, venue, room_capacity | FR-06 |
| `exam_registrations` | application_id, exam_schedule_id, permit_no, qr_token, checked_in_at, checked_in_by, signed_ticket_dms_id | FR-07, §9.1 paper records |
| `exam_results` | application_id, score (**Restricted**), result (Passed / Did Not Pass), is_released, recorded_by, released_by, released_at | FR-08 |
| `interview_schedules` | program_id, date, venue, capacity | FR-17 |
| `interview_assignments` | application_id, interview_schedule_id, grade (**Restricted**), outcome (Passed / Failed), recorded_by, recorded_at, is_released | FR-17 |
| `acceptance_letters` | application_id, generated_at, template data | FR-09 |
| `application_status_history` | application_id, from, to, changed_by, remark, created_at | FR-05 |
| `applicant_change_history` | applicant_id, field, old, new, changed_by, created_at | §8.1 change history |
| `audit_logs` | actor (user or api_client), action, entity, entity_id, ip, meta JSON, created_at | FR-15, NFR-08; **append-only** |
| `dms_outbox` | event_id (uuid), type, applicant_ref, payload JSON, status, attempts, last_error, sent_at | INT-01 record sync |
| `notifications` | (only if an in-app UI exists) | FR-12 |
| `jobs` / `failed_jobs` | Laravel queue | Emails, DMS sync |

**Integrity rules**
- `audit_logs`, `*_history`: no update/delete code paths; the app DB user has INSERT/SELECT only on `audit_logs` (Phase 14). Every login, change, release and API call (inbound and outbound) is logged.
- `exam_results.score` and `interview_assignments.grade` are never in applicant-facing or Dean-of-other-college resources.
- Medical documents (`requirements.category = medical`): only `clinic` can request download links; Admission Personnel see status only.
- Slot counts change in a transaction with a row lock (accept, forfeit, program edits).
- Exam/interview capacity enforced with a row lock (no overbooking, OBJ-02).

---

## 6. UI Integration Approach

| Existing UI type | How backend plugs in |
|---|---|
| Static HTML + jQuery AJAX (expected per §7.2) | JSON API; match URLs, methods, field names, JSON keys the JS already uses. JWT sent the way the JS expects (header or cookie). CORS only if served from another origin |
| Laravel Blade views (if found) | Return existing views with the variables they reference; use existing `name`s and CSRF |
| Mock/hard-coded data | Replace with an endpoint of the **same shape**; don't touch markup |

If an existing element conflicts with the paper (e.g., shows a score, or lets Admission Personnel open a medical file), **don't change the UI**: implement the paper's rule in the backend and log it in the discrepancy list.

---

## 7. Development Phases

Each phase ends with: feature tests passing (tagged with FR/NFR IDs), wiring checked against the matrix, DMS sync events covered by tests using the fake driver, and **no frontend files changed**.

### Phase 1 — Foundation, Data Layer, Integration Skeleton
- Laravel setup, `.env` (not in Git), MySQL (D11), upload config (5 MB, PDF/JPG/PNG).
- Migrations, models, factories, seeders for §5. Seed: 5 roles, a sysadmin user, colleges, programs, freshman requirements (§8.1 list), sample medical requirements (placeholder until Clinic confirms), one admission period.
- `DmsClient` interface + `FakeDmsClient` (local storage, deterministic IDs) + `dms_outbox` + delivery job with retry/backoff.
- `MalwareScanner` interface (ClamAV + null driver for dev).
- Base JSON response format, exception handler, validation conventions.
- **Exit:** `migrate:fresh --seed` works; ERD documented; outbox job delivers to the fake DMS.

### Phase 2 — Authentication, 2FA & Access Control (FR-01, NFR-04)
- Register → verification email → verified login. One account per email; flag possible duplicates by name + birthdate for staff review.
- JWT login, logout, refresh, `me`.
- 2FA: staff (personnel, dean, clinic, sysadmin) always use TOTP; applicants get an emailed 6-digit code on a new device (trusted-device cookie afterwards) and at slot acceptance.
- Password reset by email.
- RBAC middleware for 5 roles; policies: own-record (applicant), college-scoped (dean), medical-only (clinic).
- System API clients: X-API-Key + IP allowlist middleware.
- Throttle login, register, 2FA, reset.
- **Exit:** role × route tests; applicant A cannot read B; dean cannot read other colleges; staff without 2FA cannot reach protected routes.

### Phase 3 — Application Form, Program Choices, Status (FR-02, FR-05)
- Profile create/update with all §8.1 fields (LRN format + uniqueness, Pasig district/barangay, school type, strand).
- Draft → save → submit (completeness check, locks editing). Applicant edits only until submitted (§10.2).
- Program choices 1–3 (no duplicates, program open). Suggested programs (D2 stub).
- `ApplicationStatusService` with §3.1 transitions + history + status timeline endpoint.
- Change history on any staff edit after submission.
- DMS sync: applicant profile on registration and each change; application on submission and each change.
- **Exit:** register → fill → submit works through existing forms; timeline returns history; DMS events queued.

### Phase 4 — Documents: Upload, Review, Resubmission, Photocopies (FR-03, FR-04)
- Upload: validate type/size/MIME → malware scan → **synchronous upload to DMS** → store `dms_document_id`/version. No file kept in OAS beyond the temp upload. Target ≤ 10 s for 5 MB when DMS is healthy (NFR-02).
- Re-upload only after *Requires Resubmission*; sent as a new DMS version.
- Review queue for Admission Personnel (filter by status/program/period; non-medical only). Decision **Approved** or **Requires Resubmission**; remark **required** on resubmission (OBJ-03).
- Reviewer download via 5-minute DMS link from an authorized OAS route.
- *Photocopy received* per document.
- Auto status: any doc needs resubmission → *Requires Resubmission*; all required admission docs Approved → eligible for *For Exam*.
- DMS events + email on each decision.
- **Exit:** upload → review → resubmit loop works with existing screens; applicant sees each document's status and remark.

### Phase 5 — Programs, Slots, Requirements & System Administration (FR-14)
- Admission Personnel: CRUD for colleges, programs, slots, requirements (admission/medical), admission periods (deadlines, acceptance window). Block slot totals below filled count.
- System Administrator: staff accounts, role assignment, 2FA reset, API client keys, security settings. Admin pages only if they exist in the UI; otherwise artisan commands (`oas:user:create`, `oas:role:assign`, `oas:apikey:create`, …).
- All changes audited.
- **Exit:** applicant-side lists reflect changes; personnel cannot reach any sysadmin route.

### Phase 6 — Exam Scheduling, Permits & Attendance (FR-06, FR-07)
- Schedules: Batch A (morning) / Batch B (afternoon), date, venue, room capacity; overbooking prevented under concurrency (D17 for assign vs self-schedule).
- Move eligible applicants to *For Exam*.
- Exam permit PDF with QR code (signed token) and signature lines for applicant and proctor; printable application-form PDF. Applicant-owned, only when eligible.
- Check-in endpoint: staff scan the QR → validate token, schedule and date → mark attendance (idempotent). Roster and attendance list per schedule.
- Optional: upload scanned signed tickets to DMS (§9.1).
- Exam reminders by email (scheduler).
- **Exit:** schedule → permit → scan → attendance works end-to-end; 0 overbooked in a concurrency test.

### Phase 7 — Exam Results (FR-08)
- Personnel record internal score (from the marked paper) and Passed / Did Not Pass; editable until released.
- Single and bulk release; only released results visible.
- Applicant result resource has **no score field at all**; automated test asserts it on every applicant endpoint.
- DMS event (score flagged Restricted per §9.1), status update, email on release.
- **Exit:** OBJ-05: 0 scores in applicant payloads in security tests.

### Phase 8 — Interview (FR-17)
- Admission Personnel create interview schedules per program (capacity enforced) and assign *Passed Exam* applicants whose program requires an interview (D14).
- College Dean (own college only) records grade (**Restricted**) and Passed / Failed; Personnel view outcomes; Dean interview reports.
- Applicant sees own schedule and outcome only (no grade).
- DMS event when the Dean records the outcome.
- **Exit:** dean scoping and no-grade tests pass.

### Phase 9 — Acceptance Letter & Slot Acceptance (FR-09)
- On final pass (exam, plus interview if required) → *Offered*: acceptance letter PDF, `acceptance_deadline` set from the period's window.
- Applicant accepts with 2FA email code → slot locked (`slots_filled` +1 in a locked transaction; reject if full) → *Slot Accepted* → *For Medical*.
- Scheduler job forfeits unaccepted offers after the deadline and emails the applicant (no slot to release, since slots are taken only on acceptance).
- DMS event on acceptance/forfeit.
- **Exit:** acceptance under concurrency never exceeds slots; forfeit job tested with time travel.

### Phase 10 — Medical Requirements (FR-10, NFR-05)
- Applicant uploads medical items (same DMS pipeline as Phase 4, `category = medical`).
- Clinic: queue with submission counts, verify each item (Approved / Requires Resubmission + remark), flag suspected falsification with a note, mark photocopy received, medical reports.
- Only Clinic can obtain download links for medical files; Admission Personnel see medical **status only**; every medical file access is audited.
- All required medical items Approved → **Admitted** → DMS admission-completion event (Admitted flag + date), which is what Enrollment reads.
- **Exit:** OBJ-09 tests: 0 medical files opened outside Clinic; Admitted flag reaches the DMS fake.

### Phase 11 — DMS & Enrollment Integration, Real Driver (FR-11, INT-01, INT-02)
- Implement `HttpDmsClient` once the DMS team confirms §9.3: `PUT /applicants/{ref}`, `POST /applicants/{ref}/events`, `POST /documents`, `POST /documents/{id}/versions`, `GET /documents/{id}/download-url`.
- X-API-Key, TLS, outbound through Nginx; retries with Idempotency-Key; dead-letter view/command for failed events; reconciliation command (`oas:dms:resync {applicant}`).
- Enrollment: nothing to call (D12). If the team confirms the student-number return path, enable the callback endpoint and show `student_number` to the applicant.
- Contract tests against the DMS team's sandbox.
- **Exit:** OBJ-10: 100% uploads stored in DMS; 100% admitted records present; no duplicates on retry.

### Phase 12 — Notifications, Reports, Records, Audit (FR-12, FR-13, FR-15)
- Email (queued, throttled, Gmail SMTP primary, Brevo failover): verification, 2FA codes, status changes, document decisions, exam reminders, result availability, offer/deadline reminders, password reset. Target ≤ 5 min after a status change (OBJ-08).
- In-app notification list only if a UI exists.
- Staff applicant record view: full record, status and change history, documents (non-medical for personnel). Open in ≤ 3 s (OBJ-06).
- Reports (FR-13): applicants per program, status breakdown, document completion, exam attendance, pass rate, daily submissions, medical completion; **export to Excel and PDF**. Role-scoped: Dean → interview reports for own college; Clinic → medical reports.
- Audit: every change, login, release and API call; append-only; no edit/delete endpoint for anyone.
- **Exit:** report figures match DB counts (OBJ-07); audit entries immutable.

### Phase 13 — Deferred / Could
- **Automated document pre-checks** (FR-16, *Could*): blur detection and OCR of document type as reviewer hints only.
- In-app notifications, if a UI is added.
- *Not in plan:* online exam delivery (out of scope per §3.4), online payment, mobile app, payroll/employee-management integration.

### Phase 14 — Operations, Hardening, Testing, Handover (NFR-01…NFR-09)
- **Server:** Nginx (TLS 1.2+, 5 MB cap, rate limits, IP allowlist, access logs), PHP-FPM, Supervisor for the queue worker, cron for the scheduler, MySQL on the same EC2 (D11).
- **Backups (NFR-06, INT-05):** `spatie/laravel-backup` nightly encrypted DB backup to Google Drive; documented restore to a new server within 8 h (RPO 24 h, RTO 8 h); restore drill before go-live.
- **Monitoring (NFR-01, INT-06):** Laravel Pulse, Log Viewer, `/up` health endpoint for UptimeRobot (5-minute checks).
- **Deployment (NFR-09):** GitHub Actions: tests → deploy; no secrets in Git.
- **Security:** validation review, mass-assignment, upload checks, secure headers, least-privilege DB user for audit tables, dependency audit; 0 unresolved critical findings at go-live (OBJ-11).
- **Performance (NFR-02, NFR-03):** indexes (email, LRN, status, program, period, dms ids), pagination, eager loading, cache for public lists (programs, requirements, schedule), queue PDFs and emails; load test at document-deadline and result-release peaks (D18).
- **Privacy (NFR-05):** privacy-notice acceptance stored at registration; support for PIA (data inventory from §5); minors' data rules.
- **Accessibility (NFR-07):** backend side only — accessible error messages/JSON; UI conformance is the UI team's.
- **Docs:** API reference (matches matrix), setup and seeding guides, `.env.example`, sysadmin runbook (artisan commands, backup/restore, key rotation), DMS integration notes.
- **UAT** with the Admission Office, Clinic and a College Dean on the existing screens, measured against OBJ-01…OBJ-11.

---

## 8. Testing Strategy

| Layer | Approach | Covers |
|---|---|---|
| Unit | Status transitions, slot math, capacity, deadline/forfeit, QR token, file validation | FR-05, FR-06, FR-09 |
| Feature (HTTP) | Every endpoint × role (applicant, personnel, dean, clinic, sysadmin, guest, API client) | NFR-04 |
| Security | Own-record access; dean college scoping; medical Clinic-only; no score/grade in applicant payloads; personnel cannot reach sysadmin routes; audit immutable; 2FA enforced | NFR-04, NFR-05, NFR-08, OBJ-05, OBJ-11 |
| Integration | Register → submit → upload → review → exam → result → interview → offer → accept → medical → Admitted, with fake DMS | FR-01…FR-17 |
| Contract | `HttpDmsClient` against the DMS sandbox; idempotent retries | INT-01 |
| Concurrency | Simultaneous bookings and slot acceptances never exceed capacity | OBJ-02, FR-09 |
| Performance | Load test at peaks; p95 < 2 s target; 5 MB upload ≤ 10 s | NFR-02, NFR-03 |
| Recovery | Backup restore drill | NFR-06 |
| UI wiring | Each matrix row works against the unchanged UI (manual checklist or Playwright) | Phase 0 |
| Regression guard | CI fails if frontend directories change | Ground rule 1 |

Every test names its FR/NFR/OBJ ID so the traceability appendix can be filled.

---

## 9. Requirement Coverage Summary (updated numbering)

| Req. | Phase | Status in plan |
|---|---|---|
| FR-01 Accounts (verified email, one per email, duplicate flag) | 2, 3 | Planned |
| FR-02 Application form & suggested programs | 3 | Planned; suggestions stubbed (D2) |
| FR-03 Document upload to DMS | 4, 11 | Planned (fake DMS until contract) |
| FR-04 Verify Approved / Requires Resubmission | 4 | Planned |
| FR-05 Status timeline & document statuses | 3, 4 | Planned |
| FR-06 Batch A/B scheduling, no overbooking | 6 | Planned |
| FR-07 QR permit, application form, check-in scan | 6 | Planned |
| FR-08 Results, score internal, release | 7 | Planned |
| FR-09 Acceptance letter, slot acceptance, auto-forfeit | 9 | Planned |
| FR-10 Medical (Clinic, falsification flag) | 10 | Planned |
| FR-11 Enrollment handoff / student number | 10, 11 | Via DMS; student number behind flag (D12) |
| FR-12 Email notifications | 12 | Planned (Gmail + Brevo) |
| FR-13 Reports + Excel/PDF export | 12 | Planned |
| FR-14 Programs/slots/requirements; staff & roles admin | 5 | Planned |
| FR-15 Audit (append-only) | 2–12 | Planned |
| FR-16 Automated pre-checks (*Could*) | 13 | Deferred |
| FR-17 Interview | 8 | Planned (order per D14) |
| NFR-01 Availability 99% | 14 | Monitoring + runbook |
| NFR-02 Performance | 14 | Load test (D18) |
| NFR-03 Capacity / queued email | 12, 14 | Planned |
| NFR-04 Security (JWT, 2FA, RBAC, TLS) | 2, 14 | Planned |
| NFR-05 Privacy (RA 10173, Clinic-only medical) | 10, 14 | Planned |
| NFR-06 Recovery (RPO 24 h, RTO 8 h) | 14 | Planned |
| NFR-07 Accessibility | — | UI team; backend supports |
| NFR-08 Auditability | 12, 14 | Planned |
| NFR-09 Maintainability (tests, GitHub Actions) | all, 14 | Planned |

"Backend ready, UI pending": any module with no existing screen (likely Clinic, Dean, sysadmin pages) is built and tested as API-only and stays unwired until the UI exists.

---

## 10. Suggested Order & Dependencies

```
Phase 0 (UI audit)
 └─ Phase 1 (DB + DmsClient fake + outbox)
     └─ Phase 2 (Auth, 2FA, RBAC)
         ├─ Phase 5 (Programs/requirements/admin)  [seed data in Phase 1; CRUD early]
         └─ Phase 3 (Application) → 4 (Documents) → 6 (Exam) → 7 (Results)
                                                   → 8 (Interview) → 9 (Acceptance) → 10 (Medical)
Phase 11 (real DMS driver) — as soon as the DMS team publishes the contract; can run in parallel from Phase 4
Phase 12 (Notifications/Reports/Audit) — email + audit hooks start in Phase 2, finish here
Phase 13 (Could/deferred)
Phase 14 (Ops, hardening, UAT) — backups/monitoring can be set up early on the EC2 server
```

**Rough relative effort** (no dates; the paper has no schedule): P0 S · P1 M · P2 L · P3 L · P4 L · P5 M · P6 M · P7 S · P8 M · P9 M · P10 M · P11 M–L (depends on DMS team) · P12 L · P14 L.

---

## 11. Risks

| Risk | Mitigation |
|---|---|
| DMS API not ready or changes | Code against `DmsClient`; fake driver; outbox + idempotency; contract tests once sandbox exists |
| DMS outage blocks uploads (sync by design) | Clear error to applicant, retry guidance; deadline-day monitoring; raise with DMS team |
| Paper contradictions (D11–D14, D19) | Defaults behind config/flags; listed for the paper's next version |
| Existing UI lacks Clinic/Dean/admin screens | API-only + artisan commands; tracked in §9 |
| 10,000+ concurrent users on one small EC2 | Early load test, caching, queues; RDS/second server as documented upgrade path |
| Score, grade or medical data leakage | Allow-list resources, scoped policies, Clinic-only download links, security tests |
| Overbooking / over-acceptance under load | Row locks + concurrency tests |
| Email limits on Gmail SMTP at peaks | Queue throttling, Brevo failover, monitor bounces |
| Minors' personal data | Role-limited access, privacy notice, PIA before go-live |
| Single server = single point of failure | Nightly encrypted backups, tested restore within RTO |

---

## 12. Definition of Done (per feature)

- Endpoint(s) exist for an **existing** UI element in the matrix and work with the UI **unchanged** (or are API-only and listed as "UI pending").
- Validation, authorization (role + scope), 2FA where required, and audit logging in place.
- DMS sync event emitted where §9.1 lists the data.
- Feature + security tests pass, tagged with FR/NFR/OBJ IDs.
- No frontend files modified.
- Matrix row updated to **Wired**.
