<?php

namespace Tests\Feature\DataAccess;

use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Widgets\ChecklistKPI;
use App\Models\Area;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Aturan non-admin yang sengaja dipertahankan seperti sebelum fitur akses data badan usaha.
 */
class NonAdminBehaviourTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

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

    public function test_kpi_user_pickers_are_hidden_only_for_staff(): void
    {
        $leaderWithoutTeam = $this->fixtureUser('lonely_lead', 'TEAM LEADER', $this->coo);
        $staffWithTeam = $this->fixtureUser('staff_lead', 'STAFF', $this->coo);
        $this->fixtureUser('staff_member', 'STAFF', $this->coo, $staffWithTeam);
        $scopedAdmin = $this->fixtureScopedAdmin('ojan', $this->coo, [$this->coo]);

        foreach ([[$leaderWithoutTeam, true], [$staffWithTeam, false], [$scopedAdmin, true]] as [$actor, $expected]) {
            $this->actingAs($actor);
            $this->resetAccessMemos();

            $this->assertSame($expected, (new ChecklistKPI)->canChooseUser(), $actor->username);
            $this->assertSame($expected, (new ReflectionMethod(ListKpis::class, 'canChooseOtherUsers'))->invoke(null), $actor->username);
        }
    }

    public function test_tim_saya_hides_admin_accounts_except_for_super_admin(): void
    {
        $manager = $this->fixtureUser('manager', 'MANAGER', $this->coo);
        $member = $this->fixtureUser('member', 'STAFF', $this->coo, $manager);
        $adminMember = $this->fixtureUser('admin_member', 'ADMIN', $this->coo, $manager);
        $superAdmin = $this->fixtureGlobalAdmin('boss', $this->coo);
        $superMember = $this->fixtureUser('boss_member', 'STAFF', $this->coo, $superAdmin);
        $superAdminMember = $this->fixtureUser('boss_admin_member', 'ADMIN', $this->coo, $superAdmin);

        $this->actingAs($manager);
        $this->resetAccessMemos();
        $this->assertSame([$member->id], $this->teamIds());

        $this->actingAs($superAdmin);
        $this->resetAccessMemos();
        $this->assertEqualsCanonicalizing([$superMember->id, $superAdminMember->id], $this->teamIds());
        $this->assertNotContains($adminMember->id, $this->teamIds());
    }

    /**
     * @return array<int>
     */
    private function teamIds(): array
    {
        return TeamMemberResource::getEloquentQuery()
            ->pluck('users.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
