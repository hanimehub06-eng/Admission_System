# Backend Progress

## ▶ Quick start: the simplest way to run the system

**On this computer (already set up):** open a terminal in the project folder and run one command:

```powershell
cd D:\Admission_System
php artisan app:start
```

Open **http://127.0.0.1:8000/admin** (admission staff), **http://127.0.0.1:8000/nurse** (clinic) or **http://127.0.0.1:8000/** (applicants). Press `Ctrl+C` in the terminal to stop.

| Who | Email | Password |
|---|---|---|
| Admission staff | `admissions@plpasig.edu.ph` | `password123` |
| Clinic (at /nurse) | `clinic@plpasig.edu.ph` | `password123` |
| Applicant | click **Create account** on http://127.0.0.1:8000/ | your own |

**On a new computer (one time):** install [Laravel Herd](https://herd.laravel.com) (it gives you PHP and Composer), then:

```powershell
git clone -b backend https://github.com/hanimehub06-eng/Admission_System.git
cd Admission_System
composer setup
php artisan app:start
```

That's it: no settings to edit. It uses SQLite (a single file, no database server) and writes emails to `storage/logs/laravel.log` instead of sending them. To use MySQL instead, see "Full setup guide" below.

## ❓ Open questions (everything waiting on a decision)

**Blocking features**
1. ~~**H1 · Applicant document cards.**~~ Decided: Grade 12 Form 138 (`reportCard`) and Good Moral (`goodMoral`) are now official requirements (migration `2026_10_18_000100`). The portal builds its cards from the server's list per applicant (7 for Grade 12 students; SHS graduates get Form 137 instead of the two report cards; a guardian adds the affidavit), uploads to `/api/applicant/documents/{key}` and submits through `/api/applicant/submit`. No payment card: PLP is a public university, so there is no application or reservation fee (migration `2026_10_18_000300` retires the old payment requirement).
2. **Offer acceptance deadline.** There is no reservation fee (public university); passers only accept the offer. What happens when a passer doesn't accept by a deadline (the diagram's "slot deadlines" job)? Free the slot, notify, change status? Nothing is automated until this is defined. The period's `reservation_deadline` column is no longer shown to applicants.
3. **When is an applicant "Admitted" for Enrollment?** Built as: after medical clearance (admission complete). Confirm, or name another point (e.g. offer accepted, fee paid).

**Needed from other teams**
4. **Enrollment and the student number (D12).** Per the updated paper the OAS never calls Enrollment: it marks applicants *Admitted* in the DMS, and Enrollment reads them there. Does Enrollment send the student number back? The callback (`POST /api/integrations/enrollment/student-number`) is built and off (`ENROLLMENT_CALLBACK_ENABLED=false`).
5. **Document Management API contract.** The DMS driver is built (`DOCUMENT_STORE=dms`) on an assumed API. Needs the real contract and API key.
6. **The clinic's real medical requirement list.** The seeded one (medical certificate, chest X-ray, CBC/urinalysis) is a placeholder.

