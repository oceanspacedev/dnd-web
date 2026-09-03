<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = !empty($data['role_id']) ? \App\Models\Role::find($data['role_id']) : null;
        if ($role && ! $role->requires_approval) {
            $data['approval_id'] = null;
        } else {
            $data['approval_id'] = $data['approval_id'] ?? Auth::id();
        }

        $data['dr'] = $data['dr'] ?? false;
        $data['wn'] = $data['wn'] ?? false;
        $data['wr'] = $data['wr'] ?? false;
        $data['mn'] = $data['mn'] ?? false;
        $data['mr'] = $data['mr'] ?? false;

        return $data;
    }
}
