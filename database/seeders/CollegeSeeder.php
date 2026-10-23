<?php

namespace Database\Seeders;

use App\Models\College;
use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Colleges and which programs belong to them (FR-17 dean scoping), and the
 * programs that interview (D14). Placeholders until PLP confirms them.
 *
 * Safe to run on a database in use (app:install runs it every time): it
 * only fills in what's missing and never changes what staff set.
 */
class CollegeSeeder extends Seeder
{
    public const COLLEGES = [
        'CCS' => ['College of Computer Studies', ['BSIT', 'BSCS', 'BSIS']],
        'CBA' => ['College of Business and Accountancy', ['BSA', 'BSBA', 'BSHM']],
        'CON' => ['College of Nursing', ['BSN']],
        'CAS' => ['College of Arts and Sciences', ['BSPSY', 'ABCOMM']],
        'COED' => ['College of Education', ['BEED', 'BSED']],
    ];

    /** Programs that require an interview when first linked to a college (placeholder: BS Nursing). */
    public const INTERVIEW_PROGRAMS = ['BSN'];

    public function run(): void
    {
        foreach (self::COLLEGES as $code => [$name, $programs]) {
            $college = College::firstOrCreate(['code' => $code], ['name' => $name]);
            foreach (Program::whereIn('code', $programs)->whereNull('college_id')->get() as $program) {
                $program->update([
                    'college_id' => $college->id,
                    'requires_interview' => $program->requires_interview || in_array($program->code, self::INTERVIEW_PROGRAMS, true),
                ]);
            }
        }
    }
}
