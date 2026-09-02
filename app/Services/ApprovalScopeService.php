<?php

namespace App\Services;

use App\Models\User;

class ApprovalScopeService
{
    /** @var array<int, array<int>> */
    private static array $memo = [];

    /**
     * Clear the per-request memo (useful for testing).
     */
    public static function clearMemo(): void
    {
        self::$memo = [];
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
        if (array_key_exists($supervisorId, self::$memo)) {
            return self::$memo[$supervisorId];
        }

        $directIds = User::query()
            ->whereNull('deleted_at')
            ->where('approval_id', $supervisorId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($directIds === []) {
            return [];
        }

        $secondLevelIds = User::query()
            ->whereNull('deleted_at')
            ->whereIn('approval_id', $directIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $result = array_values(array_unique(array_merge($directIds, $secondLevelIds)));
        self::$memo[$supervisorId] = $result;

        return $result;
    }
}
