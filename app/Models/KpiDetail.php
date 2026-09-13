<?php

namespace App\Models;

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

    protected function casts(): array
    {
        return [
            'subtasks' => 'array',
        ];
    }
}
