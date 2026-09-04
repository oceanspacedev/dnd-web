<?php

namespace App\Imports;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class UsersImport implements ToModel, WithHeadingRow
{
    protected array $errors = [];

    protected int $rowCounter = 1; // Row 1 is heading row

    protected int $successCounter = 0;

    protected static ?string $defaultPasswordHash = null;

    public function __construct()
    {
        if (static::$defaultPasswordHash === null) {
            static::$defaultPasswordHash = bcrypt('complete123');
        }
    }

    /**
     * @return Model|null
     */
    public function model(array $row): Model|array|null
    {
        $this->rowCounter++;
        $currentRowNum = $row['row_number'] ?? $this->rowCounter;

        try {
            // Flexible field extraction supporting alternate column names
            $namaLengkap = trim((string) $this->getValue($row, ['nama_lengkap', 'nama', 'name']));
            $employeeId = trim((string) $this->getValue($row, ['id_karyawan', 'employee_id']));
            $noHpKeys = ['no_hp', 'hp', 'phone', 'no_telepon', 'telepon'];
            $emailKeys = ['email', 'email_address'];
            $hasNoHp = $this->hasAnyColumn($row, $noHpKeys);
            $hasEmail = $this->hasAnyColumn($row, $emailKeys);
            $noHp = $hasNoHp
                ? $this->normalizeContactAliases(
                    $row,
                    $noHpKeys,
                    fn (mixed $value): ?string => $this->normalizePhoneNumber($value),
                    'No. HP',
                )
                : null;
            $email = $hasEmail
                ? $this->normalizeContactAliases(
                    $row,
                    $emailKeys,
                    fn (mixed $value): ?string => $this->normalizeEmail($value),
                    'Email',
                )
                : null;
            $rawUsername = (string) $this->getValue($row, ['username']);
            $roleInput = trim((string) $this->getValue($row, ['role', 'role_name']));
            $areaInput = trim((string) $this->getValue($row, ['area', 'area_name']));
            $divisiInput = trim((string) $this->getValue($row, ['divisi', 'divisi_name']));

            // Clean & normalize username; auto-generate from nama_lengkap if blank
            if ($rawUsername !== '') {
                $username = strtolower(str_replace('.', '', preg_replace('/\s+/', '', $rawUsername)));
            } elseif ($namaLengkap !== '') {
                $username = strtolower(str_replace('.', '', preg_replace('/\s+/', '', $namaLengkap)));
            } else {
                $username = '';
            }

            // Look up existing user by employee_id first, then by username (including soft-deleted users)
            $user = null;
            $matchedBy = null;
            if ($employeeId !== '') {
                $user = User::withTrashed()->where('employee_id', $employeeId)->first();
                $matchedBy = $user ? 'employee_id' : null;
            }
            if (! $user && $username !== '') {
                $user = User::withTrashed()->where('username', $username)->first();
                $matchedBy = $user ? ($rawUsername !== '' ? 'username' : 'derived_username') : null;
            }

            if ($user && $hasNoHp && $matchedBy === 'derived_username') {
                throw new Exception(
                    'No. HP login user existing hanya boleh diubah melalui ID karyawan atau username eksplisit yang cocok',
                );
            }

            if ($user && $user->trashed()) {
                $user->restore();
            }

            // Role lookup (case-insensitive & whitespace tolerant)
            $role = null;
            if ($roleInput !== '') {
                $role = Role::whereRaw('LOWER(name) = ?', [strtolower($roleInput)])
                    ->orWhereRaw('LOWER(REPLACE(name, " ", "")) = ?', [strtolower(preg_replace('/\s+/', '', $roleInput))])
                    ->first();
            }
            if (! $role) {
                throw new Exception('Role "'.($roleInput ?: '-').'" tidak ditemukan');
            }

            // Area lookup & auto-creation if missing
            $area = null;
            if ($areaInput !== '') {
                $areaNormalized = preg_replace('/\s+/', '', $areaInput);
                $area = Area::where('name', $areaInput)
                    ->orWhere('name', $areaNormalized)
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($areaInput)])
                    ->first();

                if (! $area) {
                    $area = Area::create(['name' => $areaInput]);
                }
            }
            if (! $area) {
                throw new Exception('Area "-" tidak ditemukan');
            }

