<?php

namespace App\Filament\Resources\Attendances\Pages;

use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use App\Support\Scoring\AttendanceCountInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;

class CreateAttendance extends CreateRecord
{
    protected static string $resource = AttendanceResource::class;

    /**
     * component_counts hanya menyimpan hitungan > 0 (null bila semua komponen non-kolom bernilai 0).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (array_key_exists('component_counts', $data)) {
            $data['component_counts'] = AttendanceCountInput::normalizeComponentCounts($data['component_counts']);
        }

        return $data;
    }

    /**
     * "Buat & buat lainnya": data kehadiran diisi per periode untuk banyak karyawan, jadi Periode dan Hari Kerja
     * dipertahankan untuk isian berikutnya (karyawan & hitungan komponen dikosongkan).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preserveFormDataWhenCreatingAnother(array $data): array
    {
        return Arr::only($data, ['periode', 'work_days']);
    }

    protected function beforeCreate(): void
    {
        // Get the form data
        $data = $this->form->getState();

        // Validasi ulang target di server: jangan percaya state Livewire.
        if (! AttendancePolicy::canManageUser(auth()->user(), $data['user_id'] ?? null)) {
            Notification::make()
                ->title('User tidak valid')
                ->body(DataAccessService::isAreaScopedAdmin(auth()->user())
                    ? 'Anda hanya bisa membuat data kehadiran untuk karyawan di badan usaha yang diizinkan atau tim approval Anda, selain akun ADMIN (termasuk diri sendiri).'
                    : 'Anda hanya bisa membuat data kehadiran untuk bawahan dalam scope approval_id (bawahan langsung + satu level).')
                ->danger()
                ->send();

            $this->halt();

            return;
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
