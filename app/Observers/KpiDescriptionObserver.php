<?php

namespace App\Observers;

use App\Models\KpiDescription;
use App\Services\KpiCacheService;

class KpiDescriptionObserver
{
    public function created(KpiDescription $kpiDescription)
    {
        KpiCacheService::clearKpiCache();
    }

    public function updated(KpiDescription $kpiDescription)
    {
        KpiCacheService::clearKpiCache();
    }

    public function deleted(KpiDescription $kpiDescription)
    {
        KpiCacheService::clearKpiCache();
    }
}
