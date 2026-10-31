<?php

namespace Tests\Feature\UxFixes;

use App\Filament\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMembers\Pages\EditTeamMember;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\LeaderboardKPI;
use App\Imports\UsersImport;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\UserJsonImportService;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Perbaikan UX bagian karyawan:
 * - FIX 1: Divisi karyawan tidak lagi difilter divisis.area_id (data nyata: divisi hanya ada di COO/CSO,
 *   karyawan badan usaha lain memakai divisi itu), termasuk filter divisi leaderboard.
 * - FIX 2: menjadikan karyawan ADMIN gagal tertutup (Badan Usaha Tertentu, badan usaha sendiri);
 *   super admin hanya dengan konfirmasi eksplisit. Admin yang sudah ada tidak berubah.
 */
class UserFixesTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private Divisi $opsCoo;

    private Divisi $salesCoo;

    private Divisi $cooOnly;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $ulsManager;

    private User $ulsStaff;

    private User $cooStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');

        // Seperti data nyata: divisi hanya tercatat di COO, tetapi dipakai karyawan ULS.
        $this->opsCoo = Divisi::query()->create(['name' => 'OPERASIONAL', 'area_id' => $this->coo->id]);
        $this->salesCoo = Divisi::query()->create(['name' => 'SALES', 'area_id' => $this->coo->id]);
        $this->cooOnly = Divisi::query()->create(['name' => 'KHUSUS COO', 'area_id' => $this->coo->id]);

        $this->superAdmin = $this->fixtureGlobalAdmin('boss', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls]);
        $this->ulsManager = $this->fixtureUser('uls_manager', 'MANAGER', $this->uls, null, ['divisi_id' => $this->opsCoo->id]);
        $this->ulsStaff = $this->fixtureUser('uls_staff', 'STAFF', $this->uls, $this->ulsManager, ['divisi_id' => $this->opsCoo->id]);
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo, null, ['divisi_id' => $this->cooOnly->id]);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    // FIX 1a: Divisi pada form karyawan.

    public function test_divisi_options_list_every_division_without_area_headings(): void
    {
        // Nama divisi sama di dua badan usaha: hanya divisi itu yang diberi nama badan usaha.
        $umumCoo = $this->fixtureDivisi($this->coo);
        $umumGmd = $this->fixtureDivisi($this->gmd);

        $this->actAs($this->superAdmin);

        $page = Livewire::test(CreateUser::class)
            ->fillForm(['area_id' => $this->uls->id])
            ->assertSee('Divisi tidak harus sama dengan Area karyawan.');
        $options = $this->rawSelectOptions($page->instance()->form, 'divisi_id');

        // Daftar datar tanpa judul badan usaha (divisis.area_id tidak bisa diandalkan), tidak difilter Area (ULS).
        $this->assertContainsOnly('string', $options);
        $this->assertSame('OPERASIONAL', $options[$this->opsCoo->id]);
        $this->assertSame('KHUSUS COO', $options[$this->cooOnly->id]);
        $this->assertSame('UMUM (COO)', $options[$umumCoo->id]);
        $this->assertSame('UMUM (GMD)', $options[$umumGmd->id]);
        $this->assertEqualsCanonicalizing(Divisi::query()->pluck('id')->all(), array_keys($options));
        $this->assertSame(['KHUSUS COO', 'OPERASIONAL', 'SALES', 'UMUM (COO)', 'UMUM (GMD)', 'UMUM (ULS)'], array_values($options));

        // Admin badan usaha tertentu dan atasan (Tim Saya) melihat daftar yang sama, tanpa nama badan usaha lain.
        $this->actAs($this->scopedAdmin);
        $this->assertSame($options, $this->rawSelectOptions(Livewire::test(CreateUser::class)->instance()->form, 'divisi_id'));

        $this->actAs($this->ulsManager);
        $this->assertSame($options, $this->rawSelectOptions(Livewire::test(CreateTeamMember::class)->instance()->form, 'divisi_id'));
    }

    public function test_changing_area_keeps_the_chosen_divisi(): void
    {
        $this->actAs($this->superAdmin);

        Livewire::test(CreateUser::class)
            ->fillForm(['area_id' => $this->coo->id, 'divisi_id' => $this->opsCoo->id])
            ->fillForm(['area_id' => $this->uls->id])
            ->assertFormSet(['area_id' => $this->uls->id, 'divisi_id' => $this->opsCoo->id]);
    }

    public function test_uls_employee_with_coo_divisi_can_be_created_and_saved(): void
    {
        $this->actAs($this->superAdmin);

        // Simpan karyawan ULS yang divisinya milik COO tanpa mengubah divisi (sebelumnya: "The selected divisi is invalid.").
        Livewire::test(EditUser::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->fillForm(['nama_lengkap' => 'ULS STAFF EDIT'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('ULS STAFF EDIT', $this->ulsStaff->refresh()->nama_lengkap);
        $this->assertSame($this->opsCoo->id, (int) $this->ulsStaff->divisi_id);

        // Pindah ke divisi COO lain tetap boleh.
        Livewire::test(EditUser::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->fillForm(['divisi_id' => $this->salesCoo->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->salesCoo->id, (int) $this->ulsStaff->refresh()->divisi_id);

        // Karyawan ULS baru dengan divisi COO.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nama_lengkap' => 'Staf ULS Baru',
                'username' => 'staf_uls_baru',
                'password' => 'complete123',
                'area_id' => $this->uls->id,
                'divisi_id' => $this->opsCoo->id,
                'role_id' => $this->fixtureRole('STAFF')->id,
                'approval_id' => $this->ulsManager->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $created = User::query()->where('username', 'staf_uls_baru')->firstOrFail();
        $this->assertSame($this->uls->id, (int) $created->area_id);
        $this->assertSame($this->opsCoo->id, (int) $created->divisi_id);

        // Admin badan usaha tertentu (ULS) juga bisa menyimpan karyawan ULS dengan divisi COO.
        $this->actAs($this->scopedAdmin);
        Livewire::test(EditUser::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->fillForm(['divisi_id' => $this->opsCoo->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->opsCoo->id, (int) $this->ulsStaff->refresh()->divisi_id);
    }

    public function test_team_member_with_divisi_from_another_area_can_be_saved_in_tim_saya(): void
    {
        $this->actAs($this->ulsManager);

        Livewire::test(EditTeamMember::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->fillForm(['nama_lengkap' => 'ANGGOTA TIM ULS'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('ANGGOTA TIM ULS', $this->ulsStaff->refresh()->nama_lengkap);
        $this->assertSame($this->opsCoo->id, (int) $this->ulsStaff->divisi_id);
    }

    public function test_scoped_admin_gets_the_approval_suggestion_for_divisions_used_in_its_areas(): void
    {
        $cooManager = $this->fixtureUser('coo_manager', 'MANAGER', $this->coo);
        $this->opsCoo->update(['manager_id' => $cooManager->id]);
        $this->cooOnly->update(['manager_id' => $cooManager->id]);

        $this->actAs($this->scopedAdmin);

        // OPERASIONAL tercatat di COO tetapi dipakai karyawan ULS: saran tetap dihitung untuk karyawan ULS.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nama_lengkap' => 'Staf Ops ULS',
                'username' => 'staf_ops_uls',
                'password' => 'complete123',
                'area_id' => $this->uls->id,
                'divisi_id' => $this->opsCoo->id,
                'role_id' => $this->fixtureRole('STAFF')->id,
            ])
            ->assertSee('Disarankan otomatis: Kepala Divisi OPERASIONAL (COO MANAGER)')
            ->assertFormSet(['approval_id' => $cooManager->id])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame($cooManager->id, (int) User::query()->where('username', 'staf_ops_uls')->value('approval_id'));

        // Divisi yang tidak dipakai di badan usaha yang diizinkan (hanya karyawan COO): tetap tanpa saran.
        $other = Livewire::test(CreateUser::class)
            ->fillForm(['area_id' => $this->uls->id, 'divisi_id' => $this->cooOnly->id])
            ->assertDontSee('Disarankan otomatis')
            ->assertDontSee('COO MANAGER');
        $this->assertNotSame($cooManager->id, (int) data_get($other->get('data'), 'approval_id'));
    }

    // FIX 1b: filter divisi leaderboard.

    public function test_leaderboard_division_options_follow_employees_of_the_selected_area(): void
    {
        foreach ([$this->superAdmin, $this->ulsStaff] as $viewer) {
            $this->actAs($viewer);

            $component = Livewire::test(LeaderboardKPI::class, ['month' => '2026-09'])->assertSuccessful();

            // Tanpa badan usaha: semua divisi.
            $this->assertEqualsCanonicalizing(
                Divisi::query()->pluck('id')->all(),
                $this->callProtected($component->instance(), 'getDivisions')->pluck('id')->all(),
            );

            // ULS: divisi yang benar-benar dipakai karyawan ULS (divisi COO), bukan divisis.area_id = ULS.
            $component->set('area', (string) $this->uls->id);
            $ulsDivisionIds = $this->callProtected($component->instance(), 'getDivisions')->pluck('id')->all();
            $this->assertContains($this->opsCoo->id, $ulsDivisionIds);
            $this->assertNotContains($this->cooOnly->id, $ulsDivisionIds);
            $this->assertNotContains($this->salesCoo->id, $ulsDivisionIds);

            // Memilih divisi itu menampilkan karyawan ULS di dalamnya.
            $component->set('division', (string) $this->opsCoo->id)
                ->assertSee('ULS STAFF')
                ->assertDontSee('COO STAFF');
        }
    }

    public function test_leaderboard_clears_a_division_the_new_area_does_not_use(): void
    {
        $retail = Divisi::query()->create(['name' => 'RETAIL', 'area_id' => $this->coo->id]);
        $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd, null, ['divisi_id' => $retail->id]);

        foreach ([$this->superAdmin, $this->ulsStaff] as $viewer) {
            $this->actAs($viewer);

            // ULS + OPERASIONAL, lalu Area GMD (karyawannya hanya RETAIL): filter divisi tidak tertinggal tersembunyi.
            Livewire::test(LeaderboardKPI::class, ['month' => '2026-09'])
                ->set('area', (string) $this->uls->id)
                ->set('division', (string) $this->opsCoo->id)
                ->set('area', (string) $this->gmd->id)
                ->assertSet('division', '')
                ->assertSee('GMD STAFF');

            // Divisi yang masih ada di daftar Area baru (semua badan usaha) tetap terpilih.
            Livewire::test(LeaderboardKPI::class, ['month' => '2026-09'])
                ->set('area', (string) $this->uls->id)
                ->set('division', (string) $this->opsCoo->id)
                ->set('area', '')
                ->assertSet('division', (string) $this->opsCoo->id)
                ->assertSee('ULS STAFF')
                ->assertDontSee('GMD STAFF');
        }
    }

    // FIX 2a: form Filament.

    public function test_new_admin_defaults_to_own_area_and_needs_no_confirmation(): void
    {
        $this->actAs($this->superAdmin);

        // Area lebih dulu, lalu Jabatan ADMIN.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'nama_lengkap' => 'Admin ULS',
                'username' => 'admin_uls',
                'password' => 'complete123',
                'area_id' => $this->uls->id,
                'divisi_id' => $this->opsCoo->id,
                'role_id' => $this->fixtureRole('ADMIN')->id,
            ])
            ->assertFormFieldVisible('data_access')
            ->assertFormSet([
                'data_access' => User::DATA_ACCESS_SELECTED,
                'dataAccessAreas' => [(string) $this->uls->id],
            ])
            ->assertFormFieldHidden('confirm_global_access')
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('username', 'admin_uls')->firstOrFail();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $created->data_access);
        $this->assertSame([$this->uls->id], $created->dataAccessAreas()->pluck('areas.id')->all());
        $this->assertFalse(DataAccessService::hasGlobalAccess($created));

        // Jabatan lebih dulu, Area kemudian: badan usaha tetap terisi otomatis dan ikut berganti.
        Livewire::test(CreateUser::class)
            ->fillForm(['role_id' => $this->fixtureRole('ADMIN')->id])
            ->assertFormSet(['data_access' => User::DATA_ACCESS_SELECTED, 'dataAccessAreas' => []])
            ->fillForm(['area_id' => $this->gmd->id])
            ->assertFormSet(['dataAccessAreas' => [(string) $this->gmd->id]])
            ->fillForm(['area_id' => $this->uls->id])
            ->assertFormSet(['dataAccessAreas' => [(string) $this->uls->id]]);
    }

    public function test_choosing_all_areas_for_a_new_admin_requires_the_confirmation(): void
    {
        $this->actAs($this->superAdmin);

        $payload = [
            'nama_lengkap' => 'Calon Super Admin',
            'username' => 'calon_super',
            'password' => 'complete123',
            'area_id' => $this->coo->id,
            'divisi_id' => $this->opsCoo->id,
            'role_id' => $this->fixtureRole('ADMIN')->id,
        ];

        Livewire::test(CreateUser::class)
            ->fillForm($payload)
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldVisible('confirm_global_access')
            ->call('create')
            ->assertHasFormErrors(['confirm_global_access' => 'accepted']);
        $this->assertDatabaseMissing('users', ['username' => 'calon_super']);

        Livewire::test(CreateUser::class)
            ->fillForm($payload)
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL, 'confirm_global_access' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('username', 'calon_super')->firstOrFail();
        $this->assertSame(User::DATA_ACCESS_ALL, $created->data_access);
        $this->assertSame(0, $created->dataAccessAreas()->count());
        $this->assertTrue(DataAccessService::hasGlobalAccess($created));
        $this->assertArrayNotHasKey('confirm_global_access', $created->getAttributes());
    }

    public function test_promoting_an_employee_in_the_edit_form_defaults_to_own_area(): void
    {
        $this->actAs($this->superAdmin);

        Livewire::test(EditUser::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->assertFormFieldHidden('data_access')
            ->fillForm(['role_id' => $this->fixtureRole('ADMIN')->id])
            ->assertFormFieldVisible('data_access')
            ->assertFormSet([
                'data_access' => User::DATA_ACCESS_SELECTED,
                'dataAccessAreas' => [(string) $this->uls->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->ulsStaff->refresh();
        $this->assertTrue($this->ulsStaff->isAdmin());
        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->ulsStaff->data_access);
        $this->assertSame([$this->uls->id], $this->ulsStaff->dataAccessAreas()->pluck('areas.id')->all());

        // Promosi ke super admin dari edit: wajib konfirmasi.
        Livewire::test(EditUser::class, ['record' => $this->cooStaff->getRouteKey()])
            ->fillForm(['role_id' => $this->fixtureRole('ADMIN')->id])
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldVisible('confirm_global_access')
            ->call('save')
            ->assertHasFormErrors(['confirm_global_access']);
        $this->assertFalse($this->cooStaff->refresh()->isAdmin());

        Livewire::test(EditUser::class, ['record' => $this->cooStaff->getRouteKey()])
            ->fillForm(['role_id' => $this->fixtureRole('ADMIN')->id])
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL, 'confirm_global_access' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->cooStaff->refresh();
        $this->assertTrue(DataAccessService::hasGlobalAccess($this->cooStaff));
        $this->assertSame(0, $this->cooStaff->dataAccessAreas()->count());
    }

    public function test_widening_a_scoped_admin_to_all_areas_requires_the_confirmation(): void
    {
        $this->actAs($this->superAdmin);

        Livewire::test(EditUser::class, ['record' => $this->scopedAdmin->getRouteKey()])
            ->assertFormSet(['data_access' => User::DATA_ACCESS_SELECTED])
            ->assertFormFieldHidden('confirm_global_access')
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldVisible('confirm_global_access')
            ->call('save')
            ->assertHasFormErrors(['confirm_global_access']);
        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->scopedAdmin->refresh()->data_access);

        Livewire::test(EditUser::class, ['record' => $this->scopedAdmin->getRouteKey()])
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL, 'confirm_global_access' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(User::DATA_ACCESS_ALL, $this->scopedAdmin->refresh()->data_access);
        $this->assertSame(0, $this->scopedAdmin->dataAccessAreas()->count());

        // Mengganti cakupan mengosongkan centang konfirmasi.
        Livewire::test(EditUser::class, ['record' => $this->ulsManager->getRouteKey()])
            ->fillForm(['role_id' => $this->fixtureRole('ADMIN')->id])
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL, 'confirm_global_access' => true])
            ->fillForm(['data_access' => User::DATA_ACCESS_SELECTED])
            ->assertFormSet(['confirm_global_access' => false])
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormSet(['confirm_global_access' => false]);
    }

    public function test_changing_area_of_an_existing_admin_keeps_its_data_access(): void
    {
        $ulsAdmin = $this->fixtureScopedAdmin('admin_uls', $this->uls, [$this->uls]);
        $emptyAdmin = $this->fixtureScopedAdmin('admin_kosong', $this->gmd, []);
        $adminRoleId = $this->fixtureRole('ADMIN')->id;
        $this->actAs($this->superAdmin);

        // Mutasi Area admin yang sudah ada tidak mengganti daftar badan usahanya diam-diam.
        $page = Livewire::test(EditUser::class, ['record' => $ulsAdmin->getRouteKey()])
            ->assertDontSee('Terisi otomatis dengan Area karyawan ini')
            ->fillForm(['area_id' => $this->coo->id]);
        $this->assertSame([$this->uls->id], array_map('intval', (array) $page->get('data.dataAccessAreas')));
        $page->call('save')->assertHasNoFormErrors();

        $ulsAdmin->refresh();
        $this->assertSame($this->coo->id, (int) $ulsAdmin->area_id);
        $this->assertSame(User::DATA_ACCESS_SELECTED, $ulsAdmin->data_access);
        $this->assertSame([$this->uls->id], $ulsAdmin->dataAccessAreas()->pluck('areas.id')->all());

        // Admin dengan daftar kosong (fail closed) juga tidak mendapat badan usaha baru, termasuk saat
        // Jabatan ADMIN dipilih ulang.
        $empty = Livewire::test(EditUser::class, ['record' => $emptyAdmin->getRouteKey()])
            ->fillForm(['area_id' => $this->uls->id])
            ->fillForm(['role_id' => $adminRoleId])
            ->assertFormSet(['data_access' => User::DATA_ACCESS_SELECTED]);
        $this->assertSame([], (array) $empty->get('data.dataAccessAreas'));

        // Promosi di form yang sama tetap mengikuti Area, dan isian otomatis itu dijelaskan di helper.
        Livewire::test(EditUser::class, ['record' => $this->ulsStaff->getRouteKey()])
            ->fillForm(['role_id' => $adminRoleId])
            ->assertFormSet(['dataAccessAreas' => [(string) $this->uls->id]])
            ->assertSee('Terisi otomatis dengan Area karyawan ini; hapus bila admin tidak perlu mengelola area tersebut.')
            ->fillForm(['area_id' => $this->gmd->id])
            ->assertFormSet(['dataAccessAreas' => [(string) $this->gmd->id]]);
    }

    public function test_existing_super_admins_see_no_confirmation_and_stay_global(): void
    {
        $otherSuperAdmin = $this->fixtureGlobalAdmin('boss_two', $this->gmd);
        $this->actAs($this->superAdmin);

        Livewire::test(EditUser::class, ['record' => $otherSuperAdmin->getRouteKey()])
            ->assertFormFieldVisible('data_access')
            ->assertFormSet(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldHidden('confirm_global_access')
            ->fillForm(['nama_lengkap' => 'BOSS DUA'])
            ->call('save')
            ->assertHasNoFormErrors();
        $otherSuperAdmin->refresh();
        $this->assertSame('BOSS DUA', $otherSuperAdmin->nama_lengkap);
        $this->assertSame(User::DATA_ACCESS_ALL, $otherSuperAdmin->data_access);

        // Memilih ulang Jabatan ADMIN tidak mengubah cakupan super admin yang sudah ada.
        Livewire::test(EditUser::class, ['record' => $otherSuperAdmin->getRouteKey()])
            ->fillForm(['role_id' => $this->fixtureRole('ADMIN')->id])
            ->assertFormSet(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldHidden('confirm_global_access');

        // Akunnya sendiri juga tanpa konfirmasi.
        Livewire::test(EditUser::class, ['record' => $this->superAdmin->getRouteKey()])
            ->assertFormFieldHidden('confirm_global_access')
            ->fillForm(['nama_lengkap' => 'BOSS SATU'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(User::DATA_ACCESS_ALL, $this->superAdmin->refresh()->data_access);

        $data = UserResource::mutateAuthorizedData([
            'role_id' => $this->fixtureRole('ADMIN')->id,
            'data_access' => User::DATA_ACCESS_ALL,
        ], $otherSuperAdmin);
        $this->assertSame(User::DATA_ACCESS_ALL, $data['data_access']);
    }

    public function test_server_rejects_tampered_all_access_without_confirmation(): void
    {
        $this->actAs($this->superAdmin);
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        // Create, promosi karyawan biasa, dan perluasan admin badan usaha tertentu.
        foreach ([null, $this->ulsStaff, $this->scopedAdmin] as $record) {
            $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData([
                'role_id' => $adminRoleId,
                'data_access' => User::DATA_ACCESS_ALL,
            ], $record), 'data.confirm_global_access');

            $this->assertValidationFails(fn () => UserResource::mutateAuthorizedData([
                'role_id' => $adminRoleId,
                'data_access' => User::DATA_ACCESS_ALL,
                'confirm_global_access' => false,
            ], $record), 'data.confirm_global_access');

            $confirmed = UserResource::mutateAuthorizedData([
                'role_id' => $adminRoleId,
                'data_access' => User::DATA_ACCESS_ALL,
                'confirm_global_access' => true,
            ], $record);
            $this->assertSame(User::DATA_ACCESS_ALL, $confirmed['data_access']);
            $this->assertArrayNotHasKey('confirm_global_access', $confirmed);
        }

        // Tanpa pilihan cakupan: admin baru/promosi tidak jatuh ke default 'all'.
        $this->assertSame(User::DATA_ACCESS_SELECTED, UserResource::mutateAuthorizedData(['role_id' => $adminRoleId])['data_access']);
        $this->assertSame(
            User::DATA_ACCESS_SELECTED,
            UserResource::mutateAuthorizedData(['role_id' => $adminRoleId, 'data_access' => ''], $this->ulsStaff)['data_access'],
        );

        // Field konfirmasi selalu dibuang, termasuk untuk role non-ADMIN dan non super admin.
        $staff = UserResource::mutateAuthorizedData([
            'role_id' => $this->fixtureRole('STAFF')->id,
            'confirm_global_access' => true,
        ], $this->ulsStaff);
        $this->assertArrayNotHasKey('confirm_global_access', $staff);
        $this->assertSame(User::DATA_ACCESS_ALL, $staff['data_access']);

        $this->actAs($this->scopedAdmin);
        $this->assertArrayNotHasKey(
            'confirm_global_access',
            UserResource::mutateAuthorizedData(['nama_lengkap' => 'X', 'confirm_global_access' => true], $this->ulsStaff),
        );
    }

    // FIX 2b: penjaga model.

    public function test_model_promotion_to_admin_without_data_access_is_restricted_to_own_area(): void
    {
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        $this->ulsStaff->update(['role_id' => $adminRoleId]);

        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->ulsStaff->data_access);
        $this->ulsStaff->refresh();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->ulsStaff->data_access);
        $this->assertSame([$this->uls->id], $this->ulsStaff->dataAccessAreas()->pluck('areas.id')->all());
        $this->assertFalse(DataAccessService::hasGlobalAccess($this->ulsStaff));

        // Area baru pada penyimpanan yang sama dipakai sebagai badan usaha akses.
        $this->cooStaff->forceFill(['role_id' => $adminRoleId, 'area_id' => $this->gmd->id])->save();
        $this->assertSame([$this->gmd->id], $this->cooStaff->dataAccessAreas()->pluck('areas.id')->all());

        // Demosi lalu promosi lagi: kembali dibatasi.
        $this->scopedAdmin->update(['role_id' => $this->fixtureRole('STAFF')->id, 'data_access' => User::DATA_ACCESS_ALL]);
        $this->scopedAdmin->dataAccessAreas()->detach();
        $this->scopedAdmin->update(['role_id' => $adminRoleId]);
        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->scopedAdmin->refresh()->data_access);
        $this->assertSame([$this->coo->id], $this->scopedAdmin->dataAccessAreas()->pluck('areas.id')->all());
    }

    public function test_model_keeps_explicit_data_access_and_direct_admin_creation(): void
    {
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        // data_access eksplisit (walau sama dengan nilai lama 'all') tetap dipakai.
        $this->ulsStaff->update(['role_id' => $adminRoleId, 'data_access' => User::DATA_ACCESS_ALL]);
        $this->ulsStaff->refresh();
        $this->assertSame(User::DATA_ACCESS_ALL, $this->ulsStaff->data_access);
        $this->assertSame(0, $this->ulsStaff->dataAccessAreas()->count());

        // Daftar badan usaha yang sudah ada tidak diganti.
        $this->cooStaff->dataAccessAreas()->attach($this->gmd->id);
        $this->cooStaff->update(['role_id' => $adminRoleId]);
        $this->assertSame([$this->gmd->id], $this->cooStaff->dataAccessAreas()->pluck('areas.id')->all());

        // User yang langsung dibuat sebagai ADMIN (seeder, factory, fixture) tetap memakai default 'all'.
        $direct = $this->fixtureUser('admin_langsung', 'ADMIN', $this->uls);
        $this->assertSame(User::DATA_ACCESS_ALL, $direct->data_access);
        $this->assertSame(0, $direct->dataAccessAreas()->count());

        $factoryAdmin = User::factory()->create([
            'role_id' => $adminRoleId,
            'area_id' => $this->uls->id,
            'divisi_id' => $this->opsCoo->id,
        ])->refresh();
        $this->assertTrue(DataAccessService::hasGlobalAccess($factoryAdmin));

        // Simpan admin yang sudah ada tanpa mengubah role: tidak tersentuh.
        $direct->update(['nama_lengkap' => 'ADMIN LANGSUNG EDIT']);
        $this->assertSame(User::DATA_ACCESS_ALL, $direct->refresh()->data_access);
        $this->scopedAdmin->update(['nama_lengkap' => 'OJAN EDIT']);
        $this->assertSame([$this->uls->id], $this->scopedAdmin->dataAccessAreas()->pluck('areas.id')->all());
    }

    // FIX 2c: import.

    public function test_users_import_restricts_new_and_promoted_admins_and_reports_them(): void
    {
        $this->fixtureRole('ADMIN');
        $this->fixtureRole('STAFF');
        $existingSuperAdmin = $this->fixtureGlobalAdmin('boss_two', $this->gmd);
        $import = new UsersImport;

        $import->model($this->importRow(['id_karyawan' => 'IMP-1', 'nama_lengkap' => 'Admin Import', 'username' => 'admin_import', 'role' => 'ADMIN', 'area' => 'ULS']));
        $import->model($this->importRow(['nama_lengkap' => 'COO STAFF', 'username' => 'coo_staff', 'role' => 'admin', 'area' => 'GMD']));
        $import->model($this->importRow(['nama_lengkap' => 'BOSS TWO', 'username' => 'boss_two', 'role' => 'ADMIN', 'area' => 'GMD']));
        $import->model($this->importRow(['id_karyawan' => 'IMP-2', 'nama_lengkap' => 'Staf Import', 'username' => 'staf_import', 'role' => 'STAFF', 'area' => 'ULS']));

        $this->assertSame([], $import->getErrors());
        $this->assertSame(4, $import->getSuccessCount());

        $newAdmin = User::query()->where('username', 'admin_import')->firstOrFail();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $newAdmin->data_access);
        $this->assertSame([$this->uls->id], $newAdmin->dataAccessAreas()->pluck('areas.id')->all());

        $promoted = $this->cooStaff->refresh();
        $this->assertTrue($promoted->isAdmin());
        $this->assertSame(User::DATA_ACCESS_SELECTED, $promoted->data_access);
        $this->assertSame([$this->gmd->id], $promoted->dataAccessAreas()->pluck('areas.id')->all());

        // Super admin yang sudah ada tidak berubah; karyawan non-ADMIN tidak dicatat.
        $this->assertTrue(DataAccessService::hasGlobalAccess($existingSuperAdmin->refresh()));
        $this->assertSame(0, $existingSuperAdmin->dataAccessAreas()->count());

        // Catatan singkat per baris; petunjuk lanjutan ditulis sekali di notifikasi halaman.
        $this->assertSame(['Baris 2: ADMIN IMPORT (ULS)', 'Baris 3: COO STAFF (GMD)'], $import->getNotices());
    }

    public function test_import_notice_names_the_stored_access_list_of_a_repromoted_admin(): void
    {
        $this->fixtureRole('STAFF');
        $exAdmin = $this->fixtureScopedAdmin('ex_admin', $this->uls, [$this->gmd]);
        $import = new UsersImport;

        // Diturunkan lalu dinaikkan lagi lewat import: daftar badan usaha lama (GMD) tetap dipakai model.
        $import->model($this->importRow(['nama_lengkap' => 'EX ADMIN', 'username' => 'ex_admin', 'role' => 'STAFF', 'area' => 'ULS']));
        $import->model($this->importRow(['nama_lengkap' => 'EX ADMIN', 'username' => 'ex_admin', 'role' => 'ADMIN', 'area' => 'ULS']));

        $this->assertSame([], $import->getErrors());
        $exAdmin->refresh();
        $this->assertTrue($exAdmin->isAdmin());
        $this->assertSame(User::DATA_ACCESS_SELECTED, $exAdmin->data_access);
        $this->assertSame([$this->gmd->id], $exAdmin->dataAccessAreas()->pluck('areas.id')->all());

        // Catatan menyebut akses yang benar-benar tersimpan, bukan Area karyawan (ULS).
        $this->assertSame(['Baris 3: EX ADMIN (GMD; daftar area lama dipertahankan)'], $import->getNotices());
    }

    public function test_users_import_keeps_the_last_global_admin(): void
    {
        $this->fixtureRole('STAFF');
        $import = new UsersImport;

        // Satu-satunya super admin (ojan dibatasi badan usaha tertentu) tidak bisa diturunkan lewat file import.
        $import->model($this->importRow(['nama_lengkap' => 'BOSS', 'username' => 'boss', 'role' => 'STAFF', 'area' => 'COO']));
        $this->assertSame(
            ['Baris 2: Role "STAFF" tidak dapat diberikan kepada BOSS: minimal harus ada satu admin dengan akses seluruh area.'],
            $import->getErrors(),
        );
        $this->assertTrue(DataAccessService::hasGlobalAccess($this->superAdmin->refresh()));

        // Selama masih ada super admin lain, super admin boleh diturunkan; super admin terakhir tetap ditolak.
        $second = $this->fixtureGlobalAdmin('boss_two', $this->coo);
        $import->model($this->importRow(['nama_lengkap' => 'BOSS TWO', 'username' => 'boss_two', 'role' => 'STAFF', 'area' => 'COO']));
        $import->model($this->importRow(['nama_lengkap' => 'BOSS', 'username' => 'boss', 'role' => 'STAFF', 'area' => 'COO']));
        $this->assertFalse($second->refresh()->isAdmin());
        $this->assertTrue(DataAccessService::hasGlobalAccess($this->superAdmin->refresh()));
        $this->assertCount(2, $import->getErrors());
        $this->assertSame(1, $import->getSuccessCount());

        // Admin badan usaha tertentu tetap boleh diturunkan.
        $import->model($this->importRow(['nama_lengkap' => 'OJAN', 'username' => 'ojan', 'role' => 'STAFF', 'area' => 'COO']));
        $this->assertFalse($this->scopedAdmin->refresh()->isAdmin());
        $this->assertSame(2, $import->getSuccessCount());
    }

    public function test_users_import_page_reports_restricted_admins(): void
    {
        $this->fixtureRole('ADMIN');
        $this->actAs($this->superAdmin);

        $disk = (string) (config('filament.default_filesystem_disk') ?: config('filesystems.default', 'local'));
        Storage::fake($disk);
        Excel::store(new class implements FromArray
        {
            public function array(): array
            {
                return [
                    ['id_karyawan', 'nama_lengkap', 'username', 'role', 'area', 'divisi'],
                    ['XLS-1', 'Admin Dari Excel', 'admin_excel', 'ADMIN', 'ULS', 'OPERASIONAL'],
                    ['XLS-2', 'Admin <b>Dua</b> & Co', 'admin_dua', 'ADMIN', 'GMD', 'OPERASIONAL'],
                ];
            }
        }, 'imports/users/admin.xlsx', $disk);

        $page = Livewire::test(ListUsers::class)->call('processImport', ['file' => 'imports/users/admin.xlsx']);
        // Dibaca sebelum assertNotified (yang mengambil notifikasi dari session).
        $notifications = collect(session()->get('filament.claimed_notifications') ?? session()->get('filament.notifications', []));
        $page->assertNotified('2 Admin Dibatasi ke Area Tertentu');

        $created = User::query()->where('username', 'admin_excel')->firstOrFail();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $created->data_access);
        $this->assertSame([$this->uls->id], $created->dataAccessAreas()->pluck('areas.id')->all());

        // Satu admin per baris (body notifikasi = HTML tersanitasi), nama di-escape, petunjuk hanya sekali.
        $body = (string) data_get($notifications->firstWhere('title', '2 Admin Dibatasi ke Area Tertentu'), 'body');
        $instruction = 'Bila perlu akses semua area, ubah Cakupan Area di form karyawan.';
        $this->assertStringStartsWith('Baris 2: ADMIN DARI EXCEL (ULS)<br>Baris 3: ADMIN &lt;B&gt;DUA&lt;/B&gt; &amp; CO (GMD)<br><br>', $body);
        $this->assertSame(1, substr_count($body, $instruction));
        $this->assertStringNotContainsString('<b>', $body);
    }

    public function test_json_import_never_creates_a_super_admin(): void
    {
        $position = Position::query()->create(['name' => 'STAFF OPS']);
        $this->fixtureUser('template_ops', 'STAFF', $this->uls, $this->ulsManager, [
            'divisi_id' => $this->opsCoo->id,
            'position_id' => $position->id,
        ]);

        $result = UserJsonImportService::importFromContent(json_encode([[
            'employee_id' => 'EMP-ADMIN-1',
            'full_name' => 'Ingin Jadi Admin',
            'job_position' => 'STAFF OPS',
            'organization' => 'ULS',
            'role' => 'ADMIN',
            'data_access' => User::DATA_ACCESS_ALL,
            'initial_password' => 'password-awal-unik-1',
        ]], JSON_THROW_ON_ERROR));

        $this->assertSame(1, $result['created_count']);
        $this->assertSame([], $result['notices']);
        $created = User::query()->where('employee_id', 'EMP-ADMIN-1')->firstOrFail();
        $this->assertFalse($created->isAdmin());
        $this->assertFalse(DataAccessService::hasGlobalAccess($created));
    }

    // FIX 2d: API.

    public function test_api_store_defaults_new_admins_to_own_area(): void
    {
        Sanctum::actingAs($this->superAdmin->fresh());
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        $created = $this->postJson('/api/v1/users', $this->apiPayload('api_admin', $this->uls, ['role_id' => $adminRoleId]))
            ->assertCreated()
            ->assertJsonPath('data.data_access', User::DATA_ACCESS_SELECTED)
            ->assertJsonPath('data.data_access_area_ids', [$this->uls->id])
            ->json('data');
        $this->assertSame(User::DATA_ACCESS_SELECTED, User::query()->findOrFail($created['id'])->data_access);

        // Explicit 'all' dari super admin tetap membuat super admin.
        $global = $this->postJson('/api/v1/users', $this->apiPayload('api_super', $this->uls, [
            'role_id' => $adminRoleId,
            'data_access' => User::DATA_ACCESS_ALL,
        ]))->assertCreated()->assertJsonPath('data.data_access', User::DATA_ACCESS_ALL)->json('data');
        $this->assertTrue(DataAccessService::hasGlobalAccess(User::query()->findOrFail($global['id'])));

        // Explicit 'selected' memakai daftar yang dikirim.
        $this->postJson('/api/v1/users', $this->apiPayload('api_selected', $this->uls, [
            'role_id' => $adminRoleId,
            'data_access' => User::DATA_ACCESS_SELECTED,
            'data_access_area_ids' => [$this->gmd->id],
        ]))->assertCreated()->assertJsonPath('data.data_access_area_ids', [$this->gmd->id]);

        // Non-ADMIN tetap seperti biasa.
        $this->postJson('/api/v1/users', $this->apiPayload('api_staff', $this->uls))
            ->assertCreated()
            ->assertJsonPath('data.data_access', null);
    }

    public function test_api_update_promotion_is_restricted_to_own_area(): void
    {
        Sanctum::actingAs($this->superAdmin->fresh());
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        $this->putJson('/api/v1/users/'.$this->ulsStaff->id, ['role_id' => $adminRoleId])
            ->assertOk()
            ->assertJsonPath('data.data_access', User::DATA_ACCESS_SELECTED)
            ->assertJsonPath('data.data_access_area_ids', [$this->uls->id]);
        $this->assertFalse(DataAccessService::hasGlobalAccess($this->ulsStaff->fresh()));

        // Explicit 'all' saat promosi tetap dipakai.
        $this->putJson('/api/v1/users/'.$this->cooStaff->id, [
            'role_id' => $adminRoleId,
            'data_access' => User::DATA_ACCESS_ALL,
        ])->assertOk()
            ->assertJsonPath('data.data_access', User::DATA_ACCESS_ALL)
            ->assertJsonPath('data.data_access_area_ids', null);
        $this->assertSame(0, $this->cooStaff->dataAccessAreas()->count());
        $this->assertTrue(DataAccessService::hasGlobalAccess($this->cooStaff->fresh()));
    }

    private function actAs(User $user): void
    {
        $this->resetAccessMemos();
        $this->actingAs($user);
        Livewire::actingAs($user);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function importRow(array $values): array
    {
        return array_merge(['divisi' => 'OPERASIONAL'], $values);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function apiPayload(string $username, Area $area, array $overrides = []): array
    {
        return array_merge([
            'username' => $username,
            'nama_lengkap' => strtoupper($username),
            'password' => 'secret123',
            'role_id' => $this->fixtureRole('STAFF')->id,
            'area_id' => $area->id,
            'divisi_id' => $this->opsCoo->id,
        ], $overrides);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function rawSelectOptions(Schema $schema, string $name): array
    {
        $field = collect($schema->getFlatFields(withHidden: true))
            ->first(fn ($component): bool => $component instanceof Select && $component->getName() === $name);

        $this->assertInstanceOf(Select::class, $field, "Field {$name} tidak ditemukan.");

        return $field->getOptions();
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

    private function callProtected(object $instance, string $method): mixed
    {
        return (new ReflectionMethod($instance, $method))->invoke($instance);
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
}
