<?php

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\ListUsers;

class ListTeamMembers extends ListUsers
{
    protected static string $resource = TeamMemberResource::class;
}
