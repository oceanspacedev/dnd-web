<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Weekly extends Model
{
    use HasFactory,SoftDeletes;

    protected $guarded = [
        'id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function taskcategory()
    {
        return $this->belongsTo(TaskCategory::class, 'task_category_id');
    }

    public function taskstatus()
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    public function add()
    {
        return $this->belongsTo(User::class, 'add_id')->withTrashed();
    }

    public function tag()
    {
        return $this->belongsTo(User::class, 'tag_id')->withTrashed();
    }
}
