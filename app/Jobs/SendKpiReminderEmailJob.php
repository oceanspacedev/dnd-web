<?php

namespace App\Jobs;

use App\Mail\KpiReminderMail;
use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendKpiReminderEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $settingId,
        public int $userId,
        public string $email,
        public string $subject,
        public string $body,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        if ($this->alreadySentToday()) {
            return;
        }

        Mail::to($this->email)->send(new KpiReminderMail($this->subject, $this->body));

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
            ->where('channel', 'email')
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
            'channel' => 'email',
            'recipient' => $this->email,
            'status' => $status,
            'error_message' => $errorMessage,
            'sent_at' => Date::now(),
        ]);
    }
}
