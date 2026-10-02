<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\ObservabilityAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ObservabilityAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_horizon_or_log_viewer(): void
    {
        $this->assertFalse(ObservabilityAccess::allowed(null));
        $this->assertSame('horizon', config('horizon.path'));
        $this->assertSame('log-viewer', config('log-viewer.route_path'));
        $this->assertTrue(Gate::has('viewLogViewer'));
        $this->assertTrue(Gate::has('viewHorizon'));
    }

    public function test_sidebar_observability_renders_for_admin(): void
    {
        $admin = $this->userWithRole('ADMIN', 'admin-obs');

        $this->actingAs($admin);

        $rendered = view('filament.partials.sidebar-observability')->render();

        $this->assertStringNotContainsString('Observability', $rendered);
        $this->assertStringContainsString('Horizon', $rendered);
        $this->assertStringContainsString('/horizon', $rendered);
        $this->assertStringContainsString('Log Viewer', $rendered);
        $this->assertStringContainsString('/log-viewer', $rendered);
    }

    public function test_sidebar_observability_hidden_for_non_admin_and_guests(): void
    {
        $staff = $this->userWithRole('STAFF', 'staff-obs');

        $this->actingAs($staff);
        $this->assertStringNotContainsString('/horizon', view('filament.partials.sidebar-observability')->render());
        $this->assertStringNotContainsString('/log-viewer', view('filament.partials.sidebar-observability')->render());

        auth()->logout();
        $this->assertStringNotContainsString('/horizon', view('filament.partials.sidebar-observability')->render());
        $this->assertStringNotContainsString('/log-viewer', view('filament.partials.sidebar-observability')->render());
        $this->assertStringNotContainsString('Observability', view('filament.partials.sidebar-observability')->render());
    }

    private function userWithRole(string $roleName, string $username): User
    {
        $role = Role::query()->create(['name' => $roleName]);

        return User::query()->create([
            'nama_lengkap' => $roleName.' User',
            'username' => $username,
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'area_id' => 1,
            'divisi_id' => 1,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);
    }
}
