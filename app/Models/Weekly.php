<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Weekly extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<TaskCategory, $this> */
    public function taskcategory(): BelongsTo
    {
        return $this->belongsTo(TaskCategory::class, 'task_category_id');
    }

    /** @return BelongsTo<TaskStatus, $this> */
    public function taskstatus(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'task_status_id');
    }

    /** @return BelongsTo<User, $this> */
    public function add(): BelongsTo
    {
        return $this->belongsTo(User::class, 'add_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tag_id')->withTrashed();
    }
}
