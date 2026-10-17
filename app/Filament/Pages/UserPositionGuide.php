<?php

namespace App\Filament\Pages;

use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Pages\Page;

class UserPositionGuide extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Panduan Ubah Posisi';

    protected static string|\UnitEnum|null $navigationGroup = 'Panduan';

    protected static ?int $navigationSort = 82;

    protected static ?string $slug = 'panduan-ubah-posisi';

    protected static ?string $title = 'Panduan Ubah Posisi Massal';

    protected string $view = 'filament.pages.user-position-guide';

    /**
     * Admin dengan akses badan usaha (menu Karyawan) atau atasan dengan tim (menu Tim Saya).
     */
    public static function canAccess(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        return UserResource::canViewAny() || TeamMemberResource::canViewAny();
    }

    public function getUserListUrl(): string
    {
        return UserResource::canViewAny()
            ? UserResource::getUrl('index')
            : TeamMemberResource::getUrl('index');
    }

    public function getUserListLabel(): string
    {
        return UserResource::canViewAny()
            ? 'Buka Menu Karyawan'
            : 'Buka Menu Tim Saya';
    }
}
