<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('applicant_change_history')]
#[Fillable(['applicant_id', 'field', 'old_value', 'new_value', 'changed_by'])]
class ApplicantChange extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }
}
