<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'possible_duplicate_of', 'lrn', 'last_name', 'first_name', 'middle_name', 'sex', 'birthday', 'civil_status', 'phone',
    'address', 'city', 'province', 'zip', 'is_resident', 'district', 'barangay',
    'guardian_name', 'guardian_phone', 'has_guardian', 'last_school', 'school_type', 'strand',
    'grad_year', 'applicant_type', 'gwa',
])]
class Applicant extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'is_resident' => 'boolean',
            'has_guardian' => 'boolean',
            'district' => 'integer',
            'gwa' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ApplicantChange::class);
    }

    public function fullName(bool $lastFirst = false): string
    {
        if ($lastFirst) {
            return $this->last_name.', '.$this->first_name.($this->middle_name ? ' '.mb_substr($this->middle_name, 0, 1).'.' : '');
        }

        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]));
    }
}
