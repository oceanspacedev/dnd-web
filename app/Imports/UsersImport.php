<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\User;
use App\Models\Role;
use Exception;
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
     * @param array $row
     *
     * @return Model|null
     */
    public function model(array $row)
    {
        $this->rowCounter++;
        $currentRowNum = $row['row_number'] ?? $this->rowCounter;

        try {
            // Flexible field extraction supporting alternate column names
            $namaLengkap = trim((string) $this->getValue($row, ['nama_lengkap', 'nama', 'name']));
            $employeeId  = trim((string) $this->getValue($row, ['id_karyawan', 'employee_id']));
            $noHp        = trim((string) $this->getValue($row, ['no_hp', 'hp', 'phone', 'no_telepon', 'telepon']));
            $email       = trim((string) $this->getValue($row, ['email', 'email_address']));
            $rawUsername = (string) $this->getValue($row, ['username']);
            $roleInput   = trim((string) $this->getValue($row, ['role', 'role_name']));
            $areaInput   = trim((string) $this->getValue($row, ['area', 'area_name']));
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
            if ($employeeId !== '') {
                $user = User::withTrashed()->where('employee_id', $employeeId)->first();
            }
            if (!$user && $username !== '') {
                $user = User::withTrashed()->where('username', $username)->first();
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
            if (!$role) {
                throw new Exception('Role "' . ($roleInput ?: '-') . '" tidak ditemukan');
            }

            // Area lookup & auto-creation if missing
            $area = null;
            if ($areaInput !== '') {
                $areaNormalized = preg_replace('/\s+/', '', $areaInput);
                $area = Area::where('name', $areaInput)
                    ->orWhere('name', $areaNormalized)
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($areaInput)])
                    ->first();

                if (!$area) {
                    $area = Area::create(['name' => $areaInput]);
                }
            }
            if (!$area) {
                throw new Exception('Area "' . ($areaInput ?: '-') . '" tidak ditemukan');
            }

            // Divisi lookup & auto-creation if missing
            $divisi = null;
            if ($divisiInput !== '') {
                $divisiNormalized = preg_replace('/\s+/', '', $divisiInput);
                $divisi = Divisi::where('name', $divisiInput)
                    ->orWhere('name', $divisiNormalized)
                    ->orWhereRaw('LOWER(name) = ?', [strtolower($divisiInput)])
                    ->first();

                if (!$divisi) {
                    $divisi = Divisi::create([
                        'name' => $divisiInput,
                        'area_id' => $area->id,
                    ]);
                }
            }
            if (!$divisi) {
                throw new Exception('Divisi "' . ($divisiInput ?: '-') . '" tidak ditemukan');
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
                if ($noHp !== '') {
                    $updateData['no_hp'] = $noHp;
                }
                if ($email !== '') {
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
                    'no_hp' => $noHp ?: null,
                    'email' => $email ?: null,
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
                    'password' => !empty($passwordInput) ? bcrypt($passwordInput) : static::$defaultPasswordHash,
                ]);
            }

            $this->successCounter++;
        } catch (Exception $e) {
            $this->errors[] = 'Baris ' . $currentRowNum . ': ' . $e->getMessage();
        } catch (QueryException $e) {
            $this->errors[] = 'SQL Error baris ' . $currentRowNum . ': ' . $e->getMessage();
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

        if (!$position) {
            $position = Position::create(['name' => $normalizedName]);
        }

        return $position->id;
    }
}
