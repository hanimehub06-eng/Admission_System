<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An applicant's interview (FR-17). The grade is Restricted (§8.3): hidden
 * from serialization and never in an applicant payload.
 */
#[Fillable(['application_id', 'interview_schedule_id', 'assigned_by', 'grade', 'outcome', 'recorded_by', 'recorded_at'])]
#[Hidden(['grade'])]
class InterviewAssignment extends Model
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(InterviewSchedule::class, 'interview_schedule_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
