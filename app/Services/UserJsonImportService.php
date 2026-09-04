<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UserJsonImportService
{
    /** @var array<int, string> */
    private const EMAIL_KEYS = ['email', 'email_address'];

    /** @var array<int, string> */
    private const PHONE_KEYS = ['mobile_phone', 'no_hp', 'phone', 'phone_number', 'no_telepon'];

    /** @var array<int, string> */
    private const INITIAL_PASSWORD_KEYS = ['initial_password', 'password'];

    /** @var array<int, string> */
    private const TEMPLATE_FIELDS = [
        'role_id',
        'area_id',
        'divisi_id',
        'position_id',
        'approval_id',
        'd',
        'dr',
        'wn',
        'wr',
        'mn',
        'mr',
    ];

    /**
     * Import users from a JSON file.
     *
     * Existing users are contact-only synchronizations. New users inherit a
     * unanimous DND profile from active local peers instead of allowing the
     * external JSON to define roles, approval, or KPI capabilities.
     */
    public static function importFromFile(string $jsonFilePath): array
    {
        if (! is_file($jsonFilePath) || ! is_readable($jsonFilePath)) {
            return static::failedResult(
                'File JSON tidak ditemukan atau tidak dapat dibaca di server.',
                ['File JSON tidak ditemukan atau tidak dapat dibaca.'],
            );
        }

        $content = file_get_contents($jsonFilePath);
        if ($content === false) {
            return static::failedResult(
                'File JSON gagal dibaca.',
                ['File JSON gagal dibaca.'],
            );
        }

        return static::importFromContent($content);
    }

    /**
     * Import users from raw JSON content.
     */
    public static function importFromContent(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = preg_replace('/^\xFE\xFF|^\xFF\xFE/', '', $content) ?? $content;
        $content = trim($content);

        if ($content === '') {
            return static::failedResult('File JSON kosong (0 byte).', ['File JSON kosong.']);
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $repaired = preg_replace('/,\s*([\]}])/', '$1', $content) ?? $content;
            $decoded = json_decode($repaired, true);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = json_last_error_msg();
            Log::error('UserJsonImport received invalid JSON', ['error' => $errorMessage]);

            return static::failedResult(
                'Format JSON tidak valid: '.$errorMessage.'.',
                ['Format JSON tidak valid: '.$errorMessage],
            );
        }

        $rows = static::extractRows($decoded);
        if ($rows === []) {
            return static::failedResult(
                'Tidak ada data karyawan ditemukan dalam file JSON.',
                ['Tidak ada data karyawan ditemukan.'],
            );
        }

        $successCount = 0;
        $createdCount = 0;
        $errors = [];
        $usedPasswordFingerprints = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;

            if (! is_array($row)) {
                $errors[] = "Baris #{$rowNumber}: Data karyawan harus berupa object JSON.";

                continue;
            }

            try {
                $passwordFingerprint = DB::transaction(
                    fn () => static::importRow($row, $usedPasswordFingerprints),
                );
                if ($passwordFingerprint !== null) {
                    $usedPasswordFingerprints[$passwordFingerprint] = true;
                    $createdCount++;
                }
                $successCount++;
            } catch (Throwable $exception) {
                Log::warning('UserJsonImport row rejected', [
                    'row' => $rowNumber,
                    'error' => $exception->getMessage(),
                ]);
                $errors[] = "Baris #{$rowNumber}: {$exception->getMessage()}";
            }
        }

        return [
            'success' => true,
            'message' => "Import JSON selesai. Berhasil: {$successCount} karyawan.",
            'success_count' => $successCount,
            'error_count' => count($errors),
            'errors' => $errors,
            'created_count' => $createdCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, true>  $usedPasswordFingerprints
     * @return string|null SHA-256 fingerprint for a newly created user's password.
     */
    private static function importRow(array $row, array $usedPasswordFingerprints): ?string
    {
        $employeeId = static::firstScalarValue($row, [
            'employee_id',
            'id_employee',
            'emp_id',
            'id_karyawan',
            'nip',
        ]);
        $explicitUsername = static::normalizeUsername(
            static::firstScalarValue($row, ['username']),
        );
        $fullName = static::extractFullName($row);

        $hasEmail = static::hasAnyKey($row, self::EMAIL_KEYS);
        $hasPhone = static::hasAnyKey($row, self::PHONE_KEYS);
        $email = $hasEmail
            ? static::normalizeAliases(
                $row,
                self::EMAIL_KEYS,
                static::normalizeEmail(...),
                'Email',
            )
            : null;
        $phone = $hasPhone
            ? static::normalizeAliases(
                $row,
                self::PHONE_KEYS,
                static::normalizePhoneNumber(...),
                'No. HP',
            )
            : null;

        $resolution = static::resolveExistingUser($employeeId, $explicitUsername, $fullName);
        $existingUser = $resolution['user'];

        if ($existingUser) {
            if ($hasPhone && $resolution['matched_by'] === 'name') {
                throw new RuntimeException(
                    'No. HP login user existing hanya boleh diubah melalui Employee ID atau username yang cocok.',
                );
            }

            $contactData = [];

            if ($hasEmail) {
                $contactData['email'] = $email;
            }
            if ($hasPhone) {
                $contactData['no_hp'] = $phone;
            }

            if ($contactData !== []) {
                $existingUser->update($contactData);
            }

            return null;
        }

        if ($employeeId === '') {
            throw new RuntimeException('Employee ID wajib diisi untuk membuat user baru.');
        }
        if ($fullName === '') {
            throw new RuntimeException('Nama Lengkap wajib diisi untuk membuat user baru.');
        }

        $initialPassword = static::extractInitialPassword($row);
        $passwordFingerprint = hash('sha256', $initialPassword);
        if (isset($usedPasswordFingerprints[$passwordFingerprint])) {
            throw new RuntimeException('Password awal user baru harus unik dalam satu file import.');
        }

        $template = static::resolveTemplateUser($row);
        $username = $explicitUsername !== ''
            ? $explicitUsername
            : static::normalizeUsername($fullName);

        if ($username === '') {
            throw new RuntimeException('Username tidak dapat dibentuk dari data karyawan.');
        }

        if (static::findUsersByCanonicalUsername($username)->isNotEmpty()) {
            throw new RuntimeException("Username \"{$username}\" sudah dipakai user lain.");
        }

        $userData = [
            'employee_id' => $employeeId,
            'nama_lengkap' => Str::upper(Str::squish($fullName)),
            'username' => $username,
            'password' => Hash::make($initialPassword),
            'email' => $hasEmail ? $email : null,
            'no_hp' => $hasPhone ? $phone : null,
        ];

        foreach (self::TEMPLATE_FIELDS as $field) {
            $userData[$field] = $template->getAttribute($field);
        }

        User::create($userData);

        return $passwordFingerprint;
    }

    /**
     * @return array{user: User|null, matched_by: 'employee_id'|'username'|'name'|null}
     */
    private static function resolveExistingUser(
        string $employeeId,
        string $explicitUsername,
        string $fullName,
    ): array {
        $employeeMatch = $employeeId !== ''
            ? static::findUniqueUserByEmployeeId($employeeId)
            : null;
        $usernameMatch = $explicitUsername !== ''
            ? static::findUniqueUserByUsername($explicitUsername)
            : null;

        if ($employeeMatch && $usernameMatch && $employeeMatch->isNot($usernameMatch)) {
            throw new RuntimeException('Employee ID dan username menunjuk user DND yang berbeda.');
        }

        $matchedUser = $employeeMatch ?? $usernameMatch;
        if ($matchedUser) {
            static::ensureUserIsActive($matchedUser);

            return [
                'user' => $matchedUser,
                'matched_by' => $employeeMatch ? 'employee_id' : 'username',
            ];
        }

        if ($fullName === '') {
            return ['user' => null, 'matched_by' => null];
        }

        // The imported production database contains legacy active users whose
        // employee_id is blank. Exact-name fallback is deliberately limited to
        // that legacy population so a new employee sharing a name is not merged.
        $nameMatches = static::findLegacyUsersByCanonicalName($fullName, false);
        if ($nameMatches->count() > 1) {
            throw new RuntimeException('Nama cocok dengan lebih dari satu user legacy DND.');
        }
        if ($nameMatches->count() === 1) {
            return ['user' => $nameMatches->first(), 'matched_by' => 'name'];
        }

        if (static::findLegacyUsersByCanonicalName($fullName, true)->isNotEmpty()) {
            throw new RuntimeException('User yang cocok sedang diarsipkan; data tidak diubah.');
        }

        return ['user' => null, 'matched_by' => null];
    }

    private static function findUniqueUserByEmployeeId(string $employeeId): ?User
    {
        $matches = User::withTrashed()
            ->whereRaw('TRIM(employee_id) = ?', [$employeeId])
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException('Employee ID cocok dengan lebih dari satu user DND.');
        }

        return $matches->first();
    }

    private static function findUniqueUserByUsername(string $username): ?User
    {
        $matches = static::findUsersByCanonicalUsername($username);

        if ($matches->count() > 1) {
            throw new RuntimeException('Username cocok dengan lebih dari satu user DND.');
        }

        return $matches->first();
    }

    private static function findUsersByCanonicalUsername(string $username)
    {
        return User::withTrashed()
            ->whereRaw(
                "LOWER(REPLACE(REPLACE(TRIM(username), '.', ''), ' ', '')) = ?",
                [$username],
            )
            ->limit(2)
            ->get();
    }

    private static function findLegacyUsersByCanonicalName(string $fullName, bool $onlyTrashed)
    {
        $query = $onlyTrashed ? User::onlyTrashed() : User::query();
        $canonicalName = static::canonicalName($fullName);

        return $query
            ->where(function (Builder $query): void {
                $query->whereNull('employee_id')
                    ->orWhereRaw("TRIM(employee_id) = ''");
            })
            ->whereNotNull('nama_lengkap')
            ->get()
            ->filter(
                fn (User $user): bool => static::canonicalName((string) $user->nama_lengkap) === $canonicalName,
            )
            ->take(2)
            ->values();
    }

    private static function ensureUserIsActive(User $user): void
    {
        if ($user->trashed()) {
            throw new RuntimeException('User yang cocok sedang diarsipkan; data tidak diubah.');
        }
    }

    /**
     * Resolve a local peer whose DND operational profile is unambiguous.
     *
     * @param  array<string, mixed>  $row
     */
    private static function resolveTemplateUser(array $row): User
    {
        $positionName = static::firstScalarValue($row, [
            'job',
            'job_position',
            'position',
            'position_name',
            'posisi',
            'title',
        ]);

        if ($positionName === '') {
            throw new RuntimeException('Posisi wajib diisi untuk mencari pola user DND.');
        }

        $areaName = static::firstScalarValue($row, [
            'organization',
            'area',
            'area_name',
            'nama_area',
            'branch',
        ]);
        $divisionName = static::firstScalarValue($row, [
            'department',
            'divisi',
            'divisi_name',
            'nama_divisi',
        ]);

        $positionIds = static::matchingMasterIds(Position::query(), $positionName);
        $adminRoleIds = Role::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', ['admin'])
            ->pluck('id');

        $query = User::query()
            ->whereIn('position_id', $positionIds)
            ->whereNotIn('role_id', $adminRoleIds);

        if ($areaName !== '') {
            $areaIds = static::matchingMasterIds(Area::query(), $areaName);
            $query->whereIn('area_id', $areaIds);
        }
        if ($divisionName !== '') {
            $divisionIds = static::matchingMasterIds(Divisi::query(), $divisionName);
            $query->whereIn('divisi_id', $divisionIds);
        }

        $candidates = $query->orderBy('id')->get();

        if ($candidates->isEmpty()) {
            throw new RuntimeException('Tidak ada user aktif dengan pola posisi/area/divisi yang sama.');
        }

        $profiles = $candidates->groupBy(static::templateFingerprint(...));
        if ($profiles->count() !== 1) {
            throw new RuntimeException('Pola DND untuk posisi/area/divisi ini ambigu; perlu review manual.');
        }

        $template = $candidates->first();
        if ($template->approval_id !== null
            && ! User::query()->whereKey($template->approval_id)->exists()) {
            throw new RuntimeException('Pola DND memiliki approval yang tidak aktif; perlu review manual.');
        }

        return $template;
    }

    /**
     * Match master names with the same whitespace normalization used for the
     * incoming JSON. The restored DND data contains a few names with repeated
     * internal spaces, so LOWER(TRIM(name)) alone is not symmetrical.
     *
     * @return array<int, int|string>
     */
    private static function matchingMasterIds(Builder $query, string $name): array
    {
        $canonicalName = static::canonicalName($name);

        return $query
            ->get(['id', 'name'])
            ->filter(
                fn ($record): bool => static::canonicalName((string) $record->getAttribute('name')) === $canonicalName,
            )
            ->pluck('id')
            ->all();
    }

    private static function templateFingerprint(User $user): string
    {
        $profile = [];

        foreach (self::TEMPLATE_FIELDS as $field) {
            $profile[$field] = $user->getAttribute($field);
        }

        return json_encode($profile, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private static function firstScalarValue(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            $value = $row[$key];
            if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
                throw new RuntimeException("Field {$key} tidak valid.");
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function extractFullName(array $row): string
    {
        $fullName = static::firstScalarValue($row, [
            'full_name',
            'nama_lengkap',
            'name',
            'employee_name',
        ]);

        if ($fullName !== '') {
            return Str::squish($fullName);
        }

        $firstName = static::firstScalarValue($row, ['first_name']);
        $lastName = static::firstScalarValue($row, ['last_name']);

        return Str::squish($firstName.' '.$lastName);
    }

    private static function normalizeUsername(string $username): string
    {
        return Str::lower(str_replace('.', '', preg_replace('/\s+/', '', $username) ?? $username));
    }

    private static function canonicalName(string $name): string
    {
        return Str::lower(Str::squish($name));
    }

    /**
     * Read a credential only for a new user. Existing-user rows return before
     * this method, so an external JSON password can never rotate an account.
     *
     * @param  array<string, mixed>  $row
     */
    private static function extractInitialPassword(array $row): string
    {
        $passwords = [];

        foreach (self::INITIAL_PASSWORD_KEYS as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            $value = $row[$key];
            if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
                throw new RuntimeException("Field {$key} tidak valid.");
            }

            $password = (string) $value;
            if ($password === '' || trim($password) === '') {
                continue;
            }
            if ($password !== trim($password)) {
                throw new RuntimeException('Password awal tidak boleh diawali atau diakhiri spasi.');
            }
            if (! in_array($password, $passwords, true)) {
                $passwords[] = $password;
            }
        }

        if ($passwords === []) {
            throw new RuntimeException('Field initial_password atau password wajib diisi untuk user baru.');
        }
        if (count($passwords) > 1) {
            throw new RuntimeException('Field initial_password dan password berisi nilai berbeda.');
        }

        $password = $passwords[0];
        if (mb_strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException('Password awal minimal 12 karakter dan maksimal 72 byte.');
        }

        return $password;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private static function hasAnyKey(array $row, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private static function normalizeAliases(
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
            throw new RuntimeException("{$label} memiliki beberapa nilai yang berbeda pada field alias.");
        }

        return array_key_first($normalizedValues);
    }

    private static function normalizePhoneNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
            throw new RuntimeException('No. HP tidak valid.');
        }

        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new RuntimeException('No. HP tidak valid.');
            }

            $phone = sprintf('%.0f', $value);
        } else {
            $phone = trim((string) $value);
        }

        if ($phone === '') {
            return null;
        }

        if (preg_match('/^[+-]?\d+(?:\.\d+)?[eE][+-]?\d+$/', $phone) === 1) {
            $numericPhone = (float) $phone;
            if (! is_finite($numericPhone) || floor($numericPhone) !== $numericPhone) {
                throw new RuntimeException('No. HP tidak valid.');
            }

            $phone = sprintf('%.0f', $numericPhone);
        }

        if (preg_match('/^\+?[0-9\s().-]+$/', $phone) !== 1) {
            throw new RuntimeException('No. HP hanya boleh berisi angka dan pemisah umum.');
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '0062')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '0'.$digits;
        }

        if (preg_match('/^08\d{8,12}$/', $digits) !== 1) {
            throw new RuntimeException('No. HP harus berupa nomor WhatsApp Indonesia yang valid.');
        }

        return $digits;
    }

    private static function normalizeEmail(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
            throw new RuntimeException('Email tidak valid.');
        }

        $email = Str::lower(trim((string) $value));

        if ($email === '') {
            return null;
        }

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Format email tidak valid.');
        }

        return $email;
    }

    /**
     * Extract a flat list of employee rows from supported JSON wrappers.
     */
    public static function extractRows(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['data', 'employees', 'users', 'items'] as $key) {
                if (isset($decoded['data'][$key]) && is_array($decoded['data'][$key])) {
                    return array_values($decoded['data'][$key]);
                }
            }

            if (array_is_list($decoded['data'])) {
                return $decoded['data'];
            }
            if (static::isEmployeeRow($decoded['data'])) {
                return [$decoded['data']];
            }
        }

        foreach (['employees', 'users', 'items', 'records', 'results', 'data'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return array_values($decoded[$key]);
            }
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        if (static::isEmployeeRow($decoded)) {
            return [$decoded];
        }

        $first = reset($decoded);
        if (is_array($first) && static::isEmployeeRow($first)) {
            return array_values($decoded);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function isEmployeeRow(array $data): bool
    {
        $candidateKeys = [
            'full_name', 'nama_lengkap', 'name', 'employee_name', 'first_name', 'last_name',
            'employee_id', 'id_employee', 'emp_id', 'id_karyawan', 'nip', 'username',
            'email', 'email_address', 'mobile_phone', 'no_hp', 'phone', 'phone_number',
            'job', 'job_position', 'position', 'position_name', 'posisi', 'title',
        ];

        foreach ($candidateKeys as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $errors
     */
    private static function failedResult(string $message, array $errors): array
    {
        return [
            'success' => false,
            'message' => $message,
            'success_count' => 0,
            'error_count' => count($errors),
            'errors' => $errors,
            'created_count' => 0,
        ];
    }
}
