<?php

namespace App\Enums;

/**
 * The five user groups of the updated paper (§4, decision D13). The
 * admission personnel key stays "admission" (decision T10) so existing
 * accounts and tokens keep working.
 */
enum Role: string
{
    case Applicant = 'applicant';
    case Admission = 'admission';      // Admission Personnel
    case CollegeDean = 'college_dean'; // interviews for the dean's own college (FR-17)
    case Clinic = 'clinic';            // university clinic (medical requirements)
    case Sysadmin = 'sysadmin';        // accounts, backups, security settings

    public function label(): string
    {
        return match ($this) {
            self::Applicant => 'Applicant',
            self::Admission => 'Admission Personnel',
            self::CollegeDean => 'College Dean',
            self::Clinic => 'University Clinic',
            self::Sysadmin => 'System Administrator',
        };
    }

    /** Roles that sign in through the office side of the API. */
    public function isStaff(): bool
    {
        return in_array($this, [self::Admission, self::CollegeDean, self::Clinic], true);
    }

    /** Staff roles use an authenticator app for 2FA; applicants get an email code. */
    public function twoFactorMethod(): string
    {
        return $this === self::Applicant ? 'email' : 'totp';
    }
}
