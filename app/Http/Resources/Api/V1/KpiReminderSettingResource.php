<?php

namespace App\Http\Resources\Api\V1;

use App\Models\KpiReminderSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin KpiReminderSetting */
class KpiReminderSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'deadline_day' => (int) $this->deadline_day,
            'reminder_days_before' => $this->reminder_days_before ?? [],
            'send_overdue_reminder' => (bool) $this->send_overdue_reminder,
            'send_email' => (bool) $this->send_email,
            'send_whatsapp' => (bool) $this->send_whatsapp,
            'email_template' => $this->email_body ?? $this->email_template ?? KpiReminderSetting::getDefaultEmailTemplate($this->type ?? 'pengisian_kpi'),
            'whatsapp_template' => $this->whatsapp_template ?? $this->getDefaultWhatsappTemplate($this->type ?? 'pengisian_kpi'),
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
