<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\ApprovalScopeService;
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

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->role?->name === 'ADMIN') {
            return true;
        }

        return ! empty(ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id))
            || in_array($user->role?->name, ['MANAGER', 'COORDINATOR', 'TEAM LEADER', 'CHIEF', 'BOD'], true);
    }

    public function getUserListUrl(): string
    {
        return UserResource::getUrl('index');
    }
}
