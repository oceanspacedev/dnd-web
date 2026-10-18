<?php

namespace App\Filament\Resources\TeamMembers\Pages;

use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\Pages\CreateUser;

class CreateTeamMember extends CreateUser
{
    protected static string $resource = TeamMemberResource::class;
}
