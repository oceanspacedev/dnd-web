<?php

namespace App\Imports;

use App\Models\EmployeeReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import penilaian karyawan dari template Penilaian (EmployeeReviewImportTemplateExport).
 *
 * - Periode dibaca seperti import Kehadiran (AttendanceImport::parsePeriode): teks 'YYYY-MM', tanggal Excel, atau
 *   teks 'd/m/Y' / 'd/m/y'; tahun 2000-2100. Hasilnya tidak bergantung pada tanggal hari ini: template September
 *   yang diimport 31 Oktober tetap masuk ke September (bukan bergeser ke Oktober), dan bulan 13 ditolak.
 * - Baris template yang keempat aspeknya kosong belum dinilai: diabaikan dan dihitung terpisah (unfilledCount),
 *   bukan disimpan sebagai penilaian 0 yang menghalangi penilaian sebenarnya ("sudah ada"). Aspek kosong pada baris
 *   yang sudah dinilai sebagian tetap 0, seperti sebelumnya. Baris tanpa ID dan nama tidak dihitung.
 * - Karyawan dicari seperti import Kehadiran: ID karyawan di antara seluruh karyawan (termasuk yang di luar cakupan
 *   dan yang sudah dihapus), sehingga ID milik karyawan lain tidak pernah dicocokkan ulang lewat nama. Nama Lengkap
 *   hanya dipakai bila ID kosong / tidak dikenal, dan nama yang dipakai lebih dari satu karyawan ditolak. Import
 *   terbatas (bukan super admin): karyawan di luar cakupan diperlakukan seperti tidak ada, dan ID yang diisi harus
 *   ID karyawan dalam cakupan.
 * - Penilaian karyawan + periode yang sudah ada tidak ditimpa.
 */
class EmployeeReviewImport implements ToModel, WithHeadingRow
{
    private const ASPECTS = ['responsiveness', 'problem_solver', 'helpfulness', 'initiative'];

    /** Juga dipakai untuk karyawan di luar cakupan user, agar keberadaannya tidak terbaca dari alasan penolakan. */
    private const NOT_FOUND_REASON = 'Karyawan tidak ditemukan. Periksa ID karyawan dan Nama Lengkap.';

    /** @var array<string, array{id: int, deleted: bool}> employee_id => pemilik (seluruh karyawan, termasuk yang dihapus) */
    private array $usersByEmployeeId = [];

    /** @var array<string, list<int>> nama_lengkap => user id karyawan aktif (lebih dari satu = nama dipakai beberapa karyawan) */
    private array $usersByName = [];

    /** @var array<int, true> user id karyawan aktif yang bisa dipilih lewat ID karyawan */
    private array $usersWithEmployeeId = [];

    /** @var array<int, true>|null null = seluruh karyawan */
    private ?array $allowedUserIds = null;

    private $importedCount = 0;

    private $skippedCount = 0;

    /** Baris template yang belum dinilai (diabaikan, bukan gagal). */
    private int $unfilledCount = 0;

    private $skippedDetails = []; // Array untuk menyimpan detail data yang dilewati

    /**
     * @param  array<int|string>|null  $allowedUserIds  null = seluruh karyawan; selain itu hanya karyawan yang boleh dinilai user.
     */
    public function __construct(?array $allowedUserIds = null)
    {
        $this->allowedUserIds = $allowedUserIds === null
            ? null
            : array_fill_keys(array_values(array_unique(array_map(static fn ($id): int => (int) $id, $allowedUserIds))), true);

        // Dua peta terpisah (bukan array_merge, yang menomori ulang employee_id numerik), dan nama yang dipakai
        // beberapa karyawan disimpan semuanya (bukan karyawan terakhir yang menang).
        foreach (User::withTrashed()->get(['id', 'nama_lengkap', 'employee_id', 'deleted_at']) as $user) {
            $deleted = $user->deleted_at !== null;

            if (($employeeId = AttendanceImport::lookupKey($user->employee_id)) !== null
                && (! isset($this->usersByEmployeeId[$employeeId]) || $this->usersByEmployeeId[$employeeId]['deleted'])) {
                $this->usersByEmployeeId[$employeeId] = ['id' => (int) $user->id, 'deleted' => $deleted];
            }

            if (! $deleted && ($name = AttendanceImport::lookupKey($user->nama_lengkap)) !== null) {
                $this->usersByName[$name][] = (int) $user->id;
            }
        }

        foreach ($this->usersByEmployeeId as $owner) {
            if (! $owner['deleted']) {
                $this->usersWithEmployeeId[$owner['id']] = true;
            }
        }
    }

