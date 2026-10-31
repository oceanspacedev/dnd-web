<?php

namespace App\Support\Scoring;

use App\Services\ScoringRuleService;
use App\Support\Periode;
use Illuminate\Support\Collection;

/**
 * Pemetaan input/output hitungan komponen kehadiran (form panel, import/export Excel, API) ke tempat
 * penyimpanannya: komponen lama ke kolomnya di tabel attendances (late_less_30, late_more_30, sick_days),
 * komponen lain ke attendances.component_counts[kode].
 *
 * component_counts hanya menyimpan hitungan > 0 (null bila semuanya 0), sehingga komponen yang belum pernah
 * diisi tetap bisa dihapus dari master (lihat AttendanceComponent::assertDeletable). Attendance::countFor()
 * membaca kode yang tidak ada sebagai 0.
 */
final class AttendanceCountInput
{
    /** Tahun periode terkecil yang diterima input (menolak data sampah 1900-01 / 1970-01 dari kolom yang bergeser). */
    public const MIN_YEAR = 2000;

    /** Tahun periode terbesar yang diterima input (menolak angka yang terbaca sebagai tanggal Excel yang terlalu jauh). */
    public const MAX_YEAR = 2100;

    /** Hari kerja per bulan paling banyak (form, import, API): angka lebih besar pasti salah kolom/ketik. */
    public const MAX_WORK_DAYS = 31;

    /**
     * Hitungan satu komponen per bulan paling banyak (form, import, API). Menolak angka sampah (mis. NIK yang
     * tertempel di kolom hitungan) sebelum meluap di kolom INT database.
     */
    public const MAX_COUNT = 999;

    /** Kode komponen izin: versi aturan yang belum memakainya mencatat izin bersama sakit di kolom sick_days. */
    public const IZIN_CODE = 'izin';

    /** Kode komponen lama yang kolomnya berarti "Sakit/Izin" pada versi aturan tanpa komponen izin. */
    public const SICK_CODE = 'sick_days';

    /** Nama kolom sick_days pada periode yang izinnya dicatat bersama sakit (form, judul template). */
    public const SICK_IZIN_LABEL = 'Sakit/Izin';

    /** Keterangan isian sick_days pada periode yang izinnya dicatat bersama sakit. */
    public const SICK_IZIN_HELPER = 'Untuk periode ini izin dicatat bersama sakit.';

    /** Hint isian komponen yang tidak tercantum di versi aturan periode form. */
    public const UNUSED_COMPONENT_HINT = 'Tidak dipakai di aturan periode ini';

    /** Keterangan isian Izin pada periode yang izinnya dicatat bersama sakit. */
    public const IZIN_IN_SICK_HELPER = 'Untuk periode ini isi izin di Sakit/Izin.';

    /** Keterangan isian komponen yang belum tercantum di versi aturan penilaian mana pun. */
    public const UNLISTED_COMPONENT_HELPER = 'Komponen ini belum dicantumkan di versi aturan penilaian mana pun, jadi belum bisa diisi. Minta super admin mencantumkannya di aturan penilaian (penalti 0 boleh bila hanya dicatat).';

    /** Konteks pesan komponen yang tidak dipakai aturan: kolom file import. */
    public const CONTEXT_IMPORT = 'import';

    /** Konteks pesan komponen yang tidak dipakai aturan: objek components API. */
    public const CONTEXT_API = 'api';

    /** Konteks pesan komponen yang tidak dipakai aturan: isian form Kehadiran. */
    public const CONTEXT_FORM = 'form';

    /** Pola judul kolom komponen di template/export: "Nama [kode]" (lihat spreadsheetHeading()). */
    private const HEADING_CODE_PATTERN = '/\[\s*([A-Za-z][A-Za-z0-9_]*)\s*\]\s*$/u';

    /**
     * Komponen aktif, urut sort_order.
     *
     * @return Collection<string, AttendanceComponentData>
     */
    public static function activeComponents(): Collection
    {
        return ScoringRuleService::components()
            ->filter(static fn (AttendanceComponentData $component): bool => $component->isActive);
    }

