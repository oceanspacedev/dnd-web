<?php

namespace Tests\Feature;

use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class CutpointActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_action_uses_a_slideover(): void
    {
        $page = resolve(ManageCutpoints::class);
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke($page);

        $createAction = collect($actions)->first(fn (mixed $action): bool => $action instanceof CreateAction);

        $this->assertInstanceOf(CreateAction::class, $createAction);
        $this->assertTrue($createAction->isModalSlideOver());
    }

    public function test_create_action_can_be_mounted(): void
    {
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $admin = User::query()->create([
            'nama_lengkap' => 'Admin Test',
            'username' => 'admin-test',
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'area_id' => 1,
            'divisi_id' => 1,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageCutpoints::class)
            ->mountAction('create')
            ->assertActionMounted('create');
    }
}
