<?php

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use Filament\Actions\Action;

class ListTeamMembers extends ListUsers
{
    protected static string $resource = TeamMemberResource::class;

    /**
     * Tim Saya tidak menampilkan tombol Panduan Ubah Posisi; panduannya tetap ada di menu Panduan.
     */
    protected function getHeaderActions(): array
    {
        return array_values(array_filter(
            parent::getHeaderActions(),
            fn (mixed $action): bool => ! ($action instanceof Action && $action->getName() === 'panduan_ubah_posisi'),
        ));
    }
}