    /**
     * Komponen yang bisa diisi (field form, kolom tabel panel, kolom template import): komponen aktif ditambah
     * komponen nonaktif yang masih memengaruhi skor di salah satu versi aturan, karena data periode versi itu
     * tetap perlu diisi/dikoreksi. Urut sort_order.
     *
     * @return Collection<string, AttendanceComponentData>
     */
    public static function inputComponents(): Collection
    {
        $scoredCodes = self::scoredComponentCodes();

        return ScoringRuleService::components()
            ->filter(static fn (AttendanceComponentData $component): bool => $component->isActive || isset($scoredCodes[$component->code]));
    }

    /**
     * Kode komponen yang memengaruhi skor (penalti > 0 atau mengurangi rumus dasar) minimal di satu versi aturan.
     *
     * @return array<string, true>
     */
    public static function scoredComponentCodes(): array
    {
        $codes = [];

        foreach (ScoringRuleService::rules() as $rule) {
            foreach ($rule->componentCodes() as $code) {
                if ($rule->penaltyPoints($code) > 0 || $rule->reducesBase($code)) {
                    $codes[$code] = true;
                }
            }
        }

        return $codes;
    }

    /**
     * Kode komponen yang tercantum di attendance_rules minimal satu versi aturan (penalti 0 ikut, mis. cuti yang
     * hanya dicatat): kolomnya di tabel panel tampil secara default, karena memang bagian dari aturan penilaian.
     *
     * @return array<string, true>
     */
    public static function listedComponentCodes(): array
    {
        $codes = [];

        foreach (ScoringRuleService::rules() as $rule) {
            foreach ($rule->componentCodes() as $code) {
                $codes[$code] = true;
            }
        }

        return $codes;
    }

    /**
     * Komponen kolom template import: tiga komponen lama selalu di kolom 5-7 (urutan template lama, walaupun
     * komponennya dinonaktifkan), lalu komponen lain dari inputComponents() urut sort_order. Dengan $rule (versi
     * aturan periode template), komponen lain hanya yang dipakai versi itu (isUsedBy()).
     *
     * @return Collection<string, AttendanceComponentData>
     */
    public static function spreadsheetComponents(?ScoringRuleData $rule = null): Collection
    {
        $components = self::inputComponents();

        if ($rule !== null) {
            $components = $components->filter(static fn (AttendanceComponentData $component): bool => self::isUsedBy($component->code, $rule));
        }

        return self::legacyFirst($components);
    }

    /**
     * Versi aturan periode input; null bila periode tidak terbaca atau di luar rentang (perlakuan per periode
     * tidak bisa ditentukan, sehingga semua komponen dibiarkan bisa diisi dan validasi periode yang menolak).
     */
    public static function ruleFor(mixed $periode): ?ScoringRuleData
    {
        $normalized = self::periode($periode);

        return $normalized === null ? null : ScoringRuleService::forPeriode($normalized);
    }

    /**
     * Komponen dipakai versi aturan (boleh diisi untuk data periode versi itu): tercantum di attendance_rules
     * versi itu, walaupun penaltinya 0. Tiga komponen lama (kolom 5-7 template, field lama API) selalu boleh
     * diisi. Dipakai bersama oleh form, template, import, dan API.
     */
    public static function isUsedBy(string $code, ScoringRuleData $rule): bool
    {
        return AttendanceComponentData::legacyColumnFor($code) !== null || $rule->hasComponent($code);
    }

