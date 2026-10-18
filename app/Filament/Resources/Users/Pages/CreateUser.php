<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\DataAccessService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    // Data karyawan dan daftar badan usaha (relasi) disimpan atomik.
    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $role = ! empty($data['role_id']) ? Role::find($data['role_id']) : null;
        if ($role && $role->requires_approval) {
            $data['approval_id'] = $data['approval_id'] ?? Auth::id();
        } elseif (empty($data['approval_id']) && ! DataAccessService::isAdmin(Auth::user())) {
            $data['approval_id'] = Auth::id();
        }

        $data['dr'] = $data['dr'] ?? false;
        $data['wn'] = $data['wn'] ?? false;
        $data['wr'] = $data['wr'] ?? false;
        $data['mn'] = $data['mn'] ?? false;
        $data['mr'] = $data['mr'] ?? false;

        return static::getResource()::mutateAuthorizedData($data);
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof User) {
            static::getResource()::normalizeDataAccess($record);
        }
    }

    /**
     * Arahkan ke halaman edit di menu yang sedang dibuka hanya jika karyawan baru termasuk menu tersebut.
     */
    protected function getRedirectUrl(): string
    {
        $resource = static::getResource();
        $record = $this->getRecord();

        if (
            $resource::canEdit($record)
            && $resource::getEloquentQuery()->whereKey($record->getKey())->exists()
        ) {
            return $this->getResourceUrl('edit');
        }

        return $this->getResourceUrl();
    }
}
