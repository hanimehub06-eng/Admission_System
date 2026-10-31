<?php

use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\Applicant\ApplicationController;
use App\Http\Controllers\Api\Applicant\DocumentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeanController;
use App\Http\Controllers\Api\ExamQuestionController;
use App\Http\Controllers\Api\InterviewController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Clinic;
use App\Http\Controllers\Api\Office;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

/*
| JSON API used by the applicant portal and the admission office panel.
| Nginx routes /api to Laravel (paper §7.2). Endpoint reference:
| docs/API.md.
*/

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('2fa/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:login');
    Route::post('2fa/resend', [AuthController::class, 'resendTwoFactor'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:login');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:login');
    // Works with an expired access token inside the refresh window; the old token is revoked (rotation)
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:login');

    Route::middleware(['auth:api', 'active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:login');
    });
});

// In-app notifications for any signed-in user
Route::prefix('notifications')->middleware(['auth:api', 'active'])->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('read-all', [NotificationController::class, 'readAll']);
    Route::post('{id}/read', [NotificationController::class, 'read']);
});

// Applicant portal: the signed-in applicant's own application only
Route::prefix('applicant')->middleware(['auth:api', 'active', 'role:applicant'])->group(function () {
    Route::get('portal', [ApplicationController::class, 'show']);
    Route::get('announcements', [AnnouncementController::class, 'published']);
    Route::put('application', [ApplicationController::class, 'update']);
    Route::put('profile', [ApplicationController::class, 'updateProfile']);
    Route::post('submit', [ApplicationController::class, 'submit']);
    Route::post('offer/accept', [ApplicationController::class, 'acceptOffer'])->middleware('throttle:login');

    Route::get('exam-permit', [ApplicationController::class, 'examPermit']);
    Route::get('application-form', [ApplicationController::class, 'applicationForm']);
    Route::get('acceptance-letter', [ApplicationController::class, 'acceptanceLetter']);
    Route::get('schools', [ApplicationController::class, 'schools'])->middleware('throttle:api');
    Route::get('exam-sessions', [ApplicationController::class, 'examSessions']);       // EXAM_SELF_SCHEDULE only
    Route::post('exam-session', [ApplicationController::class, 'chooseExamSession']);  // EXAM_SELF_SCHEDULE only

    Route::post('documents/{key}', [DocumentController::class, 'store'])->middleware('throttle:uploads');
    Route::delete('documents/{key}', [DocumentController::class, 'destroy']);
    Route::get('documents/{key}/file', [DocumentController::class, 'file']);

    Route::post('medical/submit', [DocumentController::class, 'submitMedical']);
    Route::post('medical/{key}', [DocumentController::class, 'storeMedical'])->middleware('throttle:uploads');
    Route::delete('medical/{key}', [DocumentController::class, 'destroyMedical']);
});

// Admission office panel. Admission Staff and the Admission Head do the
// office work; only the Head releases results (FR-20). Proctors handle exam
// day (FR-16, FR-17); Read-only users (deans, registrar, DPO) only look.
// Medical clearance shows there read-only; the clinic nurse has its own
// module below. There are no routes here for accounts, backups or security
// settings (§4 role boundaries).
Route::prefix('admin')->middleware(['auth:api', 'active', 'two_factor'])->group(function () {
    Route::get('state', [Office\StateController::class, 'show'])->middleware('role:admission|admission_head|proctor|read_only');
    // Top-bar live search (the screens that show the applicant list)
    Route::get('applicants/search', [Office\StateController::class, 'search'])->middleware(['role:admission|admission_head|super_admin|read_only', 'throttle:api']);
    Route::get('reference', [Office\SetupController::class, 'reference'])->middleware('role:admission|admission_head|proctor|read_only');

    // Exam attendance (2.8): proctors, and the Admission Head (and Super Admin), who can do every personnel task
    Route::middleware(['role:admission|admission_head|super_admin|proctor', 'task:proctor'])->group(function () {
        Route::patch('applicants/{application}/exam', [Office\ExamController::class, 'roster']);
        Route::post('exam/check-in', [Office\ExamController::class, 'checkIn']);
    });
    Route::get('exam-sessions/{session}/roster', [Office\ExamController::class, 'sessionRoster'])->middleware(['role:admission|admission_head|proctor', 'task:proctor,exam_office']);

    // Proctor reports about an applicant (2.9); the office reads them
    Route::get('applicants/{application}/reports', [Office\ApplicantReportController::class, 'index'])->middleware('role:admission|admission_head|proctor');
    Route::post('applicants/{application}/reports', [Office\ApplicantReportController::class, 'store'])->middleware(['role:admission|admission_head|proctor', 'task:proctor']);

    // Admin only (use-case diagram "Admin Module"): release results (FR-20), set the admission dates
    Route::post('results/release', [Office\ExamController::class, 'release'])->middleware('role:admission_head');
    Route::patch('admission-period', [Office\SetupController::class, 'updatePeriod'])->middleware('role:admission_head');
    // The Admin can also record a dean's evaluation (any college)
    Route::post('applicants/{application}/evaluation', [InterviewController::class, 'record'])->middleware('role:admission_head');

    // Announcements of admission dates and deadlines (2.2): the Admission Head writes and publishes
    Route::middleware('role:admission_head|super_admin')->prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::patch('{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('{announcement}', [AnnouncementController::class, 'destroy']);
    });
});

