<?php

namespace App\Imports;

use App\Exports\AttendanceImportTemplateExport;
use App\Models\Attendance;
use App\Models\User;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceCountInput;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\HasReferencesToOtherSheets;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Import data kehadiran dari Excel.
 *
 * Pemetaan kolom:
 * - File dengan baris judul (template/export, baru maupun lama): kolom dipetakan lewat judulnya. Kolom 1-7
 *   memakai judul template ('ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min',
 *   'Late More 30 min', 'Sakit' / judul lama 'Sakit or Izin', plus beberapa variasi umum). Judul template/lama
 *   tiga komponen lama selalu milik komponen lama, walaupun ada komponen master yang kodenya sama (mis. kode
 *   "sakit"). Komponen lain dikenali dari judul "Nama [kode]" (tetap walaupun nama komponen diubah), atau dari
 *   kode / nama komponen saja. Urutan kolom bebas; kolom yang tidak dikenal diabaikan dengan peringatan.
 * - Judul Hari Kerja dan tiga komponen lama yang tidak dikenali dibaca dari posisi template (kolom 4-7, seperti
 *   import lama yang membaca posisi), dengan peringatan, hanya bila kolom yang dikenali tetap di posisi template
 *   (tidak bergeser oleh kolom tambahan seperti "No" / "Divisi"). ID karyawan/Nama Lengkap hanya ditebak dari
 *   posisi bila keduanya tidak dikenali (salah tebak = data masuk ke karyawan lain); Periode tidak pernah ditebak.
 *   Kolom wajib yang tetap tidak ditemukan membuat setiap baris dilewati dengan alasannya, bukan diimport dengan 0.
 * - Tanpa baris judul: hanya 7 kolom template lama yang dibaca (kolom ke-8 dst. diabaikan dengan peringatan),
 *   karena arti kolom tambahan tidak bisa dipastikan tanpa judul.
 * - Baris tanpa data kehadiran yang juga tidak menunjuk karyawan mana pun (judul laporan di atas tabel, label
 *   "Periode | November 2026", catatan) bukan baris data dan diabaikan tanpa dihitung.
 * - Workbook berisi beberapa sheet: setiap sheet dibaca dengan baris judulnya sendiri. Sheet yang baris judulnya
 *   tidak memuat kolom wajib (mis. sheet master karyawan atau rekap) tidak diimport (satu peringatan per sheet),
 *   dan alasan/peringatan diberi nama sheet-nya.
 * - Sel berisi rumus dibaca dari hasil hitungnya (rumus yang menghasilkan "" = kosong).
 * - Komponen yang kolomnya tidak ada di file dianggap 0 (diperingatkan bila dinilai aturan periode barisnya).
 * - Periode yang dipilih saat import (opsional, lihat konstruktor): sel Periode yang kosong memakai periode itu, dan
 *   baris dengan periode lain dilewati, diringkas per periode dalam satu peringatan (berapa baris per periode dan
 *   langkah perbaikannya), bukan alasan per baris. Tanpa periode pilihan, setiap baris memakai periode di filenya
 *   sendiri (perilaku lama, termasuk file 7 kolom tanpa baris judul).
 *
 * Validasi per baris (baris yang gagal dilewati dengan alasannya):
 * - Periode harus terbaca sebagai tahun-bulan (Periode::normalize, termasuk tanggal Excel) dengan tahun
 *   2000-2100. Ini menolak data sampah 1900-01 / 1970-01 yang muncul bila kolom Periode kosong dan
 *   nilai Hari Kerja terbaca sebagai tanggal Excel.
 * - Baris template yang belum diisi sama sekali (Hari Kerja dan semua kolom komponen kosong; ID, nama, dan
 *   periode bawaan template boleh terisi) diabaikan dan dihitung terpisah (unfilledCount), bukan sebagai baris
 *   gagal, agar alasan baris yang benar-benar bermasalah tidak tenggelam di antara ratusan baris kosong.
 * - Hari kerja wajib diisi, bilangan bulat 1-31 (sama dengan form Kehadiran); baris yang komponennya terisi
 *   tetapi hari kerjanya kosong dilewati dengan alasannya, bukan menjadi data kehadiran bernilai 0.
 * - Hitungan komponen bilangan bulat 0-999 (kosong = 0).
 * - Komponen yang tidak dipakai versi aturan periode barisnya (AttendanceCountInput::isUsedBy(), mis. Izin untuk
 *   periode sebelum November 2026, saat izin masih dicatat di kolom Sakit/Izin) harus kosong atau 0; baris yang
 *   mengisinya > 0 dilewati dengan satu alasan untuk semua kolom itu (Izin: jumlahkan ke kolom Sakit/Izin dulu),
 *   bukan disimpan tanpa memengaruhi skor. Tiga kolom lama selalu boleh. Ini juga berlaku untuk file export yang
 *   diimport ulang: baris yang menyimpan hitungan seperti itu dilewati, baris lain kembali utuh.
 * - Karyawan dicari dari ID karyawan di antara seluruh karyawan (termasuk yang di luar cakupan akses atau sudah
 *   dihapus), sehingga ID milik karyawan lain tidak pernah dicocokkan ulang lewat nama ke karyawan lain. Nama
 *   Lengkap hanya dipakai bila ID kosong / tidak dikenal; nama yang dipakai lebih dari satu karyawan ditolak.
 *   Karyawan yang terlihat tetapi tidak boleh diisi datanya oleh user ditolak dengan alasannya.
 * - Import terbatas (bukan seluruh karyawan): karyawan di luar cakupan user diperlakukan seperti tidak ada, dengan
 *   alasan yang sama dengan "tidak ditemukan", agar import tidak membuka keberadaan ID / nama / status dihapus
 *   karyawan badan usaha lain. ID yang diisi harus ID karyawan dalam cakupan (tidak dicocokkan ulang lewat nama).
 * - Nama dipakai beberapa karyawan yang belum memiliki ID karyawan: alasannya meminta ID karyawan dilengkapi di data
 *   karyawan dulu (mengisi kolom ID di file saja tidak cukup, karena ID itu belum dikenal).
 * - Data karyawan + periode yang sudah ada tidak ditimpa; baris ganda (karyawan + periode sama) di file yang
 *   sama hanya diimport baris pertamanya, dan baris berikutnya dilewati dengan alasan "baris ganda". Keduanya dicek
 *   sebelum isi baris divalidasi, sehingga baris yang datanya sudah ada selalu dilaporkan "sudah ada" (mis. baris
 *   Hari Kerja 0 dari API di file export yang diimport ulang).
 */
class AttendanceImport implements HasReferencesToOtherSheets, ToModel, WithCalculatedFormulas, WithEvents
{
    private const FIELD_EMPLOYEE_ID = 'employee_id';

    private const FIELD_NAME = 'nama_lengkap';

