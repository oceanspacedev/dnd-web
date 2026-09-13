<?php

namespace Tests\Feature;

use App\Filament\Resources\Kpis\Pages\ListKpis;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class KpiHeaderActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_and_copy_actions_use_slideover_panels(): void
    {
        $page = resolve(ListKpis::class);
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke($page);

        $actionGroup = collect($actions)->first(fn (mixed $action): bool => $action instanceof ActionGroup);

        $this->assertInstanceOf(ActionGroup::class, $actionGroup);
        $this->assertTrue($actionGroup->getFlatActions()['export']->isModalSlideOver());
        $this->assertTrue($actionGroup->getFlatActions()['copy']->isModalSlideOver());
    }

    public function test_copy_action_allows_only_one_source_user(): void
    {
        $resource = file_get_contents(app_path('Filament/Resources/Kpis/Pages/ListKpis.php'));
        $sourceField = str($resource)
            ->after("Select::make('source_users')")
            ->before("Select::make('target_users')");

        $this->assertStringNotContainsString('->multiple()', $sourceField);
    }
}
