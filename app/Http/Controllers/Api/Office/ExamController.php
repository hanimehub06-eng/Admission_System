<?php

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Controller;
use App\Http\Presenters\OfficeStatePresenter;
use App\Models\Application;
use App\Models\ExamSession;
use App\Services\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Entrance exams and Results screens (FR-06, FR-07, FR-08). Admission personnel only. */
class ExamController extends Controller
{
    public function __construct(
        private ExamService $exams,
        private OfficeStatePresenter $presenter,
    ) {}

    public function storeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'batch' => ['required', Rule::in(array_keys(ExamSession::BATCHES))],
            'venue' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'between:1,500'],
        ]);
        $session = $this->exams->createSession($data['date'], $data['batch'], $data['venue'], $data['capacity'], $request->user());

        return response()->json(['data' => $this->presenter->session($session)], 201);
    }

    public function approve(Request $request, Application $application): JsonResponse
    {
        $this->exams->approve($application, $this->session($request), $request->user());

        return $this->applicant($application);
    }

    public function reschedule(Request $request, Application $application): JsonResponse
    {
        $this->exams->reschedule($application, $this->session($request), $request->user());

        return $this->applicant($application);
    }

    /** Venue checklist and attendance (roster). */
    public function roster(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'permitChecked' => ['sometimes', 'boolean'],
            'formChecked' => ['sometimes', 'boolean'],
            'attendance' => ['sometimes', 'nullable', Rule::in(['present', 'absent'])],
        ]);
        $this->exams->updateRoster($application, $data, $request->user());

        return $this->applicant($application);
    }

    /**
     * FR-07: scan the QR code on a printed permit (API only: the panel has
     * no scanner screen yet). Body: { token, sessionId? }.
     */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'sessionId' => ['nullable', 'string', 'regex:/^S\d+$/'],
        ]);
        $expected = isset($data['sessionId']) ? ExamSession::findOrFail(ExamSession::idFromPublic($data['sessionId'])) : null;
        $application = $this->exams->checkIn($data['token'], $expected, $request->user());

        return $this->applicant($application);
    }

    public function recordResult(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'between:0,100'],
            'decision' => ['required', Rule::in(['passed', 'failed'])],
        ]);
        $this->exams->recordResult($application, $data['score'], $data['decision'], $request->user());

        return $this->applicant($application);
    }

    /** Single or bulk release. Body: { ids: ["AD-2026-00001", …] }. */
    public function release(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['string'],
        ]);
        $applications = Application::whereIn('number', $data['ids'])->with('result')->get();
        $released = $this->exams->release($applications, $request->user());

        return response()->json([
            'data' => $released->map(fn (Application $a) => $this->presenter->applicant($a->fresh()))->all(),
            'released' => $released->count(),
        ]);
    }

    private function session(Request $request): ExamSession
    {
        $data = $request->validate(['sessionId' => ['required', 'string', 'regex:/^S\d+$/']]);

        return ExamSession::findOrFail(ExamSession::idFromPublic($data['sessionId']));
    }

    private function applicant(Application $application): JsonResponse
    {
        return response()->json(['data' => $this->presenter->applicant($application->fresh())]);
    }
}
