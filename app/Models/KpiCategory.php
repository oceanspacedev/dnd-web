<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KpiCategory extends Model
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

    /** @return HasMany<KpiDescription, $this> */
    public function kpi_description(): HasMany
    {
        return $this->hasMany(KpiDescription::class);
    }

    /** @return HasMany<Kpi, $this> */
    public function kpi(): HasMany
    {
        return $this->hasMany(Kpi::class);
    }
}
