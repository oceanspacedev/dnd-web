<?php

namespace Tests\Unit;

use App\Jobs\SendKpiReminderEmailJob;
use App\Jobs\SendKpiReminderWhatsAppJob;
use PHPUnit\Framework\TestCase;

class HorizonQueueJobsTest extends TestCase
{
    public function test_kpi_reminder_jobs_use_the_notifications_queue(): void
    {
        $email = new SendKpiReminderEmailJob(1, 2, 'user@example.test', 'Subject', 'Body');
        $whatsapp = new SendKpiReminderWhatsAppJob(1, 2, '081234567890', 'Halo');

        $this->assertSame('notifications', $email->queue);
        $this->assertSame('notifications', $whatsapp->queue);
    }
}
