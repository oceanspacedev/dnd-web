<?php

namespace App\Filament\Pages;

use App\Exports\AttendanceImportTemplateExport;
use App\Filament\Resources\AttendanceComponents\AttendanceComponentResource;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Filament\Resources\ScoringRules\ScoringRulePresenter;
use App\Filament\Resources\ScoringRules\ScoringRuleResource;
use App\Models\Area;
use App\Models\User;
use App\Policies\AttendanceComponentPolicy;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\AttendanceCountInput;
use App\Support\Scoring\ScoringRuleData;
use Filament\Pages\Page;

/**
 * Panduan aturan penilaian kinerja (nilai akhir = KPI + Kehadiran + Penilaian) untuk semua user panel.
 *
 * Isi aturan (versi berlaku, versi terjadwal, daftar versi, contoh hitung, kolom template import) dibaca
 * langsung dari ScoringRuleService dan dihitung dengan PerformanceScoreCalculator, sehingga panduan selalu
 * sama dengan aturan yang tersimpan. Bagian input/import Kehadiran hanya tampil bagi yang bisa membuka menu
 * Kehadiran; bagian pengaturan Aturan Penilaian & Komponen Kehadiran hanya bagi super admin. Catatan internal
 * versi aturan (notes) tidak pernah ditampilkan.
 *
 * Semua data disiapkan di getViewData() (protected), bukan method publik, agar tidak bisa dipanggil dari
 * browser lewat Livewire.
 */
