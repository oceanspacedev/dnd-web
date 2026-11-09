<?php

namespace Tests\Feature;

use App\Filament\Pages\UserPositionGuide;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
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
        $this->assertSame('Panduan Ubah Posisi dan Area', $guideAction->getLabel());
        $this->assertTrue($guideAction->isModalSlideOver());
        $this->assertSame('Panduan Merubah Posisi dan Area Karyawan Secara Massal', $guideAction->getModalHeading());

        $modal = view('filament.pages.panduan-ubah-posisi')->render();
        $this->assertStringContainsString('Cara Ubah Posisi Banyak Karyawan Sekaligus', $modal);
        $this->assertStringContainsString('Cara Ubah Area Banyak Karyawan Sekaligus', $modal);
        $this->assertStringContainsString('Ubah Area Massal', $modal);
    }

    public function test_tim_saya_has_no_panduan_ubah_posisi_header_action(): void
    {
        $page = resolve(ListTeamMembers::class);
        $actions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
        $names = collect($actions)->filter(fn ($action) => $action instanceof Action)->map->getName()->all();

        $this->assertNotContains('panduan_ubah_posisi', $names);
        $this->assertContains('create', $names);
        $this->assertSame(array_keys($actions), range(0, count($actions) - 1));
    }

    public function test_user_position_guide_sidebar_page_is_configured_properly(): void
    {
        $page = new UserPositionGuide;
        $this->assertSame('Panduan Ubah Posisi dan Area', UserPositionGuide::getNavigationLabel());
        $this->assertSame('Panduan', UserPositionGuide::getNavigationGroup());
        $this->assertSame('panduan-ubah-posisi', UserPositionGuide::getSlug());
        $this->assertSame('Panduan Ubah Posisi dan Area Massal', $page->getTitle());
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
            ->assertSee('Panduan Ubah Posisi dan Area Karyawan')
            ->assertSee('Cara Mengubah Posisi Banyak Karyawan Sekaligus')
            ->assertSee('Cara Mengubah Area Banyak Karyawan Sekaligus')
            ->assertSee('Ubah Area Massal')
            ->assertSee('Divisi tidak berubah.')
            ->assertSee('Cara Mengubah Posisi atau Area Satu Karyawan');
    }

    public function test_user_position_guide_renders_every_animated_gif(): void
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

        $gifs = [
            'ubah-posisi-massal.gif',
            'ubah-posisi-tim-saya.gif',
            'ubah-posisi-perorangan.gif',
        ];

        $page = Livewire::test(UserPositionGuide::class)->assertSuccessful();

        foreach ($gifs as $gif) {
            $path = 'images/panduan-ubah-posisi/'.$gif;

            $this->assertFileExists(public_path($path));
            $page->assertSeeHtml('<img src="'.asset($path).'"');
        }

        $page->assertSeeInOrder(['GIF 1.', 'GIF 2.', 'GIF 3.']);
    }
}
