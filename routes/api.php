<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CutpointController;
use App\Http\Controllers\Api\V1\EmployeeReviewController;
use App\Http\Controllers\Api\V1\KpiController;
use App\Http\Controllers\Api\V1\MasterDataController;
use App\Http\Controllers\Api\V1\OveropenController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\RequestApprovalController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkJournalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - V1
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by bootstrap/app.php and all of them are assigned
| to the "api" middleware group.
|
*/

Route::prefix('v1')->group(function () {
    // Public authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    });

    // Authenticated routes (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // Modul 1: Auth & Profile
        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::put('change-password', [AuthController::class, 'changePassword']);
            Route::get('subordinates', [AuthController::class, 'subordinates']);
        });

        // Modul 2: Master Data
        Route::prefix('master')->group(function () {
            // Areas
            Route::get('areas', [MasterDataController::class, 'areas']);
            Route::get('areas/{id}', [MasterDataController::class, 'showArea']);
            Route::post('areas', [MasterDataController::class, 'storeArea']);
            Route::put('areas/{id}', [MasterDataController::class, 'updateArea']);
            Route::delete('areas/{id}', [MasterDataController::class, 'destroyArea']);

            // Divisis
            Route::get('divisis', [MasterDataController::class, 'divisis']);
            Route::get('divisis/{id}', [MasterDataController::class, 'showDivisi']);
            Route::post('divisis', [MasterDataController::class, 'storeDivisi']);
            Route::put('divisis/{id}', [MasterDataController::class, 'updateDivisi']);
            Route::delete('divisis/{id}', [MasterDataController::class, 'destroyDivisi']);

            // Positions
            Route::get('positions', [MasterDataController::class, 'positions']);
            Route::get('positions/{id}', [MasterDataController::class, 'showPosition']);
            Route::post('positions', [MasterDataController::class, 'storePosition']);
            Route::put('positions/{id}', [MasterDataController::class, 'updatePosition']);
            Route::delete('positions/{id}', [MasterDataController::class, 'destroyPosition']);

            // Roles
            Route::get('roles', [MasterDataController::class, 'roles']);
            Route::get('roles/{id}', [MasterDataController::class, 'showRole']);

            // Daily Task Categories & Statuses
            Route::get('task-categories', [MasterDataController::class, 'taskCategories']);
            Route::get('task-statuses', [MasterDataController::class, 'taskStatuses']);
        });

        // Modul 3: Manajemen Karyawan (Users)
        Route::prefix('users')->group(function () {
            Route::get('supervisors', [UserController::class, 'supervisors']);
            Route::post('import-json', [UserController::class, 'importJson']);
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('{id}', [UserController::class, 'show']);
            Route::put('{id}', [UserController::class, 'update']);
            Route::delete('{id}', [UserController::class, 'destroy']);
        });

        // Modul 4: Manajemen KPI & Scoring
        Route::prefix('kpis')->group(function () {
            // Master KPI References
            Route::get('categories', [KpiController::class, 'categories']);
            Route::get('types', [KpiController::class, 'types']);
            Route::get('descriptions', [KpiController::class, 'descriptions']);

            // User Performance Summary
            Route::get('user/{userId}/summary', [KpiController::class, 'userSummary']);

            // KPI Details nested under KPI
            Route::post('{kpiId}/details', [KpiController::class, 'storeDetail']);

            // KPI CRUD
            Route::get('/', [KpiController::class, 'index']);
            Route::post('/', [KpiController::class, 'store']);
            Route::get('{id}', [KpiController::class, 'show']);
            Route::put('{id}', [KpiController::class, 'update']);
            Route::delete('{id}', [KpiController::class, 'destroy']);
        });

        // Individual KPI Detail operations
        Route::prefix('kpi-details')->group(function () {
            Route::put('{id}', [KpiController::class, 'updateDetail']);
            Route::delete('{id}', [KpiController::class, 'destroyDetail']);
        });

        // Modul 5: Aktivitas Kerja (Daily, Weekly, Monthly, DailyLog, Summary)
        Route::prefix('activities')->group(function () {
            // Activity Summary
            Route::get('summary', [ActivityController::class, 'summary']);

            // Daily Tasks & Logs
            Route::get('dailies', [ActivityController::class, 'dailies']);
            Route::post('dailies', [ActivityController::class, 'storeDaily']);
            Route::get('dailies/{id}', [ActivityController::class, 'showDaily']);
            Route::put('dailies/{id}', [ActivityController::class, 'updateDaily']);
            Route::delete('dailies/{id}', [ActivityController::class, 'destroyDaily']);
            Route::get('dailies/{dailyId}/logs', [ActivityController::class, 'dailyLogs']);
            Route::post('dailies/{dailyId}/logs', [ActivityController::class, 'storeDailyLog']);

            // Weekly Tasks
            Route::get('weeklies', [ActivityController::class, 'weeklies']);
            Route::post('weeklies', [ActivityController::class, 'storeWeekly']);
            Route::get('weeklies/{id}', [ActivityController::class, 'showWeekly']);
            Route::put('weeklies/{id}', [ActivityController::class, 'updateWeekly']);
            Route::delete('weeklies/{id}', [ActivityController::class, 'destroyWeekly']);

            // Monthly Tasks
            Route::get('monthlies', [ActivityController::class, 'monthlies']);
            Route::post('monthlies', [ActivityController::class, 'storeMonthly']);
            Route::get('monthlies/{id}', [ActivityController::class, 'showMonthly']);
            Route::put('monthlies/{id}', [ActivityController::class, 'updateMonthly']);
            Route::delete('monthlies/{id}', [ActivityController::class, 'destroyMonthly']);
        });

        // Modul 6: Presensi Karyawan (Attendances)
        Route::prefix('attendances')->group(function () {
            Route::get('user/{userId}', [AttendanceController::class, 'userAttendances']);
            Route::get('/', [AttendanceController::class, 'index']);
            Route::post('/', [AttendanceController::class, 'store']);
            Route::get('{id}', [AttendanceController::class, 'show']);
            Route::put('{id}', [AttendanceController::class, 'update']);
            Route::delete('{id}', [AttendanceController::class, 'destroy']);
        });

        // Modul 6: Review Karyawan (Employee Reviews)
        Route::prefix('employee-reviews')->group(function () {
            Route::get('user/{userId}', [EmployeeReviewController::class, 'userReviews']);
            Route::get('/', [EmployeeReviewController::class, 'index']);
            Route::post('/', [EmployeeReviewController::class, 'store']);
            Route::get('{id}', [EmployeeReviewController::class, 'show']);
            Route::put('{id}', [EmployeeReviewController::class, 'update']);
            Route::delete('{id}', [EmployeeReviewController::class, 'destroy']);
        });

        // Modul 6: Evaluasi Total Performa (KPI 70% + Presensi 15% + Review 15%)
        Route::prefix('evaluations')->group(function () {
            Route::get('user/{userId}/score', [EmployeeReviewController::class, 'integratedScore']);
        });

        // Modul 7: Pengajuan Izin & Workflow Approval (Requests & Approvals)
        Route::prefix('requests')->group(function () {
            Route::get('pending-approvals', [RequestApprovalController::class, 'pendingApprovals']);
            Route::post('{id}/approve', [RequestApprovalController::class, 'approve']);
            Route::post('{id}/reject', [RequestApprovalController::class, 'reject']);
            Route::get('/', [RequestApprovalController::class, 'index']);
            Route::post('/', [RequestApprovalController::class, 'store']);
            Route::get('{id}', [RequestApprovalController::class, 'show']);
            Route::put('{id}', [RequestApprovalController::class, 'update']);
            Route::delete('{id}', [RequestApprovalController::class, 'destroy']);
        });

        // Modul 7: Overopen Deadline (Overopens)
        Route::prefix('overopens')->group(function () {
            Route::get('/', [OveropenController::class, 'index']);
            Route::post('/', [OveropenController::class, 'store']);
            Route::get('{id}', [OveropenController::class, 'show']);
            Route::put('{id}', [OveropenController::class, 'update']);
            Route::delete('{id}', [OveropenController::class, 'destroy']);
        });

        // Modul 7: Pemotongan Poin Kedisiplinan (Cutpoints)
        Route::prefix('cutpoints')->group(function () {
            Route::get('user/{userId}', [CutpointController::class, 'userCutpoints']);
            Route::get('/', [CutpointController::class, 'index']);
            Route::post('/', [CutpointController::class, 'store']);
            Route::get('{id}', [CutpointController::class, 'show']);
            Route::put('{id}', [CutpointController::class, 'update']);
            Route::delete('{id}', [CutpointController::class, 'destroy']);
        });

        // Modul 8: Leaderboard, Analitik & Dashboard (Analytics)
        Route::prefix('analytics')->group(function () {
            Route::get('leaderboard/export', [AnalyticsController::class, 'exportLeaderboard']);
            Route::get('leaderboard', [AnalyticsController::class, 'leaderboard']);
            Route::get('dashboard', [AnalyticsController::class, 'dashboard']);
            Route::get('department-stats', [AnalyticsController::class, 'departmentStats']);
            Route::get('kpi-checklist', [AnalyticsController::class, 'kpiChecklist']);
        });

        // Modul 9: Reminder & WhatsApp Integrasi (Reminders)
        Route::prefix('reminders')->group(function () {
            Route::post('trigger', [ReminderController::class, 'trigger']);
            Route::post('send-test', [ReminderController::class, 'sendTest']);
            Route::get('logs', [ReminderController::class, 'logs']);
            Route::get('logs/{id}', [ReminderController::class, 'showLog']);
            Route::get('settings', [ReminderController::class, 'settings']);
            Route::post('settings', [ReminderController::class, 'storeSetting']);
            Route::get('settings/{id}', [ReminderController::class, 'showSetting']);
            Route::put('settings/{id}', [ReminderController::class, 'updateSetting']);
            Route::delete('settings/{id}', [ReminderController::class, 'destroySetting']);
            Route::post('settings/{id}/toggle', [ReminderController::class, 'toggleSetting']);
        });

        // Modul 10: Jurnal Harian (Work Journals)
        Route::prefix('journals')->group(function () {
            Route::get('today', [WorkJournalController::class, 'today']);
            Route::get('team', [WorkJournalController::class, 'team']);
            Route::get('/', [WorkJournalController::class, 'index']);
            Route::post('/', [WorkJournalController::class, 'store']);
            Route::get('{id}', [WorkJournalController::class, 'show']);
            Route::put('{id}', [WorkJournalController::class, 'update']);
            Route::delete('{id}', [WorkJournalController::class, 'destroy']);
        });
    });
});
