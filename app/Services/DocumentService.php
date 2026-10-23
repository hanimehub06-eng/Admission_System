<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Events\ApplicantAdmitted;
use App\Events\DocumentReviewed;
use App\Exceptions\BusinessRuleException;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Requirement;
use App\Models\User;
use App\Services\Dms\DmsSync;
use App\Services\Documents\DocumentStorage;
use App\Services\Security\MalwareScanner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Document upload, review, resubmission and photocopies (FR-03, FR-04) and
 * medical requirements (FR-10, NFR-05). Admission documents are reviewed by
 * admission personnel; medical files only by the clinic (confidential).
 * Every upload is virus-scanned, then stored (the DMS in production).
 */
class DocumentService
{
    public function __construct(
        private DocumentStorage $storage,
        private ApplicationStatusService $status,
        private AuditLogger $audit,
        private MalwareScanner $scanner,
        private DmsSync $dms,
    ) {}

    public function requirement(string $key, string $stage): Requirement
    {
        $requirement = Requirement::where('key', $key)->where('stage', $stage)->first();
        if (! $requirement || ! $requirement->is_uploadable) {
            throw new BusinessRuleException('There is no such document requirement.');
        }

        return $requirement;
    }

    public function current(Application $application, Requirement $requirement): ?ApplicationDocument
    {
        return $application->documents()->where('requirement_id', $requirement->id)->first();
    }

    /* ------------------------------------------------------------------
       Applicant side
       ------------------------------------------------------------------ */

    public function upload(Application $application, Requirement $requirement, UploadedFile $file, User $by): ApplicationDocument
    {
        $existing = $this->current($application, $requirement);
        $requirement->stage === Requirement::STAGE_MEDICAL
            ? $this->assertMedicalUploadAllowed($application, $existing)
            : $this->assertAdmissionUploadAllowed($application, $requirement, $existing);

        $scan = $this->scanner->scan($file->getRealPath());
        $stored = $this->storage->store($file, $application, $requirement, $existing);

        return DB::transaction(function () use ($application, $requirement, $file, $by, $existing, $stored, $scan) {
            $existing?->update(['is_current' => false]);
            $document = $application->allDocuments()->create([
                'requirement_id' => $requirement->id,
                'version' => ($existing->version ?? 0) + 1,
                'status' => ApplicationDocument::PENDING,
                'storage_driver' => $stored->driver,
                'storage_ref' => $stored->ref,
                'checksum' => $stored->checksum,
                'scan_status' => $scan,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $by->id,
            ]);

            $this->dms->record('document.uploaded', $application, [
                'requirement' => $requirement->key,
                'stage' => $requirement->stage,
                'version' => $document->version,
                'dms_document_id' => $stored->driver === 'dms' ? $stored->ref : null,
                'confidential' => $requirement->stage === Requirement::STAGE_MEDICAL,
            ]);

            // A replacement for a file that was sent back is worth an audit line
            if ($existing?->status === ApplicationDocument::RESUBMIT) {
                $this->audit->record('document.replaced', "Uploaded a new \"{$requirement->title}\"", $document, $application);
                if ($requirement->stage === Requirement::STAGE_MEDICAL) {
                    $application->update(['medical_submitted_at' => null]); // submit again after replacing
                }
            }

            return $document;
        });
    }

    /** Remove a file before it has been sent (draft, or medical not yet submitted). */
    public function remove(Application $application, Requirement $requirement): void
    {
        $existing = $this->current($application, $requirement);
        if (! $existing) {
            return;
        }
        $locked = $requirement->stage === Requirement::STAGE_MEDICAL
            ? $application->medical_submitted_at !== null
            : $application->status !== ApplicationStatus::Draft;
        if ($locked || $existing->status !== ApplicationDocument::PENDING) {
            throw new BusinessRuleException("This file has been sent and can't be removed.");
        }
        $existing->update(['is_current' => false]);
    }

