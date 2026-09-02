<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @param string $phoneNumber
     * @param string $message
     * @return array ['success' => bool, 'message' => string]
     */
    public static function send(string $phoneNumber, string $message): array
    {
        // Sanitize phone number (strip whitespace, dashes, plus signs)
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (empty($phone)) {
            return [
                'success' => false,
                'message' => 'Nomor HP tidak valid.',
            ];
        }

        // Standardize format: if starts with 628, convert to 08...
        if (str_starts_with($phone, '628')) {
            $phone = '08' . substr($phone, 2);
        }

        $apiUrl = config('services.whatsapp.api_url', env('WA_API_URL', 'https://waghub.mekayastudio.com/api/v1/messages'));
        $apiKey = config('services.whatsapp.api_key', env('WA_API_KEY'));

        // Ensure endpoint path is correctly targeted to /api/v1/messages
        $apiUrl = rtrim($apiUrl, '/');
        if (! str_contains($apiUrl, '/api/v1/messages')) {
            $apiUrl .= '/api/v1/messages';
        }

        if (empty($apiKey)) {
            Log::info("WhatsApp Simulated Send to {$phone}: {$message}");
            return [
                'success' => true,
                'message' => 'Simulasi pengiriman WA berhasil (WA_API_KEY di .env belum diisi).',
            ];
        }

        $idempotencyKey = 'kpi-rem-' . uniqid() . '-' . time();
        $clientRef = 'kpi-ref-' . time();

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
                'Idempotency-Key' => $idempotencyKey,
                'Content-Type' => 'application/json',
            ])->post($apiUrl, [
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

            Log::error("WagHub Gateway Error ({$response->status()}): " . $response->body());
            return [
                'success' => false,
                'message' => 'Gagal mengirim WA: HTTP ' . $response->status() . ' - ' . $response->body(),
            ];
        } catch (\Exception $e) {
            Log::error("WhatsApp Service Exception: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Exception: ' . $e->getMessage(),
            ];
        }
    }
}
