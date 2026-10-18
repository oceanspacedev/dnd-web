<?php

namespace Tests\Feature\Scoring;

use App\Filament\Resources\AttendanceComponents\AttendanceComponentResource;
use App\Filament\Resources\AttendanceComponents\Pages\ManageAttendanceComponents;
use App\Filament\Resources\ScoringRules\Pages\CreateScoringRule;
use App\Filament\Resources\ScoringRules\Pages\EditScoringRule;
use App\Filament\Resources\ScoringRules\Pages\ListScoringRules;
use App\Filament\Resources\ScoringRules\Pages\ViewScoringRule;
use App\Filament\Resources\ScoringRules\ScoringRulePresenter;
use App\Filament\Resources\ScoringRules\ScoringRuleResource;
use App\Filament\Resources\ScoringRules\ScoringRuleSimulator;
use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\ScoringRule;
use App\Models\User;
use App\Services\ScoringRuleService;
use App\Support\Scoring\ScoringRuleData;
use Closure;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Pengaturan Aturan Penilaian & Komponen Kehadiran (panel super admin) dan API master baca-saja.
 *
 * Jam dibekukan 17 Oktober 2026: versi "Aturan Lama" (2000-01) berlaku dan terkunci, versi
 * "Aturan Kehadiran November 2026" (2026-11) masih terjadwal dan bisa diubah.
 */
class ScoringSettingsUiTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    /** Urutan komponen bawaan migration (sort_order). */
    private const COMPONENT_CODES = ['late_less_30', 'late_more_30', 'sick_days', 'izin', 'mangkir', 'cuti_tahunan', 'cuti_lainnya'];

    private const DELTA = 1e-9;

    private User $superAdmin;

    private User $scopedAdmin;

    private User $staff;

    private ScoringRule $legacy;

    private ScoringRule $november;

    private ?Closure $restoreRepeater = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Date::create(2026, 10, 17, 9));
        Cache::flush();
        $this->resetAccessMemos();

        // Kunci item repeater 0..n (urut komponen) agar baris aturan kehadiran bisa dialamatkan di tes.
        $this->restoreRepeater = Repeater::fake();

        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');

        $this->superAdmin = $this->fixtureGlobalAdmin('super_admin', $coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);
        $this->staff = $this->fixtureUser('uls_staff', 'STAFF', $uls);

        $this->legacy = ScoringRule::query()->where('effective_from', '2000-01')->firstOrFail();
        $this->november = ScoringRule::query()->where('effective_from', '2026-11')->firstOrFail();

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        if ($this->restoreRepeater !== null) {
            ($this->restoreRepeater)();
        }

        $this->resetAccessMemos();
        parent::tearDown();
    }

    // ==========================================
    // AKSES (SUPER ADMIN SAJA)
    // ==========================================

    public function test_policies_allow_only_super_admin_and_respect_locked_versions_and_component_usage(): void
    {
        $unused = $this->makeComponent('dinas_luar', 'Dinas luar');

        foreach ([ScoringRule::class, AttendanceComponent::class] as $class) {
            foreach (['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny'] as $ability) {
                $this->assertTrue(Gate::forUser($this->superAdmin)->allows($ability, $class), "{$class} {$ability}");
                $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows($ability, $class), "{$class} {$ability}");
                $this->assertFalse(Gate::forUser($this->staff)->allows($ability, $class), "{$class} {$ability}");
            }
        }

        $records = [$this->legacy, $this->november, $unused, $this->componentByCode('izin')];

        foreach ($records as $record) {
            foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
                $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows($ability, $record), $record::class." {$ability}");
                $this->assertFalse(Gate::forUser($this->staff)->allows($ability, $record), $record::class." {$ability}");
            }
        }

        $super = Gate::forUser($this->superAdmin);

        // Versi terkunci: lihat & ubah nama/catatan saja.
        $this->assertTrue($this->legacy->isLocked());
        $this->assertTrue($super->allows('view', $this->legacy));
        $this->assertTrue($super->allows('updateDetails', $this->legacy));
        $this->assertFalse($super->allows('update', $this->legacy));
        $this->assertFalse($super->allows('delete', $this->legacy));
        $this->assertFalse($super->allows('forceDelete', $this->legacy));
        $this->assertStringContainsString('terkunci', (string) $super->inspect('update', $this->legacy)->message());
        $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows('updateDetails', $this->legacy));

        // Versi terjadwal: bisa diubah dan dihapus.
        $this->assertFalse($this->november->isLocked());
        $this->assertTrue($super->allows('update', $this->november));
        $this->assertTrue($super->allows('delete', $this->november));

        // Komponen: hapus hanya bila belum dipakai.
        $this->assertTrue($super->allows('update', $this->componentByCode('izin')));
        $this->assertTrue($super->allows('delete', $unused));
        $this->assertStringContainsString('dipakai aturan penilaian', (string) $super->inspect('delete', $this->componentByCode('izin'))->message());
        $this->assertStringContainsString('bawaan', (string) $super->inspect('delete', $this->componentByCode('sick_days'))->message());
    }

    public function test_settings_resources_and_pages_are_forbidden_for_scoped_admin_and_staff(): void
    {
        foreach ([$this->scopedAdmin, $this->staff] as $user) {
            $this->actingAs($user);
            $this->resetAccessMemos();

            foreach ([ScoringRuleResource::class, AttendanceComponentResource::class] as $resource) {
                $this->assertFalse($resource::canAccess(), $resource);
                $this->assertFalse($resource::canCreate(), $resource);
                $this->assertFalse($resource::shouldRegisterNavigation(), $resource);
            }

            Livewire::actingAs($user);
            Livewire::test(ListScoringRules::class)->assertForbidden();
            Livewire::test(CreateScoringRule::class)->assertForbidden();
            Livewire::test(ViewScoringRule::class, ['record' => $this->legacy->getRouteKey()])->assertForbidden();
            Livewire::test(EditScoringRule::class, ['record' => $this->november->getRouteKey()])->assertForbidden();
            Livewire::test(ManageAttendanceComponents::class)->assertForbidden();
        }

        $this->actingAs($this->scopedAdmin);
        $this->get('/admin/scoring-rules')->assertForbidden();
        $this->get('/admin/attendance-components')->assertForbidden();

        $this->actingAs($this->superAdmin);
        $this->resetAccessMemos();

        foreach ([ScoringRuleResource::class, AttendanceComponentResource::class] as $resource) {
            $this->assertTrue($resource::canAccess(), $resource);
            $this->assertTrue($resource::canCreate(), $resource);
            $this->assertTrue($resource::shouldRegisterNavigation(), $resource);
        }

        // Settings: Pengingat KPI 6, Aturan Penilaian 7, Komponen Kehadiran 8.
        $this->assertSame('Settings', ScoringRuleResource::getNavigationGroup());
        $this->assertSame('Settings', AttendanceComponentResource::getNavigationGroup());
        $this->assertSame(7, ScoringRuleResource::getNavigationSort());
        $this->assertSame(8, AttendanceComponentResource::getNavigationSort());

        $this->get('/admin/scoring-rules')->assertOk();
        $this->get('/admin/scoring-rules/create')->assertOk();
        $this->get('/admin/scoring-rules/'.$this->legacy->getRouteKey())->assertOk();
        $this->get('/admin/scoring-rules/'.$this->november->getRouteKey().'/edit')->assertOk();
        $this->get('/admin/attendance-components')->assertOk();
    }

    // ==========================================
    // DAFTAR VERSI
    // ==========================================

    public function test_list_shows_month_names_status_badges_and_component_summary_newest_first(): void
    {
        Livewire::actingAs($this->superAdmin);

        Livewire::test(ListScoringRules::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$this->november, $this->legacy], inOrder: true)
            ->assertSee('November 2026')
            ->assertSee('Januari 2000')
            ->assertSee('Terjadwal')
            ->assertSee('Berlaku')
            ->assertSee('KPI 70 · Kehadiran 15 · Penilaian 15')
            ->assertSee('Izin: 5 poin/hari');

        $this->assertSame(ScoringRulePresenter::STATUS_CURRENT, ScoringRulePresenter::status('2000-01'));
        $this->assertSame(ScoringRulePresenter::STATUS_SCHEDULED, ScoringRulePresenter::status('2026-11'));
        $this->assertSame(
            ['Terlambat ≤ 30 menit: 1 poin/kejadian, kurangi rumus dasar', 'Terlambat > 30 menit: 3 poin/kejadian, kurangi rumus dasar', 'Sakit: 5 poin/hari, kurangi rumus dasar'],
            ScoringRulePresenter::componentSummaries(ScoringRuleData::fromModel($this->legacy)),
        );

        // Setelah November 2026 berjalan: versi lama menjadi riwayat.
        $this->travelTo(Date::create(2027, 1, 15, 9));
        ScoringRuleService::clearMemo();

        $this->assertSame(ScoringRulePresenter::STATUS_HISTORY, ScoringRulePresenter::status('2000-01'));
        $this->assertSame(ScoringRulePresenter::STATUS_CURRENT, ScoringRulePresenter::status('2026-11'));

        Livewire::test(ListScoringRules::class)
            ->assertSee('Riwayat')
            ->assertSee('Berlaku')
            ->assertDontSee('Terjadwal')
            // November 2026 kini terkunci: hanya bisa dilihat.
            ->assertActionHidden(TestAction::make('edit')->table($this->november))
            ->assertActionVisible(TestAction::make('view')->table($this->november));
    }

    // ==========================================
    // BUAT VERSI
    // ==========================================

    public function test_create_prefills_from_latest_version_and_requires_weights_to_total_100(): void
    {
        Livewire::actingAs($this->superAdmin);

        $page = Livewire::test(CreateScoringRule::class)
            ->assertSuccessful()
            ->assertSchemaStateSet([
                'name' => null,
                // Bulan depan (November) sudah punya versi => bulan kosong berikutnya.
                'effective_from' => '2026-12',
                'kpi_weight' => 70.0,
                'attendance_weight' => 15.0,
                'review_weight' => 15.0,
                'review_max_points' => 20,
            ]);

        // Satu baris per komponen aktif, nilai dari versi terakhir (November 2026).
        $this->assertSame(self::COMPONENT_CODES, array_column($page->get('data.attendance_rules'), 'component'));
        // TextInput numeric menyimpan state sebagai angka (5 / 5.0): bandingkan nilainya.
        $this->assertEquals([1, 3, 0, 5, 5, 0, 0], array_column($page->get('data.attendance_rules'), 'penalty_points'));

        $page
            ->fillForm([
                'name' => 'Aturan Januari 2027',
                'effective_from' => '2027-01',
                'kpi_weight' => 60,
                'attendance_weight' => 20,
                'review_weight' => 15,
            ])
            ->call('create')
            ->assertHasFormErrors(['kpi_weight'])
            ->assertSee('harus 100 (saat ini 95)');

        $this->assertDatabaseMissing('scoring_rules', ['effective_from' => '2027-01']);

        $page
            ->fillForm(['review_weight' => 20])
            ->set('data.attendance_rules.3.penalty_points', 7.5)
            ->set('data.attendance_rules.6.reduces_base', true)
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(ScoringRuleResource::getUrl('index'));

        $created = ScoringRule::query()->where('effective_from', '2027-01')->firstOrFail();
        $data = ScoringRuleData::fromModel($created);

        $this->assertSame('Aturan Januari 2027', $created->name);
        $this->assertSame($this->superAdmin->id, $created->created_by);
        $this->assertSame([60.0, 20.0, 20.0, 20], [$data->kpiWeight, $data->attendanceWeight, $data->reviewWeight, $data->reviewMaxPoints]);
        $this->assertSame(self::COMPONENT_CODES, $data->componentCodes());
        $this->assertSame(['reduces_base' => false, 'penalty_points' => 7.5], $data->attendanceRules['izin']);
        $this->assertSame(['reduces_base' => true, 'penalty_points' => 0.0], $data->attendanceRules['cuti_lainnya']);

        // Bulan lama tetap memakai versinya sendiri.
        $this->assertSame('2026-11', ScoringRuleService::forPeriode('2026-12')->effectiveFrom);
        $this->assertSame('2027-01', ScoringRuleService::forPeriode('2027-01')->effectiveFrom);
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2026-10')->effectiveFrom);
    }

    public function test_create_rejects_past_or_taken_months_and_invalid_parameters_on_their_fields(): void
    {
        Livewire::actingAs($this->superAdmin);

        Livewire::test(CreateScoringRule::class)
            ->fillForm(['name' => 'Versi mundur', 'effective_from' => '2026-09'])
            ->call('create')
            ->assertHasFormErrors(['effective_from']);

        Livewire::test(CreateScoringRule::class)
            ->fillForm(['name' => 'Versi bentrok', 'effective_from' => '2026-11'])
            ->call('create')
            ->assertHasFormErrors(['effective_from' => 'unique']);

        Livewire::test(CreateScoringRule::class)
            ->fillForm([
                'name' => 'Versi salah',
                'kpi_weight' => 70.555,
                'review_max_points' => 0,
            ])
            ->set('data.attendance_rules.0.penalty_points', -1)
            ->call('create')
            ->assertHasFormErrors(['kpi_weight', 'review_max_points', 'attendance_rules.0.penalty_points']);

        // Bulan berjalan masih boleh (versi belum terkunci sampai bulannya selesai).
        Livewire::test(CreateScoringRule::class)
            ->fillForm(['name' => 'Koreksi Oktober', 'effective_from' => '2026-10'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, ScoringRule::query()->where('effective_from', '2026-10')->count());
        $this->assertSame(0, ScoringRule::query()->whereIn('effective_from', ['2026-09', '2026-12'])->count());
    }

    public function test_model_guard_errors_are_shown_on_the_matching_form_field(): void
    {
        Livewire::actingAs($this->superAdmin);

        // Kode komponen tidak dikenal hanya dicek penjaga model (ScoringRule::saving).
        Livewire::test(CreateScoringRule::class)
            ->fillForm(['name' => 'Komponen palsu'])
            ->set('data.attendance_rules.0.component', 'tidak_ada')
            ->call('create')
            ->assertHasFormErrors(['attendance_rules'])
            ->assertNotified('Aturan penilaian belum disimpan');

        $this->assertDatabaseMissing('scoring_rules', ['name' => 'Komponen palsu']);
    }

    // ==========================================
    // VERSI TERKUNCI / UBAH / HAPUS
    // ==========================================

    public function test_locked_version_is_view_only_except_name_and_notes(): void
    {
        Livewire::actingAs($this->superAdmin);
        $originalRules = $this->legacy->getRawOriginal('attendance_rules');

        Livewire::test(EditScoringRule::class, ['record' => $this->legacy->getRouteKey()])->assertForbidden();

        $view = Livewire::test(ViewScoringRule::class, ['record' => $this->legacy->getRouteKey()])
            ->assertSuccessful()
            ->assertSchemaStateSet(['effective_from' => '2000-01', 'kpi_weight' => 70.0])
            ->assertFormFieldDisabled('effective_from')
            ->assertFormFieldDisabled('kpi_weight')
            ->assertActionHidden('edit')
            ->assertActionHidden('delete')
            ->assertActionVisible('duplicate')
            ->assertActionVisible('editDetails');

        // Halaman lihat menampilkan aturan kehadiran persis seperti tersimpan (3 komponen lama).
        $this->assertSame(['late_less_30', 'late_more_30', 'sick_days'], array_column($view->get('data.attendance_rules'), 'component'));
        $this->assertEquals([1, 3, 5], array_column($view->get('data.attendance_rules'), 'penalty_points'));

        $view
            ->callAction('editDetails', ['name' => 'Aturan Lama (sebelum November 2026)', 'notes' => 'Catatan baru'])
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['name' => 'Aturan Lama (sebelum November 2026)']);

        $fresh = $this->legacy->fresh();
        $this->assertSame('Aturan Lama (sebelum November 2026)', $fresh->name);
        $this->assertSame('Catatan baru', $fresh->notes);
        $this->assertSame($originalRules, $fresh->getRawOriginal('attendance_rules'));
        $this->assertSame('2000-01', $fresh->effective_from);

        Livewire::test(ListScoringRules::class)
            ->assertActionHidden(TestAction::make('edit')->table($this->legacy))
            ->assertActionHidden(TestAction::make('delete')->table($this->legacy))
            ->assertActionVisible(TestAction::make('view')->table($this->legacy))
            ->assertActionVisible(TestAction::make('editDetails')->table($this->legacy))
            ->assertActionVisible(TestAction::make('edit')->table($this->november))
            ->assertActionVisible(TestAction::make('delete')->table($this->november))
            ->assertActionHidden(TestAction::make('view')->table($this->november))
            ->assertActionHidden(TestAction::make('editDetails')->table($this->november));
    }

    public function test_scheduled_version_can_be_edited_and_deleted(): void
    {
        Livewire::actingAs($this->superAdmin);

        $edit = Livewire::test(EditScoringRule::class, ['record' => $this->november->getRouteKey()])
            ->assertSuccessful()
            ->assertSchemaStateSet(['effective_from' => '2026-11', 'name' => 'Aturan Kehadiran November 2026']);

        $this->assertSame(self::COMPONENT_CODES, array_column($edit->get('data.attendance_rules'), 'component'));

        $edit
            ->fillForm(['kpi_weight' => 65, 'attendance_weight' => 20, 'effective_from' => '2026-12'])
            ->set('data.attendance_rules.4.penalty_points', 6)
            ->call('save')
            ->assertHasNoFormErrors();

        $data = ScoringRuleData::fromModel($this->november->fresh());
        $this->assertSame('2026-12', $data->effectiveFrom);
        $this->assertSame([65.0, 20.0, 15.0], [$data->kpiWeight, $data->attendanceWeight, $data->reviewWeight]);
        $this->assertSame(6.0, $data->penaltyPoints('mangkir'));
        $this->assertSame(5.0, $data->penaltyPoints('izin'));

        Livewire::test(ListScoringRules::class)
            ->callAction(TestAction::make('delete')->table($this->november->fresh()));

        $this->assertNull(ScoringRule::query()->find($this->november->id));
        $this->assertNotNull(ScoringRule::query()->find($this->legacy->id));
        $this->assertSame('2000-01', ScoringRuleService::forPeriode('2027-01')->effectiveFrom);
    }

    // ==========================================
    // DUPLIKAT JADI VERSI BARU
    // ==========================================

    public function test_duplicate_action_prefills_a_new_version_from_the_record_starting_next_free_month(): void
    {
        Livewire::actingAs($this->superAdmin);

        Livewire::test(ListScoringRules::class)
            ->assertActionVisible(TestAction::make('duplicate')->table($this->november))
            ->assertActionHasUrl(
                TestAction::make('duplicate')->table($this->november),
                ScoringRuleResource::getUrl('create', ['duplicate' => $this->november->id]),
            );

        $page = Livewire::withQueryParams(['duplicate' => (string) $this->november->id])
            ->test(CreateScoringRule::class)
            ->assertSchemaStateSet([
                'name' => 'Salinan dari Aturan Kehadiran November 2026',
                'effective_from' => '2026-12',
                'kpi_weight' => 70.0,
                'attendance_weight' => 15.0,
                'review_weight' => 15.0,
                'review_max_points' => 20,
            ]);

        $this->assertSame(self::COMPONENT_CODES, array_column($page->get('data.attendance_rules'), 'component'));

        $page
            ->fillForm(['name' => 'Aturan Desember 2026'])
            ->call('create')
            ->assertHasNoFormErrors();

        $copy = ScoringRule::query()->where('effective_from', '2026-12')->firstOrFail();
        $this->assertSame('Aturan Desember 2026', $copy->name);
        $this->assertSame(
            ScoringRuleData::fromModel($this->november)->attendanceRules,
            ScoringRuleData::fromModel($copy)->attendanceRules,
        );

        // Duplikat versi lama (terkunci): parameternya disalin, komponen aktif lain ditawarkan dengan 0.
        $legacyCopy = Livewire::withQueryParams(['duplicate' => (string) $this->legacy->id])
            ->test(CreateScoringRule::class)
            ->assertSchemaStateSet(['effective_from' => '2027-01', 'name' => 'Salinan dari Aturan Lama']);

        $rows = collect($legacyCopy->get('data.attendance_rules'))->keyBy('component');
        $this->assertSame(self::COMPONENT_CODES, $rows->keys()->all());
        $this->assertEquals(['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 5], $rows['sick_days']);
        $this->assertEquals(['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 0], $rows['izin']);

        // Halaman lihat juga punya tombol duplikat.
        Livewire::test(ViewScoringRule::class, ['record' => $this->legacy->getRouteKey()])
            ->assertActionHasUrl('duplicate', ScoringRuleResource::getUrl('create', ['duplicate' => $this->legacy->id]));
    }

    // ==========================================
    // SIMULASI
    // ==========================================

    public function test_simulator_matches_the_november_2026_worked_example(): void
    {
        $result = ScoringRuleSimulator::run([
            'work_days' => 22,
            'counts' => ['late_less_30' => 2, 'late_more_30' => 1, 'sick_days' => 1, 'izin' => 1],
            'review_points' => 16,
            'kpi_raw' => 100,
        ], ScoringRuleData::fromModel($this->november));

        $base = ((22 - 4) / 22) * 100; // telat ≤30 2 + telat >30 1 + sakit 1 mengurangi rumus dasar
        $this->assertEqualsWithDelta(81.81818181818181, $result['base_pct'], self::DELTA);
        $this->assertEqualsWithDelta($base, $result['base_pct'], self::DELTA);
        $this->assertEqualsWithDelta(10.0, $result['penalty'], self::DELTA); // 2×1 + 1×3 + 1×0 + 1×5
        $this->assertEqualsWithDelta(71.81818181818181, $result['final_pct'], self::DELTA);
        $this->assertEqualsWithDelta(10.772727272727273, $result['attendance_score'], self::DELTA);
        $this->assertEqualsWithDelta(12.0, $result['review_score'], self::DELTA);
        $this->assertEqualsWithDelta(70.0, $result['kpi_score'], self::DELTA);
        $this->assertEqualsWithDelta(92.77272727272727, $result['total'], self::DELTA);

        $this->assertSame('81,82%', ScoringRuleSimulator::format($result, 'base_pct'));
        $this->assertSame('10 poin', ScoringRuleSimulator::format($result, 'penalty'));
        $this->assertSame('10,77 / 15', ScoringRuleSimulator::format($result, 'attendance_score'));
    }

    public function test_simulator_uses_unsaved_form_state_side_by_side_with_the_current_version(): void
    {
        Livewire::actingAs($this->superAdmin);

        $page = Livewire::test(EditScoringRule::class, ['record' => $this->november->getRouteKey()])
            ->assertSee('Berlaku bulan ini: Aturan Lama')
            ->fillForm([
                'simulation' => [
                    'work_days' => 22,
                    'counts' => ['late_less_30' => 2, 'late_more_30' => 1, 'sick_days' => 1, 'izin' => 1],
                    'review_points' => 16,
                    'kpi_raw' => 100,
                ],
            ])
            ->assertSee('81,82%')
            ->assertSee('71,82%')
            ->assertSee('10 poin')
            ->assertSee('10,77 / 15')
            ->assertSee('12 / 15')
            ->assertSee('70 / 70')
            ->assertSee('92,77 / 100');

        // Ubah penalti izin (belum disimpan) => hanya sisi versi ini yang berubah; versi berlaku (Aturan Lama,
        // tanpa komponen izin) tetap 10,77.
        $page
            ->set('data.attendance_rules.3.penalty_points', 10)
            ->assertSee('15 poin')
            ->assertSee('66,82%')
            ->assertSee('10,02 / 15')
            ->assertSee('10,77 / 15');

        // Bobot ikut dari form: Kehadiran 25 => 71,82% × 25 = 17,95 (penalti izin dikembalikan 5).
        $page
            ->set('data.attendance_rules.3.penalty_points', 5)
            ->fillForm(['kpi_weight' => 60, 'attendance_weight' => 25])
            ->assertSee('17,95 / 25')
            ->assertSee('60 / 60');

        // Simulasi tidak ikut tersimpan.
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame(25.0, (float) $this->november->fresh()->attendance_weight);
        $this->assertArrayNotHasKey('simulation', $this->november->fresh()->getAttributes());
    }

    // ==========================================
    // KOMPONEN KEHADIRAN
    // ==========================================

    public function test_super_admin_creates_edits_and_deactivates_components(): void
    {
        Livewire::actingAs($this->superAdmin);

        Livewire::test(ManageAttendanceComponents::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(AttendanceComponent::query()->orderBy('sort_order')->get(), inOrder: true)
            ->callAction('create', [
                'code' => 'izin_khusus',
                'name' => 'Izin khusus',
                'unit' => 'hari',
                'description' => 'Izin dengan surat resmi',
                'sort_order' => 8,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $created = $this->componentByCode('izin_khusus');
        $this->assertSame('Izin khusus', $created->name);
        $this->assertNull($created->legacy_column);

        Livewire::test(ManageAttendanceComponents::class)
            ->callAction('create', ['code' => 'Izin Khusus', 'name' => 'Salah', 'unit' => 'hari', 'sort_order' => 9])
            ->assertHasActionErrors(['code' => 'regex']);

        Livewire::test(ManageAttendanceComponents::class)
            ->callAction('create', ['code' => 'izin', 'name' => 'Duplikat', 'unit' => 'hari', 'sort_order' => 9])
            ->assertHasActionErrors(['code' => 'unique']);

        // Komponen aktif baru langsung ditawarkan di form versi baru, tanpa ubah kode.
        $rows = Livewire::test(CreateScoringRule::class)->get('data.attendance_rules');
        $this->assertContains('izin_khusus', array_column($rows, 'component'));

        // Ubah: kode tetap (field kode nonaktif saat ubah).
        Livewire::test(ManageAttendanceComponents::class)
            ->mountAction(TestAction::make('edit')->table($created))
            ->assertFormFieldDisabled('code')
            ->assertFormFieldEnabled('name');

        Livewire::test(ManageAttendanceComponents::class)
            ->callAction(TestAction::make('edit')->table($created), ['name' => 'Izin khusus (surat)', 'unit' => 'kejadian'])
            ->assertHasNoActionErrors();
        $this->assertSame(['izin_khusus', 'Izin khusus (surat)', 'kejadian'], [$created->fresh()->code, $created->fresh()->name, $created->fresh()->unit]);

        // Nonaktifkan komponen yang ada di versi November 2026 tanpa penalti (cuti lainnya: 0 poin, tidak
        // mengurangi rumus dasar). Izin (5 poin/hari) masih dinilai sehingga belum bisa dinonaktifkan.
        $cuti = $this->componentByCode('cuti_lainnya');

        Livewire::test(ManageAttendanceComponents::class)
            ->assertActionHidden(TestAction::make('activate')->table($cuti))
            ->assertActionDisabled(TestAction::make('deactivate')->table($this->componentByCode('izin')))
            ->callAction(TestAction::make('deactivate')->table($cuti))
            ->assertNotified('Komponen dinonaktifkan');

        $this->assertFalse($cuti->fresh()->is_active);
        $this->assertTrue($this->componentByCode('izin')->is_active);

        // Versi yang sudah memakai cuti lainnya tetap menampilkannya (ditandai nonaktif)...
        $edit = Livewire::test(EditScoringRule::class, ['record' => $this->november->getRouteKey()])
            ->assertSee('Cuti lainnya · per hari (nonaktif)');
        $this->assertContains('cuti_lainnya', array_column($edit->get('data.attendance_rules'), 'component'));

        // ...tetapi tidak ditawarkan lagi untuk versi yang belum memakainya.
        $legacyCopy = Livewire::withQueryParams(['duplicate' => (string) $this->legacy->id])->test(CreateScoringRule::class);
        $this->assertNotContains('cuti_lainnya', array_column($legacyCopy->get('data.attendance_rules'), 'component'));

        Livewire::test(ManageAttendanceComponents::class)
            ->callAction(TestAction::make('activate')->table($cuti->fresh()));
        $this->assertTrue($cuti->fresh()->is_active);
    }

    public function test_components_still_scored_by_last_current_or_scheduled_versions_cannot_be_deactivated(): void
    {
        Livewire::actingAs($this->superAdmin);
        $super = Gate::forUser($this->superAdmin);

        // 17 Oktober 2026: bulan lalu & bulan berjalan = Aturan Lama, terjadwal = November 2026.
        foreach (['late_less_30', 'late_more_30', 'sick_days', 'izin', 'mangkir'] as $code) {
            $this->assertTrue($super->denies('deactivate', $this->componentByCode($code)), $code);
        }

        foreach (['cuti_tahunan', 'cuti_lainnya'] as $code) {
            $this->assertTrue($super->allows('deactivate', $this->componentByCode($code)), $code);
        }

        $this->assertFalse(Gate::forUser($this->scopedAdmin)->allows('deactivate', $this->componentByCode('cuti_tahunan')));

        $mangkir = $this->componentByCode('mangkir');
        $message = (string) $super->inspect('deactivate', $mangkir)->message();
        $this->assertStringContainsString('"Aturan Kehadiran November 2026" (mulai November 2026, penalti 5 poin/hari)', $message);
        $this->assertStringContainsString('belum bisa dinonaktifkan', $message);
        $this->assertStringContainsString('"Aturan Lama" (mulai Januari 2000, penalti 1 poin/kejadian, mengurangi rumus dasar)', (string) $super->inspect('deactivate', $this->componentByCode('late_less_30'))->message());

        // Tombol nonaktif dengan alasan; aksi dan toggle Aktif di form ubah juga tidak mengubah apa pun.
        Livewire::test(ManageAttendanceComponents::class)
            ->assertActionDisabled(TestAction::make('deactivate')->table($mangkir))
            ->assertSee('Komponen masih dinilai versi aturan')
            ->mountAction(TestAction::make('edit')->table($mangkir))
            ->assertFormFieldDisabled('is_active');

        Livewire::test(ManageAttendanceComponents::class)
            ->callAction(TestAction::make('edit')->table($mangkir), ['name' => 'Mangkir', 'is_active' => false])
            ->assertHasNoActionErrors();

        $this->assertTrue($mangkir->fresh()->is_active);
        $this->assertSame('Mangkir', $mangkir->fresh()->name);

        // Versi Desember 2026 berhenti menilai mangkir. Desember: bulan lalu (November) masih menilai mangkir
        // dan datanya baru diinput setelah bulannya selesai, jadi masih ditolak. Januari 2027: boleh.
        ScoringRule::query()->create([
            'name' => 'Aturan Desember 2026',
            'effective_from' => '2026-12',
            'kpi_weight' => 70,
            'attendance_weight' => 15,
            'review_weight' => 15,
            'review_max_points' => 20,
            'attendance_rules' => collect(ScoringRuleData::fromModel($this->november)->attendanceRulesList())
                ->map(fn (array $row): array => $row['component'] === 'mangkir' ? ['component' => 'mangkir', 'reduces_base' => false, 'penalty_points' => 0] : $row)
                ->all(),
        ]);
        ScoringRuleService::clearMemo();

        $this->assertTrue($super->denies('deactivate', $mangkir->fresh()));
        $this->assertStringNotContainsString('Aturan Desember 2026', (string) $super->inspect('deactivate', $mangkir->fresh())->message());

        $this->travelTo(Date::create(2026, 12, 5, 9));
        ScoringRuleService::clearMemo();
        $this->assertTrue($super->denies('deactivate', $mangkir->fresh()));

        $this->travelTo(Date::create(2027, 1, 5, 9));
        ScoringRuleService::clearMemo();
        $this->assertTrue($super->allows('deactivate', $mangkir->fresh()));
        $this->assertTrue($super->denies('deactivate', $this->componentByCode('izin')));

        Livewire::test(ManageAttendanceComponents::class)
            ->callAction(TestAction::make('deactivate')->table($mangkir->fresh()))
            ->assertNotified('Komponen dinonaktifkan');

        $this->assertFalse($mangkir->fresh()->is_active);
    }

    public function test_new_versions_leave_out_inactive_components_with_a_warning(): void
    {
        Livewire::actingAs($this->superAdmin);

        // Dinonaktifkan di luar Eloquent (mis. data lama sebelum penjaga nonaktif ada); model sendiri menolaknya.
        DB::table('attendance_components')->where('code', 'mangkir')->update(['is_active' => false]);
        ScoringRuleService::clearCache();
        ScoringRuleService::clearMemo();

        $page = Livewire::test(CreateScoringRule::class)
            ->assertNotified('Komponen nonaktif tidak disertakan');
        $this->assertSame(['Mangkir (tanpa keterangan): 5 poin/hari'], ScoringRuleResource::inactiveComponentsLeftOut($this->november));
        $this->assertSame([], ScoringRuleResource::inactiveComponentsLeftOut($this->legacy));

        $codes = array_column($page->get('data.attendance_rules'), 'component');
        $this->assertNotContains('mangkir', $codes);
        $this->assertSame(['late_less_30', 'late_more_30', 'sick_days', 'izin', 'cuti_tahunan', 'cuti_lainnya'], $codes);

        $page->fillForm(['name' => 'Aturan Desember 2026'])->call('create')->assertHasNoFormErrors();

        $created = ScoringRuleData::fromModel(ScoringRule::query()->where('effective_from', '2026-12')->firstOrFail());
        $this->assertFalse($created->hasComponent('mangkir'));
        $this->assertSame(5.0, $created->penaltyPoints('izin'));

        // Duplikat versi November juga tanpa mangkir; versi November sendiri tetap menampilkannya.
        $copy = Livewire::withQueryParams(['duplicate' => (string) $this->november->id])
            ->test(CreateScoringRule::class)
            ->assertNotified('Komponen nonaktif tidak disertakan');
        $this->assertNotContains('mangkir', array_column($copy->get('data.attendance_rules'), 'component'));

        $edit = Livewire::test(EditScoringRule::class, ['record' => $this->november->getRouteKey()])
            ->assertSee('Mangkir (tanpa keterangan) · per hari (nonaktif)');
        $rows = collect($edit->get('data.attendance_rules'))->keyBy('component');
        $this->assertEquals(5, $rows['mangkir']['penalty_points']);

        // Tanpa komponen nonaktif di versi sumber: tidak ada peringatan.
        $this->componentByCode('mangkir')->update(['is_active' => true]);
        ScoringRuleService::clearMemo();
        Livewire::withQueryParams(['duplicate' => (string) $this->legacy->id])
            ->test(CreateScoringRule::class)
            ->assertNotNotified('Komponen nonaktif tidak disertakan');
    }

    public function test_component_unit_is_fixed_once_the_component_is_used(): void
    {
        Livewire::actingAs($this->superAdmin);

        $unused = $this->makeComponent('dinas_luar', 'Dinas luar');
        $usedByAttendance = $this->makeComponent('wfh', 'Kerja dari rumah');
        Attendance::query()->create([
            'user_id' => $this->staff->id,
            'periode' => '2026-11',
            'work_days' => 20,
            'component_counts' => ['wfh' => 2],
        ]);
        ScoringRuleService::clearMemo();

        foreach (['late_less_30', 'izin', 'wfh'] as $code) {
            Livewire::test(ManageAttendanceComponents::class)
                ->mountAction(TestAction::make('edit')->table($this->componentByCode($code)))
                ->assertFormFieldDisabled('unit');
            $this->assertTrue(AttendanceComponentResource::isInUse($this->componentByCode($code)), $code);
        }

        $this->assertFalse(AttendanceComponentResource::isInUse($unused));

        Livewire::test(ManageAttendanceComponents::class)
            ->mountAction(TestAction::make('edit')->table($unused))
            ->assertFormFieldEnabled('unit');

        // Satuan yang dipakai versi terkunci tidak berubah walaupun dikirim.
        Livewire::test(ManageAttendanceComponents::class)
            ->callAction(TestAction::make('edit')->table($this->componentByCode('late_less_30')), ['name' => 'Telat ≤ 30 menit', 'unit' => 'hari'])
            ->assertHasNoActionErrors();
        Livewire::test(ManageAttendanceComponents::class)
            ->callAction(TestAction::make('edit')->table($usedByAttendance), ['unit' => 'kejadian'])
            ->assertHasNoActionErrors();

        $this->assertSame(['Telat ≤ 30 menit', 'kejadian'], [$this->componentByCode('late_less_30')->name, $this->componentByCode('late_less_30')->unit]);
        $this->assertSame('hari', $usedByAttendance->fresh()->unit);
        $this->assertContains('Telat ≤ 30 menit: 1 poin/kejadian, kurangi rumus dasar', ScoringRulePresenter::componentSummaries(ScoringRuleService::forPeriode('2026-10')));
    }

    public function test_component_table_checks_attendance_usage_once_per_row(): void
    {
        Livewire::actingAs($this->superAdmin);

        foreach (['komp_a', 'komp_b', 'komp_c'] as $code) {
            $this->makeComponent($code, 'Komponen '.$code);
        }
        ScoringRuleService::clearMemo();

        $scans = 0;
        DB::listen(function ($query) use (&$scans): void {
            if (str_contains($query->sql, 'component_counts')) {
                $scans++;
            }
        });

        $page = Livewire::test(ManageAttendanceComponents::class)
            ->assertActionEnabled(TestAction::make('delete')->table($this->componentByCode('komp_a')));
        $initial = $scans;

        // Satu pemindaian per komponen yang belum dipakai versi aturan (tampil, nonaktif, tooltip memakai hasil yang sama).
        $scans = 0;
        $page->call('$refresh');
        $this->assertSame(3, $scans);
        $this->assertLessThanOrEqual(6, $initial);
    }

    public function test_only_unused_components_can_be_deleted(): void
    {
        Livewire::actingAs($this->superAdmin);

        $unused = $this->makeComponent('dinas_luar', 'Dinas luar');
        $usedByAttendance = $this->makeComponent('wfh', 'Kerja dari rumah');
        Attendance::query()->create([
            'user_id' => $this->staff->id,
            'periode' => '2026-11',
            'work_days' => 20,
            'component_counts' => ['wfh' => 2],
        ]);
        ScoringRuleService::clearMemo();

        Livewire::test(ManageAttendanceComponents::class)
            ->assertActionVisible(TestAction::make('delete')->table($unused))
            ->assertActionEnabled(TestAction::make('delete')->table($unused))
            // Dipakai versi aturan / data kehadiran / komponen bawaan: tombol hapus nonaktif dengan alasan.
            ->assertActionDisabled(TestAction::make('delete')->table($this->componentByCode('izin')))
            ->assertActionDisabled(TestAction::make('delete')->table($usedByAttendance))
            ->assertActionDisabled(TestAction::make('delete')->table($this->componentByCode('late_less_30')))
            ->assertSee('Komponen sudah dipakai aturan penilaian (Aturan Kehadiran November 2026) sehingga tidak dapat dihapus. Nonaktifkan saja.')
            ->assertSee('Komponen sudah dipakai data kehadiran sehingga tidak dapat dihapus. Nonaktifkan saja.')
            ->callAction(TestAction::make('delete')->table($this->componentByCode('izin')))
            ->callAction(TestAction::make('delete')->table($unused));

        $this->assertNull(AttendanceComponent::query()->find($unused->id));
        $this->assertNotNull(AttendanceComponent::query()->where('code', 'izin')->first());
        $this->assertNotNull(AttendanceComponent::query()->where('code', 'wfh')->first());
    }

    // ==========================================
    // API MASTER (BACA SAJA)
    // ==========================================

    public function test_api_lists_rules_newest_first_for_any_authenticated_user(): void
    {
        $this->getJson('/api/v1/master/scoring-rules')->assertUnauthorized();

        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/v1/master/scoring-rules')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_periode', '2026-10')
            ->assertJsonPath('meta.current_rule_id', $this->legacy->id)
            ->assertJsonPath('data.0.id', $this->november->id)
            ->assertJsonPath('data.0.effective_from', '2026-11')
            ->assertJsonPath('data.0.effective_from_label', 'November 2026')
            ->assertJsonPath('data.0.status', 'terjadwal')
            ->assertJsonPath('data.0.status_label', 'Terjadwal')
            ->assertJsonPath('data.0.is_locked', false)
            ->assertJsonPath('data.1.id', $this->legacy->id)
            ->assertJsonPath('data.1.status', 'berlaku')
            ->assertJsonPath('data.1.is_locked', true)
            ->assertJsonPath('data.1.is_fallback', false);

        $november = $response->json('data.0');
        $this->assertEquals(['kpi' => 70, 'attendance' => 15, 'review' => 15], $november['weights']);
        $this->assertSame(20, $november['review_max_points']);
        $this->assertSame(self::COMPONENT_CODES, array_column($november['attendance_rules'], 'component'));

        $izin = collect($november['attendance_rules'])->firstWhere('component', 'izin');
        $this->assertSame('Izin', $izin['name']);
        $this->assertSame('hari', $izin['unit']);
        $this->assertFalse($izin['reduces_base']);
        $this->assertEquals(5, $izin['penalty_points']);

        // Catatan versi bersifat internal: tidak dikirim ke staff/admin per badan usaha, hanya ke super admin.
        $this->assertArrayNotHasKey('notes', $november);
        $this->assertArrayNotHasKey('notes', $response->json('data.1'));

        Sanctum::actingAs($this->scopedAdmin);
        $this->assertArrayNotHasKey('notes', $this->getJson('/api/v1/master/scoring-rules')->assertOk()->json('data.0'));
        $this->assertArrayNotHasKey('notes', $this->getJson('/api/v1/master/scoring-rules/resolve?periode=2026-11')->assertOk()->json('data'));

        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/master/scoring-rules')
            ->assertOk()
            ->assertJsonPath('data.0.notes', $this->november->notes)
            ->assertJsonPath('data.1.notes', $this->legacy->notes);
        $this->getJson('/api/v1/master/scoring-rules/resolve?periode=1970-01')
            ->assertOk()
            ->assertJsonPath('data.notes', null);
    }

    public function test_api_resolves_the_rule_of_a_periode(): void
    {
        Sanctum::actingAs($this->scopedAdmin);

        $cases = [
            '2026-10' => $this->legacy->id,
            '2026-11' => $this->november->id,
            '2027-03' => $this->november->id,
            '1970-01' => null,
        ];

        foreach ($cases as $periode => $expectedId) {
            $response = $this->getJson('/api/v1/master/scoring-rules/resolve?periode='.$periode)
                ->assertOk()
                ->assertJsonPath('meta.periode', $periode)
                ->assertJsonPath('data.id', $expectedId);

            if ($expectedId === null) {
                // Sebelum versi pertama: aturan lama tertanam di kode.
                $response->assertJsonPath('data.is_fallback', true)->assertJsonPath('data.name', 'Aturan Lama');
            }
        }

        $this->getJson('/api/v1/master/scoring-rules/resolve')
            ->assertOk()
            ->assertJsonPath('meta.periode', '2026-10')
            ->assertJsonPath('data.id', $this->legacy->id)
            ->assertJsonPath('data.status', 'berlaku');

        foreach (['2026-13', '2026-1', 'abc'] as $invalid) {
            $this->getJson('/api/v1/master/scoring-rules/resolve?periode='.$invalid)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('periode');
        }
    }

    public function test_api_lists_attendance_components(): void
    {
        $this->getJson('/api/v1/master/attendance-components')->assertUnauthorized();

        DB::table('attendance_components')->where('code', 'mangkir')->update(['is_active' => false]);
        ScoringRuleService::clearCache();

        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/v1/master/attendance-components')
            ->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('data.0.code', 'late_less_30')
            ->assertJsonPath('data.0.unit', 'kejadian')
            ->assertJsonPath('data.0.is_builtin', true)
            ->assertJsonPath('data.3.code', 'izin')
            ->assertJsonPath('data.3.is_builtin', false);

        $this->assertSame(self::COMPONENT_CODES, array_column($response->json('data'), 'code'));

        $this->getJson('/api/v1/master/attendance-components?active=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'mangkir')
            ->assertJsonPath('data.0.is_active', false);

        $this->getJson('/api/v1/master/attendance-components?active=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 6);
    }

    public function test_review_max_points_cannot_be_below_the_reachable_review_points(): void
    {
        Livewire::actingAs($this->superAdmin);

        Livewire::test(CreateScoringRule::class)
            ->fillForm(['name' => 'Poin maksimal kecil', 'review_max_points' => 10])
            ->call('create')
            ->assertHasFormErrors(['review_max_points' => 'min'])
            ->assertSee('tidak boleh lebih kecil dari poin tertinggi yang bisa dicapai (20');

        Livewire::test(EditScoringRule::class, ['record' => $this->november->getRouteKey()])
            ->fillForm(['review_max_points' => 19])
            ->call('save')
            ->assertHasFormErrors(['review_max_points' => 'min']);

        $this->assertSame(20, $this->november->fresh()->review_max_points);
        $this->assertDatabaseMissing('scoring_rules', ['name' => 'Poin maksimal kecil']);

        Livewire::test(CreateScoringRule::class)
            ->fillForm(['name' => 'Poin maksimal besar', 'review_max_points' => 25])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(25, ScoringRule::query()->where('name', 'Poin maksimal besar')->value('review_max_points'));

        // Simulasi menandai skor yang melebihi bobotnya.
        $draft = ScoringRuleSimulator::draftRule([
            'kpi_weight' => 70, 'attendance_weight' => 15, 'review_weight' => 15, 'review_max_points' => 10, 'attendance_rules' => [],
        ]);
        $result = ScoringRuleSimulator::run(['work_days' => 22, 'counts' => [], 'review_points' => 20, 'kpi_raw' => 100], $draft);

        $this->assertTrue(ScoringRuleSimulator::exceedsLimit($result, 'review_score'));
        $this->assertSame('30 / 15 (melebihi bobot)', ScoringRuleSimulator::format($result, 'review_score'));
        $this->assertSame('115 / 100 (melebihi 100)', ScoringRuleSimulator::format($result, 'total'));
        $this->assertFalse(ScoringRuleSimulator::exceedsLimit($result, 'kpi_score'));
        $this->assertSame('70 / 70', ScoringRuleSimulator::format($result, 'kpi_score'));
    }

    public function test_junk_duplicate_query_string_is_ignored_and_authorization_still_applies(): void
    {
        $this->actingAs($this->superAdmin);

        $this->get('/admin/scoring-rules/create?duplicate[]=1')->assertOk();
        $this->get('/admin/scoring-rules/create?duplicate[a]=1')->assertOk();
        $this->get('/admin/scoring-rules/create?duplicate=1abc')->assertOk();

        Livewire::actingAs($this->superAdmin);
        Livewire::withQueryParams(['duplicate' => [(string) $this->november->id]])
            ->test(CreateScoringRule::class)
            ->assertSuccessful()
            ->assertSchemaStateSet(['name' => null, 'effective_from' => '2026-12']);

        foreach ([$this->staff, $this->scopedAdmin] as $user) {
            $this->actingAs($user);
            $this->resetAccessMemos();
            $this->get('/admin/scoring-rules/create?duplicate[]=1')->assertForbidden();
        }
    }

    public function test_settings_resources_do_not_enable_global_search(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertFalse(ScoringRuleResource::canGloballySearch());
        $this->assertFalse(AttendanceComponentResource::canGloballySearch());
        // Judul record tetap dipakai untuk breadcrumb dan modal.
        $this->assertSame('Aturan Lama', ScoringRuleResource::getRecordTitle($this->legacy));
    }

    private function componentByCode(string $code): AttendanceComponent
    {
        return AttendanceComponent::query()->where('code', $code)->firstOrFail();
    }

    private function makeComponent(string $code, string $name): AttendanceComponent
    {
        return AttendanceComponent::query()->create([
            'code' => $code,
            'name' => $name,
            'unit' => AttendanceComponent::UNIT_DAY,
            'sort_order' => 20,
            'is_active' => true,
        ]);
    }
}
