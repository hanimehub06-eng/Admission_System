<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * FR-01: verification link sent at registration, from the queue. The link
 * (route "verification.verify") is signed and expires after 60 minutes;
 * the portal can ask for a new one (POST /api/auth/email/resend).
 */
class VerifyApplicantEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email · PLP Online Admission')
            ->line('Confirm your email address to finish setting up your applicant account.')
            ->action('Confirm my email', $url)
            ->line('The link works for 60 minutes. If you didn’t create an account, ignore this email.')
            ->line('Pamantasan ng Lungsod ng Pasig · Office of Admissions');
    }
}
