<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Periode bulanan 'Y-m' (mis. '2026-09') yang dipakai attendances, employee_reviews, cutpoints,
 * dan aturan penilaian kinerja (scoring_rules.effective_from).
 *
 * String 'YYYY-MM' yang sudah dinormalisasi bisa dibandingkan secara leksikal (strcmp) sesuai urutan waktu.
 */
final class Periode
{
    private const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/D';

    private const MONTH_NAMES = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private const LOOSE_PATTERN = '/^(?<year>\d{4})-(?<month>\d{1,2})(?:-(?<day>\d{1,2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?<zone>Z|[+-]\d{2}(?::?\d{2})?)?)?)?$/D';

    /**
     * Normalisasi ke 'Y-m' dengan bulan dua digit. Menerima 'Y-m', 'Y-n', 'Y-m-d' (opsional dengan jam,
     * mis. format ISO-8601 / serialisasi tanggal Laravel '...T03:00:00.000000Z') dan DateTimeInterface;
     * selain itu (termasuk bulan/tanggal tidak valid) mengembalikan null.
     *
     * Nilai yang membawa zona waktu (sufiks 'Z' atau '+07:00', dan DateTimeInterface) dikonversi dulu ke
     * zona waktu aplikasi, sehingga '2026-10-31T17:00:00Z' (= 1 November 00:00 WIB) menjadi '2026-11'.
     * Tanggal-jam tanpa zona dianggap sudah dalam zona waktu aplikasi.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(self::timezone())->format('Y-m');
        }

        if (! is_string($value) || ! preg_match(self::LOOSE_PATTERN, trim($value), $matches)) {
            return null;
        }

        $year = (int) $matches['year'];
        $month = (int) $matches['month'];

        if ($month < 1 || $month > 12) {
            return null;
        }

        if (isset($matches['day']) && $matches['day'] !== '' && ! checkdate($month, (int) $matches['day'], $year)) {
            return null;
        }

        if (isset($matches['zone']) && $matches['zone'] !== '') {
            try {
                return CarbonImmutable::parse(trim($value))->setTimezone(self::timezone())->format('Y-m');
            } catch (Throwable) {
                return null;
            }
        }

        return sprintf('%04d-%02d', $year, $month);
    }

    /**
     * Format ketat 'Y-m' (bulan dua digit 01-12).
     */
    public static function isValid(string $periode): bool
    {
        return preg_match(self::PATTERN, $periode) === 1;
    }

    public static function current(): string
    {
        return Date::now()->format('Y-m');
    }

    /**
     * Bulan sebelum current(): periode yang biasanya direkap/diisi (data kehadiran bulan lalu).
     */
    public static function previous(): string
    {
        return Date::now()->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * Periode bulan berjalan atau sesudahnya (bulannya belum selesai). Nilai yang tidak terbaca = false.
     */
    public static function isCurrentOrLater(mixed $periode): bool
    {
        $normalized = self::normalize($periode);

        return $normalized !== null && strcmp($normalized, self::current()) >= 0;
    }

    /**
     * Nama bulan dan tahun berbahasa Indonesia, mis. '2026-10' => 'Oktober 2026'. Nilai yang tidak terbaca
     * dikembalikan apa adanya.
     */
    public static function label(mixed $periode): string
    {
        $normalized = self::normalize($periode);

        if ($normalized === null) {
            return is_scalar($periode) ? (string) $periode : '';
        }

        return self::MONTH_NAMES[(int) substr($normalized, 5, 2) - 1].' '.substr($normalized, 0, 4);
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone', 'UTC');
    }
}
