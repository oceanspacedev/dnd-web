<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KpiReminderLog extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function setting()
    {
        return $this->belongsTo(KpiReminderSetting::class, 'kpi_reminder_setting_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
