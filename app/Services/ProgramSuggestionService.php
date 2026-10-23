<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * Suggested programs (FR-02, decision D2). The paper defines no rules yet,
 * so this returns open programs that still have slots. When the rules are
 * decided (e.g. by strand), only this class changes.
 */
class ProgramSuggestionService
{
    /** @return Collection<int, Program> */
    public function suggestFor(Applicant $applicant): Collection
    {
        $filled = Program::filledCounts();

        return Program::where('is_open', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Program $p) => ($filled[$p->id] ?? 0) < $p->slots)
            ->values();
    }
}
