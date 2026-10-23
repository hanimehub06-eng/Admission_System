<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['label', 'academic_year', 'opens_on', 'application_deadline', 'exam_starts_on', 'exam_ends_on', 'results_release_on', 'reservation_deadline', 'acceptance_window_days', 'is_active'])]
class AdmissionPeriod extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'opens_on' => 'date',
            'application_deadline' => 'date',
            'exam_starts_on' => 'date',
            'exam_ends_on' => 'date',
            'results_release_on' => 'date',
            'reservation_deadline' => 'date',
            'acceptance_window_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::where('is_active', true)->latest('opens_on')->firstOrFail();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }
}
