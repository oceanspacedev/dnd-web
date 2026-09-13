<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Services\ApprovalScopeService;
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

        $data = $this->form->getState();
        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
        $isValidSubordinate = in_array((int) $data['user_id'], $managedUserIds, true);

        if (! $isValidSubordinate) {
            Notification::make()
                ->title('User tidak valid')
                ->body('Anda hanya bisa mengubah data kehadiran untuk bawahan dalam scope approval_id (bawahan langsung + satu level).')
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
