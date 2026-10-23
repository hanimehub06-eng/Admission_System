<?php

namespace App\Http\Presenters;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ExamSession;
use App\Models\Requirement;
use App\Services\ApplicationService;
use App\Services\ProgramSuggestionService;
use App\Support\ApplicantForm;

/**
 * Builds the applicant portal's state in the shape applicant.js keeps in
 * localStorage, so the UI renders it unchanged.
 *
 * Built from an explicit allow-list (§8.3): the exam score and the
 * interview grade are never read here, and an unreleased result is never
 * mentioned. Keys the portal doesn't use yet (offer, interview,
 * emailVerified…) are there for the screens still to come.
 */
class ApplicantPortalPresenter
{
    public function __construct(
        private ApplicationService $applications,
        private ProgramSuggestionService $suggestions,
    ) {}

    public function present(Application $application): array
    {
        $application->loadMissing(['applicant.user', 'period', 'examRegistration.session', 'result', 'documents.requirement', 'interview.schedule', 'offeredProgram']);
        $progress = $application->form_progress ?? ['step' => 1, 'completed' => []];
        $released = $application->result?->is_released ? $application->result : null;
        $period = $application->period;

        return [
            'applicationId' => $application->number,
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'phase' => $this->phase($application),
            'emailVerified' => $application->applicant->user->hasVerifiedEmail(),
            'step' => (int) $progress['step'],
            'completed' => array_map('intval', $progress['completed']),
            'data' => $this->applications->formValues($application),
            'submittedAt' => $application->submitted_at?->copy()->setTimezone(config('admission.display_timezone'))->toDateString(),
            'accepted' => $application->status->holdsSlot(),
            'offer' => $this->offer($application),
            'interview' => $this->interview($application),
            'studentNumber' => $application->student_number,
            // The document cards this applicant must fill (H1: one card per official
            // requirement that applies to them), and what they uploaded, by key.
            'requirements' => $this->applications->requiredDocuments($application)
                ->map(fn (Requirement $r) => ['key' => $r->key, 'title' => $r->title, 'icon' => $r->icon])->all(),
            'documents' => $this->documents($application, Requirement::STAGE_ADMISSION),
            'exam' => $this->exam($application),
            'result' => $released ? ['decision' => $released->decision, 'releasedAt' => $released->released_at->toDateString()] : null,
            'medical' => $this->medical($application),
            'programs' => ApplicantForm::programNames(),
            'suggestedPrograms' => $this->suggestions->suggestFor($application->applicant)->pluck('name')->all(),
            'keyDates' => array_values(array_filter([
                ['title' => 'Application deadline', 'date' => $period->application_deadline?->toDateString()],
                ['title' => 'Entrance exam days', 'date' => $period->exam_starts_on?->toDateString(), 'end' => $period->exam_ends_on?->toDateString()],
                ['title' => 'Release of results', 'date' => $period->results_release_on?->toDateString()],
                // No fee (public university): only the applicant's own confirm-by date once offered
                ['title' => 'Offer acceptance deadline', 'date' => $this->local($application->acceptance_deadline)],
            ], fn ($d) => $d['date'] !== null)),
        ];
    }

    /**
     * The portal's four phases (draft → review → exam → result). An application
     * sent back for resubmission reopens as "draft" so the applicant can replace
     * files and submit again with the existing screens.
     */
    private function phase(Application $application): string
    {
        return match ($application->status) {
            ApplicationStatus::Draft, ApplicationStatus::Resubmit => 'draft',
            ApplicationStatus::Submitted => 'review',
            // The portal has no "did not pass" or interview screens yet (UI pending)
            ApplicationStatus::ForExam, ApplicationStatus::Passed, ApplicationStatus::Failed,
            ApplicationStatus::ForInterview, ApplicationStatus::InterviewFailed => 'exam',
            ApplicationStatus::Offered, ApplicationStatus::SlotAccepted, ApplicationStatus::ForMedical,
            ApplicationStatus::Admitted, ApplicationStatus::Forfeited => 'result',
        };
    }

    private function offer(Application $application): ?array
    {
        if (! $application->offered_at) {
            return null;
        }

        return [
            'program' => $application->offeredProgram?->name,
            'deadline' => $this->local($application->acceptance_deadline),
            'acceptedAt' => $this->local($application->offer_accepted_at),
            'forfeited' => $application->status === ApplicationStatus::Forfeited,
        ];
    }

    /** Schedule and outcome only: the grade is Restricted. */
    private function interview(Application $application): ?array
    {
        $i = $application->interview;
        if (! $i) {
            return null;
        }

        return [
            'date' => $i->schedule->date->toDateString(),
            'time' => $i->schedule->time,
            'venue' => $i->schedule->venue,
            'outcome' => $i->outcome,
        ];
    }

    private function local($at): ?string
    {
        return $at?->copy()->setTimezone(config('admission.display_timezone'))->toDateString();
    }

    private function exam(Application $application): ?array
    {
        $reg = $application->examRegistration;
        if (! $reg) {
            return null;
        }

        return [
            'date' => $reg->session->date->toDateString(),
            'time' => ExamSession::BATCHES[$reg->session->batch]['time'],
            'venue' => $reg->session->venue,
            'permit' => $reg->permit_no,
        ];
    }

    private function documents(Application $application, string $stage): array
    {
        return $application->documents
            ->filter(fn (ApplicationDocument $d) => $d->requirement->stage === $stage)
            ->mapWithKeys(fn (ApplicationDocument $d) => [$d->requirement->key => [
                'name' => $d->original_name,
                'size' => $d->size,
                'type' => $d->mime,
                'status' => $d->status,
                'remark' => $d->remark,
            ]])
            ->all();
    }

    /**
     * { files, status } as applicant.js expects: status none → submitted →
     * cleared. A file the clinic sent back is left out of `files`, so its
     * card shows "Upload" again and the applicant can resubmit.
     */
    private function medical(Application $application): array
    {
        $all = $this->documents($application, Requirement::STAGE_MEDICAL);
        $files = array_filter($all, fn ($f) => $f['status'] !== ApplicationDocument::RESUBMIT);
        $required = Requirement::medical()->where('is_active', true)->count();
        $verified = count(array_filter($all, fn ($f) => $f['status'] === ApplicationDocument::VERIFIED));

        $status = match (true) {
            $required > 0 && $verified === $required => 'cleared',
            $application->medical_submitted_at !== null && count($files) === count($all) => 'submitted',
            default => 'none',
        };

        return ['files' => $files, 'status' => $status, 'detail' => $all];
    }
}
