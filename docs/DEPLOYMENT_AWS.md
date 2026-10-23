# Deploying to AWS (EC2, with MySQL on the server or on Amazon RDS)

The system runs locally today. This guide moves it to AWS as the paper describes (§7.2): one EC2 server with Nginx → PHP-FPM → Laravel, a queue worker and the scheduler, and MySQL 8, either on the same server (decision D11, cheapest) or on **Amazon RDS** (the documented upgrade). Nothing in the code depends on where it runs: every host, key and password is in `.env`.

## 1. What you create in AWS

| Piece | Suggested setting | Why |
|---|---|---|
| VPC | Default VPC is fine to start; RDS in **private** subnets | The database is never reachable from the internet |
| EC2 | Ubuntu 24.04 LTS, `t3.small` or larger, 30 GB gp3 | Runs Nginx, PHP 8.4-FPM, queue worker, scheduler, ClamAV |
| Elastic IP | Attached to the EC2 instance | A fixed IP for DNS and the DMS team's allowlist |
| Security group `oas-web` | Inbound 443 and 80 from anywhere; 22 from the office/VPN IP only | Only HTTPS is public |
| RDS (optional) | MySQL 8.0, `db.t3.micro`/`small`, Multi-AZ off for a start, **automated backups on (7+ days)**, storage encrypted | Managed backups and patching |
| Security group `oas-db` | Inbound 3306 **only from `oas-web`** | Only the app server can reach the database |
| RDS parameter group | `log_bin_trust_function_creators = 1` | Lets the migrations create the audit-log triggers (NFR-08); RDS doesn't give the SUPER privilege |
| S3 bucket (optional) | Private, versioning on, default encryption | Off-server backups (`BACKUP_REMOTE_DISK=s3`) and, until the DMS exists, document files (`DOCUMENT_LOCAL_DISK=s3`) |
| IAM role on EC2 | Access to that bucket only | No AWS keys in `.env`: the SDK picks up the instance role |
| Route 53 / DNS | `admission.plpasig.edu.ph` → Elastic IP | TLS certificate with Let's Encrypt |

An Application Load Balancer is not needed for one server. If one is added later, set `TRUSTED_PROXIES` to the load balancer subnets' CIDRs and point its health check at `/up`.

## 2. Prepare the server

```bash
sudo apt update && sudo apt install -y nginx mysql-client unzip git supervisor clamav-daemon
sudo add-apt-repository ppa:ondrej/php -y && sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-sqlite3
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
sudo systemctl enable --now clamav-daemon     # virus scan of uploads (MALWARE_SCANNER=clamav)

sudo mkdir -p /var/www/admission && sudo chown $USER:www-data /var/www/admission
git clone -b backend https://github.com/hanimehub06-eng/Admission_System.git /var/www/admission
cd /var/www/admission
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

## 3. The database

**Option A, MySQL on the same EC2 (D11 default):** `sudo apt install mysql-server`, then as root:

```sql
CREATE DATABASE admission_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SET PERSIST log_bin_trust_function_creators = 1;
```

**Option B, Amazon RDS:** create the instance with the parameter group above, then from the EC2 server:

```bash
mysql -h <endpoint>.rds.amazonaws.com -u admin -p -e "CREATE DATABASE admission_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
sudo curl -o /etc/ssl/certs/rds-global-bundle.pem https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem
```

For the first install, `.env` uses the admin user (step 4). Once the tables exist, switch to least-privilege users: `php artisan app:db-privileges` prints the `CREATE USER`/`GRANT` statements for a migration user and a runtime user that can only SELECT/INSERT on the audit and history tables. Add `--host=%` on RDS (the app connects from the EC2 server, not from localhost; the `oas-db` security group still limits who can reach the database). Run them as the admin user, then put the runtime user in `.env` and use the migration user only for `php artisan migrate --force` during deploys. Run `app:db-privileges` again whenever a migration adds tables.

## 4. `.env` for production

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://admission.plpasig.edu.ph
TRUSTED_PROXIES=127.0.0.1

DB_CONNECTION=mysql
DB_HOST=127.0.0.1                      # or <endpoint>.rds.amazonaws.com
DB_PORT=3306
DB_DATABASE=admission_system
DB_USERNAME=admission_app              # admin user for the first install, then the runtime user (step 3)
DB_PASSWORD=...
MYSQL_ATTR_SSL_CA=/etc/ssl/certs/rds-global-bundle.pem   # RDS only: TLS to the database

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

MAIL_MAILER=failover                   # Gmail SMTP, Brevo as backup (FR-12)
GMAIL_USERNAME=...
GMAIL_APP_PASSWORD=...
BREVO_SMTP_USERNAME=...
BREVO_SMTP_KEY=...

MALWARE_SCANNER=clamav
CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl

DMS_DRIVER=fake                        # http once the DMS team publishes the API (D15)
DOCUMENT_STORE=local                   # dms once the DMS exists
DOCUMENT_LOCAL_DISK=s3                 # optional: keep files in S3 instead of on the server

AWS_DEFAULT_REGION=ap-southeast-1      # Singapore, nearest region (confirm with PLP, §8.3)
AWS_BUCKET=plp-oas-files
BACKUP_REMOTE_DISK=s3
BACKUP_PASSWORD=...                    # also store it outside AWS: without it the backups can't be opened

SYSADMIN_EMAIL=sysadmin@plpasig.edu.ph
```