    private const FIELD_PERIODE = 'periode';

    private const FIELD_WORK_DAYS = 'work_days';

    /** Posisi kolom dasar template (file tanpa baris judul, dan cadangan bila judulnya tidak dikenali). */
    private const BASE_POSITIONS = [
        self::FIELD_EMPLOYEE_ID => 0,
        self::FIELD_NAME => 1,
        self::FIELD_PERIODE => 2,
        self::FIELD_WORK_DAYS => 3,
    ];

    /** Kolom dasar yang boleh ditebak dari posisi template bila judulnya tidak dikenali (Periode tidak pernah). */
    private const GUESSABLE_BASE_FIELDS = [self::FIELD_EMPLOYEE_ID, self::FIELD_NAME, self::FIELD_WORK_DAYS];

    /** Posisi kolom tiga komponen lama di template (kolom 5-7). */
    private const LEGACY_COMPONENT_POSITIONS = [
        'late_less_30' => 4,
        'late_more_30' => 5,
        'sick_days' => 6,
    ];

    /** Jumlah kolom yang dibaca dari file tanpa baris judul. */
    private const POSITIONAL_COLUMN_COUNT = 7;

    /** Judul kolom dasar yang dikenali (dibandingkan setelah normalizeHeading()). */
    private const BASE_HEADING_ALIASES = [
        self::FIELD_EMPLOYEE_ID => ['ID karyawan', 'Employee ID', 'NIK', 'NIP', 'No karyawan', 'Nomor karyawan', 'No induk karyawan', 'Nomor induk karyawan', 'Kode karyawan', 'ID pegawai', 'Kode pegawai'],
        self::FIELD_NAME => ['Nama Lengkap', 'Nama', 'Nama karyawan', 'Nama pegawai', 'Full name', 'Name'],
        self::FIELD_PERIODE => ['Periode', 'Period', 'Bulan', 'Periode (YYYY-MM)', 'Periode Kehadiran', 'Periode Absensi', 'Bulan/Tahun', 'Month'],
        self::FIELD_WORK_DAYS => ['Hari Kerja', 'Work Days', 'Jumlah Hari Kerja', 'Jml Hari Kerja', 'Hari Kerja Efektif', 'Jumlah Hari Kerja Efektif', 'HK', 'Jumlah HK', 'Jml HK'],
    ];

    /** Judul lama / variasi umum kolom tiga komponen lama (dibandingkan setelah normalizeHeading()). */
    private const LEGACY_HEADING_ALIASES = [
        'late_less_30' => ['Late Less 30 min', 'Late < 30', 'Late <= 30', 'Terlambat < 30', 'Terlambat <= 30', 'Terlambat < 30 menit', 'Terlambat <= 30 menit', 'Keterlambatan < 30', 'Keterlambatan <= 30', 'Keterlambatan < 30 menit', 'Keterlambatan <= 30 menit', 'Telat < 30', 'Telat <= 30', 'Telat < 30 menit', 'Telat <= 30 menit'],
        'late_more_30' => ['Late More 30 min', 'Late > 30', 'Terlambat > 30', 'Terlambat > 30 menit', 'Keterlambatan > 30', 'Keterlambatan > 30 menit', 'Telat > 30', 'Telat > 30 menit'],
        'sick_days' => ['Sakit', 'Sakit or Izin', 'Sakit/Izin', 'Sakit atau Izin', 'Sakit & Izin', 'Sakit dan Izin', 'Jumlah Hari Sakit', 'Sick Days'],
    ];

    /**
     * Variasi umum judul komponen master bawaan (dipakai hanya bila komponennya ada di master; kalah dari nama
     * dan kode komponen).
     */
    private const COMPONENT_HEADING_ALIASES = [
        'izin' => ['Ijin'],
        'mangkir' => ['Alpa', 'Alpha', 'Alfa', 'Tanpa Keterangan'],
    ];

    /**
     * Karyawan yang terlihat di daftar Kehadiran tetapi tidak boleh dicatat user: akun ADMIN bagi admin per badan
     * usaha (AttendancePolicy::writableUserIds).
     */
    private const READ_ONLY_EMPLOYEE_REASON = 'Kehadiran akun ADMIN (termasuk akun Anda sendiri) hanya dapat dicatat oleh super admin.';

    /** Juga dipakai untuk karyawan di luar cakupan user, agar keberadaannya tidak terbaca dari alasan penolakan. */
    private const NOT_FOUND_REASON = 'Karyawan tidak ditemukan. Periksa ID karyawan dan Nama Lengkap.';

    /** @var array<string, array{id: int, deleted: bool}> employee_id => pemilik (seluruh karyawan, termasuk yang dihapus) */
    private array $usersByEmployeeId = [];

    /** @var array<string, list<int>> nama_lengkap => user id karyawan aktif (lebih dari satu = nama dipakai beberapa karyawan) */
    private array $usersByName = [];

    /** @var array<int, true> user id karyawan aktif yang bisa dipilih lewat ID karyawan (pemilik ID di usersByEmployeeId) */
    private array $usersWithEmployeeId = [];

    /** @var array<int, true>|null */
    private ?array $allowedUserIds = null;

    /** @var array<int, true>|null */
    private ?array $visibleUserIds = null;

    private int $importedCount = 0;

    private int $skippedCount = 0;

    /** Baris template yang belum diisi (diabaikan, bukan gagal). */
    private int $unfilledCount = 0;

    /** @var list<array{nama_lengkap: mixed, employee_id: mixed, periode: mixed, reason: string}> */
    private array $skippedDetails = [];

    /** @var array<string, true> "user_id|periode" yang sudah diimport dari file ini */
    private array $importedRows = [];

    /**
     * Pemetaan kolom dari baris judul sheet yang sedang dibaca; null = urutan template (tanpa baris judul).
     * 'error' = kolom wajib yang tidak ditemukan (setiap baris dilewati dengan alasan itu). 'warnings'/'ignored'
     * baru dilaporkan ('committed') saat ada baris data yang dibaca dengan pemetaan ini, agar baris label di atas
     * tabel yang terbaca sebagai judul tidak meninggalkan peringatan palsu.
     *
     * @var array{base: array<string, int>, components: array<string, int>, error: ?string, warnings: list<string>, ignored: list<string>, committed: bool}|null
     */
    private ?array $headingLayout = null;

    /** Nama sheet yang sedang dibaca bila workbook berisi lebih dari satu sheet; null = satu sheet. */
    private ?string $sheetName = null;

    /** @var array{base: array<string, string>, components: array<string, string|null>, legacy: array<string, true>, names: array<string, string|null>}|null judul ternormalisasi => field / kode (null = ambigu) */
    private ?array $headingAliases = null;

    /** @var array<string, true> peringatan pemetaan kolom (urut kemunculan) */
    private array $layoutWarnings = [];

