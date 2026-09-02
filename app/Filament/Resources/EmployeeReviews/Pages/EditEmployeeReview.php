<?php

namespace App\Filament\Resources\EmployeeReviews\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\EmployeeReviews\EmployeeReviewResource;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeReview extends EditRecord
{
    protected static string $resource = EmployeeReviewResource::class;

    protected function beforeSave(): void
    {
        if (auth()->user()->role?->name === 'ADMIN') {
            return;
        }

        $data = $this->form->getState();
        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
        $isValidSubordinate = in_array((int) $data['user_id'], $managedUserIds, true);

        if (! $isValidSubordinate) {
            Notification::make()
                ->title('User tidak valid')
                ->body('Anda hanya bisa mengubah penilaian untuk bawahan dalam scope approval_id (bawahan langsung + satu level).')
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
