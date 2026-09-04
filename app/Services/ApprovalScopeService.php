<?php

namespace App\Services;

use App\Models\User;

class ApprovalScopeService
{
    /** @var array<int, array<int>> */
    private array $memo = [];

    /**
     * Clear the per-request memo (useful for testing).
     */
    public static function clearMemo(): void
    {
        resolve(self::class)->memo = [];
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

        if ($directIds === []) {
            return $this->memo[$supervisorId] = [];
        }

        $secondLevelIds = User::query()
            ->whereNull('deleted_at')
            ->whereIn('approval_id', $directIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $result = array_values(array_unique(array_merge($directIds, $secondLevelIds)));
        $this->memo[$supervisorId] = $result;

        return $result;
    }
}
