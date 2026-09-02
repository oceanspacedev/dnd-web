<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;


class DailyLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'daily_logs';

    protected $guarded = ['id'];

    protected $fillable = ['user_id', 'task_id', 'activity'];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function daily()
    {
        return $this->belongsTo(Daily::class, 'task_id');
    }
}