    public function model(array $row): Model|array|null
    {
        $employeeId = $row['id_karyawan'] ?? null;
        $name = $row['nama_lengkap'] ?? null;
        $rawPeriode = $row['periode'] ?? null;

        // Template berisi semua karyawan yang boleh dinilai; baris yang belum dinilai bukan penilaian 0.
        if ($this->isUnfilled($row)) {
            if (AttendanceImport::lookupKey($employeeId) !== null || AttendanceImport::lookupKey($name) !== null) {
                $this->unfilledCount++;
            }

            return null;
        }

        [$userId, $userError] = $this->resolveUser($employeeId, $name);

        if ($userId === null) {
            return $this->skip($employeeId, $name, $rawPeriode, (string) $userError);
        }

        [$periode, $periodeError] = AttendanceImport::parsePeriode($rawPeriode);

        if ($periode === null) {
            return $this->skip($employeeId, $name, $rawPeriode, (string) $periodeError);
        }

        // Skala penilaian 0-5 per aspek (sama dengan form panel); nilai di luar skala membuat skor
        // Penilaian melebihi bobotnya di aturan penilaian.
        $scores = $this->reviewScores($row);

        if ($scores === null) {
            return $this->skip($employeeId, $name, $periode, 'Nilai penilaian harus bilangan bulat 0 sampai 5 untuk setiap aspek.');
        }

        $existingReview = EmployeeReview::where('user_id', $userId)
            ->where('periode', $periode)
            ->exists();

        if ($existingReview) {
            return $this->skip($employeeId, $name, $periode, 'Penilaian karyawan ini untuk periode tersebut sudah ada.');
        }

        $this->importedCount++;

        return new EmployeeReview([
            'user_id' => $userId,
            'periode' => $periode,
            ...$scores,
        ]);
    }

