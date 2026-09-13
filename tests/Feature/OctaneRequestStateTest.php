<?php

namespace Tests\Feature;

use App\Services\ApprovalScopeService;
use App\Services\KpiCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OctaneRequestStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_scope_is_memoized_only_for_the_current_container_scope(): void
    {
        $userQueries = 0;

        DB::listen(function ($query) use (&$userQueries): void {
            if (str_contains(strtolower($query->sql), 'from "users"')) {
                $userQueries++;
            }
        });

        $this->assertSame([], ApprovalScopeService::getManagedUserIdsOneLevelDown(999_999));
        $this->assertSame([], ApprovalScopeService::getManagedUserIdsOneLevelDown(999_999));
        $this->assertSame(1, $userQueries);

        $this->app->forgetScopedInstances();

        $this->assertSame([], ApprovalScopeService::getManagedUserIdsOneLevelDown(999_999));
        $this->assertSame(2, $userQueries);
    }

    public function test_kpi_cache_invalidation_does_not_flush_unrelated_cache(): void
    {
        Cache::put('unrelated-application-key', 'survives', 600);

        KpiCacheService::clearKpiCache();

        $this->assertSame('survives', Cache::get('unrelated-application-key'));
    }
}