class ScoringRulesGuide extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Panduan Aturan Penilaian';

    protected static string|\UnitEnum|null $navigationGroup = 'Panduan';

    protected static ?int $navigationSort = 84;

    protected static ?string $slug = 'panduan-aturan-penilaian';

    protected static ?string $title = 'Panduan Aturan Penilaian Kinerja';

    protected string $view = 'filament.pages.scoring-rules-guide';

    /** Contoh hitung: hari kerja sebulan. */
    public const EXAMPLE_WORK_DAYS = 22;

    /** Contoh hitung: kejadian sebulan per kode komponen (2x telat <= 30 menit, 1x telat > 30 menit, 1 sakit, 1 izin). */
    public const EXAMPLE_COUNTS = [
        'late_less_30' => 2,
        'late_more_30' => 1,
        'sick_days' => 1,
        'izin' => 1,
    ];

    /** Contoh hitung: total poin 4 aspek penilaian. */
    public const EXAMPLE_REVIEW_POINTS = 16;

    /** Contoh hitung: nilai KPI (%). */
    public const EXAMPLE_KPI_RAW = 90;

    private const SICK_CODE = 'sick_days';

    private const IZIN_CODE = 'izin';

    /**
     * Semua user panel dinilai, jadi semua user yang login boleh membaca panduan ini.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $user = $user instanceof User ? $user : null;

        $current = Periode::current();
        // Bulan lalu: periode bawaan form Kehadiran, Download Template, dan Import (Periode::previous()).
        $previous = Periode::previous();
        $currentRule = ScoringRuleService::forPeriode($current);
        $previousRule = ScoringRuleService::forPeriode($previous);
        $nextRule = $this->ruleAfter($current);
        $izinFrom = $this->izinSeparateFrom();

        $canInputAttendance = AttendanceResource::canViewAny();
        $canManageRules = DataAccessService::hasGlobalAccess($user);
        $sick = self::componentData(self::SICK_CODE);

        return [
            'currentPeriode' => $current,
            'currentPeriodeLabel' => ScoringRulePresenter::periodeLabel($current),
            'previousPeriode' => $previous,
            'previousPeriodeLabel' => ScoringRulePresenter::periodeLabel($previous),
            'currentRule' => $this->ruleSummary($currentRule),
            'previousRuleName' => $previousRule->name,
            // Periode bawaan masih mencatat izin bersama sakit (isian/kolom "Sakit/Izin", Izin nonaktif).
            'previousIzinInSick' => AttendanceCountInput::recordsIzinInSick($previousRule),
            'nextRule' => $nextRule !== null ? $this->ruleSummary($nextRule) + ['following' => $this->followingSummary($nextRule)] : null,
            'versions' => $this->versions(),
            // Bulan penanda versi pertama ("sejak awal"), tampil apa adanya di form Kehadiran dan menu Aturan Penilaian.
            'sinceStartPeriode' => ScoringRuleData::legacy()->effectiveFrom,
            'sinceStartPeriodeLabel' => ScoringRulePresenter::periodeLabel(ScoringRuleData::legacy()->effectiveFrom),
            'exampleEvents' => $this->exampleEvents(),
            'example' => $this->example($currentRule),
            'nextExample' => $nextRule !== null ? $this->example($nextRule) : null,
            'izinFromLabel' => $izinFrom !== null ? ScoringRulePresenter::periodeLabel($izinFrom) : null,
            'izinHeading' => $this->izinHeading(),
            'izinName' => self::componentData(self::IZIN_CODE)->name ?? 'Izin',
            'sickName' => $sick->name ?? 'Sakit',
            'formHintExamples' => $this->formHintExamples($sick, $previousRule),
            // Kolom ke-7 template (komponen sakit): "Sakit", atau "Sakit/Izin" untuk periode yang izinnya dicatat
            // bersama sakit. Judulnya tetap walaupun nama komponen diubah.
            'sickHeading' => AttendanceImportTemplateExport::LEGACY_HEADINGS[6],
            'sickIzinLabel' => AttendanceCountInput::SICK_IZIN_LABEL,
            // Tiga kolom komponen lama (kolom 5-7) selalu boleh diisi; judulnya seperti di pesan import.
            'legacyColumnsText' => AttendanceCountInput::joinList(array_slice(AttendanceImportTemplateExport::LEGACY_HEADINGS, AttendanceImportTemplateExport::FIRST_COMPONENT_COLUMN - 1)),
            'unusedHint' => AttendanceCountInput::UNUSED_COMPONENT_HINT,
            'izinUnusedExample' => $canInputAttendance ? $this->izinUnusedExample($izinFrom) : null,
            'templateFileName' => AttendanceImportTemplateExport::fileName($previous),
            'canInputAttendance' => $canInputAttendance,
            'canManageRules' => $canManageRules,
            'isAreaScopedAdmin' => DataAccessService::isAreaScopedAdmin($user),
            'attendanceScope' => $canInputAttendance ? $this->attendanceScope($user) : null,
            'templateColumns' => $canInputAttendance ? $this->templateColumns($previous) : [],
            'maxWorkDays' => AttendanceCountInput::MAX_WORK_DAYS,
            'maxCount' => AttendanceCountInput::MAX_COUNT,
            'minYear' => AttendanceCountInput::MIN_YEAR,
            'maxYear' => AttendanceCountInput::MAX_YEAR,
            'minReviewMaxPoints' => ScoringRuleResource::REVIEW_REACHABLE_POINTS,
            'nextAvailablePeriodeLabel' => $canManageRules ? ScoringRulePresenter::periodeLabel(ScoringRuleResource::nextAvailablePeriode()) : null,
            'componentStatuses' => $canManageRules ? $this->componentStatuses() : [],
            'attendanceUrl' => $canInputAttendance ? AttendanceResource::getUrl('index') : null,
            'scoringRulesUrl' => ScoringRuleResource::canViewAny() ? ScoringRuleResource::getUrl('index') : null,
            'componentsUrl' => AttendanceComponentResource::canViewAny() ? AttendanceComponentResource::getUrl('index') : null,
            'leaderboardUrl' => Dashboard::getUrl(['tab' => 'leaderboard']),
        ];
    }

    /**
     * Versi paling awal yang mulai berlaku sesudah $periode (untuk bulan berjalan: versi terjadwal terdekat);
     * null bila belum ada.
     */
    private function ruleAfter(string $periode): ?ScoringRuleData
    {
        foreach (ScoringRuleService::rules() as $rule) {
            if (strcmp($rule->effectiveFrom, $periode) > 0) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Versi yang menggantikan $rule (bila sudah dijadwalkan juga), agar rentang bulan versi terjadwal tidak
     * ditulis "dan sesudahnya" padahal ada versi lain sesudahnya.
     *
     * @return array{name: string, startLabel: string}|null
     */
    private function followingSummary(ScoringRuleData $rule): ?array
    {
        $following = $this->ruleAfter($rule->effectiveFrom);

        return $following !== null
            ? ['name' => $following->name, 'startLabel' => ScoringRulePresenter::periodeLabel($following->effectiveFrom)]
            : null;
    }

    /**
     * Bulan pertama izin dicatat sebagai komponen sendiri: versi paling awal yang mencantumkan izin (walaupun
     * penaltinya 0), sama dengan form Kehadiran, template, import, dan API (AttendanceCountInput::recordsIzinInSick()).
     * Sebelum bulan itu izin dicatat bersama sakit di isian/kolom "Sakit/Izin". null bila belum ada versi yang
     * mencantumkannya.
     */
    private function izinSeparateFrom(): ?string
    {
        foreach (ScoringRuleService::rules() as $rule) {
            if (! AttendanceCountInput::recordsIzinInSick($rule)) {
                return $rule->effectiveFrom;
            }
        }

        return null;
    }

    /**
     * Contoh alasan baris import dilewati karena kolom Izin diisi untuk periode yang izinnya masih dicatat bersama
     * sakit (bulan sebelum $izinFrom), persis seperti pesan import; null bila tidak ada periode seperti itu.
     */
    private function izinUnusedExample(?string $izinFrom): ?string
    {
        if ($izinFrom === null || ScoringRuleService::component(self::IZIN_CODE) === null) {
            return null;
        }

        $periode = ScoringRulePresenter::previousPeriode($izinFrom);

        if (! AttendanceCountInput::isPeriodeInRange($periode)
            || ! AttendanceCountInput::recordsIzinInSick(ScoringRuleService::forPeriode($periode))) {
            return null;
        }

        return AttendanceCountInput::unusedComponentsMessage([self::IZIN_CODE], $periode);
    }

    /**
     * Contoh tulisan kecil di kanan atas isian komponen yang bisa diisi pada form Kehadiran
     * (hint AttendanceCountInput::ruleSummary): isian Sakit untuk periode bawaan form (bulan lalu), lalu bentuk
     * lain yang mungkin muncul. Isian yang tidak dipakai aturan dijelaskan terpisah (UNUSED_COMPONENT_HINT).
     */
    private function formHintExamples(?AttendanceComponentData $sick, ScoringRuleData $defaultRule): string
    {
        $examples = ['Tanpa penalti'];

        if ($sick !== null) {
            $sickHint = AttendanceCountInput::ruleSummary($sick, $defaultRule);

            if (! in_array($sickHint, $examples, true)) {
                array_unshift($examples, $sickHint);
            }
        }

        $quoted = array_map(static fn (string $example): string => '"'.$example.'"', $examples);
        $last = array_pop($quoted);

        return implode(', ', $quoted).', atau '.$last;
    }

    /**
     * Versi pertama yang tersimpan dengan bulan penanda "sejak awal" (Januari 2000, sama dengan
     * ScoringRuleData::legacy()): dipakai untuk semua bulan sebelum ada versi lain, bukan benar-benar mulai 2000.
     */
    private static function isSinceStart(ScoringRuleData $rule): bool
    {
        return strcmp($rule->effectiveFrom, ScoringRuleData::legacy()->effectiveFrom) <= 0;
    }

    private static function startLabel(ScoringRuleData $rule): string
    {
        return self::isSinceStart($rule) ? 'Sejak awal' : ScoringRulePresenter::periodeLabel($rule->effectiveFrom);
    }

    /**
     * Judul kolom izin di template import, mis. 'Izin [izin]'; null bila komponen izin tidak ada di master.
     */
    private function izinHeading(): ?string
    {
        $izin = ScoringRuleService::component(self::IZIN_CODE);

        return $izin !== null ? AttendanceCountInput::spreadsheetHeading($izin) : null;
    }

    private static function componentData(string $code): ?AttendanceComponentData
    {
        return ScoringRuleService::component($code) ?? (AttendanceComponentData::legacyDefaults()[$code] ?? null);
    }

    /**
     * Ringkasan satu versi: nama, bulan mulai, bobot, dan perlakuan tiap komponen (komponen versi itu, ditambah
     * komponen aktif lain yang tidak dinilai versi itu).
     *
     * @return array<string, mixed>
     */
    private function ruleSummary(ScoringRuleData $rule): array
    {
        $rows = [];
        $listed = [];

        foreach (ScoringRuleService::components() as $code => $component) {
            if (! $rule->hasComponent($code) && ! $component->isActive) {
                continue;
            }

            $rows[] = $this->componentRow($rule, $code, $component);
            $listed[$code] = true;
        }

        foreach ($rule->componentCodes() as $code) {
            if (! isset($listed[$code])) {
                $rows[] = $this->componentRow($rule, $code, self::componentData($code));
            }
        }

        return [
            'name' => $rule->name,
            'startLabel' => self::startLabel($rule),
            'sinceStart' => self::isSinceStart($rule),
            'weights' => [
                'kpi' => ScoringRulePresenter::number($rule->kpiWeight),
                'attendance' => ScoringRulePresenter::number($rule->attendanceWeight),
                'review' => ScoringRulePresenter::number($rule->reviewWeight),
            ],
            'weightsText' => ScoringRulePresenter::weightsSummary($rule),
            'reviewMaxPoints' => $rule->reviewMaxPoints,
            'components' => $rows,
            'summaries' => ScoringRulePresenter::componentSummaries($rule),
        ];
    }

    /**
     * Nama komponen seperti di form Kehadiran untuk periode versi ini (Sakit = "Sakit/Izin" bila izin dicatat
     * bersama sakit).
     */
    private static function componentName(ScoringRuleData $rule, string $code, ?AttendanceComponentData $component): string
    {
        return $component !== null ? AttendanceCountInput::componentLabel($component, $rule) : $code;
    }

    /**
     * @return array{name: string, unit: string, description: ?string, counted: bool, usable: bool, reducesBase: bool, penaltyText: string, noEffect: bool}
     */
    private function componentRow(ScoringRuleData $rule, string $code, ?AttendanceComponentData $component): array
    {
        $unit = $component->unit ?? 'hari';
        $penalty = $rule->penaltyPoints($code);
        $counted = $rule->hasComponent($code);

        return [
            'name' => self::componentName($rule, $code, $component),
            'unit' => $unit,
            'description' => $component?->description,
            'counted' => $counted,
            // Tidak tercantum: isiannya nonaktif di form dan tidak ada kolomnya di template (kecuali 3 komponen lama).
            'usable' => AttendanceCountInput::isUsedBy($code, $rule),
            'reducesBase' => $rule->reducesBase($code),
            'penaltyText' => $penalty > 0 ? ScoringRulePresenter::number($penalty).' poin/'.$unit : '0 (tanpa penalti)',
            'noEffect' => $counted && $penalty <= 0 && ! $rule->reducesBase($code),
        ];
    }

    /**
     * Semua versi aturan, terbaru di atas (sama dengan urutan menu Aturan Penilaian).
     *
     * @return list<array<string, mixed>>
     */
    private function versions(): array
    {
        $versions = [];

        foreach (array_reverse(ScoringRuleService::rules()) as $rule) {
            $status = ScoringRulePresenter::status($rule->effectiveFrom);
            $startLabel = ScoringRulePresenter::periodeLabel($rule->effectiveFrom);

            $versions[] = [
                'name' => $rule->name,
                'startLabel' => self::startLabel($rule),
                'sinceStart' => self::isSinceStart($rule),
                'statusLabel' => ScoringRulePresenter::statusLabel($status),
                'statusColor' => ScoringRulePresenter::statusColor($status),
                'weightsText' => ScoringRulePresenter::weightsSummary($rule),
                'lockText' => match (true) {
                    $rule->isFallback() => 'Aturan bawaan sistem',
                    ScoringRulePresenter::isLocked($rule->effectiveFrom) => 'Terkunci: hanya nama dan catatan yang bisa diubah',
                    default => 'Masih bisa diubah atau dihapus sampai akhir '.$startLabel,
                },
            ];
        }

        return $versions;
    }

    /**
     * Kejadian contoh sebulan dengan nama dan satuan komponen saat ini, mis. 'Terlambat ≤ 30 menit 2 kejadian'.
     *
     * @return list<string>
     */
    private function exampleEvents(): array
    {
        $events = [];

        foreach (self::EXAMPLE_COUNTS as $code => $count) {
            $component = self::componentData($code);
            $name = $component->name ?? ($code === self::IZIN_CODE ? 'Izin' : $code);

            $events[] = $name.' '.$count.' '.($component->unit ?? 'hari');
        }

        return $events;
    }

    /**
     * Contoh hitung langkah demi langkah dengan rumus sistem (PerformanceScoreCalculator) untuk satu versi.
     * Versi yang belum mencantumkan izin mencatat izin di isian Sakit/Izin (form Kehadiran menonaktifkan Izin),
     * jadi contohnya mengikuti cara isi itu.
     *
     * @return array<string, mixed>|null null bila mesin perhitungan versi ini tidak dikenal
     */
    private function example(ScoringRuleData $rule): ?array
    {
        if (! PerformanceScoreCalculator::supportsEngine($rule->engine)) {
            return null;
        }

        $counts = self::EXAMPLE_COUNTS;
        $izinInSick = AttendanceCountInput::recordsIzinInSick($rule) && $rule->hasComponent(self::SICK_CODE);

        if ($izinInSick) {
            $counts[self::SICK_CODE] += $counts[self::IZIN_CODE];
            unset($counts[self::IZIN_CODE]);
        }

        $attendance = PerformanceScoreCalculator::attendance($counts, self::EXAMPLE_WORK_DAYS, $rule);
        $reviewScore = PerformanceScoreCalculator::review((float) self::EXAMPLE_REVIEW_POINTS, $rule);
        $kpiScore = PerformanceScoreCalculator::kpiFinal((float) self::EXAMPLE_KPI_RAW, $rule);

        $rows = [];
        $reducingTerms = [];
        $reducingCount = 0;
        $penaltyTerms = [];

        foreach ($counts as $code => $count) {
            $component = self::componentData($code);
            $name = self::componentName($rule, $code, $component);
            $unit = $component->unit ?? 'hari';
            $counted = $rule->hasComponent($code);
            $penalty = $rule->penaltyPoints($code);

            if ($counted && $rule->reducesBase($code)) {
                $reducingCount += $count;
                $reducingTerms[] = $count.' ('.$name.')';
            }

            if ($counted && $count > 0) {
                $penaltyTerms[] = $count.' × '.ScoringRulePresenter::number($penalty);
            }

            $rows[] = [
                'name' => $name,
                'count' => $count.' '.$unit,
                'note' => $izinInSick && $code === self::SICK_CODE
                    ? self::EXAMPLE_COUNTS[self::SICK_CODE].' sakit + '.self::EXAMPLE_COUNTS[self::IZIN_CODE].' izin'
                    : null,
                'counted' => $counted,
                'reducesBase' => $rule->reducesBase($code),
                'penaltyPerUnit' => ScoringRulePresenter::number($penalty).' poin/'.$unit,
                'penalty' => ScoringRulePresenter::number($count * $penalty),
            ];
        }

        $number = static fn (float $value): string => ScoringRulePresenter::number($value);
        $kpiUncapped = self::EXAMPLE_KPI_RAW * ($rule->kpiWeight / 100);

        return [
            'ruleName' => $rule->name,
            'startLabel' => self::startLabel($rule),
            'workDays' => self::EXAMPLE_WORK_DAYS,
            'rows' => $rows,
            'izinInSick' => $izinInSick,
            'reducingCount' => $reducingCount,
            'reducingTerms' => implode(' + ', $reducingTerms),
            'basePct' => $number($attendance->basePct),
            'penaltyTerms' => $penaltyTerms === [] ? '0' : implode(' + ', $penaltyTerms),
            'penalty' => $number($attendance->penaltyPoints),
            'finalPct' => $number($attendance->finalPct),
            'finalClamped' => $attendance->basePct - $attendance->penaltyPoints < 0,
            'attendanceWeight' => $number($rule->attendanceWeight),
            'attendanceScore' => $number($attendance->score),
            'reviewPoints' => self::EXAMPLE_REVIEW_POINTS,
            'reviewMaxPoints' => $rule->reviewMaxPoints,
            'reviewWeight' => $number($rule->reviewWeight),
            'reviewScore' => $number($reviewScore),
            'kpiRaw' => self::EXAMPLE_KPI_RAW,
            'kpiWeight' => $number($rule->kpiWeight),
            'kpiUncapped' => $number($kpiUncapped),
            'kpiCapped' => $kpiUncapped > $rule->kpiWeight,
            'kpiScore' => $number($kpiScore),
            'total' => $number($kpiScore + $attendance->score + $reviewScore),
        ];
    }

    /**
     * Cakupan karyawan yang boleh dicatat kehadirannya oleh user ini (sama dengan isi template import).
     */
    private function attendanceScope(?User $user): string
    {
        if (AttendancePolicy::canManageAllAttendance($user)) {
            return 'Seluruh karyawan di semua badan usaha.';
        }

        if (DataAccessService::isAreaScopedAdmin($user)) {
            $areaNames = Area::query()
                ->whereKey(DataAccessService::allowedAreaIds($user) ?? [])
                ->orderBy('name')
                ->pluck('name')
                ->all();

            return ($areaNames === []
                ? 'Anggota tim Anda menurut Approval Line'
                : 'Karyawan di badan usaha '.implode(', ', $areaNames).' dan anggota tim Anda menurut Approval Line')
                .', kecuali akun ADMIN (termasuk akun Anda sendiri) yang hanya dicatat oleh super admin.';
        }

        return 'Anggota tim Anda menurut Approval Line (bawahan langsung dan satu level di bawahnya).';
    }

    /**
     * Semua kolom yang bisa muncul di template import Kehadiran (judul persis seperti file template), cara
     * mengisinya, dan periode template yang memuatnya. Template dibuat per periode (AttendanceImportTemplateExport):
     * kolom 1-7 selalu ada (kolom 7 "Sakit/Izin" atau "Sakit"), lalu komponen yang dipakai versi aturan periode itu.
     *
     * @return list<array{heading: string, fill: string, periodes: string}>
     */
    private function templateColumns(string $defaultPeriode): array
    {
        $maxCount = AttendanceCountInput::MAX_COUNT;
        $segments = self::ruleSegments();
        /** @var array<string, array{component: ?AttendanceComponentData, segments: list<int>}> $columns */
        $columns = [];

        foreach ($segments as $index => $segment) {
            $components = AttendanceCountInput::spreadsheetComponents($segment['rule']);
            $headings = AttendanceImportTemplateExport::headingsFor(AttendanceImportTemplateExport::legacyHeadings($segment['rule']), $components);
            // Kolom ke-5 dst. sejajar dengan urutan spreadsheetComponents() (tiga komponen lama lalu "Nama [kode]").
            $byPosition = $components->values();
            $firstComponent = AttendanceImportTemplateExport::FIRST_COMPONENT_COLUMN - 1;

            foreach ($headings as $position => $heading) {
                $columns[$heading] ??= ['component' => $byPosition[$position - $firstComponent] ?? null, 'segments' => []];
                $columns[$heading]['segments'][] = $index;
            }
        }

        $baseFill = [
            'Sudah terisi dari template. Dipakai untuk mencocokkan karyawan, jangan diubah.',
            'Sudah terisi dari template. Dipakai bila ID karyawan kosong atau tidak dikenal.',
            "Sudah terisi periode yang dipilih saat Download Template (format YYYY-MM, mis. {$defaultPeriode}). Jangan diubah: saat import, baris dengan periode lain dilewati, dan sel kosong memakai Periode yang diimport.",
            'Wajib diisi, bilangan bulat 1 sampai '.AttendanceCountInput::MAX_WORK_DAYS.'.',
        ];
        $rows = [];

        foreach (array_values(array_keys($columns)) as $position => $heading) {
            $column = $columns[$heading];
            $component = $column['component'];

            if ($component === null) {
                $fill = $baseFill[$position] ?? '';
            } elseif ($heading === AttendanceCountInput::SICK_IZIN_LABEL) {
                $fill = "Jumlah {$component->unit} sakit ditambah {$component->unit} izin, karena untuk periode ini izin dicatat bersama sakit. Bilangan bulat 0 sampai {$maxCount}; kosong dianggap 0.";
            } else {
                $fill = "Jumlah {$component->unit} {$component->name}. Bilangan bulat 0 sampai {$maxCount}; kosong dianggap 0.";

                if (filled($component->description)) {
                    $fill .= ' '.$component->description;
                }

                if (! $component->isActive) {
                    $fill .= ' (Komponen nonaktif, masih dinilai versi aturan lama.)';
                }
            }

            $rows[] = ['heading' => (string) $heading, 'fill' => $fill, 'periodes' => self::segmentsLabel($column['segments'], $segments)];
        }

        return $rows;
    }

    /**
     * Rentang periode tiap versi aturan, urut naik: periode sebelum versi pertama di database memakai aturan lama
     * bawaan kode (ScoringRuleService::forPeriode()).
     *
     * @return list<array{start: string, rule: ScoringRuleData}>
     */
    private static function ruleSegments(): array
    {
        $segments = [];
        $legacy = ScoringRuleData::legacy();

        foreach (ScoringRuleService::rules() as $rule) {
            if ($segments === [] && strcmp($rule->effectiveFrom, $legacy->effectiveFrom) > 0) {
                $segments[] = ['start' => $legacy->effectiveFrom, 'rule' => $legacy];
            }

            $segments[] = ['start' => $rule->effectiveFrom, 'rule' => $rule];
        }

        return $segments === [] ? [['start' => $legacy->effectiveFrom, 'rule' => $legacy]] : $segments;
    }

    /**
     * Periode yang dicakup beberapa rentang versi, mis. "Semua periode", "Sebelum November 2026",
     * "Mulai November 2026", atau "November 2026 sampai sebelum Januari 2027" (beberapa rentang dipisah titik koma).
     *
     * @param  list<int>  $indexes  indeks rentang di $segments, urut naik
     * @param  list<array{start: string, rule: ScoringRuleData}>  $segments
     */
    private static function segmentsLabel(array $indexes, array $segments): string
    {
        $runs = [];

        foreach ($indexes as $index) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1] === $index - 1) {
                $runs[$last][1] = $index;
            } else {
                $runs[] = [$index, $index];
            }
        }

        $labels = [];

        foreach ($runs as [$from, $to]) {
            $start = $from === 0 ? null : ScoringRulePresenter::periodeLabel($segments[$from]['start']);
            $end = isset($segments[$to + 1]) ? ScoringRulePresenter::periodeLabel($segments[$to + 1]['start']) : null;

            $labels[] = match (true) {
                $start === null && $end === null => 'Semua periode',
                $start === null => "Sebelum {$end}",
                $end === null => "Mulai {$start}",
                default => "{$start} sampai sebelum {$end}",
            };
        }

        return implode('; ', $labels);
    }

    /**
     * Status tiap komponen untuk super admin: aktif/nonaktif, bawaan, dan versi aturan yang masih menilainya
     * (alasan komponen belum bisa dinonaktifkan).
     *
     * @return list<array{name: string, code: string, unit: string, isActive: bool, isBuiltIn: bool, scoredBy: list<string>}>
     */
    private function componentStatuses(): array
    {
        $statuses = [];

        foreach (ScoringRuleService::components() as $code => $component) {
            $statuses[] = [
                'name' => $component->name,
                'code' => $code,
                'unit' => $component->unit,
                'isActive' => $component->isActive,
                'isBuiltIn' => $component->usesLegacyColumn(),
                'scoredBy' => $component->isActive ? AttendanceComponentPolicy::rulesStillScoring($code) : [],
            ];
        }

        return $statuses;
    }
}
