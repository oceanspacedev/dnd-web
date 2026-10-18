<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('LOG_VIEWER_ENABLED=true');
        $_ENV['LOG_VIEWER_ENABLED'] = 'true';
        $_SERVER['LOG_VIEWER_ENABLED'] = 'true';

        parent::setUp();

        config([
            'app.url' => 'http://localhost',
            'log-viewer.api_stateful_domains' => ['localhost'],
            'sanctum.stateful' => ['localhost'],
        ]);
    }

    public function test_log_viewer_api_starts_the_web_session_without_stateful_domains(): void
    {
        $middleware = app('router')->getRoutes()->getByName('log-viewer.folders')->gatherMiddleware();

        $this->assertContains('web', $middleware);

        $admin = $this->userWithRole('ADMIN', 'admin-log-viewer');

        $this->withSession([
            auth('web')->getName() => $admin->getAuthIdentifier(),
        ])->withHeader('Referer', 'https://dnd.example.test/log-viewer')
            ->get('https://dnd.example.test/log-viewer/api/folders')
            ->assertOk();
    }

    public function test_non_admin_session_cannot_call_log_viewer_api(): void
    {
        $staff = $this->userWithRole('STAFF', 'staff-log-viewer');

        $this->withSession([
            auth('web')->getName() => $staff->getAuthIdentifier(),
        ])->withHeader('Referer', 'https://dnd.example.test/log-viewer')
            ->get('https://dnd.example.test/log-viewer/api/folders')
            ->assertForbidden();
    }

    public function test_guest_cannot_call_log_viewer_api(): void
    {
        $this->withHeader('Referer', 'https://dnd.example.test/log-viewer')
            ->get('https://dnd.example.test/log-viewer/api/folders')
            ->assertForbidden();
    }

    private function userWithRole(string $roleName, string $username): User
    {
        $role = Role::query()->create(['name' => $roleName]);

        return User::query()->create([
            'nama_lengkap' => $roleName.' Log Viewer',
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
