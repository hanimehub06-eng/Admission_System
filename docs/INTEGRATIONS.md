# Integrations

How the Online Admission System (OAS) connects to the other university systems of the ERP. Every app-to-app call follows the **School Systems API Standard** ([API_Guide.md](API_Guide.md)). Interfaces marked **waiting** are built and tested, and switch on through `.env` once the other system gives us a URL and token.

## Common rules (School Systems API Standard)

- **No system calls the OAS.** The OAS pulls from EMS and sends to the DMS and Enrollment. There are no inbound API keys.
- **Outbound:** `Authorization: Bearer <token>` (one token per system, issued by that system, kept in `.env`), `Accept`/`Content-Type: application/json`, base URLs ending in `/api/v1`. Never logged.
- **Every POST** carries an `Idempotency-Key` (a UUID, the same on every retry). The DMS and Enrollment sends go through the `dms_outbox` table: a queued job delivers each row after the database commit and retries with backoff. An outage never blocks staff work. `php artisan dms:outbox` shows what is pending or failed, and `--retry` (also run hourly) sends it again.
- **Data:** `snake_case`, dates `YYYY-MM-DD`, timestamps in UTC (`2027-03-10T02:15:00Z`), `null` for missing values. Official IDs: the applicant's **reference number** (`AD-2026-00001`, ours), EMS's `employee_no`, the DMS's document and version UUIDs.
- **Our contract:** [openapi.json](openapi.json) defines the JSON we send to Enrollment. Update it in the same PR as any change.

