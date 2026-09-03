<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MasterDataController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - V1
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider or bootstrap/app.php
| and all of them will be assigned to the "api" middleware group.
|
*/

Route::prefix('v1')->group(function () {
    // Public authentication routes
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
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
            Route::get('supervisors', [\App\Http\Controllers\Api\V1\UserController::class, 'supervisors']);
            Route::post('import-json', [\App\Http\Controllers\Api\V1\UserController::class, 'importJson']);
            Route::get('/', [\App\Http\Controllers\Api\V1\UserController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\UserController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\UserController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\UserController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\UserController::class, 'destroy']);
        });

        // Modul 4: Manajemen KPI & Scoring
        Route::prefix('kpis')->group(function () {
            // Master KPI References
            Route::get('categories', [\App\Http\Controllers\Api\V1\KpiController::class, 'categories']);
            Route::get('types', [\App\Http\Controllers\Api\V1\KpiController::class, 'types']);
            Route::get('descriptions', [\App\Http\Controllers\Api\V1\KpiController::class, 'descriptions']);

            // User Performance Summary
            Route::get('user/{userId}/summary', [\App\Http\Controllers\Api\V1\KpiController::class, 'userSummary']);

            // KPI Details nested under KPI
            Route::post('{kpiId}/details', [\App\Http\Controllers\Api\V1\KpiController::class, 'storeDetail']);

            // KPI CRUD
            Route::get('/', [\App\Http\Controllers\Api\V1\KpiController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\KpiController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\KpiController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\KpiController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\KpiController::class, 'destroy']);
        });

        // Individual KPI Detail operations
        Route::prefix('kpi-details')->group(function () {
            Route::put('{id}', [\App\Http\Controllers\Api\V1\KpiController::class, 'updateDetail']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\KpiController::class, 'destroyDetail']);
        });

        // Modul 5: Aktivitas Kerja (Daily, Weekly, Monthly, DailyLog, Summary)
        Route::prefix('activities')->group(function () {
            // Activity Summary
            Route::get('summary', [\App\Http\Controllers\Api\V1\ActivityController::class, 'summary']);

            // Daily Tasks & Logs
            Route::get('dailies', [\App\Http\Controllers\Api\V1\ActivityController::class, 'dailies']);
            Route::post('dailies', [\App\Http\Controllers\Api\V1\ActivityController::class, 'storeDaily']);
            Route::get('dailies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'showDaily']);
            Route::put('dailies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'updateDaily']);
            Route::delete('dailies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'destroyDaily']);
            Route::get('dailies/{dailyId}/logs', [\App\Http\Controllers\Api\V1\ActivityController::class, 'dailyLogs']);
            Route::post('dailies/{dailyId}/logs', [\App\Http\Controllers\Api\V1\ActivityController::class, 'storeDailyLog']);

            // Weekly Tasks
            Route::get('weeklies', [\App\Http\Controllers\Api\V1\ActivityController::class, 'weeklies']);
            Route::post('weeklies', [\App\Http\Controllers\Api\V1\ActivityController::class, 'storeWeekly']);
            Route::get('weeklies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'showWeekly']);
            Route::put('weeklies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'updateWeekly']);
            Route::delete('weeklies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'destroyWeekly']);

            // Monthly Tasks
            Route::get('monthlies', [\App\Http\Controllers\Api\V1\ActivityController::class, 'monthlies']);
            Route::post('monthlies', [\App\Http\Controllers\Api\V1\ActivityController::class, 'storeMonthly']);
            Route::get('monthlies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'showMonthly']);
            Route::put('monthlies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'updateMonthly']);
            Route::delete('monthlies/{id}', [\App\Http\Controllers\Api\V1\ActivityController::class, 'destroyMonthly']);
        });

        // Modul 6: Presensi Karyawan (Attendances)
        Route::prefix('attendances')->group(function () {
            Route::get('user/{userId}', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'userAttendances']);
            Route::get('/', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\AttendanceController::class, 'destroy']);
        });

        // Modul 6: Review Karyawan (Employee Reviews)
        Route::prefix('employee-reviews')->group(function () {
            Route::get('user/{userId}', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'userReviews']);
            Route::get('/', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'destroy']);
        });

        // Modul 6: Evaluasi Total Performa (KPI 70% + Presensi 15% + Review 15%)
        Route::prefix('evaluations')->group(function () {
            Route::get('user/{userId}/score', [\App\Http\Controllers\Api\V1\EmployeeReviewController::class, 'integratedScore']);
        });

        // Modul 7: Pengajuan Izin & Workflow Approval (Requests & Approvals)
        Route::prefix('requests')->group(function () {
            Route::get('pending-approvals', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'pendingApprovals']);
            Route::post('{id}/approve', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'approve']);
            Route::post('{id}/reject', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'reject']);
            Route::get('/', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\RequestApprovalController::class, 'destroy']);
        });

        // Modul 7: Overopen Deadline (Overopens)
        Route::prefix('overopens')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\OveropenController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\OveropenController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\OveropenController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\OveropenController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\OveropenController::class, 'destroy']);
        });

        // Modul 7: Pemotongan Poin Kedisiplinan (Cutpoints)
        Route::prefix('cutpoints')->group(function () {
            Route::get('user/{userId}', [\App\Http\Controllers\Api\V1\CutpointController::class, 'userCutpoints']);
            Route::get('/', [\App\Http\Controllers\Api\V1\CutpointController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\CutpointController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\CutpointController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\CutpointController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\CutpointController::class, 'destroy']);
        });

        // Modul 8: Leaderboard, Analitik & Dashboard (Analytics)
        Route::prefix('analytics')->group(function () {
            Route::get('leaderboard/export', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'exportLeaderboard']);
            Route::get('leaderboard', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'leaderboard']);
            Route::get('dashboard', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'dashboard']);
            Route::get('department-stats', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'departmentStats']);
            Route::get('kpi-checklist', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'kpiChecklist']);
        });

        // Modul 9: Reminder & WhatsApp Integrasi (Reminders)
        Route::prefix('reminders')->group(function () {
            Route::post('trigger', [\App\Http\Controllers\Api\V1\ReminderController::class, 'trigger']);
            Route::post('send-test', [\App\Http\Controllers\Api\V1\ReminderController::class, 'sendTest']);
            Route::get('logs', [\App\Http\Controllers\Api\V1\ReminderController::class, 'logs']);
            Route::get('logs/{id}', [\App\Http\Controllers\Api\V1\ReminderController::class, 'showLog']);
            Route::get('settings', [\App\Http\Controllers\Api\V1\ReminderController::class, 'settings']);
            Route::post('settings', [\App\Http\Controllers\Api\V1\ReminderController::class, 'storeSetting']);
            Route::get('settings/{id}', [\App\Http\Controllers\Api\V1\ReminderController::class, 'showSetting']);
            Route::put('settings/{id}', [\App\Http\Controllers\Api\V1\ReminderController::class, 'updateSetting']);
            Route::delete('settings/{id}', [\App\Http\Controllers\Api\V1\ReminderController::class, 'destroySetting']);
            Route::post('settings/{id}/toggle', [\App\Http\Controllers\Api\V1\ReminderController::class, 'toggleSetting']);
        });

        // Modul 10: Jurnal Harian (Work Journals)
        Route::prefix('journals')->group(function () {
            Route::get('today', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'today']);
            Route::get('team', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'team']);
            Route::get('/', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'store']);
            Route::get('{id}', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'show']);
            Route::put('{id}', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'update']);
            Route::delete('{id}', [\App\Http\Controllers\Api\V1\WorkJournalController::class, 'destroy']);
        });
    });
});
