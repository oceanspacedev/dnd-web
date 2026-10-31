<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use App\Support\Scoring\AttendanceCountInput;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAttendance extends EditRecord
{
    protected static string $resource = AttendanceResource::class;

    /**
     * Form hanya memuat komponen yang bisa diisi (aktif, atau nonaktif tetapi masih dinilai aturan), dan isian
     * komponen yang tidak dipakai versi aturan periodenya nonaktif (tidak ikut dikirim) kecuali masih menyimpan
     * hitungan > 0 (tetap aktif agar bisa dikosongkan; AttendanceResource::unusedComponentError() menolak nilai
     * selain 0 atau nilai tersimpan, dan menolak memindah hitungan itu ke periode lain). Hitungan komponen yang
     * tidak ada di form (komponen nonaktif yang tidak dinilai aturan mana pun) dipertahankan, lalu
     * component_counts dinormalisasi (hanya hitungan > 0).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Attendance $record */
        $record = $this->getRecord();
        $current = is_array($record->component_counts) ? $record->component_counts : [];
        $submitted = is_array($data['component_counts'] ?? null) ? $data['component_counts'] : [];

        if ($current !== [] || $submitted !== []) {
            $data['component_counts'] = AttendanceCountInput::normalizeComponentCounts(array_replace($current, $submitted));
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        // Validasi ulang target di server: jangan percaya state Livewire.
        $data = $this->form->getState();

        if (! AttendanceResource::canAccessAllAttendance(auth()->user())
            && ! AttendancePolicy::canManageUser(auth()->user(), $data['user_id'] ?? null)) {
            Notification::make()
                ->title('User tidak valid')
                ->body(DataAccessService::isAreaScopedAdmin(auth()->user())
                    ? 'Anda hanya bisa mengubah data kehadiran untuk karyawan di area yang diizinkan atau tim approval Anda, selain akun ADMIN (termasuk diri sendiri).'
                    : 'Anda hanya bisa mengubah data kehadiran untuk bawahan dalam scope approval_id (bawahan langsung + satu level).')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        // Satu data per karyawan + periode (sama dengan tambah, import, dan API): periode/karyawan tidak boleh
        // dipindah ke data yang sudah ada.
        $exists = Attendance::query()
            ->where('user_id', $data['user_id'] ?? $this->getRecord()->getAttribute('user_id'))
            ->where('periode', $data['periode'] ?? $this->getRecord()->getAttribute('periode'))
            ->whereKeyNot($this->getRecord()->getKey())
            ->exists();

        if ($exists) {
            Notification::make()
                ->title('Data sudah ada')
                ->body('User ini sudah memiliki data kehadiran untuk periode yang sama.')
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
