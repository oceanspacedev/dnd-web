<?php

namespace App\Services;

use RuntimeException;

class WhatsAppOtpNotificationService
{
    public function sendOtp(string $target, string $otp, int $otpRecordId): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $expiresIn = max(1, (int) config('services.whatsapp.otp_expires_in', 60));
        $validFor = $expiresIn % 60 === 0
            ? ($expiresIn / 60).' menit'
            : $expiresIn.' detik';

        $message = strtr(
            (string) config(
                'services.whatsapp.otp_message',
                'Kode OTP {app_name} Anda: {otp}. Berlaku {expires_in}. Jangan bagikan kode ini kepada siapa pun.',
            ),
            [
                '{app_name}' => (string) config('app.name', 'DnD'),
                '{otp}' => $otp,
                '{expires_in}' => $validFor,
            ],
        );

        $result = WhatsAppService::send(
            $target,
            $message,
            'whatsapp-login-otp-'.$otpRecordId,
        );

        if (! $result['success']) {
            throw new RuntimeException('Pengiriman WhatsApp gagal.');
        }
    }
}
