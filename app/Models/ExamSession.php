<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['admission_period_id', 'date', 'batch', 'venue', 'capacity', 'created_by'])]
class ExamSession extends Model
{
    use HasFactory;

    public const BATCHES = [
        'A' => ['label' => 'Batch A', 'time' => '8:00 AM – 12:00 NN'],
        'B' => ['label' => 'Batch B', 'time' => '1:00 PM – 5:00 PM'],
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'capacity' => 'integer',
        ];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AdmissionPeriod::class, 'admission_period_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ExamRegistration::class);
    }

    /** "S12": the id format the admin UI uses for sessions. */
    public function publicId(): string
    {
        return 'S'.$this->id;
    }

    public static function idFromPublic(string $publicId): int
    {
        return (int) ltrim($publicId, 'S');
    }

    public function label(): string
    {
        return self::BATCHES[$this->batch]['label'].' · '.$this->date->format('D, M j');
    }

    public function isFull(): bool
    {
        return $this->registrations()->count() >= $this->capacity;
    }
}
