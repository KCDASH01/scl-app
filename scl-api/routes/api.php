<?php

use App\Http\Controllers\Batch\BatchController;
use App\Http\Controllers\Branch\BranchController;
use App\Http\Controllers\Course\CourseController;
use App\Http\Controllers\Subject\SubjectController;
use App\Http\Controllers\AcademicYear\AcademicYearController;
use App\Http\Controllers\Admission\AdmissionController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\StaffAttendanceController;
use App\Http\Controllers\Activity\ActivityController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Deal\DealController;
use App\Http\Controllers\Deal\DealPaymentController;
use App\Http\Controllers\Department\DepartmentController;
use App\Http\Controllers\EmailTemplate\EmailTemplateController;
use App\Http\Controllers\Employee\EmployeeController;
use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\ExpenseCategory\ExpenseCategoryController;
use App\Http\Controllers\FollowUp\FollowUpController;
use App\Http\Controllers\Hostel\HostelController;
use App\Http\Controllers\HostelExpense\HostelExpenseController;
use App\Http\Controllers\Important\ImportantController;
use App\Http\Controllers\Meeting\MeetingController;
use App\Http\Controllers\Lead\LeadController;
use App\Http\Controllers\Note\NoteController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Pipeline\PipelineController;
use App\Http\Controllers\Pipeline\StageController;
use App\Http\Controllers\Proposal\ProposalController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\SalesTarget\SalesTargetController;
use App\Http\Controllers\Sidebar\SidebarSettingsController;
use App\Http\Controllers\StaffRole\StaffRoleController;
use Illuminate\Support\Facades\Route;

// ── Public routes ──────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('login',            [AuthController::class, 'login']);
    Route::post('forgot-password',  [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',   [AuthController::class, 'resetPassword']);
});

// ── Authenticated routes ───────────────────────────────────────────────────────