    /**
     * Karyawan baris ini: [user id, null] atau [null, alasan ditolak]. Sama dengan AttendanceImport::resolveUser.
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function resolveUser(mixed $employeeId, mixed $name): array
    {
        $idKey = AttendanceImport::lookupKey($employeeId);

        if ($idKey !== null && isset($this->usersByEmployeeId[$idKey])) {
            $owner = $this->usersByEmployeeId[$idKey];

            if (! $this->isAllowedUser($owner['id'])) {
                return [null, self::NOT_FOUND_REASON];
            }

            return $owner['deleted']
                ? [null, "ID karyawan \"{$idKey}\" milik karyawan yang sudah dihapus."]
                : [$owner['id'], null];
        }

        // Import terbatas: ID yang diisi harus ID karyawan dalam cakupan (tidak dicocokkan ulang lewat nama), agar
        // ID karyawan badan usaha lain tidak bisa dibedakan dari ID yang tidak ada.
        if ($idKey !== null && $this->allowedUserIds !== null) {
            return [null, self::NOT_FOUND_REASON];
        }

        $nameKey = AttendanceImport::lookupKey($name);
        $candidates = $nameKey === null ? [] : ($this->usersByName[$nameKey] ?? []);
        $allowedCandidates = array_values(array_filter($candidates, fn (int $userId): bool => $this->isAllowedUser($userId)));

        // Nama karyawan dalam cakupan yang juga dipakai karyawan lain ditolak agar penilaian tidak masuk ke karyawan
        // yang salah.
        if ($nameKey !== null && count($candidates) > 1 && $allowedCandidates !== []) {
            return [null, $this->ambiguousNameReason($nameKey, $idKey, $allowedCandidates)];
        }

        return $allowedCandidates !== [] ? [$allowedCandidates[0], null] : [null, self::NOT_FOUND_REASON];
    }

    private function isAllowedUser(int $userId): bool
    {
        return $this->allowedUserIds === null || isset($this->allowedUserIds[$userId]);
    }

    /**
     * Karyawan yang belum memiliki ID karyawan tidak bisa dipilih lewat kolom ID (ID apa pun yang diisi tidak dikenal),
     * jadi ID-nya harus dilengkapi di data karyawan dulu.
     *
     * @param  list<int>  $allowedCandidates
     */
    private function ambiguousNameReason(string $nameKey, ?string $idKey, array $allowedCandidates): string
    {
        $withoutEmployeeId = array_filter($allowedCandidates, fn (int $userId): bool => ! isset($this->usersWithEmployeeId[$userId]));

        if (count($withoutEmployeeId) === count($allowedCandidates)) {
            return "Nama \"{$nameKey}\" dipakai lebih dari satu karyawan, dan ID karyawan untuk nama ini belum diisi di data karyawan. "
                .'Lengkapi ID karyawan di data karyawan (menu Karyawan / Tim Saya) lalu isi kolom ID karyawan di file, atau isi penilaiannya lewat form Penilaian.';
        }

        return "Nama \"{$nameKey}\" dipakai lebih dari satu karyawan. ".($idKey === null ? 'Isi' : 'Perbaiki')
            .' kolom ID karyawan agar penilaian tidak masuk ke karyawan lain.'
            .($withoutEmployeeId !== [] ? ' Karyawan bernama sama yang belum memiliki ID karyawan perlu dilengkapi dulu ID-nya di data karyawan.' : '');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isUnfilled(array $row): bool
    {
        foreach (self::ASPECTS as $aspect) {
            if (! self::isBlank($row[$aspect] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function skip(mixed $employeeId, mixed $name, mixed $periode, string $reason): null
    {
        Log::info('Import EmployeeReview: baris dilewati', [
            'employee_id' => $employeeId,
            'nama_lengkap' => $name,
            'periode' => $periode,
            'reason' => $reason,
        ]);

        $this->skippedDetails[] = [
            'nama_lengkap' => $name ?? '',
            'employee_id' => $employeeId ?? '',
            'periode' => $periode ?? 'Tidak diketahui',
            'reason' => $reason,
        ];

        $this->skippedCount++;

        return null;
    }

    /**
     * Nilai empat aspek penilaian sebagai bilangan bulat 0-5 (kosong = 0, seperti sebelumnya); null bila ada
     * nilai di luar skala atau bukan bilangan bulat.
     *
     * @param  array<string, mixed>  $row
     * @return array{responsiveness: int, problem_solver: int, helpfulness: int, initiative: int}|null
     */
    private function reviewScores(array $row): ?array
    {
        $scores = [];

        foreach (self::ASPECTS as $aspect) {
            $value = $row[$aspect] ?? null;

            if (self::isBlank($value)) {
                $scores[$aspect] = 0;

                continue;
            }

            if (! is_numeric($value) || (float) $value != (int) $value || (int) $value < 0 || (int) $value > 5) {
                return null;
            }

            $scores[$aspect] = (int) $value;
        }

        return $scores;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @return array{importedCount: int, skippedCount: int, unfilledCount: int, skippedDetails: list<array{nama_lengkap: mixed, employee_id: mixed, periode: mixed, reason: string}>}
     */
    public function getImportSummary()
    {
        return [
            'importedCount' => $this->importedCount,
            'skippedCount' => $this->skippedCount,
            'unfilledCount' => $this->unfilledCount,
            'skippedDetails' => $this->skippedDetails, // Menyertakan detail data yang dilewati
        ];
    }
}
