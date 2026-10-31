<?php

namespace Tests\Feature;

use App\Filament\Pages\DataAccessGuide;
use App\Filament\Resources\Kpis\KpiResource;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Area;
use App\Models\Divisi;
use App\Models\Kpi;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Policies\EmployeeReviewPolicy;
use App\Policies\WorkJournalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Panduan Akses Data: akses halaman sama dengan Panduan Ubah Posisi, kartu "Akses Data Anda" dihitung
 * dari akun yang login, dan SOP serta ringkasan akun ADMIN hanya untuk super admin.
 *
 * Skenario: super admin "boss" (COO), admin "ojan" (homebase COO) hanya ULS + GMD dengan satu anggota tim
 * di COO, admin "rina" bercakupan tanpa badan usaha, manager GMD dengan satu bawahan.
 */
class DataAccessGuideTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private Area $coo;

    private Area $uls;

    private Area $gmd;

    private Area $xyz;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $scopedAdminWithoutArea;

    private User $ulsAdminAccount;

    private User $gmdManager;

    private User $gmdStaff;

    private User $cooStaff;

    private User $teamLeaderWithoutTeam;

    private User $staffWithSubordinate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $this->coo = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');
        $this->gmd = $this->fixtureArea('GMD');
        $this->xyz = $this->fixtureArea('XYZ-PUSAT');

        $this->superAdmin = $this->fixtureGlobalAdmin('boss', $this->coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->uls, $this->gmd]);
        $this->scopedAdminWithoutArea = $this->fixtureScopedAdmin('rina', $this->uls, []);
        $this->ulsAdminAccount = $this->fixtureUser('uls_admin', 'ADMIN', $this->uls);

        $this->fixtureUser('uls_staff', 'STAFF', $this->uls);
        $this->fixtureUser('uls_archived', 'STAFF', $this->uls)->delete();
        $this->gmdManager = $this->fixtureUser('gmd_manager', 'MANAGER', $this->gmd);
        $this->gmdStaff = $this->fixtureUser('gmd_staff', 'STAFF', $this->gmd, $this->gmdManager);
        $this->cooStaff = $this->fixtureUser('coo_staff', 'STAFF', $this->coo);
        $this->fixtureUser('coo_team', 'STAFF', $this->coo, $this->scopedAdmin);
        $this->fixtureUser('xyz_staff', 'STAFF', $this->xyz);
        $this->teamLeaderWithoutTeam = $this->fixtureUser('xyz_lead', 'TEAM LEADER', $this->xyz);
        $this->staffWithSubordinate = $this->fixtureUser('xyz_senior', 'STAFF', $this->xyz);
        $this->fixtureUser('xyz_junior', 'STAFF', $this->xyz, $this->staffWithSubordinate);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_page_is_registered_in_panduan_group(): void
    {
        $this->assertSame('Panduan Akses Data', DataAccessGuide::getNavigationLabel());
        $this->assertSame('Panduan', DataAccessGuide::getNavigationGroup());
        $this->assertSame(83, DataAccessGuide::getNavigationSort());
        $this->assertSame('panduan-akses-data', DataAccessGuide::getSlug());
        $this->assertSame('heroicon-o-book-open', DataAccessGuide::getNavigationIcon());
        $this->assertSame('Panduan Akses Data Karyawan', (new DataAccessGuide)->getTitle());
    }

    public function test_can_access_follows_karyawan_or_tim_saya_menu(): void
    {
        $this->assertFalse(DataAccessGuide::canAccess(), 'Tamu tidak boleh membuka panduan.');

        $expectations = [
            'super admin' => [$this->superAdmin, true],
            'admin area tertentu' => [$this->scopedAdmin, true],
            'manager dengan tim' => [$this->gmdManager, true],
            'team leader (role atasan) tanpa tim' => [$this->teamLeaderWithoutTeam, true],
            'staf yang punya bawahan' => [$this->staffWithSubordinate, true],
            'staf tanpa tim' => [$this->cooStaff, false],
            'admin bercakupan tanpa area dan tanpa tim' => [$this->scopedAdminWithoutArea, false],
        ];

        foreach ($expectations as $label => [$user, $expected]) {
            $this->actAs($user);

            $this->assertSame($expected, DataAccessGuide::canAccess(), $label);
            $this->assertSame(
                UserResource::canViewAny() || TeamMemberResource::canViewAny(),
                DataAccessGuide::canAccess(),
                "{$label}: aturan sama dengan Panduan Ubah Posisi",
            );
        }
    }

    public function test_route_renders_for_allowed_roles_and_is_forbidden_for_staff(): void
    {
        $this->actAs($this->superAdmin);
        $this->get('/admin/panduan-akses-data')
            ->assertOk()
            ->assertSee('Panduan Akses Data Karyawan')
            ->assertSee('Siapa Melihat Apa');

        $this->actAs($this->gmdManager);
        $this->get('/admin/panduan-akses-data')->assertOk();

        $this->actAs($this->cooStaff);
        $this->get('/admin/panduan-akses-data')->assertForbidden();

        $this->actAs($this->scopedAdminWithoutArea);
        $this->get('/admin/panduan-akses-data')->assertForbidden();
    }

    public function test_super_admin_sees_sop_and_current_admin_access(): void
    {
        $this->actAs($this->superAdmin);

        $access = (new DataAccessGuide)->getViewerAccess();
        $this->assertSame('super_admin', $access['role']);
        $this->assertSame('all', $access['scope']);
        $this->assertSame([], $access['area_names']);
        // Seluruh karyawan aktif (termasuk akun ADMIN), tanpa arsip.
        $this->assertSame(User::query()->count(), $access['employees_count']);
        $this->assertSame(13, $access['employees_count']);
        $this->assertNull($access['team_count'], 'Super admin tanpa bawahan tidak punya menu Tim Saya.');

        $summary = (new DataAccessGuide)->getAdminAccessSummary();
        $this->assertNotNull($summary);
        $this->assertSame(2, $summary['global_count']);
        $this->assertSame([
            ['name' => 'OJAN', 'areas' => ['GMD', 'ULS']],
            ['name' => 'RINA', 'areas' => []],
        ], $summary['scoped']);

        Livewire::test(DataAccessGuide::class)
            ->assertSuccessful()
            ->assertSee('Super Admin (Semua Area)')
            ->assertSee('Semua area')
            ->assertSee('SOP 1 - Memberi Admin Akses ke Area Tertentu (Khusus Super Admin)')
            ->assertSee('SOP 2 - Mengubah atau Mencabut Akses (Khusus Super Admin)')
            ->assertSee('Kondisi akses admin saat ini')
            ->assertSee('OJAN')
            ->assertSee('RINA')
            ->assertSee('Belum dipilih')
            ->assertSee('Buka Menu Karyawan')
            ->assertSee('Buka Settings > Area')
            ->assertSee('Panduan Ubah Posisi')
            ->assertSee('Jangan menghapus akun super admin terakhir')
            ->assertSee('Setelah menjadi ADMIN, karyawan ini tidak lagi tampil di menu Tim Saya atasannya')
            ->assertSee('Cek Akses Data admin')
            ->assertDontSee('Minimal harus ada satu super admin')
            ->assertDontSee('Buka Menu Tim Saya')
            // SOP 1: ADMIN baru gagal tertutup ke badan usaha sendiri; super admin hanya dengan konfirmasi.
            ->assertSeeHtml('sudah terpilih <strong>Area Tertentu</strong>, dan field <strong>Area yang Dikelola</strong> sudah terisi Area karyawan itu sendiri')
            ->assertSee('Jabatan ADMIN tidak pernah otomatis menjadi super admin.')
            ->assertDontSeeHtml('Pada <strong>Cakupan Area</strong>, pilih <strong>Area Tertentu</strong>.')
            ->assertSee('Admin Dibatasi ke Area Tertentu')
            ->assertSee('Mengubah Area admin yang sudah ada tidak mengubah daftar Area yang Dikelola.')
            ->assertSee('Saya yakin menjadikan karyawan ini super admin')
            ->assertSee('Centang konfirmasi ini untuk memberi akses Semua Area.')
            ->assertSee('Akun yang sudah super admin tidak diminta konfirmasi.')
            ->assertSeeHtml('Field <strong>Divisi</strong> tidak menentukan Area')
            ->assertSee('6. Admin baru hanya melihat area-nya sendiri')
            ->assertSee('7. Pesan saat menyimpan form karyawan');
    }

    public function test_scoped_admin_sees_only_own_access_without_super_admin_sections(): void
    {
        $this->actAs($this->scopedAdmin);

        $access = (new DataAccessGuide)->getViewerAccess();
        $this->assertSame('scoped_admin', $access['role']);
        $this->assertSame('selected', $access['scope']);
        $this->assertSame(['GMD', 'ULS'], $access['area_names']);
        $this->assertSame('COO', $access['home_area']);
        // uls_staff, gmd_manager, gmd_staff: tanpa akun ADMIN, arsip, atau anggota tim di COO.
        $this->assertSame(3, $access['employees_count']);
        $this->assertSame(1, $access['team_count']);
        $this->assertNull((new DataAccessGuide)->getAdminAccessSummary());

        Livewire::test(DataAccessGuide::class)
            ->assertSuccessful()
            ->assertSee('Admin Area Tertentu')
            ->assertSee('GMD')
            ->assertSee('ULS')
            ->assertSee('Khusus super admin')
            ->assertSee('Buka Menu Karyawan')
            ->assertSee('Buka Menu Tim Saya')
            ->assertSee('Profil akun ADMIN (form karyawan) hanya dapat diubah oleh super admin.')
            ->assertSee('KPI dan Jurnal Harian miliknya sendiri tetap bisa ia ubah')
            ->assertSee('anggota tim boleh ditempatkan di area mana pun')
            ->assertSee('menambahkan area tersebut ke Akses Data Anda')
            ->assertDontSee('Cek Akses Data admin')
            ->assertDontSee('dan aktivitas anggota tim')
            ->assertDontSee('Belum dipilih')
            ->assertDontSee('XYZ-PUSAT')
            ->assertDontSee('SOP 1 - Memberi Admin Akses')
            ->assertDontSee('Kondisi akses admin saat ini')
            ->assertDontSee('RINA')
            ->assertDontSee('Buka Settings > Area')
            ->assertDontSee('Saya yakin menjadikan karyawan ini super admin')
            ->assertDontSee('Admin baru hanya melihat area-nya sendiri')
            ->assertSee('6. Pesan saat menyimpan form karyawan');
    }

    public function test_scoped_admin_without_badan_usaha_but_with_team_is_told_to_contact_super_admin(): void
    {
        $this->fixtureUser('uls_team', 'STAFF', $this->uls, $this->scopedAdminWithoutArea);
        $this->actAs($this->scopedAdminWithoutArea);

        $this->assertTrue(DataAccessGuide::canAccess(), 'Masuk lewat menu Tim Saya.');

        $access = (new DataAccessGuide)->getViewerAccess();
        $this->assertSame('scoped_admin', $access['role']);
        $this->assertSame('none', $access['scope']);
        $this->assertNull($access['employees_count']);
        $this->assertSame(1, $access['team_count']);

        Livewire::test(DataAccessGuide::class)
            ->assertSuccessful()
            ->assertSee('Admin Area Tertentu')
            ->assertSee('Belum dipilih')
            ->assertSee('Area untuk akun Anda belum dipilih, sehingga menu Karyawan tidak tersedia.')
            ->assertDontSee('Tidak ada akses area')
            ->assertDontSee('Data yang Anda kelola mengikuti anggota tim di menu Tim Saya.')
            ->assertDontSee('Buka Menu Karyawan');
    }

    public function test_supervisor_sees_team_without_badan_usaha_access(): void
    {
        $this->actAs($this->gmdManager);

        $access = (new DataAccessGuide)->getViewerAccess();
        $this->assertSame('supervisor', $access['role']);
        $this->assertSame('none', $access['scope']);
        $this->assertNull($access['employees_count']);
        $this->assertSame(1, $access['team_count']);

        Livewire::test(DataAccessGuide::class)
            ->assertSuccessful()
            ->assertSee('Atasan (Tim Saya)')
            ->assertSee('Tidak ada akses area')
            ->assertSee('Data yang Anda kelola mengikuti anggota tim di menu Tim Saya.')
            ->assertSee('Menghapus anggota tim')
            ->assertSee('termasuk akun ADMIN yang tidak tampil di menu Tim Saya')
            ->assertDontSee('Belum dipilih')
            ->assertSee('Khusus super admin')
            ->assertSee('Buka Menu Tim Saya')
            ->assertDontSee('Buka Menu Karyawan')
            ->assertDontSee('SOP 1 - Memberi Admin Akses')
            ->assertDontSee('OJAN');
    }

    public function test_access_card_follows_data_access_changes(): void
    {
        $this->scopedAdmin->dataAccessAreas()->sync([$this->xyz->id]);
        $this->actAs($this->scopedAdmin);

        $access = (new DataAccessGuide)->getViewerAccess();
        $this->assertSame(['XYZ-PUSAT'], $access['area_names']);
        // xyz_staff, xyz_lead, xyz_senior, xyz_junior.
        $this->assertSame(4, $access['employees_count']);

        $this->scopedAdmin->forceFill(['data_access' => User::DATA_ACCESS_ALL])->save();
        $this->actAs($this->scopedAdmin->refresh());

        $access = (new DataAccessGuide)->getViewerAccess();
        $this->assertSame('super_admin', $access['role']);
        $this->assertSame('all', $access['scope']);
    }

    public function test_guide_gifs_exist_and_are_embedded_per_section(): void
    {
        $gifs = [
            'atur_admin' => 'images/panduan-akses-data/akses-data-atur-admin.gif',
            'hasil_admin' => 'images/panduan-akses-data/akses-data-hasil-admin.gif',
            'tim_saya' => 'images/panduan-akses-data/akses-data-tim-saya.gif',
        ];

        foreach ($gifs as $path) {
            $file = public_path($path);
            $this->assertFileExists($file);
            $this->assertStringStartsWith('GIF8', (string) file_get_contents($file, false, null, 0, 6), "{$path} harus berupa GIF.");
            $this->assertLessThanOrEqual(350 * 1024, filesize($file), "{$path} maksimal 350 KB.");
        }

        // Super admin: GIF 1 di SOP 1, lalu GIF 2 (admin badan usaha) dan GIF 3 (Tim Saya) berurutan.
        $this->actAs($this->superAdmin);
        Livewire::test(DataAccessGuide::class)
            ->assertSuccessful()
            ->assertSeeHtmlInOrder([
                'SOP 1 - Memberi Admin Akses ke Area Tertentu (Khusus Super Admin)',
                'src="'.asset($gifs['atur_admin']).'"',
                'alt="GIF super admin mengubah Jabatan karyawan COO menjadi ADMIN: Akses Data langsung terisi Area Tertentu dengan COO (Area karyawan itu), lalu COO dihapus dan GMD serta ULS dipilih',
                'GIF 1. Super admin menjadikan karyawan COO sebagai ADMIN: Akses Data otomatis Area Tertentu dengan COO, lalu diganti GMD dan ULS',
                'SOP 2 - Mengubah atau Mencabut Akses (Khusus Super Admin)',
                'Admin Area Tertentu: Apa yang Terlihat &amp; Batasannya',
                'src="'.asset($gifs['hasil_admin']).'"',
                'alt="GIF tampilan admin area tertentu yang hanya melihat karyawan ULS dan GMD tanpa menu Settings"',
                'GIF 2. Tampilan admin area ULS dan GMD',
                'Tim Saya untuk Atasan',
                'src="'.asset($gifs['tim_saya']).'"',
                'alt="GIF atasan membuka menu Tim Saya dan melihat anggota tim di area lain yang Approval Line-nya atasan tersebut"',
                'GIF 3. Atasan membuka Tim Saya: anggota di ULS dan GMD tampil karena Approval Line-nya atasan tersebut',
                'Siapa Melihat Apa',
            ]);

        // Viewer lain tidak melihat SOP super admin, jadi GIF 1 ikut tersembunyi dan penomoran mulai dari 1.
        foreach (['admin area tertentu' => $this->scopedAdmin, 'atasan' => $this->gmdManager] as $label => $viewer) {
            $this->actAs($viewer);
            Livewire::test(DataAccessGuide::class)
                ->assertSuccessful()
                ->assertDontSeeHtml($gifs['atur_admin'])
                ->assertDontSee('GIF 3.')
                ->assertSeeHtmlInOrder([
                    'Admin Area Tertentu: Apa yang Terlihat &amp; Batasannya',
                    'src="'.asset($gifs['hasil_admin']).'"',
                    'GIF 1. Tampilan admin area ULS dan GMD',
                    'Tim Saya untuk Atasan',
                    'src="'.asset($gifs['tim_saya']).'"',
                    'GIF 2. Atasan membuka Tim Saya: anggota di ULS dan GMD tampil karena Approval Line-nya atasan tersebut',
                ]);
        }
    }

    public function test_access_matrix_covers_every_role_column(): void
    {
        $matrix = (new DataAccessGuide)->getAccessMatrix();

        $this->assertNotEmpty($matrix);

        foreach ($matrix as $row) {
            foreach (['module', 'super_admin', 'scoped_admin', 'supervisor', 'staff'] as $column) {
                $this->assertArrayHasKey($column, $row);
                $this->assertNotSame('', trim($row[$column]));
            }
        }

        $this->actAs($this->scopedAdmin);
        $this->assertSame('scoped_admin', (new DataAccessGuide)->getViewerRoleKey());

        $this->actAs($this->staffWithSubordinate);
        $this->assertSame('supervisor', (new DataAccessGuide)->getViewerRoleKey());

        $rows = collect($matrix)->keyBy('module');
        $this->assertStringContainsString('(jika punya bawahan)', $rows['Menu Tim Saya']['scoped_admin']);
        foreach (['KPI', 'Kehadiran & Penilaian', 'Jurnal Harian'] as $module) {
            $this->assertStringContainsString('sesuai Approval Line', $rows[$module]['supervisor'], $module);
            $this->assertStringContainsString('termasuk akun ADMIN', $rows[$module]['supervisor'], $module);
            $this->assertStringNotContainsString('Anggota Tim Saya', $rows[$module]['supervisor'], $module);
        }
        $this->assertStringContainsString('Checklist KPI di Dashboard', $rows['KPI']['supervisor']);
    }

    /**
     * Fakta yang dijelaskan di panduan harus tetap sesuai aturan akses di kode.
     */
    public function test_guide_statements_match_access_rules(): void
    {
        // Admin badan usaha tertentu: KPI & Jurnal Harian miliknya sendiri tetap bisa diubah,
        // Kehadiran & Penilaian miliknya sendiri dicatat super admin.
        $this->actAs($this->scopedAdmin);
        $this->assertTrue($this->scopedAdmin->can('update', new Kpi(['user_id' => $this->scopedAdmin->id])));
        $this->assertTrue(WorkJournalPolicy::canWriteFor($this->scopedAdmin, $this->scopedAdmin));
        $this->assertFalse(AttendancePolicy::canManageUser($this->scopedAdmin, $this->scopedAdmin->id));
        $this->assertFalse(EmployeeReviewPolicy::canManageUser($this->scopedAdmin, $this->scopedAdmin->id));
        $this->assertFalse($this->scopedAdmin->can('update', new Kpi(['user_id' => $this->ulsAdminAccount->id])));

        // Atasan non-admin dengan bawahan berjabatan ADMIN: data ADMIN itu tetap dikelola,
        // walaupun akunnya tidak tampil di Tim Saya.
        $bod = $this->fixtureUser('xyz_bod', 'BOD', $this->xyz);
        $adminSubordinate = $this->fixtureUser('xyz_admin_sub', 'ADMIN', $this->xyz, $bod, ['data_access' => User::DATA_ACCESS_ALL]);
        $this->actAs($bod);
        $this->assertTrue($bod->can('update', new Kpi(['user_id' => $adminSubordinate->id])));
        $this->assertTrue(AttendancePolicy::canManageUser($bod, $adminSubordinate->id));
        $this->assertTrue(EmployeeReviewPolicy::canManageUser($bod, $adminSubordinate->id));
        $this->assertSame(0, TeamMemberResource::getEloquentQuery()->count());

        // Atasan dapat menghapus anggota tim; Tim Saya tidak punya tab Arsip.
        $this->actAs($this->gmdManager);
        $this->assertTrue(TeamMemberResource::canDelete($this->gmdStaff));
        $this->assertFalse(TeamMemberResource::canViewArchived());

        // KPI milik sendiri hanya tampil di menu KPI jika punya bawahan.
        $managerKpiId = $this->insertKpi($this->gmdManager);
        $leaderKpiId = $this->insertKpi($this->teamLeaderWithoutTeam);
        $this->assertTrue(KpiResource::getEloquentQuery()->whereKey($managerKpiId)->exists());

        $this->actAs($this->teamLeaderWithoutTeam);
        $this->assertFalse(KpiResource::getEloquentQuery()->whereKey($leaderKpiId)->exists());

        // Panel web tidak mencegah super admin menghapus akunnya sendiri (lihat peringatan di SOP 1).
        $this->actAs($this->superAdmin);
        $this->assertTrue($this->superAdmin->can('delete', $this->superAdmin));
    }

    /**
     * SOP 1/SOP 2 dan FAQ menjelaskan isian form karyawan: Jabatan ADMIN gagal tertutup ke badan usaha sendiri,
     * Semua Badan Usaha hanya dengan centang konfirmasi, Area admin lama tidak mengubah daftar badan usahanya,
     * dan Divisi tidak dibatasi Area.
     */
    public function test_sop_statements_match_the_employee_form(): void
    {
        $this->actAs($this->superAdmin);
        $adminRoleId = $this->fixtureRole('ADMIN')->id;

        // Langkah 4-5: Jabatan ADMIN => Badan Usaha Tertentu + Area karyawan itu sendiri, tanpa konfirmasi.
        Livewire::test(EditUser::class, ['record' => $this->cooStaff->getRouteKey()])
            ->fillForm(['role_id' => $adminRoleId])
            ->assertFormSet([
                'data_access' => User::DATA_ACCESS_SELECTED,
                'dataAccessAreas' => [(string) $this->coo->id],
            ])
            ->assertFormFieldHidden('confirm_global_access')
            // Langkah 6: badan usaha lain ditambahkan di field Area yang Dikelola.
            ->fillForm(['dataAccessAreas' => [(string) $this->coo->id, (string) $this->gmd->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->cooStaff->refresh();
        $this->assertSame(User::DATA_ACCESS_SELECTED, $this->cooStaff->data_access);
        $this->assertEqualsCanonicalizing([$this->coo->id, $this->gmd->id], $this->cooStaff->dataAccessAreas()->pluck('areas.id')->all());

        // SOP 2 "Jadikan super admin": wajib centang, dengan label dan pesan yang dikutip panduan.
        Livewire::test(EditUser::class, ['record' => $this->gmdStaff->getRouteKey()])
            ->fillForm(['role_id' => $adminRoleId])
            ->fillForm(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldVisible('confirm_global_access')
            ->assertSee('Saya yakin menjadikan karyawan ini super admin')
            ->call('save')
            ->assertHasFormErrors(['confirm_global_access'])
            ->assertSee('Centang konfirmasi ini untuk memberi akses Semua Area.');
        $this->assertFalse($this->gmdStaff->refresh()->isAdmin());

        // Akun yang sudah super admin tidak diminta konfirmasi.
        Livewire::test(EditUser::class, ['record' => $this->ulsAdminAccount->getRouteKey()])
            ->assertFormSet(['data_access' => User::DATA_ACCESS_ALL])
            ->assertFormFieldHidden('confirm_global_access');

        // Aturan penting: Area admin yang sudah ada diganti, daftar Badan Usaha-nya tetap.
        Livewire::test(EditUser::class, ['record' => $this->scopedAdmin->getRouteKey()])
            ->fillForm(['area_id' => $this->xyz->id])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->xyz->id, (int) $this->scopedAdmin->refresh()->area_id);
        $this->assertEqualsCanonicalizing([$this->uls->id, $this->gmd->id], $this->scopedAdmin->dataAccessAreas()->pluck('areas.id')->all());

        // Istilah: Divisi tidak menentukan badan usaha, pilihannya semua divisi.
        $this->assertSame(Divisi::query()->count(), count(UserResource::divisiOptions()));
    }

    private function insertKpi(User $owner): int
    {
        return (int) DB::table('kpis')->insertGetId([
            'user_id' => $owner->id,
            'kpi_category_id' => 1,
            'kpi_type_id' => 1,
            'date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function actAs(User $user): void
    {
        $this->resetAccessMemos();
        $this->actingAs($user);
        Livewire::actingAs($user);
    }
}
