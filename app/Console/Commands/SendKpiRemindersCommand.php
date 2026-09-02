<?php

namespace App\Console\Commands;

use App\Mail\KpiReminderMail;
use App\Models\Kpi;
use App\Models\KpiReminderLog;
use App\Models\KpiReminderSetting;
use App\Models\User;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
    public function handle()
    {
        $this->info('Memulai pengecekan dan pengiriman pengingat KPI...');

        $settingId = $this->option('setting-id');
        $isDryRun = $this->option('dry-run');

        $query = KpiReminderSetting::where('is_active', true);
        if ($settingId) {
            $query->where('id', $settingId);
        }

        $settings = $query->get();

        if ($settings->isEmpty()) {
            $this->warn('Tidak ada pengaturan pengingat KPI yang aktif.');
            return 0;
        }

        $today = Carbon::today();
        $currentMonthPeriod = Carbon::now()->startOfMonth()->format('Y-m-d');
        $periodeLabel = Carbon::now()->isoFormat('MMMM YYYY');

        foreach ($settings as $setting) {
            $this->info("Memproses Aturan: [{$setting->title}] (Tipe: {$setting->type})");

            $deadlineDate = Carbon::today()->day(min($setting->deadline_day, $today->daysInMonth));
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

            if (! $shouldTrigger && ! $settingId) {
                $this->info("Hari ini (Tgl {$today->day}) bukan jadwal pengingat untuk tenggat Tgl {$setting->deadline_day}. Dilewati.");
                continue;
            }

            // Identify target users
            $targetUsers = $this->identifyTargetUsers($setting, $currentMonthPeriod);
            $this->info("Ditemukan " . count($targetUsers) . " user target yang belum menyelesaikan KPI.");

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
                if ($setting->send_email && ! empty($user->email)) {
                    $this->processEmailReminder($setting, $user, $placeholders, $isDryRun);
                }

                // 2. Send WhatsApp if enabled
                if ($setting->send_whatsapp && ! empty($user->no_hp)) {
                    $this->processWhatsAppReminder($setting, $user, $placeholders, $isDryRun);
                }
            }
        }

        $this->info('Selesai memproses seluruh pengingat KPI.');
        return 0;
    }

    /**
     * Identify users who have not completed KPI creation or filling.
     */
    protected function identifyTargetUsers(KpiReminderSetting $setting, string $currentMonthPeriod): array
    {
        $targetUsers = [];

        if ($setting->type === 'pembuatan_kpi') {
            // Target: Superiors/Approvers who haven't created KPIs for their subordinates
            $superiors = User::whereHas('approval')->distinct()->get();

            foreach ($superiors as $superior) {
                $subordinateIds = User::where('approval_id', $superior->id)->pluck('id');
                if ($subordinateIds->isEmpty()) {
                    continue;
                }

                // Check how many subordinates have KPI records created for this month
                $createdCount = Kpi::whereIn('user_id', $subordinateIds)
                    ->whereDate('date', '>=', $currentMonthPeriod)
                    ->distinct('user_id')
                    ->count('user_id');

                if ($createdCount < $subordinateIds->count()) {
                    $targetUsers[] = $superior;
                }
            }
        } else {
            // Target: Employees who have KPIs assigned but haven't filled value_actual
            $usersWithPendingKpis = User::whereHas('kpi', function ($query) use ($currentMonthPeriod) {
                $query->whereDate('date', '>=', $currentMonthPeriod)
                    ->whereHas('kpi_detail', function ($q) {
                        $q->whereNull('value_actual');
                    });
            })->get();

            foreach ($usersWithPendingKpis as $emp) {
                $targetUsers[] = $emp;
            }
        }

        return $targetUsers;
    }

    /**
     * Process Email reminder sending with log deduplication.
     */
    protected function processEmailReminder(KpiReminderSetting $setting, User $user, array $placeholders, bool $isDryRun)
    {
        $alreadySentToday = KpiReminderLog::where('kpi_reminder_setting_id', $setting->id)
            ->where('user_id', $user->id)
            ->where('channel', 'email')
            ->whereDate('sent_at', Carbon::today())
            ->exists();

        if ($alreadySentToday) {
            return;
        }

        $subject = strtr($setting->email_subject ?: 'Pengingat KPI - DnD System', $placeholders);
        $body = strtr($setting->email_body ?: KpiReminderSetting::getDefaultEmailTemplate($setting->type), $placeholders);

        if ($isDryRun) {
            $this->line(" [DRY-RUN EMAIL] Ke: {$user->email} | Subjek: {$subject}");
            return;
        }

        try {
            Mail::to($user->email)->send(new KpiReminderMail($subject, $body));

            KpiReminderLog::create([
                'kpi_reminder_setting_id' => $setting->id,
                'user_id' => $user->id,
                'channel' => 'email',
                'recipient' => $user->email,
                'status' => 'sent',
                'sent_at' => Carbon::now(),
            ]);

            $this->info(" [EMAIL SENT] Terkirim ke {$user->email}");
        } catch (Throwable $e) {
            Log::error("Gagal mengirim email pengingat KPI ke {$user->email}: " . $e->getMessage());

            KpiReminderLog::create([
                'kpi_reminder_setting_id' => $setting->id,
                'user_id' => $user->id,
                'channel' => 'email',
                'recipient' => $user->email,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'sent_at' => Carbon::now(),
            ]);

            $this->error(" [EMAIL FAILED] Gagal ke {$user->email}: {$e->getMessage()}");
        }
    }

    /**
     * Process WhatsApp reminder sending with log deduplication.
     */
    protected function processWhatsAppReminder(KpiReminderSetting $setting, User $user, array $placeholders, bool $isDryRun)
    {
        $alreadySentToday = KpiReminderLog::where('kpi_reminder_setting_id', $setting->id)
            ->where('user_id', $user->id)
            ->where('channel', 'whatsapp')
            ->whereDate('sent_at', Carbon::today())
            ->exists();

        if ($alreadySentToday) {
            return;
        }

        $message = strtr($setting->whatsapp_template ?: KpiReminderSetting::getDefaultWhatsappTemplate($setting->type), $placeholders);

        if ($isDryRun) {
            $this->line(" [DRY-RUN WA] Ke: {$user->no_hp} | Pesan: {$message}");
            return;
        }

        $result = WhatsAppService::send($user->no_hp, $message);

        KpiReminderLog::create([
            'kpi_reminder_setting_id' => $setting->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'recipient' => $user->no_hp,
            'status' => $result['success'] ? 'sent' : 'failed',
            'error_message' => $result['success'] ? null : $result['message'],
            'sent_at' => Carbon::now(),
        ]);

        if ($result['success']) {
            $this->info(" [WA SENT] Terkirim ke {$user->no_hp}");
        } else {
            $this->error(" [WA FAILED] Gagal ke {$user->no_hp}: {$result['message']}");
        }
    }
}
