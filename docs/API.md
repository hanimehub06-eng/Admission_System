# API Reference

JSON API under `/api`, used by the existing applicant portal and admission office panel through jQuery AJAX (see [UI_BACKEND_MATRIX.md](UI_BACKEND_MATRIX.md) for which screen calls what). Regenerate the route list with `php artisan route:list --path=api --except-vendor`.

## Conventions

- **Auth:** `Authorization: Bearer <JWT>` from `POST /api/auth/login`. Access tokens last **15 minutes** (NFR-04); `POST /api/auth/refresh` swaps a token for a new one, even just after it expired (within `JWT_REFRESH_TTL`, 24 h), and revokes the old one (rotation). The portals do this silently on a `401`. A disabled account is refused on its next request. **5 failed sign-ins lock the account for 15 minutes** (FR-02).
- **Roles (SDD FR-30):** `applicant`, `admission` (Admission Staff), `admission_head` (the **Admin**: the whole system; also releases results and sets the admission dates), `proctor` (exam day), `read_only` (deans, registrar, DPO: view and reports), `college_dean` (interviews, own college), `clinic` (Medical), `sysadmin`. Staff accounts are made by the Super Admin (Admin → Accounts) and checked nightly against EMS ([INTEGRATIONS.md](INTEGRATIONS.md)). The sysadmin works through artisan commands, Pulse and Log Viewer ([RUNBOOK.md](RUNBOOK.md)), not this API.
- **2FA** (column below): staff routes also require a completed two-factor sign-in when `STAFF_2FA_REQUIRED=true`.
- **Applications** are addressed by their number (`AD-2026-00001`); documents and medical items by their requirement key (`birthCert`, `xray`, …).
- **Responses:** `{ "data": … }`. Validation errors: `422` with `{ "message", "errors": { field: [messages] } }`. Refused by a business rule (e.g. exam session full): `422` with `message`. Wrong role or not your record: `403`.
- **Never in applicant responses:** exam scores and interview grades (Restricted). Only the released result (Passed / Did Not Pass) and the interview outcome are shown; tests assert this on every applicant endpoint.
- **Rate limits:** `login` (sign-in, 2FA, password reset, offer acceptance), `register`, `uploads` (file uploads), `api` (general API calls).

## Sign-in and account

| Method | Path | Who | Notes |
|---|---|---|---|
| POST | `/api/auth/register` | guest | Applicant account (FR-01). `captcha` token required when `CAPTCHA_DRIVER` is set (NFR-06). With `APPLICANT_EMAIL_2FA=true`: emails a code and returns `{ two_factor_required, method, challenge }`, no token; the code also confirms the email. Otherwise returns the token and sends the verification link |
| POST | `/api/auth/login` | guest | `{ email, password, portal: applicant\|office\|clinic, device_token? }`. Returns the token, or `{ two_factor_required, method, challenge }` |
| POST | `/api/auth/2fa/verify` | guest | `{ challenge, code }` → token. Applicants also get a `device_token` that skips the code on that device |
| POST | `/api/auth/2fa/resend` | guest | `{ challenge }`: emails a new code (old one stops working). 30 s apart, at most 3 per challenge |
| POST | `/api/auth/email/resend` | signed in | Resend the verification link |
| POST | `/api/auth/forgot-password` | guest | Emails a reset link (same answer whether the email exists or not) |
| POST | `/api/auth/reset-password` | guest | `{ token, email, password, password_confirmation }` |
| GET | `/api/auth/me` | signed in | Current user, roles, `two_factor_enabled` |
| POST | `/api/auth/refresh` | bearer token (may be expired) | New 15-minute token; the old one is revoked |
| POST | `/api/auth/logout` | signed in | |
| GET | `/api/notifications` | signed in | In-app notices (UI pending) |
| POST | `/api/notifications/{id}/read`, `/api/notifications/read-all` | signed in | |

