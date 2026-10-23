<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AdmissionPeriod;
use App\Models\Applicant;
use App\Models\ApplicantChange;
use App\Models\Application;
use App\Models\Program;
use App\Models\Requirement;
use App\Models\User;
use App\Notifications\VerifyApplicantEmail;
use App\Services\Dms\DmsSync;
use App\Support\ApplicantForm;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Applicant registration and the application lifecycle (FR-01, FR-02, FR-05).
 */
class ApplicationService
{
    public function __construct(
        private ApplicationStatusService $status,
        private AuditLogger $audit,
        private DmsSync $dms,
    ) {}

    /**
     * New applicant account: user + profile + draft application for the open
     * period (FR-01). The privacy notice was accepted on the form (NFR-05);
     * a verification link is emailed.
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->forceFill(['privacy_accepted_at' => now()])->save();
            $user->assignRole('applicant');

            $applicant = Applicant::create([
                'user_id' => $user->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
            ]);

            $application = $this->start($applicant, $user);
            $this->audit->record('applicant.registered', 'Created an applicant account', $applicant, $application, actor: $user);
            $this->dms->record('applicant.upserted', $application);

            return $user;
        });
        // With applicant 2FA on, the emailed sign-up code confirms the address instead of a link
        if (! config('admission.applicant_email_2fa')) {
            $user->notify(new VerifyApplicantEmail);
        }

        return $user;
    }

    public function start(Applicant $applicant, ?User $by = null): Application
    {
        $application = Application::create([
            'number' => 'tmp-'.Str::random(16),   // 20 chars: fits the column until the real number is set
            'applicant_id' => $applicant->id,
            'admission_period_id' => AdmissionPeriod::current()->id,
            'status' => ApplicationStatus::Draft,
            'form_progress' => ['step' => 1, 'completed' => []],
        ]);
        // The number comes from the row id, so it's unique without a race
        $application->update(['number' => sprintf('%s-%s-%05d', config('admission.number_prefix'), now()->year, $application->id)]);
        $this->status->recordCreated($application, $by);

        return $application;
    }

    /** The applicant's application for the open period, created if missing. */
    public function current(Applicant $applicant): Application
    {
        $period = AdmissionPeriod::current();

        return $applicant->applications()->where('admission_period_id', $period->id)->first()
            ?? $this->start($applicant, $applicant->user);
    }

    /**
     * Autosave from "My application". Values that fail their format rule are
     * skipped and reported; sections newly marked complete are validated
     * strictly. Returns the skipped fields with their messages.
     *
     * @return array<string, string>
     */
    public function saveForm(Application $application, array $input, User $by): array
    {
        if (! $this->isEditable($application)) {
            throw new BusinessRuleException("Your application has been sent to admissions and can't be edited.");
        }

        $applicant = $application->applicant;
        $rules = ApplicantForm::formatRules($applicant->id);
        $fields = Arr::only($input, array_keys($rules));

        $valid = [];
        $skipped = [];
        foreach ($fields as $name => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;
            $check = Validator::make(array_merge($fields, [$name => $value]), [$name => $rules[$name]], ApplicantForm::MESSAGES);
            if ($check->fails()) {
                $skipped[$name] = $check->errors()->first($name);
            } else {
                $valid[$name] = $value;
            }
        }

        $progress = $application->form_progress ?? ['step' => 1, 'completed' => []];
        $completed = array_values(array_unique(array_map('intval', array_intersect((array) ($input['completed'] ?? $progress['completed']), [1, 2, 3]))));
        sort($completed);
        $current = array_merge($this->formValues($application), $valid);
        $this->assertSectionsComplete(array_diff($completed, $progress['completed']), $current, $applicant->id);

        DB::transaction(function () use ($application, $applicant, $valid, $completed, $input, $progress, $by) {
            $this->saveProfile($application, $applicant, $valid, $by);
            $this->saveChoices($application, $valid);
            if (array_key_exists('campus', $valid)) {
                $application->campus = $valid['campus'];
            }
            $step = (int) ($input['step'] ?? $progress['step']);
            $application->form_progress = ['step' => max(1, min(3, $step)), 'completed' => $completed];
            $application->save();
        });

        return $skipped;
    }

