<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function kpi()
    {
        return $this->belongsTo(Kpi::class);
    }

    public function kpi_description()
    {
        return $this->belongsTo(KpiDescription::class);
    }

    public function children()
    {
        return $this->hasMany(KpiDetail::class, 'parent_id');
    }
    /**
     * The attributes that should be cast.
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'subtasks' => 'array',
        ];
    }
}