Then:

```bash
php artisan app:install        # keys, DB check, migrations, starting data (no demo data outside "local")
php artisan optimize           # caches config, routes and views
# PHP-FPM (www-data) and the deploy user (you, in the www-data group) both write here
sudo usermod -aG www-data $USER
sudo chown -R $USER:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} +
```

Copy the sysadmin password `app:install` prints. Never commit `.env`.

## 5. Nginx, TLS, workers

- Nginx: copy [deploy/nginx.conf](deploy/nginx.conf) to `/etc/nginx/sites-available/admission`, adjust `server_name` and the allowlisted ranges, enable it, then `sudo certbot --nginx -d admission.plpasig.edu.ph`.
- Queue worker (emails, DMS sync): Supervisor program running `php /var/www/admission/artisan queue:work --sleep=3 --tries=3 --max-time=3600` as `www-data`, `autorestart=true`.
- Scheduler (offer forfeits, reminders, DMS retries, nightly backup): `* * * * * www-data php /var/www/admission/artisan schedule:run >> /dev/null 2>&1` in `/etc/cron.d/admission`.
- Deploys: use the GitHub Actions workflow (§8). By hand it is the same steps: `php artisan down`, `git pull`, `composer install --no-dev -o`, `php artisan optimize:clear`, `php artisan migrate --force` as the migration user, `php artisan optimize`, `php artisan queue:restart` (Supervisor starts the worker again on the new code), `php artisan up`.

## 6. Monitoring and recovery (NFR-01, NFR-06)

- **UptimeRobot:** HTTPS monitor on `https://admission.plpasig.edu.ph/up` every 5 minutes.
- **Pulse** (`/pulse`) and **Log Viewer** (`/log-viewer`): Nginx allows them from the office/VPN range only.
- **Backups:** `app:backup` runs nightly at 02:00 (Manila): an AES-256 encrypted zip of the database dump (plus server-side documents), copied to S3. With RDS, its automated backups and snapshots are a second layer.
- **Restore drill** (target: a new server within 8 hours, RPO 24 h):
  1. Build a server with steps 2–5 (or launch from an AMI of the old one).
  2. Download the latest zip from S3 (`aws s3 cp s3://plp-oas-files/oas-backups/<file> .`) and open it with the backup password (`7z x <file>`).
  3. `mysql -h <host> -u <admin> -p admission_system < database.sql` (the triggers come with it), copy `documents/` back to `storage/app/private/documents` if files were kept on the server.
  4. `php artisan optimize`, start the workers, check `/up`, sign in, then run `php artisan dms:outbox --retry`.
  With RDS you can instead restore a snapshot or a point in time from the RDS console and point `DB_HOST` at it.

## 7. Moving the local data to AWS later

1. Locally: `php artisan app:backup` (MySQL dump + documents in one zip).
2. On AWS: create the empty database (step 3), import `database.sql` with the admin user, copy the documents.
3. `php artisan migrate --force` (runs only migrations the dump doesn't have yet).

The demo data shouldn't go to production: start production with `app:install` on an empty database instead.

## 8. Deploying with GitHub Actions (NFR-09)

[.github/workflows/deploy.yml](../.github/workflows/deploy.yml) runs only when started by hand: **Actions → deploy → Run workflow**, choosing the branch under "Use workflow from". It first runs the full test suite on SQLite and MySQL (the `tests` workflow); only if that passes does it SSH into the server and deploy **the commit that was tested**. Two deploys never run at once.

One-time setup:

1. On the server, create a key pair for deploys and allow it to log in as the deploy user (the owner of `/var/www/admission`, in the `www-data` group, step 4):
   ```bash
   ssh-keygen -t ed25519 -f ~/oas-deploy -N "" -C "github-deploy"
   cat ~/oas-deploy.pub >> ~/.ssh/authorized_keys
   ```
   The security group allows SSH from the office/VPN only, and GitHub's runners aren't in that range: either add GitHub's published `actions` ranges (`https://api.github.com/meta`) to port 22, or run the workflow on a self-hosted runner inside the VPC.
2. On the server, put the migration user from `app:db-privileges` in `~/.oas-migrate` (readable only by the deploy user, never in Git):
   ```bash
   printf 'DB_USERNAME=admission_migrate\nDB_PASSWORD=...\n' > ~/.oas-migrate && chmod 600 ~/.oas-migrate
   ```
   Without this file, migrations run as the `.env` user (fine while that is still the admin user).
3. In GitHub, **Settings → Secrets and variables → Actions**, add `EC2_HOST` (the Elastic IP or DNS name), `EC2_USER` (the deploy user), `EC2_SSH_KEY` (the contents of `~/oas-deploy`, then delete that file from the server) and, if the app isn't in `/var/www/admission`, `DEPLOY_PATH`.
4. Optional: in **Settings → Environments → production**, add required reviewers so every deploy needs an approval.

If a step fails, the site stays in maintenance mode and the run shows the failing step; by then the server already has the new code. Fix the cause and run the workflow again. To go back to the previous version instead, on the server: `git checkout -B <branch> <previous commit>`, `composer install --no-dev -o`, `php artisan optimize`, `php artisan queue:restart`, `php artisan up` (if a migration had already run, restore the database too, §6).