    /** FR-03/FR-05: send the application to admissions. */
    public function submit(Application $application, User $by): void
    {
        if (! in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Resubmit], true)) {
            throw new BusinessRuleException('This application has already been sent to admissions.');
        }

        $completed = $application->form_progress['completed'] ?? [];
        if (count(array_intersect([1, 2, 3], $completed)) < 3) {
            throw new BusinessRuleException('Finish all three sections of My application first.');
        }
        $this->assertSectionsComplete([1, 2, 3], $this->formValues($application), $application->applicant_id);

        if (config('admission.require_verified_email') && ! $by->hasVerifiedEmail()) {
            throw new BusinessRuleException("Confirm your email address first: open the link we sent to {$by->email}.");
        }

        $missing = $this->missingDocuments($application);
        if ($missing) {
            throw new BusinessRuleException('Upload these documents first: '.implode(', ', $missing).'.');
        }

        $resubmission = $application->status === ApplicationStatus::Resubmit;
        DB::transaction(function () use ($application, $by, $resubmission) {
            $this->status->transition($application, ApplicationStatus::Submitted, $by);
            $this->dms->record('applicant.upserted', $application->fresh());
            $count = $application->documents()->whereHas('requirement', fn ($q) => $q->where('stage', Requirement::STAGE_ADMISSION))->count();
            $this->audit->record(
                $resubmission ? 'application.resubmitted' : 'application.submitted',
                $resubmission ? 'Resubmitted the requested documents' : "Submitted the application form and {$count} documents",
                $application, $application,
            );
        });
    }

    /** Admission requirements that apply to this applicant and are active (admin-store.js requiredDocs). */
    public function requiredDocuments(Application $application)
    {
        $applicant = $application->applicant;

        return Requirement::admission()
            ->where('is_active', true)
            ->where('is_uploadable', true)
            ->get()
            ->filter(fn (Requirement $r) => $r->appliesTo($applicant))
            ->values();
    }

    /** Titles of required documents not uploaded yet, or waiting for a replacement. */
    public function missingDocuments(Application $application): array
    {
        $docs = $application->documents()->get()->keyBy('requirement_id');

        return $this->requiredDocuments($application)
            ->filter(fn (Requirement $r) => ! $docs->has($r->id) || $docs[$r->id]->status === 'resubmit')
            ->pluck('title')
            ->all();
    }

    /**
     * "My profile": the mobile number can be updated at any time so admissions
     * can reach the applicant; names only while the form is still editable.
     * Changes after the first submission are kept (FR-10, saveProfile).
     * Returns the fields that were saved.
     */
    public function updateContact(Application $application, array $input, User $by): array
    {
        $rules = ['phone' => ['required', 'regex:'.ApplicantForm::PH_MOBILE]];
        if ($this->isEditable($application)) {
            $rules += ['firstName' => ['required', 'string', 'max:100'], 'lastName' => ['required', 'string', 'max:100']];
        }
        $input = array_map(fn ($v) => is_string($v) ? trim($v) : $v, Arr::only($input, array_keys($rules)));
        $valid = Validator::make($input, $rules, ApplicantForm::MESSAGES)->validate();

        DB::transaction(fn () => $this->saveProfile($application, $application->applicant, $valid, $by));

        return array_keys($valid);
    }

    /** Draft, or sent back for resubmission (the applicant fixes and resubmits). */
    public function isEditable(Application $application): bool
    {
        return in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Resubmit], true);
    }

    /** The form's current values, keyed by form field name. */
    public function formValues(Application $application): array
    {
        $applicant = $application->applicant;
        $values = [];
        foreach (ApplicantForm::PROFILE_FIELDS as $field => $column) {
            $value = $applicant->{$column};
            $values[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                $value === null => null,
                default => (string) $value,
            };
        }
        $choices = $application->choices()->with('program')->get()->keyBy('rank');
        foreach ([1, 2, 3] as $rank) {
            $values['program'.$rank] = $choices[$rank]->program->name ?? null;
        }
        $values['campus'] = $application->campus;
        $values['email'] = $applicant->user->email;

        return $values;
    }

    private function assertSectionsComplete(array $sections, array $values, int $applicantId): void
    {
        $rules = ApplicantForm::formatRules($applicantId);
        $check = [];
        foreach ($sections as $section) {
            foreach (ApplicantForm::SECTION_REQUIRED[$section] ?? [] as $field) {
                $check[$field] = array_merge(['required'], array_diff($rules[$field], ['nullable']));
            }
        }
        if ($check) {
            Validator::make($values, $check, ApplicantForm::MESSAGES)->validate();
        }
    }

    private function saveProfile(Application $application, Applicant $applicant, array $valid, User $by): void
    {
        foreach (ApplicantForm::PROFILE_FIELDS as $field => $column) {
            if (array_key_exists($field, $valid)) {
                $applicant->{$column} = $valid[$field];
            }
        }
        if (array_key_exists('gradYear', $valid)) {
            $applicant->applicant_type = ApplicantForm::applicantType($valid['gradYear']);
        }
        if (array_key_exists('city', $valid)) {
            // Decision T7: the form has no residency field yet
            $applicant->is_resident = $valid['city'] ? (bool) preg_match('/\bpasig\b/i', $valid['city']) : null;
        }

        $identityChanged = $applicant->isDirty(['last_name', 'first_name', 'birthday']);

        // §8.1 change history: after the first submission, every profile change is kept
        $afterSubmission = $application->status !== ApplicationStatus::Draft && $applicant->isDirty();
        if ($afterSubmission) {
            foreach ($applicant->getDirty() as $column => $new) {
                ApplicantChange::create([
                    'applicant_id' => $applicant->id,
                    'field' => $column,
                    'old_value' => $applicant->getOriginal($column) instanceof \DateTimeInterface
                        ? $applicant->getOriginal($column)->format('Y-m-d') : $applicant->getOriginal($column),
                    'new_value' => $new,
                    'changed_by' => $by->id,
                ]);
            }
        }
        $applicant->save();

        if ($identityChanged) {
            $this->flagDuplicate($applicant);
        }
        if ($afterSubmission) {
            $this->dms->record('applicant.upserted', $application);
        }
    }

    /**
     * FR-01: one account per email is enforced at registration; a second
     * account for the same person (same name and birthdate) is flagged for
     * staff review, never blocked.
     */
    private function flagDuplicate(Applicant $applicant): void
    {
        $match = null;
        if ($applicant->birthday && $applicant->first_name && $applicant->last_name) {
            $match = Applicant::whereKeyNot($applicant->id)
                ->whereDate('birthday', $applicant->birthday)
                ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($applicant->last_name)])
                ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($applicant->first_name)])
                ->orderBy('id')
                ->value('id');
        }
        if ($applicant->possible_duplicate_of !== $match) {
            $applicant->forceFill(['possible_duplicate_of' => $match])->save();
        }
    }

    private function saveChoices(Application $application, array $valid): void
    {
        foreach ([1, 2, 3] as $rank) {
            if (! array_key_exists('program'.$rank, $valid)) {
                continue;
            }
            $application->choices()->where('rank', $rank)->delete();
            if ($valid['program'.$rank]) {
                $program = Program::where('name', $valid['program'.$rank])->firstOrFail();
                // A program can only hold one rank: drop it from the other rank first
                $application->choices()->where('program_id', $program->id)->delete();
                $application->choices()->create(['program_id' => $program->id, 'rank' => $rank]);
            }
        }
    }
}
