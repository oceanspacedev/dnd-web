<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskStatus extends Model
{
    use HasFactory;

    protected $table = 'task_status';

    protected $guarded = [
        'id',
    ];

    protected $fillable = [
        'task_status'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];
}
