<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Application;
use App\Models\ExamSession;
use App\Services\Documents\DocumentStorage;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * The applicant's printable documents (FR-07, FR-09): the exam permit with
 * a QR code and signature lines, the application form, and the acceptance
 * letter. Each is only available once the applicant is eligible for it.
 */
class PdfService
{
    public function __construct(
        private ApplicationService $applications,
        private OfferService $offers,
    ) {}

    public function examPermit(Application $application): PdfDocument
    {
        $registration = $application->examRegistration?->load('session')
            ?? throw new BusinessRuleException('Your exam permit is issued once admissions approves you for the exam.');
        $session = $registration->session;

        return Pdf::loadView('pdf.exam-permit', [
            'application' => $application,
            'applicant' => $application->applicant,
            'permit' => $registration->permit_no,
            'session' => $session,
            'time' => ExamSession::BATCHES[$session->batch]['time'],
            'batch' => ExamSession::BATCHES[$session->batch]['label'],
            'qr' => $this->qr($registration->qr_token ?? $registration->permit_no),
            'photo' => $this->photo($application),
        ])->setPaper('a4');
    }

    public function applicationForm(Application $application): PdfDocument
    {
        if (! $application->submitted_at) {
            throw new BusinessRuleException('The application form can be printed after you submit it.');
        }
        $application->loadMissing(['applicant.user', 'choices.program', 'documents.requirement', 'period']);

        return Pdf::loadView('pdf.application-form', [
            'application' => $application,
            'applicant' => $application->applicant,
            'values' => $this->applications->formValues($application),
            'documents' => $this->applications->requiredDocuments($application),
            'uploaded' => $application->documents->keyBy('requirement_id'),
            'photo' => $this->photo($application),
        ])->setPaper('a4');
    }

    public function acceptanceLetter(Application $application): PdfDocument
    {
        $offered = [ApplicationStatus::Offered, ApplicationStatus::SlotAccepted, ApplicationStatus::ForMedical, ApplicationStatus::Admitted];
        if (! in_array($application->status, $offered, true)) {
            throw new BusinessRuleException('The acceptance letter is issued with your offer of admission.');
        }

        return Pdf::loadView('pdf.acceptance-letter', [
            'application' => $application,
            'applicant' => $application->applicant,
            'program' => $application->offeredProgram,
            'issued' => $this->offers->localDate($application->offered_at),
            'deadline' => $this->offers->localDate($application->acceptance_deadline),
            'accepted' => $application->offer_accepted_at ? $this->offers->localDate($application->offer_accepted_at) : null,
        ])->setPaper('a4');
    }

    private function qr(string $value): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(170, 1), new SvgImageBackEnd)))->writeString($value);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** The uploaded passport photo, if it's an image the PDF can show. */
    private function photo(Application $application): ?string
    {
        $doc = $application->documents()->whereHas('requirement', fn ($q) => $q->where('key', 'photo'))->first();
        if (! $doc || ! in_array($doc->mime, ['image/jpeg', 'image/png'], true)) {
            return null;
        }
        try {
            $stream = app(DocumentStorage::class)->readStream($doc);
            $bytes = stream_get_contents($stream);
            fclose($stream);
        } catch (\Throwable) {
            return null; // the permit still prints without the photo
        }

        return "data:{$doc->mime};base64,".base64_encode($bytes);
    }
}
