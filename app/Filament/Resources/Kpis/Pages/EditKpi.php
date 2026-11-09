<?php

namespace App\Filament\Resources\Kpis\Pages;

use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Kpi;
use App\Models\KpiDetail;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditKpi extends EditRecord
{
    protected static string $resource = KpiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function resolveRecord($key): Model
    {
        return parent::resolveRecord($key)
            ->load([
                'kpi_detail.kpi_description',
                'user.position',
                'kpi_category',
                'kpi_type',
            ]);
    }

    /**
     * Hitung ulang value_result semua baris RESULT setelah repeater detail disimpan. Filament menyimpan
     * relasi repeater sebelum hook ini, masih dalam transaksi yang sama, jadi yang dibaca di sini sudah
     * data tersimpan (termasuk deskripsi yang baru dibuat lewat createOptionForm).
     *
     * Admin bisa mengubah value_plan, count_type, atau deskripsi (dengan is_negative-nya) pada baris yang
     * sudah punya value_actual; tanpa ini value_result tersimpan jadi basi (mis. tetap 6.0 padahal rumus
     * saat ini memberi 1.0). Dipilih hook di halaman edit, bukan event saving di model KpiDetail, karena:
     * - hanya jalur ini yang menulis plan/count_type/deskripsi tanpa menghitung value_result; checklist,
     *   extra task, copy, dan API sudah memanggil KpiScoringService::calculateResultValue sendiri
     *   (konvensi proyek: penulis menetapkan value_result secara eksplisit);
     * - event di model harus membedakan nilai yang di-set langsung (toggle NON 0/1, extra task yang
     *   menganggap plan kosong = 1) dari yang perlu dihitung ulang, dan aturan tersembunyi itu mudah
     *   salah saat ada penulis baru.
     * Efek samping yang disengaja: menyimpan form ini juga membetulkan value_result basi pada baris
     * RESULT yang tidak diubah. Baris NON dilewati (KpiDetail::syncResultValue).
     */
    protected function afterSave(): void
    {
        /** @var Kpi $kpi */
        $kpi = $this->getRecord();

        $kpi->kpi_detail()
            ->where('count_type', 'RESULT')
            ->with('kpi_description')
            ->get()
            ->each(fn (KpiDetail $detail) => $detail->syncResultValue());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }
}
