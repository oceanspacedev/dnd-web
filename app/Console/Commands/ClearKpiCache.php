<?php

namespace App\Console\Commands;

use App\Services\KpiCacheService;
use Illuminate\Console\Command;

class ClearKpiCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kpi:clear-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear KPI related cache';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        KpiCacheService::clearKpiCache();

        $this->info('KPI cache cleared successfully!');

        return Command::SUCCESS;
    }
}
