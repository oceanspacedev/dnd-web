<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAttendance extends EditRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function beforeSave(): void
    {
        if (AttendanceResource::canAccessAllAttendance(auth()->user())) {
            return;
        }

        // Validasi ulang target di server: jangan percaya state Livewire.
        $data = $this->form->getState();

        if (! AttendancePolicy::canManageUser(auth()->user(), $data['user_id'] ?? null)) {
            Notification::make()
                ->title('User tidak valid')
                ->body(DataAccessService::isAreaScopedAdmin(auth()->user())
                    ? 'Anda hanya bisa mengubah data kehadiran untuk karyawan di badan usaha yang diizinkan atau tim approval Anda, selain akun ADMIN (termasuk diri sendiri).'
                    : 'Anda hanya bisa mengubah data kehadiran untuk bawahan dalam scope approval_id (bawahan langsung + satu level).')
                ->danger()
                ->send();

            $this->halt();

            return;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
