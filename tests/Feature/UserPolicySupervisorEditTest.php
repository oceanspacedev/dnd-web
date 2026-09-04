<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPolicySupervisorEditTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApprovalScopeService::clearMemo();
        parent::tearDown();
    }

    public function test_supervisor_with_any_position_can_update_their_subordinates(): void
    {
        ApprovalScopeService::clearMemo();

        $roleTeamLeader = Role::firstOrCreate(['name' => 'TEAM LEADER']);
        $roleStaff = Role::firstOrCreate(['name' => 'STAFF']);
        $area = Area::first() ?? Area::create(['name' => 'Test Area']);
        $divisi = Divisi::first() ?? Divisi::create(['name' => 'Test Divisi', 'area_id' => $area->id]);
        $posSupervisor = Position::firstOrCreate(['name' => 'Lead Developer']);
        $posStaff = Position::firstOrCreate(['name' => 'Junior Developer']);

        $supervisor = User::create([
            'nama_lengkap' => 'Supervisor Lead',
            'username' => 'spv_'.uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $roleTeamLeader->id,
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $posSupervisor->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $subordinate = User::create([
            'nama_lengkap' => 'Subordinate Staff',
            'username' => 'sub_'.uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $roleStaff->id,
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $posStaff->id,
            'approval_id' => $supervisor->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $otherUser = User::create([
            'nama_lengkap' => 'Other Staff',
            'username' => 'other_'.uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $roleStaff->id,
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $posStaff->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        // Supervisor can viewAny
        $this->assertTrue($supervisor->can('viewAny', User::class));

        // Supervisor can view and update their subordinate
        $this->assertTrue($supervisor->can('view', $subordinate));
        $this->assertTrue($supervisor->can('update', $subordinate));

        // Supervisor CANNOT update a user outside their approval scope
        $this->assertFalse($supervisor->can('update', $otherUser));

        // Staff without subordinates cannot update
        $this->assertFalse($subordinate->can('update', $otherUser));

        // Clean up
        $subordinate->forceDelete();
        $otherUser->forceDelete();
        $supervisor->forceDelete();
    }
}
