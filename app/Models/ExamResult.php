<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The score is internal (NFR-02). It is hidden from serialization by default;
 * only the staff-facing state builder reads it explicitly.
 */
#[Fillable(['application_id', 'score', 'decision', 'recorded_by', 'recorded_at', 'is_released', 'released_by', 'released_at'])]
#[Hidden(['score'])]
class ExamResult extends Model
{
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'recorded_at' => 'datetime',
            'is_released' => 'boolean',
            'released_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
