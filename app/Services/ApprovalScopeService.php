<?php

namespace App\Services;

use App\Models\User;

class ApprovalScopeService
{
    /** @var array<int, array<int>> */
    private array $memo = [];

    /** @var array<int, int|null>|null id karyawan => id atasan, dimuat sekali per request */
    private ?array $parentMap = null;

    /**
     * Clear the per-request memo (useful for testing).
     */
    public static function clearMemo(): void
    {
        $instance = resolve(self::class);
        $instance->memo = [];
        $instance->parentMap = null;
    }

    /**
     * Get managed users for a supervisor:
     * - direct reports
     * - one level below direct reports
     *
     * Results are memoized per-request to avoid repeated queries.
     *
     * @return array<int>
     */
    public static function getManagedUserIdsOneLevelDown(int $supervisorId): array
    {
        return resolve(self::class)->managedUserIdsOneLevelDown($supervisorId);
    }

    /**
     * Seluruh bawahan di approval line seorang atasan, semua tingkat ke bawah (tanpa dirinya sendiri).
     * Karyawan soft-deleted ikut, karena datanya (mis. KPI) masih tampil dan perlu bisa disaring.
     * Hanya untuk menyaring tampilan; bukan dasar hak akses.
     *
     * @return array<int>
     */
    public static function getAllSubordinateIds(int $supervisorId): array
    {
        $childrenOf = [];
        foreach (self::approvalParentMap() as $userId => $approvalId) {
            if ($approvalId !== null) {
                $childrenOf[$approvalId][] = $userId;
            }
        }

        $seen = [$supervisorId => true];
        $queue = [$supervisorId];
        $result = [];

        while ($queue !== []) {
            foreach ($childrenOf[array_shift($queue)] ?? [] as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return $result;
    }

    /**
     * Seluruh atasan (semua tingkat ke atas) dari kumpulan karyawan, tanpa duplikat.
     *
     * @param  array<int>  $userIds
     * @return array<int>
     */
    public static function getAllSupervisorIds(array $userIds): array
    {
        $parentOf = self::approvalParentMap();
        $supervisors = [];

        foreach ($userIds as $userId) {
            $seen = [(int) $userId => true];
            $current = $parentOf[(int) $userId] ?? null;

            // Berhenti di atasan yang sudah ditelusuri (rantainya sudah tercatat) atau saat approval line melingkar.
            while ($current !== null && ! isset($seen[$current])) {
                $seen[$current] = true;

                if (isset($supervisors[$current])) {
                    break;
                }

                $supervisors[$current] = true;
                $current = $parentOf[$current] ?? null;
            }
        }

        return array_keys($supervisors);
    }

    /**
     * Dimemo per request (sama seperti daftar bawahan): filter Atasan di tabel KPI membacanya untuk opsi dan
     * untuk menyaring, jadi cukup satu query.
     *
     * @return array<int, int|null> id karyawan => id atasan (approval line), termasuk karyawan soft-deleted
     */
    private static function approvalParentMap(): array
    {
        return resolve(self::class)->parentMap ??= User::query()
            ->withTrashed()
            ->toBase()
            ->pluck('approval_id', 'id')
            ->map(static fn ($approvalId): ?int => $approvalId === null ? null : (int) $approvalId)
            ->all();
    }

    /**
     * @return array<int>
     */
    private function managedUserIdsOneLevelDown(int $supervisorId): array
    {
        if (array_key_exists($supervisorId, $this->memo)) {
            return $this->memo[$supervisorId];
        }

        $directIds = User::query()
            ->whereNull('deleted_at')
            ->where('approval_id', $supervisorId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (array_diff($directIds, [$supervisorId]) === []) {
            return $this->memo[$supervisorId] = [];
        }

        $secondLevelIds = User::query()
            ->whereNull('deleted_at')
            ->whereIn('approval_id', $directIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // Approval line ke diri sendiri (atau saling menyetujui) tidak menjadikan seseorang anggota timnya sendiri.
        $result = array_values(array_diff(array_unique(array_merge($directIds, $secondLevelIds)), [$supervisorId]));
        $this->memo[$supervisorId] = $result;

        return $result;
    }
}
