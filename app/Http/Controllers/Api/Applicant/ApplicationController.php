<?php

namespace App\Http\Controllers\Api\Applicant;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ApplicantPortalPresenter;
use App\Http\Presenters\OfficeStatePresenter;
use App\Models\Application;
use App\Models\ExamSession;
use App\Services\ApplicationService;
use App\Services\ExamService;
use App\Services\OfferService;
use App\Services\PdfService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * The applicant's own application. No IDs in the URL: the application is
 * always the signed-in applicant's, so nobody can address someone else's.
 */
class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationService $applications,
        private ApplicantPortalPresenter $presenter,
    ) {}

    /** Everything the portal shows (replaces the localStorage state). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->presenter->present($this->application($request))]);
    }

    /** Autosave of "My application" (FR-02). */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'step' => ['sometimes', 'integer', 'between:1,3'],
            'completed' => ['sometimes', 'array'],
            'completed.*' => ['integer', 'between:1,3'],
        ]);

        $application = $this->application($request);
        $skipped = $this->applications->saveForm($application, $request->all(), $request->user());

        return response()->json([
            'data' => $this->presenter->present($application->fresh()),
            'skipped' => (object) $skipped,
        ]);
    }

    /** "My profile": mobile number any time, names while the form is still editable (FR-01, FR-10). */
    public function updateProfile(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $this->applications->updateContact($application, $request->all(), $request->user());

        return response()->json([
            'data' => $this->presenter->present($application->fresh()),
            'message' => 'Profile saved.',
        ]);
    }

    /** Send to admissions (FR-03, FR-05). */
    public function submit(Request $request): JsonResponse
    {
        $application = $this->application($request);
        $this->applications->submit($application, $request->user());

        return response()->json([
            'data' => $this->presenter->present($application->fresh()),
            'message' => "Sent to admissions. We'll let you know once it's reviewed.",
        ]);
    }

    /**
     * "Accept offer" on the result screen (FR-09). With applicant 2FA on,
     * the first call emails a code and answers 202 with a challenge; the
     * second call sends { challenge, code }.
     */
    public function acceptOffer(Request $request, OfferService $offers, TwoFactorService $twoFactor): JsonResponse
    {
        $application = $this->application($request);
        $user = $request->user();

        if ($application->status === ApplicationStatus::Offered && $twoFactor->requiredForAcceptance($user)) {
            $data = $request->validate(['challenge' => ['nullable', 'string'], 'code' => ['required_with:challenge', 'nullable', 'digits:6']]);
            if (empty($data['challenge'])) {
                return response()->json([
                    'data' => ['two_factor_required' => true, 'method' => 'email', 'challenge' => $twoFactor->challenge($user, TwoFactorService::SLOT_ACCEPTANCE)],
                    'message' => "We emailed a 6-digit code to {$user->email}. Enter it to accept your slot.",
                ], 202);
            }
            if ($twoFactor->verify($data['challenge'], $data['code'], TwoFactorService::SLOT_ACCEPTANCE)?->id !== $user->id) {
                throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
            }
        }

        $offers->accept($application, $user);

        return response()->json([
            'data' => $this->presenter->present($application->fresh()),
            'message' => 'Offer accepted. Last step: submit your medical requirements.',
        ]);
    }

    /** FR-07: exam permit with QR code (PDF). */
    public function examPermit(Request $request, PdfService $pdf): Response
    {
        $application = $this->application($request);

        return $pdf->examPermit($application)->stream("exam-permit-{$application->number}.pdf");
    }

    /** FR-07: printable application form (PDF). */
    public function applicationForm(Request $request, PdfService $pdf): Response
    {
        $application = $this->application($request);

        return $pdf->applicationForm($application)->stream("application-form-{$application->number}.pdf");
    }

    /** FR-09: acceptance letter (PDF). */
    public function acceptanceLetter(Request $request, PdfService $pdf): Response
    {
        $application = $this->application($request);

        return $pdf->acceptanceLetter($application)->stream("acceptance-letter-{$application->number}.pdf");
    }

    /** D17 (off by default): sessions the applicant may choose. */
    public function examSessions(ExamService $exams, OfficeStatePresenter $office): JsonResponse
    {
        abort_unless(config('admission.exams.self_schedule'), 404);

        return response()->json(['data' => $exams->openSessions()->map(fn (ExamSession $s) => $office->session($s) + [
            'time' => ExamSession::BATCHES[$s->batch]['time'],
            'seatsLeft' => $s->capacity - $s->registrations_count,
        ])->all()]);
    }

    /** D17 (off by default): pick a session once every document is verified. */
    public function chooseExamSession(Request $request, ExamService $exams): JsonResponse
    {
        abort_unless(config('admission.exams.self_schedule'), 404);
        $data = $request->validate(['sessionId' => ['required', 'string', 'regex:/^S\d+$/']]);
        $session = $exams->openSessions()->firstWhere('id', ExamSession::idFromPublic($data['sessionId']))
            ?? throw ValidationException::withMessages(['sessionId' => 'That session is full or no longer open.']);
        $application = $this->application($request);
        $exams->approve($application, $session, $request->user());

        return response()->json(['data' => $this->presenter->present($application->fresh())]);
    }

    private function application(Request $request): Application
    {
        return $this->applications->current($request->user()->applicant);
    }
}
