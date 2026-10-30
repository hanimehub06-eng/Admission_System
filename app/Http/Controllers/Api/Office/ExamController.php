<?php

namespace App\Http\Controllers\Api\Office;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\OfficeStatePresenter;
use App\Models\Application;
use App\Models\ExamSession;
use App\Models\Program;
use App\Services\ExamService;
use App\Services\OfferService;
use App\Services\PdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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
            'capacity' => ['required', 'integer', 'between:5,500'],
        ]);
        $session = $this->exams->createSession($data['date'], $data['batch'], $data['venue'], $data['capacity'], $request->user());

        return response()->json(['data' => $this->presenter->session($session)], 201);
    }

    /** Task 2.7: the office issues (prints) an applicant's exam permit. */
    public function permit(Application $application, PdfService $pdf): Response
    {
        return $pdf->examPermit($application)->stream("exam-permit-{$application->number}.pdf");
    }

    /** Task 2.7: the office issues the applicant's slip (the submitted application form). */
    public function slip(Application $application, PdfService $pdf): Response
    {
        return $pdf->applicationForm($application)->stream("application-slip-{$application->number}.pdf");
    }

    /** Approve for the exam. Without a sessionId the earliest session with a free seat is used (FR-14). */
    public function approve(Request $request, Application $application): JsonResponse
    {
        $session = $request->filled('sessionId') ? $this->session($request)
            : ($this->exams->nextOpenSession() ?? throw new BusinessRuleException('Every upcoming exam session is full. Add a session first.'));
        $this->exams->approve($application, $session, $request->user());

        return $this->applicant($application);
    }

    /**
     * FR-14: seat every applicant whose documents are all verified in the
     * earliest sessions with room. Body: { ids?: ["AD-2026-00001", …] }.
     */
    public function autoAssign(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:5000'], 'ids.*' => ['string']]);
        $result = $this->exams->autoAssign($data['ids'] ?? null, $request->user());

        return response()->json([
            'data' => Application::whereIn('number', $result['assigned'])->get()->map(fn (Application $a) => $this->presenter->applicant($a))->all(),
            'assigned' => count($result['assigned']),
            'waiting' => $result['waiting'],
            'message' => count($result['assigned']).' assigned'.($result['waiting'] ? ', '.count($result['waiting']).' waiting for a free seat (add a session)' : '').'.',
        ]);
    }

    /** FR-16: printable roster for one room and batch. GET …/roster?format=pdf|json */
    public function sessionRoster(Request $request, string $session): Response
    {
        $session = ExamSession::findOrFail(ExamSession::idFromPublic($session));
        $roster = $this->exams->roster($session);
        if ($request->query('format') !== 'pdf') {
            return response()->json(['data' => $roster]);
        }

        return Pdf::loadView('pdf.report', ['report' => $roster, 'by' => $request->user()->name])
            ->setPaper('a4', 'landscape')->download('roster-'.$session->publicId().'.pdf');
    }

    /** FR-19: results from a CSV upload (file) or pasted text (csv). */
    public function importResults(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required_without:csv', 'file', 'mimes:csv,txt', 'max:2048'],
            'csv' => ['required_without:file', 'string', 'max:2000000'],
        ]);
        $csv = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('csv');
        $result = $this->exams->importResults($csv, $request->user());

        return response()->json(['data' => $result, 'message' => "{$result['recorded']} result".($result['recorded'] === 1 ? '' : 's').' recorded'.($result['errors'] ? ', '.count($result['errors']).' lines skipped.' : '.')]);
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
            'envelopeReceived' => ['sometimes', 'boolean'],
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

    /** FR-21: place a released pass in one of their three choices. Body: { program: "BSIT" }. */
    public function place(Request $request, Application $application, OfferService $offers): JsonResponse
    {
        $data = $request->validate(['program' => ['required', 'string', 'max:20']]);
        $offers->place($application, Program::where('code', $data['program'])->firstOrFail(), $request->user());

        return $this->applicant($application);
    }

    /** Single or bulk release (FR-20: Admission Head only). Body: { ids: ["AD-2026-00001", …] }. */
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