            // Divisi lookup & auto-creation if missing
            $divisi = null;
            if ($divisiInput !== '') {
                $divisiNormalized = preg_replace('/\s+/', '', $divisiInput);
                $divisi = Divisi::where('name', $divisiInput)
                    ->orWhere('name', $divisiNormalized)
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($divisiInput)])
                    ->first();

                if (! $divisi) {
                    $divisi = Divisi::create([
                        'name' => $divisiInput,
                        'area_id' => $area->id,
                    ]);
                }
            }
            if (! $divisi) {
                throw new Exception('Divisi "-" tidak ditemukan');
            }

            // Approval lookup (case-insensitive on nama_lengkap or employee_id)
            $approvalName = trim((string) $this->getValue($row, ['approval', 'approval_nama_lengkap', 'approval_id']));
            $approval = null;
            if ($approvalName !== '') {
                $approval = User::whereRaw('LOWER(nama_lengkap) = ?', [strtolower($approvalName)])
                    ->orWhere('employee_id', $approvalName)
                    ->first();
            }

            $positionId = $this->resolvePositionId($row);

            if ($user) {
                // Update existing user
                $updateData = [
                    'role_id' => $role->id,
                    'area_id' => $area->id,
                    'divisi_id' => $divisi->id,
                    'approval_id' => $approval ? $approval->id : $user->approval_id,
                    'position_id' => $positionId ?? $user->position_id,
                ];

                if ($employeeId !== '') {
                    $updateData['employee_id'] = $employeeId;
                }
                if ($namaLengkap !== '') {
                    $updateData['nama_lengkap'] = strtoupper($namaLengkap);
                }
                if ($hasNoHp) {
                    $updateData['no_hp'] = $noHp;
                }
                if ($hasEmail) {
                    $updateData['email'] = $email;
                }

                $user->update($updateData);
            } else {
                if ($namaLengkap === '') {
                    throw new Exception('Nama lengkap tidak boleh kosong');
                }

                $passwordInput = $this->getValue($row, ['password']);

                // Create new user directly
                User::create([
                    'nama_lengkap' => strtoupper($namaLengkap),
                    'username' => $username,
                    'employee_id' => $employeeId,
                    'no_hp' => $noHp,
                    'email' => $email,
                    'role_id' => $role->id,
                    'area_id' => $area->id,
                    'divisi_id' => $divisi->id,
                    'dr' => false,
                    'wn' => false,
                    'wr' => false,
                    'mn' => false,
                    'mr' => false,
                    'approval_id' => $approval ? $approval->id : null,
                    'position_id' => $positionId,
                    'password' => ! empty($passwordInput) ? bcrypt($passwordInput) : static::$defaultPasswordHash,
                ]);
            }

            $this->successCounter++;
        } catch (QueryException $e) {
            $this->errors[] = 'SQL Error baris '.$currentRowNum.': '.$e->getMessage();
        } catch (Exception $e) {
            $this->errors[] = 'Baris '.$currentRowNum.': '.$e->getMessage();
        }

        return null;
    }

    /**
     * Get value from row matching any of the candidate keys
     */
    protected function getValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return '';
    }

    /**
     * Determine whether the spreadsheet explicitly included any supported
     * heading, including a heading whose cell value is blank.
     */
    protected function hasAnyColumn(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve aliases without allowing a blank alias to hide a populated one.
     * Multiple populated aliases must normalize to the same value.
     */
    protected function normalizeContactAliases(
        array $row,
        array $keys,
        callable $normalizer,
        string $label,
    ): ?string {
        $normalizedValues = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $value = $row[$key];
            if ($value === null || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $normalized = $normalizer($value);
            if ($normalized !== null) {
                $normalizedValues[$normalized] = true;
            }
        }

        if (count($normalizedValues) > 1) {
            throw new Exception("{$label} memiliki beberapa nilai yang berbeda pada kolom alias");
        }

        return array_key_first($normalizedValues);
    }

    /**
     * Normalize Indonesian WhatsApp numbers to the domestic 08... format.
     */
    protected function normalizePhoneNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
            throw new Exception('No. HP tidak valid');
        }

        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new Exception('No. HP tidak valid');
            }

            $phone = sprintf('%.0f', $value);
        } else {
            $phone = trim((string) $value);
        }

        if ($phone === '') {
            return null;
        }

        // Spreadsheet applications can expose long numeric cells in
        // scientific notation even when they contain a phone number.
        if (preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $phone) === 1) {
            $numericPhone = (float) $phone;
            if (! is_finite($numericPhone) || floor($numericPhone) !== $numericPhone) {
                throw new Exception('No. HP tidak valid');
            }

            $phone = sprintf('%.0f', $numericPhone);
        }

        if (preg_match('/^\+?[0-9\s().-]+$/', $phone) !== 1) {
            throw new Exception('No. HP hanya boleh berisi angka dan pemisah umum');
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '0062')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '8')) {
            // Numeric spreadsheet cells drop a leading zero. Restore it for
            // Indonesian mobile numbers.
            $digits = '0'.$digits;
        }

        if (preg_match('/^08\d{8,12}$/', $digits) !== 1) {
            throw new Exception('No. HP harus berupa nomor WhatsApp Indonesia yang valid, contoh 081234567890');
        }

        return $digits;
    }

    protected function normalizeEmail(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
            throw new Exception('Email tidak valid');
        }

        $email = strtolower(trim((string) $value));

        if ($email === '') {
            return null;
        }

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new Exception('Format email tidak valid');
        }

        return $email;
    }

    /**
     * Get the errors after the import process
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getSuccessCount(): int
    {
        return $this->successCounter;
    }

    public function getProcessedCount(): int
    {
        return $this->rowCounter - 1;
    }

    protected function resolvePositionId(array $row): ?int
    {
        $positionValue = trim((string) $this->getValue($row, ['position', 'position_id', 'position_name']));

        if ($positionValue === '') {
            return null;
        }

        if (is_numeric($positionValue)) {
            $positionById = Position::find((int) $positionValue);
            if ($positionById) {
                return $positionById->id;
            }
        }

        $normalizedName = preg_replace('/\s+/', ' ', $positionValue);
        $position = Position::whereRaw('LOWER(name) = ?', [strtolower($normalizedName)])->first();

        if (! $position) {
            $position = Position::create(['name' => $normalizedName]);
        }

        return $position->id;
    }
}
