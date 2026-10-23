<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An update about the applicant's admission (FR-12, decision T3): stored
 * in-app and emailed (Gmail SMTP, Brevo failover) from the queue.
 *
 * Never carries a score (NFR-02). Result notices only say a result is
 * ready; the applicant reads it in the portal.
 */
class AdmissionNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $kind,      // submitted, document_resubmit, exam_scheduled, result_released…
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $applicationNumber = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'application' => $this->applicationNumber,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title.' · PLP Online Admission')
            ->greeting('Hello '.strtok($notifiable->name, ' ').',')
            ->line($this->message)
            ->action('Open the applicant portal', url('/'))
            ->line('Pamantasan ng Lungsod ng Pasig · Office of Admissions');
    }
}
