<?php

namespace Tests\Feature;

use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Models\User;
use Filament\Actions\CreateAction;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class CutpointActionsTest extends TestCase
{
    public function test_create_action_uses_a_slideover(): void
    {
        $page = app(ManageCutpoints::class);
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke($page);

        $createAction = collect($actions)->first(fn (mixed $action): bool => $action instanceof CreateAction);

        $this->assertInstanceOf(CreateAction::class, $createAction);
        $this->assertTrue($createAction->isModalSlideOver());
    }

    public function test_create_action_can_be_mounted(): void
    {
        $admin = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'ADMIN'))
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageCutpoints::class)
            ->mountAction('create')
            ->assertActionMounted('create');
    }

}
