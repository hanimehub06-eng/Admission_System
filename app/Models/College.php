<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A college and its dean, who sees interviews for its programs only (FR-17, §10.2). */
#[Fillable(['code', 'name', 'dean_user_id'])]
class College extends Model
{
    public function dean(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dean_user_id');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
