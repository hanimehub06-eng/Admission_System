<?php

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Api\Applicant\DocumentController as ApplicantDocumentController;
use App\Http\Controllers\Controller;
use App\Http\Presenters\OfficeStatePresenter;
use App\Models\Application;
use App\Models\Requirement;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentStorage;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Document review screen of the admission office panel (FR-03, FR-04).
 * Medical files are reviewed in the clinic portal (Clinic\MedicalController).
 * Applications are addressed by number (AD-2026-00001), the id the panel
 * already uses.
 */
class DocumentController extends Controller
{
    public function __construct(
        private DocumentService $documents,
        private OfficeStatePresenter $presenter,
    ) {}

    public function review(Request $request, Application $application, string $key): JsonResponse
    {
        return $this->decide($request, $application, $key, Requirement::STAGE_ADMISSION);
    }

    public function verifyPending(Request $request, Application $application): JsonResponse
    {
        $n = $this->documents->verifyPending($application, Requirement::STAGE_ADMISSION, $request->user());

        return response()->json(['data' => $this->presenter->applicant($application->fresh()), 'verified' => $n]);
    }

    /** §9.1: photocopy handed in (API only: the panel has no control for it yet). Body: { received: bool } */
    public function photocopy(Request $request, Application $application, string $key): JsonResponse
    {
        $data = $request->validate(['received' => ['required', 'boolean']]);
        $requirement = Requirement::where('key', $key)->where('is_uploadable', true)->firstOrFail();
        $this->documents->markPhotocopy($application, $requirement, $data['received'], $request->user());

        return response()->json(['data' => $this->presenter->applicant($application->fresh())]);
    }

    /** Open an uploaded admission document. Medical files are confidential: clinic portal only (NFR-05). */
    public function file(Request $request, Application $application, string $key, DocumentStorage $storage, AuditLogger $audit): StreamedResponse
    {
        $requirement = Requirement::where('key', $key)->firstOrFail();
        abort_if($requirement->stage === Requirement::STAGE_MEDICAL, 403, 'Medical files can only be opened by the university clinic.');
        $document = $this->documents->current($application, $requirement) ?? abort(404);
        if ($requirement->stage === Requirement::STAGE_MEDICAL) {
            $audit->record('medical.file_opened', "Opened medical file \"{$requirement->title}\"", $document, $application);
        }

        return ApplicantDocumentController::stream($storage, $document);
    }

    private function decide(Request $request, Application $application, string $key, string $stage): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'resubmit'])],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);
        $requirement = $this->documents->requirement($key, $stage);
        $this->documents->review($application, $requirement, $data['status'], $data['remark'] ?? null, $request->user());

        return response()->json(['data' => $this->presenter->applicant($application->fresh())]);
    }
}