    public function submitMedical(Application $application): void
    {
        if (! $application->medicalApplies()) {
            throw new BusinessRuleException('Medical requirements open after you accept your slot.');
        }
        if ($application->medical_submitted_at) {
            throw new BusinessRuleException('Your medical requirements were already sent to the clinic.');
        }
        $docs = $application->documents()->get()->keyBy('requirement_id');
        $missing = Requirement::medical()->where('is_active', true)->get()
            ->filter(fn ($r) => ! $docs->has($r->id) || $docs[$r->id]->status === ApplicationDocument::RESUBMIT);
        if ($missing->isNotEmpty()) {
            throw new BusinessRuleException('Upload these first: '.$missing->pluck('title')->implode(', ').'.');
        }

        $application->update(['medical_submitted_at' => now()]);
        $count = $docs->filter(fn ($d) => $d->requirement->stage === Requirement::STAGE_MEDICAL)->count();
        $this->audit->record('medical.submitted', "Submitted {$count} medical requirements", $application, $application);
    }

    /* ------------------------------------------------------------------
       Staff side
       ------------------------------------------------------------------ */

    /** Verify, or send back with a remark (required). */
    public function review(Application $application, Requirement $requirement, string $decision, ?string $remark, User $staff): ApplicationDocument
    {
        $this->assertReviewer($staff, $requirement);
        $document = $this->current($application, $requirement);
        if (! $document) {
            throw new BusinessRuleException('Nothing has been uploaded for this requirement yet.');
        }
        $this->assertReviewable($application, $requirement);
        if ($decision === ApplicationDocument::RESUBMIT && blank($remark)) {
            throw new BusinessRuleException('Add a remark so the applicant knows what to fix.');
        }

        DB::transaction(function () use ($application, $requirement, $document, $decision, $remark, $staff) {
            $document->update([
                'status' => $decision,
                'remark' => $decision === ApplicationDocument::RESUBMIT ? $remark : null,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
            ]);

            $medical = $requirement->stage === Requirement::STAGE_MEDICAL;
            $title = $requirement->title;
            $text = match (true) {
                $medical && $decision === ApplicationDocument::VERIFIED => "Verified medical requirement \"{$title}\"",
                $medical => "Asked to resubmit medical requirement \"{$title}\": {$remark}",
                $decision === ApplicationDocument::VERIFIED => "Verified \"{$title}\"",
                default => "Marked \"{$title}\" as requires resubmission: {$remark}",
            };
            $this->audit->record($medical ? 'medical.reviewed' : 'document.reviewed', $text, $document, $application, ['decision' => $decision]);
            $this->dms->record('document.reviewed', $application, ['requirement' => $requirement->key, 'stage' => $requirement->stage, 'decision' => $decision, 'remark' => $document->remark]);

            $medical ? $this->afterMedicalReview($application, $staff) : $this->syncStatus($application, $staff);
        });

        DocumentReviewed::dispatch($document->fresh(), $staff);

        return $document;
    }

    /**
     * The clinic nurse's "Approve" (clinic portal): every medical file is in,
     * sent by the applicant and none is waiting on a replacement. Verifies
     * them all; the office then sees the applicant as Approved.
     */
    public function approveMedical(Application $application, User $staff): int
    {
        $this->assertReviewer($staff, new Requirement(['stage' => Requirement::STAGE_MEDICAL]));
        $this->assertReviewable($application, new Requirement(['stage' => Requirement::STAGE_MEDICAL]));
        if ($this->medicallyCleared($application)) {
            throw new BusinessRuleException('This applicant is already approved.');
        }
        if (! $application->medical_submitted_at) {
            throw new BusinessRuleException('The applicant hasn’t sent their medical requirements yet.');
        }
        $docs = $application->documents()->where('is_current', true)
            ->whereHas('requirement', fn ($q) => $q->where('stage', Requirement::STAGE_MEDICAL)->where('is_active', true))
            ->get();
        if ($docs->count() < Requirement::medical()->where('is_active', true)->count()) {
            throw new BusinessRuleException('A medical file is still missing.');
        }
        if ($docs->contains('status', ApplicationDocument::RESUBMIT)) {
            throw new BusinessRuleException('A file was sent back. Approve once the applicant replaces it.');
        }

        return $this->verifyPending($application, Requirement::STAGE_MEDICAL, $staff, 'Approved the medical requirements and sent the approval to the admission office');
    }

