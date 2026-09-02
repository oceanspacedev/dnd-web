<?php

namespace App\Imports;

use DateTime;
use Exception;
use App\Models\Attendance;
use App\Models\User;
use Maatwebsite\Excel\Concerns\ToModel;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class AttendanceImport implements ToModel
{
    private $usersCache = [];
    private ?array $allowedUserIds = null;
    private $importedCount = 0;
    private $skippedCount = 0;
    private $skippedDetails = []; // Array untuk menyimpan detail data yang dilewati

    public function __construct(?array $allowedUserIds = null)
    {
        $normalizedAllowedUserIds = $allowedUserIds === null
            ? null
            : array_values(array_unique(array_map(static fn ($id) => (int) $id, $allowedUserIds)));

        $this->allowedUserIds = $normalizedAllowedUserIds === null
            ? null
            : array_fill_keys($normalizedAllowedUserIds, true);

        $usersQuery = User::query()->whereNull('deleted_at');
        if ($normalizedAllowedUserIds !== null) {
            if ($normalizedAllowedUserIds === []) {
                $usersQuery->whereIn('id', []);
            } else {
                $usersQuery->whereIn('id', $normalizedAllowedUserIds);
            }
        }

        $users = $usersQuery->get(['id', 'nama_lengkap', 'employee_id']);

        // Cache semua pengguna berdasarkan 'nama_lengkap' dan 'employee_id' untuk menghindari query database berulang
        $this->usersCache = $users
            ->pluck('id', 'nama_lengkap')
            ->toArray();
        $employeeCache = $users
            ->pluck('id', 'employee_id')
            ->filter()
            ->toArray();

        // Gabungkan cache berdasarkan 'nama_lengkap' dan 'employee_id'
        $this->usersCache = array_merge($this->usersCache, $employeeCache);
    }

    public function model(array $row)
    {
        Log::info('Data baris: ', $row);

        // Cek apakah ada employee_id, jika ada gunakan employee_id untuk mencari userId, jika tidak, gunakan nama_lengkap
        $userId = null;

        // Prioritaskan pencarian berdasarkan employee_id terlebih dahulu
        if (!empty($row[0]) && isset($this->usersCache[$row[0]])) {
            // Jika id_karyawan ada (kolom 0), cari berdasarkan employee_id
            $userId = $this->usersCache[$row[0]];
        }

        // Jika tidak ditemukan berdasarkan id_karyawan, coba cari berdasarkan nama_lengkap
        if (is_null($userId) && !empty($row[1]) && isset($this->usersCache[$row[1]])) {
            // Jika nama_lengkap ada (kolom 1), cari berdasarkan nama_lengkap
            $userId = $this->usersCache[$row[1]];
        }

        if ($userId && $this->allowedUserIds !== null && ! isset($this->allowedUserIds[$userId])) {
            $this->skippedDetails[] = [
                'nama_lengkap' => $row[1],
                'employee_id' => $row[0],
                'periode' => $row[2] ?? 'Tidak diketahui',
                'reason' => 'User di luar scope approval Anda',
            ];
            $this->skippedCount++;
            return null;
        }

        // Proses periode
        $periode = null;

        try {
            if (is_numeric($row[2])) {
                $periode = Date::excelToDateTimeObject($row[2])->format('Y-m');
            } elseif (DateTime::createFromFormat('Y-m', $row[2]) !== false) {
                $periode = DateTime::createFromFormat('Y-m', $row[2])->format('Y-m');
            } elseif (DateTime::createFromFormat('d/m/y', $row[2]) !== false) {
                $periode = DateTime::createFromFormat('d/m/y', $row[2])->format('Y-m');
            } else {
                Log::error("Format Periode tidak dikenali: " . $row[2]);
            }
        } catch (Exception $e) {
            Log::error("Kesalahan saat parsing Periode: " . $e->getMessage());
        }

        // Validasi userId dan periode sebelum memproses lebih lanjut
        if ($userId && $periode) {
            // Cek apakah absensi untuk userId dan periode sudah ada
            $existingAttendance = Attendance::where('user_id', $userId)
                ->where('periode', $periode)
                ->exists();

            if ($existingAttendance) {
                Log::info('Melewati absensi yang sudah ada untuk user_id ' . $userId . ' dan periode ' . $periode);

                // Simpan detail data yang dilewati
                $this->skippedDetails[] = [
                    'nama_lengkap' => $row[1],
                    'employee_id' => $row[0],
                    'periode' => $periode,
                ];

                $this->skippedCount++;
                return null;
            }

            $this->importedCount++;
            return new Attendance([
                'user_id' => $userId,
                'periode' => $periode,
                'work_days' => $row[3] ?? 0,
                'late_less_30' => $row[4] ?? 0,
                'late_more_30' => $row[5] ?? 0,
                'sick_days' => $row[6] ?? 0,
            ]);
        }

        Log::error('Import Attendance: Pengguna tidak ditemukan atau gagal parsing Periode untuk nama_lengkap ' . $row[1] . ' atau id_karyawan ' . $row[0]);

        // Simpan detail data yang dilewati jika pengguna tidak ditemukan atau periode tidak valid
        $this->skippedDetails[] = [
            'nama_lengkap' => $row[1],
            'employee_id' => $row[0],
            'periode' => $row[2] ?? 'Tidak diketahui',
        ];

        $this->skippedCount++;
        return null;
    }

    public function getImportSummary()
    {
        return [
            'importedCount' => $this->importedCount,
            'skippedCount' => $this->skippedCount,
            'skippedDetails' => $this->skippedDetails,
        ];
    }
}
