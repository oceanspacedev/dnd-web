<?php

namespace App\Support;

class WhatsAppNumber
{
    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || preg_match('/^\+?[0-9\s().-]+$/', $value) !== 1) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '0062')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '62'.substr($digits, 1);
        }

        if (str_starts_with($digits, '620')) {
            return '62'.substr($digits, 3);
        }

        if (str_starts_with($digits, '8')) {
            return '62'.$digits;
        }

        return $digits;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^628\d{8,12}$/', $value) === 1;
    }

    public static function toLocal(string $value): ?string
    {
        $number = self::normalize($value);

        if (! self::isValid($number)) {
            return null;
        }

        return '0'.substr($number, 2);
    }

    public static function mask(string $value): string
    {
        $length = strlen($value);

        if ($length <= 7) {
            return '+'.$value;
        }

        return '+'.substr($value, 0, 5).str_repeat('*', max(4, $length - 7)).substr($value, -2);
    }
}
