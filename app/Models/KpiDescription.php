<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    protected $casts = [
        'is_negative' => 'boolean',
    ];
    
    public function kpi_category()
    {
        return $this->belongsTo(KpiCategory::class);
    }

    public function kpi_detail()
    {
        return $this->hasMany(KpiDetail::class);
    }
}
