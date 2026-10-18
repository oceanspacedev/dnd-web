<?php

namespace App\Jobs;

use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

class SendKpiReminderWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $settingId,
        public int $userId,
        public string $phoneNumber,
        public string $message,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        if ($this->alreadySentToday()) {
            return;
        }

        $normalizedPhone = WhatsAppService::normalizePhoneNumber($this->phoneNumber)
            ?? trim($this->phoneNumber);
        $idempotencyKey = 'kpi-rem-'.substr(hash(
            'sha256',
            "{$this->settingId}:{$this->userId}:whatsapp:".Date::today()->toDateString().":{$normalizedPhone}:{$this->message}",
        ), 0, 32);
        $result = WhatsAppService::send($this->phoneNumber, $this->message, $idempotencyKey);

        if (! ($result['success'] ?? false)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Pengiriman WhatsApp gagal.'));
        }

        $this->writeLog('sent');
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->alreadySentToday()) {
            return;
        }

        $this->writeLog('failed', $exception?->getMessage());
    }

    private function alreadySentToday(): bool
    {
        return KpiReminderLog::query()
            ->where('kpi_reminder_setting_id', $this->settingId)
            ->where('user_id', $this->userId)
            ->where('channel', 'whatsapp')
            ->where('status', 'sent')
            ->whereDate('sent_at', Date::today())
            ->exists();
    }

    private function writeLog(string $status, ?string $errorMessage = null): void
    {
        if (! KpiReminderSetting::query()->whereKey($this->settingId)->exists()) {
            return;
        }

        KpiReminderLog::query()->create([
            'kpi_reminder_setting_id' => $this->settingId,
            'user_id' => $this->userId,
            'channel' => 'whatsapp',
            'recipient' => $this->phoneNumber,
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => Date::now(),
        ]);
    }
}
