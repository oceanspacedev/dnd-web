<?php

namespace App\Models;

use App\Services\KpiScoringService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiDetail extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @return BelongsTo<Kpi, $this> */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    /** @return BelongsTo<KpiDescription, $this> */
    public function kpi_description(): BelongsTo
    {
        return $this->belongsTo(KpiDescription::class);
    }

    /** @return HasMany<KpiDetail, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(KpiDetail::class, 'parent_id');
    }

    /**
     * Pencapaian mentah untuk tampilan, tanpa batas 100% (mis. 6.0 = 600% saat negatif plan 2 actual 0).
     * Dihitung dari plan/actual/is_negative, jadi berlaku juga untuk data lama. Skor tetap memakai
     * value_result yang dibatasi 100%. Tipe selain RESULT mengembalikan value_result apa adanya.
     *
     * @return Attribute<float, never>
     */
    protected function rawResult(): Attribute
    {
        return Attribute::make(get: function (): float {
            if ($this->count_type !== 'RESULT') {
                return (float) ($this->value_result ?? 0);
            }

            return KpiScoringService::calculateRawResultValue(
                $this->value_plan !== null ? (float) $this->value_plan : null,
                $this->value_actual !== null ? (float) $this->value_actual : null,
                (bool) ($this->kpi_description?->is_negative ?? false),
            );
        });
    }

    /**
     * True bila pencapaian mentah melampaui 100% (skor tetap dihitung 100%).
     *
     * @return Attribute<bool, never>
     */
    protected function exceedsTarget(): Attribute
    {
        return Attribute::make(get: fn (): bool => $this->raw_result > KpiScoringService::RESULT_CAP);
    }

    /**
     * Hitung ulang value_result baris RESULT dari value_plan, value_actual, dan is_negative deskripsinya
     * (KpiScoringService::calculateResultValue, dibatasi 100%), lalu simpan bila nilainya berubah.
     * Baris NON tidak disentuh: value_result-nya 0/1 dari toggle checklist, bukan hasil rumus.
     *
     * @return bool True bila value_result berubah dan tersimpan.
     */
    public function syncResultValue(): bool
    {
        if ($this->count_type !== 'RESULT') {
            return false;
        }

        // Relasi yang sudah dimuat bisa masih menunjuk deskripsi lama setelah kpi_description_id diganti.
        if ($this->relationLoaded('kpi_description')
            && (int) ($this->kpi_description?->getKey() ?? 0) !== (int) $this->kpi_description_id) {
            $this->unsetRelation('kpi_description');
        }

        $this->value_result = KpiScoringService::calculateResultValue(
            $this->value_plan !== null ? (float) $this->value_plan : null,
            $this->value_actual !== null ? (float) $this->value_actual : null,
            (bool) ($this->kpi_description?->is_negative ?? false),
        );

        if (! $this->isDirty('value_result')) {
            return false;
        }

        return $this->save();
    }

    protected function casts(): array
    {
        return [
            'subtasks' => 'array',
        ];
    }
}
