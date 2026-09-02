<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    
    public function kpi_description()
    {
        return $this->hasMany(KpiDescription::class);
    }

    public function kpi()
    {
        return $this->hasMany(Kpi::class);
    }
}
