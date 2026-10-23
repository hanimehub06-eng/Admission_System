<?php

namespace App\Http\Controllers\Api\Applicant;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ApplicantPortalPresenter;
use App\Http\Requests\DocumentUploadRequest;
use App\Models\Application;
use App\Models\Requirement;
use App\Services\ApplicationService;
use App\Services\DocumentService;
use App\Services\Documents\DocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The applicant's own documents and medical requirements, keyed by the
 * official requirement key (psa, parentId…, medCert, xray, labs).
 */
class DocumentController extends Controller
{
    public function __construct(
        private ApplicationService $applications,
        private DocumentService $documents,
        private ApplicantPortalPresenter $presenter,
    ) {}

    public function store(DocumentUploadRequest $request, string $key): JsonResponse
    {
        return $this->upload($request, $key, Requirement::STAGE_ADMISSION);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        return $this->remove($request, $key, Requirement::STAGE_ADMISSION);
    }

    public function storeMedical(DocumentUploadRequest $request, string $key): JsonResponse
    {
        return $this->upload($request, $key, Requirement::STAGE_MEDICAL);
    }

    public function destroyMedical(Request $request, string $key): JsonResponse
    {
        return $this->remove($request, $key, Requirement::STAGE_MEDICAL);
    }

    public function submitMedical(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $this->documents->submitMedical($application);

        return response()->json([
            'data' => $this->presenter->present($application->fresh()),
            'message' => 'Medical requirements sent to the university clinic.',
        ]);
    }

    /** The applicant's own file (preview). */
    public function file(Request $request, string $key, DocumentStorage $storage): StreamedResponse
    {
        $application = $this->application($request);
        $requirement = Requirement::where('key', $key)->firstOrFail();
        $document = $this->documents->current($application, $requirement) ?? abort(404);

        return $this->stream($storage, $document);
    }

    private function upload(DocumentUploadRequest $request, string $key, string $stage): JsonResponse
    {
        $application = $this->application($request);
        $requirement = $this->documents->requirement($key, $stage);
        $this->documents->upload($application, $requirement, $request->file('file'), $request->user());

        return response()->json([
            'data' => $this->presenter->present($application->fresh()),
            'message' => "{$requirement->title} uploaded.",
        ], 201);
    }

    private function remove(Request $request, string $key, string $stage): JsonResponse
    {
        $application = $this->application($request);
        $this->documents->remove($application, $this->documents->requirement($key, $stage));

        return response()->json(['data' => $this->presenter->present($application->fresh())]);
    }

    private function application(Request $request): Application
    {
        return $this->applications->current($request->user()->applicant);
    }

    public static function stream(DocumentStorage $storage, $document): StreamedResponse
    {
        $stream = $storage->readStream($document);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document->mime,
            'Content-Disposition' => 'inline; filename="'.addcslashes($document->original_name, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
