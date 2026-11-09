<?php

namespace Tests\Feature;

use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Area;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Aksi massal Ubah Area Massal: pasangan Ubah Posisi Massal untuk kolom users.area_id,
 * dengan opsi dan pengecekan server mengikuti cakupan badan usaha admin.
 */
class UserBulkUpdateAreaTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $ulsManager;

    private User $ulsStaff;

    private User $ulsStaff2;

    private User $cooTeam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');

        $this->superAdmin = $this->fixtureGlobalAdmin('boss', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls, $this->gmd]);
        $this->ulsManager = $this->fixtureUser('uls_manager', 'MANAGER', $this->uls);
        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, $this->ulsManager);
        $this->ulsStaff2 = $this->fixtureUser('uls_staff_2', 'STAFF', $this->uls, $this->ulsManager);
        $this->cooTeam = $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_user_resource_table_registers_update_area_bulk_action(): void
    {
        $this->actAs($this->superAdmin);

        $bulkAction = $this->bulkAction('update_area');

        $this->assertInstanceOf(BulkAction::class, $bulkAction);
        $this->assertSame('Ubah Area Massal', $bulkAction->getLabel());
        $this->assertTrue($bulkAction->isModalSlideOver());
        $modalWidth = $bulkAction->getModalWidth();
        $this->assertSame('md', is_object($modalWidth) && property_exists($modalWidth, 'value') ? $modalWidth->value : (string) $modalWidth);
    }

    public function test_update_area_bulk_action_moves_selected_employees(): void
    {
        $this->actAs($this->superAdmin);

        $records = new Collection([$this->ulsStaff, $this->ulsStaff2]);
        $this->bulkAction('update_area')->call(['records' => $records, 'data' => ['area_id' => $this->gmd->id]]);

        $this->assertSame($this->gmd->id, (int) $this->ulsStaff->fresh()->area_id);
        $this->assertSame($this->gmd->id, (int) $this->ulsStaff2->fresh()->area_id);
        // Karyawan lain tidak tersentuh.
        $this->assertSame($this->uls->id, (int) $this->ulsManager->fresh()->area_id);
    }

    public function test_super_admin_moves_employees_through_the_table(): void
    {
        $this->actAs($this->superAdmin);

        $this->assertEqualsCanonicalizing(
            [$this->coo->id, $this->uls->id, $this->gmd->id],
            array_keys(UserResource::bulkAreaOptions()),
        );

        Livewire::test(ListUsers::class)
            ->assertTableBulkActionVisible('update_area')
            ->callTableBulkAction('update_area', [$this->ulsStaff, $this->ulsStaff2], [
                'area_id' => $this->coo->id,
            ])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Area Berhasil Diperbarui');

        $this->assertSame($this->coo->id, (int) $this->ulsStaff->refresh()->area_id);
        $this->assertSame($this->coo->id, (int) $this->ulsStaff2->refresh()->area_id);
    }

    public function test_scoped_admin_karyawan_menu_only_offers_allowed_areas(): void
    {
        $this->actAs($this->scopedAdmin);

        // Opsi hanya badan usaha yang diizinkan (COO, badan usaha admin sendiri, tidak termasuk).
        $this->assertEqualsCanonicalizing(
            [$this->uls->id, $this->gmd->id],
            array_keys(UserResource::bulkAreaOptions()),
        );

        // Nilai di luar opsi ditolak validasi form; karyawan tidak berubah.
        Livewire::test(ListUsers::class)
            ->callTableBulkAction('update_area', [$this->ulsStaff], [
                'area_id' => $this->coo->id,
            ])
            ->assertHasTableBulkActionErrors(['area_id']);
        $this->assertSame($this->uls->id, (int) $this->ulsStaff->refresh()->area_id);

        // Pindah antar badan usaha yang diizinkan berhasil.
        Livewire::test(ListUsers::class)
            ->callTableBulkAction('update_area', [$this->ulsStaff], [
                'area_id' => $this->gmd->id,
            ])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame($this->gmd->id, (int) $this->ulsStaff->refresh()->area_id);
    }

    public function test_assign_area_skips_moves_outside_scope_without_failing_the_rest(): void
    {
        $this->actAs($this->scopedAdmin);

        // Pengecekan sisi server tetap berlaku walau opsi form dilewati.
        $result = UserResource::assignArea(new Collection([$this->ulsStaff, $this->ulsStaff2]), $this->coo->id);

        $this->assertSame(['updated' => 0, 'unchanged' => 0, 'skipped_scope' => 2], $result);
        $this->assertSame($this->uls->id, (int) $this->ulsStaff->refresh()->area_id);
        $this->assertSame($this->uls->id, (int) $this->ulsStaff2->refresh()->area_id);

        $message = UserResource::areaAssignmentMessage($result, 'COO');
        $this->assertSame('Area Tidak Diubah', $message['title']);
        $this->assertSame('warning', $message['status']);
        $this->assertStringContainsString('di luar cakupan akses data Anda', $message['body']);

        // Karyawan yang sudah di badan usaha tujuan dihitung terpisah dan tidak disentuh.
        $this->ulsStaff2->forceFill(['area_id' => $this->gmd->id])->save();
        $result = UserResource::assignArea(new Collection([$this->ulsStaff, $this->ulsStaff2]), $this->gmd->id);

        $this->assertSame(['updated' => 1, 'unchanged' => 1, 'skipped_scope' => 0], $result);
        $this->assertSame($this->gmd->id, (int) $this->ulsStaff->refresh()->area_id);

        $message = UserResource::areaAssignmentMessage($result, 'GMD');
        $this->assertSame('Area Berhasil Diperbarui', $message['title']);
        $this->assertSame('success', $message['status']);
        $this->assertStringContainsString('1 karyawan berhasil dipindahkan ke area GMD.', $message['body']);
        $this->assertStringContainsString('1 karyawan sudah berada di area GMD.', $message['body']);
    }

    public function test_team_menu_lets_scoped_admin_move_team_members_to_any_area(): void
    {
        $this->actAs($this->scopedAdmin);

        // Tim Saya: seperti form anggota tim, semua badan usaha bisa dipilih.
        $this->assertEqualsCanonicalizing(
            [$this->coo->id, $this->uls->id, $this->gmd->id],
            array_keys(TeamMemberResource::bulkAreaOptions()),
        );

        // cooTeam tetap anggota tim (atasannya admin ini), jadi boleh pindah ke badan usaha di luar cakupan.
        Livewire::test(ListTeamMembers::class)
            ->assertTableBulkActionVisible('update_area')
            ->callTableBulkAction('update_area', [$this->cooTeam], [
                'area_id' => $this->coo->id,
            ])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Area Tidak Diubah');
        $this->assertSame($this->coo->id, (int) $this->cooTeam->refresh()->area_id);

        $this->cooTeam->forceFill(['area_id' => $this->uls->id])->save();
        $this->resetAccessMemos();

        Livewire::test(ListTeamMembers::class)
            ->callTableBulkAction('update_area', [$this->cooTeam], [
                'area_id' => $this->coo->id,
            ])
            ->assertHasNoTableBulkActionErrors()
            ->assertNotified('Area Berhasil Diperbarui');
        $this->assertSame($this->coo->id, (int) $this->cooTeam->refresh()->area_id);
    }

    public function test_supervisor_moves_team_members_from_tim_saya(): void
    {
        $this->actAs($this->ulsManager);

        Livewire::test(ListTeamMembers::class)
            ->assertTableBulkActionVisible('update_area')
            ->callTableBulkAction('update_area', [$this->ulsStaff, $this->ulsStaff2], [
                'area_id' => $this->gmd->id,
            ])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame($this->gmd->id, (int) $this->ulsStaff->refresh()->area_id);
        $this->assertSame($this->gmd->id, (int) $this->ulsStaff2->refresh()->area_id);
    }

    private function actAs(User $user): void
    {
        $this->resetAccessMemos();
        $this->actingAs($user);
        Livewire::actingAs($user);
    }

    private function bulkAction(string $name): BulkAction
    {
        $page = resolve(ListUsers::class);
        $table = UserResource::table(Table::make($page));

        $bulkGroup = collect($table->getToolbarActions())->first(fn ($action) => $action instanceof BulkActionGroup);
        $this->assertNotNull($bulkGroup, 'BulkActionGroup should be registered in toolbarActions.');

        $flatActions = $bulkGroup->getFlatActions();
        $this->assertArrayHasKey($name, $flatActions, "Bulk action {$name} should exist.");

        return $flatActions[$name];
    }
}
