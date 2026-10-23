<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['admission_period_id', 'program_id', 'date', 'time', 'venue', 'capacity', 'created_by'])]
class InterviewSchedule extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'capacity' => 'integer',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(InterviewAssignment::class);
    }

    public function label(): string
    {
        return "{$this->program->code} interview · ".$this->date->format('D, M j').($this->time ? ", {$this->time}" : '');
    }
}
