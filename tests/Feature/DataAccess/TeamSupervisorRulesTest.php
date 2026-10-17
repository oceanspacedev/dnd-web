<?php

namespace Tests\Feature\DataAccess;

use App\Models\Area;
use App\Models\Attendance;
use App\Models\Daily;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\Monthly;
use App\Models\Overopen;
use App\Models\Weekly;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Semua role atasan (TEAM LEADER, COORDINATOR, MANAGER, CHIEF, BOD) mengelola data anggota timnya
 * di setiap modul, dengan aturan yang sama seperti menu Tim Saya.
 */
class TeamSupervisorRulesTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    /** @var array<class-string<Model>> */
    private const TEAM_MODELS = [
        Kpi::class,
        Attendance::class,
        EmployeeReview::class,
        Daily::class,
        Weekly::class,
        Monthly::class,
        Overopen::class,
    ];

    private Area $coo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();
        $this->coo = $this->fixtureArea('COO');
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supervisorRoles(): array
    {
        return array_combine(
            DataAccessService::SUPERVISOR_ROLES,
            array_map(static fn (string $role): array => [$role], DataAccessService::SUPERVISOR_ROLES),
        );
    }

    #[DataProvider('supervisorRoles')]
    public function test_every_supervisor_role_manages_its_team_in_every_module(string $role): void
    {
        $supervisor = $this->fixtureUser('supervisor', $role, $this->coo);
        $member = $this->fixtureUser('member', 'STAFF', $this->coo, $supervisor);
        $secondLevel = $this->fixtureUser('second_level', 'STAFF', $this->coo, $member);
        $outsider = $this->fixtureUser('outsider', 'STAFF', $this->coo);
        $this->resetAccessMemos();

        $gate = Gate::forUser($supervisor);

        $this->assertTrue(DataAccessService::isTeamSupervisor($supervisor));
        $this->assertTrue($gate->allows('viewAny', Attendance::class), "{$role} viewAny Kehadiran");
        $this->assertTrue($gate->allows('viewAny', EmployeeReview::class), "{$role} viewAny Penilaian");
        $this->assertTrue($gate->allows('create', Kpi::class), "{$role} create KPI");
        $this->assertTrue($gate->allows('create', Attendance::class), "{$role} create Kehadiran");
        $this->assertTrue($gate->allows('create', EmployeeReview::class), "{$role} create Penilaian");
        $this->assertTrue($gate->allows('update', $member), "{$role} edit anggota di Tim Saya");

        foreach (self::TEAM_MODELS as $modelClass) {
            $name = class_basename($modelClass);

            foreach ([$member, $secondLevel] as $teamMember) {
                $record = new $modelClass(['user_id' => $teamMember->id]);
                $this->assertTrue($gate->allows('view', $record), "{$role} view {$name} {$teamMember->username}");
                $this->assertTrue($gate->allows('update', $record), "{$role} update {$name} {$teamMember->username}");
                $this->assertTrue($gate->allows('delete', $record), "{$role} delete {$name} {$teamMember->username}");
            }

            $outside = new $modelClass(['user_id' => $outsider->id]);
            $this->assertFalse($gate->allows('view', $outside), "{$role} view {$name} di luar tim");
            $this->assertFalse($gate->allows('update', $outside), "{$role} update {$name} di luar tim");
        }
    }

    public function test_supervisor_without_team_cannot_reach_other_employees(): void
    {
        $leader = $this->fixtureUser('lonely_leader', 'TEAM LEADER', $this->coo);
        $someone = $this->fixtureUser('someone', 'STAFF', $this->coo);
        $this->resetAccessMemos();

        $gate = Gate::forUser($leader);

        // Menu tetap tampil (role atasan), tetapi tanpa anggota tim tidak ada data orang lain.
        $this->assertTrue($gate->allows('viewAny', Attendance::class));
        $this->assertFalse($gate->allows('create', Kpi::class));
        $this->assertFalse($gate->allows('create', Attendance::class));

        foreach (self::TEAM_MODELS as $modelClass) {
            $this->assertFalse($gate->allows('update', new $modelClass(['user_id' => $someone->id])), class_basename($modelClass));
        }
    }

    public function test_self_or_mutual_approval_line_is_not_part_of_own_team(): void
    {
        // Seperti satu MANAGER di produksi yang approval line-nya dirinya sendiri.
        $manager = $this->fixtureUser('self_manager', 'MANAGER', $this->coo);
        $manager->update(['approval_id' => $manager->id]);
        $member = $this->fixtureUser('member', 'STAFF', $this->coo, $manager);

        $staffA = $this->fixtureUser('staff_a', 'STAFF', $this->coo);
        $staffB = $this->fixtureUser('staff_b', 'STAFF', $this->coo, $staffA);
        $staffA->update(['approval_id' => $staffB->id]);
        $this->resetAccessMemos();

        $this->assertSame([$member->id], ApprovalScopeService::getManagedUserIdsOneLevelDown($manager->id));
        $this->assertSame([$staffB->id], ApprovalScopeService::getManagedUserIdsOneLevelDown($staffA->id));

        $managerGate = Gate::forUser($manager);
        $this->assertTrue($managerGate->allows('update', new EmployeeReview(['user_id' => $member->id])));
        $this->assertFalse($managerGate->allows('update', new EmployeeReview(['user_id' => $manager->id])), 'menilai diri sendiri');
        $this->assertFalse($managerGate->allows('update', new Attendance(['user_id' => $manager->id])), 'kehadiran sendiri');

        // Hanya menyetujui diri sendiri tidak menjadikan seseorang atasan.
        $loner = $this->fixtureUser('loner', 'STAFF', $this->coo);
        $loner->update(['approval_id' => $loner->id]);
        $this->resetAccessMemos();
        $this->assertFalse(DataAccessService::isTeamSupervisor($loner));
        $this->assertFalse(Gate::forUser($loner)->allows('create', EmployeeReview::class));
    }

    public function test_staff_without_team_keeps_no_team_access(): void
    {
        $staff = $this->fixtureUser('staff', 'STAFF', $this->coo);
        $peer = $this->fixtureUser('peer', 'STAFF', $this->coo);
        $this->resetAccessMemos();

        $gate = Gate::forUser($staff);

        $this->assertFalse(DataAccessService::isTeamSupervisor($staff));
        $this->assertFalse($gate->allows('viewAny', Attendance::class));
        $this->assertFalse($gate->allows('viewAny', EmployeeReview::class));
        $this->assertFalse($gate->allows('create', Kpi::class));
        $this->assertFalse($gate->allows('update', $peer));

        foreach (self::TEAM_MODELS as $modelClass) {
            $this->assertFalse($gate->allows('update', new $modelClass(['user_id' => $peer->id])), class_basename($modelClass));
        }

        // Data miliknya sendiri tetap mengikuti aturan lama per modul (mis. KPI sendiri).
        $this->assertTrue($gate->allows('update', new Kpi(['user_id' => $staff->id])));
        $this->assertFalse($gate->allows('update', new Attendance(['user_id' => $staff->id])));
    }
}
