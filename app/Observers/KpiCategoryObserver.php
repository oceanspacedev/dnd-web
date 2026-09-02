<?php

namespace App\Observers;

use App\Models\KpiCategory;
use App\Services\KpiCacheService;

class KpiCategoryObserver
{
    public function created(KpiCategory $kpiCategory)
    {
        KpiCacheService::clearKpiCache();
    }

    public function updated(KpiCategory $kpiCategory)
    {
        KpiCacheService::clearKpiCache();
    }

    public function deleted(KpiCategory $kpiCategory)
    {
        KpiCacheService::clearKpiCache();
    }
}
