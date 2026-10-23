<?php

namespace App\Http\Controllers\Api\Integration;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Called by the Enrollment system (API client "enrollment"), not by a person. Off unless D12 is confirmed. */
class EnrollmentController extends Controller
{
    /** Body: { "admission_reference": "AD-2026-00001", "student_number": "2027-00123" } */
    public function studentNumber(Request $request, EnrollmentService $enrollment): JsonResponse
    {
        abort_unless($enrollment->callbackEnabled(), 404);
        $data = $request->validate([
            'admission_reference' => ['required', 'string', 'exists:applications,number'],
            'student_number' => ['required', 'string', 'max:30'],
        ]);
        $application = Application::where('number', $data['admission_reference'])->firstOrFail();
        $application = $enrollment->acknowledge($application, $data['student_number']);

        return response()->json(['data' => ['admission_reference' => $application->number, 'student_number' => $application->student_number]]);
    }
}
