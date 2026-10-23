# API Reference

JSON API under `/api`, used by the existing applicant portal and admission office panel through jQuery AJAX (see [UI_BACKEND_MATRIX.md](UI_BACKEND_MATRIX.md) for which screen calls what). Regenerate the route list with `php artisan route:list --path=api --except-vendor`.

## Conventions

- **Auth:** `Authorization: Bearer <JWT>` from `POST /api/auth/login`. Tokens expire; `POST /api/auth/refresh` renews one. A disabled account is refused on its next request.
- **Roles:** `applicant`, `admission` (Admission Personnel), `college_dean`, `clinic`, `sysadmin`. The sysadmin works through artisan commands, Pulse and Log Viewer ([RUNBOOK.md](RUNBOOK.md)), not this API.
- **2FA** (column below): staff routes also require a completed two-factor sign-in when `STAFF_2FA_REQUIRED=true`.
- **Applications** are addressed by their number (`AD-2026-00001`); documents and medical items by their requirement key (`birthCert`, `xray`, …).
- **Responses:** `{ "data": … }`. Validation errors: `422` with `{ "message", "errors": { field: [messages] } }`. Refused by a business rule (e.g. exam session full): `422` with `message`. Wrong role or not your record: `403`.
- **Never in applicant responses:** exam scores and interview grades (Restricted). Only the released result (Passed / Did Not Pass) and the interview outcome are shown; tests assert this on every applicant endpoint.
- **Rate limits:** `login` (sign-in, 2FA, password reset, offer acceptance), `register`, `uploads` (file uploads), `api` (integrations).

## Sign-in and account

| Method | Path | Who | Notes |
|---|---|---|---|
| POST | `/api/auth/register` | guest | Applicant account (FR-01). With `APPLICANT_EMAIL_2FA=true`: emails a code and returns `{ two_factor_required, method, challenge }`, no token; the code also confirms the email. Otherwise returns the token and sends the verification link |
| POST | `/api/auth/login` | guest | `{ email, password, portal: applicant\|office\|clinic, device_token? }`. Returns the token, or `{ two_factor_required, method, challenge }` |
| POST | `/api/auth/2fa/verify` | guest | `{ challenge, code }` → token. Applicants also get a `device_token` that skips the code on that device |
| POST | `/api/auth/2fa/resend` | guest | `{ challenge }`: emails a new code (old one stops working). 30 s apart, at most 3 per challenge |
| POST | `/api/auth/email/resend` | signed in | Resend the verification link |
| POST | `/api/auth/forgot-password` | guest | Emails a reset link (same answer whether the email exists or not) |
| POST | `/api/auth/reset-password` | guest | `{ token, email, password, password_confirmation }` |
| GET | `/api/auth/me` | signed in | Current user, roles, `two_factor_enabled` |
| POST | `/api/auth/refresh` / `/api/auth/logout` | signed in | |
| GET | `/api/notifications` | signed in | In-app notices (UI pending) |
| POST | `/api/notifications/{id}/read`, `/api/notifications/read-all` | signed in | |

## Applicant portal (role `applicant`, own record only)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/applicant/portal` | Everything the portal shows: profile, status timeline, documents, exam, result, offer, medical |
| PUT | `/api/applicant/application` | Save the form (autosave); locked after submission (FR-02) |
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

## Admission office panel (roles `admission`, `clinic`; 2FA)

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/api/admin/state` | admission, clinic | The whole panel state in the shape the panel expects; exam scores only for admission (the clinic never gets them) |
| PATCH | `/api/admin/applicants/{no}/documents/{key}` | admission | `{ status: verified\|resubmit, remark }`; remark required on resubmit (FR-04) |
| POST | `/api/admin/applicants/{no}/documents/verify-pending` | admission | Verify every pending document |
| GET | `/api/admin/applicants/{no}/documents/{key}/file` | admission (admission docs), clinic (medical) | Medical openings are audited (NFR-05) |
| PATCH | `/api/admin/applicants/{no}/documents/{key}/photocopy` | admission / clinic | `{ received: bool }` (§9.1) |
| PATCH | `/api/admin/applicants/{no}/medical/{key}` | clinic | Same body as document review |
| POST | `/api/admin/applicants/{no}/medical/verify-pending` | clinic | All medical items verified → *Admitted* |
| PATCH | `/api/admin/applicants/{no}/medical/{key}/flag` | clinic | `{ flagged, note }` suspected falsification |
| POST | `/api/admin/applicants/{no}/approve` | admission | Move to *For Exam* |
| PATCH | `/api/admin/applicants/{no}/exam` | admission | Assign to an exam session |
| POST | `/api/admin/applicants/{no}/reschedule` | admission | Move to another session |
| POST | `/api/admin/exam-sessions` | admission | Create a Batch A/B session (date, venue, capacity) |
| POST | `/api/admin/exam/check-in` | admission | Scanned permit QR → attendance (idempotent) |
| POST | `/api/admin/applicants/{no}/result` | admission | Record score + Passed/Did Not Pass (editable until released, FR-08) |
| POST | `/api/admin/results/release` | admission | Release one or many results |
| POST | `/api/admin/interview-schedules` | admission | Interview schedule per program, with capacity (FR-17) |
| POST | `/api/admin/applicants/{no}/interview` | admission | Assign a *Passed Exam* applicant to an interview |
| GET | `/api/admin/interviews` | admission | Interview outcomes |
| POST / PATCH | `/api/admin/programs`, `/api/admin/programs/{code}` | admission | Programs and slots; slots can't go below accepted (FR-14) |
| PATCH | `/api/admin/requirements/{key}` | admission | Turn a requirement on/off |
| PATCH | `/api/admin/admission-period` | admission | `{ deadline }` application deadline |

## College Dean (role `college_dean`, own college only; 2FA)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/dean/interviews` | Interviews for the dean's college |
| POST | `/api/dean/applicants/{no}/interview` | Record grade (Restricted) and Passed/Failed |

## Reports (FR-13; 2FA)

| Method | Path | Notes |
|---|---|---|
| GET | `/api/reports` | Reports the caller may see |
| GET | `/api/reports/{key}?format=json\|xlsx\|pdf` | Exports are audited |

| Key | Roles |
|---|---|
| `applicants-per-program`, `status-breakdown`, `document-completion`, `exam-attendance`, `pass-rate`, `daily-submissions` | admission |
| `medical-completion` | clinic, admission |
| `interviews` | college_dean (own college), admission |

## Other systems

| Method | Path | Auth | Notes |
|---|---|---|---|
| POST | `/api/integrations/enrollment/student-number` | `X-API-Key` (`api-client:create enrollment`) + IP allowlist | Student-number callback, off unless enabled (D12) |
| GET | `/up` | none | Health check |

The OAS's own calls **to** the DMS (`PUT /applicants/{ref}`, `POST /applicants/{ref}/events`, `POST /documents`, …) are described in DEVELOPMENT_PLAN.md Phase 11 and switch on with `DMS_DRIVER=http`.