    /** "Verify all pending" on the review screen. Returns how many were verified. */
    public function verifyPending(Application $application, string $stage, User $staff, ?string $description = null): int
    {
        $medical = $stage === Requirement::STAGE_MEDICAL;
        $this->assertReviewer($staff, new Requirement(['stage' => $stage]));
        $this->assertReviewable($application, new Requirement(['stage' => $stage]));

        $pending = $application->documents()
            ->where('status', ApplicationDocument::PENDING)
            ->whereHas('requirement', fn ($q) => $q->where('stage', $stage))
            ->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($application, $pending, $medical, $staff, $description) {
            $pending->each->update(['status' => ApplicationDocument::VERIFIED, 'remark' => null, 'reviewed_by' => $staff->id, 'reviewed_at' => now()]);
            $n = $pending->count();
            $this->audit->record(
                $medical ? 'medical.reviewed' : 'document.reviewed',
                $description ?? ($medical ? "Verified {$n} medical requirement".($n === 1 ? '' : 's') : "Verified {$n} document".($n === 1 ? '' : 's')),
                $application, $application, ['decision' => 'verified', 'count' => $n],
            );
            foreach ($pending as $d) {
                $this->dms->record('document.reviewed', $application, ['requirement' => $d->requirement->key, 'stage' => $d->requirement->stage, 'decision' => ApplicationDocument::VERIFIED, 'remark' => null]);
            }
            $medical ? $this->afterMedicalReview($application, $staff) : $this->syncStatus($application, $staff);
        });
        $pending->each(fn ($d) => DocumentReviewed::dispatch($d->fresh(), $staff));

