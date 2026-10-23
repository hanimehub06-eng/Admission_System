<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'program_id', 'rank'])]
class ApplicationProgramChoice extends Model
{
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class)->withTrashed();
    }
}
