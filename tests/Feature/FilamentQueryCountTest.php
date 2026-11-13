<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DataAccessGuide;
use App\Filament\Pages\ScoringRulesGuide;
use App\Filament\Resources\ApprovalRules\Pages\ManageApprovalRules;
use App\Filament\Resources\Areas\Pages\ManageAreas;
use App\Filament\Resources\AttendanceComponents\Pages\ManageAttendanceComponents;
use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Filament\Resources\Divisis\Pages\ManageDivisis;
use App\Filament\Resources\EmployeeReviews\Pages\CreateEmployeeReview;
use App\Filament\Resources\EmployeeReviews\Pages\EditEmployeeReview;
use App\Filament\Resources\EmployeeReviews\Pages\ListEmployeeReviews;
use App\Filament\Resources\KpiCategories\Pages\ManageKpiCategories;
use App\Filament\Resources\KpiDescriptions\Pages\ManageKpiDescriptions;
use App\Filament\Resources\KpiReminderSettings\Pages\ListKpiReminderSettings;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\EditKpi;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Resources\Positions\Pages\ManagePositions;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Filament\Resources\ScoringRules\Pages\CreateScoringRule;
use App\Filament\Resources\ScoringRules\Pages\EditScoringRule;
use App\Filament\Resources\ScoringRules\Pages\ListScoringRules;
use App\Filament\Resources\ScoringRules\Pages\ViewScoringRule;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\WorkJournals\Pages\ManageWorkJournals;
use App\Filament\Widgets\ChecklistKPI;
use App\Filament\Widgets\LeaderboardKPI;
use App\Models\ApprovalRule;
use App\Models\Area;
use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\Cutpoint;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiReminderSetting;
use App\Models\KpiType;
use App\Models\Position;
use App\Models\Role;
use App\Models\ScoringRule;
use App\Models\User;
use App\Models\WorkJournal;
use App\Services\ApprovalResolverService;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use App\Services\KpiCacheService;
use App\Services\ScoringRuleService;
use Closure;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Penjaga N+1 untuk panel Filament: setiap halaman dirender dua kali, sebelum dan sesudah data ditambah satu
 * "unit" (karyawan, pemilik yang sudah dihapus, akun ADMIN lain, baris tiap modul, dan master baru). Jumlah
 * query tidak boleh bertambah bersama jumlah baris. Tambahkan halaman/aksi baru ke scenarios().
 */
class FilamentQueryCountTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    /** Halaman dengan tabel deferLoading(): baris baru dimuat setelah loadTable. */
    private const DEFERRED = [ListKpis::class, ListEmployeeReviews::class, ListAttendances::class];

    private const DASHBOARD = [Dashboard::class, ChecklistKPI::class, LeaderboardKPI::class];

    /** Daftar data karyawan yang bisa dibuka admin per badan usaha dan atasan. */
    private const EMPLOYEE_DATA_LISTS = [
        ManageCutpoints::class,
        ListKpis::class,
        ManageWorkJournals::class,
        ListEmployeeReviews::class,
        ListAttendances::class,
    ];

    /** Master & pengaturan: khusus super admin. */
    private const MASTER_LISTS = [
        ManageKpiCategories::class,
        ManageKpiDescriptions::class,
        ManageAreas::class,
        ManageDivisis::class,
        ManagePositions::class,
        ManageRoles::class,
        ManageApprovalRules::class,
        ListKpiReminderSettings::class,
        ListScoringRules::class,
        ManageAttendanceComponents::class,
    ];

    private Area $home;

    private Area $uls;

    /** @var array<string, User> */
    private array $actors = [];

    /** @var array<string, Kpi> KPI bulan berjalan milik tiap aktor; detailnya bertambah per unit */
    private array $actorKpis = [];

    private int $seeded = 0;

    private Kpi $staffKpi;

    private User $staff;

    private Attendance $staffAttendance;

    private EmployeeReview $staffReview;

    private ScoringRule $latestRule;

    /** @var array<string, list<int|null>> jumlah baris tabel per skenario, per pengukuran */
    private array $rowCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
        KpiCacheService::clearKpiCache();

        foreach ([1 => 'ADMINISTRATION', 2 => 'REPORTING', 3 => 'MAIN JOB'] as $id => $name) {
            KpiCategory::query()->forceCreate(['id' => $id, 'name' => $name]);
        }

        foreach ([1 => 'DAILY', 2 => 'WEEKLY', 3 => 'MONTHLY'] as $id => $name) {
            KpiType::query()->forceCreate(['id' => $id, 'name' => $name]);
        }

        $this->home = $this->fixtureArea('COO');
        $this->uls = $this->fixtureArea('ULS');

        $this->actors['super'] = $this->fixtureGlobalAdmin('super_admin', $this->home);
        $this->actors['scoped'] = $this->fixtureScopedAdmin('scoped_admin', $this->home, [$this->uls]);
        $this->actors['manager'] = $this->fixtureUser('uls_manager', 'MANAGER', $this->uls);
        $this->actors['staff'] = $this->fixtureUser('uls_owner', 'STAFF', $this->uls, $this->actors['manager']);

        foreach ($this->actors as $key => $actor) {
            $this->actorKpis[$key] = Kpi::query()->create([
                'user_id' => $actor->id,
                'kpi_category_id' => 3,
                'kpi_type_id' => 3,
                'date' => now()->startOfMonth()->addDays(2)->toDateString(),
                'percentage' => 100,
            ]);
        }

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_pages_do_not_run_more_queries_when_rows_are_added(): void
    {
        $this->seedUnits(1);
        $before = $this->measureAll();
        $this->seedUnits(1);
        $after = $this->measureAll();

        $grown = [];

        foreach ($after as $name => $counts) {
            if (array_sum($counts) <= array_sum($before[$name])) {
                continue;
            }

            $lines = [sprintf('%s: %d -> %d query', $name, array_sum($before[$name]), array_sum($counts))];

            foreach ($counts as $sql => $count) {
                if ($count !== ($before[$name][$sql] ?? 0)) {
                    $lines[] = sprintf('    %d -> %d  %s', $before[$name][$sql] ?? 0, $count, mb_strimwidth($sql, 0, 220, '...'));
                }
            }

            $grown[] = implode("\n", $lines);
        }

        $this->assertSame([], $grown, "Jumlah query bertambah bersama jumlah baris (N+1):\n".implode("\n", $grown));

        // Penjaganya tidak kosong: daftar milik super admin memang bertambah barisnya di pengukuran kedua.
        foreach ([...self::EMPLOYEE_DATA_LISTS, ListUsers::class, ...self::MASTER_LISTS] as $page) {
            [$rowsBefore, $rowsAfter] = $this->rowCounts[class_basename($page).' @super'];

            $this->assertGreaterThan($rowsBefore, $rowsAfter, class_basename($page).' tidak menampilkan baris baru.');
        }
    }

    public function test_kpi_edit_form_reads_description_labels_from_loaded_details(): void
    {
        $this->seedUnits(2);
        $kpi = $this->actorKpis['super'];
        $this->assertGreaterThanOrEqual(4, $kpi->kpi_detail()->count());

        // Render pertama (KPI dimuat bersama detail & deskripsinya) dan render ulang (KPI dimuat ulang tanpa relasi).
        $queries = $this->measure($this->actors['super'], fn (): Testable => Livewire::test(EditKpi::class, ['record' => $kpi->getRouteKey()]));

        // Label deskripsi tiap baris repeater berasal dari relasi yang dimuat sekaligus, bukan find() per baris:
        // satu query deskripsi per render, berapa pun jumlah detailnya.
        $this->assertSame(0, $this->countMatching($queries, '"kpi_descriptions"."id" = ?'));
        $this->assertSame(2, $this->countMatching($queries, 'from "kpi_descriptions"'));
        $this->assertSame(2, $this->countMatching($queries, 'from "kpi_details"'));
    }

    public function test_user_form_loads_approval_rules_once_per_render(): void
    {
        $this->seedUnits(1);

        foreach ([CreateUser::class => [], EditUser::class => ['record' => $this->staff->getRouteKey()]] as $page => $parameters) {
            $queries = $this->measure(
                $this->actors['super'],
                fn (): Testable => Livewire::test($page, $parameters),
                rerender: false,
            );

            // Saran atasan dihitung beberapa kali per render (opsi, teks bantuan), aturannya dibaca sekali.
            $this->assertSame(1, $this->countMatching($queries, 'from "approval_rules"'), class_basename($page));
        }
    }

    public function test_approval_resolver_memo_follows_rule_division_and_employee_changes(): void
    {
        $this->seedUnits(1);
        $divisi = $this->fixtureDivisi($this->uls);
        $resolve = fn (): ?int => ApprovalResolverService::resolve(divisiId: (int) $divisi->id)?->id;

        $this->assertNull($resolve());

        // Kepala divisi baru langsung terpakai.
        $divisi->update(['manager_id' => $this->actors['manager']->id]);
        $this->assertSame($this->actors['manager']->id, $resolve());

        // Aturan baru mengalahkan kepala divisi; aturan dinonaktifkan atau atasannya dihapus kembali ke kepala divisi.
        $rule = ApprovalRule::query()->create([
            'name' => 'Aturan Divisi ULS',
            'divisi_id' => $divisi->id,
            'approver_id' => $this->staff->id,
            'priority' => 1,
            'is_active' => true,
        ]);
        $this->assertSame($this->staff->id, $resolve());

        $rule->update(['is_active' => false]);
        $this->assertSame($this->actors['manager']->id, $resolve());

        $rule->update(['is_active' => true]);
        $this->assertSame($this->staff->id, $resolve());

        $this->staff->delete();
        $this->assertSame($this->actors['manager']->id, $resolve());

        $this->staff->restore();
        $this->assertSame($this->staff->id, $resolve());

        // Tanpa perubahan data, aturan tidak dibaca ulang.
        $queries = $this->countQueries(function () use ($resolve): void {
            $resolve();
            $resolve();
        });
        $this->assertSame(0, $queries);
    }

    public function test_trashed_owner_is_resolved_from_the_eager_loaded_relation(): void
    {
        $this->seedUnits(1);
        $gone = User::onlyTrashed()->where('username', 'gone_1')->firstOrFail();

        foreach ([Kpi::class, Attendance::class, EmployeeReview::class] as $model) {
            $record = $model::query()->with(['user', 'trashedUser.role'])->where('user_id', $gone->id)->firstOrFail();

            // Relasi user tidak memuat karyawan yang dihapus; trashedUser yang dimuat sekaligus dipakai untuk cek akses.
            $this->assertNull($record->user, $model);
            $this->assertTrue($gone->is($record->ownerForAccessCheck()), $model);

            // Pemilik yang masih aktif tetap dari relasi user; tanpa relasi yang dimuat kembali ke id.
            $active = $model::query()->with(['user', 'trashedUser'])->where('user_id', $this->staff->id)->firstOrFail();
            $this->assertTrue($this->staff->is($active->ownerForAccessCheck()), $model);
            $this->assertNull($active->trashedUser, $model);
            $this->assertSame((int) $gone->id, (int) $model::query()->where('user_id', $gone->id)->firstOrFail()->ownerForAccessCheck(), $model);
        }

        // Admin per badan usaha tetap melihat dan mengubah data pemilik yang dihapus, tanpa query per baris.
        $kpi = Kpi::query()->with(['user.role', 'trashedUser.role'])->where('user_id', $gone->id)->firstOrFail();
        $scoped = User::query()->with('role')->findOrFail($this->actors['scoped']->id);
        $this->resetAccessMemos();
        // Cakupan badan usaha aktor dibaca sekali per request, bukan per baris.
        $this->assertSame([$this->uls->id], DataAccessService::allowedAreaIds($scoped));

        $this->assertSame(0, $this->countQueries(function () use ($scoped, $kpi): void {
            $this->assertTrue($scoped->can('view', $kpi));
            $this->assertTrue($scoped->can('update', $kpi));
        }));
    }

    public function test_supervisor_filter_reads_the_approval_line_once_per_request(): void
    {
        $this->seedUnits(1);

        $this->assertSame(1, $this->countQueries(function (): void {
            ApprovalScopeService::getAllSupervisorIds([$this->staff->id]);
            ApprovalScopeService::getAllSubordinateIds((int) $this->actors['manager']->id);
        }));

        // Perubahan approval line terbaca setelah memo dikosongkan (sama seperti daftar bawahan).
        $this->staff->update(['approval_id' => $this->actors['super']->id]);
        ApprovalScopeService::clearMemo();

        $this->assertNotContains($this->staff->id, ApprovalScopeService::getAllSubordinateIds((int) $this->actors['manager']->id));
    }

    /**
     * Skenario per aktor: nama => closure yang merender halaman/aksi.
     *
     * @return array<string, Closure(): Testable>
     */
    private function scenarios(string $actorKey): array
    {
        $pages = match ($actorKey) {
            'super' => [...self::DASHBOARD, ...self::EMPLOYEE_DATA_LISTS, ListUsers::class, ...self::MASTER_LISTS, ScoringRulesGuide::class, DataAccessGuide::class],
            'scoped' => [...self::DASHBOARD, ...self::EMPLOYEE_DATA_LISTS, ListUsers::class, ScoringRulesGuide::class],
            'manager' => [...self::DASHBOARD, ...self::EMPLOYEE_DATA_LISTS, ListTeamMembers::class, ScoringRulesGuide::class],
            default => [...self::DASHBOARD, ManageCutpoints::class, ListKpis::class, ManageWorkJournals::class, ScoringRulesGuide::class],
        };

        $scenarios = [];

        foreach ($pages as $page) {
            $scenarios[class_basename($page)] = function () use ($page): Testable {
                $component = Livewire::test($page);

                return in_array($page, self::DEFERRED, true) ? $component->call('loadTable') : $component;
            };
        }

        if ($actorKey === 'staff') {
            return $scenarios;
        }

        // Form & modal yang memuat baris berulang (detail KPI) atau opsi dari master.
        $ownKpi = $this->actorKpis[$actorKey];
        $scenarios += [
            'EditKpi (milik sendiri)' => fn (): Testable => Livewire::test(EditKpi::class, ['record' => $ownKpi->getRouteKey()]),
            'EditKpi (bawahan)' => fn (): Testable => Livewire::test(EditKpi::class, ['record' => $this->staffKpi->getRouteKey()]),
            'ListKpis (modal lihat)' => fn (): Testable => Livewire::test(ListKpis::class)
                ->call('loadTable')
                ->mountAction(TestAction::make('view')->table($ownKpi)),
            'CreateKpi' => fn (): Testable => Livewire::test(CreateKpi::class),
            'CreateAttendance' => fn (): Testable => Livewire::test(CreateAttendance::class),
            'EditAttendance' => fn (): Testable => Livewire::test(EditAttendance::class, ['record' => $this->staffAttendance->getRouteKey()]),
            'CreateEmployeeReview' => fn (): Testable => Livewire::test(CreateEmployeeReview::class),
            'EditEmployeeReview' => fn (): Testable => Livewire::test(EditEmployeeReview::class, ['record' => $this->staffReview->getRouteKey()]),
        ];

        if ($actorKey === 'manager') {
            return $scenarios;
        }

        $scenarios += [
            'CreateUser' => fn (): Testable => Livewire::test(CreateUser::class),
            'EditUser' => fn (): Testable => Livewire::test(EditUser::class, ['record' => $this->staff->getRouteKey()]),
        ];

        if ($actorKey === 'scoped') {
            return $scenarios;
        }

        return $scenarios + [
            'CreateScoringRule' => fn (): Testable => Livewire::test(CreateScoringRule::class),
            'EditScoringRule' => fn (): Testable => Livewire::test(EditScoringRule::class, ['record' => $this->latestRule->getRouteKey()]),
            'ViewScoringRule' => fn (): Testable => Livewire::test(ViewScoringRule::class, ['record' => $this->latestRule->getRouteKey()]),
        ];
    }

    /**
     * @return array<string, array<string, int>> skenario => [SQL ternormalisasi => jumlah]
     */
    private function measureAll(): array
    {
        $results = [];

        foreach ($this->actors as $actorKey => $actor) {
            foreach ($this->scenarios($actorKey) as $name => $scenario) {
                $results["{$name} @{$actorKey}"] = $this->measure($actor, $scenario, "{$name} @{$actorKey}");
            }
        }

        return $results;
    }

    /**
     * Render skenario sebagai $actor dengan memo & cache kosong, render ulang sekali, lalu hitung query per
     * bentuk SQL.
     *
     * @param  Closure(): Testable  $scenario
     * @return array<string, int>
     */
    private function measure(User $actor, Closure $scenario, ?string $name = null, bool $rerender = true): array
    {
        $this->resetAccessMemos();
        KpiCacheService::clearKpiCache();
        Cache::flush();

        // Model segar: relasi yang termuat di pengukuran sebelumnya tidak ikut mengurangi query.
        $actor = User::query()->findOrFail($actor->id);
        $this->actingAs($actor);
        Livewire::actingAs($actor);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $component = $scenario();
        // Request Livewire berikutnya memuat ulang record tanpa relasinya (form edit) atau baris tabelnya:
        // render ulang ikut diukur.
        if ($rerender) {
            $component->call('$refresh');
        }
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // Skenario yang ditolak (403) bukan pengukuran yang sah.
        $component->assertSuccessful();

        if ($name !== null) {
            $instance = $component->instance();
            $this->rowCounts[$name][] = method_exists($instance, 'getTableRecords') ? $instance->getTableRecords()->count() : null;
        }

        $counts = [];

        foreach ($log as $entry) {
            // Daftar id dan angka berbeda di tiap pengukuran; yang dibandingkan bentuk query-nya.
            $sql = preg_replace('/in \((\?(, )?)+\)/', 'in (?)', (string) $entry['query']);
            $sql = preg_replace('/\d+/', 'N', (string) $sql);
            $sql = (string) preg_replace('/in \((N(, )?)+\)/', 'in (N)', (string) $sql);
            $counts[$sql] = ($counts[$sql] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function countMatching(array $counts, string $needle): int
    {
        return array_sum(array_filter(
            $counts,
            static fn (string $sql): bool => str_contains($sql, $needle),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    private function countQueries(Closure $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * Satu unit: karyawan baru (bawahan manager di ULS), karyawan yang sudah dihapus, akun ADMIN lain, baris tiap
     * modul untuk masing-masing, baris periode lampau milik staf, detail KPI baru untuk tiap aktor, dan satu
     * baris baru di setiap master.
     */
    private function seedUnits(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $k = ++$this->seeded;
            $today = now();
            $thisMonth = $today->copy()->startOfMonth()->addDays(2);
            $pastMonth = $today->copy()->startOfMonth()->subMonthsNoOverflow($k)->addDays(2);

            $area = Area::query()->create(['name' => "AREA {$k}"]);
            $position = Position::query()->create(['name' => "POSISI {$k}"]);
            $role = Role::query()->create(['name' => "ROLE {$k}", 'level' => 5, 'requires_approval' => true]);
            $staff = $this->fixtureUser("staff_{$k}", 'STAFF', $this->uls, $this->actors['manager'], [
                'position_id' => $position->id,
                'employee_id' => "EMP{$k}",
                'email' => "staff{$k}@example.test",
            ]);
            $gone = $this->fixtureUser("gone_{$k}", 'STAFF', $this->uls, $this->actors['manager'], ['position_id' => $position->id]);
            $peer = $this->fixtureScopedAdmin("peer_admin_{$k}", $this->uls, [$this->uls]);
            $divisi = Divisi::query()->create(['name' => "DIVISI {$k}", 'area_id' => $area->id, 'manager_id' => $staff->id]);
            $category = KpiCategory::query()->create(['name' => "KATEGORI {$k}"]);
            $description = KpiDescription::query()->create([
                'kpi_category_id' => $category->id,
                'description' => "Deskripsi {$k}",
                'is_negative' => false,
            ]);

            ApprovalRule::query()->create([
                'name' => "Aturan {$k}",
                'area_id' => $area->id,
                'divisi_id' => $divisi->id,
                'position_id' => $position->id,
                'role_id' => $role->id,
                'approver_id' => $staff->id,
                'priority' => $k,
                'is_active' => true,
            ]);
            KpiReminderSetting::query()->create([
                'title' => "Pengingat {$k}",
                'type' => 'pengisian_kpi',
                'deadline_day' => 20,
                'is_active' => true,
                'send_email' => true,
                'send_whatsapp' => false,
            ]);

            // Komponen "bebas" tidak dipakai aturan mana pun, sehingga tombol hapusnya bergantung pada pemindaian
            // data kehadiran; komponen lainnya dipakai versi aturan unit ini dan unit berikutnya.
            foreach (["komponen_{$k}" => 100 + $k, "bebas_{$k}" => 1 + $k] as $code => $sortOrder) {
                AttendanceComponent::query()->create([
                    'code' => $code,
                    'name' => ucfirst(str_replace('_', ' ', $code)),
                    'unit' => AttendanceComponent::UNIT_DAY,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                ]);
            }

            ScoringRuleService::clearCache();

            $this->latestRule = ScoringRule::query()->create([
                'name' => "Aturan Nilai {$k}",
                'effective_from' => $today->copy()->startOfMonth()->addMonthsNoOverflow($k)->format('Y-m'),
                'kpi_weight' => 60,
                'attendance_weight' => 20,
                'review_weight' => 20,
                'review_max_points' => 20,
                'attendance_rules' => [
                    ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                    ...array_map(
                        static fn (int $n): array => ['component' => "komponen_{$n}", 'reduces_base' => false, 'penalty_points' => 1],
                        range(1, $k),
                    ),
                ],
                'created_by' => $this->actors['super']->id,
            ]);

            ScoringRuleService::clearCache();

            foreach ([[$staff, $thisMonth], [$gone, $thisMonth], [$peer, $thisMonth], [$this->actors['staff'], $pastMonth]] as [$owner, $date]) {
                $kpi = Kpi::query()->create([
                    'user_id' => $owner->id,
                    'kpi_category_id' => 3,
                    'kpi_type_id' => 3,
                    'date' => $date->toDateString(),
                    'percentage' => 100,
                ]);
                $this->addKpiDetails($kpi, $description);

                $periode = $date->format('Y-m');
                $attendance = Attendance::query()->create([
                    'user_id' => $owner->id,
                    'periode' => $periode,
                    'work_days' => 22,
                    'late_less_30' => 1,
                    'late_more_30' => 0,
                    'sick_days' => 0,
                ]);
                $review = EmployeeReview::query()->create([
                    'user_id' => $owner->id,
                    'periode' => $periode,
                    'responsiveness' => 4,
                    'problem_solver' => 4,
                    'helpfulness' => 4,
                    'initiative' => 4,
                ]);
                Cutpoint::query()->create([
                    'user_id' => $owner->id,
                    'periode' => $periode,
                    'point' => 1,
                    'keterangan' => "Potongan {$k}",
                ]);
                WorkJournal::query()->create([
                    'user_id' => $owner->id,
                    'date' => $owner->is($this->actors['staff']) ? $today->copy()->subDays($k)->toDateString() : $today->toDateString(),
                    'activity' => "Aktivitas {$k}",
                ]);

                if ($owner->is($staff)) {
                    $this->staff = $staff;
                    $this->staffKpi = $kpi;
                    $this->staffAttendance = $attendance;
                    $this->staffReview = $review;
                }
            }

            // Checklist dashboard dan form edit KPI: detail baru (dengan sub-detail) di KPI bulan berjalan tiap aktor.
            foreach ($this->actorKpis as $kpi) {
                $this->addKpiDetails($kpi, $description);
            }

            $gone->delete();
        }

        $this->resetAccessMemos();
    }

    private function addKpiDetails(Kpi $kpi, KpiDescription $description): void
    {
        $detail = $kpi->kpi_detail()->create([
            'kpi_description_id' => $description->id,
            'count_type' => 'RESULT',
            'value_plan' => 10,
            'subtasks' => [['description' => 'Sub 1'], ['description' => 'Sub 2']],
        ]);

        $kpi->kpi_detail()->create([
            'parent_id' => $detail->id,
            'kpi_description_id' => $description->id,
            'count_type' => 'NON',
            'is_extra_task' => 1,
        ]);
    }
}
