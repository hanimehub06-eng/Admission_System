<?php

return [

    /*
    | Uploads (paper §9.1, D6): PDF/JPG/PNG up to 5 MB, matching the Nginx cap.
    */
    'uploads' => [
        'max_kb' => (int) env('ADMISSION_UPLOAD_MAX_KB', 5120),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png'],
        'image_mimes' => ['jpg', 'jpeg', 'png'],
    ],

    /*
    | Where uploaded files go. "dms" = the Document Management System (the
    | paper's target: files never live in the OAS); "local" = private disk on
    | this server, the development stand-in for the DMS.
    */
    'documents' => [
        'driver' => env('DOCUMENT_STORE', 'local'),
        'local_disk' => env('DOCUMENT_LOCAL_DISK', 'local'),
    ],

    /*
    | Document Management System (paper §9, INT-01). Record sync goes
    | through the dms_outbox table: "fake" writes to
    | storage/app/private/dms-fake until the DMS API exists, "http" calls it.
    */
    'dms' => [
        'driver' => env('DMS_DRIVER', 'fake'),
        'base_url' => env('DMS_BASE_URL'),
        'api_key' => env('DMS_API_KEY'),
        'timeout' => (int) env('DMS_TIMEOUT', 20),
    ],

    /*
    | Virus scan of uploads: "clamav" (production) or "none".
    */
    'scanner' => [
        'driver' => env('MALWARE_SCANNER', 'none'),
        'clamav_socket' => env('CLAMAV_SOCKET', 'tcp://127.0.0.1:3310'),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],

    /*
    | Enrollment (decision D12): it reads admitted applicants from the DMS,
    | so the OAS never calls it. If the team confirms that Enrollment sends
    | the student number back, switch the callback on; the caller
    | authenticates as the "enrollment" API client (api-client:create).
    */
    'enrollment' => [
        'callback_enabled' => (bool) env('ENROLLMENT_CALLBACK_ENABLED', false),
    ],

    /*
    | Offer and slot acceptance (FR-09, D16). The window is set per
    | admission period (acceptance_window_days); this is the default for
    | new periods. Unaccepted offers are forfeited by the scheduler.
    */
    'offers' => [
        'default_window_days' => (int) env('ACCEPTANCE_WINDOW_DAYS', 7),
        'reminder_days_before' => 2,
    ],

    /*
    | Exams (FR-06, FR-07, D17). Staff assign sessions; applicant
    | self-scheduling is off by default. Reminders go out the day before.
    */
    'exams' => [
        'self_schedule' => (bool) env('EXAM_SELF_SCHEDULE', false),
        // Scanning a permit only works on the exam day (off in tests and demos)
        'check_in_same_day_only' => (bool) env('EXAM_CHECK_IN_SAME_DAY_ONLY', true),
    ],

    /*
    | Backups (NFR-06): nightly, encrypted (AES-256) when BACKUP_PASSWORD is
    | set, copied to BACKUP_REMOTE_DISK when set (an off-server disk: s3 on
    | AWS, or a Google Drive disk per the paper).
    */
    'backups' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'keep' => (int) env('BACKUP_KEEP', 14),
        'mysqldump' => env('BACKUP_MYSQLDUMP_PATH', 'mysqldump'),
        'password' => env('BACKUP_PASSWORD'),
        'remote_disk' => env('BACKUP_REMOTE_DISK'),
        'remote_path' => env('BACKUP_REMOTE_PATH', 'oas-backups'),
    ],

    /*
    | Proxies whose X-Forwarded-* headers are trusted: "127.0.0.1" when
    | Nginx runs on the same EC2 server, the load balancer's subnet CIDRs
    | behind an AWS ALB, or "*". Empty locally.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Times are stored in UTC and shown to staff and applicants in Philippine time
    'display_timezone' => env('DISPLAY_TIMEZONE', 'Asia/Manila'),

    // Internal: suggested pass mark shown to staff only (scores are Restricted)
    'passing_score' => 60,

    // Application number prefix: AD-{year}-{5 digits}
    'number_prefix' => 'AD',
    'permit_prefix' => 'EX',

    /*
    | Sign-in security (NFR-04).
    | - require_verified_email: applicants must open the link in the
    |   verification email before they can submit.
    | - applicant_email_2fa: email code when creating an account and when
    |   signing in on a new device (the applicant portal's code screen).
    | - applicant_acceptance_2fa: email code again when accepting a slot.
    |   Off until the portal's "Accept offer" button can ask for the code.
    | - staff_2fa_required: staff can't sign in without authenticator-app
    |   2FA. Off until the office panel has a code screen.
    */
    'require_verified_email' => (bool) env('REQUIRE_VERIFIED_EMAIL', true),
    'applicant_email_2fa' => (bool) env('APPLICANT_EMAIL_2FA', false),
    'applicant_acceptance_2fa' => (bool) env('APPLICANT_ACCEPTANCE_2FA', false),
    'staff_2fa_required' => (bool) env('STAFF_2FA_REQUIRED', false),
    'trusted_device_days' => (int) env('TRUSTED_DEVICE_DAYS', 30),
];
