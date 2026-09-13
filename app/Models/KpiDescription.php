<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiDescription extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** @return BelongsTo<KpiCategory, $this> */
    public function kpi_category(): BelongsTo
    {
        return $this->belongsTo(KpiCategory::class);
    }

    /** @return HasMany<KpiDetail, $this> */
    public function kpi_detail(): HasMany
    {
        return $this->hasMany(KpiDetail::class);
    }

    protected function casts(): array
    {
        return [
            'is_negative' => 'boolean',
        ];
    }
}
