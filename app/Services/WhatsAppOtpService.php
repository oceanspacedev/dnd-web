<?php

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappOtp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WhatsAppOtpService
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        protected WhatsAppOtpNotificationService $whatsApp,
    ) {}

    /**
     * @return array{otp_record: WhatsappOtp, expires_in: int}
     */
    public function issue(User $user, string $number, string $purpose): array
    {
        $expiresIn = max(1, (int) config('services.whatsapp.otp_expires_in', 60));
        $gatewayTimeout = (int) config('services.whatsapp.timeout', 15)
            + (int) config('services.whatsapp.connect_timeout', 5);
        $lockSeconds = max(30, $gatewayTimeout + 10);
        $lockKey = 'whatsapp-otp:issue:'.hash('sha256', implode('|', [
            (string) $user->getKey(),
            $number,
            $purpose,
        ]));

        return Cache::lock($lockKey, $lockSeconds)->block(10, function () use (
            $user,
            $number,
            $purpose,
            $expiresIn,
        ): array {
            $expiresAt = now()->addSeconds($expiresIn);
            $otp = (string) random_int(100000, 999999);

            $otpRecord = DB::transaction(function () use (
                $user,
                $number,
                $purpose,
                $otp,
                $expiresAt,
            ): WhatsappOtp {
                WhatsappOtp::query()
                    ->where('whatsapp_number', $number)
                    ->where('purpose', $purpose)
                    ->whereNull('verified_at')
                    ->update(['expires_at' => now()]);

                return WhatsappOtp::query()->create([
                    'user_id' => $user->id,
                    'whatsapp_number' => $number,
                    'purpose' => $purpose,
                    'otp_hash' => Hash::make($otp),
                    'expires_at' => $expiresAt,
                    'attempt_count' => 0,
                ]);
            });

            try {
                $this->whatsApp->sendOtp($number, $otp, (int) $otpRecord->id);
            } catch (Throwable $exception) {
                $otpRecord->forceFill(['expires_at' => now()])->save();

                Log::warning('Gagal mengirim OTP WhatsApp', [
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'whatsapp_last4' => substr($number, -4),
                    'exception' => $exception::class,
                ]);

                throw new RuntimeException('Gagal mengirim OTP WhatsApp.', previous: $exception);
            }

            return [
                'otp_record' => $otpRecord,
                'expires_in' => $expiresIn,
            ];
        });
    }

    public function verify(User $user, string $number, string $purpose, string $otp): ?WhatsappOtp
    {
        return DB::transaction(function () use ($user, $number, $purpose, $otp): ?WhatsappOtp {
            $otpRecord = WhatsappOtp::query()
                ->where('whatsapp_number', $number)
                ->where('purpose', $purpose)
                ->where('user_id', $user->id)
                ->whereNull('verified_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $expiresAt = $otpRecord?->getAttribute('expires_at');

            if (
                ! $otpRecord
                || ! $expiresAt instanceof CarbonInterface
                || $expiresAt->isPast()
                || $otpRecord->attempt_count >= self::MAX_ATTEMPTS
            ) {
                return null;
            }

            $currentAttempts = (int) $otpRecord->attempt_count;

            if (! Hash::check($otp, $otpRecord->otp_hash)) {
                $attempts = $currentAttempts + 1;
                $payload = ['attempt_count' => $attempts];

                if ($attempts >= self::MAX_ATTEMPTS) {
                    $payload['expires_at'] = now();
                }

                WhatsappOtp::query()
                    ->whereKey($otpRecord->getKey())
                    ->whereNull('verified_at')
                    ->where('attempt_count', $currentAttempts)
                    ->update($payload);

                return null;
            }

            $verifiedAt = now();
            $consumed = WhatsappOtp::query()
                ->whereKey($otpRecord->getKey())
                ->whereNull('verified_at')
                ->where('attempt_count', $currentAttempts)
                ->update(['verified_at' => $verifiedAt]);

            if ($consumed !== 1) {
                return null;
            }

            return $otpRecord->fresh();
        });
    }
}
