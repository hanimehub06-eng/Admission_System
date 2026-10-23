<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'stage', 'title', 'note', 'applies_when', 'icon', 'is_uploadable', 'is_active', 'sort_order'])]
class Requirement extends Model
{
    use HasFactory;

    public const STAGE_ADMISSION = 'admission';

    public const STAGE_MEDICAL = 'medical';

    protected function casts(): array
    {
        return [
            'is_uploadable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeAdmission(Builder $q): void
    {
        $q->where('stage', self::STAGE_ADMISSION)->orderBy('sort_order');
    }

    public function scopeMedical(Builder $q): void
    {
        $q->where('stage', self::STAGE_MEDICAL)->orderBy('sort_order');
    }

    /** Whether this requirement applies to the applicant (the "when" rule in admin-store.js). */
    public function appliesTo(Applicant $applicant): bool
    {
        return match ($this->applies_when) {
            'guardian' => (bool) $applicant->has_guardian,
            'graduate' => $applicant->applicant_type === 'graduate',
            'grade12' => $applicant->applicant_type !== 'graduate',
            default => true,
        };
    }
}
