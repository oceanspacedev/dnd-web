<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use Filament\Actions\Action;
use ReflectionMethod;
use Tests\TestCase;

class UserPositionGuideTest extends TestCase
{
    public function test_list_users_has_panduan_ubah_posisi_header_action(): void
    {
        $page = app(ListUsers::class);
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
        $page = new \App\Filament\Pages\UserPositionGuide();
        $this->assertSame('Panduan Ubah Posisi', \App\Filament\Pages\UserPositionGuide::getNavigationLabel());
        $this->assertSame('Panduan', \App\Filament\Pages\UserPositionGuide::getNavigationGroup());
        $this->assertSame('panduan-ubah-posisi', \App\Filament\Pages\UserPositionGuide::getSlug());
        $this->assertSame('Panduan Ubah Posisi Massal', $page->getTitle());
    }

    public function test_user_position_guide_page_renders_successfully(): void
    {
        $admin = \App\Models\User::first() ?? \App\Models\User::create([
            'nama_lengkap' => 'Admin Test',
            'username' => 'adm_' . uniqid(),
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Pages\UserPositionGuide::class)
            ->assertSuccessful()
            ->assertSee('Panduan Ubah Posisi Karyawan')
            ->assertSee('Cara Mengubah Posisi Banyak Karyawan Sekaligus');
    }
}
