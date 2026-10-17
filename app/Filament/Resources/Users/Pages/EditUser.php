<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    // Data karyawan dan daftar badan usaha (relasi) disimpan atomik.
    protected ?bool $hasDatabaseTransactions = true;

    protected function beforeSave(): void
    {
        // Baca state mentah (sudah lolos validasi): getState() kedua akan ikut menyimpan relasi
        // (mis. daftar badan usaha akses data) sebelum pengecekan di bawah sempat membatalkan simpan.
        $rawApprovalId = data_get($this->form->getRawState(), 'approval_id');
        $record = $this->getRecord();

        if (! $record instanceof User) {
            $this->halt(shouldRollbackDatabaseTransaction: true);

            return;
        }

        $recordId = (int) $record->id;
        $approvalId = filled($rawApprovalId) && is_scalar($rawApprovalId) ? (int) $rawApprovalId : 0;

        if ($approvalId === 0) {
            return;
        }

        if ($approvalId === $recordId) {
            Notification::make()
                ->title('Approval tidak valid')
                ->body('User tidak boleh menjadi approval untuk dirinya sendiri.')
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);

            return;
        }

        if ($this->createsApprovalCycle($recordId, $approvalId)) {
            Notification::make()
                ->title('Approval tidak valid')
                ->body('Relasi approval membentuk siklus. Pilih atasan lain agar hirarki tetap valid.')
                ->danger()
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        return static::getResource()::mutateAuthorizedData($data, $record instanceof User ? $record : null);
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record instanceof User) {
            static::getResource()::normalizeDataAccess($record);
        }
    }

    private function createsApprovalCycle(int $recordId, int $approvalId): bool
    {
        return static::getResource()::createsApprovalCycle($recordId, $approvalId);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
