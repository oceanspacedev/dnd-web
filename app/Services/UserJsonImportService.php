<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserJsonImportService
{
    /**
     * Import users from a JSON file path or string payload.
     *
     * @param string $jsonFilePath
     * @return array
     */
    public static function importFromFile(string $jsonFilePath): array
    {
        if (!file_exists($jsonFilePath)) {
            return [
                'success' => false,
                'message' => 'File JSON tidak ditemukan di server.',
                'success_count' => 0,
                'error_count' => 1,
                'errors' => ['File tidak ditemukan di path: ' . $jsonFilePath],
            ];
        }

        $content = file_get_contents($jsonFilePath);
        return static::importFromContent($content);
    }

    /**
     * Import users from raw JSON content.
     *
     * @param string $content
     * @return array
     */
    public static function importFromContent(string $content): array
    {
        // 1. Strip UTF-8 / UTF-16 BOM & invisible control characters
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = preg_replace('/^\xFE\xFF|^\xFF\xFE/', '', $content);
        $content = trim($content);

        if ($content === '') {
            return [
                'success' => false,
                'message' => 'File JSON kosong (0 byte).',
                'success_count' => 0,
                'error_count' => 1,
                'errors' => ['File JSON kosong.'],
            ];
        }

        // Detect non-JSON error content (e.g. "404: Not Found" or HTML error pages)
        if (str_contains(strtolower($content), '404: not found') || str_contains(strtolower($content), '404 not found')) {
            Log::error('UserJsonImport Uploaded file contains 404 error text');
            return [
                'success' => false,
                'message' => 'File yang diunggah berisi teks "404: Not Found" (bukan data JSON karyawan). Mohon periksa kembali file ekspor JSON Talenta Anda.',
                'success_count' => 0,
                'error_count' => 1,
                'errors' => ['File berisi teks 404: Not Found.'],
            ];
        }

        if (str_starts_with(strtolower($content), '<!doctype html') || str_starts_with(strtolower($content), '<html')) {
            Log::error('UserJsonImport Uploaded file is HTML');
            return [
                'success' => false,
                'message' => 'File yang diunggah adalah halaman web HTML (bukan file JSON karyawan). Mohon pastikan mengunggah file bertipe .json yang benar.',
                'success_count' => 0,
                'error_count' => 1,
                'errors' => ['File berformat HTML.'],
            ];
        }

        // 2. Try JSON decode
        $decoded = json_decode($content, true);

        // 3. Fallback repair for common trailing commas
        if (json_last_error() !== JSON_ERROR_NONE) {
            $repaired = preg_replace('/,\s*([\]}])/', '$1', $content);
            $decoded = json_decode($repaired, true);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = json_last_error_msg();
            Log::error('UserJsonImport Invalid JSON content sample: ' . substr($content, 0, 200));

            return [
                'success' => false,
                'message' => 'Format JSON tidak valid: ' . $errorMsg . '. Pastikan file bertipe .json yang berisi data karyawan.',
                'success_count' => 0,
                'error_count' => 1,
                'errors' => ['Format JSON tidak valid: ' . $errorMsg],
            ];
        }

        // 4. Unwrap standard wrappers (e.g. {"status": "success", "data": {"data": [...]}})
        $rows = static::extractRows($decoded);

        if (empty($rows)) {
            return [
                'success' => false,
                'message' => 'Tidak ada data karyawan ditemukan dalam file JSON.',
                'success_count' => 0,
                'error_count' => 0,
                'errors' => [],
            ];
        }

        $successCount = 0;
        $errors = [];
        $rowNum = 0;
        $defaultPasswordHash = Hash::make('complete123');

        foreach ($rows as $row) {
            $rowNum++;
            if (!is_array($row)) {
                continue;
            }

            $namaLengkap = '';
            try {
                // Extract fields with smart aliases (supporting Talenta, standard HR JSON, and DB exports)
                $namaLengkap = trim($row['full_name'] ?? $row['nama_lengkap'] ?? $row['name'] ?? $row['employee_name'] ?? '');
                if ($namaLengkap === '') {
                    $namaLengkap = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                }
                if ($namaLengkap === '') {
                    $errors[] = "Baris #{$rowNum}: Nama Lengkap wajib diisi.";
                    continue;
                }

                $employeeId = trim((string) ($row['employee_id'] ?? $row['id_employee'] ?? $row['emp_id'] ?? $row['id_karyawan'] ?? $row['nip'] ?? ''));
                $email = trim((string) ($row['email'] ?? $row['email_address'] ?? ''));
                $noHp = static::normalizePhoneNumber($row['mobile_phone'] ?? $row['no_hp'] ?? $row['phone'] ?? $row['phone_number'] ?? $row['no_telepon'] ?? '');

                // Username extraction / generation
                $rawUsername = trim((string) ($row['username'] ?? ''));
                if ($rawUsername === '') {
                    if ($employeeId !== '') {
                        $rawUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $employeeId));
                    } else {
                        $rawUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '.', $namaLengkap)));
                    }
                }
                $username = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', $rawUsername));

                // Area lookup / create
                $areaName = strtoupper(trim((string) ($row['organization'] ?? $row['area'] ?? $row['nama_area'] ?? $row['branch'] ?? 'HEAD OFFICE')));
                $area = Area::whereRaw('LOWER(name) = ?', [strtolower($areaName)])->first()
                    ?: Area::firstOrCreate(['name' => $areaName]);

                // Divisi lookup / create
                $divisiName = strtoupper(trim((string) ($row['department'] ?? $row['divisi'] ?? $row['nama_divisi'] ?? $row['branch'] ?? 'GENERAL')));
                $divisi = Divisi::where('name', $divisiName)->where('area_id', $area->id)->first()
                    ?: Divisi::firstOrCreate([
                        'name' => $divisiName,
                        'area_id' => $area->id,
                    ]);

                // Role lookup (flexible match against DB roles: STAFF, TEAM LEADER, COORDINATOR, MANAGER, etc.)
                $roleInput = strtoupper(trim((string) ($row['title'] ?? $row['role'] ?? $row['role_name'] ?? $row['designation'] ?? $row['jabatan'] ?? 'STAFF')));
                $role = Role::whereRaw('LOWER(name) = ?', [strtolower($roleInput)])
                    ->orWhereRaw('LOWER(REPLACE(name, " ", "")) = ?', [strtolower(str_replace(' ', '', $roleInput))])
                    ->first();
                if (!$role) {
                    $role = Role::where('name', 'STAFF')->first() ?: Role::firstOrCreate(['name' => $roleInput]);
                }

                // Position lookup / create
                $positionName = strtoupper(trim((string) ($row['job'] ?? $row['job_position'] ?? $row['position'] ?? $row['posisi'] ?? $row['title'] ?? 'Staff')));
                $position = Position::firstOrCreate(['name' => $positionName]);

                // Approval user lookup (optional)
                $approvalId = null;
                $approvalTarget = trim((string) ($row['approval_line'] ?? $row['approval'] ?? $row['atasan'] ?? ''));
                if ($approvalTarget !== '') {
                    $appUser = User::where('nama_lengkap', $approvalTarget)
                        ->orWhere('username', strtolower($approvalTarget))
                        ->orWhere('employee_id', $approvalTarget)
                        ->first();
                    if ($appUser) {
                        $approvalId = $appUser->id;
                    }
                }

                // Check existing user to preserve password if updating (check employee_id, username, email)
                $existingUser = null;
                if ($employeeId !== '') {
                    $existingUser = User::withTrashed()->where('employee_id', $employeeId)->first();
                }
                if (!$existingUser && $username !== '') {
                    $existingUser = User::withTrashed()->where('username', $username)->first();
                }
                if (!$existingUser && $email !== '') {
                    $existingUser = User::withTrashed()->where('email', $email)->first();
                }

                if ($existingUser && $existingUser->trashed()) {
                    $existingUser->restore();
                }

                $userData = [
                    'employee_id' => $employeeId ?: ($existingUser?->employee_id),
                    'nama_lengkap' => strtoupper($namaLengkap),
                    'email' => $email ?: ($existingUser?->email),
                    'no_hp' => $noHp ?: ($existingUser?->no_hp),
                    'area_id' => $area->id,
                    'divisi_id' => $divisi->id,
                    'role_id' => $role->id,
                    'position_id' => $position->id,
                    'approval_id' => $approvalId ?: ($existingUser?->approval_id),
                    'dr' => 0,
                    'wn' => 0,
                    'wr' => 0,
                    'mn' => 0,
                    'mr' => 0,
                ];

                if (!$existingUser) {
                    $userData['username'] = $username;
                    $userData['password'] = $defaultPasswordHash;
                    User::create($userData);
                } else {
                    $existingUser->update($userData);
                }

                $successCount++;
            } catch (\Throwable $e) {
                Log::error("UserJsonImport Error on row #{$rowNum}: " . $e->getMessage());
                $errors[] = "Baris #{$rowNum} (" . ($namaLengkap ?: 'Karyawan') . "): " . $e->getMessage();
            }
        }

        return [
            'success' => true,
            'message' => "Import JSON Selesai. Berhasil: {$successCount} karyawan.",
            'success_count' => $successCount,
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Normalize Indonesian phone numbers to domestic format 08...
     */
    protected static function normalizePhoneNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $phone = preg_replace('/\D+/', '', (string) $value);
        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '0062')) {
            $phone = '0' . substr($phone, 4);
        } elseif (str_starts_with($phone, '62')) {
            $phone = '0' . substr($phone, 2);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '0' . $phone;
        }

        return $phone;
    }

    /**
     * Robustly extract a flat list of employee rows from various JSON structures:
     * - Paginated Talenta API response: { data: { data: [ ... ], _links: ... } }
     * - Standard wrapped API: { data: [ ... ] } or { employees: [ ... ] } or { users: [ ... ] }
     * - Bare list of objects: [ { ... }, { ... } ]
     * - Key-value map of employees: { "0": { ... }, "emp_1": { ... } }
     * - Single employee object: { "full_name": "...", ... }
     */
    public static function extractRows(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        // 1. Deeply nested wrappers (e.g. Talenta paginated directory)
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            if (isset($decoded['data']['data']) && is_array($decoded['data']['data'])) {
                return array_values($decoded['data']['data']);
            }
            if (isset($decoded['data']['employees']) && is_array($decoded['data']['employees'])) {
                return array_values($decoded['data']['employees']);
            }
            if (isset($decoded['data']['users']) && is_array($decoded['data']['users'])) {
                return array_values($decoded['data']['users']);
            }
            if (isset($decoded['data']['items']) && is_array($decoded['data']['items'])) {
                return array_values($decoded['data']['items']);
            }
            if (array_is_list($decoded['data'])) {
                return $decoded['data'];
            }
            if (static::isEmployeeRow($decoded['data'])) {
                return [$decoded['data']];
            }
            $first = reset($decoded['data']);
            if (is_array($first) && static::isEmployeeRow($first)) {
                return array_values($decoded['data']);
            }
        }

        // 2. Named wrapper collections
        foreach (['employees', 'users', 'items', 'records', 'results', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return array_values($decoded[$key]);
            }
        }

        // 3. Top-level list
        if (array_is_list($decoded)) {
            return $decoded;
        }

        // 4. Single employee object
        if (static::isEmployeeRow($decoded)) {
            return [$decoded];
        }

        // 5. Map of employee objects
        $first = reset($decoded);
        if (is_array($first) && static::isEmployeeRow($first)) {
            return array_values($decoded);
        }

        return array_values($decoded);
    }

    /**
     * Check whether an array represents an individual employee object.
     */
    protected static function isEmployeeRow(array $data): bool
    {
        $candidateKeys = [
            'full_name', 'nama_lengkap', 'name', 'employee_name', 'first_name', 'last_name',
            'employee_id', 'id_employee', 'emp_id', 'id_karyawan', 'nip',
            'email', 'mobile_phone', 'no_hp', 'phone', 'job', 'position', 'posisi',
        ];

        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }
}
