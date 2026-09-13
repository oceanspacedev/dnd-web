<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEloquentGuardableColumns();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        ApprovalScopeService::clearMemo();

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_lengkap');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('no_hp')->nullable()->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        ApprovalScopeService::clearMemo();
        $this->clearEloquentGuardableColumns();

        parent::tearDown();
    }

    public function test_a_regular_api_user_cannot_update_another_account(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $actor = $this->createUser('actor', $staffRole);
        $target = $this->createUser('target', $staffRole);
        Sanctum::actingAs($actor);

        $this->putJson("/api/v1/users/{$target->id}", [
            'password' => 'attacker-password',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('password', $target->fresh()->password));
    }

    public function test_a_manager_cannot_promote_a_managed_user_to_admin(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $manager = $this->createUser('manager', $managerRole);
        $target = $this->createUser('managed-user', $staffRole, $manager);
        Sanctum::actingAs($manager);

        $this->putJson("/api/v1/users/{$target->id}", [
            'role_id' => $adminRole->id,
        ])->assertForbidden();

        $this->assertSame($staffRole->id, $target->fresh()->role_id);
    }

    public function test_an_admin_can_still_update_a_user(): void
    {
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $admin = $this->createUser('admin', $adminRole);
        $target = $this->createUser('target', $staffRole);
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/users/{$target->id}", [
            'password' => 'new-secure-password',
            'no_hp' => '+62 812-3456-7890',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure-password', $target->fresh()->password));
        $this->assertSame('081234567890', $target->fresh()->no_hp);
    }

    public function test_a_manager_cannot_rebind_a_managed_users_no_hp(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $manager = $this->createUser('manager-phone', $managerRole);
        $target = $this->createUser('managed-phone', $staffRole, $manager);
        $target->update(['no_hp' => '081211111111']);
        Sanctum::actingAs($manager);

        foreach (['081299999999', null, ''] as $attemptedNumber) {
            $this->putJson("/api/v1/users/{$target->id}", [
                'no_hp' => $attemptedNumber,
            ])->assertForbidden();
        }

        $this->postJson('/api/v1/users', ['no_hp' => null])->assertForbidden();

        $this->assertSame('081211111111', $target->fresh()->no_hp);
    }

    public function test_profile_endpoint_cannot_move_the_whatsapp_login_number(): void
    {
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $user = $this->createUser('profile-phone', $staffRole);
        $user->update(['no_hp' => '081211111111']);
        Sanctum::actingAs($user);

        foreach (['081299999999', null, ''] as $attemptedNumber) {
            $this->putJson('/api/v1/auth/profile', [
                'no_hp' => $attemptedNumber,
            ])->assertForbidden();
        }

        $this->assertSame('081211111111', $user->fresh()->no_hp);
    }

    public function test_a_manager_only_sees_users_inside_their_approval_scope(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $manager = $this->createUser('manager', $managerRole);
        $direct = $this->createUser('direct-report', $staffRole, $manager);
        $secondLevel = $this->createUser('second-level', $staffRole, $direct);
        $this->createUser('outside-scope', $staffRole);
        $this->createUser('admin-report', $adminRole, $manager);
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/users')->assertOk();
        $visibleIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$direct->id, $secondLevel->id],
            $visibleIds,
        );
    }

    public function test_nested_subordinates_do_not_escape_the_managers_scope(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $manager = $this->createUser('manager', $managerRole);
        $direct = $this->createUser('direct-report', $staffRole, $manager);
        $secondLevel = $this->createUser('second-level', $staffRole, $direct);
        $this->createUser('third-level', $staffRole, $secondLevel);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/users/{$secondLevel->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.subordinates');
    }

    public function test_supervisor_options_are_scoped_for_a_manager(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $staffRole = Role::query()->create(['name' => 'STAFF']);
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $manager = $this->createUser('manager', $managerRole);
        $direct = $this->createUser('direct-report', $staffRole, $manager);
        $this->createUser('outside-scope', $staffRole);
        $this->createUser('admin-report', $adminRole, $manager);
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/users/supervisors')->assertOk();
        $visibleIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$manager->id, $direct->id], $visibleIds);
    }

    private function createUser(string $username, Role $role, ?User $approval = null): User
    {
        return User::query()->create([
            'nama_lengkap' => strtoupper($username),
            'username' => $username,
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'approval_id' => $approval?->id,
        ]);
    }

    private function clearEloquentGuardableColumns(): void
    {
        $property = new \ReflectionProperty(Model::class, 'guardableColumns');
        $property->setValue(null, []);
    }
}
