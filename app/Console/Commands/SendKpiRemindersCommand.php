<?php

namespace App\Console\Commands;

use App\Mail\KpiReminderMail;
use App\Models\Kpi;
use App\Models\KpiDetail;
use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use App\Models\User;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendKpiRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kpi:send-reminders {--setting-id= : ID spesifik pengaturan pengingat} {--dry-run : Jalankan analisis tanpa benar-benar mengirim email/WA}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Kirim pengingat pembuatan dan pengisian KPI ke Email & WhatsApp berdasarkan tenggat waktu.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memulai pengecekan dan pengiriman pengingat KPI...');

        $settingId = $this->option('setting-id');
        $isDryRun = (bool) $this->option('dry-run');
        $failureCount = 0;

        $query = KpiReminderSetting::where('is_active', true);
        if ($settingId !== null) {
            $query->where('id', $settingId);
        }

        $settings = $query->get();

        if ($settings->isEmpty()) {
            if ($settingId !== null) {
                $this->error('Aturan pengingat yang diminta tidak ditemukan atau sedang tidak aktif.');

                return self::FAILURE;
            }

            $this->warn('Tidak ada pengaturan pengingat KPI yang aktif.');

            return self::SUCCESS;
        }

        $now = Date::now();
        $today = $now->copy()->startOfDay();
        $periodStart = $now->copy()->startOfMonth();
        $periodEnd = $periodStart->copy()->addMonth();
        $periodeLabel = $now->isoFormat('MMMM YYYY');

        foreach ($settings as $setting) {
            $this->info("Memproses Aturan: [{$setting->title}] (Tipe: {$setting->type})");

            $deadlineDate = $today->copy()->day(min($setting->deadline_day, $today->daysInMonth));
            $daysDiff = $today->diffInDays($deadlineDate, false); // Positive if before deadline, 0 if deadline, negative if past deadline

            $shouldTrigger = false;
            $reminderOffsets = is_array($setting->reminder_days_before) ? $setting->reminder_days_before : [];

            // Convert array values to integer
            $reminderOffsets = array_map('intval', $reminderOffsets);

            if (in_array((int) $daysDiff, $reminderOffsets, true)) {
                $shouldTrigger = true;
            } elseif ($daysDiff < 0 && $setting->send_overdue_reminder) {
                // Overdue trigger
                $shouldTrigger = true;
            }

            if (! $shouldTrigger && $settingId === null) {
                $this->info("Hari ini (Tgl {$today->day}) bukan jadwal pengingat untuk tenggat Tgl {$setting->deadline_day}. Dilewati.");

                continue;
            }

            // Identify target users
            $targetUsers = $this->identifyTargetUsers($setting, $periodStart, $periodEnd);
            $this->info('Ditemukan '.count($targetUsers).' user target yang belum menyelesaikan KPI.');

            foreach ($targetUsers as $user) {
                $tenggatLabel = $deadlineDate->format('d M Y');
                $appUrl = config('app.url', 'http://localhost');
                $link = "{$appUrl}/admin/kpis";

                $placeholders = [
                    '{nama}' => $user->nama_lengkap,
                    '{tenggat}' => $tenggatLabel,
                    '{periode}' => $periodeLabel,
                    '{link}' => $link,
                ];

                // 1. Send Email if enabled
                if ($setting->send_email) {
                    if (empty($user->email)) {
                        $failureCount++;
                        $this->recordUnavailableRecipient($setting, $user, 'email', $isDryRun);
                    } elseif (! $this->processEmailReminder($setting, $user, $placeholders, $isDryRun)) {
                        $failureCount++;
                    }
                }

                // 2. Send WhatsApp if enabled
                if ($setting->send_whatsapp) {
                    if (empty($user->no_hp)) {
                        $failureCount++;
                        $this->recordUnavailableRecipient($setting, $user, 'whatsapp', $isDryRun);
                    } elseif (! $this->processWhatsAppReminder($setting, $user, $placeholders, $isDryRun)) {
                        $failureCount++;
                    }
                }
            }
        }

        if ($failureCount > 0) {
            $this->error("Selesai dengan {$failureCount} pengiriman gagal.");

            return self::FAILURE;
        }

        $this->info('Selesai memproses seluruh pengingat KPI.');

        return self::SUCCESS;
    }

    /**
     * Identify users who have not completed KPI creation or filling.
     */
    protected function identifyTargetUsers(
        KpiReminderSetting $setting,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $targetUsers = [];

        if ($setting->type === 'pembuatan_kpi') {
            // Target: Superiors/Approvers who haven't created KPIs for their subordinates
            $superiors = User::whereIn(
                'id',
                User::query()->whereNotNull('approval_id')->select('approval_id'),
            )->get();

            foreach ($superiors as $superior) {
                $subordinateIds = User::where('approval_id', $superior->id)->pluck('id');
                if ($subordinateIds->isEmpty()) {
                    continue;
                }

                // Check how many subordinates have KPI records created for this month
                $createdCount = Kpi::whereIn('user_id', $subordinateIds)
                    ->where('date', '>=', $periodStart)
                    ->where('date', '<', $periodEnd)
                    ->distinct('user_id')
                    ->count('user_id');

                if ($createdCount < $subordinateIds->count()) {
                    $targetUsers[] = $superior;
                }
            }
        } else {
            // Target: Employees who have incomplete KPI details for the current month.
            $pendingKpiIds = KpiDetail::query()
                ->select('kpi_id')
                ->where('is_extra_task', false)
                ->where(function ($detailQuery) {
                    $detailQuery
                        ->where(function ($resultQuery) {
                            $resultQuery->where('count_type', 'RESULT')
                                ->whereNull('value_actual');
                        })
                        ->orWhere(function ($nonResultQuery) {
                            $nonResultQuery->where('count_type', 'NON')
                                ->where(function ($completionQuery) {
                                    $completionQuery->whereNull('value_result')
                                        ->orWhere('value_result', '<', 1);
                                });
                        });
                });

            $pendingUserIds = Kpi::query()
                ->select('user_id')
                ->where('date', '>=', $periodStart)
                ->where('date', '<', $periodEnd)
                ->whereIn('id', $pendingKpiIds);

            $usersWithPendingKpis = User::whereIn('id', $pendingUserIds)->get();

            foreach ($usersWithPendingKpis as $emp) {
                $targetUsers[] = $emp;
            }
        }

        return $targetUsers;
    }

    /**
     * Process Email reminder sending with log deduplication.
     */
    protected function processEmailReminder(
        KpiReminderSetting $setting,
        User $user,
        array $placeholders,
        bool $isDryRun,
    ): bool {
        $lock = $this->createReminderLock($setting, $user, 'email');

        if (! $lock->get()) {
            $this->line(" [EMAIL SKIPPED] Pengingat untuk {$user->email} sedang diproses.");

            return true;
        }

        try {
            $alreadySentToday = KpiReminderLog::where('kpi_reminder_setting_id', $setting->id)
                ->where('user_id', $user->id)
                ->where('channel', 'email')
                ->where('status', 'sent')
                ->whereDate('sent_at', Date::today())
                ->exists();

            if ($alreadySentToday) {
                return true;
            }

            $subject = strtr($setting->email_subject ?: 'Pengingat KPI - DnD System', $placeholders);
            $body = strtr($setting->email_body ?: KpiReminderSetting::getDefaultEmailTemplate($setting->type), $placeholders);

            if ($isDryRun) {
                $this->line(" [DRY-RUN EMAIL] Ke: {$user->email} | Subjek: {$subject}");

                return true;
            }

            try {
                Mail::to($user->email)->send(new KpiReminderMail($subject, $body));

                KpiReminderLog::create([
                    'kpi_reminder_setting_id' => $setting->id,
                    'user_id' => $user->id,
                    'channel' => 'email',
                    'recipient' => $user->email,
                    'status' => 'sent',
                    'sent_at' => Date::now(),
                ]);

                $this->info(" [EMAIL SENT] Terkirim ke {$user->email}");

                return true;
            } catch (Throwable $e) {
                Log::error("Gagal mengirim email pengingat KPI ke {$user->email}: ".$e->getMessage());

                KpiReminderLog::create([
                    'kpi_reminder_setting_id' => $setting->id,
                    'user_id' => $user->id,
                    'channel' => 'email',
                    'recipient' => $user->email,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'sent_at' => Date::now(),
                ]);

                $this->error(" [EMAIL FAILED] Gagal ke {$user->email}: {$e->getMessage()}");

                return false;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Process WhatsApp reminder sending with log deduplication.
     */
    protected function processWhatsAppReminder(
        KpiReminderSetting $setting,
        User $user,
        array $placeholders,
        bool $isDryRun,
    ): bool {
        $lock = $this->createReminderLock($setting, $user, 'whatsapp');

        if (! $lock->get()) {
            $this->line(" [WA SKIPPED] Pengingat untuk {$user->no_hp} sedang diproses.");

            return true;
        }

        try {
            $alreadySentToday = KpiReminderLog::where('kpi_reminder_setting_id', $setting->id)
                ->where('user_id', $user->id)
                ->where('channel', 'whatsapp')
                ->where('status', 'sent')
                ->whereDate('sent_at', Date::today())
                ->exists();

            if ($alreadySentToday) {
                return true;
            }

            $message = strtr($setting->whatsapp_template ?: KpiReminderSetting::getDefaultWhatsappTemplate($setting->type), $placeholders);

            if ($isDryRun) {
                $this->line(" [DRY-RUN WA] Ke: {$user->no_hp} | Pesan: {$message}");

                return true;
            }

            $normalizedPhone = WhatsAppService::normalizePhoneNumber((string) $user->no_hp)
                ?? trim((string) $user->no_hp);
            $idempotencyKey = 'kpi-rem-'.substr(hash(
                'sha256',
                "{$setting->id}:{$user->id}:whatsapp:".Date::today()->toDateString().":{$normalizedPhone}:{$message}",
            ), 0, 32);
            $result = WhatsAppService::send($user->no_hp, $message, $idempotencyKey);

            KpiReminderLog::create([
                'kpi_reminder_setting_id' => $setting->id,
                'user_id' => $user->id,
                'channel' => 'whatsapp',
                'recipient' => $user->no_hp,
                'status' => $result['success'] ? 'sent' : 'failed',
                'error_message' => $result['success'] ? null : $result['message'],
                'sent_at' => Date::now(),
            ]);

            if ($result['success']) {
                $this->info(" [WA SENT] Terkirim ke {$user->no_hp}");

                return true;
            }

            $this->error(" [WA FAILED] Gagal ke {$user->no_hp}: {$result['message']}");

            return false;
        } finally {
            $lock->release();
        }
    }

    private function reminderLockKey(KpiReminderSetting $setting, User $user, string $channel): string
    {
        return "kpi-reminder:{$setting->id}:{$user->id}:{$channel}:".Date::today()->toDateString();
    }

    private function createReminderLock(
        KpiReminderSetting $setting,
        User $user,
        string $channel,
    ): Lock {
        $storeName = (string) config('kpi-reminders.cache_store', 'kpi_reminders');
        $store = Cache::store($storeName)->getStore();

        if (! $store instanceof LockProvider) {
            throw new \RuntimeException("Cache store [{$storeName}] tidak mendukung atomic lock.");
        }

        return $store->lock($this->reminderLockKey($setting, $user, $channel), 600);
    }

    private function recordUnavailableRecipient(
        KpiReminderSetting $setting,
        User $user,
        string $channel,
        bool $isDryRun,
    ): void {
        $errorMessage = $channel === 'email'
            ? 'Alamat email user belum tersedia.'
            : 'Nomor WhatsApp user belum tersedia.';

        $this->warn(' ['.strtoupper($channel)." FAILED] {$errorMessage} User ID: {$user->id}");

        if ($isDryRun) {
            return;
        }

        try {
            KpiReminderLog::create([
                'kpi_reminder_setting_id' => $setting->id,
                'user_id' => $user->id,
                'channel' => $channel,
                'recipient' => '-',
                'status' => 'failed',
                'error_message' => $errorMessage,
                'sent_at' => Date::now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Gagal mencatat penerima pengingat KPI yang tidak tersedia: '.$exception->getMessage());
        }
    }
}
