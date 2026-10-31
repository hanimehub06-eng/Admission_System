<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Application;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Dms\DmsSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Remove a troll or fake applicant: the account can no longer sign in, the
 * uploaded files are deleted and the personal details are wiped, so the email
 * can sign up again. Status history and the audit log are append-only
 * (NFR-03), so the rows stay soft-deleted and anonymous instead of gone.
 */
class ApplicantRemovalService
{
    public function __construct(private AuditLogger $audit, private DmsSync $dms) {}

    public function remove(Application $application, string $reason, User $staff): void
    {
        $applicant = $application->applicant;
        $applications = $applicant->applications()->with('allDocuments', 'examRegistration', 'interviews')->get();
        // Admitted students are already with the Enrollment System and the DMS packet
        $kept = [ApplicationStatus::SlotAccepted, ApplicationStatus::ForMedical, ApplicationStatus::Admitted];
        if ($applications->contains(fn (Application $a) => in_array($a->status, $kept, true))) {
            throw new BusinessRuleException("An applicant who accepted a slot or was admitted can't be removed.");
        }

        DB::transaction(function () use ($application, $applicant, $applications, $reason, $staff) {
            $this->audit->record('applicant.removed', "Removed the applicant and wiped their personal data: {$reason}", $application, $application);

            $disk = Storage::disk(config('admission.documents.local_disk'));
            foreach ($applications as $app) {
                foreach ($app->allDocuments as $document) {
                    if ($document->storage_driver === 'local') {
                        $disk->delete($document->storage_ref);
                    } else {
                        $this->dms->record('document.retention_expired', $app, ['dms_document_id' => $document->storage_ref]);
                    }
                    $document->delete();
                }
                $app->examRegistration?->delete();     // frees the exam seat
                $app->interviews()->delete();
                $app->delete();
            }

            $applicant->forceFill([
                'lrn' => null, 'last_name' => 'Removed', 'first_name' => 'Applicant', 'middle_name' => null,
                'sex' => null, 'birthday' => null, 'civil_status' => null, 'phone' => null, 'address' => null,
                'city' => null, 'province' => null, 'zip' => null, 'barangay' => null, 'guardian_name' => null,
                'guardian_phone' => null, 'guardian_consent_name' => null, 'last_school' => null, 'gwa' => null,
            ])->save();
            $applicant->delete();

            $user = $applicant->user;
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            TrustedDevice::where('user_id', $user->id)->delete();
            $user->notifications()->delete();
            // A soft-deleted user fails the JWT lookup, so any open session ends at once
            $user->forceFill([
                'name' => 'Removed applicant', 'email' => "removed-{$user->id}@invalid", 'password' => Str::random(40),
                'is_active' => false, 'two_factor_method' => null, 'two_factor_secret' => null, 'remember_token' => null,
            ])->save();
            $user->delete();
        });
    }
}
