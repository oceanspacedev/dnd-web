<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use App\Models\Attendance;
use App\Services\ApprovalScopeService;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    protected function beforeCreate(): void
    {
        // Get the form data
        $data = $this->form->getState();

        if (! AttendanceResource::canAccessAllAttendance(auth()->user())) {
            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            $isValidSubordinate = in_array((int) $data['user_id'], $managedUserIds, true);

            if (! $isValidSubordinate) {
                Notification::make()
                    ->title('User tidak valid')
                    ->body('Anda hanya bisa membuat data kehadiran untuk bawahan dalam scope approval_id (bawahan langsung + satu level).')
                    ->danger()
                    ->send();

                $this->halt();
                return;
            }
        }

        // Check if a record with the same user_id and periode already exists
        $exists = Attendance::where('user_id', $data['user_id'])
            ->where('periode', $data['periode'])
            ->exists();

        if ($exists) {
            // If duplicate found, show error notification
            Notification::make()
                ->title('Data sudah ada')
                ->body('User ini sudah memiliki data kehadiran untuk periode yang sama.')
                ->danger()
                ->send();

            // Halt the creation process
            $this->halt();
        }
    }

    // Optionally, you can customize the redirect after creation
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
