<?php

namespace App\Notifications;

use App\Services\TwoFactorService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent right away (not queued): the user is waiting for it on screen.
 */
class TwoFactorCode extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        private int $minutes,
        private string $purpose = TwoFactorService::LOGIN,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $intro, $ignore] = match ($this->purpose) {
            TwoFactorService::SLOT_ACCEPTANCE => ['Your code to accept your slot', 'Use this code to confirm that you accept your slot:', "If this wasn't you, change your password."],
            TwoFactorService::REGISTRATION => ['Confirm your PLP admission account', 'Use this code to confirm your email address and finish creating your account:', "If you didn't sign up, you can ignore this email."],
            default => ['Your PLP admission sign-in code', 'Use this code to finish signing in:', "If this wasn't you, change your password."],
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($intro)
            ->line("**{$this->code}**")
            ->line("It expires in {$this->minutes} minutes. {$ignore}");
    }
}