// A personnel account with a task (Exam Proctor, Exam Office, Admission
// Personnel) reaches only its task's routes; untasked personnel, the Head
// and the Super Admin reach all of them (EnsureStaffTask).
Route::prefix('admin')->middleware(['auth:api', 'active', 'two_factor', 'role:admission|admission_head|super_admin'])->group(function () {

    // Admission Personnel (2.6, 2.7): documents, approve or send back, slip and permit
    Route::middleware('task:personnel')->group(function () {
        Route::prefix('applicants/{application}')->group(function () {
            Route::patch('documents/{key}', [Office\DocumentController::class, 'review']);
            Route::post('documents/verify-pending', [Office\DocumentController::class, 'verifyPending']);
            Route::get('documents/{key}/file', [Office\DocumentController::class, 'file']);
            Route::patch('documents/{key}/photocopy', [Office\DocumentController::class, 'photocopy']);
            Route::get('exam-permit', [Office\ExamController::class, 'permit']);
            Route::get('slip', [Office\ExamController::class, 'slip']);
        });

        Route::post('applicants/{application}/approve', [Office\ExamController::class, 'approve']);
        Route::post('applicants/{application}/reschedule', [Office\ExamController::class, 'reschedule']);
        Route::post('applicants/{application}/reject', [Office\ExamController::class, 'reject']);
        Route::post('exam/auto-assign', [Office\ExamController::class, 'autoAssign']);
    });

    // Exam Office (2.10, 2.11): scores and who passed
    Route::middleware('task:exam_office')->group(function () {
        Route::post('applicants/{application}/result', [Office\ExamController::class, 'recordResult']);
        Route::post('applicants/{application}/placement', [Office\ExamController::class, 'place']);
        Route::post('results/import', [Office\ExamController::class, 'importResults']);
    });

    // Setup: no task covers it
    Route::middleware('task')->group(function () {
        Route::post('exam-sessions', [Office\ExamController::class, 'storeSession']);
        Route::patch('exam-sessions/{session}', [Office\SetupController::class, 'updateSession']);
        Route::delete('exam-sessions/{session}', [Office\SetupController::class, 'deleteSession']);

        Route::get('interviews', [InterviewController::class, 'index']);
        Route::post('interview-schedules', [InterviewController::class, 'storeSchedule']);
        Route::post('applicants/{application}/interview', [InterviewController::class, 'assign']);

        Route::post('programs', [Office\SetupController::class, 'storeProgram']);
        Route::patch('programs/{program:code}', [Office\SetupController::class, 'updateProgram']);
        Route::patch('requirements/{key}', [Office\SetupController::class, 'updateRequirement']);
        Route::put('strands', [Office\SetupController::class, 'saveStrand']);
        Route::put('barangays/{district}', [Office\SetupController::class, 'saveBarangays'])->whereNumber('district');
    });

    // Staff accounts (admission personnel, clinic, college deans): the Super Admin creates and
    // disables them; the Admission Head also assigns personnel their task (2.3)
    Route::prefix('accounts')->group(function () {
        Route::middleware('role:admission_head|super_admin')->group(function () {
            Route::get('/', [Office\AccountController::class, 'index']);
            Route::patch('{user}/task', [Office\AccountController::class, 'setTask']);
        });
        Route::middleware('role:super_admin')->group(function () {
            Route::post('/', [Office\AccountController::class, 'store']);
            Route::patch('{user}/active', [Office\AccountController::class, 'setActive']);
        });
    });

    // Super admin oversight: open an applicant's medical files (read-only, every opening is audited)
    Route::get('applicants/{application}/medical/{key}/file', [Clinic\MedicalController::class, 'file'])->middleware('role:super_admin');
});

// Clinic module (admin portal, clinic role): the nurse sees medical files only,
// sends a file back or approves. Medical files are confidential to the clinic.
Route::prefix('clinic')->middleware(['auth:api', 'active', 'two_factor', 'role:clinic'])->group(function () {
    Route::get('state', [Clinic\MedicalController::class, 'state']);

    Route::prefix('applicants/{application}/medical')->group(function () {
        Route::post('approve', [Clinic\MedicalController::class, 'approve']);
        Route::get('{key}/file', [Clinic\MedicalController::class, 'file']);
        Route::patch('{key}/flag', [Clinic\MedicalController::class, 'flag']);
        Route::patch('{key}', [Clinic\MedicalController::class, 'review']);
    });
});

// College Dean module (admin portal): applicant list, evaluation and results/referral, own college only
Route::prefix('dean')->middleware(['auth:api', 'active', 'two_factor', 'role:college_dean'])->group(function () {
    Route::get('applicants', [DeanController::class, 'applicants']);
    Route::put('interview-questions', [DeanController::class, 'saveQuestions']);
    // Entrance exam questions of the dean's college (3.1)
    Route::get('exam-questions', [ExamQuestionController::class, 'index']);
    Route::post('exam-questions', [ExamQuestionController::class, 'store']);
    Route::patch('exam-questions/{question}', [ExamQuestionController::class, 'update']);
    Route::delete('exam-questions/{question}', [ExamQuestionController::class, 'destroy']);
    Route::get('interviews', [InterviewController::class, 'index']);
    Route::post('applicants/{application}/interview', [InterviewController::class, 'record']);
});

// Reports (FR-27), scoped by role inside ReportService
Route::prefix('reports')->middleware(['auth:api', 'active', 'two_factor', 'role:admission|admission_head|clinic|college_dean|proctor|read_only'])->group(function () {
    Route::get('/', [ReportController::class, 'index']);
    Route::get('{report}', [ReportController::class, 'show']);
});
