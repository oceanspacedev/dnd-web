<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiReminderLog extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
    ];

    /** @return BelongsTo<KpiReminderSetting, $this> */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(KpiReminderSetting::class, 'kpi_reminder_setting_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
