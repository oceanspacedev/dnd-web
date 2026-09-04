<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiReminderSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    /** @return HasMany<KpiReminderLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(KpiReminderLog::class, 'kpi_reminder_setting_id');
    }

    public static function getDefaultEmailTemplate(string $type): string
    {
        if ($type === 'pembuatan_kpi') {
            return "Halo {nama},\n\n"
                ."Ini adalah pengingat bahwa Anda belum membuat/menugaskan KPI untuk anggota tim Anda periode {periode}.\n\n"
                ."Batas akhir pembuatan KPI: tanggal {tenggat}.\n"
                ."Mohon segera masuk ke sistem dan buatkan KPI untuk tim Anda melalui link berikut:\n"
                ."{link}\n\n"
                ."Terima kasih,\nDnD System";
        }

        return "Halo {nama},\n\n"
            ."Ini adalah pengingat bahwa Anda belum mengisi nilai aktual KPI Anda untuk periode {periode}.\n\n"
            ."Batas akhir pengisian KPI: tanggal {tenggat}.\n"
            ."Mohon segera lengkapi pengisian progress KPI Anda melalui link berikut:\n"
            ."{link}\n\n"
            ."Terima kasih,\nDnD System";
    }

    public static function getDefaultWhatsappTemplate(string $type): string
    {
        if ($type === 'pembuatan_kpi') {
            return "*PENGINGAT PEMBUATAN KPI*\n\n"
                ."Halo *{nama}*,\n"
                ."Batas akhir pembuatan KPI periode *{periode}* adalah tanggal *{tenggat}*.\n"
                ."Anda terdeteksi belum membuat KPI untuk tim Anda.\n\n"
                ."Silakan akses sistem DnD untuk membuat KPI:\n{link}";
        }

        return "*PENGINGAT PENGISIAN KPI*\n\n"
            ."Halo *{nama}*,\n"
            ."Batas akhir pengisian KPI periode *{periode}* adalah tanggal *{tenggat}*.\n"
            ."Anda terdeteksi belum melengkapi pengisian KPI.\n\n"
            ."Silakan akses sistem DnD untuk mengisi KPI:\n{link}";
    }

    protected function casts(): array
    {
        return [
            'reminder_days_before' => 'array',
            'send_overdue_reminder' => 'boolean',
            'send_email' => 'boolean',
            'send_whatsapp' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