    /** @var array<string, true> judul kolom yang tidak dikenal (diabaikan) */
    private array $ignoredHeadings = [];

    /** @var array<string, array<string, true>> kode komponen => periode yang hitungannya tidak ada di file */
    private array $missingComponents = [];

    /**
     * @var array<string, array<string, true>> kode komponen lama => periode yang diisi tetapi tidak tercantum di aturannya
     *                                         (komponen lain yang tidak dipakai aturan membuat barisnya dilewati)
     */
    private array $uncountedComponents = [];

    /** Periode yang dipilih saat import ('Y-m'); null = setiap baris memakai periode di filenya. */
    private ?string $periode = null;

    /** @var array<string, int> periode lain di file => jumlah baris yang dilewati (periode yang dipilih berbeda) */
    private array $periodeMismatches = [];

    /** File tanpa baris judul berisi nilai setelah kolom ke-7 (diabaikan). */
    private bool $positionalExtraColumns = false;

    /**
     * @param  array<int|string>|null  $allowedUserIds  null = seluruh karyawan; selain itu hanya karyawan yang boleh diisi datanya.
     * @param  array<int|string>|null  $visibleUserIds  karyawan yang terlihat oleh user (AttendancePolicy::visibleUserIds), untuk
     *                                                  alasan penolakan yang tepat; null = tidak dibedakan.
     * @param  string|null  $periode  periode yang dipilih saat import ('YYYY-MM'): sel Periode kosong memakai periode ini
     *                                dan baris periode lain dilewati; null = periode diambil dari setiap baris.
     *
     * @throws InvalidArgumentException bila $periode tidak terbaca atau di luar rentang tahun yang diizinkan.
     */
    public function __construct(?array $allowedUserIds = null, ?array $visibleUserIds = null, ?string $periode = null)
    {
        $this->allowedUserIds = self::idSet($allowedUserIds);
        $this->visibleUserIds = self::idSet($visibleUserIds);

        if ($periode !== null) {
            $this->periode = AttendanceCountInput::periode($periode)
                ?? throw new InvalidArgumentException((string) AttendanceCountInput::periodeError($periode));
        }

        // Seluruh karyawan, termasuk di luar cakupan dan yang sudah dihapus: ID karyawan selalu menunjuk pemiliknya
        // (ditolak bila tidak boleh diisi), tidak pernah jatuh ke pencarian nama yang bisa menemukan karyawan lain
        // bernama sama. Dua peta terpisah: employee_id numerik tidak boleh tertimpa/teracak oleh array_merge.
        foreach (User::withTrashed()->get(['id', 'nama_lengkap', 'employee_id', 'deleted_at']) as $user) {
            $deleted = $user->deleted_at !== null;

            if (($employeeId = self::lookupKey($user->employee_id)) !== null
                && (! isset($this->usersByEmployeeId[$employeeId]) || $this->usersByEmployeeId[$employeeId]['deleted'])) {
                $this->usersByEmployeeId[$employeeId] = ['id' => (int) $user->id, 'deleted' => $deleted];
            }

            if (! $deleted && ($name = self::lookupKey($user->nama_lengkap)) !== null) {
                $this->usersByName[$name][] = (int) $user->id;
            }
        }

        foreach ($this->usersByEmployeeId as $owner) {
            if (! $owner['deleted']) {
                $this->usersWithEmployeeId[$owner['id']] = true;
            }
        }
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            // Setiap sheet membaca baris judulnya sendiri.
            BeforeSheet::class => function (BeforeSheet $event): void {
                $worksheet = $event->getSheet()->getDelegate();

                $this->headingLayout = null;
                $this->sheetName = ($worksheet->getParent()?->getSheetCount() ?? 1) > 1 ? $worksheet->getTitle() : null;
            },
        ];
    }

    /**
     * @param  array<int, mixed>  $row
     */
    public function model(array $row): Model|array|null
    {
        $row = array_values($row);

        if (self::isBlankRow($row)) {
            return null;
        }

        if ($this->isHeadingRow($row)) {
            $this->headingLayout = $this->layoutFromHeadings($row);

            return null;
        }

        $layout = $this->headingLayout;
        $base = $layout['base'] ?? self::BASE_POSITIONS;
        $componentColumns = $layout['components'] ?? self::LEGACY_COMPONENT_POSITIONS;
        $layoutError = $layout['error'] ?? null;

        $employeeId = self::cell($row, $base[self::FIELD_EMPLOYEE_ID] ?? null);
        $name = self::cell($row, $base[self::FIELD_NAME] ?? null);
        $rawPeriode = self::cell($row, $base[self::FIELD_PERIODE] ?? null);
        $unfilled = self::isUnfilledTemplateRow($row, $base, $componentColumns);

        // Tanpa data kehadiran dan tanpa karyawan yang dikenal (judul laporan, label "Periode | November 2026",
        // catatan): bukan baris data.
        if ($unfilled && ! $this->isKnownEmployee($employeeId, $name)) {
            return null;
        }

        // Workbook beberapa sheet: sheet tanpa baris judul template (mis. catatan yang menyebut karyawan) bukan data
        // kehadiran. Tidak dibaca dengan posisi template agar tidak dihitung sebagai baris template / baris gagal.
        if ($layout === null && $this->sheetName !== null) {
            $this->layoutWarnings["Sheet \"{$this->sheetName}\" tidak diimport: baris judul template (ID karyawan, Nama Lengkap, Periode, Hari Kerja) tidak ditemukan."] = true;

            return null;
        }

        // Workbook beberapa sheet: sheet tanpa kolom wajib (mis. master karyawan, rekap) bukan data kehadiran.
        if ($layoutError !== null && $this->sheetName !== null) {
            $this->layoutWarnings["Sheet \"{$this->sheetName}\" tidak diimport: {$layoutError}"] = true;

            return null;
        }

        $this->commitLayoutWarnings();

        if ($layout === null && self::hasValuesFrom($row, self::POSITIONAL_COLUMN_COUNT)) {
            $this->positionalExtraColumns = true;
        }

        // Kolom wajib tidak ditemukan: baris karyawan tidak bisa dianggap "belum diisi" (kolomnya yang tidak ada).
        if ($layoutError !== null) {
            return $this->skip($employeeId, $name, $rawPeriode, $layoutError.' Baris tidak diimport.');
        }

        if ($unfilled) {
            $this->unfilledCount++;

            return null;
        }

        [$userId, $userError] = $this->resolveUser($employeeId, $name);

        if ($userId === null) {
            return $this->skip($employeeId, $name, $rawPeriode, (string) $userError);
        }

        // Sel Periode kosong memakai periode yang dipilih saat import (bila ada).
        [$periode, $periodeError] = ($rawPeriode === null || $rawPeriode === '') && $this->periode !== null
            ? [$this->periode, null]
            : self::parsePeriode($rawPeriode);

        if ($periode === null) {
            return $this->skip($employeeId, $name, $rawPeriode, (string) $periodeError);
        }

        // Periode lain di file: diringkas per periode di peringatan (bukan alasan per baris), karena langkahnya sama
        // untuk semua baris itu (pilih periode itu saat import, atau perbaiki kolom Periode).
        if ($this->periode !== null && $periode !== $this->periode) {
            $this->periodeMismatches[$periode] = ($this->periodeMismatches[$periode] ?? 0) + 1;

            return $this->skip($employeeId, $name, $periode, "Periode di file ({$periode}) berbeda dengan periode yang dipilih ({$this->periode}).", detailed: false);
        }

        // Baris pertama yang diimport menang; baris berikutnya untuk karyawan + periode yang sama dilaporkan sebagai
        // baris ganda (bukan "sudah ada", yang berarti data sebelum import).
        $rowKey = "{$userId}|{$periode}";

        if (isset($this->importedRows[$rowKey])) {
            return $this->skip($employeeId, $name, $periode, 'Baris ganda: karyawan dan periode ini sudah diisi di baris sebelumnya pada file yang sama. Hanya baris pertama yang diimport.');
        }

        // Cek apakah absensi untuk userId dan periode sudah ada (data lama tidak ditimpa). Dicek sebelum isi baris
        // divalidasi: baris yang datanya sudah ada tidak akan diimport apa pun isinya, jadi alasannya "sudah ada" (mis.
        // file export yang diimport ulang, termasuk baris Hari Kerja 0 yang hanya bisa dibuat lewat API).
        $existingAttendance = Attendance::query()
            ->where('user_id', $userId)
            ->where('periode', $periode)
            ->exists();

        if ($existingAttendance) {
            return $this->skip($employeeId, $name, $periode, 'Data kehadiran karyawan ini untuk periode tersebut sudah ada.');
        }

        $rawWorkDays = self::cell($row, $base[self::FIELD_WORK_DAYS] ?? null);
        $workDayLabel = self::baseLabel(self::FIELD_WORK_DAYS);

        if ($rawWorkDays === null || $rawWorkDays === '') {
            return $this->skip($employeeId, $name, $periode, "{$workDayLabel} kosong. Isi jumlah hari kerja (1 sampai ".AttendanceCountInput::MAX_WORK_DAYS.').');
        }

        $workDays = self::parseInteger($rawWorkDays);

        if ($workDays === null || $workDays < 1 || $workDays > AttendanceCountInput::MAX_WORK_DAYS) {
            return $this->skip($employeeId, $name, $periode, "{$workDayLabel} harus bilangan bulat 1 sampai ".AttendanceCountInput::MAX_WORK_DAYS.'.');
        }

        $counts = [];

        foreach ($componentColumns as $code => $index) {
            $count = self::parseCount(self::cell($row, $index));

            if ($count === null) {
                return $this->skip($employeeId, $name, $periode, 'Nilai '.self::columnLabel($code).' harus bilangan bulat 0 sampai '.AttendanceCountInput::MAX_COUNT.'.');
            }

            $counts[$code] = $count;
        }

        // Komponen yang tidak dipakai aturan periode ini tidak boleh diisi (mis. Izin sebelum izin dinilai sendiri:
        // izin seharusnya dijumlahkan ke kolom Sakit/Izin). 0/kosong tetap boleh, misalnya template lama berisi semua
        // kolom. Satu alasan untuk semua kolom yang bermasalah.
        $unused = AttendanceCountInput::unusedCodes($counts, ScoringRuleService::forPeriode($periode));

        if ($unused !== []) {
            return $this->skip($employeeId, $name, $periode, AttendanceCountInput::unusedComponentsMessage($unused, $periode));
        }

        // File tanpa baris judul hanya memuat komponen sampai kolom terakhirnya.
        $providedCodes = $layout !== null
            ? array_keys($componentColumns)
            : array_keys(array_filter($componentColumns, static fn (int $index): bool => array_key_exists($index, $row)));
        $this->rememberRuleMismatches($periode, $providedCodes, $counts);
        $this->importedRows[$rowKey] = true;
        $this->importedCount++;

        // Komponen yang tidak ada di file dianggap 0 (default kolom / tidak disimpan di component_counts).
        return new Attendance(array_filter(
            [
                'user_id' => $userId,
                'periode' => $periode,
                'work_days' => $workDays,
            ] + AttendanceCountInput::attributes($counts),
            static fn (mixed $value): bool => $value !== null,
        ));
    }

    /**
     * Periode yang dipilih saat import; null = periode diambil dari setiap baris.
     */
    public function periode(): ?string
    {
        return $this->periode;
    }

    /**
     * skippedDetails berisi alasan per baris yang dilewati, kecuali baris periode lain (periode yang dipilih
     * berbeda): baris itu diringkas per periode di periodeMismatches dan di peringatan pertama, tetapi tetap
     * dihitung di skippedCount.
     *
     * @return array{periode: ?string, importedCount: int, skippedCount: int, unfilledCount: int, skippedDetails: list<array{nama_lengkap: mixed, employee_id: mixed, periode: mixed, reason: string}>, periodeMismatches: array<string, int>, warnings: list<string>}
     */
    public function getImportSummary(): array
    {
        $mismatches = $this->periodeMismatches;
        ksort($mismatches);

        return [
            'periode' => $this->periode,
            'importedCount' => $this->importedCount,
            'skippedCount' => $this->skippedCount,
            'unfilledCount' => $this->unfilledCount,
            'skippedDetails' => $this->skippedDetails,
            'periodeMismatches' => $mismatches,
            'warnings' => $this->warnings(),
        ];
    }

    /**
     * Ringkasan baris periode lain, mis. "File berisi 25 baris periode Agustus 2026, bukan September 2026 yang
     * dipilih; baris tersebut dilewati. Bila datanya untuk Agustus 2026, import ulang file ini dan pilih Agustus
     * 2026. Bila datanya untuk September 2026, perbaiki atau kosongkan kolom Periode di file."; null bila tidak ada.
     */
    private function periodeMismatchWarning(): ?string
    {
        if ($this->periode === null || $this->periodeMismatches === []) {
            return null;
        }

        $mismatches = $this->periodeMismatches;
        ksort($mismatches);
        $chosen = Periode::label($this->periode);
        $parts = [];

        foreach ($mismatches as $periode => $count) {
            $parts[] = "{$count} baris periode ".Periode::label($periode);
        }

        $retry = count($mismatches) === 1
            ? 'Bila datanya untuk '.Periode::label((string) array_key_first($mismatches)).', import ulang file ini dan pilih '.Periode::label((string) array_key_first($mismatches)).'.'
            : 'Bila datanya untuk periode tersebut, import ulang file ini untuk setiap periode dan pilih periodenya.';

        return 'File berisi '.AttendanceCountInput::joinList($parts).", bukan {$chosen} yang dipilih; baris tersebut dilewati. {$retry} "
            ."Bila datanya untuk {$chosen}, perbaiki atau kosongkan kolom Periode di file.";
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        $warnings = array_keys($this->layoutWarnings);

        if (($mismatch = $this->periodeMismatchWarning()) !== null) {
            array_unshift($warnings, $mismatch);
        }

        foreach ($this->missingComponents as $code => $periodes) {
            $warnings[] = 'Kolom "'.self::columnLabel($code).'" tidak ada di file sehingga dianggap 0 untuk periode '.self::periodeList($periodes).'.';
        }

        foreach ($this->uncountedComponents as $code => $periodes) {
            $warnings[] = 'Kolom "'.self::columnLabel($code).'" berisi nilai untuk periode '.self::periodeList($periodes)
                .', tetapi aturan penilaian periode tersebut tidak menghitung komponen ini (nilai disimpan tanpa memengaruhi skor). Periksa apakah nilainya seharusnya diisi di kolom lain.';
        }

        if ($this->ignoredHeadings !== []) {
            $warnings[] = 'Kolom tidak dikenal diabaikan: '.implode(', ', array_keys($this->ignoredHeadings)).'.';
        }

        if ($this->positionalExtraColumns) {
            $warnings[] = 'File tanpa baris judul: hanya 7 kolom pertama yang dibaca ('.implode(', ', AttendanceImportTemplateExport::LEGACY_HEADINGS)
                .'); kolom ke-8 dan seterusnya diabaikan. Gunakan template terbaru (dengan baris judul) untuk mengisi komponen lain.';
        }

        return $warnings;
    }

    /**
     * Catat komponen yang dinilai aturan periode baris ini (penalti > 0 atau mengurangi hari kerja) tetapi
     * kolomnya tidak ada di file (mis. file 7 kolom lama untuk periode aturan baru: izin, mangkir), dan kolom lama
     * yang diisi > 0 tetapi tidak tercantum di aturan periode itu (komponen lain yang tidak dipakai aturan sudah
     * membuat barisnya dilewati).
     *
     * @param  list<string>  $providedCodes
     * @param  array<string, int>  $counts
     */
    private function rememberRuleMismatches(string $periode, array $providedCodes, array $counts): void
    {
        $rule = ScoringRuleService::forPeriode($periode);

        foreach ($rule->componentCodes() as $code) {
            if (! in_array($code, $providedCodes, true) && ($rule->penaltyPoints($code) > 0 || $rule->reducesBase($code))) {
                $this->missingComponents[$code][$periode] = true;
            }
        }

        foreach ($counts as $code => $count) {
            if ($count > 0 && ! $rule->hasComponent($code)) {
                $this->uncountedComponents[$code][$periode] = true;
            }
        }
    }

    /**
     * @param  bool  $detailed  false = hanya dihitung & dicatat di log (diringkas di peringatan), tanpa alasan per baris.
     */
    private function skip(mixed $employeeId, mixed $name, mixed $periode, string $reason, bool $detailed = true): null
    {
        $reason = $this->sheetPrefix().$reason;

        Log::info('Import Attendance: baris dilewati', [
            'employee_id' => $employeeId,
            'nama_lengkap' => $name,
            'periode' => $periode,
            'reason' => $reason,
        ]);

        if ($detailed) {
            $this->skippedDetails[] = [
                'nama_lengkap' => $name,
                'employee_id' => $employeeId,
                'periode' => $periode ?? 'Tidak diketahui',
                'reason' => $reason,
            ];
        }

        $this->skippedCount++;

        return null;
    }

    /**
     * Karyawan baris ini: [user id, null] atau [null, alasan ditolak].
     *
     * ID karyawan yang dikenal selalu menunjuk pemiliknya (di luar cakupan / sudah dihapus = ditolak); Nama
     * Lengkap hanya dipakai bila ID kosong atau tidak dikenal, dan harus menunjuk tepat satu karyawan aktif.
     * Karyawan di luar cakupan user ditolak dengan alasan yang sama dengan karyawan yang tidak ada.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveUser(mixed $employeeId, mixed $name): array
    {
        $idKey = self::lookupKey($employeeId);

        if ($idKey !== null && isset($this->usersByEmployeeId[$idKey])) {
            $owner = $this->usersByEmployeeId[$idKey];

            if (! $this->isVisibleUser($owner['id'])) {
                return [null, self::NOT_FOUND_REASON];
            }

            return $owner['deleted']
                ? [null, "ID karyawan \"{$idKey}\" milik karyawan yang sudah dihapus."]
                : $this->writableUser($owner['id']);
        }

        // Import terbatas: mencocokkan ulang lewat nama hanya untuk ID yang tidak ada di perusahaan akan membedakan
        // ID karyawan badan usaha lain (ditolak di atas) dari ID yang tidak ada. ID yang diisi harus ID dalam cakupan.
        if ($idKey !== null && $this->isLimited()) {
            return [null, self::NOT_FOUND_REASON];
        }

        if (($nameKey = self::lookupKey($name)) !== null) {
            $candidates = $this->usersByName[$nameKey] ?? [];
            $visibleCandidates = array_values(array_filter($candidates, fn (int $userId): bool => $this->isVisibleUser($userId)));

            // Nama yang tidak dipakai karyawan dalam cakupan: sama dengan tidak ditemukan. Nama karyawan dalam cakupan
            // yang juga dipakai karyawan lain tetap ditolak agar data tidak masuk ke karyawan yang salah.
            if (count($candidates) > 1 && $visibleCandidates !== []) {
                return [null, $this->ambiguousNameReason($nameKey, $idKey, $visibleCandidates)];
            }

            if ($visibleCandidates !== []) {
                return $this->writableUser($visibleCandidates[0]);
            }
        }

        return [null, self::NOT_FOUND_REASON];
    }

    /**
     * Alasan penolakan nama yang dipakai lebih dari satu karyawan. Karyawan dalam cakupan yang belum memiliki ID
     * karyawan tidak bisa dipilih lewat kolom ID (ID apa pun yang diisi tidak dikenal), jadi ID-nya harus dilengkapi
     * di data karyawan dulu.
     *
     * @param  list<int>  $visibleCandidates
     */
    private function ambiguousNameReason(string $nameKey, ?string $idKey, array $visibleCandidates): string
    {
        $withoutEmployeeId = array_filter($visibleCandidates, fn (int $userId): bool => ! isset($this->usersWithEmployeeId[$userId]));

        if (count($withoutEmployeeId) === count($visibleCandidates)) {
            return "Nama \"{$nameKey}\" dipakai lebih dari satu karyawan, dan ID karyawan untuk nama ini belum diisi di data karyawan. "
                .'Lengkapi ID karyawan di data karyawan (menu Karyawan / Tim Saya) lalu isi kolom ID karyawan di file, atau isi kehadirannya lewat form Kehadiran.';
        }

        return "Nama \"{$nameKey}\" dipakai lebih dari satu karyawan. ".($idKey === null ? 'Isi' : 'Perbaiki')
            .' kolom ID karyawan agar data tidak masuk ke karyawan lain.'
            .($withoutEmployeeId !== [] ? ' Karyawan bernama sama yang belum memiliki ID karyawan perlu dilengkapi dulu ID-nya di data karyawan.' : '');
    }

    /**
     * Import dibatasi ke sebagian karyawan (admin per badan usaha, atasan), bukan seluruh karyawan.
     */
    private function isLimited(): bool
    {
        return $this->allowedUserIds !== null;
    }

    /**
     * Karyawan dalam cakupan user: boleh diisi datanya, atau terlihat di daftar Kehadiran.
     */
    private function isVisibleUser(int $userId): bool
    {
        return ! $this->isLimited()
            || isset($this->allowedUserIds[$userId])
            || isset($this->visibleUserIds[$userId]);
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function writableUser(int $userId): array
    {
        if ($this->allowedUserIds === null || isset($this->allowedUserIds[$userId])) {
            return [$userId, null];
        }

        // Pemanggil sudah memastikan karyawan dalam cakupan (isVisibleUser).
        return [null, self::READ_ONLY_EMPLOYEE_REASON];
    }

    /**
     * ID karyawan dikenal (termasuk karyawan yang dihapus) atau Nama Lengkap milik karyawan aktif, dalam cakupan user:
     * baris tentang karyawan di luar cakupan diperlakukan sama dengan baris tentang karyawan yang tidak ada.
     */
    private function isKnownEmployee(mixed $employeeId, mixed $name): bool
    {
        $idKey = self::lookupKey($employeeId);
        $nameKey = self::lookupKey($name);

        if ($idKey !== null && isset($this->usersByEmployeeId[$idKey]) && $this->isVisibleUser($this->usersByEmployeeId[$idKey]['id'])) {
            return true;
        }

        foreach ($nameKey === null ? [] : ($this->usersByName[$nameKey] ?? []) as $userId) {
            if ($this->isVisibleUser($userId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laporkan peringatan pemetaan kolom baris judul yang sedang dipakai (sekali per baris judul).
     */
    private function commitLayoutWarnings(): void
    {
        if ($this->headingLayout === null || $this->headingLayout['committed']) {
            return;
        }

        foreach ($this->headingLayout['warnings'] as $warning) {
            $this->layoutWarnings[$this->sheetPrefix().$warning] = true;
        }

        foreach ($this->headingLayout['ignored'] as $heading) {
            $this->ignoredHeadings[$heading] = true;
        }

        $this->headingLayout['committed'] = true;
    }

    private function sheetPrefix(): string
    {
        return $this->sheetName === null ? '' : "Sheet \"{$this->sheetName}\": ";
    }

    /**
     * Pemetaan kolom dari baris judul (lihat dokumentasi kelas).
     *
     * @param  array<int, mixed>  $row
     * @return array{base: array<string, int>, components: array<string, int>, error: ?string, warnings: list<string>, ignored: list<string>, committed: bool}
     */
    private function layoutFromHeadings(array $row): array
    {
        $aliases = $this->headingAliases();
        $base = [];
        $components = [];
        $warnings = [];
        /** @var array<int, string|null> $unclaimed indeks => judul kolom yang tidak dikenali (null = tanpa judul) */
        $unclaimed = [];

        foreach ($row as $index => $heading) {
            $text = is_string($heading) || is_numeric($heading) ? self::clean((string) $heading) : '';
            $normalized = self::normalizeHeading($text);

            if ($normalized === '') {
                $unclaimed[$index] = null;

                continue;
            }

            $code = $this->headingCode($text, $normalized, $aliases);
            $field = $code === null ? ($aliases['base'][$normalized] ?? null) : null;

            if ($code === null && $field === null && array_key_exists($normalized, $aliases['components'])) {
                $code = $aliases['components'][$normalized];

                if ($code === null) {
                    $warnings[] = "Kolom \"{$text}\" diabaikan karena namanya dipakai lebih dari satu komponen kehadiran. Gunakan judul dengan kode komponen seperti di template, mis. \"{$text} [kode]\".";

                    continue;
                }
            }

            if ($field !== null) {
                if (isset($base[$field])) {
                    $warnings[] = self::duplicateColumnWarning($text, $index, self::baseLabel($field), $base[$field]);

                    continue;
                }

                $base[$field] = $index;

                continue;
            }

            if ($code !== null) {
                if (isset($components[$code])) {
                    $warnings[] = self::duplicateColumnWarning($text, $index, self::columnLabel($code), $components[$code]);

                    continue;
                }

                $components[$code] = $index;

                continue;
            }

            $unclaimed[$index] = $text;
        }

        // Judul yang tidak dikenali di posisi template dibaca sesuai posisi (seperti import lama), hanya bila kolom
        // yang dikenali tidak bergeser dari posisi template. ID karyawan dan Nama Lengkap hanya ditebak bila
        // keduanya tidak dikenali, agar baris tidak masuk ke karyawan lain.
        if (self::followsTemplateOrder($base, $components)) {
            $guessIdentity = ! isset($base[self::FIELD_EMPLOYEE_ID]) && ! isset($base[self::FIELD_NAME]);

            foreach (self::GUESSABLE_BASE_FIELDS as $field) {
                $position = self::BASE_POSITIONS[$field];
                $isIdentity = $field !== self::FIELD_WORK_DAYS;

                if (! isset($base[$field]) && array_key_exists($position, $unclaimed) && (! $isIdentity || $guessIdentity)) {
                    $warnings[] = self::positionalColumnWarning($unclaimed[$position], $position, self::baseLabel($field));
                    $base[$field] = $position;
                    unset($unclaimed[$position]);
                }
            }

            foreach (self::LEGACY_COMPONENT_POSITIONS as $code => $position) {
                if (! isset($components[$code]) && array_key_exists($position, $unclaimed)) {
                    $warnings[] = self::positionalColumnWarning($unclaimed[$position], $position, self::columnLabel($code));
                    $components[$code] = $position;
                    unset($unclaimed[$position]);
                }
            }
        }

        $errors = [];

        if (! isset($base[self::FIELD_EMPLOYEE_ID]) && ! isset($base[self::FIELD_NAME])) {
            $errors[] = 'Kolom ID karyawan dan Nama Lengkap tidak ditemukan di baris judul.';
        } elseif (! isset($base[self::FIELD_EMPLOYEE_ID])) {
            $warnings[] = 'Kolom ID karyawan tidak ditemukan di baris judul; karyawan dicari dari Nama Lengkap.';
        }

        if (! isset($base[self::FIELD_PERIODE])) {
            $errors[] = 'Kolom Periode tidak ditemukan di baris judul (gunakan judul "Periode" seperti template).';
        }

        if (! isset($base[self::FIELD_WORK_DAYS])) {
            $errors[] = 'Kolom Hari Kerja tidak ditemukan di baris judul (gunakan judul "Hari Kerja" seperti template).';
        }

        return [
            'base' => $base,
            'components' => $components,
            'error' => $errors === [] ? null : implode(' ', $errors),
            'warnings' => $warnings,
            'ignored' => array_values(array_filter($unclaimed, static fn (?string $text): bool => $text !== null)),
            'committed' => false,
        ];
    }

    /**
     * Kolom yang dikenali dari judulnya berada di posisi template: kolom dasar di kolom 1-4, tiga komponen lama di
     * kolom 5-7, komponen lain setelah kolom 7. Bila tidak (mis. ada kolom "No" / "Divisi" tambahan di depan),
     * posisi template sudah tidak menunjuk kolom yang sama sehingga tidak dipakai untuk menebak.
     *
     * @param  array<string, int>  $base
     * @param  array<string, int>  $components
     */
    private static function followsTemplateOrder(array $base, array $components): bool
    {
        foreach ($base as $field => $index) {
            if ($index !== self::BASE_POSITIONS[$field]) {
                return false;
            }
        }

        foreach ($components as $code => $index) {
            $position = self::LEGACY_COMPONENT_POSITIONS[$code] ?? null;

            if ($position !== null ? $index !== $position : $index < self::POSITIONAL_COLUMN_COUNT) {
                return false;
            }
        }

        return true;
    }

    private static function positionalColumnWarning(?string $heading, int $index, string $label): string
    {
        $column = 'kolom ke-'.($index + 1);
        $source = $heading === null ? ucfirst($column).' tanpa judul' : "Kolom \"{$heading}\" ({$column}) tidak dikenali dan";

        return "{$source} dibaca sebagai {$label} sesuai urutan template.";
    }

    private static function duplicateColumnWarning(string $heading, int $index, string $label, int $firstIndex): string
    {
        return "Kolom \"{$heading}\" (kolom ke-".($index + 1).") diabaikan karena {$label} sudah dibaca dari kolom ke-".($firstIndex + 1).'.';
    }

    /**
     * Judul ternormalisasi => field dasar, dan => kode komponen (null = nama dipakai lebih dari satu komponen).
     * Prioritas komponen (yang kanan menang): variasi umum judul komponen master < nama / "nama (satuan)" < kode
     * komponen < judul template/lama tiga komponen lama. Judul kolom 5-7 template ('Late Less 30 min', 'Sakit', ...)
     * tetap milik komponen lama walaupun ada komponen master berkode sama (mis. "sakit"); kolom komponen itu
     * dikenali dari judul "Nama [kode]"-nya.
     *
     * @return array{base: array<string, string>, components: array<string, string|null>, legacy: array<string, true>, names: array<string, string|null>}
     */
    private function headingAliases(): array
    {
        if ($this->headingAliases !== null) {
            return $this->headingAliases;
        }

        $base = [];
        foreach (self::BASE_HEADING_ALIASES as $field => $headings) {
            foreach ($headings as $heading) {
                $base[self::normalizeHeading($heading)] = $field;
            }
        }

        $components = [];
        foreach (self::COMPONENT_HEADING_ALIASES as $code => $headings) {
            if (ScoringRuleService::component($code) !== null) {
                foreach ($headings as $heading) {
                    $components[self::normalizeHeading($heading)] = $code;
                }
            }
        }

        $names = [];
        foreach (ScoringRuleService::components() as $code => $component) {
            foreach ([$component->name, "{$component->name} ({$component->unit})"] as $heading) {
                $normalized = self::normalizeHeading($heading);
                $names[$normalized] = array_key_exists($normalized, $names) && $names[$normalized] !== $code
                    ? null
                    : $code;
            }
        }

        $components = array_replace($components, $names);

        foreach (ScoringRuleService::components()->keys() as $code) {
            $components[self::normalizeHeading($code)] = $code;
        }

        $legacy = [];
        foreach (self::LEGACY_HEADING_ALIASES as $code => $headings) {
            foreach ($headings as $heading) {
                $components[self::normalizeHeading($heading)] = $code;
                $legacy[self::normalizeHeading($heading)] = true;
            }
        }

        return $this->headingAliases = ['base' => $base, 'components' => $components, 'legacy' => $legacy, 'names' => $names];
    }

    /**
     * Kode komponen dari judul "Nama [kode]" (template/export). Judul yang persis nama komponen lain yang
     * kebetulan diakhiri "[kode]" (mis. komponen "Tugas [dl]" di samping komponen berkode dl) dibaca lewat
     * namanya (null), kecuali judul itu persis judul template komponen berkode tersebut.
     *
     * @param  array{names: array<string, string|null>}  $aliases
     */
    private function headingCode(string $text, string $normalized, array $aliases): ?string
    {
        $code = $this->bracketCode($text);

        if ($code === null || ! array_key_exists($normalized, $aliases['names'])) {
            return $code;
        }

        $component = ScoringRuleService::component($code);

        return $component !== null && self::normalizeHeading(AttendanceCountInput::spreadsheetHeading($component)) === $normalized
            ? $code
            : null;
    }

    /**
     * Kode komponen yang dikenal dari judul "Nama [kode]" (template/export); null bila bukan pola itu.
     */
    private function bracketCode(string $heading): ?string
    {
        $code = AttendanceCountInput::codeFromHeading($heading);

        return $code !== null && ScoringRuleService::component($code) !== null ? $code : null;
    }

    /**
     * Baris judul: minimal dua sel berjudul kolom template / "Nama [kode]", atau sel berjudul Periode di baris
     * berisi minimal tiga teks yang bukan nilai data (angka, tanggal, periode). Label "Periode | November 2026"
     * di atas tabel dan baris data karyawan bernama mis. "Bulan" bukan baris judul.
     *
     * @param  array<int, mixed>  $row
     */
    private function isHeadingRow(array $row): bool
    {
        $aliases = $this->headingAliases();
        $recognized = 0;
        $filled = 0;
        $hasPeriode = false;
        $hasDataValue = false;

        foreach ($row as $cell) {
            if ($cell === null || (is_scalar($cell) && self::clean((string) $cell) === '')) {
                continue;
            }

            $filled++;

            if (! is_string($cell) || is_numeric(self::clean($cell)) || Periode::normalize(self::clean($cell)) !== null) {
                $hasDataValue = true;

                continue;
            }

            $normalized = self::normalizeHeading($cell);
            $field = $aliases['base'][$normalized] ?? null;
            $hasPeriode = $hasPeriode || $field === self::FIELD_PERIODE;

            if ($field !== null || isset($aliases['legacy'][$normalized]) || $this->bracketCode($cell) !== null) {
                $recognized++;
            }
        }

        return $recognized >= 2 || ($hasPeriode && $filled >= 3 && ! $hasDataValue);
    }

    /**
     * Baris yang belum diisi: Hari Kerja dan semua kolom komponen kosong (kolom identitas/periode diabaikan).
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $base
     * @param  array<string, int>  $componentColumns
     */
    private static function isUnfilledTemplateRow(array $row, array $base, array $componentColumns): bool
    {
        foreach ([$base[self::FIELD_WORK_DAYS] ?? null, ...array_values($componentColumns)] as $index) {
            $value = self::cell($row, $index);

            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private static function isBlankRow(array $row): bool
    {
        return ! self::hasValuesFrom($row, 0);
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private static function hasValuesFrom(array $row, int $fromIndex): bool
    {
        foreach ($row as $index => $cell) {
            if ($index >= $fromIndex && $cell !== null && (! is_scalar($cell) || self::clean((string) $cell) !== '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private static function cell(array $row, ?int $index): mixed
    {
        if ($index === null) {
            return null;
        }

        $value = $row[$index] ?? null;

        return is_string($value) ? self::clean($value) : $value;
    }

    /**
     * Teks tanpa spasi di awal/akhir, termasuk spasi tak terputus (U+00A0) dan karakter tak terlihat (zero-width
     * space, BOM) yang sering ikut tersalin dari laporan web/HRIS dan tidak dibuang trim().
     */
    private static function clean(string $value): string
    {
        return preg_replace('/^[\s\x{200B}\x{FEFF}]+|[\s\x{200B}\x{FEFF}]+$/u', '', $value) ?? trim($value);
    }

    /**
     * Judul ternormalisasi: huruf kecil, tanpa spasi/garis bawah/titik/strip/titik dua, '≤'/'≥' => '<='/'>='.
     */
    private static function normalizeHeading(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $text = str_replace(['≤', '≥'], ['<=', '>='], mb_strtolower(trim((string) $value)));

        return (string) preg_replace('/[\s_.\-:]+/u', '', $text);
    }

    /**
     * Judul template kolom dasar, mis. 'Hari Kerja'.
     */
    private static function baseLabel(string $field): string
    {
        return AttendanceImportTemplateExport::LEGACY_HEADINGS[self::BASE_POSITIONS[$field]];
    }

    /**
     * Nama kolom komponen untuk pesan: judul template untuk tiga komponen lama (mis. 'Sakit or Izin'),
     * nama komponen di master untuk komponen lain.
     */
    private static function columnLabel(string $code): string
    {
        if (isset(self::LEGACY_COMPONENT_POSITIONS[$code])) {
            return AttendanceImportTemplateExport::LEGACY_HEADINGS[self::LEGACY_COMPONENT_POSITIONS[$code]];
        }

        return ScoringRuleService::component($code)->name ?? $code;
    }

    /**
     * @param  array<string, true>  $periodes
     */
    private static function periodeList(array $periodes): string
    {
        $list = array_keys($periodes);
        sort($list);

        return implode(', ', $list);
    }

    /**
     * @param  array<int|string>|null  $ids
     * @return array<int, true>|null
     */
    private static function idSet(?array $ids): ?array
    {
        return $ids === null
            ? null
            : array_fill_keys(array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids))), true);
    }

    /**
     * Kunci pencarian karyawan: teks yang di-trim; angka bulat dari Excel (mis. 1001.0) menjadi "1001".
     * Juga dipakai EmployeeReviewImport.
     */
    public static function lookupKey(mixed $value): ?string
    {
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $key = self::clean((string) $value);

        return $key === '' ? null : $key;
    }

    /**
     * Periode 'Y-m' dari teks (Periode::normalize: '2026-11', '2026-1', '2026-11-01', ...), tanggal Excel
     * (angka serial / DateTime), atau teks 'd/m/Y' / 'd/m/y' (format lama). Tahun di luar 2000-2100 ditolak.
     * Tidak bergantung pada tanggal hari ini (tidak ada bulan yang bergeser pada tanggal 29-31). Juga dipakai
     * EmployeeReviewImport.
     *
     * @return array{0: ?string, 1: ?string} [periode, alasan bila ditolak]
     */
    public static function parsePeriode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [null, 'Periode kosong. Isi kolom Periode dengan format YYYY-MM (mis. 2026-11).'];
        }

        $candidate = $value;

        try {
            if ($value instanceof DateTimeInterface) {
                $candidate = $value->format('Y-m-d');
            } elseif (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                // Sel tanggal Excel tersimpan sebagai angka serial (format dibaca tanpa zona waktu).
                $candidate = Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } elseif (is_string($value) && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2}|\d{4})$#D', $value, $matches) === 1) {
                $year = (int) $matches[3];
                $year = strlen($matches[3]) === 2 ? ($year < 70 ? 2000 + $year : 1900 + $year) : $year;
                $candidate = checkdate((int) $matches[2], (int) $matches[1], $year)
                    ? sprintf('%04d-%02d-%02d', $year, (int) $matches[2], (int) $matches[1])
                    : null;
            }
        } catch (Throwable) {
            $candidate = null;
        }

        $label = is_scalar($value) ? (string) $value : get_debug_type($value);
        $periode = Periode::normalize($candidate);

        if ($periode === null) {
            return [null, "Periode \"{$label}\" tidak dikenali. Gunakan format YYYY-MM (mis. 2026-11)."];
        }

        if (! AttendanceCountInput::isPeriodeInRange($periode)) {
            return [null, "Periode \"{$label}\" terbaca sebagai {$periode}, di luar rentang tahun ".AttendanceCountInput::MIN_YEAR.'-'.AttendanceCountInput::MAX_YEAR.'. Pastikan kolom Periode terisi dan urutan kolom sesuai template.'];
        }

        return [$periode, null];
    }

    /**
     * Hitungan komponen: bilangan bulat 0..MAX_COUNT; kosong = 0; selain itu null (tidak valid).
     */
    private static function parseCount(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $count = self::parseInteger($value);

        return $count !== null && $count >= 0 && $count <= AttendanceCountInput::MAX_COUNT ? $count : null;
    }

    /**
     * Bilangan bulat dari sel (angka atau teks angka, mis. "3" / 3.0); selain itu null.
     */
    private static function parseInteger(mixed $value): ?int
    {
        if (is_string($value) && is_numeric($value)) {
            $value += 0;
        }

        // Batas 9e18 mencegah luapan saat float diubah ke int (nilai sebesar itu tetap ditolak batas hitungan).
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 9.0E18) {
            $value = (int) $value;
        }

        return is_int($value) ? $value : null;
    }
}
