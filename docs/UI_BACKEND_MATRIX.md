# UI → Backend Matrix (Phase 0 deliverable)

Audit of the existing UI on 2026-10-17, as required by DEVELOPMENT_PLAN.md §2.

## Integration style (step 0.1)

- Both sides are single Blade pages (`resources/views/applicant.blade.php`, `resources/views/admin/index.blade.php`) driven by jQuery. There are no form posts: every form is intercepted in JS.
- All data lives in `localStorage` inside the client JS (`admin-store.js`, `applicant.js`). Actions mark the API calls they need with `BACKEND:` comments.
- **Decision (approved 2026-10-17):** a JSON API under `/api/*` with a JWT bearer token, per the architecture diagram. Only the JS **data layer** changes (localStorage reads/writes and the `BACKEND:` stubs become AJAX calls). Markup, CSS, text and visible behaviour stay as they are.
- The admin panel derives every screen (counts, charts, reports, CSV) from one in-memory state object. The backend supplies that object in the **same shape** (`GET /api/admin/state`), and each action calls its endpoint.

## Page inventory (step 0.2)

| Side | Screen | Route |
|---|---|---|
| Applicant | Sign in / Create account / Forgot password | `/` (auth screen) |
| Applicant | Overview (6-stage journey: application, documents, review, exam, result, medical) | `/` view `overview` |
| Applicant | My application (3 sections: personal, academic, program) | `/` view `application` |
| Applicant | Documents (8 upload cards + submit) | `/` view `documents` |
| Applicant | Offer letter modal, accept offer, medical uploads (inside Overview) | `/` |
| Admin | Sign in | `/admin` |
| Admin | Dashboard, Applicants (list + record), Document review, Entrance exams (sessions, roster, attendance), Results (record + release), Medical, Reports (CSV export), Programs & requirements, Audit log | `/admin#/…` |

There is **no** screen for account/role management, backups, security settings or 2FA entry.

## Matrix (steps 0.3–0.5)

Status: **Complete** = UI element is finished and can be wired · **Partial** = works but has gaps (see Discrepancies) · **Placeholder** = hard-coded demo content · **Missing** = no UI.
Wiring: **To do** · **Built** (API exists, tested) · **Wired** (UI uses it).

### Applicant portal

| UI element | Needs | FR/NFR | Endpoint | UI status | Wiring |
|---|---|---|---|---|---|
| Create account form (`#signup-form`: first, last, email, phone, password, confirm, terms) | Register applicant | FR-01, NFR-01 | `POST /api/auth/register` | Complete | Wired |
| Sign-in form (`#signin-form`) | Login, JWT | NFR-01 | `POST /api/auth/login` | Complete | Wired |
| Forgot password form | Send reset link | NFR-01 | `POST /api/auth/forgot-password` | Partial (no reset-password screen) | Wired (the reset itself is UI pending) |
| Sign out | Invalidate token | NFR-01 | `POST /api/auth/logout` | Complete | Wired |
| Session restore on load | Current user + portal state | NFR-01 | `GET /api/applicant/portal` | Complete | Wired |
| My application: 3 sections, autosave (`#application-form`, 23 named fields) | Save draft, section progress | FR-01, FR-02, FR-05 | `PUT /api/applicant/application` | Partial (missing residency/district/barangay) | Wired |
| Program 1 / Program 2 selects | Program choices (open programs) | FR-02 | `GET /api/applicant/portal` (`programs`) + `PUT /api/applicant/application` | Partial (2 choices, plan says up to 3) | Wired |
| Document cards: upload / replace / remove (8 cards) | Upload to private storage / DMS | FR-03 | `POST/DELETE /api/applicant/documents/{key}` | Partial (list differs from admin list) | On hold (H1) |
| Submit for review (`#confirm-submit`) | Lock and submit | FR-03, FR-05 | `POST /api/applicant/submit` | Complete | On hold (H1) |
| Journey stepper, status pill | Status timeline | FR-05 | `GET /api/applicant/portal` | Complete | Wired |
| Review / exam stage panels (date, time, venue, permit) | Exam schedule + permit | FR-06 | `GET /api/applicant/portal` (`exam`) | Placeholder (hard-coded `EXAM`) | Wired |
| Result stage + offer letter modal | Released result only, no score | FR-08, NFR-02 | `GET /api/applicant/portal` (`result`) | Partial (passed only; hard-coded "89th percentile") | Wired |
| Accept offer (`#accept-offer`) | Record acceptance | FR-08 | `POST /api/applicant/offer/accept` | Complete | Wired |
| Medical uploads + submit | Medical files | FR-13 | `POST /api/applicant/medical/{key}`, `POST /api/applicant/medical/submit` | Complete | Wired |
| Key dates card | Period dates | FR-09 | `GET /api/applicant/portal` (`keyDates`) | Placeholder (hard-coded) | Wired |
| Demo controls (advance / reset) | None (demo only) | — | — | Placeholder | Not wired: changes beyond the form stay in the browser |
| Profile view (`#view-profile`, `#profile-form`) | Edit name, email, phone | FR-01 | — | Placeholder (not reachable; never wired in the prototype) | Not wired |

### Admission office panel

