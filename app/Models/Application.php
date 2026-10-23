<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'number', 'applicant_id', 'admission_period_id', 'status', 'campus', 'offered_program_id', 'form_progress', 'submitted_at', 'locked_at',
    'offered_at', 'acceptance_deadline', 'acceptance_reminded_at', 'offer_accepted_at', 'forfeited_at', 'medical_submitted_at', 'admitted_at', 'student_number',
])]
class Application extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'form_progress' => 'array',
            'submitted_at' => 'datetime',
            'locked_at' => 'datetime',
            'offered_at' => 'datetime',
            'acceptance_deadline' => 'datetime',
            'acceptance_reminded_at' => 'datetime',
            'offer_accepted_at' => 'datetime',
            'forfeited_at' => 'datetime',
            'medical_submitted_at' => 'datetime',
            'admitted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AdmissionPeriod::class, 'admission_period_id');
    }

    public function choices(): HasMany
    {
        return $this->hasMany(ApplicationProgramChoice::class)->orderBy('rank');
    }

    /** Every uploaded version, including replaced ones. */
    public function allDocuments(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    /** The current version of each uploaded requirement. */
    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class)->where('is_current', true);
    }

    public function examRegistration(): HasOne
    {
        return $this->hasOne(ExamRegistration::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(ExamResult::class);
    }

    public function interview(): HasOne
    {
        return $this->hasOne(InterviewAssignment::class);
    }

    /** The program offered (the first choice at the time of the offer). */
    public function offeredProgram(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'offered_program_id');
    }

    /** First-choice program. */
    public function firstChoice(): ?Program
    {
        return $this->choices()->with('program')->first()?->program;
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(StatusHistory::class)->orderBy('id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class)->orderBy('id');
    }

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    /** Medical requirements open once the slot is accepted (FR-09, FR-10). */
    public function medicalApplies(): bool
    {
        return in_array($this->status, [ApplicationStatus::ForMedical, ApplicationStatus::Admitted], true);
    }
}