Route::middleware('auth:sanctum')->group(function () {

    Route::post('auth/logout', [AuthController::class, 'logout']);

    Route::get('me',               [MeController::class, 'show']);
    Route::put('me',               [MeController::class, 'update']);
    Route::put('me/password',      [MeController::class, 'changePassword']);

    Route::get('organization/receipt-settings', [MeController::class, 'getReceiptSettings']);
    Route::put('organization/receipt-settings', [MeController::class, 'updateReceiptSettings']);

    Route::get('sidebar-settings', [SidebarSettingsController::class, 'show']);
    Route::put('sidebar-settings', [SidebarSettingsController::class, 'update']);

    Route::middleware(['check.organization', 'check.branch'])->group(function () {

        Route::get('branches/{branch}/managers', [BranchController::class, 'managers']);
        Route::apiResource('branches', BranchController::class);
        Route::patch('branches/{branch}/status', [BranchController::class, 'toggleActive']);

        Route::get('courses/options', [CourseController::class, 'options']);
        Route::get('courses/{course}/batches', [BatchController::class, 'index']);
        Route::apiResource('courses', CourseController::class)->only(['index', 'show']);

        Route::get('batches/options', [BatchController::class, 'options']);
        Route::get('batches', [BatchController::class, 'index']);
        Route::get('batches/{batch}', [BatchController::class, 'show']);

        Route::middleware('role:superadmin')->group(function () {
            Route::post('courses', [CourseController::class, 'store']);
            Route::put('courses/{course}', [CourseController::class, 'update']);
            Route::delete('courses/{course}', [CourseController::class, 'destroy']);
            Route::patch('courses/{course}/status', [CourseController::class, 'toggleActive']);
            Route::post('courses/{course}/batches', [BatchController::class, 'store']);
            Route::put('batches/{batch}', [BatchController::class, 'update']);
            Route::delete('batches/{batch}', [BatchController::class, 'destroy']);

            Route::post('staff-roles', [StaffRoleController::class, 'store']);
            Route::put('staff-roles/{staffRole}', [StaffRoleController::class, 'update']);
            Route::delete('staff-roles/{staffRole}', [StaffRoleController::class, 'destroy']);

            Route::get('subjects', [SubjectController::class, 'index']);
            Route::post('subjects', [SubjectController::class, 'store']);
            Route::put('subjects/{subject}', [SubjectController::class, 'update']);
            Route::delete('subjects/{subject}', [SubjectController::class, 'destroy']);
        });

        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::prefix('notifications')->group(function () {
            Route::get('/',                        [NotificationController::class, 'index']);
            Route::get('/unread-count',            [NotificationController::class, 'unreadCount']);
            Route::post('/read-all',               [NotificationController::class, 'markAllRead']);
            Route::patch('/{notification}/read',   [NotificationController::class, 'markRead']);
        });

        Route::apiResource('pipelines', PipelineController::class);
        Route::post('pipelines/{pipeline}/stages',                  [StageController::class, 'store']);
        Route::put('pipelines/{pipeline}/stages/{stage}',           [StageController::class, 'update']);
        Route::delete('pipelines/{pipeline}/stages/{stage}',        [StageController::class, 'destroy']);
        Route::post('pipelines/{pipeline}/stages/reorder',          [StageController::class, 'reorder']);

        Route::get('leads/department-counts',     [LeadController::class, 'departmentCounts']);
        Route::get('leads/options',               [LeadController::class, 'options']);
        Route::get('leads/{lead}/timeline',       [LeadController::class, 'timeline']);
        Route::post('leads/{lead}/convert',       [LeadController::class, 'convertToDeal']);
        Route::post('leads/bulk-import',          [LeadController::class, 'bulkImport'])->middleware('role:superadmin,admin,manager');
        Route::apiResource('leads',               LeadController::class);

        Route::get('academic-years', [AcademicYearController::class, 'index']);
        Route::middleware('role:superadmin,admin,manager')->group(function () {
            Route::post('academic-years', [AcademicYearController::class, 'store']);
            Route::put('academic-years/{academicYear}', [AcademicYearController::class, 'update']);
            Route::delete('academic-years/{academicYear}', [AcademicYearController::class, 'destroy']);
        });

        Route::get('admissions/{admission}/documents/{document}/download', [AdmissionController::class, 'downloadDocument']);
        Route::post('admissions/{admission}/documents',                    [AdmissionController::class, 'storeDocument']);
        Route::delete('admissions/{admission}/documents/{document}',       [AdmissionController::class, 'destroyDocument']);
        Route::get('admissions',                                          [AdmissionController::class, 'index']);
        Route::get('admissions/{admission}',                              [AdmissionController::class, 'show']);
        Route::put('admissions/{admission}',                              [AdmissionController::class, 'update']);

        Route::get('attendance/subjects', [AttendanceController::class, 'subjects']);
        Route::get('attendance/students', [AttendanceController::class, 'students']);
        Route::post('attendance/mark', [AttendanceController::class, 'mark']);
        Route::patch('attendance/{attendanceRecord}', [AttendanceController::class, 'update'])->whereNumber('attendanceRecord');

        Route::middleware('role:superadmin,admin,manager')->group(function () {
            Route::get('staff-attendance', [StaffAttendanceController::class, 'index']);
            Route::post('staff-attendance/mark', [StaffAttendanceController::class, 'mark']);
            Route::patch('staff-attendance/{staffAttendance}', [StaffAttendanceController::class, 'update'])->whereNumber('staffAttendance');
        });

        Route::patch('deals/{deal}/stage',          [DealController::class, 'moveStage']);
        Route::get('deals/{deal}/payments',              [DealPaymentController::class, 'index']);
        Route::post('deals/{deal}/payments',             [DealPaymentController::class, 'store']);
        Route::put('deals/{deal}/payments/{payment}',    [DealPaymentController::class, 'update']);
        Route::apiResource('deals',                 DealController::class);

        Route::get('follow-ups', [FollowUpController::class, 'index']);
        Route::get('meetings', [MeetingController::class, 'index']);
        Route::get('important', [ImportantController::class, 'index']);
        Route::get('calendar', [CalendarController::class, 'index']);

        Route::patch('activities/{activity}/done', [ActivityController::class, 'markDone']);
        Route::apiResource('activities',            ActivityController::class);

        Route::apiResource('notes', NoteController::class)->except(['show']);

        Route::prefix('sales-targets')->group(function () {
            Route::get('/',                          [SalesTargetController::class, 'index']);
            Route::get('/my-progress',               [SalesTargetController::class, 'myProgress']);
            Route::post('/',                         [SalesTargetController::class, 'upsert']);
            Route::get('/{user}/progress',           [SalesTargetController::class, 'userProgress']);
            Route::patch('/{salesTarget}/received',  [SalesTargetController::class, 'updateReceived']);
        });

        Route::get('whatsapp-templates', fn () => response()->json([
            'data' => \App\Models\WhatsAppTemplate::where('is_active', true)
                        ->orderBy('sort_order')->orderBy('id')
                        ->get(['id', 'name', 'message']),
        ]));

        Route::middleware('role:superadmin,admin,manager')->group(function () {
            Route::get('staff-roles', [StaffRoleController::class, 'index']);
            Route::get('employees/options', [EmployeeController::class, 'options']);
            Route::apiResource('employees',   EmployeeController::class);
            Route::apiResource('departments', DepartmentController::class);

            Route::get('hostels/options', [HostelController::class, 'options']);
            Route::apiResource('hostels', HostelController::class);

            Route::get('expense-categories/options', [ExpenseCategoryController::class, 'options']);
            Route::apiResource('expense-categories', ExpenseCategoryController::class);

            Route::get('hostel-expenses/export', [HostelExpenseController::class, 'export']);
            Route::apiResource('hostel-expenses', HostelExpenseController::class);
        });

        Route::get('reports', [ReportsController::class, 'index']);
        Route::get('reports/leads', [ReportsController::class, 'leadReport']);
        Route::get('reports/leads/export', [ReportsController::class, 'exportLeadReport']);
        Route::get('reports/revenue', [ReportsController::class, 'revenueReport']);
        Route::get('reports/revenue/export', [ReportsController::class, 'exportRevenueReport']);
        Route::get('reports/admissions', [ReportsController::class, 'admissionReport']);
        Route::get('reports/admissions/export', [ReportsController::class, 'exportAdmissionReport']);

        Route::post('leads/{lead}/proposals/generate', [ProposalController::class, 'generate']);
        Route::get('leads/{lead}/proposals',           [ProposalController::class, 'index']);
        Route::get('proposals/{proposal}',             [ProposalController::class, 'show']);
        Route::put('proposals/{proposal}',             [ProposalController::class, 'update']);
        Route::delete('proposals/{proposal}',          [ProposalController::class, 'destroy']);

        Route::apiResource('email-templates', EmailTemplateController::class);
    });
});
