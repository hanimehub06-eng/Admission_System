<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

#[Fillable(['college_id', 'code', 'name', 'slots', 'is_open', 'requires_interview'])]
class Program extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'slots' => 'integer',
            'is_open' => 'boolean',
            'requires_interview' => 'boolean',
        ];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ApplicationProgramChoice::class);
    }

    /** Accepted offers for this program (slots are taken only on acceptance, FR-09). */
    public function filledSlots(): int
    {
        return static::filledCounts()[$this->id] ?? 0;
    }

    /** Slots taken for every program in one query: [program_id => count]. */
    public static function filledCounts(): array
    {
        return DB::table('applications')
            ->whereIn('status', ApplicationStatus::slotHolding())->whereNotNull('offered_program_id')->whereNull('deleted_at')
            ->groupBy('offered_program_id')
            ->pluck(DB::raw('count(*)'), 'offered_program_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