| UI element | Needs | FR/NFR | Endpoint | UI status | Wiring |
|---|---|---|---|---|---|
| Sign-in form | Staff login, JWT, role | NFR-01 | `POST /api/auth/login` | Complete | Wired |
| All screens' data (`ADM.state`) | Programs, sessions, applicants, settings, audit | FR-10, FR-11 | `GET /api/admin/state` | Complete | Wired |
| Verify / request resubmission (with remark) | Document review | FR-04 | `PATCH /api/admin/applicants/{id}/documents/{key}` | Complete | Wired |
| Verify all pending | Bulk verify | FR-04 | `POST /api/admin/applicants/{id}/documents/verify-pending` | Complete | Wired |
| View uploaded file | Authorized download | FR-04 | `GET /api/admin/applicants/{id}/documents/{key}/file` | Placeholder (`admin-ui.js:229`) | Wired (preview now shows the file: the one visible change, done as the `BACKEND:` comment specified) |
| Approve for exam (pick session) | Issue permit, `for_exam` | FR-05, FR-06 | `POST /api/admin/applicants/{id}/approve` | Complete | Wired |
| Reschedule absent examinee | Move session | FR-06 | `POST /api/admin/applicants/{id}/reschedule` | Complete | Wired |
| Roster checks + attendance | Permit/form check, present/absent | FR-06 | `PATCH /api/admin/applicants/{id}/exam` | Complete | Wired |
| Create exam session (date, batch, venue, capacity) | Session | FR-06 | `POST /api/admin/exam-sessions` | Complete | Wired |
| Record result (score + decision) | Internal score | FR-08, NFR-02 | `POST /api/admin/applicants/{id}/result` | Complete | Wired |
| Release results (single/bulk) | Controlled release + notify | FR-08, FR-12 | `POST /api/admin/results/release` | Complete | Wired |
| Medical: verify / resubmit / verify all | Clinic review | FR-13 | `PATCH /api/admin/applicants/{id}/medical/{key}`, `POST …/medical/verify-pending` | Complete | Wired |
| Programs: edit slots, add program | Programs and slots | FR-09 | `PATCH /api/admin/programs/{code}`, `POST /api/admin/programs` | Complete | Wired |
| Requirement on/off switches | Requirements per period | FR-09 | `PATCH /api/admin/requirements/{key}` | Complete | Wired |
| Application deadline | Admission period | FR-09 | `PATCH /api/admin/admission-period` | Complete | Wired |
| Reports + CSV export | Reports | FR-11 | Computed client-side from `GET /api/admin/state` | Complete | Wired |
| Audit log (read-only) | Append-only audit | NFR-03 | `GET /api/admin/state` (`audit` + applicant histories) | Complete | Wired |
| Reset demo data | None (demo only) | — | — | Placeholder | Reloads the server state (no demo reset on a real server) |

### Backend only, UI pending

| Feature | Why there's no wiring | Endpoint |
|---|---|---|
| 2FA (TOTP for staff, email code for applicants) | No code-entry or enrolment screen | `POST /api/auth/2fa/verify`, `artisan` enrolment |
| Reset password (token + new password) | Only the "forgot" form exists | `POST /api/auth/reset-password` |
| In-app notification list / mark read | No notification UI | `GET /api/notifications`, `POST /api/notifications/{id}/read` |
| Account, role, backup and security admin | Staff must not do these (NFR-01); no sysadmin UI | `artisan` commands |
| Enrollment hand-off | External system doesn't exist yet (deferred) | — |

## Discrepancies for the team

1. **Two different document lists.** The applicant portal shows 8 cards (Valid ID, Parent/Guardian ID, CTC Grade 11 card, Form 138, Good Moral, PSA, Photo, GCash/Dragonpay payment). The admin panel uses 7 conditional requirements (PSA, Parent ID, Guardianship affidavit, Photo, Form 137, Form 138, Applicant ID). **Decision:** the admin list is official. How the 8 cards map onto it is **on hold** until the team discusses it (PROGRESS.md, H1).
2. **Applicant form lacks residency, district and barangay**, which the admin record and the plan need. **Decision:** store the form as-is, derive "resident" from the city field, and leave district/barangay empty until the UI has the fields.
3. **Upload size:** both UIs say 10 MB; the architecture's Nginx cap is 5 MB. **Decision:** 5 MB; the backend returns a clear error message. UI text is unchanged.
4. **Result screen only handles "Passed".** There is no "Did not pass" view, and the text "Passed · 89th percentile" is hard-coded. Showing a percentile would conflict with NFR-02. The backend never sends a score or percentile.
5. **Only 2 program choices** in the applicant form; the plan allows up to 3. The backend accepts 1–3.
6. **Program lists differ** (applicant: 7 full names, admin: 11 codes). The database's program list is used by both.
7. **Applicant-only items not in the plan:** campus, "accept offer", reservation fee and the application-fee payment upload.
8. **Admin panel loads all applicants at once** (filtering, charts and CSV run in the browser). This works for the prototype; at ~20,000 applicants it needs server-side paging (Phase 10).
9. **Office panel doesn't adapt to the signed-in role.** The clinic account sees every screen and the sidebar label always says "Admission personnel". The server refuses actions outside the role (with a message), but the UI should hide them.
10. **Autosave label says "Saved on this device"** although the form is now saved on the server (UI text, unchanged).
11. **Applicant "Required documents" card shows 0 of 8** for applicants whose documents went through the server (seeded data), because the cards are still browser-only (H1).
