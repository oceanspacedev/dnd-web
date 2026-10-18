<?php

namespace App\Exports;

use App\Support\Scoring\ScoringRuleData;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class LeaderboardExport implements Export, WithMultipleSheets
{
    protected array $leaderboardData;

    protected string $periodType;

    protected string $selectedPeriod;

    protected $kpiDetails;

    protected $attendanceDetails;

    protected $reviewDetails;

    protected array $monthlySummaryRows;

    protected bool $includeDetails;

    /** @var list<ScoringRuleData> versi aturan yang dipakai periode ini (tahunan: satu per bulan). */
    protected array $scoringRules;

    /**
     * @param  list<ScoringRuleData>  $scoringRules
     */
    public function __construct(
        array $leaderboardData,
        string $periodType,
        string $selectedPeriod,
        $kpiDetails,
        $attendanceDetails,
        $reviewDetails,
        array $monthlySummaryRows = [],
        bool $includeDetails = true,
        array $scoringRules = [],
    ) {
        $this->leaderboardData = $leaderboardData;
        $this->periodType = $periodType;
        $this->selectedPeriod = $selectedPeriod;
        $this->kpiDetails = $kpiDetails;
        $this->attendanceDetails = $attendanceDetails;
        $this->reviewDetails = $reviewDetails;
        $this->monthlySummaryRows = $monthlySummaryRows;
        $this->includeDetails = $includeDetails;
        $this->scoringRules = $scoringRules;
    }

    public function sheets(): array
    {
        $sheets = [
            new LeaderboardSummarySheet($this->leaderboardData, $this->scoringRules),
        ];

        if ($this->periodType === 'year') {
            $sheets[] = new LeaderboardMonthlySummarySheet($this->monthlySummaryRows, $this->selectedPeriod, $this->scoringRules);
        }

        if ($this->includeDetails) {
            $sheets[] = new LeaderboardKpiDetailSheet($this->kpiDetails, $this->periodType, $this->selectedPeriod);
            $sheets[] = new LeaderboardAttendanceDetailSheet($this->attendanceDetails, $this->periodType, $this->selectedPeriod);
            $sheets[] = new LeaderboardEmployeeReviewDetailSheet($this->reviewDetails, $this->periodType, $this->selectedPeriod);
        }

        return $sheets;
    }
}
