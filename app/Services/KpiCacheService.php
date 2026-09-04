<?php

namespace App\Services;

use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class KpiCacheService
{
    private const VERSION_CACHE_KEY = 'kpi_options_version';

    public static function getKpiCategories(): array
    {
        return Cache::remember(self::versionedKey('categories'), self::getCacheTtl('categories', 300), function () {
            return KpiCategory::pluck('name', 'id')->toArray();
        });
    }

    public static function getKpiDescriptionsByCategory(int $categoryId): array
    {
        return Cache::remember(self::versionedKey("descriptions_category_{$categoryId}"), self::getCacheTtl('descriptions', 300), function () use ($categoryId) {
            return KpiDescription::where('kpi_category_id', $categoryId)
                ->pluck('description', 'id')
                ->toArray();
        });
    }

    public static function getPositionsForUser(): array
    {
        $userId = Auth::id();
        $userRoleName = Auth::user()->role?->name;

        $cacheKey = self::versionedKey("positions_user_{$userId}_{$userRoleName}");

        return Cache::remember($cacheKey, self::getCacheTtl('positions', 300), function () use ($userRoleName, $userId) {
            $usersQuery = User::query()
                ->whereNull('deleted_at')
                ->whereNotNull('position_id')
                ->orderBy('nama_lengkap');

            if ($userRoleName !== 'ADMIN') {
                $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown($userId);

                if ($managedUserIds === []) {
                    return [];
                }

                $usersQuery->whereIn('id', array_merge([$userId], $managedUserIds));
            }

            $users = $usersQuery->get(['id', 'nama_lengkap', 'position_id']);
            if ($users->isEmpty()) {
                return [];
            }

            $positionNames = Position::query()
                ->whereIn('id', $users->pluck('position_id')->unique()->values())
                ->pluck('name', 'id');

            $positionOptions = [];
            foreach ($users->groupBy('position_id') as $positionId => $members) {
                $positionName = $positionNames[$positionId] ?? 'Unknown Position';
                $userNames = $members->pluck('nama_lengkap')->implode(', ');
                $positionOptions[(int) $positionId] = "{$positionName} - {$userNames}";
            }

            asort($positionOptions, SORT_NATURAL | SORT_FLAG_CASE);

            return $positionOptions;
        });
    }

    public static function clearKpiCache(): void
    {
        Cache::add(self::VERSION_CACHE_KEY, 1, now()->addYears(10));

        // Old versioned entries expire naturally; unrelated application cache survives.
        if (Cache::increment(self::VERSION_CACHE_KEY) === false) {
            Cache::forever(self::VERSION_CACHE_KEY, 2);
        }
    }

    private static function versionedKey(string $key): string
    {
        $version = (int) Cache::get(self::VERSION_CACHE_KEY, 1);

        return "kpi:v{$version}:{$key}";
    }

    protected static function getCacheTtl(string $key, int $default): int
    {
        return max(1, (int) config("kpi.cache_ttl_seconds.{$key}", $default));
    }
}
