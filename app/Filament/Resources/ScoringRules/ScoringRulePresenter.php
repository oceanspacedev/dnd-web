<?php

namespace App\Filament\Resources\ScoringRules;

use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\ScoringRuleData;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tampilan versi aturan penilaian (status, label bulan, ringkasan komponen) yang dipakai bersama oleh
 * panel (Aturan Penilaian) dan API master (master/scoring-rules).
 *
 * Status versi terhadap bulan berjalan:
 * - Terjadwal : effective_from setelah bulan berjalan (masih bisa diubah/dihapus);
 * - Berlaku   : versi yang dipakai bulan berjalan;
 * - Riwayat   : versi lama yang sudah digantikan versi berikutnya (terkunci).
 */
final class ScoringRulePresenter
{
    public const STATUS_HISTORY = 'riwayat';

    public const STATUS_CURRENT = 'berlaku';

    public const STATUS_SCHEDULED = 'terjadwal';

    public static function status(string $effectiveFrom): string
    {
        $current = Periode::current();

        if (strcmp($effectiveFrom, $current) > 0) {
            return self::STATUS_SCHEDULED;
        }

        return ScoringRuleService::forPeriode($current)->effectiveFrom === $effectiveFrom
            ? self::STATUS_CURRENT
            : self::STATUS_HISTORY;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_CURRENT => 'Berlaku',
            self::STATUS_SCHEDULED => 'Terjadwal',
            default => 'Riwayat',
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_CURRENT => 'success',
            self::STATUS_SCHEDULED => 'info',
            default => 'gray',
        };
    }

    /**
     * Sama dengan ScoringRule::isLocked() untuk versi yang tersimpan: sudah dipakai bulan yang telah berakhir.
     */
    public static function isLocked(string $effectiveFrom): bool
    {
        return Periode::isValid($effectiveFrom) && strcmp($effectiveFrom, Periode::current()) < 0;
    }

    /**
     * '2026-11' => 'November 2026' (nama bulan mengikuti locale aplikasi).
     */
    public static function periodeLabel(?string $periode): string
    {
        if (! is_string($periode) || ! Periode::isValid($periode)) {
            return (string) $periode;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m', $periode)->locale(app()->getLocale())->isoFormat('MMMM YYYY');
        } catch (Throwable) {
            return $periode;
        }
    }

    /**
     * Bulan sesudah periode 'Y-m'.
     */
    public static function nextPeriode(string $periode): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $periode)->addMonthNoOverflow()->format('Y-m');
    }

    /**
     * Bulan sebelum periode 'Y-m'.
     */
    public static function previousPeriode(string $periode): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $periode)->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * Angka gaya Indonesia tanpa nol di belakang koma: 70 => '70', 12.5 => '12,5', 81.8181 => '81,82'.
     */
    public static function number(int|float $value, int $decimals = 2): string
    {
        $formatted = number_format((float) $value, $decimals, ',', '.');

        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * 'KPI 70 · Kehadiran 15 · Penilaian 15'.
     */
    public static function weightsSummary(ScoringRuleData $rule): string
    {
        return 'KPI '.self::number($rule->kpiWeight)
            .' · Kehadiran '.self::number($rule->attendanceWeight)
            .' · Penilaian '.self::number($rule->reviewWeight);
    }

    /**
     * Satu baris per komponen, mis. 'Izin: 5 poin/hari' atau 'Sakit: 0 poin/hari, kurangi rumus dasar'.
     *
     * @return list<string>
     */
    public static function componentSummaries(ScoringRuleData $rule): array
    {
        $lines = [];

        foreach ($rule->componentCodes() as $code) {
            $lines[] = self::componentSummary($rule, $code);
        }

        return $lines;
    }

    /**
     * Ringkasan satu komponen pada versi aturan, mis. 'Mangkir: 5 poin/hari'.
     */
    public static function componentSummary(ScoringRuleData $rule, string $code): string
    {
        $component = ScoringRuleService::component($code);
        $line = ($component->name ?? $code).': '.self::number($rule->penaltyPoints($code))
            .' poin/'.($component->unit ?? 'hari');

        if ($rule->reducesBase($code)) {
            $line .= ', kurangi rumus dasar';
        }

        return $line;
    }
}
