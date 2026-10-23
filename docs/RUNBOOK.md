# System Administrator Runbook

Day-to-day operations for the PLP Online Admission System on the server (Phase 14, paper §7.1). Run every command from the app folder (`/var/www/admission` on EC2, the project folder locally). Server setup and deploys are in [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md).

Account, key and backup commands write an audit-log entry; offers forfeited or records re-sent by the other commands are audited by the services they call. Nobody, the sysadmin included, can edit or delete audit entries: the database triggers refuse it (FR-15, NFR-08).

## Accounts

| Task | Command |
|---|---|
| Create a staff account (prints a temporary password) | `php artisan user:create <email> "<Full Name>" admission` |
| …a College Dean (scoped to one college) | `php artisan user:create <email> "<Name>" college_dean --college=CCS` |
| …Clinic staff / another sysadmin | role `clinic` / `sysadmin` |
| Disable or re-enable an account (takes effect on the next request) | `php artisan user:active <email> off` / `on` |
| Turn on authenticator-app 2FA (prints the secret to add to the app) | `php artisan user:2fa <email> totp` |
| Email-code 2FA instead / reset 2FA (lost phone) | `php artisan user:2fa <email> email` / `off`, then `totp` again |

Applicants create their own accounts; to block one, use `user:active <email> off`.

**2FA switches (`.env`):** `APPLICANT_EMAIL_2FA=true` emails applicants a 6-digit code when they create an account (it also confirms their email) and when they sign in on a new device; the portal has the code screen. `APPLICANT_ACCEPTANCE_2FA` (code again at slot acceptance) and `STAFF_2FA_REQUIRED` stay `false` until the "Accept offer" button and the office/clinic sign-in screens can ask for a code. With staff 2FA off, `user:2fa` still records the method, but sign-in doesn't ask for a code.

## Keys for other systems

| Task | Command |
|---|---|
| Create or rotate a system's API key (shown once) | `php artisan api-client:create enrollment --ips=203.0.113.10` |
| Switch a system off | `php artisan api-client:create enrollment --disable` |

Rotating replaces the old key immediately: give the new one to the other team first. The DMS key we *send* is `DMS_API_KEY` in `.env`; after changing it run `php artisan optimize`.

Other secrets: `APP_KEY` encrypts stored data (2FA secrets) and must never change on a running system. To rotate `JWT_SECRET`, run `php artisan jwt:secret --force` then `php artisan optimize`: everybody is signed out.

## Document Management System (DMS) sync

Every record change goes into a DMS outbox and is sent by the queue worker; failed sends retry hourly.

| Task | Command |
|---|---|
| See what's pending or failed | `php artisan dms:outbox` |
| Send pending and failed events again now | `php artisan dms:outbox --retry` |
| Re-send one applicant's whole record (after a DMS restore or mismatch) | `php artisan dms:outbox --resync=AD-2026-00001` |

Sends carry an `Idempotency-Key`, so retrying never creates duplicates in the DMS.

## Deadlines and reminders

The scheduler runs these by itself (cron → `schedule:run` every minute). Run by hand only to catch up after an outage:

```bash
php artisan admission:deadlines                         # all three
php artisan admission:deadlines --only=forfeit          # close offers past their acceptance deadline
php artisan admission:deadlines --only=offer-reminders  # remind applicants before the deadline
php artisan admission:deadlines --only=exam-reminders   # remind applicants before their exam
```

The acceptance window is the admission period's `acceptance_window_days`, filled from `ACCEPTANCE_WINDOW_DAYS` (default 7) when the period is seeded. The panel has no field for it; change it in the database if PLP sets another value (D16).

## Backups and restore (NFR-06)

- Nightly at 02:00 (Manila): `php artisan app:backup` → encrypted zip (`BACKUP_PASSWORD`, AES-256) in `storage/app/backups`, newest 14 kept (`BACKUP_KEEP`), copied to `BACKUP_REMOTE_DISK` when set.
- Back up now (e.g. before a risky change): `php artisan app:backup`.
- **Keep `BACKUP_PASSWORD` somewhere outside the server.** Without it the backups can't be opened.
- Restore steps and the drill: [DEPLOYMENT_AWS.md §6](DEPLOYMENT_AWS.md#6-monitoring-and-recovery-nfr-01-nfr-06). Do one drill before go-live and record how long it took (target under 8 hours).

## Database users

`php artisan app:db-privileges --host=%` prints the `CREATE USER`/`GRANT` statements: the runtime user can only SELECT/INSERT on the audit and history tables; a separate migration user changes the schema during deploys. Run it again after a release adds tables, and apply the output as the database admin.

## Monitoring (NFR-01)

| What | Where | Who |
|---|---|---|
| Health check | `https://<host>/up` (UptimeRobot, every 5 min) | public |
| Requests, slow queries, jobs, exceptions | `/pulse` | sysadmin, office/VPN network only |
| Application logs | `/log-viewer` | sysadmin, office/VPN network only |
| Queue failures | `php artisan queue:failed`, retry with `php artisan queue:retry all` | SSH |

If emails or DMS events stop: check the worker (`sudo supervisorctl status`), then `php artisan queue:failed` and `php artisan dms:outbox`.

## After a deploy or a `.env` change

```bash
php artisan optimize        # re-cache config, routes, views
php artisan queue:restart   # the worker picks up the new code/config
```

Deploys normally go through the GitHub Actions workflow, which does this for you ([DEPLOYMENT_AWS.md §8](DEPLOYMENT_AWS.md#8-deploying-with-github-actions-nfr-09)).
