<?php

namespace App\Http\Presenters;

use App\Models\AdmissionPeriod;
use App\Models\Application;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * The clinic portal's state (ADM.state when nurse.js loads admin-store.js).
 * Same shape as OfficeStatePresenter so the shared store works unchanged,
 * but only what a nurse needs: applicants at the medical step (released
 * pass), their name, sex and birthday, and their medical files. No scores,
 * contact details, admission documents, exams or history (NFR-01, NFR-02).
 */
class ClinicStatePresenter
{
    private DateTimeZone $utc;

    private DateTimeZone $local;

    public function __construct()
    {
        $this->utc = new DateTimeZone('UTC');
        $this->local = new DateTimeZone(config('admission.display_timezone'));
    }

    public function state(User $staff): array
    {
        $period = AdmissionPeriod::current();
        $ids = $this->medicalStage()->where('a.admission_period_id', $period->id)->pluck('a.id')->all();

        return [
            'version' => 2,
            'staff' => [
                'name' => $staff->name,
                'initials' => $staff->initials(),
                'role' => $staff->primaryRole()?->label(),
                'roleKey' => $staff->primaryRole()?->value,
                'email' => $staff->email,
            ],
            // Kept empty so the shared store's shape is the same as the office's
            'programs' => [],
            'sessions' => [],
            'applicants' => $this->applicants($ids),
            'settings' => ['deadline' => $period->application_deadline->toDateString(), 'period' => $period->label, 'inactiveDocs' => []],
            'audit' => [],
        ];
    }

    /** One applicant, after an action (the portal replaces its copy). */
    public function applicant(Application $application): array
    {
        return $this->applicants([$application->id])[0];
    }

    /** Accepted slots: the only applicants the clinic deals with (Application::medicalApplies). */
    private function medicalStage()
    {
        return DB::table('applications as a')
            ->leftJoin('exam_results as res', 'res.application_id', '=', 'a.id')
            ->whereIn('a.status', ['for_medical', 'admitted'])->whereNull('a.deleted_at');
    }

    private function applicants(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = $this->medicalStage()
            ->join('applicants as p', 'p.id', '=', 'a.applicant_id')
            ->whereIn('a.id', $ids)->orderBy('a.id')
            ->get(['a.id', 'a.number', 'a.medical_submitted_at', 'p.last_name', 'p.first_name', 'p.middle_name', 'p.sex', 'p.birthday', 'res.released_at']);

        $files = DB::table('application_documents as d')
            ->join('requirements as q', 'q.id', '=', 'd.requirement_id')
            ->leftJoin('users as rv', 'rv.id', '=', 'd.reviewed_by')
            ->whereIn('d.application_id', $ids)->where('d.is_current', true)->where('q.stage', 'medical')
            ->orderBy('d.id')
            ->get(['d.application_id', 'q.key', 'd.status', 'd.original_name', 'd.size', 'd.created_at', 'rv.name as reviewer', 'd.reviewed_at', 'd.remark',
                'd.scan_status', 'd.falsification_flag', 'd.falsification_note'])
            ->groupBy('application_id');

        return $rows->map(fn ($a) => [
            'id' => $a->number,
            'lastName' => $a->last_name,
            'firstName' => $a->first_name,
            'middleName' => $a->middle_name,
            'sex' => $a->sex,
            'birthday' => $a->birthday ? substr($a->birthday, 0, 10) : null,
            'docs' => [],
            'exam' => null,
            // Enough for medStatus(): a released pass. No score, no recorder.
            'result' => ['decision' => 'passed', 'released' => true, 'releasedAt' => $this->at($a->released_at)],
            'medical' => [
                'submittedAt' => $this->at($a->medical_submitted_at),
                'files' => ($files[$a->id] ?? collect())->mapWithKeys(fn ($d) => [$d->key => array_filter([
                    'status' => $d->status,
                    'file' => $d->original_name,
                    'size' => (int) $d->size,
                    'uploadedAt' => $this->at($d->created_at),
                    'reviewedBy' => $d->reviewer,
                    'reviewedAt' => $this->at($d->reviewed_at),
                    'remark' => $d->remark,
                    'scan' => $d->scan_status,
                    'flagged' => $d->falsification_flag ? true : null,
                    'flagNote' => $d->falsification_flag ? $d->falsification_note : null,
                ], fn ($v) => $v !== null)])->all() ?: new \stdClass,
            ],
            'history' => [],
        ])->all();
    }

    /** "2026-10-17T09:05" in Philippine time, the format the portal uses. Stored values are UTC. */
    private function at(?string $utc): ?string
    {
        return $utc === null ? null : (new DateTimeImmutable($utc, $this->utc))->setTimezone($this->local)->format('Y-m-d\TH:i');
    }
}
