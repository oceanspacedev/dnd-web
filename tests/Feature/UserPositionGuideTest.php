<?php

namespace Tests\Feature;

use App\Filament\Pages\UserPositionGuide;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class UserPositionGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_users_has_panduan_ubah_posisi_header_action(): void
    {
        $page = resolve(ListUsers::class);
        $method = new ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke($page);

        $guideAction = collect($actions)->first(fn ($action) => $action instanceof Action && $action->getName() === 'panduan_ubah_posisi');

        $this->assertNotNull($guideAction, 'panduan_ubah_posisi action should exist in header actions.');
        $this->assertSame('Panduan Ubah Posisi', $guideAction->getLabel());
        $this->assertTrue($guideAction->isModalSlideOver());
        $this->assertSame('Panduan Merubah Posisi Karyawan Secara Massal', $guideAction->getModalHeading());
    }

    public function test_user_position_guide_sidebar_page_is_configured_properly(): void
    {
        $page = new UserPositionGuide;
        $this->assertSame('Panduan Ubah Posisi', UserPositionGuide::getNavigationLabel());
        $this->assertSame('Panduan', UserPositionGuide::getNavigationGroup());
        $this->assertSame('panduan-ubah-posisi', UserPositionGuide::getSlug());
        $this->assertSame('Panduan Ubah Posisi Massal', $page->getTitle());
    }

    public function test_user_position_guide_page_renders_successfully(): void
    {
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $admin = User::query()->create([
            'nama_lengkap' => 'Admin Test',
            'username' => 'adm_'.uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'area_id' => 1,
            'divisi_id' => 1,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $this->actingAs($admin);

        Livewire::test(UserPositionGuide::class)
            ->assertSuccessful()
            ->assertSee('Panduan Ubah Posisi Karyawan')
            ->assertSee('Cara Mengubah Posisi Banyak Karyawan Sekaligus');
    }
}
