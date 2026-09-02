<?php

namespace App\Filament\Resources\Users\Pages;

use Filament\Actions\DeleteAction;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeSave(): void
    {
        $data = $this->form->getState();
        $recordId = (int) $this->record->id;
        $approvalId = isset($data['approval_id']) ? (int) $data['approval_id'] : 0;

        if ($approvalId === 0) {
            return;
        }

        if ($approvalId === $recordId) {
            Notification::make()
                ->title('Approval tidak valid')
                ->body('User tidak boleh menjadi approval untuk dirinya sendiri.')
                ->danger()
                ->send();

            $this->halt();
            return;
        }

        if ($this->createsApprovalCycle($recordId, $approvalId)) {
            Notification::make()
                ->title('Approval tidak valid')
                ->body('Relasi approval membentuk siklus. Pilih atasan lain agar hirarki tetap valid.')
                ->danger()
                ->send();

            $this->halt();
        }
    }

    private function createsApprovalCycle(int $recordId, int $approvalId): bool
    {
        $visited = [];
        $currentId = $approvalId;

        while ($currentId !== 0) {
            if ($currentId === $recordId) {
                return true;
            }

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $currentId = (int) (User::where('id', $currentId)->value('approval_id') ?? 0);
        }

        return false;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
