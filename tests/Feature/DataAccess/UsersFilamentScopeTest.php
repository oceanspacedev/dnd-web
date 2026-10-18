<?php

namespace Tests\Feature\DataAccess;

use App\Filament\Exports\UserExporter;
use App\Filament\Pages\UserPositionGuide;
use App\Filament\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMembers\Pages\EditTeamMember;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\User;
use App\Services\DataAccessService;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Menu Karyawan (per badan usaha) dan Tim Saya (approval line) di panel Filament.
 *
 * Skenario: super admin "boss" (COO) melihat semua. Admin "ojan" (homebase COO) hanya boleh ULS
 * dan punya satu anggota tim di COO. Manager GMD hanya punya tim di GMD.
 */
class UsersFilamentScopeTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private Divisi $opsCoo;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $ulsAdminAccount;

    private User $ulsManager;

    private User $ulsStaff;

    private User $ulsArchived;

    private User $cooStaff;

    private User $cooTeam;

    private User $gmdManager;

    private User $gmdStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');
        // Divisi milik COO yang dipakai karyawan ULS: users.area_id tetap acuan cakupan.
        $this->opsCoo = $this->fixtureDivisi($this->coo, 'OPS COO');

        $this->superAdmin = $this->fixtureGlobalAdmin('boss', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);
        $this->ulsAdminAccount = $this->fixtureUser('uls_admin', 'ADMIN', $this->uls);
        $this->gmdManager = $this->fixtureUser('gmd_manager', 'MANAGER', $this->gmd);
        $this->gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd, $this->gmdManager);
        $this->ulsManager = $this->fixtureUser('uls_manager', 'MANAGER', $this->uls, $this->gmdManager);
        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, $this->ulsManager, [
            'divisi_id' => $this->opsCoo->id,
        ]);
        $this->ulsArchived = $this->fixtureUser('uls_archived', 'STAFF', $this->uls, $this->ulsManager);
        $this->ulsArchived->delete();
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo);
        $this->cooTeam = $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_menus_are_split_into_karyawan_and_tim_saya(): void
    {
        $this->assertSame('users', UserResource::getSlug());
        $this->assertSame('Karyawan', UserResource::getNavigationLabel());
        $this->assertSame(4, UserResource::getNavigationSort());
        $this->assertSame('tim-saya', TeamMemberResource::getSlug());
        $this->assertSame('Tim Saya', TeamMemberResource::getNavigationLabel());
        $this->assertSame(5, TeamMemberResource::getNavigationSort());
        $this->assertSame('heroicon-o-users', TeamMemberResource::getNavigationIcon());

        // Navigasi panel dibangun sekali per aplikasi di tes, jadi cek sidebar dilakukan pada request pertama.
        $this->actAs($this->scopedAdmin);
        $this->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSee(TeamMemberResource::getUrl('index'), false);

        $this->actAs($this->superAdmin);
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(UserResource::shouldRegisterNavigation());
        $this->assertFalse(TeamMemberResource::canViewAny(), 'Super admin tanpa tim tidak punya menu Tim Saya.');
        $this->get(UserResource::getUrl('index'))->assertOk();

        $this->actAs($this->scopedAdmin);
        $this->assertTrue(UserResource::canViewAny());
        $this->assertTrue(TeamMemberResource::canViewAny());
        $this->assertTrue(TeamMemberResource::shouldRegisterNavigation());
        $this->get(UserResource::getUrl('index'))->assertOk();
        $this->get(TeamMemberResource::getUrl('index'))->assertOk();
        $this->get(TeamMemberResource::getUrl('create'))->assertOk();

        $this->actAs($this->gmdManager);
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(UserResource::shouldRegisterNavigation());
        $this->assertTrue(TeamMemberResource::canViewAny());
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(TeamMemberResource::getUrl('index'))->assertOk();

        $this->actAs($this->cooStaff);
        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(TeamMemberResource::canViewAny());
    }

    public function test_super_admin_sees_every_employee_including_admins_and_archive(): void
    {
        $this->actAs($this->superAdmin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([
                $this->superAdmin,
                $this->scopedAdmin,
                $this->ulsAdminAccount,
                $this->ulsStaff,
                $this->cooStaff,
                $this->gmdStaff,
            ])
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$this->ulsArchived])
            ->assertCanNotSeeTableRecords([$this->ulsStaff]);
    }

    public function test_scoped_admin_karyawan_lists_only_allowed_areas_without_admin_accounts(): void
    {
        $this->actAs($this->scopedAdmin);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->ulsStaff, $this->ulsManager])
            ->assertCanNotSeeTableRecords([
                $this->cooStaff,
                $this->cooTeam,
                $this->gmdStaff,
                $this->ulsAdminAccount,
                $this->scopedAdmin,
                $this->superAdmin,
            ])
            ->set('activeTab', 'archived')
            ->assertCanSeeTableRecords([$this->ulsArchived]);

        // Tim Saya: anggota tim lintas badan usaha, tanpa tab Arsip.
        $teamList = Livewire::test(ListTeamMembers::class)
            ->assertCanSeeTableRecords([$this->cooTeam])
            ->assertCanNotSeeTableRecords([$this->ulsStaff, $this->cooStaff]);
        $this->assertSame([], $teamList->instance()->getTabs());
    }

    public function test_out_of_scope_records_cannot_be_opened(): void
    {
        $this->actAs($this->scopedAdmin);

        $this->get(UserResource::getUrl('edit', ['record' => $this->ulsStaff]))->assertOk();
        $this->get(UserResource::getUrl('edit', ['record' => $this->cooStaff]))->assertNotFound();
        $this->get(UserResource::getUrl('edit', ['record' => $this->ulsAdminAccount]))->assertNotFound();
        // Anggota tim di luar badan usaha hanya lewat menu Tim Saya.
        $this->get(UserResource::getUrl('edit', ['record' => $this->cooTeam]))->assertNotFound();
        $this->get(TeamMemberResource::getUrl('edit', ['record' => $this->cooTeam]))->assertOk();
        $this->get(TeamMemberResource::getUrl('edit', ['record' => $this->ulsStaff]))->assertNotFound();

        $this->assertTrue($this->scopedAdmin->can('update', $this->ulsStaff));
        $this->assertTrue($this->scopedAdmin->can('update', $this->cooTeam));
        $this->assertFalse($this->scopedAdmin->can('update', $this->cooStaff));
        $this->assertFalse($this->scopedAdmin->can('update', $this->ulsAdminAccount));

        $this->actAs($this->gmdManager);
        $this->assertContains(
            $this->get(UserResource::getUrl('edit', ['record' => $this->gmdStaff]))->status(),
            [403, 404],
        );
        $this->get(TeamMemberResource::getUrl('edit', ['record' => $this->gmdStaff]))->assertOk();
        // ulsStaff berada satu level di bawah ulsManager (bawahan langsung), jadi termasuk tim.
        $this->get(TeamMemberResource::getUrl('edit', ['record' => $this->ulsStaff]))->assertOk();
        $this->get(TeamMemberResource::getUrl('edit', ['record' => $this->cooStaff]))->assertNotFound();
    }

    public function test_scoped_admin_form_options_are_limited_to_scope(): void
    {
        $this->actAs($this->scopedAdmin);

        $create = Livewire::test(CreateUser::class)
            ->assertFormFieldIsEnabled('no_hp')
            ->assertFormFieldIsEnabled('approval_id')
            ->assertFormFieldHidden('data_access');
        $form = $create->instance()->form;

        $this->assertSame([$this->uls->id], array_keys($this->selectOptions($form, 'area_id')));
        $this->assertNotContains(
            $this->fixtureRole('ADMIN')->id,
            array_keys($this->selectOptions($form, 'role_id')),
        );

        $approvers = array_keys($this->selectOptions($form, 'approval_id'));
        $this->assertEqualsCanonicalizing(
            [$this->ulsStaff->id, $this->ulsManager->id, $this->ulsAdminAccount->id, $this->cooTeam->id, $this->scopedAdmin->id],
            $approvers,
        );

        // Edit: atasan yang sedang terpasang tetap muncul walau di luar cakupan.
        $edit = Livewire::test(EditUser::class, ['record' => $this->ulsManager->getRouteKey()]);
        $editApprovers = array_keys($this->selectOptions($edit->instance()->form, 'approval_id'));
        $this->assertContains($this->gmdManager->id, $editApprovers);
        $this->assertNotContains($this->gmdStaff->id, $editApprovers);
        $this->assertNotContains($this->cooStaff->id, $editApprovers);

        // Super admin tetap memilih dari seluruh badan usaha dan role.
        $this->actAs($this->superAdmin);
        $superForm = Livewire::test(CreateUser::class)->instance()->form;
        $this->assertEqualsCanonicalizing(
            [$this->coo->id, $this->uls->id, $this->gmd->id],
            array_keys($this->selectOptions($superForm, 'area_id')),
        );
        $this->assertContains($this->fixtureRole('ADMIN')->id, array_keys($this->selectOptions($superForm, 'role_id')));
        $this->assertContains($this->cooStaff->id, array_keys($this->selectOptions($superForm, 'approval_id')));
    }

    public function test_scoped_admin_cannot_create_or_move_employees_outside_scope(): void
    {
        $this->actAs($this->scopedAdmin);
        $staffRole = $this->fixtureRole('STAFF');
        $ulsDivisi = $this->fixtureDivisi($this->uls);
        $cooDivisi = $this->fixtureDivisi($this->coo);

        $payload = [
            'nama_lengkap' => 'Karyawan Baru ULS',
            'username' => 'baru_uls',
            'password' => 'complete123',
            'area_id' => $this->uls->id,
            'divisi_id' => $ulsDivisi->id,
            'role_id' => $staffRole->id,
            'approval_id' => $this->ulsManager->id,
        ];

        Livewire::test(CreateUser::class)
            ->fillForm([...$payload, 'username' => 'luar_area', 'area_id' => $this->coo->id, 'divisi_id' => $cooDivisi->id])
            ->call('create')
            ->assertHasFormErrors(['area_id']);
        Livewire::test(CreateUser::class)
            ->fillForm([...$payload, 'username' => 'atasan_luar', 'approval_id' => $this->cooStaff->id])
            ->call('create')
            ->assertHasFormErrors(['approval_id']);
        Livewire::test(CreateUser::class)
            ->fillForm([...$payload, 'username' => 'calon_admin', 'role_id' => $this->fixtureRole('ADMIN')->id])
            ->call('create')
            ->assertHasFormErrors(['role_id']);
        $this->assertDatabaseMissing('users', ['username' => 'luar_area']);
        $this->assertDatabaseMissing('users', ['username' => 'atasan_luar']);
        $this->assertDatabaseMissing('users', ['username' => 'calon_admin']);

        $createdPage = Livewire::test(CreateUser::class)
            ->fillForm([...$payload, 'no_hp' => '081398765432'])
            ->call('create')
            ->assertHasNoFormErrors();
        $created = User::query()->where('username', 'baru_uls')->firstOrFail();
        $createdPage->assertRedirect(UserResource::getUrl('edit', ['record' => $created]));
        $this->assertSame($this->uls->id, (int) $created->area_id);
        $this->assertSame('081398765432', $created->no_hp);
        $this->assertSame(User::DATA_ACCESS_ALL, $created->data_access);

        // Penjaga server (tanpa melewati opsi form) menolak state Livewire yang dimanipulasi.
        $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData(['area_id' => $this->gmd->id]), 'data.area_id');
        $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData(['approval_id' => $this->gmdStaff->id]), 'data.approval_id');
        $this->assertValidationFails(
            fn () => UserResource::mutateAuthorizedData(['area_id' => $this->gmd->id], $this->cooTeam),
            'data.area_id',
        );

        // Anggota tim di COO: badan usaha lama boleh dipertahankan. Pindah ke badan usaha di luar cakupan
        // sekaligus keluar dari tim ditolak (lihat test_scoped_admin_places_team_members_in_any_area_while_they_stay_in_team).
        Livewire::test(EditTeamMember::class, ['record' => $this->cooTeam->getRouteKey()])
            ->fillForm(['nama_lengkap' => 'COO TEAM UPDATED'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('COO TEAM UPDATED', $this->cooTeam->refresh()->nama_lengkap);

        Livewire::test(EditTeamMember::class, ['record' => $this->cooTeam->getRouteKey()])
            ->fillForm([
                'area_id' => $this->gmd->id,
                'divisi_id' => $this->fixtureDivisi($this->gmd)->id,
                'approval_id' => $this->ulsStaff->id,
            ])
            ->call('save')
            ->assertHasFormErrors(['area_id']);
        $this->assertSame($this->coo->id, (int) $this->cooTeam->refresh()->area_id);
        $this->assertSame($this->scopedAdmin->id, (int) $this->cooTeam->approval_id);

        // Atasan lama di luar cakupan tetap boleh dipertahankan saat edit.
        Livewire::test(EditUser::class, ['record' => $this->ulsManager->getRouteKey()])
            ->fillForm(['nama_lengkap' => 'ULS MANAGER UPDATED'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->gmdManager->id, (int) $this->ulsManager->refresh()->approval_id);
    }

    public function test_approval_matrix_suggestion_outside_scope_is_accepted_for_scoped_admin(): void
    {
        $ulsDivisi = $this->fixtureDivisi($this->uls);
        $ulsDivisi->update(['manager_id' => $this->gmdManager->id]);

        $this->actAs($this->scopedAdmin);

        $data = UserResource::mutateAuthorizedData([
            'area_id' => $this->uls->id,
            'divisi_id' => $ulsDivisi->id,
            'role_id' => $this->fixtureRole('STAFF')->id,
            'approval_id' => $this->gmdManager->id,
        ]);
        $this->assertSame($this->gmdManager->id, $data['approval_id']);

        $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData([
            'area_id' => $this->uls->id,
            'divisi_id' => $ulsDivisi->id,
            'role_id' => $this->fixtureRole('STAFF')->id,
            'approval_id' => $this->gmdStaff->id,
        ]), 'data.approval_id');
    }

    public function test_super_admin_manages_data_access_of_admin_accounts(): void
    {
        $this->actAs($this->superAdmin);

        Livewire::test(EditUser::class, ['record' => $this->ulsAdminAccount->getRouteKey()])
            ->assertFormFieldVisible('data_access')
            ->fillForm([
                'data_access' => User::DATA_ACCESS_SELECTED,
                'dataAccessAreas' => [$this->gmd->id, $this->uls->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->ulsAdminAccount->refresh();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->ulsAdminAccount->data_access);
        $this->assertEqualsCanonicalizing(
            [$this->gmd->id, $this->uls->id],
            $this->ulsAdminAccount->dataAccessAreas()->pluck('areas.id')->all(),
        );
        $this->assertSame('GMD, ULS', UserResource::dataAccessLabel($this->ulsAdminAccount));
        $this->assertSame('Semua', UserResource::dataAccessLabel($this->superAdmin));
        $this->assertSame('-', UserResource::dataAccessLabel($this->ulsStaff));

        // 'selected' tanpa badan usaha ditolak.
        Livewire::test(EditUser::class, ['record' => $this->scopedAdmin->getRouteKey()])
            ->fillForm(['dataAccessAreas' => []])
            ->call('save')
            ->assertHasFormErrors(['dataAccessAreas']);

        // Bukan ADMIN lagi => akses data kembali 'all' dan daftar badan usaha dikosongkan.
        Livewire::test(EditUser::class, ['record' => $this->ulsAdminAccount->getRouteKey()])
            ->fillForm(['role_id' => $this->fixtureRole('STAFF')->id])
            ->assertFormFieldHidden('data_access')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->ulsAdminAccount->refresh();
        $this->assertSame(User::DATA_ACCESS_ALL, $this->ulsAdminAccount->data_access);
        $this->assertSame(0, $this->ulsAdminAccount->dataAccessAreas()->count());

        // Super admin tidak dapat membatasi akses datanya sendiri.
        Livewire::test(EditUser::class, ['record' => $this->superAdmin->getRouteKey()])
            ->fillForm(['data_access' => User::DATA_ACCESS_SELECTED, 'dataAccessAreas' => [$this->uls->id]])
            ->call('save')
            ->assertHasFormErrors(['data_access']);
        $this->assertSame(User::DATA_ACCESS_ALL, $this->superAdmin->refresh()->data_access);
        $this->assertSame(0, $this->superAdmin->dataAccessAreas()->count());

        // Admin baru tanpa memilih apa pun memakai default fail closed 'Badan Usaha Tertentu' (badan usaha sendiri).
        // Super admin hanya dengan konfirmasi (lihat tests/Feature/UxFixes/UserFixesTest.php).
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nama_lengkap' => 'Admin Default',
                'username' => 'admin_default',
                'password' => 'complete123',
                'area_id' => $this->coo->id,
                'divisi_id' => $this->fixtureDivisi($this->coo)->id,
                'role_id' => $this->fixtureRole('ADMIN')->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $adminDefault = User::query()->where('username', 'admin_default')->firstOrFail();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $adminDefault->data_access);
        $this->assertSame([$this->coo->id], $adminDefault->dataAccessAreas()->pluck('areas.id')->all());

        // Membuat admin baru dengan akses badan usaha tertentu.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nama_lengkap' => 'Admin GMD',
                'username' => 'admin_gmd',
                'password' => 'complete123',
                'area_id' => $this->gmd->id,
                'divisi_id' => $this->fixtureDivisi($this->gmd)->id,
                'role_id' => $this->fixtureRole('ADMIN')->id,
            ])
            ->assertFormFieldVisible('data_access')
            ->fillForm([
                'data_access' => User::DATA_ACCESS_SELECTED,
                'dataAccessAreas' => [$this->gmd->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $created = User::query()->where('username', 'admin_gmd')->firstOrFail();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $created->data_access);
        $this->assertSame([$this->gmd->id], $created->dataAccessAreas()->pluck('areas.id')->all());
    }

    public function test_data_access_settings_are_stripped_for_non_super_admins(): void
    {
        $this->actAs($this->scopedAdmin);

        Livewire::test(EditUser::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->assertFormFieldHidden('data_access');

        $withoutRole = UserResource::mutateAuthorizedData(['data_access' => User::DATA_ACCESS_SELECTED]);
        $this->assertArrayNotHasKey('data_access', $withoutRole);

        $staffRole = UserResource::mutateAuthorizedData([
            'data_access' => User::DATA_ACCESS_SELECTED,
            'role_id' => $this->fixtureRole('STAFF')->id,
        ]);
        $this->assertSame(User::DATA_ACCESS_ALL, $staffRole['data_access']);

        $this->assertValidationFails(
            fn () => UserResource::mutateAuthorizedData(['role_id' => $this->fixtureRole('ADMIN')->id]),
            'data.role_id',
        );
    }

    public function test_imports_are_super_admin_only(): void
    {
        $this->actAs($this->superAdmin);
        $this->assertTrue(UserResource::canImportUsers());

        $this->actAs($this->scopedAdmin);
        $this->assertFalse(UserResource::canImportUsers());
        $this->assertFalse(TeamMemberResource::canImportUsers());

        $list = Livewire::test(ListUsers::class);
        $this->assertHttpForbidden(fn () => $list->instance()->processImport(['file' => 'x.xlsx']));
        $this->assertHttpForbidden(fn () => $list->instance()->processJsonImport(['file' => 'x.json']));

        $this->actAs($this->gmdManager);
        $teamList = Livewire::test(ListTeamMembers::class);
        $this->assertHttpForbidden(fn () => $teamList->instance()->processImport(['file' => 'x.xlsx']));
    }

    public function test_table_filter_options_are_scoped_for_area_scoped_admin(): void
    {
        $this->actAs($this->scopedAdmin);

        $list = Livewire::test(ListUsers::class);
        $filters = $list->instance()->getTableFiltersForm();

        $this->assertSame([$this->uls->id], array_keys($this->filterOptions($filters, 'area')));
        $this->assertEqualsCanonicalizing(
            [$this->opsCoo->id, $this->fixtureDivisi($this->uls)->id],
            array_keys($this->filterOptions($filters, 'divisi')),
        );
        $this->assertEqualsCanonicalizing(
            [$this->ulsManager->id, $this->gmdManager->id],
            array_keys($this->filterOptions($filters, 'approval')),
        );

        $list->filterTable('divisi', $this->opsCoo->id)
            ->assertCanSeeTableRecords([$this->ulsStaff])
            ->assertCanNotSeeTableRecords([$this->ulsManager, $this->cooStaff]);

        // Filter relasi approval (self-relation) tetap bekerja dengan opsi yang dibatasi.
        Livewire::test(ListUsers::class)
            ->filterTable('approval', $this->gmdManager->id)
            ->assertCanSeeTableRecords([$this->ulsManager])
            ->assertCanNotSeeTableRecords([$this->ulsStaff, $this->gmdStaff]);
        Livewire::test(ListUsers::class)
            ->filterTable('area', $this->uls->id)
            ->assertCanSeeTableRecords([$this->ulsStaff, $this->ulsManager])
            ->assertCanNotSeeTableRecords([$this->cooStaff]);

        // Tim Saya admin badan usaha tertentu: opsi dari anggota timnya.
        $teamFilters = Livewire::test(ListTeamMembers::class)->instance()->getTableFiltersForm();
        $this->assertSame([$this->coo->id], array_keys($this->filterOptions($teamFilters, 'area')));
        $this->assertSame([$this->scopedAdmin->id], array_keys($this->filterOptions($teamFilters, 'approval')));

        $this->actAs($this->superAdmin);
        $superFilters = Livewire::test(ListUsers::class)->instance()->getTableFiltersForm();
        $this->assertEqualsCanonicalizing(
            [$this->coo->id, $this->uls->id, $this->gmd->id],
            array_keys($this->filterOptions($superFilters, 'area')),
        );
    }

    public function test_admin_bulk_actions_recheck_scope(): void
    {
        $this->actAs($this->scopedAdmin);

        Livewire::test(ListUsers::class)
            ->assertTableBulkActionVisible('update_approval_line')
            ->assertTableBulkActionVisible('sync_approval_rules')
            ->assertTableActionVisible('send_reminder', $this->ulsStaff)
            ->callTableBulkAction('update_approval_line', [$this->ulsStaff], [
                'approval_id' => $this->cooStaff->id,
            ])
            ->assertHasTableBulkActionErrors(['approval_id']);
        $this->assertSame($this->ulsManager->id, (int) $this->ulsStaff->refresh()->approval_id);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('update_approval_line', [$this->ulsStaff], [
                'approval_id' => $this->cooTeam->id,
            ])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame($this->cooTeam->id, (int) $this->ulsStaff->refresh()->approval_id);

        // Atasan non-admin di Tim Saya tidak melihat aksi admin.
        $this->actAs($this->gmdManager);
        Livewire::test(ListTeamMembers::class)
            ->assertTableBulkActionHidden('update_approval_line')
            ->assertTableBulkActionHidden('sync_approval_rules')
            ->assertTableActionHidden('send_reminder', $this->gmdStaff);
    }

    public function test_supervisor_team_menu_keeps_today_rules(): void
    {
        $this->actAs($this->gmdManager);
        $staffRole = $this->fixtureRole('STAFF');
        $gmdDivisi = $this->fixtureDivisi($this->gmd);

        Livewire::test(ListTeamMembers::class)
            ->assertCanSeeTableRecords([$this->gmdStaff, $this->ulsManager, $this->ulsStaff])
            ->assertCanNotSeeTableRecords([$this->cooStaff, $this->cooTeam]);

        $create = Livewire::test(CreateTeamMember::class)
            ->assertFormFieldIsDisabled('approval_id')
            ->assertFormFieldIsDisabled('no_hp');
        $this->assertSame([$this->gmdManager->id], array_keys($this->selectOptions($create->instance()->form, 'approval_id')));

        $payload = [
            'nama_lengkap' => 'Staff GMD Baru',
            'username' => 'gmd_baru',
            'password' => 'complete123',
            'area_id' => $this->gmd->id,
            'divisi_id' => $gmdDivisi->id,
            'role_id' => $staffRole->id,
        ];

        // State approval_id yang dimanipulasi (field disabled) ditolak di server.
        Livewire::test(CreateTeamMember::class)
            ->fillForm([...$payload, 'username' => 'gmd_palsu', 'approval_id' => $this->cooStaff->id])
            ->call('create')
            ->assertHasFormErrors(['approval_id']);
        $this->assertDatabaseMissing('users', ['username' => 'gmd_palsu']);
        $this->assertValidationFails(
            fn () => UserResource::mutateAuthorizedData(['approval_id' => $this->cooStaff->id]),
            'data.approval_id',
        );

        $createdPage = Livewire::test(CreateTeamMember::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasNoFormErrors();
        $created = User::query()->where('username', 'gmd_baru')->firstOrFail();
        $this->assertSame($this->gmdManager->id, (int) $created->approval_id);
        $this->assertStringStartsWith(
            TeamMemberResource::getUrl('index'),
            (string) data_get($createdPage->effects, 'redirect'),
        );

        // Edit anggota tim: atasan aktif mana pun masih boleh dipilih seperti sebelumnya.
        Livewire::test(EditTeamMember::class, ['record' => $this->gmdStaff->getRouteKey()])
            ->fillForm(['approval_id' => $this->ulsManager->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->ulsManager->id, (int) $this->gmdStaff->refresh()->approval_id);
    }

    public function test_export_query_never_exceeds_actor_scope(): void
    {
        $this->actAs($this->superAdmin);
        $this->assertSame(
            User::query()->count(),
            UserExporter::modifyQuery(User::query())->count(),
        );

        $this->actAs($this->scopedAdmin);
        $this->assertEqualsCanonicalizing(
            [$this->ulsAdminAccount->id, $this->ulsManager->id, $this->ulsStaff->id, $this->cooTeam->id, $this->scopedAdmin->id],
            UserExporter::modifyQuery(User::query())->pluck('id')->all(),
        );

        $this->actAs($this->gmdManager);
        $this->assertNotContains(
            $this->cooStaff->id,
            UserExporter::modifyQuery(User::query())->pluck('id')->all(),
        );
    }

    public function test_user_position_guide_points_to_the_right_menu(): void
    {
        $this->actAs($this->scopedAdmin);
        $this->assertTrue(UserPositionGuide::canAccess());
        $this->assertSame(UserResource::getUrl('index'), (new UserPositionGuide)->getUserListUrl());

        $this->actAs($this->gmdManager);
        $this->assertTrue(UserPositionGuide::canAccess());
        $this->assertSame(TeamMemberResource::getUrl('index'), (new UserPositionGuide)->getUserListUrl());
        Livewire::test(UserPositionGuide::class)
            ->assertSuccessful()
            ->assertSee('Buka Menu Tim Saya')
            ->assertSee('Admin Badan Usaha Tertentu');

        $this->actAs($this->cooStaff);
        $this->assertFalse(UserPositionGuide::canAccess());
    }

    public function test_scoped_admin_cannot_pull_out_of_scope_employees_into_team_via_approval_line(): void
    {
        // Chief ULS (dalam cakupan) memimpin staff GMD (di luar cakupan ojan).
        $ulsChief = $this->fixtureUser('uls_chief', 'CHIEF', $this->uls, $this->gmdManager);
        $gmdSub = $this->fixtureUser('gmd_sub', 'STAFF', $this->gmd, $ulsChief);

        $this->actAs($this->scopedAdmin);
        $this->assertFalse(DataAccessService::canSeeUser($this->scopedAdmin, $gmdSub));

        // Form: menjadikan dirinya atasan chief akan memasukkan staff GMD ke Tim Saya-nya.
        Livewire::test(EditUser::class, ['record' => $ulsChief->getRouteKey()])
            ->fillForm(['approval_id' => $this->scopedAdmin->id])
            ->call('save')
            ->assertHasFormErrors(['approval_id']);
        $this->assertSame($this->gmdManager->id, (int) $ulsChief->refresh()->approval_id);

        // Aksi massal dan helper-nya melewati karyawan tersebut.
        Livewire::test(ListUsers::class)
            ->callTableBulkAction('update_approval_line', [$ulsChief], ['approval_id' => $this->scopedAdmin->id])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame($this->gmdManager->id, (int) $ulsChief->refresh()->approval_id);

        $assigned = UserResource::assignApprovalLine(new Collection([$ulsChief]), $this->scopedAdmin->id);
        $this->assertSame(0, $assigned['updated']);
        $this->assertSame(1, $assigned['skipped_scope']);
        $this->assertStringContainsString(
            'di luar cakupan akses data Anda',
            UserResource::approvalLineAssignmentMessage($assigned, 'OJAN')['body'],
        );

        // Saran matriks (ojan Kepala Divisi ULS) juga tidak boleh memperluas cakupan.
        $salesUls = $this->fixtureDivisi($this->uls, 'SALES ULS');
        $salesUls->update(['manager_id' => $this->scopedAdmin->id]);
        $ulsChief->update(['divisi_id' => $salesUls->id]);
        $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData([
            'area_id' => $this->uls->id,
            'divisi_id' => $salesUls->id,
            'approval_id' => $this->scopedAdmin->id,
        ], $ulsChief->refresh()), 'data.approval_id');

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('sync_approval_rules', [$ulsChief]);
        $this->assertSame($this->gmdManager->id, (int) $ulsChief->refresh()->approval_id);
        $synced = UserResource::syncApprovalLines(new Collection([$ulsChief]));
        $this->assertSame(0, $synced['updated']);
        $this->assertSame(1, $synced['skipped_scope']);

        // Menjadikan bawahan langsungnya (cooTeam) atasan karyawan di luar cakupan juga ditolak.
        $this->assertTrue(UserResource::approvalWidensActorScope($this->scopedAdmin, $gmdSub->id, $this->cooTeam->id));
        $this->assertFalse(UserResource::approvalWidensActorScope($this->scopedAdmin, $this->ulsStaff->id, $this->cooTeam->id));

        $this->resetAccessMemos();
        $this->assertFalse(DataAccessService::canSeeUser($this->scopedAdmin, $gmdSub));
        $this->get(TeamMemberResource::getUrl('edit', ['record' => $gmdSub]))->assertNotFound();

        // Karyawan yang seluruh bawahannya sudah terlihat (ulsStaff di ULS) tetap boleh dijadikan bawahannya.
        Livewire::test(EditUser::class, ['record' => $this->ulsManager->getRouteKey()])
            ->fillForm(['approval_id' => $this->scopedAdmin->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->scopedAdmin->id, (int) $this->ulsManager->refresh()->approval_id);

        // Super admin dan atasan non-admin memakai aturan sebelumnya.
        $this->assertFalse(UserResource::approvalWidensActorScope($this->superAdmin, $ulsChief->id, $this->superAdmin->id));
        $this->assertFalse(UserResource::approvalWidensActorScope($this->gmdManager, $ulsChief->id, $this->gmdManager->id));
    }

    public function test_inline_position_creation_is_unchanged_for_every_editor(): void
    {
        foreach ([[$this->superAdmin, CreateUser::class, 'Posisi Super Admin'], [$this->scopedAdmin, CreateUser::class, 'Posisi Admin ULS']] as [$actor, $page, $name]) {
            $this->actAs($actor);
            Livewire::test($page)
                ->assertFormComponentActionVisible('position_id', 'createOption')
                ->callFormComponentAction('position_id', 'createOption', ['name' => $name])
                ->assertHasNoFormComponentActionErrors();
            $this->assertDatabaseHas('positions', ['name' => $name]);
        }

        // Atasan non-admin tetap dapat menambah posisi seperti sebelum fitur akses data.
        $this->actAs($this->gmdManager);
        Livewire::test(EditTeamMember::class, ['record' => $this->gmdStaff->getRouteKey()])
            ->assertFormComponentActionVisible('position_id', 'createOption')
            ->callFormComponentAction('position_id', 'createOption', ['name' => 'Posisi Atasan'])
            ->assertHasNoFormComponentActionErrors();
        $this->assertDatabaseHas('positions', ['name' => 'Posisi Atasan']);
    }

    public function test_last_global_admin_cannot_drop_their_own_admin_role(): void
    {
        $this->actAs($this->superAdmin);
        $staffRoleId = $this->fixtureRole('STAFF')->id;

        // Jadikan boss satu-satunya super admin (ojan dibatasi badan usaha tertentu).
        $this->ulsAdminAccount->update(['role_id' => $staffRoleId]);
        $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData([
            'role_id' => $staffRoleId,
        ], $this->superAdmin), 'data.role_id');

        // Admin lain boleh diturunkan, dan setelah ada super admin lain boss juga boleh.
        $this->assertSame(User::DATA_ACCESS_ALL, UserResource::mutateAuthorizedData([
            'role_id' => $staffRoleId,
        ], $this->scopedAdmin)['data_access']);

        $this->fixtureGlobalAdmin('boss_two', $this->coo);
        $this->assertSame(User::DATA_ACCESS_ALL, UserResource::mutateAuthorizedData([
            'role_id' => $staffRoleId,
        ], $this->superAdmin)['data_access']);
    }

    public function test_approval_suggestion_ignores_out_of_scope_form_state(): void
    {
        // Kepala divisi GMD dan kepala divisi ULS sama-sama gmdManager.
        $gmdDivisi = $this->fixtureDivisi($this->gmd, 'GMD SALES');
        $gmdDivisi->update(['manager_id' => $this->gmdManager->id]);
        $ulsDivisi = $this->fixtureDivisi($this->uls, 'ULS SALES');
        $ulsDivisi->update(['manager_id' => $this->gmdManager->id]);

        $this->actAs($this->scopedAdmin);

        // State Livewire dimanipulasi ke divisi badan usaha lain: nama atasan tidak boleh muncul.
        $tampered = Livewire::test(CreateUser::class)
            ->set('data.area_id', $this->gmd->id)
            ->set('data.divisi_id', $gmdDivisi->id)
            ->set('data.role_id', $this->fixtureRole('STAFF')->id)
            ->assertDontSee('Disarankan otomatis')
            ->assertDontSee('GMD MANAGER');
        $this->assertNotContains($this->gmdManager->id, array_keys($this->selectOptions($tampered->instance()->form, 'approval_id')));
        $this->assertNotSame($this->gmdManager->id, (int) data_get($tampered->get('data'), 'approval_id'));

        // Divisi badan usaha lain dengan badan usaha yang diizinkan juga diabaikan.
        $mixed = Livewire::test(CreateUser::class)
            ->set('data.area_id', $this->uls->id)
            ->set('data.divisi_id', $gmdDivisi->id)
            ->assertDontSee('Disarankan otomatis')
            ->assertDontSee('GMD MANAGER');
        $this->assertNotContains($this->gmdManager->id, array_keys($this->selectOptions($mixed->instance()->form, 'approval_id')));
        $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData([
            'area_id' => $this->uls->id,
            'divisi_id' => $gmdDivisi->id,
            'approval_id' => $this->gmdManager->id,
        ]), 'data.approval_id');

        // Divisi di badan usaha yang diizinkan: saran tetap tampil dan bisa dipilih.
        $allowed = Livewire::test(CreateUser::class)
            ->fillForm(['area_id' => $this->uls->id, 'divisi_id' => $ulsDivisi->id])
            ->assertSee('Disarankan otomatis: Kepala Divisi ULS SALES (GMD MANAGER)');
        $this->assertContains($this->gmdManager->id, array_keys($this->selectOptions($allowed->instance()->form, 'approval_id')));
        $this->assertSame($this->gmdManager->id, (int) data_get($allowed->get('data'), 'approval_id'));

        // Super admin tidak terpengaruh.
        $this->actAs($this->superAdmin);
        Livewire::test(CreateUser::class)
            ->set('data.area_id', $this->gmd->id)
            ->set('data.divisi_id', $gmdDivisi->id)
            ->assertSee('Disarankan otomatis: Kepala Divisi GMD SALES (GMD MANAGER)');
    }

    public function test_halted_save_rolls_back_data_access_areas(): void
    {
        $this->actAs($this->superAdmin);

        // cooTeam melapor ke ojan, jadi ojan -> cooTeam membentuk siklus dan simpan dibatalkan.
        Livewire::test(EditUser::class, ['record' => $this->scopedAdmin->getRouteKey()])
            ->fillForm([
                'nama_lengkap' => 'OJAN BARU',
                'dataAccessAreas' => [$this->uls->id, $this->gmd->id],
                'approval_id' => $this->cooTeam->id,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Approval tidak valid');

        $this->scopedAdmin->refresh();
        $this->assertSame('OJAN', $this->scopedAdmin->nama_lengkap);
        $this->assertNull($this->scopedAdmin->approval_id);
        $this->assertSame([$this->uls->id], $this->scopedAdmin->dataAccessAreas()->pluck('areas.id')->all());

        // Simpan yang valid tetap menyimpan relasi.
        Livewire::test(EditUser::class, ['record' => $this->scopedAdmin->getRouteKey()])
            ->fillForm(['dataAccessAreas' => [$this->uls->id, $this->gmd->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertEqualsCanonicalizing(
            [$this->uls->id, $this->gmd->id],
            $this->scopedAdmin->dataAccessAreas()->pluck('areas.id')->all(),
        );
    }

    public function test_scoped_admin_places_team_members_in_any_area_while_they_stay_in_team(): void
    {
        $this->actAs($this->scopedAdmin);
        $cooDivisi = $this->fixtureDivisi($this->coo);
        $gmdDivisi = $this->fixtureDivisi($this->gmd);

        // Tim Saya: seperti atasan lain, semua badan usaha bisa dipilih.
        $create = Livewire::test(CreateTeamMember::class);
        $this->assertEqualsCanonicalizing(
            [$this->coo->id, $this->uls->id, $this->gmd->id],
            array_keys($this->selectOptions($create->instance()->form, 'area_id')),
        );

        $create->fillForm([
            'nama_lengkap' => 'Anggota COO Baru',
            'username' => 'anggota_coo',
            'password' => 'complete123',
            'area_id' => $this->coo->id,
            'divisi_id' => $cooDivisi->id,
            'role_id' => $this->fixtureRole('STAFF')->id,
            'approval_id' => $this->scopedAdmin->id,
        ])
            ->call('create')
            ->assertHasNoFormErrors();
        $created = User::query()->where('username', 'anggota_coo')->firstOrFail();
        $this->assertSame($this->coo->id, (int) $created->area_id);
        $this->assertSame($this->scopedAdmin->id, (int) $created->approval_id);

        // Anggota tim yang tetap di timnya boleh dipindah ke badan usaha lain.
        Livewire::test(EditTeamMember::class, ['record' => $this->cooTeam->getRouteKey()])
            ->fillForm(['area_id' => $this->gmd->id, 'divisi_id' => $gmdDivisi->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->gmd->id, (int) $this->cooTeam->refresh()->area_id);

        // Bawahan langsung (cooTeam) sebagai atasan: karyawan baru tetap anggota tim.
        $this->resetAccessMemos();
        $this->assertSame(
            ['area_id' => $this->coo->id, 'approval_id' => $this->cooTeam->id],
            array_intersect_key(
                TeamMemberResource::mutateAuthorizedData(['area_id' => $this->coo->id, 'approval_id' => $this->cooTeam->id]),
                ['area_id' => true, 'approval_id' => true],
            ),
        );

        // Di luar tim dan di luar badan usaha yang diizinkan: ditolak.
        $this->assertValidationFails(
            fn () => TeamMemberResource::mutateAuthorizedData(['area_id' => $this->coo->id, 'approval_id' => $this->ulsStaff->id]),
            'data.area_id',
        );

        // Menu Karyawan tetap dibatasi ke badan usaha yang diizinkan.
        $this->assertValidationFails(
            fn () => UserResource::mutateAuthorizedData(['area_id' => $this->coo->id, 'approval_id' => $this->scopedAdmin->id]),
            'data.area_id',
        );
        $this->assertSame(
            [$this->uls->id],
            array_keys($this->selectOptions(Livewire::test(CreateUser::class)->instance()->form, 'area_id')),
        );
    }

    private function actAs(User $user): void
    {
        $this->resetAccessMemos();
        $this->actingAs($user);
        Livewire::actingAs($user);
    }

    /**
     * @return array<int|string, string>
     */
    private function selectOptions(Schema $schema, string $name): array
    {
        $field = collect($schema->getFlatFields(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === $name);

        $this->assertInstanceOf(Select::class, $field, "Field {$name} tidak ditemukan.");

        return $this->flattenOptions($field->getOptions());
    }

    /**
     * @return array<int|string, string>
     */
    private function filterOptions(Schema $filters, string $filter): array
    {
        $field = collect($filters->getFlatFields(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Select && str_ends_with((string) $component->getStatePath(), "{$filter}.value"));

        $this->assertInstanceOf(Select::class, $field, "Filter {$filter} tidak ditemukan.");

        return $this->flattenOptions($field->getOptions());
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return array<int|string, string>
     */
    private function flattenOptions(array $options): array
    {
        $flat = [];

        foreach ($options as $key => $label) {
            if (is_array($label)) {
                $flat += $label;

                continue;
            }

            $flat[$key] = (string) $label;
        }

        return $flat;
    }

    private function assertValidationFails(callable $callback, string $key): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());

            return;
        }

        $this->fail("ValidationException untuk {$key} tidak dilempar.");
    }

    private function assertHttpForbidden(callable $callback): void
    {
        try {
            $callback();
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());

            return;
        }

        $this->fail('Akses tidak ditolak dengan 403.');
    }
}
