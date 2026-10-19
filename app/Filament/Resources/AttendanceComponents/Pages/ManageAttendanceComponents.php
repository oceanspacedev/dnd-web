<?php

namespace App\Filament\Resources\AttendanceComponents\Pages;

use App\Filament\Resources\AttendanceComponents\AttendanceComponentResource;
use Filament\Resources\Pages\ManageRecords;

class ManageAttendanceComponents extends ManageRecords
{
    protected static string $resource = AttendanceComponentResource::class;

    protected ?string $subheading = 'Jenis data kehadiran (terlambat, sakit, izin, mangkir, cuti, ...). Penalti tiap komponen diatur per versi di Aturan Penilaian. Komponen yang sudah dipakai tidak bisa dihapus; nonaktifkan saja.';

    protected function getHeaderActions(): array
    {
        return [
            AttendanceComponentResource::createAction(),
        ];
    }
}
