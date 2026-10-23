<?php

namespace App\Services\Dms;

use App\Jobs\DeliverDmsEvent;
use App\Models\Application;
use App\Models\DmsOutboxEvent;
use Illuminate\Support\Str;

/**
 * Outbox for the DMS (plan §4): each change writes a dms_outbox row in the
 * same transaction as the change; a queued job delivers it after commit
 * with Idempotency-Key = event_id and retries. A DMS outage never blocks
 * staff work.
 *
 * Event types: applicant.upserted (the whole record), status.changed,
 * document.uploaded, document.reviewed, document.photocopy,
 * document.flagged, exam.scheduled, exam.attendance, exam.result,
 * interview.recorded, offer.accepted, offer.forfeited, admission.completed.
 * Restricted values (exam score, interview grade) are marked as such.
 */
class DmsSync
{
    public function record(string $type, Application $application, array $data = []): DmsOutboxEvent
    {
        $event = DmsOutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'type' => $type,
            'applicant_ref' => $application->number,
            'payload' => $type === 'applicant.upserted'
                ? $this->applicantRecord($application)
                : ['type' => $type, 'occurred_at' => now()->toIso8601String(), 'data' => $data],
            'status' => DmsOutboxEvent::PENDING,
        ]);
        DeliverDmsEvent::dispatch($event->id)->afterCommit();

        return $event;
    }

    /** The applicant's record as the DMS keeps it (§9.1). No credentials, no Restricted values. */
    public function applicantRecord(Application $application): array
    {
        $application->loadMissing(['applicant.user', 'choices.program', 'period', 'offeredProgram']);
        $p = $application->applicant;

        return [
            'applicant_ref' => $application->number,
            'admission_period' => $application->period->label,
            'status' => $application->status->value,
            'lrn' => $p->lrn,
            'last_name' => $p->last_name,
            'first_name' => $p->first_name,
            'middle_name' => $p->middle_name,
            'sex' => $p->sex,
            'birthday' => $p->birthday?->toDateString(),
            'email' => $p->user->email,
            'phone' => $p->phone,
            'address' => implode(', ', array_filter([$p->address, $p->city, $p->province, $p->zip])),
            'is_resident' => $p->is_resident,
            'district' => $p->district,
            'barangay' => $p->barangay,
            'guardian_name' => $p->guardian_name,
            'guardian_phone' => $p->guardian_phone,
            'last_school' => $p->last_school,
            'school_type' => $p->school_type,
            'strand' => $p->strand,
            'applicant_type' => $p->applicant_type,
            'program_choices' => $application->choices->map(fn ($c) => ['rank' => $c->rank, 'code' => $c->program->code])->values()->all(),
            'offered_program' => $application->offeredProgram?->code,
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'admitted' => $application->admitted_at !== null,
            'admitted_at' => $application->admitted_at?->toIso8601String(),
            'student_number' => $application->student_number,
        ];
    }

    /**
     * Re-queue failed events, and pending ones untouched for an hour (their
     * job was lost, e.g. the queue was cleared). Returns how many.
     */
    public function retryOutstanding(): int
    {
        $ids = DmsOutboxEvent::where('status', DmsOutboxEvent::FAILED)
            ->orWhere(fn ($q) => $q->where('status', DmsOutboxEvent::PENDING)->where('updated_at', '<', now()->subHour()))
            ->orderBy('id')->pluck('id');
        $ids->each(fn ($id) => DeliverDmsEvent::dispatch($id));

        return $ids->count();
    }
}
