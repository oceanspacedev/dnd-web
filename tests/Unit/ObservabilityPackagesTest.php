<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ObservabilityPackagesTest extends TestCase
{
    public function test_composer_requires_horizon_and_log_viewer(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertArrayHasKey('laravel/horizon', $composer['require']);
        $this->assertArrayHasKey('opcodesio/log-viewer', $composer['require']);
    }

    public function test_providers_and_support_classes_are_wired(): void
    {
        $root = dirname(__DIR__, 2);
        $providers = (string) file_get_contents($root.'/bootstrap/providers.php');

        $this->assertFileExists($root.'/app/Providers/HorizonServiceProvider.php');
        $this->assertFileExists($root.'/app/Support/ObservabilityAccess.php');
        $this->assertFileExists($root.'/config/horizon.php');
        $this->assertFileExists($root.'/config/log-viewer.php');
        $this->assertStringContainsString('HorizonServiceProvider::class', $providers);
        $this->assertStringContainsString('PanelsRenderHook::SIDEBAR_FOOTER', (string) file_get_contents($root.'/app/Providers/Filament/AdminPanelProvider.php'));
        $this->assertFileExists($root.'/resources/views/filament/partials/sidebar-observability.blade.php');
        $this->assertFileExists($root.'/resources/views/vendor/mekaya/livewire/partials/mekaya-sidebar-footer.blade.php');
        $this->assertStringContainsString('horizon:snapshot', (string) file_get_contents($root.'/routes/console.php'));
        $horizon = (string) file_get_contents($root.'/config/horizon.php');
        $this->assertStringContainsString("'queue' => ['notifications', 'default', 'exports']", $horizon);
        $this->assertStringNotContainsString('supervisor-notifications', $horizon);
        $this->assertStringNotContainsString('supervisor-exports', $horizon);
        $this->assertStringContainsString('exec php artisan horizon --no-interaction', (string) file_get_contents($root.'/docker/entrypoint.sh'));
        $this->assertStringNotContainsString('queue:work', (string) file_get_contents($root.'/docker/entrypoint.sh'));
    }
}