        return $pending->count();
    }

    /**
     * §9.1: the applicant also hands in a photocopy of each document; staff
     * tick it off. Admission personnel for admission documents, the clinic
     * for medical ones.
     */
    public function markPhotocopy(Application $application, Requirement $requirement, bool $received, User $staff): ApplicationDocument
    {
        $this->assertReviewer($staff, $requirement);
        $document = $this->current($application, $requirement)
            ?? throw new BusinessRuleException('Nothing has been uploaded for this requirement yet.');
        if ($received === ($document->photocopy_received_at !== null)) {
            return $document;
        }

        DB::transaction(function () use ($application, $requirement, $document, $received, $staff) {
            $document->update([
                'photocopy_received_at' => $received ? now() : null,
                'photocopy_received_by' => $received ? $staff->id : null,
            ]);
            $this->audit->record('document.photocopy', ($received ? 'Received the photocopy of' : 'Cleared the photocopy of')." \"{$requirement->title}\"", $document, $application);
            $this->dms->record('document.photocopy', $application, ['requirement' => $requirement->key, 'received' => $received]);
        });

        return $document;
    }

    /** FR-10: the clinic flags a medical file it suspects is falsified, with a note. */
    public function flagFalsification(Application $application, Requirement $requirement, bool $flag, ?string $note, User $staff): ApplicationDocument
    {
        if ($requirement->stage !== Requirement::STAGE_MEDICAL || ! $staff->hasRole('clinic')) {
            throw new AuthorizationException('Only the university clinic can flag medical files.');
        }
        $document = $this->current($application, $requirement)
            ?? throw new BusinessRuleException('Nothing has been uploaded for this requirement yet.');
        if ($flag && blank($note)) {
            throw new BusinessRuleException('Add a note explaining what looks wrong.');
        }

        DB::transaction(function () use ($application, $requirement, $document, $flag, $note, $staff) {
            $document->update([
                'falsification_flag' => $flag,
                'falsification_note' => $flag ? $note : null,
                'falsification_flagged_by' => $flag ? $staff->id : null,
            ]);
            $this->audit->record('medical.flagged', $flag
                ? "Flagged medical requirement \"{$requirement->title}\" as possibly falsified: {$note}"
                : "Cleared the falsification flag on \"{$requirement->title}\"", $document, $application);
            $this->dms->record('document.flagged', $application, ['requirement' => $requirement->key, 'flagged' => $flag, 'note' => $flag ? $note : null]);
            if (! $flag) {
                $this->afterMedicalReview($application, $staff); // the flag may have been the only thing holding clearance
            }
        });

        return $document;
    }

    /** Medical clearance: every active medical requirement verified. */
    public function medicallyCleared(Application $application): bool
    {
        $required = Requirement::medical()->where('is_active', true)->pluck('id');
        $verified = $application->documents()->whereIn('requirement_id', $required)->where('status', ApplicationDocument::VERIFIED)->count();

        return $required->isNotEmpty() && $verified === $required->count();
    }

    /**
     * FR-04: a document sent back moves the application to "Requires
     * resubmission"; once none are left it returns to "Submitted".
     */
    private function syncStatus(Application $application, User $staff): void
    {
        $sentBack = $application->documents()
            ->where('status', ApplicationDocument::RESUBMIT)
            ->whereHas('requirement', fn ($q) => $q->where('stage', Requirement::STAGE_ADMISSION))
            ->exists();

        if ($sentBack && $application->status === ApplicationStatus::Submitted) {
            $this->status->transition($application, ApplicationStatus::Resubmit, $staff);
        } elseif (! $sentBack && $application->status === ApplicationStatus::Resubmit) {
            $this->status->transition($application, ApplicationStatus::Submitted, $staff);
        }
    }

    /**
     * All required medical items verified → Admitted (FR-10), which the DMS
     * records for Enrollment (FR-11, D12). A flagged file blocks clearance.
     */
    private function afterMedicalReview(Application $application, User $staff): void
    {
        if ($application->status !== ApplicationStatus::ForMedical || ! $this->medicallyCleared($application)) {
            return;
        }
        if ($application->documents()->where('falsification_flag', true)->exists()) {
            return;
        }

        $application->update(['admitted_at' => now()]);
        $this->status->transition($application, ApplicationStatus::Admitted, $staff, 'Medically cleared');
        $this->audit->record('medical.cleared', 'Medically cleared · admission complete', $application, $application);
        $this->dms->record('admission.completed', $application, [
            'admitted' => true,
            'admitted_at' => $application->admitted_at->toIso8601String(),
            'program' => $application->offeredProgram?->code,
        ]);
        $this->dms->record('applicant.upserted', $application);
        ApplicantAdmitted::dispatch($application);
    }

    private function assertAdmissionUploadAllowed(Application $application, Requirement $requirement, ?ApplicationDocument $existing): void
    {
        if (! $requirement->is_active || ! $requirement->appliesTo($application->applicant)) {
            throw new BusinessRuleException("\"{$requirement->title}\" isn't required for your application.");
        }
        if ($application->status === ApplicationStatus::Draft) {
            return;
        }
        if ($application->status === ApplicationStatus::Resubmit && (! $existing || $existing->status === ApplicationDocument::RESUBMIT)) {
            return;
        }
        throw new BusinessRuleException('Your documents have been sent to admissions. You can only replace a file admissions sends back.');
    }

    private function assertMedicalUploadAllowed(Application $application, ?ApplicationDocument $existing): void
    {
        if (! $application->medicalApplies()) {
            throw new BusinessRuleException('Medical requirements open after you accept your slot.');
        }
        if (! $existing) {
            return;
        }
        if ($existing->status === ApplicationDocument::VERIFIED) {
            throw new BusinessRuleException('The clinic already verified this file.');
        }
        if ($existing->status === ApplicationDocument::PENDING && $application->medical_submitted_at) {
            throw new BusinessRuleException('The clinic is checking this file.');
        }
    }

    /** Medical files are confidential: clinic only. Admission documents: admission personnel only. */
    private function assertReviewer(User $staff, Requirement $requirement): void
    {
        $role = $requirement->stage === Requirement::STAGE_MEDICAL ? 'clinic' : 'admission';
        if (! $staff->hasRole($role)) {
            throw new AuthorizationException($role === 'clinic'
                ? 'Only the university clinic can review medical requirements.'
                : 'Only admission personnel can review admission documents.');
        }
    }

    private function assertReviewable(Application $application, Requirement $requirement): void
    {
        if ($requirement->stage === Requirement::STAGE_MEDICAL) {
            if (! $application->medicalApplies()) {
                throw new BusinessRuleException('Medical requirements apply only after the applicant accepts a slot.');
            }

            return;
        }
        if (! in_array($application->status, [ApplicationStatus::Submitted, ApplicationStatus::Resubmit], true)) {
            throw new BusinessRuleException('Documents can be reviewed while the application is waiting for review.');
        }
    }
}
