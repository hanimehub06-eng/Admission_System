<?php

namespace App\Http\Controllers\Api\Clinic;

use App\Http\Controllers\Api\Applicant\DocumentController as ApplicantDocumentController;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ClinicStatePresenter;
use App\Models\Application;
use App\Models\Requirement;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\Documents\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Clinic portal (nurse/): medical requirements only (FR-13). The nurse sends
 * a file back with a remark, or approves the applicant; the admission office
 * sees the approval, read-only. Applications are addressed by number.
 */
class MedicalController extends Controller
{
    public function __construct(
        private DocumentService $documents,
        private ClinicStatePresenter $presenter,
    ) {}

    public function state(Request $request): StreamedJsonResponse
    {
        return response()->streamJson(['data' => $this->presenter->state($request->user())]);
    }

    public function review(Request $request, Application $application, string $key): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'resubmit'])],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);
        $requirement = $this->documents->requirement($key, Requirement::STAGE_MEDICAL);
        $this->documents->review($application, $requirement, $data['status'], $data['remark'] ?? null, $request->user());

        return response()->json(['data' => $this->presenter->applicant($application->fresh())]);
    }

    public function approve(Request $request, Application $application): JsonResponse
    {
        $n = $this->documents->approveMedical($application, $request->user());

        return response()->json(['data' => $this->presenter->applicant($application->fresh()), 'verified' => $n]);
    }

    /** FR-10: flag a suspected falsified medical file. Body: { flagged: bool, note } */
    public function flag(Request $request, Application $application, string $key): JsonResponse
    {
        $data = $request->validate(['flagged' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $requirement = $this->documents->requirement($key, Requirement::STAGE_MEDICAL);
        $this->documents->flagFalsification($application, $requirement, $data['flagged'], $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->presenter->applicant($application->fresh())]);
    }

    /** Open a medical file (confidential, NFR-05). Every opening is audited. */
    public function file(Application $application, string $key, DocumentStorage $storage, AuditLogger $audit): StreamedResponse
    {
        $requirement = $this->documents->requirement($key, Requirement::STAGE_MEDICAL);
        $document = $this->documents->current($application, $requirement) ?? abort(404);
        $audit->record('medical.file_opened', "Opened medical file \"{$requirement->title}\"", $document, $application);

        return ApplicantDocumentController::stream($storage, $document);
    }
}
