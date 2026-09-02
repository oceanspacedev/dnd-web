<?php

namespace App\Console\Commands;

use App\Models\Kpi;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanKpiDescriptionDuplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kpi:clean-duplicates {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean duplicate KPI descriptions based on KPIs used in 2025';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Starting KPI Description duplicate cleanup...');

        // Step 1: Get all KPI descriptions used in 2025
        $usedDescriptions2025 = $this->getUsedDescriptionsIn2025();

        $this->info("Found {$usedDescriptions2025->count()} KPI descriptions used in 2025");

        // Step 2: Find duplicates
        $duplicates = $this->findDuplicateDescriptions($usedDescriptions2025);

        $this->info("Found {$duplicates->count()} duplicate groups");

        // Step 3: Show analysis
        $this->showDuplicateAnalysis($duplicates);

        if ($isDryRun) {
            $this->warn('This is a dry run. No data will be deleted.');
        }

        // Step 4: Clean duplicates
        $this->cleanDuplicates($duplicates, $isDryRun);

        $this->info('KPI Description cleanup completed!');
    }

    /**
     * Get all KPI descriptions used in 2025
     */
    private function getUsedDescriptionsIn2025()
    {
        return DB::table('kpi_descriptions')
            ->select('kpi_descriptions.*')
            ->join('kpi_details', 'kpi_descriptions.id', '=', 'kpi_details.kpi_description_id')
            ->join('kpis', 'kpi_details.kpi_id', '=', 'kpis.id')
            ->whereYear('kpis.date', 2025)
            ->whereNull('kpi_descriptions.deleted_at')
            ->whereNull('kpi_details.deleted_at')
            ->whereNull('kpis.deleted_at')
            ->distinct()
            ->get();
    }

    /**
     * Find duplicate descriptions
     */
    private function findDuplicateDescriptions($usedDescriptions)
    {
        $duplicates = collect();

        // Group by description text and category
        $grouped = $usedDescriptions->groupBy(function ($item) {
            return strtolower(trim($item->description)) . '_' . $item->kpi_category_id;
        });

        foreach ($grouped as $key => $group) {
            if ($group->count() > 1) {
                $duplicates->put($key, $group);
            }
        }

        return $duplicates;
    }

    /**
     * Show duplicate analysis
     */
    private function showDuplicateAnalysis($duplicates)
    {
        $this->info("\n=== DUPLICATE ANALYSIS ===");

        foreach ($duplicates as $key => $group) {
            $this->warn("\nDuplicate group: {$key}");
            $this->table(
                ['ID', 'Description', 'Category ID', 'Created At', 'Usage Count'],
                $group->map(function ($item) {
                    $usageCount = DB::table('kpi_details')
                        ->where('kpi_description_id', $item->id)
                        ->whereNull('deleted_at')
                        ->count();

                    return [
                        $item->id,
                        substr($item->description, 0, 50) . (strlen($item->description) > 50 ? '...' : ''),
                        $item->kpi_category_id,
                        $item->created_at,
                        $usageCount
                    ];
                })
            );
        }
    }

    /**
     * Clean duplicates
     */
    private function cleanDuplicates($duplicates, $isDryRun)
    {
        $totalDeleted = 0;
        $totalUpdated = 0;

        foreach ($duplicates as $key => $group) {
            // Keep the one with most usage or oldest
            $keeper = $group->sortByDesc(function ($item) {
                $usageCount = DB::table('kpi_details')
                    ->where('kpi_description_id', $item->id)
                    ->whereNull('deleted_at')
                    ->count();

                // Primary sort by usage count, secondary by creation date (oldest first)
                return $usageCount * 10000 + (strtotime('2099-12-31') - strtotime($item->created_at));
            })->first();

            $toDelete = $group->where('id', '!=', $keeper->id);

            $this->info("\nProcessing group: {$key}");
            $this->info("Keeping ID: {$keeper->id} (Description: " . substr($keeper->description, 0, 50) . "...)");

            foreach ($toDelete as $duplicate) {
                $this->warn("Processing duplicate ID: {$duplicate->id}");

                // Get usage count
                $usageCount = DB::table('kpi_details')
                    ->where('kpi_description_id', $duplicate->id)
                    ->whereNull('deleted_at')
                    ->count();

                if ($usageCount > 0) {
                    // Update kpi_details to use the keeper
                    if (!$isDryRun) {
                        DB::table('kpi_details')
                            ->where('kpi_description_id', $duplicate->id)
                            ->update(['kpi_description_id' => $keeper->id]);
                    }

                    $this->info("  - Updated {$usageCount} kpi_details records to use keeper ID: {$keeper->id}");
                    $totalUpdated += $usageCount;
                }

                // Delete the duplicate
                if (!$isDryRun) {
                    DB::table('kpi_descriptions')
                        ->where('id', $duplicate->id)
                        ->update(['deleted_at' => now()]);
                }

                $this->info("  - Deleted duplicate ID: {$duplicate->id}");
                $totalDeleted++;
            }
        }

        $this->info("\n=== SUMMARY ===");
        $this->info("Total duplicates deleted: {$totalDeleted}");
        $this->info("Total kpi_details updated: {$totalUpdated}");

        if ($isDryRun) {
            $this->warn("This was a dry run. No actual changes were made.");
        }
    }
}
