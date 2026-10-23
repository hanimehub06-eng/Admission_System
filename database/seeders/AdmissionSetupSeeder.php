<?php

namespace Database\Seeders;

use App\Models\AdmissionPeriod;
use App\Models\Program;
use App\Models\Requirement;
use Illuminate\Database\Seeder;

/**
 * Programs, requirements and the admission period. Values come from the
 * existing UI (admin-store.js, applicant.js) so both sides start in sync.
 */
class AdmissionSetupSeeder extends Seeder
{
    /** [key, title, applies when, icon, note]. Also used by the migration that adds the H1 cards. */
    public const ADMISSION_REQUIREMENTS = [
        ['applicantId', "Applicant's government-issued or school ID", 'all', 'idcard', null],
        ['parentId', "Parent's government-issued ID", 'all', 'users', null],
        ['affidavit', 'Notarized affidavit of guardianship', 'guardian', 'stamp', 'Applicants with a guardian'],
        ['form137', 'CTC of Form 137 — "For Evaluation Purposes Only"', 'graduate', 'file', 'SHS graduates'],
        ['form138', 'CTC of Grade 11 Form 138', 'grade12', 'stamp', 'Ongoing Grade 12'],
        ['reportCard', 'Grade 12 Form 138 (latest grading period)', 'grade12', 'file', 'Ongoing Grade 12'],
        ['goodMoral', 'Certificate of Good Moral Character', 'all', 'shield', null],
        ['psa', 'PSA Birth Certificate', 'all', 'scroll', null],
        ['photo', '2 passport-size photos, white background with nameplate', 'all', 'camera', null],
    ];

    public function run(): void
    {
        AdmissionPeriod::updateOrCreate(['academic_year' => '2027–2028'], [
            'label' => 'AY 2027–2028 · 1st semester',
            'opens_on' => '2026-09-01',
            'application_deadline' => '2026-11-28',
            'exam_starts_on' => '2026-12-05',
            'exam_ends_on' => '2026-12-13',
            'results_release_on' => '2027-02-13',
            'reservation_deadline' => '2027-03-20',
            'acceptance_window_days' => config('admission.offers.default_window_days'),
            'is_active' => true,
        ]);

        $programs = [
            ['BSIT', 'BS Information Technology', 120],
            ['BSCS', 'BS Computer Science', 80],
            ['BSIS', 'BS Information Systems', 60],
            ['BSA', 'BS Accountancy', 80],
            ['BSBA', 'BS Business Administration', 100],
            ['BSHM', 'BS Hospitality Management', 80],
            ['BSN', 'BS Nursing', 60],
            ['BSPSY', 'BS Psychology', 60],
            ['ABCOMM', 'AB Communication', 50],
            ['BEED', 'Bachelor of Elementary Education', 60],
            ['BSED', 'Bachelor of Secondary Education', 60],
        ];
        foreach ($programs as [$code, $name, $slots]) {
            Program::updateOrCreate(['code' => $code], ['name' => $name, 'slots' => $slots]);
        }
        // Colleges, deans' scope and interview programs (FR-17)
        $this->call(CollegeSeeder::class);

        // Freshman documentary requirements (decision T6: the admin panel's list,
        // plus the portal's Grade 12 report card and Good Moral cards: H1).
        // In the order the applicant portal shows its document cards.
        $admission = self::ADMISSION_REQUIREMENTS;
        foreach ($admission as $i => [$key, $title, $when, $icon, $note]) {
            Requirement::updateOrCreate(['key' => $key], [
                'stage' => Requirement::STAGE_ADMISSION, 'title' => $title, 'applies_when' => $when,
                'icon' => $icon, 'note' => $note, 'sort_order' => $i + 1,
            ]);
        }

        // Physical item: submitted at the venue, not uploaded
        Requirement::updateOrCreate(['key' => 'envelope'], [
            'stage' => Requirement::STAGE_ADMISSION, 'title' => 'Long brown envelope with plastic envelope',
            'applies_when' => 'all', 'is_uploadable' => false, 'sort_order' => 99,
        ]);

        // Medical requirements (decision T2). Placeholder list until the clinic confirms it.
        $medical = [
            ['medCert', 'Medical certificate', 'Signed by a licensed physician', 'clipboard'],
            ['xray', 'Chest X-ray result (PA view)', "With the radiologist's reading", 'file'],
            ['labs', 'Laboratory results (CBC and urinalysis)', 'Official laboratory copy', 'file'],
        ];
        foreach ($medical as $i => [$key, $title, $note, $icon]) {
            Requirement::updateOrCreate(['key' => $key], [
                'stage' => Requirement::STAGE_MEDICAL, 'title' => $title, 'note' => $note,
                'icon' => $icon, 'sort_order' => $i + 1,
            ]);
        }
    }
}
