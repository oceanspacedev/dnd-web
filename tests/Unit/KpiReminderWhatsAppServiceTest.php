<?php

namespace Tests\Unit;

use App\Services\WhatsAppService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KpiReminderWhatsAppServiceTest extends TestCase
{
    public function test_missing_api_key_fails_without_sending_a_request(): void
    {
        Http::fake();
        config()->set('services.whatsapp.api_url', 'https://gateway.example.test/api/v1/messages');
        config()->set('services.whatsapp.api_key');

        $result = WhatsAppService::send('081234567890', 'Pengingat KPI');

        $this->assertFalse($result['success']);
        $this->assertSame('WA_API_KEY belum dikonfigurasi.', $result['message']);
        Http::assertNothingSent();
    }

    public function test_it_normalizes_international_indonesian_number_and_preserves_idempotency_key(): void
    {
        Http::fake([
            'https://gateway.example.test/api/v1/messages' => Http::response(['success' => true]),
        ]);
        config()->set('services.whatsapp.api_url', 'https://gateway.example.test/api/v1/messages');
        config()->set('services.whatsapp.api_key', 'test-key');

        $result = WhatsAppService::send(
            '+62 812-3456-789',
            'Pengingat KPI',
            'stable-idempotency-key',
        );
        $retryResult = WhatsAppService::send(
            '08123456789',
            'Pengingat KPI',
            'stable-idempotency-key',
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($retryResult['success']);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://gateway.example.test/api/v1/messages'
                && $request['recipient']['value'] === '08123456789'
                && $request->hasHeader('Idempotency-Key', 'stable-idempotency-key');
        });

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame(
            $requests[0][0]['client_reference'],
            $requests[1][0]['client_reference'],
        );
    }

    public function test_invalid_phone_number_fails_without_sending_a_request(): void
    {
        Http::fake();
        config()->set('services.whatsapp.api_url', 'https://gateway.example.test/api/v1/messages');
        config()->set('services.whatsapp.api_key', 'test-key');

        $result = WhatsAppService::send('12345', 'Pengingat KPI');

        $this->assertFalse($result['success']);
        $this->assertSame('Nomor WhatsApp Indonesia tidak valid.', $result['message']);
        Http::assertNothingSent();
    }
}
