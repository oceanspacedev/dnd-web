<?php

namespace App\Services;

use App\Models\ApprovalRule;
use App\Models\Divisi;
use App\Models\User;

class ApprovalResolverService
{
    /**
     * Resolve the recommended approver (User) based on organizational rules or division manager.
     */
    public static function resolve(
        ?int $divisiId = null,
        ?int $areaId = null,
        ?int $roleId = null,
        ?int $positionId = null,
        ?int $excludeUserId = null,
    ): ?User {
        $approver = static::resolveOutcome($divisiId, $areaId, $roleId, $positionId, $excludeUserId)['approver'];

        return $approver instanceof User ? $approver : null;
    }

    /**
     * @return array{approver: User|null, blocked_by_cycle: bool, excluded_self: bool, source: string|null, rule_name: string|null, label: string|null}
     */
    public static function resolveOutcome(
        ?int $divisiId = null,
        ?int $areaId = null,
        ?int $roleId = null,
        ?int $positionId = null,
        ?int $excludeUserId = null,
    ): array {
        return static::selectApprover($divisiId, $areaId, $roleId, $positionId, $excludeUserId);
    }

    /**
     * Resolve detailed information about the matched rule and recommended approver.
     *
     * @return array{approver: User, source: string, rule_name: string, label: string}|null
     */
    public static function resolveRuleDetails(
        ?int $divisiId = null,
        ?int $areaId = null,
        ?int $roleId = null,
        ?int $positionId = null,
        ?int $excludeUserId = null,
    ): ?array {
        $outcome = static::selectApprover($divisiId, $areaId, $roleId, $positionId, $excludeUserId);
        $approver = $outcome['approver'];

        if (! $approver instanceof User || ! is_string($outcome['source']) || ! is_string($outcome['rule_name']) || ! is_string($outcome['label'])) {
            return null;
        }

        return [
            'approver' => $approver,
            'source' => $outcome['source'],
            'rule_name' => $outcome['rule_name'],
            'label' => $outcome['label'],
        ];
    }

    public static function createsApprovalCycle(int $recordId, int $approvalId): bool
    {
        $visited = [];
        $currentId = $approvalId;

        while ($currentId !== 0) {
            if ($currentId === $recordId) {
                return true;
            }

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $currentId = (int) (User::query()->whereKey($currentId)->value('approval_id') ?? 0);
        }

        return false;
    }

    /**
     * @return array{approver: User|null, blocked_by_cycle: bool, excluded_self: bool, source: string|null, rule_name: string|null, label: string|null}
     */
    private static function selectApprover(
        ?int $divisiId,
        ?int $areaId,
        ?int $roleId,
        ?int $positionId,
        ?int $excludeUserId,
    ): array {
        $rules = ApprovalRule::query()
            ->with(['approver.role', 'approver.divisi', 'divisi'])
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNotNull('area_id')
                    ->orWhereNotNull('divisi_id')
                    ->orWhereNotNull('position_id')
                    ->orWhereNotNull('role_id');
            })
            ->get();

        $matches = [];

        foreach ($rules as $rule) {
            if (! $rule->approver instanceof User) {
                continue;
            }

            $specificity = static::matchingSpecificity($rule, $divisiId, $areaId, $roleId, $positionId);
            if ($specificity === null) {
                continue;
            }

            $matches[] = [
                'rule' => $rule,
                'specificity' => $specificity,
            ];
        }

        usort($matches, function (array $left, array $right): int {
            $specificity = $right['specificity'] <=> $left['specificity'];
            if ($specificity !== 0) {
                return $specificity;
            }

            $priority = ((int) $right['rule']->priority) <=> ((int) $left['rule']->priority);
            if ($priority !== 0) {
                return $priority;
            }

            return ((int) $left['rule']->id) <=> ((int) $right['rule']->id);
        });

        $sawSelf = false;
        $sawCycle = false;

        foreach ($matches as $match) {
            $approver = $match['rule']->approver;
            if (! $approver instanceof User) {
                continue;
            }

            if (static::isExcludedSelf($excludeUserId, (int) $approver->id)) {
                $sawSelf = true;

                continue;
            }

            if (static::hasApprovalCycle($excludeUserId, (int) $approver->id)) {
                $sawCycle = true;

                continue;
            }

            return static::matchedOutcome(
                $approver,
                'rule',
                (string) $match['rule']->name,
                "Aturan Matriks '{$match['rule']->name}'",
            );
        }

        $divisi = $divisiId ? Divisi::with('manager.role')->find($divisiId) : null;
        $manager = $divisi?->manager;

        if ($divisi instanceof Divisi && $manager instanceof User) {
            if (static::isExcludedSelf($excludeUserId, (int) $manager->id)) {
                $sawSelf = true;
            } elseif (static::hasApprovalCycle($excludeUserId, (int) $manager->id)) {
                $sawCycle = true;
            } else {
                return static::matchedOutcome(
                    $manager,
                    'division_manager',
                    "Kepala Divisi {$divisi->name}",
                    "Kepala Divisi {$divisi->name}",
                );
            }
        }

        return static::emptyOutcome($sawCycle, $sawSelf && ! $sawCycle);
    }

    private static function isExcludedSelf(?int $excludeUserId, int $approverId): bool
    {
        return $excludeUserId !== null && $excludeUserId !== 0 && $excludeUserId === $approverId;
    }

    private static function hasApprovalCycle(?int $excludeUserId, int $approverId): bool
    {
        return $excludeUserId !== null
            && $excludeUserId !== 0
            && static::createsApprovalCycle($excludeUserId, $approverId);
    }

    /**
     * Count matching criteria. Null means the rule does not match.
     * A rule with no criteria is ignored.
     */
    private static function matchingSpecificity(
        ApprovalRule $rule,
        ?int $divisiId,
        ?int $areaId,
        ?int $roleId,
        ?int $positionId,
    ): ?int {
        $constraints = [
            'area_id' => $areaId,
            'divisi_id' => $divisiId,
            'position_id' => $positionId,
            'role_id' => $roleId,
        ];

        $specificity = 0;

        foreach ($constraints as $column => $input) {
            if ($rule->{$column} === null) {
                continue;
            }

            if ($input === null || (int) $rule->{$column} !== $input) {
                return null;
            }

            $specificity++;
        }

        return $specificity > 0 ? $specificity : null;
    }

    /**
     * @return array{approver: User, blocked_by_cycle: bool, excluded_self: bool, source: string, rule_name: string, label: string}
     */
    private static function matchedOutcome(User $approver, string $source, string $ruleName, string $label): array
    {
        return [
            'approver' => $approver,
            'blocked_by_cycle' => false,
            'excluded_self' => false,
            'source' => $source,
            'rule_name' => $ruleName,
            'label' => $label,
        ];
    }

    /**
     * @return array{approver: null, blocked_by_cycle: bool, excluded_self: bool, source: null, rule_name: null, label: null}
     */
    private static function emptyOutcome(bool $blockedByCycle, bool $excludedSelf): array
    {
        return [
            'approver' => null,
            'blocked_by_cycle' => $blockedByCycle,
            'excluded_self' => $excludedSelf,
            'source' => null,
            'rule_name' => null,
            'label' => null,
        ];
    }
}