| ID | System | Direction | Status | Section |
|---|---|---|---|---|
| INT-A | Employee Management System (EMS): staff status | OAS ← EMS (we pull) | Built, **waiting** for an EMS token (`EMS_BASE_URL` empty) | [1](#1-ems-staff-accounts-fr-30) |
| INT-B | Enrollment System: admitted applicants | OAS → Enrollment (we send JSON) | Built, **waiting** for their endpoint (`ENROLLMENT_DRIVER=fake`) | [2](#2-enrollment-system-admitted-applicants-fr-11-fr-29) |
| INT-D | Document Management System (DMS) | OAS → DMS | Built to the DMS `openapi.json`, **waiting** for a DMS token (`DMS_DRIVER=fake`) | [3](#3-document-management-system-dms) |
| INT-E | SMS provider | OAS → provider | Built, off (`SMS_DRIVER=none`) | [5](#5-sms-provider-fr-13) |
| INT-F | Email (Gmail SMTP, Brevo failover) | OAS → provider | Live | `.env` `MAIL_*` |
| INT-G | CAPTCHA (Cloudflare Turnstile or Google reCAPTCHA) | OAS → provider | Built, off (`CAPTCHA_DRIVER=none`) | [6](#6-captcha-on-registration-nfr-06) |

---

## 1. EMS: staff accounts (FR-30)

EMS owns employees (`employee_no`, e.g. `26-00001`), but it has no Admission roles. So:

- The **Super Admin** creates staff accounts in **Admin → Accounts** and gives the role there, with the person's **EMS employee no.** (optional, `NN-NNNNN`). Until EMS is connected, demo accounts and `php artisan user:create` still work.
- Every night at 01:30 (Asia/Manila), `php artisan ems:sync-staff` calls `GET {EMS_BASE_URL}/employees/{employee_no}` (EMS permission `employees:read`) for each active linked account:
  - EMS says `inactive`, or `404` → the account is **disabled** (audited as *API · ems*). It is never re-enabled automatically: the Admin does that.
  - The name changed in EMS → the account name follows (audited, before and after).
  - Network error, 429 or 5xx → retried (1 s, 4 s, 16 s), then the run **stops without disabling anyone**, and it tries again the next night.
- **To connect:** ask the EMS team for a token with `employees:read`, set `EMS_BASE_URL=https://<ems-host>/api/v1` and `EMS_TOKEN`, then run `php artisan optimize`.

---

## 2. Enrollment System: admitted applicants (FR-11, FR-29)

Admission **gives** the Enrollment System each applicant who is officially admitted (status `admitted`, after medical clearance), as JSON. Admission doesn't make or store student numbers: that is Enrollment's job.

- **When:** once, at the moment the clinic clears the applicant (event `enrollment.admitted` in the outbox).
- **Call:** `POST {ENROLLMENT_URL}` with `Authorization: Bearer {ENROLLMENT_TOKEN}` and `Idempotency-Key`. Body: one applicant ([openapi.json](openapi.json) → `webhooks.admitted-applicant`):

```json
{
  "reference_number": "AD-2026-00001", "lrn": "123456789012",
  "last_name": "Mendoza", "first_name": "Jordan", "middle_name": "Reyes", "sex": "Male", "birthday": "2008-03-14",
  "email": "jordan@example.com", "phone": "+63 917 123 4567", "address": "12 Mabini St.",
  "is_pasig_resident": true, "district": 1, "barangay": "Kapitolyo",
  "last_school": "Rizal High School", "school_type": "Public", "strand": "STEM",
  "program": { "code": "BSIT", "name": "BS Information Technology" },
  "college": { "code": "CCS", "name": "College of Computer Studies" },
  "admitted_at": "2027-03-10T02:15:00Z"
}
```

- Exam scores and interview grades never leave the OAS. The documents reach Enrollment through the DMS applicant packet ([§3](#3-document-management-system-dms)).
- Staff can download the same list as the **Admitted applicants** report (`/api/reports/admitted-applicants?format=csv|xlsx|pdf`). The fields live in `App\Services\AdmittedApplicantsService`.
- **Until connected:** `ENROLLMENT_DRIVER=fake` writes each send to `storage/app/private/enrollment-fake.jsonl`, and the outbox marks it sent.
- **To connect** (⏳ waiting on the Enrollment team for their URL, a token, and confirmation of this body):
  1. Set `ENROLLMENT_DRIVER=http`, `ENROLLMENT_URL` (proposed: `https://<enrollment-host>/api/v1/admitted-applicants`) and `ENROLLMENT_TOKEN`, then run `php artisan optimize`.
  2. Applicants admitted while on `fake` are already marked sent. To send one again: `php artisan tinker` → `app(App\Services\Dms\DmsSync::class)->admitted(App\Models\Application::where('number', 'AD-2026-00001')->first());`
  3. If Enrollment wants a different body, change `AdmittedApplicantsService::record()` and `openapi.json` together.

---

## 3. Document Management System (DMS)

Built against the DMS team's `docs/openapi.json` (monorepo `apps/document-management`). `DMS_BASE_URL=https://<dms-host>/api/v1`, `DMS_TOKEN` = the connector token the DMS issues for source `OAS`.

- **Record changes (`DMS_DRIVER`):** every change (applicant data, document uploaded or reviewed, exam scheduled, result, offer, admission completed, …) becomes one `POST /history` entry: `{ event_id, record_type, record_id, revision, operation: "upsert", payload }`. `record_type` is `applicant` for the full record, or the event type (e.g. `status.changed`). `record_id` is the reference number. `revision` is the outbox row id, which only grows.
- **Applicant packet:** on admission, the verified admission documents stored in the DMS are released with `POST /applicant-packets`: `{ event_id, applicant_id: <reference number>, revision, released: true, admitted: true, verified: true, version_ids: [...] }`. Enrollment reads them from the DMS. Nothing is sent if no document is in the DMS (the DMS refuses an empty release).
- **Files (`DOCUMENT_STORE`):** `local` (private disk, the default) or `dms`. With `dms`, a first upload goes to `POST /documents` (multipart: `file`, `title`, `classification: Confidential`, `reference` = reference number, `category` = requirement key). A replacement goes to `POST /documents/{id}/versions` with the document's current `revision`. Files are opened through `GET /files/{version_id}`, and only through an authorized OAS route. The OAS keeps `"{document_id}:{version_id}"`. Files already stored stay readable from where they were saved.
- **Medical files never go to the DMS:** it refuses them, so they stay on the OAS private disk even with `DOCUMENT_STORE=dms`, and they are never in a packet.
- **To connect:** set `DMS_DRIVER=http`, `DMS_BASE_URL`, `DMS_TOKEN` (and `DOCUMENT_STORE=dms` for files), then run `php artisan optimize` and `php artisan dms:outbox --retry`. Use `--resync=AD-2026-00001` to send one applicant's record again.

---

## 5. SMS provider (FR-13)

`SMS_DRIVER=none` (default), `log` (written to the log, for testing) or `http`. With `http`, each exam schedule, exam reminder and result-release notice also goes by SMS:

```
POST {SMS_URL}
X-API-Key: {SMS_API_KEY}
{ "to": "+639171234567", "message": "PLP Admissions: Your entrance exam is on …", "sender": "{SMS_SENDER}" }
```

Adjust the body in `App\Notifications\SmsChannel` to the provider chosen. Sent from the queue; a failure is retried and never blocks the email or in-app notice.

---

## 6. CAPTCHA on registration (NFR-06)

Set `CAPTCHA_DRIVER=turnstile` (Cloudflare, free) or `recaptcha` (Google), with `CAPTCHA_SITE_KEY` and `CAPTCHA_SECRET`, then `php artisan optimize`. The **Create account** form then shows the widget and the server checks its token with the provider; if the provider can't be reached, registration is refused (fails closed).
