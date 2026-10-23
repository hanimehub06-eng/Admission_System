<?php

namespace App\Listeners;

use App\Enums\ApplicationStatus;
use App\Events\ApplicationStatusChanged;
use App\Events\DocumentReviewed;
use App\Events\ExamScheduled;
use App\Events\ResultReleased;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ExamSession;
use App\Models\Requirement;
use App\Notifications\AdmissionNotice;
use Illuminate\Events\Dispatcher;

/**
 * Which events notify the applicant (FR-12): in-app and email, queued.
 * A recorded result is not one of them: applicants hear about results only
 * when they are released, and never see a score or interview grade.
 */
class SendAdmissionNotices
{
    public function onStatusChanged(ApplicationStatusChanged $event): void
    {
        $from = $event->from;
        $to = $event->to;

        if ($to === ApplicationStatus::Submitted && $from === ApplicationStatus::Draft) {
            $this->notify($event->application, 'submitted', 'Application received',
                "We received your application {$event->application->number}. Admissions usually reviews it within 3 to 5 working days.");
        } elseif ($to === ApplicationStatus::Submitted && $from === ApplicationStatus::Resubmit && $this->byApplicant($event->application)) {
            $this->notify($event->application, 'resubmitted', 'Replacement documents received',
                'Thanks for sending the replacement files. Admissions will check them again.');
        } elseif ($from === ApplicationStatus::ForInterview) {
            // Offered or Interview failed: same wording, the decision is read in the portal
            $this->notify($event->application, 'interview_result', 'Your interview result is ready',
                'Your interview result has been recorded. Sign in to the applicant portal to see it.');
        } elseif ($to === ApplicationStatus::Forfeited) {
            $this->notify($event->application, 'offer_forfeited', 'Your offer of admission has lapsed',
                'The deadline to accept your slot has passed, so the offer was forfeited. Contact the admission office if you think this is a mistake.');
        } elseif ($to === ApplicationStatus::Admitted) {
            $this->notify($event->application, 'medical_cleared', 'You are medically cleared',
                'The university clinic verified your medical requirements. Your admission is complete.');
        }
    }

    public function onDocumentReviewed(DocumentReviewed $event): void
    {
        $document = $event->document;
        $requirement = $document->requirement;
        $application = $document->application;
        $medical = $requirement->stage === Requirement::STAGE_MEDICAL;

        if ($document->status === ApplicationDocument::RESUBMIT) {
            $who = $medical ? 'The university clinic' : 'Admissions';
            $this->notify($application, $medical ? 'medical_resubmit' : 'document_resubmit', "Please replace your {$requirement->title}",
                "{$who} asked you to upload a new \"{$requirement->title}\": {$document->remark}");
        }
    }

    public function onExamScheduled(ExamScheduled $event): void
    {
        $s = $event->session;
        $time = ExamSession::BATCHES[$s->batch]['time'];
        $permit = $event->application->examRegistration?->permit_no;
        $this->notify($event->application, 'exam_scheduled', 'Your entrance exam schedule',
            "Your entrance exam is on {$s->date->format('l, F j, Y')}, {$time}, at {$s->venue}. Exam permit {$permit}. Bring your printed permit and application form.");
    }

    public function onResultReleased(ResultReleased $event): void
    {
        // No decision in the email: the applicant reads it (and any offer deadline) in the portal
        $this->notify($event->application, 'result_released', 'Your admission result is ready',
            'Your entrance exam result has been released. Sign in to the applicant portal to see it.');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            ApplicationStatusChanged::class => 'onStatusChanged',
            DocumentReviewed::class => 'onDocumentReviewed',
            ExamScheduled::class => 'onExamScheduled',
            ResultReleased::class => 'onResultReleased',
        ];
    }

    private function notify(Application $application, string $kind, string $title, string $message): void
    {
        $application->applicant->user->notify(new AdmissionNotice($kind, $title, $message, $application->number));
    }

    /** Resubmit → Submitted happens on the applicant's resubmission, or when staff take back their own request. */
    private function byApplicant(Application $application): bool
    {
        $last = $application->statusHistory()->latest('id')->first();

        return $last && $last->changed_by === $application->applicant->user_id;
    }
}
