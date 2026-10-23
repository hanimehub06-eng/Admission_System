<?php

namespace App\Services;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the append-only audit trail (NFR-03). The description is the
 * sentence the admission office's audit screen and applicant history show.
 */
class AuditLogger
{
    public function __construct(private Request $request) {}

    public function record(
        string $action,
        string $description,
        ?Model $subject = null,
        ?Application $application = null,
        array $meta = [],
        ?User $actor = null,
        ?string $actorName = null,          // a system caller, e.g. "API · enrollment"
    ): AuditLog {
        // auth() rather than $request->user(): the latter is always null in console runs
        $actor ??= $actorName ? null : auth()->user();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'actor_name' => $actorName ?? $this->actorName($actor),
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'application_id' => $application?->id,
            'description' => mb_substr($description, 0, 500),
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'meta' => $meta ?: null,
        ]);
    }

    private function actorName(?User $actor): string
    {
        if (! $actor) {
            return 'System';
        }

        return $actor->hasRole('applicant') ? 'Applicant' : $actor->name;
    }
}
