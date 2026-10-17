<?php

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\EditUser;

class EditTeamMember extends EditUser
{
    protected static string $resource = TeamMemberResource::class;
}
