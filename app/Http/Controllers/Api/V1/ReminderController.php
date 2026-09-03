<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SendTestReminderRequest;
use App\Http\Requests\Api\V1\StoreReminderSettingRequest;
use App\Http\Requests\Api\V1\TriggerReminderRequest;
use App\Http\Requests\Api\V1\UpdateReminderSettingRequest;
use App\Http\Resources\Api\V1\KpiReminderLogResource;
use App\Http\Resources\Api\V1\KpiReminderSettingResource;
use App\Mail\KpiReminderMail;
use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * @tags Reminder & WhatsApp Integrasi (Reminders & WhatsApp)
 */
class ReminderController extends Controller
{
    /**
     * Get paginated list of KPI reminder settings.
     */
    public function settings(Request $request): JsonResponse
    {
        $query = KpiReminderSetting::query();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($request->has('is_active')) {
            $isActive = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $settings = $query->orderBy('type')->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar aturan pengingat KPI berhasil diambil.',
            'data' => KpiReminderSettingResource::collection($settings),
        ]);
    }

    /**
     * Get detail of a reminder setting.
     */
    public function showSetting(int $id): JsonResponse
    {
        $setting = KpiReminderSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pengaturan pengingat tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail aturan pengingat berhasil diambil.',
            'data' => new KpiReminderSettingResource($setting),
        ]);
    }

    /**
     * Create a new reminder setting.
     */
    public function storeSetting(StoreReminderSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['is_active'] = $validated['is_active'] ?? true;

        $setting = KpiReminderSetting::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Aturan pengingat KPI berhasil dibuat.',
            'data' => new KpiReminderSettingResource($setting),
        ], 201);
    }

    /**
     * Update a reminder setting.
     */
    public function updateSetting(UpdateReminderSettingRequest $request, int $id): JsonResponse
    {
        $setting = KpiReminderSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pengaturan pengingat tidak ditemukan.',
            ], 404);
        }

        $validated = $request->validated();
        $setting->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Aturan pengingat KPI berhasil diperbarui.',
            'data' => new KpiReminderSettingResource($setting),
        ]);
    }

    /**
     * Delete a reminder setting.
     */
    public function destroySetting(int $id): JsonResponse
    {
        $setting = KpiReminderSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pengaturan pengingat tidak ditemukan.',
            ], 404);
        }

        $setting->delete();

        return response()->json([
            'success' => true,
            'message' => 'Aturan pengingat KPI berhasil dihapus.',
        ]);
    }

    /**
     * Toggle active status of a reminder setting.
     */
    public function toggleSetting(int $id): JsonResponse
    {
        $setting = KpiReminderSetting::find($id);

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pengaturan pengingat tidak ditemukan.',
            ], 404);
        }

        $setting->is_active = !$setting->is_active;
        $setting->save();

        return response()->json([
            'success' => true,
            'message' => 'Status aktif pengingat berhasil diubah menjadi: ' . ($setting->is_active ? 'AKTIF' : 'NON-AKTIF'),
            'data' => new KpiReminderSettingResource($setting),
        ]);
    }

    /**
     * Get paginated delivery logs of KPI reminders.
     */
    public function logs(Request $request): JsonResponse
    {
        $query = KpiReminderLog::with(['user', 'setting']);

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($settingId = $request->query('kpi_reminder_setting_id')) {
            $query->where('kpi_reminder_setting_id', $settingId);
        }

        if ($channel = $request->query('channel')) {
            $query->where('channel', strtolower($channel));
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtolower($status));
        }

        if ($periode = $request->query('periode')) {
            $query->where('periode', 'like', "{$periode}%");
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('destination', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('nama_lengkap', 'like', "%{$search}%")
                         ->orWhere('username', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 15)));
        $logs = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Riwayat log pengiriman pengingat berhasil diambil.',
            'data' => KpiReminderLogResource::collection($logs),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Get detail of a delivery log.
     */
    public function showLog(int $id): JsonResponse
    {
        $log = KpiReminderLog::with(['user', 'setting'])->find($id);

        if (!$log) {
            return response()->json([
                'success' => false,
                'message' => 'Log pengiriman tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Detail log pengiriman berhasil diambil.',
            'data' => new KpiReminderLogResource($log),
        ]);
    }

    /**
     * Manually trigger KPI reminder analysis & sending.
     */
    public function trigger(TriggerReminderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $isDryRun = filter_var($validated['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $settingId = $validated['setting_id'] ?? null;

        $params = [];
        if ($settingId !== null) {
            $params['--setting-id'] = $settingId;
        }
        if ($isDryRun) {
            $params['--dry-run'] = true;
        }

        try {
            $exitCode = Artisan::call('kpi:send-reminders', $params);
            $output = Artisan::output();

            return response()->json([
                'success' => $exitCode === 0,
                'message' => $isDryRun
                    ? 'Simulasi pengingat KPI (Dry-Run) berhasil dijalankan.'
                    : 'Pemicu pengiriman pengingat KPI berhasil dieksekusi.',
                'data' => [
                    'exit_code' => $exitCode,
                    'is_dry_run' => $isDryRun,
                    'setting_id' => $settingId,
                    'console_output' => trim($output),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengeksekusi pemicu pengingat KPI: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send test notification message via Email or WhatsApp.
     */
    public function sendTest(SendTestReminderRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $channel = strtolower($validated['channel']);
        $destination = $validated['destination'];
        $customMessage = $validated['message'] ?? 'Ini adalah pesan uji coba integrasi notifikasi sistem KPI DnD.';

        if ($channel === 'whatsapp') {
            $res = WhatsAppService::send($destination, $customMessage);

            return response()->json([
                'success' => $res['success'],
                'message' => $res['success']
                    ? 'Pesan uji coba WhatsApp berhasil dikirim ke ' . $destination
                    : 'Gagal mengirim pesan WhatsApp: ' . ($res['message'] ?? 'Error gateway.'),
                'data' => [
                    'channel' => 'whatsapp',
                    'destination' => $destination,
                    'gateway_response' => $res,
                ],
            ], $res['success'] ? 200 : 422);
        }

        if ($channel === 'email') {
            try {
                Mail::raw($customMessage, function ($m) use ($destination) {
                    $m->to($destination)->subject('[TEST] Uji Coba Pengingat KPI DnD');
                });

                return response()->json([
                    'success' => true,
                    'message' => 'Email uji coba berhasil dikirim ke ' . $destination,
                    'data' => [
                        'channel' => 'email',
                        'destination' => $destination,
                    ],
                ]);
            } catch (Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengirim email uji coba: ' . $e->getMessage(),
                ], 500);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Channel tidak valid.',
        ], 422);
    }
}
