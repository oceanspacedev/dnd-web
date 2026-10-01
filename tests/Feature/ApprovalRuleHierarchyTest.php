<?php

namespace Tests\Feature;

use App\Filament\Resources\ApprovalRules\ApprovalRuleResource;
use App\Filament\Resources\ApprovalRules\Pages\ManageApprovalRules;
use App\Filament\Resources\Divisis\Pages\ManageDivisis;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalResolverService;
use App\Services\ApprovalScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalRuleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::creating(function (User $user): void {
            static::ensureUserPlacement($user);
        });
    }

    protected function tearDown(): void
    {
        ApprovalScopeService::clearMemo();
        parent::tearDown();
    }

    public function test_role_level_and_divisi_manager_relationships(): void
    {
        $roleAdmin = Role::query()->create(['name' => 'ADMIN', 'level' => 100]);
        $roleStaff = Role::query()->create(['name' => 'STAFF', 'level' => 10]);

        $this->assertSame(100, $roleAdmin->level);
        $this->assertSame(10, $roleStaff->level);

        $admin = User::query()->create([
            'nama_lengkap' => 'Admin Boss',
            'username' => 'admin_boss',
            'password' => bcrypt('password'),
            'role_id' => $roleAdmin->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $area = Area::query()->create(['name' => 'Surabaya']);
        $divisi = Divisi::query()->create([
            'name' => 'Marketing',
            'area_id' => $area->id,
            'manager_id' => $admin->id,
        ]);

        $this->assertTrue($divisi->manager->is($admin));
    }

    public function test_approval_resolver_prefers_specific_rule_over_division_manager(): void
    {
        $roleAdmin = Role::query()->create(['name' => 'ADMIN', 'level' => 100]);
        $roleManager = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $roleStaff = Role::query()->create(['name' => 'STAFF', 'level' => 10]);

        $generalManager = User::query()->create([
            'nama_lengkap' => 'General Manager',
            'username' => 'gm_user',
            'password' => bcrypt('password'),
            'role_id' => $roleManager->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $specificApprover = User::query()->create([
            'nama_lengkap' => 'Specific Lead',
            'username' => 'lead_user',
            'password' => bcrypt('password'),
            'role_id' => $roleManager->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $area = Area::query()->create(['name' => 'Jakarta']);
        $divisi = Divisi::query()->create([
            'name' => 'Operasional',
            'area_id' => $area->id,
            'manager_id' => $generalManager->id,
        ]);

        $position = Position::query()->create(['name' => 'Field Officer']);

        // 1. When no rule exists, fallback to division manager
        $resolved = ApprovalResolverService::resolve(divisiId: $divisi->id);
        $this->assertNotNull($resolved);
        $this->assertSame($generalManager->id, $resolved->id);

        // 2. Create specific approval rule for Field Officer in this division
        ApprovalRule::query()->create([
            'name' => 'Aturan Khusus Field Officer',
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $position->id,
            'role_id' => $roleStaff->id,
            'approver_id' => $specificApprover->id,
            'priority' => 10,
            'is_active' => true,
        ]);

        // When matching Field Officer, specific rule is preferred
        $resolvedSpecific = ApprovalResolverService::resolve(
            divisiId: $divisi->id,
            areaId: $area->id,
            roleId: $roleStaff->id,
            positionId: $position->id
        );

        $this->assertNotNull($resolvedSpecific);
        $this->assertSame($specificApprover->id, $resolvedSpecific->id);

        $details = ApprovalResolverService::resolveRuleDetails(
            divisiId: $divisi->id,
            areaId: $area->id,
            roleId: $roleStaff->id,
            positionId: $position->id
        );
        $this->assertSame('rule', $details['source']);
        $this->assertStringContainsString('Aturan Khusus Field Officer', $details['label']);
    }

    public function test_inactive_rule_is_ignored(): void
    {
        $roleAdmin = Role::query()->create(['name' => 'ADMIN', 'level' => 100]);
        $approver = User::query()->create([
            'nama_lengkap' => 'Inactive Approver',
            'username' => 'inactive_lead',
            'password' => bcrypt('password'),
            'role_id' => $roleAdmin->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $area = Area::query()->create(['name' => 'Bandung']);
        $divisi = Divisi::query()->create([
            'name' => 'Creative',
            'area_id' => $area->id,
        ]);

        ApprovalRule::query()->create([
            'name' => 'Aturan Nonaktif',
            'divisi_id' => $divisi->id,
            'approver_id' => $approver->id,
            'is_active' => false,
        ]);

        $resolved = ApprovalResolverService::resolve(divisiId: $divisi->id);
        $this->assertNull($resolved);
    }

    public function test_approval_resolver_skips_excluded_user_to_prevent_self_approval(): void
    {
        $roleManager = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $managerUser = User::query()->create([
            'nama_lengkap' => 'Division Manager',
            'username' => 'div_mgr',
            'password' => bcrypt('password'),
            'role_id' => $roleManager->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $area = Area::query()->create(['name' => 'IT Area']);
        $divisi = Divisi::query()->create([
            'name' => 'IT',
            'area_id' => $area->id,
            'manager_id' => $managerUser->id,
        ]);

        // When resolving for ordinary member, resolves to manager
        $this->assertSame($managerUser->id, ApprovalResolverService::resolve(divisiId: $divisi->id)?->id);

        // When resolving for the manager himself, it excludes him and does not self-approve
        $excluded = ApprovalResolverService::resolveOutcome(divisiId: $divisi->id, excludeUserId: $managerUser->id);
        $this->assertNull($excluded['approver']);
        $this->assertTrue($excluded['excluded_self']);
        $this->assertFalse($excluded['blocked_by_cycle']);
    }

    public function test_more_specific_rule_beats_a_higher_priority(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $broadApprover = $this->makeUser('Broad Approver', 'broad_approver', $role);
        $specificApprover = $this->makeUser('Specific Approver', 'specific_approver', $role);
        $area = Area::query()->create(['name' => 'Medan']);
        $divisi = Divisi::query()->create(['name' => 'Finance', 'area_id' => $area->id]);
        $position = Position::query()->create(['name' => 'Analyst']);

        ApprovalRule::query()->create([
            'name' => 'Aturan Divisi',
            'divisi_id' => $divisi->id,
            'approver_id' => $broadApprover->id,
            'priority' => 5,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Aturan Divisi dan Posisi',
            'divisi_id' => $divisi->id,
            'position_id' => $position->id,
            'approver_id' => $specificApprover->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $resolved = ApprovalResolverService::resolve(
            divisiId: $divisi->id,
            areaId: $area->id,
            roleId: $role->id,
            positionId: $position->id,
        );

        $this->assertSame($specificApprover->id, $resolved?->id);
    }

    public function test_same_specificity_prefers_higher_priority(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $lowPriorityApprover = $this->makeUser('Low Priority Approver', 'low_priority', $role);
        $highPriorityApprover = $this->makeUser('High Priority Approver', 'high_priority', $role);
        $area = Area::query()->create(['name' => 'Priority Area']);
        $divisi = Divisi::query()->create(['name' => 'Priority Division', 'area_id' => $area->id]);

        ApprovalRule::query()->create([
            'name' => 'Prioritas Rendah',
            'divisi_id' => $divisi->id,
            'approver_id' => $lowPriorityApprover->id,
            'priority' => 1,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Prioritas Tinggi',
            'divisi_id' => $divisi->id,
            'approver_id' => $highPriorityApprover->id,
            'priority' => 9,
            'is_active' => true,
        ]);

        $this->assertSame(
            $highPriorityApprover->id,
            ApprovalResolverService::resolve(divisiId: $divisi->id)?->id,
        );
    }

    public function test_same_priority_prefers_the_rule_with_more_matching_criteria(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $broadApprover = $this->makeUser('Broad Same Priority', 'broad_same', $role);
        $specificApprover = $this->makeUser('Specific Same Priority', 'specific_same', $role);
        $area = Area::query()->create(['name' => 'Legal Area']);
        $divisi = Divisi::query()->create(['name' => 'Legal', 'area_id' => $area->id]);
        $position = Position::query()->create(['name' => 'Counsel']);

        ApprovalRule::query()->create([
            'name' => 'Hanya Divisi',
            'divisi_id' => $divisi->id,
            'approver_id' => $broadApprover->id,
            'priority' => 2,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Divisi dan Posisi',
            'divisi_id' => $divisi->id,
            'position_id' => $position->id,
            'approver_id' => $specificApprover->id,
            'priority' => 2,
            'is_active' => true,
        ]);

        $resolved = ApprovalResolverService::resolve(
            divisiId: $divisi->id,
            positionId: $position->id,
        );

        $this->assertSame($specificApprover->id, $resolved?->id);
    }

    public function test_tied_rules_use_the_lowest_id(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $firstApprover = $this->makeUser('First Tied Approver', 'first_tied', $role);
        $secondApprover = $this->makeUser('Second Tied Approver', 'second_tied', $role);
        $area = Area::query()->create(['name' => 'Audit Area']);
        $divisi = Divisi::query()->create(['name' => 'Audit', 'area_id' => $area->id]);

        $firstRule = ApprovalRule::query()->create([
            'name' => 'Aturan Pertama',
            'divisi_id' => $divisi->id,
            'approver_id' => $firstApprover->id,
            'priority' => 3,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Aturan Kedua',
            'divisi_id' => $divisi->id,
            'approver_id' => $secondApprover->id,
            'priority' => 3,
            'is_active' => true,
        ]);

        $resolved = ApprovalResolverService::resolve(divisiId: $divisi->id);

        $this->assertSame($firstRule->id, ApprovalRule::query()->orderBy('id')->value('id'));
        $this->assertSame($firstApprover->id, $resolved?->id);
    }

    public function test_rule_without_criteria_is_ignored_in_favor_of_a_specific_rule(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $catchAllApprover = $this->makeUser('Catch All Approver', 'catch_all', $role);
        $specificApprover = $this->makeUser('Narrow Approver', 'narrow_approver', $role);
        $area = Area::query()->create(['name' => 'Warehouse Area']);
        $divisi = Divisi::query()->create(['name' => 'Warehouse', 'area_id' => $area->id]);

        DB::table('approval_rules')->insert([
            'name' => 'Tanpa Kriteria',
            'approver_id' => $catchAllApprover->id,
            'priority' => 100,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ApprovalRule::query()->create([
            'name' => 'Divisi Warehouse',
            'divisi_id' => $divisi->id,
            'approver_id' => $specificApprover->id,
            'priority' => 0,
            'is_active' => true,
        ]);

        $resolved = ApprovalResolverService::resolve(divisiId: $divisi->id);

        $this->assertSame($specificApprover->id, $resolved?->id);
    }

    public function test_rule_without_criteria_does_not_replace_the_division_manager(): void
    {
        $role = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $manager = $this->makeUser('Warehouse Manager', 'warehouse_mgr', $role);
        $catchAllApprover = $this->makeUser('Global Approver', 'global_approver', $role);
        $area = Area::query()->create(['name' => 'Gudang Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Gudang',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);

        DB::table('approval_rules')->insert([
            'name' => 'Aturan Kosong',
            'approver_id' => $catchAllApprover->id,
            'priority' => 50,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($manager->id, ApprovalResolverService::resolve(divisiId: $divisi->id)?->id);
    }

    public function test_approval_rule_requires_at_least_one_criterion(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $approver = $this->makeUser('Rule Approver', 'rule_approver', $role);

        $this->expectException(ValidationException::class);

        ApprovalRule::query()->create([
            'name' => 'Aturan Tanpa Kriteria',
            'approver_id' => $approver->id,
            'priority' => 1,
            'is_active' => true,
        ]);
    }

    public function test_force_deleting_approver_clears_the_rule_instead_of_deleting_it(): void
    {
        $role = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $approver = $this->makeUser('Removable Approver', 'removable_approver', $role);
        $area = Area::query()->create(['name' => 'Procurement Area']);
        $divisi = Divisi::query()->create(['name' => 'Procurement', 'area_id' => $area->id]);
        $rule = ApprovalRule::query()->create([
            'name' => 'Aturan Procurement',
            'divisi_id' => $divisi->id,
            'approver_id' => $approver->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $approver->forceDelete();

        $rule->refresh();
        $this->assertNull($rule->approver_id);
    }

    public function test_bulk_approval_assignment_skips_self_and_cycles(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $approver = $this->makeUser('Target Approver', 'target_approver', $role);
        $subordinate = $this->makeUser('Subordinate', 'subordinate_user', $role);
        $approver->update(['approval_id' => $subordinate->id]);

        $result = UserResource::assignApprovalLine(
            new Collection([$approver->refresh(), $subordinate->refresh()]),
            (int) $approver->id,
        );

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped_self']);
        $this->assertSame(1, $result['skipped_cycles']);
        $this->assertNull($subordinate->refresh()->approval_id);

        $message = UserResource::approvalLineAssignmentMessage($result, $approver->nama_lengkap);
        $this->assertSame('warning', $message['status']);
        $this->assertStringContainsString('tidak boleh menjadi atasan dirinya sendiri', $message['body']);
        $this->assertStringContainsString('membentuk siklus', $message['body']);
    }

    public function test_bulk_sync_reports_updated_unchanged_cycle_and_unresolved_rows(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $manager = $this->makeUser('Sync Manager', 'sync_manager', $role);
        $alreadyCorrect = $this->makeUser('Already Correct', 'already_correct', $role);
        $needsUpdate = $this->makeUser('Needs Update', 'needs_update', $role);
        $cycleUser = $this->makeUser('Cycle User', 'cycle_user', $role);
        $unresolved = $this->makeUser('Unresolved User', 'unresolved_user', $role);
        $area = Area::query()->create(['name' => 'Sync Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Sync Division',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);

        $alreadyCorrect->update(['divisi_id' => $divisi->id, 'approval_id' => $manager->id]);
        $needsUpdate->update(['divisi_id' => $divisi->id, 'approval_id' => null]);
        $cycleUser->update(['divisi_id' => $divisi->id, 'approval_id' => null]);
        $manager->update(['approval_id' => $cycleUser->id]);

        $result = UserResource::syncApprovalLines(new Collection([
            $alreadyCorrect->refresh(),
            $needsUpdate->refresh(),
            $cycleUser->refresh(),
            $unresolved->refresh(),
        ]));

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(1, $result['skipped_cycles']);
        $this->assertSame(0, $result['skipped_self']);
        $this->assertSame(1, $result['unresolved']);
        $this->assertSame($manager->id, $needsUpdate->refresh()->approval_id);
        $this->assertNull($cycleUser->refresh()->approval_id);

        $message = UserResource::syncApprovalLinesMessage($result);
        $this->assertSame('success', $message['status']);
        $this->assertStringContainsString('1 karyawan disesuaikan', $message['body']);
        $this->assertStringContainsString('1 karyawan sudah sesuai', $message['body']);
        $this->assertStringContainsString('1 karyawan dilewati karena relasi approval membentuk siklus', $message['body']);
        $this->assertStringContainsString('1 karyawan tidak memiliki aturan atau Kepala Divisi', $message['body']);
    }

    public function test_suggested_approval_does_not_replace_a_manual_choice(): void
    {
        $this->assertTrue(UserResource::shouldApplySuggestedApproval(null, null, 10, 1));
        $this->assertTrue(UserResource::shouldApplySuggestedApproval(1, null, null, 1));
        $this->assertTrue(UserResource::shouldApplySuggestedApproval(8, 8, 10, 1));
        $this->assertFalse(UserResource::shouldApplySuggestedApproval(8, null, 10, 1));
        $this->assertFalse(UserResource::shouldApplySuggestedApproval(4, 8, null, 1));
    }

    public function test_archived_rule_approver_falls_through_to_division_manager(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $archived = $this->makeUser('Archived Approver', 'archived_approver', $role);
        $manager = $this->makeUser('Live Manager', 'live_manager', $role);
        $area = Area::query()->create(['name' => 'Archive Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Archive Division',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Aturan Arsip',
            'divisi_id' => $divisi->id,
            'approver_id' => $archived->id,
            'priority' => 10,
            'is_active' => true,
        ]);
        $archived->delete();

        $this->assertSame($manager->id, ApprovalResolverService::resolve(divisiId: $divisi->id)?->id);
    }

    public function test_cyclic_rule_falls_through_to_a_safe_approver(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $subject = $this->makeUser('Cycle Subject', 'cycle_subject', $role);
        $cyclicApprover = $this->makeUser('Cyclic Approver', 'cyclic_approver', $role);
        $safeApprover = $this->makeUser('Safe Approver', 'safe_approver', $role);
        $area = Area::query()->create(['name' => 'Fallback Area']);
        $divisi = Divisi::query()->create(['name' => 'Fallback Division', 'area_id' => $area->id]);
        $position = Position::query()->create(['name' => 'Fallback Position']);
        $cyclicApprover->update(['approval_id' => $subject->id]);

        ApprovalRule::query()->create([
            'name' => 'Aturan Spesifik Siklus',
            'divisi_id' => $divisi->id,
            'position_id' => $position->id,
            'approver_id' => $cyclicApprover->id,
            'priority' => 1,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Aturan Divisi Aman',
            'divisi_id' => $divisi->id,
            'approver_id' => $safeApprover->id,
            'priority' => 50,
            'is_active' => true,
        ]);

        $outcome = ApprovalResolverService::resolveOutcome(
            divisiId: $divisi->id,
            positionId: $position->id,
            excludeUserId: (int) $subject->id,
        );

        $this->assertSame($safeApprover->id, $outcome['approver']?->id);
        $this->assertFalse($outcome['blocked_by_cycle']);
    }

    public function test_negative_priority_and_level_are_rejected(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $approver = $this->makeUser('Priority Approver', 'priority_approver', $role);
        $area = Area::query()->create(['name' => 'Grade Area']);
        $divisi = Divisi::query()->create(['name' => 'Grade Division', 'area_id' => $area->id]);

        try {
            ApprovalRule::query()->create([
                'name' => 'Prioritas Negatif',
                'divisi_id' => $divisi->id,
                'approver_id' => $approver->id,
                'priority' => -1,
                'is_active' => true,
            ]);
            $this->fail('Prioritas negatif harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('priority', $exception->errors());
        }

        try {
            Role::query()->create(['name' => 'NEGATIF', 'level' => -5]);
            $this->fail('Level negatif harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('level', $exception->errors());
        }
    }

    public function test_admin_can_manage_approval_rules_and_non_admin_cannot(): void
    {
        $adminRole = Role::query()->create(['name' => 'ADMIN', 'level' => 100]);
        $leaderRole = Role::query()->create(['name' => 'TEAM LEADER', 'level' => 20]);
        $admin = $this->makeUser('Panel Admin', 'panel_admin', $adminRole);
        $leader = $this->makeUser('Panel Leader', 'panel_leader', $leaderRole);
        $approver = $this->makeUser('Panel Approver', 'panel_approver', $leaderRole);
        $staff = $this->makeUser('Panel Staff', 'panel_staff', $leaderRole);
        $area = Area::query()->create(['name' => 'Panel Area']);
        $divisi = Divisi::query()->create(['name' => 'Panel Division', 'area_id' => $area->id]);

        $this->actingAs($admin);
        Livewire::actingAs($admin);

        Livewire::test(ManageApprovalRules::class)
            ->callAction('create', [
                'name' => 'Aturan Panel',
                'approver_id' => $approver->id,
                'divisi_id' => $divisi->id,
                'priority' => 2,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('approval_rules', [
            'name' => 'Aturan Panel',
            'approver_id' => $approver->id,
            'divisi_id' => $divisi->id,
        ]);

        Livewire::test(ManageApprovalRules::class)
            ->callAction('create', [
                'name' => 'Aturan Tanpa Kriteria',
                'approver_id' => $approver->id,
                'priority' => 1,
                'is_active' => true,
            ])
            ->assertHasActionErrors(['name']);

        $this->assertDatabaseMissing('approval_rules', [
            'name' => 'Aturan Tanpa Kriteria',
        ]);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('update_approval_line', [$staff], [
                'approval_id' => $approver->id,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($approver->id, $staff->refresh()->approval_id);

        $this->actingAs($leader);
        Livewire::actingAs($leader);

        Livewire::test(ListUsers::class)
            ->assertTableBulkActionHidden('update_approval_line')
            ->assertTableBulkActionHidden('sync_approval_rules');

        $this->assertFalse(ApprovalRuleResource::canViewAny());
        $this->get('/admin/approval-rules')->assertForbidden();
    }

    public function test_admin_can_save_role_grade_and_division_manager_from_the_panel(): void
    {
        $adminRole = Role::query()->create(['name' => 'ADMIN', 'level' => 100]);
        $admin = $this->makeUser('Grade Admin', 'grade_admin', $adminRole);
        $manager = $this->makeUser('Division Head', 'division_head', $adminRole);
        $area = Area::query()->create(['name' => 'Grade Area']);

        $this->actingAs($admin);
        Livewire::actingAs($admin);

        Livewire::test(ManageRoles::class)
            ->callAction('create', [
                'name' => 'SUPERVISOR',
                'level' => 30,
                'requires_approval' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('roles', [
            'name' => 'SUPERVISOR',
            'level' => 30,
            'requires_approval' => true,
        ]);

        Livewire::test(ManageRoles::class)
            ->callAction('create', [
                'name' => 'NEGATIF PANEL',
                'level' => -1,
                'requires_approval' => false,
            ])
            ->assertHasActionErrors(['level']);

        Livewire::test(ManageDivisis::class)
            ->callAction('create', [
                'name' => 'Divisi Panel',
                'area_id' => $area->id,
                'manager_id' => $manager->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('divisis', [
            'name' => 'Divisi Panel',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);
    }

    public function test_partial_criterion_mismatch_does_not_use_that_rule(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $broadApprover = $this->makeUser('Broad Match', 'broad_match', $role);
        $narrowApprover = $this->makeUser('Narrow Miss', 'narrow_miss', $role);
        $area = Area::query()->create(['name' => 'Mismatch Area']);
        $divisi = Divisi::query()->create(['name' => 'Mismatch Division', 'area_id' => $area->id]);
        $wanted = Position::query()->create(['name' => 'Wanted Position']);
        $other = Position::query()->create(['name' => 'Other Position']);

        ApprovalRule::query()->create([
            'name' => 'Hanya Divisi',
            'divisi_id' => $divisi->id,
            'approver_id' => $broadApprover->id,
            'priority' => 1,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Divisi dan Posisi Lain',
            'divisi_id' => $divisi->id,
            'position_id' => $other->id,
            'approver_id' => $narrowApprover->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        $resolved = ApprovalResolverService::resolve(
            divisiId: $divisi->id,
            positionId: $wanted->id,
        );

        $this->assertSame($broadApprover->id, $resolved?->id);
    }

    public function test_inactive_rule_falls_through_to_the_division_manager(): void
    {
        $role = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $manager = $this->makeUser('Inactive Fallback Manager', 'inactive_fallback_mgr', $role);
        $ruled = $this->makeUser('Inactive Rule Approver', 'inactive_rule_approver', $role);
        $area = Area::query()->create(['name' => 'Inactive Fallback Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Inactive Fallback Division',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);

        ApprovalRule::query()->create([
            'name' => 'Aturan Mati',
            'divisi_id' => $divisi->id,
            'approver_id' => $ruled->id,
            'priority' => 99,
            'is_active' => false,
        ]);

        $outcome = ApprovalResolverService::resolveOutcome(divisiId: $divisi->id);

        $this->assertSame($manager->id, $outcome['approver']?->id);
        $this->assertSame('division_manager', $outcome['source']);
        $this->assertFalse($outcome['blocked_by_cycle']);
    }

    public function test_wrong_area_does_not_match_an_area_rule(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $approver = $this->makeUser('Area Approver', 'area_approver', $role);
        $jakarta = Area::query()->create(['name' => 'Jakarta Rule']);
        $surabaya = Area::query()->create(['name' => 'Surabaya Rule']);

        ApprovalRule::query()->create([
            'name' => 'Hanya Jakarta',
            'area_id' => $jakarta->id,
            'approver_id' => $approver->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $this->assertNull(ApprovalResolverService::resolve(areaId: $surabaya->id));
        $this->assertSame($approver->id, ApprovalResolverService::resolve(areaId: $jakarta->id)?->id);
    }

    public function test_same_specificity_skips_a_cyclic_higher_priority_rule(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $subject = $this->makeUser('Priority Subject', 'priority_subject', $role);
        $cyclic = $this->makeUser('Priority Cyclic', 'priority_cyclic', $role);
        $safe = $this->makeUser('Priority Safe', 'priority_safe', $role);
        $area = Area::query()->create(['name' => 'Priority Cycle Area']);
        $divisi = Divisi::query()->create(['name' => 'Priority Cycle Division', 'area_id' => $area->id]);
        $cyclic->update(['approval_id' => $subject->id]);

        ApprovalRule::query()->create([
            'name' => 'Prioritas Tinggi Siklus',
            'divisi_id' => $divisi->id,
            'approver_id' => $cyclic->id,
            'priority' => 20,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Prioritas Rendah Aman',
            'divisi_id' => $divisi->id,
            'approver_id' => $safe->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $outcome = ApprovalResolverService::resolveOutcome(
            divisiId: $divisi->id,
            excludeUserId: (int) $subject->id,
        );

        $this->assertSame($safe->id, $outcome['approver']?->id);
        $this->assertFalse($outcome['blocked_by_cycle']);
    }

    public function test_three_hop_approval_cycle_is_rejected_and_unrelated_links_are_not(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $first = $this->makeUser('Hop One', 'hop_one', $role);
        $second = $this->makeUser('Hop Two', 'hop_two', $role);
        $third = $this->makeUser('Hop Three', 'hop_three', $role);
        $outside = $this->makeUser('Hop Outside', 'hop_outside', $role);

        $second->update(['approval_id' => $third->id]);
        $third->update(['approval_id' => $first->id]);

        $this->assertTrue(ApprovalResolverService::createsApprovalCycle((int) $first->id, (int) $second->id));
        $this->assertFalse(ApprovalResolverService::createsApprovalCycle((int) $first->id, (int) $outside->id));
    }

    public function test_resolution_reasons_do_not_leak_between_calls(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $subject = $this->makeUser('Leak Subject', 'leak_subject', $role);
        $cyclic = $this->makeUser('Leak Cyclic', 'leak_cyclic', $role);
        $manager = $this->makeUser('Leak Manager', 'leak_manager', $role);
        $blockedArea = Area::query()->create(['name' => 'Leak Blocked Area']);
        $openArea = Area::query()->create(['name' => 'Leak Open Area']);
        $blockedDivision = Divisi::query()->create([
            'name' => 'Leak Blocked Division',
            'area_id' => $blockedArea->id,
            'manager_id' => $cyclic->id,
        ]);
        $openDivision = Divisi::query()->create([
            'name' => 'Leak Open Division',
            'area_id' => $openArea->id,
            'manager_id' => $manager->id,
        ]);
        $cyclic->update(['approval_id' => $subject->id]);

        $blocked = ApprovalResolverService::resolveOutcome(
            divisiId: $blockedDivision->id,
            excludeUserId: (int) $subject->id,
        );
        $this->assertNull($blocked['approver']);
        $this->assertTrue($blocked['blocked_by_cycle']);
        $this->assertFalse($blocked['excluded_self']);

        $open = ApprovalResolverService::resolveOutcome(divisiId: $openDivision->id);
        $this->assertSame($manager->id, $open['approver']?->id);
        $this->assertFalse($open['blocked_by_cycle']);
        $this->assertFalse($open['excluded_self']);
    }

    public function test_soft_deleted_manager_is_ignored_until_a_live_rule_matches(): void
    {
        $role = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $manager = $this->makeUser('Deleted Head', 'deleted_head', $role);
        $replacement = $this->makeUser('Replacement Lead', 'replacement_lead', $role);
        $area = Area::query()->create(['name' => 'Deleted Head Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Deleted Head Division',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);
        $manager->delete();

        $this->assertNull(ApprovalResolverService::resolve(divisiId: $divisi->id));

        ApprovalRule::query()->create([
            'name' => 'Pengganti Kepala',
            'divisi_id' => $divisi->id,
            'approver_id' => $replacement->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        $this->assertSame($replacement->id, ApprovalResolverService::resolve(divisiId: $divisi->id)?->id);
    }

    public function test_clearing_every_criterion_is_rejected(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $approver = $this->makeUser('Criterion Approver', 'criterion_approver', $role);
        $area = Area::query()->create(['name' => 'Criterion Area']);
        $divisi = Divisi::query()->create(['name' => 'Criterion Division', 'area_id' => $area->id]);
        $rule = ApprovalRule::query()->create([
            'name' => 'Masih Punya Divisi',
            'divisi_id' => $divisi->id,
            'approver_id' => $approver->id,
            'priority' => 1,
            'is_active' => true,
        ]);

        try {
            $rule->update([
                'area_id' => null,
                'divisi_id' => null,
                'position_id' => null,
                'role_id' => null,
            ]);
            $this->fail('Aturan tanpa kriteria harus ditolak saat diperbarui.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('area_id', $exception->errors());
        }

        $this->assertSame($divisi->id, $rule->refresh()->divisi_id);
    }

    public function test_zero_priority_and_level_are_allowed(): void
    {
        $role = Role::query()->create(['name' => 'TRAINEE', 'level' => 0]);
        $approver = $this->makeUser('Zero Approver', 'zero_approver', $role);
        $area = Area::query()->create(['name' => 'Zero Area']);
        $divisi = Divisi::query()->create(['name' => 'Zero Division', 'area_id' => $area->id]);

        $rule = ApprovalRule::query()->create([
            'name' => 'Prioritas Nol',
            'divisi_id' => $divisi->id,
            'approver_id' => $approver->id,
            'priority' => 0,
            'is_active' => true,
        ]);

        $this->assertSame(0, $role->level);
        $this->assertSame(0, $rule->priority);
        $this->assertSame($approver->id, ApprovalResolverService::resolve(divisiId: $divisi->id)?->id);
    }

    public function test_suggestion_text_explains_a_match_a_cycle_and_self_approval(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $subject = $this->makeUser('Suggestion Subject', 'suggestion_subject', $role);
        $approver = $this->makeUser('Suggestion Approver', 'suggestion_approver', $role);
        $area = Area::query()->create(['name' => 'Suggestion Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Suggestion Division',
            'area_id' => $area->id,
            'manager_id' => $subject->id,
        ]);

        $matched = UserResource::approvalLineSuggestionText($divisi->id, $area->id, null, null, null, true);
        $this->assertStringContainsString('Kepala Divisi Suggestion Division', $matched);
        $this->assertStringContainsString($subject->nama_lengkap, $matched);

        $subject->update(['approval_id' => $approver->id]);
        $approver->update(['approval_id' => $subject->id]);
        ApprovalRule::query()->create([
            'name' => 'Saran Siklus',
            'divisi_id' => $divisi->id,
            'approver_id' => $approver->id,
            'priority' => 5,
            'is_active' => true,
        ]);

        $cyclic = UserResource::approvalLineSuggestionText(
            $divisi->id,
            $area->id,
            null,
            null,
            (int) $subject->id,
            false,
        );
        $this->assertStringContainsString('membentuk siklus approval', $cyclic);

        $subject->update(['approval_id' => null]);
        $approver->update(['approval_id' => null]);
        ApprovalRule::query()->delete();

        $self = UserResource::approvalLineSuggestionText(
            $divisi->id,
            null,
            null,
            null,
            (int) $subject->id,
            false,
        );
        $this->assertStringContainsString('menunjuk karyawan ini sendiri', $self);
    }

    public function test_bulk_sync_reaches_the_best_approver_regardless_of_selection_order(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $safe = $this->makeUser('Order Safe', 'order_safe', $role);
        $blocked = $this->makeUser('Order Blocked', 'order_blocked', $role);
        $mover = $this->makeUser('Order Mover', 'order_mover', $role);
        $area = Area::query()->create(['name' => 'Order Area']);
        $divisi = Divisi::query()->create(['name' => 'Order Division', 'area_id' => $area->id]);
        $blockedPosition = Position::query()->create(['name' => 'Order Blocked Position']);
        $moverPosition = Position::query()->create(['name' => 'Order Mover Position']);

        ApprovalRule::query()->create([
            'name' => 'Blocked ke Mover',
            'divisi_id' => $divisi->id,
            'position_id' => $blockedPosition->id,
            'approver_id' => $mover->id,
            'priority' => 3,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Mover ke Safe',
            'divisi_id' => $divisi->id,
            'position_id' => $moverPosition->id,
            'approver_id' => $safe->id,
            'priority' => 3,
            'is_active' => true,
        ]);

        $blocked->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $blockedPosition->id,
        ]);
        $mover->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $moverPosition->id,
        ]);

        $batches = [
            new Collection([$blocked, $mover]),
            new Collection([$mover, $blocked]),
        ];

        foreach ($batches as $batch) {
            $blocked->update(['approval_id' => null]);
            $mover->update(['approval_id' => $blocked->id]);

            $result = UserResource::syncApprovalLines($batch);

            $this->assertSame(2, $result['updated']);
            $this->assertSame(0, $result['skipped_cycles']);
            $this->assertSame($mover->id, $blocked->refresh()->approval_id);
            $this->assertSame($safe->id, $mover->refresh()->approval_id);
            $this->assertFalse(ApprovalResolverService::createsApprovalCycle((int) $blocked->id, (int) $blocked->approval_id));
            $this->assertFalse(ApprovalResolverService::createsApprovalCycle((int) $mover->id, (int) $mover->approval_id));
        }
    }

    public function test_bulk_sync_upgrades_a_temporary_manager_fallback_after_the_cycle_breaks(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $manager = $this->makeUser('Upgrade Manager', 'upgrade_manager', $role);
        $blocked = $this->makeUser('Upgrade Blocked', 'upgrade_blocked', $role);
        $mover = $this->makeUser('Upgrade Mover', 'upgrade_mover', $role);
        $area = Area::query()->create(['name' => 'Upgrade Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Upgrade Division',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);
        $blockedPosition = Position::query()->create(['name' => 'Upgrade Blocked Position']);
        $moverPosition = Position::query()->create(['name' => 'Upgrade Mover Position']);

        ApprovalRule::query()->create([
            'name' => 'Upgrade ke Mover',
            'divisi_id' => $divisi->id,
            'position_id' => $blockedPosition->id,
            'approver_id' => $mover->id,
            'priority' => 4,
            'is_active' => true,
        ]);

        $blocked->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $blockedPosition->id,
            'approval_id' => $mover->id,
        ]);
        $mover->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $moverPosition->id,
            'approval_id' => $blocked->id,
        ]);

        $result = UserResource::syncApprovalLines(new Collection([$blocked, $mover]));

        $this->assertSame($mover->id, $blocked->refresh()->approval_id);
        $this->assertSame($manager->id, $mover->refresh()->approval_id);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertSame(0, $result['skipped_cycles']);
        $this->assertFalse(ApprovalResolverService::createsApprovalCycle((int) $blocked->id, (int) $blocked->approval_id));
    }

    public function test_bulk_sync_reports_when_the_only_candidate_is_the_employee(): void
    {
        $role = Role::query()->create(['name' => 'MANAGER', 'level' => 40]);
        $manager = $this->makeUser('Self Head', 'self_head', $role);
        $area = Area::query()->create(['name' => 'Self Head Area']);
        $divisi = Divisi::query()->create([
            'name' => 'Self Head Division',
            'area_id' => $area->id,
            'manager_id' => $manager->id,
        ]);
        $manager->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'approval_id' => null,
        ]);

        $result = UserResource::syncApprovalLines(new Collection([$manager->refresh()]));

        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(1, $result['skipped_self']);
        $this->assertNull($manager->refresh()->approval_id);

        $message = UserResource::syncApprovalLinesMessage($result);
        $this->assertSame('warning', $message['status']);
        $this->assertStringContainsString('tidak boleh menjadi atasan dirinya sendiri', $message['body']);
    }

    public function test_conflicting_batch_keeps_an_acyclic_result(): void
    {
        $role = Role::query()->create(['name' => 'STAFF', 'level' => 10]);
        $first = $this->makeUser('Conflict First', 'conflict_first', $role);
        $second = $this->makeUser('Conflict Second', 'conflict_second', $role);
        $bridge = $this->makeUser('Conflict Bridge', 'conflict_bridge', $role);
        $area = Area::query()->create(['name' => 'Conflict Area']);
        $divisi = Divisi::query()->create(['name' => 'Conflict Division', 'area_id' => $area->id]);
        $firstPosition = Position::query()->create(['name' => 'Conflict First Position']);
        $secondPosition = Position::query()->create(['name' => 'Conflict Second Position']);
        $bridge->update(['approval_id' => $second->id]);

        ApprovalRule::query()->create([
            'name' => 'First ke Bridge',
            'divisi_id' => $divisi->id,
            'position_id' => $firstPosition->id,
            'approver_id' => $bridge->id,
            'priority' => 2,
            'is_active' => true,
        ]);
        ApprovalRule::query()->create([
            'name' => 'Second ke First',
            'divisi_id' => $divisi->id,
            'position_id' => $secondPosition->id,
            'approver_id' => $first->id,
            'priority' => 2,
            'is_active' => true,
        ]);

        $first->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $firstPosition->id,
            'approval_id' => null,
        ]);
        $second->update([
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $secondPosition->id,
            'approval_id' => null,
        ]);

        $result = UserResource::syncApprovalLines(new Collection([$second, $first]));

        $this->assertSame($bridge->id, $first->refresh()->approval_id);
        $this->assertNull($second->refresh()->approval_id);
        $this->assertSame(1, $result['skipped_cycles']);
        $this->assertFalse(ApprovalResolverService::createsApprovalCycle((int) $first->id, (int) $first->approval_id));
    }

    private function makeUser(string $name, string $username, Role $role): User
    {
        return User::query()->create([
            'nama_lengkap' => $name,
            'username' => $username,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);
    }

    private static function ensureUserPlacement(User $user): void
    {
        if ($user->area_id && $user->divisi_id) {
            return;
        }

        $area = Area::query()->first() ?? Area::query()->create(['name' => 'Default Area']);

        if (! $user->area_id) {
            $user->area_id = $area->id;
        }

        if (! $user->divisi_id) {
            $divisi = Divisi::query()->where('area_id', $user->area_id)->first()
                ?? Divisi::query()->create([
                    'name' => 'Default Divisi',
                    'area_id' => $user->area_id,
                ]);
            $user->divisi_id = $divisi->id;
        }
    }
}