## Applicant portal (role `applicant`, own record only)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/applicant/portal` | Everything the portal shows: profile, dated `timeline` (FR-11), documents, exam, result, offer, medical, `strandSuggestions` (FR-06). Until a result is released, `status` stays `for_exam` (FR-20) |
| PUT | `/api/applicant/application` | Save the form (autosave); locked after submission (FR-04). Applicants under 18 also send `guardianConsentName` and `guardianConsent: "1"` (FR-31) |
| POST | `/api/applicant/submit` | Submit; checks completeness |
| POST / DELETE | `/api/applicant/documents/{key}` | Upload (multipart `file`, PDF/JPG/PNG ≤ 5 MB, malware-scanned, sent to the DMS) / remove before review (FR-03) |
| GET | `/api/applicant/documents/{key}/file` | Own file |
| GET | `/api/applicant/exam-sessions` | Open sessions (when self-scheduling is on, D17) |
| POST | `/api/applicant/exam-session` | Choose a session; capacity enforced under concurrency (FR-06) |
| GET | `/api/applicant/exam-permit` | PDF with QR code (FR-07) |
| GET | `/api/applicant/application-form` | Printable application form PDF |
| GET | `/api/applicant/acceptance-letter` | PDF, once *Offered* (FR-09) |
| POST | `/api/applicant/offer/accept` | Accept the slot before the deadline (email code when `APPLICANT_ACCEPTANCE_2FA=true`) |
| POST / DELETE | `/api/applicant/medical/{key}` | Medical uploads after slot acceptance (FR-10) |
| POST | `/api/applicant/medical/submit` | Send medical requirements to the Clinic |

## Admission office panel (2FA)

"office" below = `admission` and `admission_head`.

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/api/admin/state` | office, proctor, read_only | The whole panel state in the shape the panel expects, plus `reference` (strand suggestions, barangays); exam scores only for office roles |
| GET | `/api/admin/reference` | office, proctor, read_only | Strands with suggested programs, barangays by district (FR-26) |
| PATCH | `/api/admin/applicants/{no}/documents/{key}` | office | `{ status: verified\|resubmit\|rejected, remark }`; remark required unless verified (FR-09). Rejected files can't be replaced and block *For Exam* |
| POST | `/api/admin/applicants/{no}/documents/verify-pending` | admission | Verify every pending document |
| GET | `/api/admin/applicants/{no}/documents/{key}/file` | admission (admission docs), clinic (medical) | Medical openings are audited (NFR-05) |
| PATCH | `/api/admin/applicants/{no}/documents/{key}/photocopy` | admission / clinic | `{ received: bool }` (§9.1) |
| PATCH | `/api/admin/applicants/{no}/medical/{key}` | clinic | Same body as document review |
| POST | `/api/admin/applicants/{no}/medical/verify-pending` | clinic | All medical items verified → *Admitted* |
| PATCH | `/api/admin/applicants/{no}/medical/{key}/flag` | clinic | `{ flagged, note }` suspected falsification |
| POST | `/api/admin/applicants/{no}/approve` | office | Move to *For Exam*. `{ sessionId? }`: without it, the earliest session with a free seat (FR-14) |
| POST | `/api/admin/exam/auto-assign` | office | `{ ids? }`: seat every applicant whose documents are all verified, earliest session first, never over capacity (FR-14) |
| PATCH | `/api/admin/applicants/{no}/exam` | office, proctor | `{ permitChecked, formChecked, attendance: present\|absent, envelopeReceived }` (FR-16, FR-17) |
| POST | `/api/admin/applicants/{no}/reschedule` | office | Move to another session |
| POST / PATCH / DELETE | `/api/admin/exam-sessions`, `/api/admin/exam-sessions/{S1}` | office | Create a Batch A/B session; change venue/capacity (not below seats taken); remove an empty one (FR-26) |
| GET | `/api/admin/exam-sessions/{S1}/roster?format=json\|pdf` | office, proctor | Printable roster per room and batch (FR-16) |
| POST | `/api/admin/exam/check-in` | office, proctor | Scanned permit QR → attendance (idempotent) |
| POST | `/api/admin/applicants/{no}/result` | office | Record score + Passed/Did Not Pass (editable until released) |
| POST | `/api/admin/results/import` | office | CSV (`file` upload or `csv` text): header row with `application number`, `score`, optional `decision`. Bad lines are listed, the rest recorded (FR-19) |
| POST | `/api/admin/results/release` | **admission_head** | Release one or many results (FR-20) |
| POST | `/api/admin/applicants/{no}/placement` | office | `{ program: CODE }`: place a released pass in another of their three choices, within slots (FR-21) |
| POST | `/api/admin/interview-schedules` | admission | Interview schedule per program, with capacity (FR-17) |
| POST | `/api/admin/applicants/{no}/interview` | admission | Assign a *Passed Exam* applicant to an interview |
| GET | `/api/admin/interviews` | admission | Interview outcomes |
| POST / PATCH | `/api/admin/programs`, `/api/admin/programs/{code}` | office | Programs: `{ slots?, name?, open?, requiresInterview? }`; slots can't go below accepted |
| PATCH | `/api/admin/requirements/{key}` | office | Turn a requirement on/off |
| PATCH | `/api/admin/admission-period` | **admission_head** | Dates of admission: `{ deadline?, label?, opensOn?, examStartsOn?, examEndsOn?, resultsReleaseOn?, acceptanceWindowDays? }` (FR-26) |
| POST | `/api/admin/applicants/{no}/evaluation` | **admission_head** | Record a dean's evaluation for any college (same body as the dean's) |
| PUT | `/api/admin/strands` | office | `{ name, programs: [CODE…], active? }`: a strand and its suggested programs (FR-06, FR-26) |
| PUT | `/api/admin/barangays/{1\|2}` | office | `{ names: [...] }`: the whole barangay list of a district (FR-26) |

## College Dean (role `college_dean`, own college only; 2FA)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/dean/applicants` | Applicant list: submitted applicants who chose one of the college's programs (no scores, no contact details) |
| GET | `/api/dean/interviews` | Evaluations (interviews) for the dean's college |
| POST | `/api/dean/applicants/{no}/interview` | `{ grade, outcome: passed\|failed\|referred, program? (referred: another of the applicant's choices, without its own interview), remark? }`. Grade is Restricted |

