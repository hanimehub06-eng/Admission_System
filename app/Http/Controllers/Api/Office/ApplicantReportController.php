<?php

namespace App\Http\Controllers\Api\Office;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicantReport;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Task 2.9: an exam proctor's report about an applicant. Body: { category, body } */
class ApplicantReportController extends Controller
{
    public function index(Application $application): JsonResponse
    {
        $reports = ApplicantReport::with('reporter')->where('application_id', $application->id)->latest('id')->get();

        return response()->json(['data' => $reports->map->toApi()->all()]);
    }

    public function store(Request $request, Application $application, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(ApplicantReport::CATEGORIES)],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $report = ApplicantReport::create($data + [
            'application_id' => $application->id,
            'exam_session_id' => $application->examRegistration?->exam_session_id,
            'reported_by' => $request->user()->id,
        ]);
        $audit->record('applicant.reported', "Filed a {$report->category} report", $report, $application);

        return response()->json(['data' => $report->load('reporter')->toApi()], 201);
    }
}
