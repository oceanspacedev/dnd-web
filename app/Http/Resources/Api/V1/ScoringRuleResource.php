<?php

namespace App\Http\Resources\Api\V1;

use App\Filament\Resources\ScoringRules\ScoringRulePresenter;
use App\Models\ScoringRule;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\ScoringRuleService;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu versi aturan penilaian kinerja. Menerima model ScoringRule (daftar versi) atau ScoringRuleData
 * hasil ScoringRuleService::forPeriode() (resolve; id null = aturan lama tertanam di kode).
 * Kunci notes hanya ada untuk super admin (DataAccessService::hasGlobalAccess).
 *
 * @property ScoringRule|ScoringRuleData $resource
 */
class ScoringRuleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $model = $this->resource instanceof ScoringRule ? $this->resource : null;
        $rule = $model !== null ? ScoringRuleData::fromModel($model) : $this->resource;

        $status = ScoringRulePresenter::status($rule->effectiveFrom);
        $attendanceRules = [];

        foreach ($rule->attendanceRules as $code => $parameters) {
            $component = ScoringRuleService::component($code);
            $attendanceRules[] = [
                'component' => $code,
                'name' => $component->name ?? $code,
                'unit' => $component->unit ?? null,
                'reduces_base' => $parameters['reduces_base'],
                'penalty_points' => $parameters['penalty_points'],
            ];
        }

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'effective_from' => $rule->effectiveFrom,
            'effective_from_label' => ScoringRulePresenter::periodeLabel($rule->effectiveFrom),
            'engine' => $rule->engine,
            'status' => $status,
            'status_label' => ScoringRulePresenter::statusLabel($status),
            'is_locked' => $model !== null ? $model->isLocked() : ScoringRulePresenter::isLocked($rule->effectiveFrom),
            'is_fallback' => $rule->isFallback(),
            'weights' => [
                'kpi' => $rule->kpiWeight,
                'attendance' => $rule->attendanceWeight,
                'review' => $rule->reviewWeight,
            ],
            'review_max_points' => $rule->reviewMaxPoints,
            'attendance_rules' => $attendanceRules,
            // Catatan internal (dasar keputusan) hanya untuk super admin, bukan seluruh pengguna aplikasi.
            'notes' => $this->when(self::canSeeNotes($request), fn (): ?string => $model?->notes),
            'created_at' => $model?->created_at?->toIso8601String(),
            'updated_at' => $model?->updated_at?->toIso8601String(),
        ];
    }

    private static function canSeeNotes(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && DataAccessService::hasGlobalAccess($user);
    }
}