## Reports (FR-27; 2FA)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/reports` | Reports the caller may see |
| GET | `/api/reports/{key}?format=json\|csv\|xlsx\|pdf` | Exports are audited |

| Key | Roles |
|---|---|
| `applicants-per-program` (1st/2nd/3rd choice), `status-breakdown`, `document-completion`, `pass-rate`, `daily-submissions` | office, read_only |
| `exam-attendance` | office, read_only, proctor |
| `admitted-applicants` (same fields Enrollment receives, FR-29) | office, read_only |
| `possible-duplicates` (FR-25) | office |
| `medical-completion` | clinic, office |
| `interviews` | college_dean (own college), office, read_only |

## Other systems

No other system calls the OAS (School Systems API Standard, [API_Guide.md](API_Guide.md)). The only public endpoint besides this API is `GET /up` (health check, no auth).

The OAS's own calls **out**, all Bearer-token, `/api/v1` and JSON, are described in [INTEGRATIONS.md](INTEGRATIONS.md): it pulls staff status from EMS (`GET /employees/{employee_no}`), sends record changes, files and applicant packets to the DMS (`POST /history`, `/documents`, `/applicant-packets`), and sends each admitted applicant to the Enrollment System (body in [openapi.json](openapi.json)).

## Admission roles and tasks (2026-10-31)

An Admission Personnel account can be given a task (`personnel`, `proctor`, `exam_office`) by the Admission Head or the Super Admin. With a task, the server allows only that task's routes (`task` middleware); without one, every admission route works as before. The Admission Head (and the Super Admin) can do every task, exam attendance included.

| Method | Path | Who | Notes |
|---|---|---|---|
| GET / POST | `/api/admin/announcements` | admission_head, super_admin | Dates and deadlines. Body: `{ title, body, event_date?: "YYYY-MM-DD", published?: bool }` |
| PATCH / DELETE | `/api/admin/announcements/{id}` | admission_head, super_admin | `published: true` makes it visible to applicants |
| GET | `/api/applicant/announcements` | applicant | Published only, nearest date first |
| GET | `/api/admin/accounts` · PATCH `/api/admin/accounts/{user}/task` | admission_head, super_admin | Assign a task. Body: `{ task: "personnel"\|"proctor"\|"exam_office"\|null }` |
| GET | `/api/admin/applicants/{number}/exam-permit` | personnel task | Exam permit PDF (after approval) |
| GET | `/api/admin/applicants/{number}/slip` | personnel task | Application slip PDF (after submission) |
| PATCH | `/api/admin/applicants/{number}/exam` · POST `/api/admin/exam/check-in` | proctor task, proctor role, Admission Head, Super Admin | Attendance |
| POST `/api/admin/applicants/{number}/result` · `/placement` · `/api/admin/results/import` | | exam_office task | Score and passed/failed |
| GET / POST | `/api/admin/applicants/{number}/reports` | GET: office, proctor · POST: proctor task, proctor role | Body: `{ category: incident\|misconduct\|health\|other, body }` |
| GET / POST | `/api/dean/exam-questions` | college_dean | Own college. Body: `{ question, choices: string[2..6], answer: index }` |
| PATCH / DELETE | `/api/dean/exam-questions/{id}` | college_dean | Another college's question answers 404 |

The Applicant ID (`applicationId` in the portal, `applicantNo` in the office list, e.g. `2026-00001`) is issued when admission approves the application; it is `null` before. The application `number` (`AD-2026-00001`) stays the internal reference used in URLs.
