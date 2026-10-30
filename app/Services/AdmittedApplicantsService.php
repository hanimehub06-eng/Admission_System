<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Models\AdmissionPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FR-29: admitted applicants as the Enrollment System receives them (the
 * JSON in docs/openapi.json) and as staff download them (CSV/XLSX/PDF).
 * Scores never leave.
 */
class AdmittedApplicantsService
{
    /** record key => report heading */
    public const COLUMNS = [
        'reference_number' => 'Application no.',
        'lrn' => 'LRN',
        'last_name' => 'Last name',
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'sex' => 'Sex',
        'birthday' => 'Birthday',
        'email' => 'Email',
        'phone' => 'Mobile',
        'address' => 'Address',
        'is_pasig_resident' => 'Pasig resident',
        'district' => 'District',
        'barangay' => 'Barangay',
        'last_school' => 'Last school attended',
        'school_type' => 'School type',
        'strand' => 'SHS strand',
        'program' => 'Program',
        'college' => 'College',
        'admitted_at' => 'Admitted on',
    ];

    /** Admitted applications of the current period, oldest admission first. */
    public function admitted(): Builder
    {
        return $this->query()->where('a.admission_period_id', AdmissionPeriod::current()->id);
    }

    /** One admitted applicant in the Enrollment JSON layout, or null if not admitted. */
    public function find(int $applicationId): ?array
    {
        $row = $this->query()->where('a.id', $applicationId)->first();

        return $row ? $this->record($row) : null;
    }

    public function record(object $r): array
    {
        return [
            'reference_number' => $r->number,
            'lrn' => $r->lrn,
            'last_name' => $r->last_name,
            'first_name' => $r->first_name,
            'middle_name' => $r->middle_name,
            'sex' => $r->sex,
            'birthday' => $r->birthday ? Carbon::parse($r->birthday)->toDateString() : null,
            'email' => $r->email,
            'phone' => $r->phone,
            'address' => $r->address,
            'is_pasig_resident' => $r->is_resident === null ? null : (bool) $r->is_resident,
            'district' => $r->district,
            'barangay' => $r->barangay,
            'last_school' => $r->last_school,
            'school_type' => $r->school_type,
            'strand' => $r->strand,
            'program' => $r->program_code ? ['code' => $r->program_code, 'name' => $r->program_name] : null,
            'college' => $r->college_code ? ['code' => $r->college_code, 'name' => $r->college_name] : null,
            'admitted_at' => $r->admitted_at ? Carbon::parse($r->admitted_at, 'UTC')->toIso8601ZuluString() : null,
        ];
    }

    /** As a report (ReportService): headings and rows. */
    public function report(): array
    {
        return [
            'columns' => array_values(self::COLUMNS),
            'rows' => $this->admitted()->get()->map(function ($r) {
                $row = $this->record($r);
                $row['is_pasig_resident'] = $row['is_pasig_resident'] === null ? '' : ($row['is_pasig_resident'] ? 'Yes' : 'No');
                $row['program'] = $r->program_name;
                $row['college'] = $r->college_code;
                $row['admitted_at'] = $r->admitted_at ? Carbon::parse($r->admitted_at, 'UTC')->setTimezone(config('admission.display_timezone'))->toDateString() : null;

                return array_values($row);
            })->all(),
        ];
    }

    private function query(): Builder
    {
        return DB::table('applications as a')
            ->join('applicants as p', 'p.id', '=', 'a.applicant_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('programs as g', 'g.id', '=', 'a.offered_program_id')
            ->leftJoin('colleges as c', 'c.id', '=', 'g.college_id')
            ->where('a.status', ApplicationStatus::Admitted->value)
            ->whereNull('a.deleted_at')
            ->orderBy('a.admitted_at')->orderBy('a.id')
            ->select(['a.number', 'a.admitted_at', 'p.lrn', 'p.last_name', 'p.first_name', 'p.middle_name', 'p.sex',
                'p.birthday', 'p.phone', 'p.address', 'p.is_resident', 'p.district', 'p.barangay', 'p.last_school', 'p.school_type',
                'p.strand', 'u.email', 'g.code as program_code', 'g.name as program_name', 'c.code as college_code', 'c.name as college_name']);
    }
}