**UI team (the backend is ready; the screens can't show it yet)**
7. 2FA code screen · "new password" screen for the reset link · notification list · "Did not pass" result screen (failed applicants currently stay on the exam step) · district/barangay fields in the applicant form · the unreachable profile view.
8. ~~Hide admin screens by role~~ Done: the clinic has its own portal (`/nurse`, `/api/clinic`) and can't sign in to `/admin`; admission staff can't sign in to `/nurse`.
9. Remove the hard-coded "Passed · 89th percentile" from the result screen (the backend never sends a score or percentile, NFR-02).
10. "Saved on this device" autosave label: the form is now saved on the server.

**Ownership and policy**
11. Who owns the sysadmin duties (accounts, backups, security settings) (D7)?
12. Who may open the Pulse monitoring dashboard in production (sysadmin over VPN via the Nginx allowlist is the suggestion)?
13. What should "Reset demo data" in the panel do on a real server? It currently just reloads.
14. Still on defaults from the plan: D6 file types (PDF/JPG/PNG), D8 status set, D9 freshmen only, D10 retention (soft deletes).

Tracks the backend build on the `backend` branch. Guide: [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md). Architecture: the system architecture diagram (Nginx communication layer → Laravel on EC2 → RDS MySQL; DMS and Enrollment as external systems). UI audit: [docs/UI_BACKEND_MATRIX.md](docs/UI_BACKEND_MATRIX.md).

The system is one module of the university ERP. Other systems (Document Mgmt, Enrollment) connect through REST + API key, so integrations go behind interfaces and are switched on through `.env`.

## Full setup guide (details, troubleshooting, production)

Windows with [Laravel Herd](https://herd.laravel.com) (it installs PHP and Composer). Run every command in PowerShell or the VS Code terminal, inside the project folder.

**Without Herd ([php.new](https://php.new)):** its PHP build has no `sodium` or `gd` extension. Install with `$env:COMPOSER_IGNORE_PLATFORM_REQ="ext-sodium"; composer setup`. That's safe here: the JWT library only needs sodium for EdDSA keys and falls back to plain PHP for HS256, which this app uses. The tests don't need GD (they upload a real JPEG, not a generated one). XAMPP's PHP 8.0 is too old for this app (PHP 8.3+).

### First time only

**1. Get the code**
```powershell
cd D:\Admission_System
git checkout backend
git pull
```

**2. Choose a database (optional).** `composer setup` creates `.env` from `.env.example`, which uses **SQLite** by default, so nothing needs changing for a demo. To use **MySQL** instead (this project's local setup), create `.env` first (`copy .env.example .env`) and in it:
- set `DB_CONNECTION=mysql` and uncomment/fill `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=admission_system`, `DB_USERNAME`, `DB_PASSWORD` (the database must already exist);
- run this **once** as the MySQL root user (it allows the audit-log protection triggers):
  ```sql
  SET PERSIST log_bin_trust_function_creators = 1;
  ```

Emails go to `storage/logs/laravel.log` by default. For real email, set `MAIL_MAILER=failover` and the Gmail/Brevo lines.

**3. Run the setup**
```powershell
composer setup
```
This installs the packages, creates the keys, builds the tables and loads the starting data (about a minute with demo data). **Copy the sysadmin password it prints.** It is safe to run again: it never overwrites keys or reloads data that is already there. If the database can't be reached, it says exactly which setting to check.

### Every time you want to use the system

```powershell
cd D:\Admission_System
php artisan app:start
```
This starts the web server, the queue worker (emails, notifications) and the scheduler (nightly backup, retries) together in one window. Leave it open; press `Ctrl+C` to stop everything. Then open:

| Screen | Address | Sign in with |
|---|---|---|
| Applicant portal | http://127.0.0.1:8000/ | **Create account**, or a sample applicant (any email in the admin panel's Applicants list) / `password123` |
| Admission office | http://127.0.0.1:8000/admin | `admissions@plpasig.edu.ph` / `password123` |
| Clinic (medical) | http://127.0.0.1:8000/nurse | `clinic@plpasig.edu.ph` / `password123` |
| Monitoring (Pulse) | http://127.0.0.1:8000/pulse | local only |

### After pulling new code

| If… | Run |
|---|---|
| `composer.json` changed (new packages) | `composer install` |
| new files in `database/migrations` | `php artisan migrate` |
| you want fresh demo data (deletes everything) | `php artisan migrate:fresh --seed` |

### Sysadmin tools (command line only; staff can't do these)

```powershell
php artisan user:create name@plpasig.edu.ph "Full Name" admission   # admission | clinic | sysadmin
php artisan user:active name@plpasig.edu.ph off                     # off = disable, on = enable
php artisan user:2fa name@plpasig.edu.ph totp                       # totp | email | off
php artisan app:backup                                              # database + documents → storage/app/backups (also runs nightly)
php artisan app:db-privileges                                       # prints MySQL least-privilege GRANTs (production)
php artisan enrollment:retry                                        # resend admitted applicants to Enrollment (when switched on)
php artisan test                                                    # run the automated tests
```
For backups on MySQL, set `BACKUP_MYSQLDUMP_PATH` in `.env` if `mysqldump` isn't on the PATH, e.g. `"C:/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump.exe"` (forward slashes).

### Troubleshooting
- *"php" or "composer" is not recognized:* install Herd, then open a new terminal.
- *"Can't connect to the database":* check the `DB_` lines in `.env`; for MySQL, create the database first.
- *"MySQL won't let this user create the audit-log triggers":* run the `SET PERSIST` line from step 2 as root, then `composer setup` again.
- *Port 8000 is busy:* `php artisan app:start --port=8080`, then use `http://127.0.0.1:8080`.
- *Emails/notifications not appearing:* they're sent by the queue worker, which `app:start` runs; with `MAIL_MAILER=log` they're in `storage/logs/laravel.log`.

### Production (architecture diagram)
Nginx in front ([docs/deploy/nginx.conf](docs/deploy/nginx.conf): TLS, gzip, rate limits, 5 MB uploads), PHP-FPM with OPcache, Amazon RDS for MySQL (parameter group: `log_bin_trust_function_creators = 1`), least-privilege DB users from `php artisan app:db-privileges`, `MAIL_MAILER=failover` with the Gmail and Brevo credentials, `QUEUE_CONNECTION=database`, and two background services: `php artisan queue:work` and `php artisan schedule:work` (or cron running `schedule:run` every minute). Run `php artisan optimize` after each deploy. Never commit `.env`.

## Decisions made with the team (2026-10-17)

These override DEVELOPMENT_PLAN.md where the two disagree.

| # | Topic | Decision | Overrides |
|---|---|---|---|
| T1 | Wiring the UI | Change only the JS data layer (localStorage and `BACKEND:` stubs → AJAX). No markup, CSS, text or visible behaviour changes | Plan §0.4 "never modify client-side JS" |
| T2 | Medical requirements (FR-13) | Build now, with a separate **Clinic** role, following the diagram and the existing medical screens | Plan D5 (deferred) |
| T3 | Notifications | In-app **and** email: Gmail SMTP with Brevo as failover, sent from the queue | Plan D4 (in-app only) |
| T4 | 2FA | Built in the backend: authenticator app (TOTP) for staff, email code for applicants. UI pending, since there is no code screen yet | Not in plan |
| T5 | Document storage | `DocumentStore` interface: private local disk now, DMS REST driver (API key) switched on via `.env` when the DMS exists. The database stores the storage reference (DMS document ID) | Plan Phase 4 (local only) |
| T6 | Official document list | The admin panel's 7 conditional requirements | — |
| T7 | Applicant form gaps | Store the form as it is; derive residency from city; district/barangay empty until the UI has fields | — |
| T8 | Upload limit | 5 MB (diagram), PDF/JPG/PNG | UI text says 10 MB |
| T9 | Enrollment integration | Built and switched off (`ENROLLMENT_ENABLED=false`) until the Enrollment system exists (updated 2026-10-17) | — |
| T10 | Roles | `applicant`, `admission`, `clinic`, `sysadmin` (diagram's four user groups) | Plan's `applicant/staff/admin` |
| T11 | Auth | JWT (`php-open-source-saver/jwt-auth`), sent as a bearer token by the AJAX calls | — |

### On hold: to discuss with the team

| # | Topic | Detail | Blocks |
|---|---|---|---|
| H1 | Applicant document cards vs official list | The portal shows 8 cards; the official list (T6) has 7 conditional requirements. 5 cards map cleanly (Valid ID→`applicantId`, Parent/Guardian ID→`parentId`, CTC Grade 11→`form138`, PSA→`psa`, Photo→`photo`). 3 cards have no official requirement (Grade 12 Form 138, Good Moral, GCash/Dragonpay payment). 2 requirements have no card (Guardianship affidavit, Form 137). The portal only enables "Submit" when all 8 cards are filled. **Nothing is built for this mapping until it's discussed.** | Wiring the applicant's document cards and the submit button. The backend upload/review API is keyed by official requirement and is built. |


## Environment

- Laravel 13, PHP 8.4 (Herd), MySQL 8.0.46 locally (`admission_system`, user `admission_app`). Tests run on in-memory SQLite by default; CI runs them on SQLite and MySQL.
- Packages: `php-open-source-saver/jwt-auth`, `spatie/laravel-permission`, `pragmarx/google2fa`, `laravel/pulse`, `laravel/boost` (dev).

## Phases

Legend: ✅ done · 🔄 in progress · ⬜ to do · ⏸ deferred

| Phase | Scope | Status |
|---|---|---|
| 0 | UI audit → [UI_BACKEND_MATRIX](docs/UI_BACKEND_MATRIX.md) | ✅ |
| 1 | Foundation & data layer: migrations, models, seeders, API response format | ✅ |
| 2 | Auth & access control: JWT, roles, 2FA, password reset, throttling | ✅ |
| 3 | Application: profile, draft/autosave, program choices, submit, status service, history | ✅ Wired (submit button on hold: H1) |
| 4 | Documents: upload (DocumentStore), review, resubmission | ✅ Staff side wired · applicant cards on hold (H1) |
| 5 | Programs, slots, requirements, admission period maintenance | ✅ Wired |
| 6 | Exams: sessions, approval/permit, roster, attendance, reschedule | ✅ Wired |
| 7 | Results & acceptance: record, release, accept offer (no score to applicants) | ✅ Wired |
| 7b | Medical requirements (Clinic) | ✅ Wired |
| 8 | Records, reports, notifications (in-app + email), audit log | ✅ (reports computed in the panel from `/api/admin/state`) |
| 9 | Enrollment integration · automated document checks | ✅ Enrollment built, switched off · ⏸ automated checks (D3) |
| 10 | Hardening, performance, setup, docs, UAT | ✅ except UAT and production deployment (see Remaining work) |

## Work log

### 2026-10-17
- Converted the static site into a Laravel project (pages → Blade views, assets → `public/assets`). UI unchanged.
- Created the `backend` branch.
- Phase 0: audited both UIs, wrote the matrix and the discrepancy list, and agreed decisions T1–T11 with the team.
- Installed the packages above and removed Sanctum (JWT is used instead).

- Phase 1: schema (periods, programs, generic requirements incl. medical, applicants, applications, choices, versioned documents with storage driver/ref, exam sessions/registrations/results, status history, change history, audit log, notifications). History and audit tables are append-only, enforced by the model **and** by DB triggers (MySQL + SQLite). Seeders: roles, programs, requirements, active period, sysadmin, local demo staff + exam sessions. Mail config: Gmail → Brevo failover. Tests: 4 passing.

- Phase 2: `/api/auth/*` (register, login with `portal` = applicant|office, 2FA verify, me, refresh, logout, forgot/reset password). Each portal only admits its own roles; sysadmin can't use either. Disabled accounts lose access immediately. 2FA: TOTP for staff, email code for applicants (off by default via `APPLICANT_EMAIL_2FA`). Rate limits on login/register. Sysadmin artisan commands: `user:create`, `user:active`, `user:2fa`. Staff logins/logouts audited. Tests: 17 passing.

- Phase 3: `GET /api/applicant/portal` returns the portal state in the exact shape `applicant.js` keeps in localStorage (phase, step, completed, form data, exam, released result only, medical, programs, key dates). `PUT /api/applicant/application` autosaves: half-typed values are skipped and reported, sections marked complete are validated strictly (rules mirror the form's `required`/`pattern`). Program choices 1–3 by name, open programs only, no duplicates. `POST /api/applicant/submit` checks form + required documents and moves Draft/Resubmit → Submitted through `ApplicationStatusService` (the only place status changes; writes history + fires `ApplicationStatusChanged`). Resubmission reopens the portal as "draft" so existing screens can be reused. Profile edits after the first submission go to `applicant_change_history` (FR-10). `ProgramSuggestionService` placeholder (D2). Tests: 26 passing.

- Phase 4 + medical: `DocumentStorage` writes new files to the configured store (`local` private disk or `dms`) and reads each file from the store it was saved in, so switching to the DMS doesn't strand old files. DMS driver: `X-API-Key`, `Idempotency-Key`, retries, `confidential` flag on medical files (assumed REST contract, adjust when the DMS API is published). Uploads: content-checked PDF/JPG/PNG (photo image-only), 5 MB, random names, versioned (old versions kept). Applicant: upload/replace/remove in draft; during resubmission only the files sent back. Staff: verify / send back (remark required) / verify all pending; the application moves to "Requires resubmission" and back automatically. Medical: opens after a released pass; clinic-only review and file access (admission staff can't open medical files, the clinic can't open admission documents). `GET /api/admin/state` returns the panel's full `ADM.state` shape (score for admission personnel only). Times shown in Asia/Manila. Tests: 34 passing.

- Phases 5–7: setup endpoints (slots 1–2,000, never below admitted count; add program; requirement on/off; deadline), exam sessions (no duplicates), approve-for-exam (all required docs verified, seat check under a row lock, permit `EX-27-xxxxx`), reschedule, venue checklist + attendance ("present" needs permit and form checked), record/correct result before release, single/bulk release, accept offer. Clinic is limited to medical routes. Test proves no applicant response contains a score or percentile, and the clinic's state has no score. The applicant acceptance letter is rendered by the existing modal from portal data, so no PDF was built (no UI asks for one). Tests: 45 passing.

- Phase 8: `AdmissionNotice` (database + mail, queued) on: application received, resubmission received, document/medical file sent back (with the remark), exam scheduled/rescheduled (date, time, venue, permit), result released (no decision, no score: "sign in to see it"), medically cleared. Recording a result notifies nobody. `GET /api/notifications`, mark read / read all (UI pending). Scheduler prunes failed jobs. Audit log is fed by every service (staff actions, applicant submissions, sysadmin commands). Tests: 48 passing.

- Demo data: `DemoApplicantsSeeder` (local only) walks 60 applicants through the real services at back-dated times, so statuses, histories and the audit log are consistent. Demo logins: `admissions@plpasig.edu.ph`, `r.dizon@plpasig.edu.ph`, `clinic@plpasig.edu.ph` / `password123`.
- **Admission office panel wired** (data layer only): `core.js` gained `AO.api` (JWT bearer, JSON or file upload), `AO.apiError`, `AO.busy`. `admin-store.js` loads `ADM.state` from `GET /api/admin/state` (demo generator and localStorage removed). `admin-actions.js` keeps each action's instant local update, then sends it to the API on one ordered queue; the server's copy replaces the local one, and a refusal shows the server's message and reloads. `admin.js`: real sign-in (`portal: office`), session restore per tab, sign-out, expired-token handling. Document preview fetches the real file (the one visible change, as the `BACKEND:` comment specified). Verified in headless Edge: officer and clinic flows, persistence across reloads, role refusals, no page errors.
- Fixed: `AuditLogger` now resolves the actor through `auth()` (the request had no user in console runs).

- **Applicant portal wired** (data layer only): sign-in (`portal: applicant`), create account (server errors shown on the matching fields), forgot password, sign-out, session restore. The portal state comes from `GET /api/applicant/portal`; every `save()` sends the form and section progress to the server (debounced). Program options, key dates, the exam schedule, the application number, the offer letter dates, the released result, "Accept offer" and the medical uploads/submit all use the server. A value the server refuses although it looks valid (e.g. an LRN already in use) is shown on its field. **Document cards and "Submit" stay browser-only (H1)**, kept per application in localStorage. Verified in headless Edge: register → fill 3 sections → reload (data back from the server) → sign out → wrong password → passer: accept offer → 3 medical uploads → submit; no page errors.

- Phase 10 (part): `SecurityHeaders` middleware (nosniff, frame, referrer, permissions policy); sample Nginx config for the communication layer in [docs/deploy/nginx.conf](docs/deploy/nginx.conf) (TLS 443, static files, `/api` to PHP-FPM, 6 MB body cap, rate limits, IP allowlist for Pulse, outbound proxy for DMS/Enrollment); GitHub Actions workflow running the test suite; unit tests for the status transition rules. Tests: 51 passing.

- **MySQL (2026-10-17):** migrations, demo seed and all 51 tests pass on MySQL 8.0.46. It caught three problems SQLite ignores: an index name over 64 characters, the temporary application number being longer than its column (this would have broken registration in production), and a test helper with a hard-coded ID. The triggers are confirmed to block UPDATE/DELETE on MySQL. CI now runs the tests on both SQLite and MySQL.

- **Sysadmin tools, Enrollment, setup, performance (2026-10-17):**
  - `app:db-privileges` prints least-privilege MySQL GRANTs: the runtime user gets only SELECT/INSERT on the audit and history tables; a separate migration user does schema changes.
  - `app:backup` zips a database dump (mysqldump, or SQLite `VACUUM INTO`) with the private documents into `storage/app/backups`, keeps the newest 14, and runs nightly at 02:00. Verified on MySQL (the dump includes the 6 audit triggers).
  - Enrollment integration (switched off): an applicant is handed off when medically cleared, as a queued job with `X-API-Key` and the same `Idempotency-Key` on every retry (5 tries with backoff). The job runs only after the database commit, so Enrollment being down never undoes staff work. The student-number callback is authenticated by its own inbound key. `enrollment:retry` runs hourly.
  - Setup: `composer setup` → `php artisan app:install` (keys, database check with clear messages, MySQL trigger-setting check, migrations, seeding only when empty). `php artisan app:start` runs the web server, queue worker and scheduler together. Verified from a fresh clone (SQLite and MySQL error paths).
  - Tests: 58 passing.

- **Updated plan (2026-10-20)**, from the updated Solution Design Document; see "What changed" at the top of [DEVELOPMENT_PLAN.md](DEVELOPMENT_PLAN.md). One migration (`2026_10_20_000100_create_updated_plan_tables`) adds colleges, interview schedules/assignments, trusted devices, API clients and the DMS outbox, and the new columns (QR token, offer deadline, falsification flag, student number, privacy acceptance, …).
  - **Roles:** five (applicant, admission, college_dean, clinic, sysadmin). Colleges own programs; a dean sees only their college.
  - **DMS (INT-01):** every record change goes into `dms_outbox` and is sent by a queued job with an Idempotency-Key; a DMS outage never blocks staff work. `fake` and `http` drivers (`DMS_DRIVER`). `dms:outbox` shows/retries/resyncs; retries hourly.
  - **Enrollment (D12):** the direct handoff (`SendToEnrollment`, `enrollment_handoffs`, `enrollment:retry`) is removed; the Admitted flag goes to the DMS. The student-number callback stays, off.
  - **Exam (FR-06, FR-07):** permit PDF with a signed QR code, QR check-in (exam day only), printable application form, reminder the day before, optional self-scheduling (`EXAM_SELF_SCHEDULE`).
  - **Offer (FR-09):** releasing a pass issues the acceptance letter (PDF) with a deadline; accepting takes the slot in a locked transaction and opens medical; unaccepted offers are forfeited by the scheduler (`admission:deadlines`).
  - **Interview (FR-17):** personnel schedule and assign; the dean records grade (never shown to the applicant) and Passed/Failed; programs flagged `requires_interview`.
  - **Security (NFR-04, NFR-05):** email verification before submitting, privacy-notice acceptance, possible-duplicate flag, TOTP 2FA for staff and email codes for applicants on new devices/slot acceptance (both behind switches until the UI has a code screen), API clients with IP allowlist, medical file openings audited. `user:create`, `user:2fa`, `api-client:create`.
  - **Reports (FR-13):** 8 role-scoped reports as JSON, Excel or PDF; exports audited.
  - **Ops (Phase 14):** Pulse and Log Viewer (sysadmin only), `/up`, encrypted backups with an off-server copy (`BACKUP_REMOTE_DISK`), ClamAV upload scan (`MALWARE_SCANNER`), [AWS deployment guide](docs/DEPLOYMENT_AWS.md), manual GitHub Actions deploy (`deploy.yml`: the full test suite on SQLite and MySQL, then SSH deploy of the tested commit, migrations as the migration user), [sysadmin runbook](docs/RUNBOOK.md), [API reference](docs/API.md).
  - Tests: 89 passing.

## Performance (measured 2026-10-17, local Windows machine, demo data)

You said this is a demo system, so the goal is that it runs smoothly at demo size, not a capacity test.

| What | Time |
|---|---|
| Applicant page / office page (HTML) | 55–65 ms |
| CSS/JS files | 2–4 ms |
| Sign-in (password check is deliberately slow) | ~0.5 s |
| Applicant portal data | ~160 ms (14 queries, was 24) |
| Autosave of the form | ~170 ms |
| Admission office: full data on sign-in | ~160 ms (114 KB) |
| Sign-in → dashboard shown | 1.6 s |
| Opening any admin screen | 20–150 ms |
| An action (e.g. verify a document) | ~10 ms on screen; saved in the background |

**Improvements made:**
- The office data is built from plain database rows, not full model objects: 7× faster with identical output, checked by comparing old and new JSON.
- It's streamed in batches of 1,000, so memory stays flat (27 MB instead of 400 MB) however many applicants there are.
- Admin screens use a cached lookup index instead of scanning every applicant: the Entrance exams screen is 3× faster.
- Slot counts take one query instead of one per program.
- gzip in the Nginx sample.

**Headroom (one-off check, then the demo data was restored):**
- **2,000 applicants:** the full office data takes ~0.6 s.
- **~20,000 applicants** (the paper's figure): the office sign-in takes ~5–7 s and the Reports screen ~4 s. Every other screen and action stays under ~0.5 s.

If the system ever goes past demo size, the fix is paging the office screens (R3). `php artisan db:seed --class=LoadTestSeeder` (local only) recreates the large dataset for measuring.

## Remaining work

| # | Item | Status |
|---|---|---|
| R1 | Wire the applicant document cards and "Submit" | Waiting on open question 1 (H1) |
| R2 | Run on MySQL | ✅ Locally (MySQL 8.0.46, CI too). Still to do on RDS |
| R3 | Server-side paging for the office screens | Not needed at demo size (see Performance). Needs view changes if the system grows |
| R4 | DMS driver against the real API | Waiting on open question 5 |
| R5 | Enrollment integration | ✅ Via the DMS (D12). Student-number callback built, off: waiting on open question 4 |
| R6 | DB least privilege | ✅ `php artisan app:db-privileges` (apply in production) |
| R7 | Backups | ✅ `php artisan app:backup`, nightly. Also keep RDS automated backups on |
| R8 | Pulse dashboard access in production | Waiting on open question 12 |
| R9 | Load test | ✅ Done once (see Performance); not needed further for a demo |
| R10 | UAT with the admission office on the existing screens | To do |
| R11 | Production deployment (EC2, RDS, Nginx, TLS, mail credentials) | Guide, Nginx config and deploy workflow ready ([docs/DEPLOYMENT_AWS.md](docs/DEPLOYMENT_AWS.md)). To do: create the server, add the GitHub secrets, restore drill |
| R12 | Turn on 2FA (`STAFF_2FA_REQUIRED`, `APPLICANT_EMAIL_2FA`) | Waiting on the UI code screen (open question 7) |
