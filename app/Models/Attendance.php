<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'periode',
        'work_days',
        'late_less_30',
        'late_more_30',
        'sick_days',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