    /**
     * Kode komponen dengan hitungan > 0 yang tidak dipakai versi aturan $rule (urutan $counts). Kode yang tidak ada
     * di master dilewati: tidak bisa dicantumkan di aturan mana pun dan tidak bisa dikirim ulang sebagai 0.
     *
     * @param  array<string, int|float|string|null>  $counts  kode => hitungan
     * @return list<string>
     */
    public static function unusedCodes(array $counts, ScoringRuleData $rule): array
    {
        $codes = [];

        foreach ($counts as $code => $count) {
            $code = (string) $code;

            if (is_numeric($count) && (int) $count > 0 && ScoringRuleService::component($code) !== null && ! self::isUsedBy($code, $rule)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Versi aturan belum memakai komponen izin: izin dicatat bersama sakit di kolom sick_days (Sakit/Izin).
     */
    public static function recordsIzinInSick(ScoringRuleData $rule): bool
    {
        return ! $rule->hasComponent(self::IZIN_CODE);
    }

    /**
     * Periode data dipindah dari versi aturan yang mencatat izin bersama sakit (sick_days = Sakit/Izin, izin ikut
     * dipenalti) ke versi yang mencatat izin sendiri (sick_days = sakit saja), mis. Oktober -> November 2026.
     * sick_days tersimpan bisa berisi hari izin yang akan kehilangan penaltinya bila tidak dipisah ke komponen
     * Izin. Periode tujuan yang belum valid = false (validasi periode yang menolak).
     */
    public static function movesIzinOutOfSick(mixed $fromPeriode, mixed $toPeriode): bool
    {
        $from = Periode::normalize($fromPeriode);
        $to = self::periode($toPeriode);

        if ($from === null || $to === null || $from === $to) {
            return false;
        }

        return self::recordsIzinInSick(ScoringRuleService::forPeriode($from))
            && ! self::recordsIzinInSick(ScoringRuleService::forPeriode($to));
    }

    /**
     * Alasan penolakan memindah data yang menyimpan Sakit/Izin > 0 ke periode yang mencatat izin sendiri
     * (movesIzinOutOfSick()), dengan langkah memisahkannya:
     * - api: kirim sick_days (sakit saja) dan components.izin bersama periode baru.
     * - form: isi Sakit dan Izin, lalu centang konfirmasi pemisahan.
     */
    public static function sickIzinSplitMessage(int $sickDays, string $fromPeriode, string $toPeriode, string $context = self::CONTEXT_API): string
    {
        $sick = self::componentName(self::SICK_CODE);
        $izin = self::componentName(self::IZIN_CODE);
        $lead = 'Data ini menyimpan '.self::SICK_IZIN_LABEL." {$sickDays} hari (sakit + izin) menurut aturan penilaian "
            .Periode::label($fromPeriode).'. Aturan penilaian '.Periode::label($toPeriode).' mencatat izin terpisah dari sakit';

        $step = $context === self::CONTEXT_FORM
            ? ": isi {$sick} dengan hari sakit saja dan {$izin} dengan hari izin, lalu centang konfirmasi ini."
            : ': kirim sick_days (sakit saja) dan components.'.self::IZIN_CODE.' (jumlah izin, 0 bila tidak ada) bersama periode baru.';

        return $lead.$step.' Bila periodenya yang keliru, perbaiki periodenya.';
    }

    /**
     * Nama komponen untuk periode versi aturan $rule: kolom sick_days bernama "Sakit/Izin" bila izin dicatat
     * bersama sakit; selain itu nama komponen di master. Tanpa versi aturan (periode belum valid) = nama master.
     */
    public static function componentLabel(AttendanceComponentData $component, ?ScoringRuleData $rule): string
    {
        if ($component->code === self::SICK_CODE && $rule !== null && self::recordsIzinInSick($rule)) {
            return self::SICK_IZIN_LABEL;
        }

        return $component->name;
    }

    /**
     * Keterangan isian komponen untuk periode versi aturan $rule: sick_days pada periode yang izinnya dicatat
     * bersama sakit memakai SICK_IZIN_HELPER; selain itu deskripsi komponen di master, ditambah alasan isian
     * nonaktif: Izin pada periode itu menunjuk ke Sakit/Izin (IZIN_IN_SICK_HELPER), dan komponen yang belum
     * tercantum di versi aturan mana pun (selalu nonaktif di form) memakai UNLISTED_COMPONENT_HELPER.
     */
    public static function componentHelperText(AttendanceComponentData $component, ?ScoringRuleData $rule): ?string
    {
        $note = null;

        if ($rule !== null && self::recordsIzinInSick($rule)) {
            if ($component->code === self::SICK_CODE) {
                return self::SICK_IZIN_HELPER;
            }

            if ($component->code === self::IZIN_CODE) {
                $note = self::IZIN_IN_SICK_HELPER;
            }
        }

        if ($note === null && $rule !== null && ! self::isUsedBy($component->code, $rule) && ! self::isListedInAnyRule($component->code)) {
            $note = self::UNLISTED_COMPONENT_HELPER;
        }

        if ($note === null) {
            return $component->description;
        }

        return filled($component->description) ? rtrim((string) $component->description).' '.$note : $note;
    }

    /**
     * Komponen tercantum di minimal satu versi aturan (walau penaltinya 0). Komponen yang tidak tercantum di versi
     * mana pun belum bisa diisi untuk periode apa pun.
     */
    public static function isListedInAnyRule(string $code): bool
    {
        foreach (ScoringRuleService::rules() as $rule) {
            if ($rule->hasComponent($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kalimat pembuka pesan komponen yang tidak dipakai versi aturan periodenya, mis. "Aturan penilaian Oktober 2026
     * belum memakai Izin dan Mangkir (tanpa keterangan)." ("belum" bila versi sesudahnya memakai komponen itu,
     * mis. izin sejak November 2026; selain itu "tidak").
     *
     * @param  list<string>  $codes
     */
    public static function unusedComponentsSentence(array $codes, string $periode): string
    {
        $notYet = [];
        $never = [];

        foreach ($codes as $code) {
            if (self::isUsedAfter($code, $periode)) {
                $notYet[] = self::componentName($code);
            } else {
                $never[] = self::componentName($code);
            }
        }

        $clauses = [];

        if ($notYet !== []) {
            $clauses[] = 'belum memakai '.self::joinList($notYet);
        }

        if ($never !== []) {
            $clauses[] = 'tidak memakai '.self::joinList($never);
        }

        return 'Aturan penilaian '.Periode::label($periode).' '.implode(' dan ', $clauses).'.';
    }

    /**
     * Alasan penolakan hitungan > 0 untuk komponen yang tidak dipakai versi aturan periodenya (satu kalimat untuk
     * semua kolom yang bermasalah), dengan langkah perbaikannya. Izin pada periode yang izinnya dicatat bersama
     * sakit diarahkan ke Sakit/Izin (jumlahkan dulu, baru kosongkan), agar hari izinnya tidak hilang:
     * - import: "Aturan penilaian Oktober 2026 belum memakai Izin, tetapi kolomnya diisi. Untuk periode ini izin
     *   dicatat di kolom Sakit/Izin: jumlahkan angka Izin ke kolom Sakit/Izin, lalu kosongkan kolom Izin. ..."
     * - api: objek components (per kunci components.{kode}); dengan $storedCounts = hitungan tersimpan yang tidak
     *   dikirim saat periode data diganti.
     * - form: isian komponen di form Kehadiran.
     *
     * @param  list<string>  $codes
     * @param  array<string, int>|null  $storedCounts  kode => hitungan tersimpan (data yang periodenya diganti)
     */
    public static function unusedComponentsMessage(array $codes, string $periode, string $context = self::CONTEXT_IMPORT, ?array $storedCounts = null): string
    {
        $lead = rtrim(self::unusedComponentsSentence($codes, $periode), '.');

        if ($storedCounts !== null) {
            $stored = [];

            foreach ($codes as $code) {
                $stored[] = self::componentName($code).' '.($storedCounts[$code] ?? 0).' '.(ScoringRuleService::component($code)->unit ?? 'hari');
            }

            $lead .= ', tetapi data ini menyimpan '.self::joinList($stored).'.';
        } else {
            $lead .= match ($context) {
                self::CONTEXT_API => ', tetapi nilainya lebih dari 0.',
                self::CONTEXT_FORM => ', tetapi isian ini lebih dari 0.',
                default => ', tetapi kolomnya diisi.',
            };
        }

        $others = array_values(array_filter($codes, static fn (string $code): bool => $code !== self::IZIN_CODE));
        $steps = [];

        if (in_array(self::IZIN_CODE, $codes, true)) {
            $izin = self::componentName(self::IZIN_CODE);
            $steps[] = match ($context) {
                self::CONTEXT_API => 'Untuk periode ini izin dicatat di sick_days ('.self::SICK_IZIN_LABEL.'): tambahkan jumlah izin ke sick_days dan kirim components.'.self::IZIN_CODE.' 0.',
                self::CONTEXT_FORM => 'Untuk periode ini izin dicatat di '.self::SICK_IZIN_LABEL.': jumlahkan ke '.self::SICK_IZIN_LABEL.', lalu isi 0 di sini.',
                default => 'Untuk periode ini izin dicatat di kolom '.self::SICK_IZIN_LABEL.": jumlahkan angka {$izin} ke kolom ".self::SICK_IZIN_LABEL.", lalu kosongkan kolom {$izin}.",
            };
        }

        if ($others !== []) {
            $steps[] = match ($context) {
                self::CONTEXT_API => 'Kirim 0 untuk '.self::joinList(array_map(static fn (string $code): string => "components.{$code}", $others)).'.',
                self::CONTEXT_FORM => 'Isi 0 di sini.',
                default => 'Kosongkan kolom '.self::joinList(array_map(self::componentName(...), $others)).'.',
            };
        }

        $steps[] = 'Bila periodenya yang keliru, perbaiki periodenya.';

        return $lead.' '.implode(' ', $steps);
    }

    /**
     * Ada versi aturan yang berlaku sesudah periode ini dan memakai komponen tersebut.
     */
    private static function isUsedAfter(string $code, string $periode): bool
    {
        foreach (ScoringRuleService::rules() as $rule) {
            if (strcmp($rule->effectiveFrom, $periode) > 0 && $rule->hasComponent($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nama komponen di master (kode bila tidak ada di master).
     */
    public static function componentName(string $code): string
    {
        return ScoringRuleService::component($code)->name ?? $code;
    }

    /**
     * "A", "A dan B", "A, B, dan C".
     *
     * @param  list<string>  $items
     */
    public static function joinList(array $items): string
    {
        if (count($items) <= 2) {
            return implode(' dan ', $items);
        }

        $last = array_pop($items);

        return implode(', ', $items).', dan '.$last;
    }

    /**
     * Komponen kolom export: seperti template, ditambah semua komponen lain di master (termasuk yang nonaktif)
     * agar hitungan yang sudah tersimpan tidak hilang dari file. Bila file diimport ulang, baris kembali utuh,
     * kecuali baris yang menyimpan hitungan > 0 untuk komponen yang tidak dipakai versi aturan periodenya (mis. Izin
     * sebelum November 2026, atau komponen yang belum tercantum di aturan mana pun): import melewati baris itu
     * dengan alasannya (lihat unusedComponentsMessage()), sama seperti saat diisi lewat template.
     *
     * @return Collection<string, AttendanceComponentData>
     */
    public static function exportComponents(): Collection
    {
        return self::legacyFirst(ScoringRuleService::components());
    }

    /**
     * Judul kolom komponen baru (kolom ke-8 dst.) di template/export: nama dan kode tetap, mis. "Izin [izin]".
     * Import mencocokkan kodenya, sehingga nama komponen boleh diubah tanpa membuat file lama terbaca 0.
     */
    public static function spreadsheetHeading(AttendanceComponentData $component): string
    {
        return "{$component->name} [{$component->code}]";
    }

    /**
     * Kode komponen dari judul berpola "Nama [kode]" (huruf kecil); null bila judul tidak berpola itu.
     */
    public static function codeFromHeading(mixed $heading): ?string
    {
        if (! is_string($heading) || preg_match(self::HEADING_CODE_PATTERN, trim($heading), $matches) !== 1) {
            return null;
        }

        return mb_strtolower($matches[1]);
    }

    /**
     * Tiga komponen lama (kolom 5-7 file Excel) di depan, lalu komponen lain dari $components.
     *
     * @param  Collection<string, AttendanceComponentData>  $components
     * @return Collection<string, AttendanceComponentData>
     */
    private static function legacyFirst(Collection $components): Collection
    {
        $defaults = AttendanceComponentData::legacyDefaults();
        $ordered = collect();

        foreach (array_keys(AttendanceComponentData::LEGACY_COLUMN_BY_CODE) as $code) {
            $ordered->put($code, ScoringRuleService::component($code) ?? $defaults[$code]);
        }

        foreach ($components as $code => $component) {
            if (! $ordered->has($code)) {
                $ordered->put($code, $component);
            }
        }

        return $ordered;
    }

    /**
     * Kolom attendances untuk kode komponen; null = disimpan di component_counts. Sama dengan Attendance::countFor().
     */
    public static function storageColumn(string $code): ?string
    {
        return AttendanceComponentData::legacyColumnFor($code)
            ?? ScoringRuleService::component($code)?->legacyColumn;
    }

    /**
     * Bentuk simpan component_counts: hanya kode yang tidak punya kolom sendiri dan hitungan > 0; null bila kosong.
     *
     * @return array<string, int>|null
     */
    public static function normalizeComponentCounts(mixed $counts): ?array
    {
        if (! is_array($counts)) {
            return null;
        }

        $normalized = [];

        foreach ($counts as $code => $count) {
            if (! is_string($code) || $code === '' || self::storageColumn($code) !== null || ! is_numeric($count)) {
                continue;
            }

            if ((int) $count > 0) {
                $normalized[$code] = (int) $count;
            }
        }

        ksort($normalized);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Atribut Attendance dari hitungan per kode komponen. Komponen lama mengisi kolomnya; komponen lain
     * digabung ke component_counts yang sudah ada (kode lain tidak tersentuh). component_counts hanya ikut
     * diisi bila ada komponen non-kolom pada $counts.
     *
     * @param  array<string, int|float|string|null>  $counts
     * @return array<string, mixed>
     */
    public static function attributes(array $counts, mixed $currentComponentCounts = null): array
    {
        $attributes = [];
        $json = is_array($currentComponentCounts) ? $currentComponentCounts : [];
        $touchesJson = false;

        foreach ($counts as $code => $count) {
            $count = (int) ($count ?? 0);
            $column = self::storageColumn((string) $code);

            if ($column !== null) {
                $attributes[$column] = $count;

                continue;
            }

            $json[(string) $code] = $count;
            $touchesJson = true;
        }

        if ($touchesJson) {
            $attributes['component_counts'] = self::normalizeComponentCounts($json);
        }

        return $attributes;
    }

    /**
     * Periode 'Y-m' yang sudah dinormalisasi dan berada di rentang MIN_YEAR..MAX_YEAR; null bila tidak valid.
     */
    public static function periode(mixed $value): ?string
    {
        $periode = Periode::normalize($value);

        return $periode !== null && self::isPeriodeInRange($periode) ? $periode : null;
    }

    public static function isPeriodeInRange(string $periode): bool
    {
        $year = (int) substr($periode, 0, 4);

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    /**
     * Pesan bila periode tidak bisa dibaca atau di luar rentang; null bila valid.
     */
    public static function periodeError(mixed $value): ?string
    {
        $periode = Periode::normalize($value);

        if ($periode === null) {
            return 'Periode harus berformat tahun-bulan YYYY-MM (mis. 2026-11).';
        }

        if (! self::isPeriodeInRange($periode)) {
            return 'Periode '.$periode.' di luar rentang yang diizinkan (tahun '.self::MIN_YEAR.' sampai '.self::MAX_YEAR.').';
        }

        return null;
    }

    /**
     * Ringkasan perlakuan komponen pada versi aturan, mis. "Penalti 5 poin/hari" atau "Tidak dihitung".
     */
    public static function ruleSummary(AttendanceComponentData $component, ScoringRuleData $rule): string
    {
        if (! $rule->hasComponent($component->code)) {
            return 'Tidak dihitung';
        }

        $penalty = $rule->penaltyPoints($component->code);
        $parts = [$penalty > 0
            ? 'Penalti '.self::formatNumber($penalty).' poin/'.$component->unit
            : 'Tanpa penalti'];

        if ($rule->reducesBase($component->code)) {
            $parts[] = 'mengurangi hari kerja';
        }

        return implode(', ', $parts);
    }

    public static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
