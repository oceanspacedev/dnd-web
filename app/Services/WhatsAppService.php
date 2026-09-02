<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class WhatsAppService
{
    /**
     * Send a WhatsApp message matching the WagHub Gateway API structure:
     *
     * POST https://waghub.mekayastudio.com/api/v1/messages
     * Headers:
     *  - Accept: application/json
     *  - Authorization: Bearer {WA_API_KEY}
     *  - Idempotency-Key: {unique_id}
     *  - Content-Type: application/json
     * Body:
     *  {
     *    "recipient": {"type": "phone", "value": "081234567890"},
     *    "message": {"type": "text", "text": "..."},
     *    "purpose": "notification",
     *    "mode": "sync",
     *    "route_key": "default",
     *    "client_reference": "..."
     *  }
     *
     * @return array ['success' => bool, 'message' => string]
     */
    public static function send(string $phoneNumber, string $message, ?string $idempotencyKey = null): array
    {
        $phone = static::normalizePhoneNumber($phoneNumber);

        if ($phone === null) {
            return [
                'success' => false,
                'message' => 'Nomor WhatsApp Indonesia tidak valid.',
            ];
        }

        $apiUrl = trim((string) config('services.whatsapp.api_url'));
        $apiKey = trim((string) config('services.whatsapp.api_key'));

        if ($apiKey === '') {
            Log::error('WhatsApp API key belum dikonfigurasi.');

            return [
                'success' => false,
                'message' => 'WA_API_KEY belum dikonfigurasi.',
            ];
        }

        if ($apiUrl === '') {
            Log::error('WhatsApp API URL belum dikonfigurasi.');

            return [
                'success' => false,
                'message' => 'WA_API_URL belum dikonfigurasi.',
            ];
        }

        // Ensure endpoint path is correctly targeted to /api/v1/messages
        $apiUrl = rtrim($apiUrl, '/');
        if (! str_contains($apiUrl, '/api/v1/messages')) {
            $apiUrl .= '/api/v1/messages';
        }

        $idempotencyKey ??= 'wa-'.Str::uuid()->toString();
        $clientRef = 'kpi-ref-'.substr(hash('sha256', $idempotencyKey), 0, 32);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$apiKey,
                'Idempotency-Key' => $idempotencyKey,
                'Content-Type' => 'application/json',
            ])
                ->connectTimeout((int) config('services.whatsapp.connect_timeout', 5))
                ->timeout((int) config('services.whatsapp.timeout', 15))
                ->post($apiUrl, [
                    'recipient' => [
                        'type' => 'phone',
                        'value' => $phone,
                    ],
                    'message' => [
                        'type' => 'text',
                        'text' => $message,
                    ],
                    'purpose' => 'notification',
                    'mode' => 'sync',
                    'route_key' => 'default',
                    'client_reference' => $clientRef,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Pesan WA berhasil dikirim via WagHub Gateway.',
                ];
            }

            Log::error("WagHub Gateway Error ({$response->status()}): ".$response->body());

            return [
                'success' => false,
                'message' => 'Gagal mengirim WA: HTTP '.$response->status().' - '.$response->body(),
            ];
        } catch (Throwable $e) {
            Log::error('WhatsApp Service Exception: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Exception: '.$e->getMessage(),
            ];
        }
    }

    public static function normalizePhoneNumber(string $phoneNumber): ?string
    {
        $phone = preg_replace('/\D+/', '', $phoneNumber);

        if ($phone === null || $phone === '') {
            return null;
        }

        if (str_starts_with($phone, '0062')) {
            $phone = '0'.substr($phone, 4);
        } elseif (str_starts_with($phone, '62')) {
            $phone = '0'.substr($phone, 2);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '0'.$phone;
        }

        return preg_match('/^08\d{8,12}$/', $phone) === 1 ? $phone : null;
    }
}
