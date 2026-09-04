<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WeeklyLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'weekly_logs';

    protected $guarded = ['id'];

    protected $fillable = ['user_id', 'task_id', 'activity'];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Weekly, $this> */
    public function weekly(): BelongsTo
    {
        return $this->belongsTo(Weekly::class, 'task_id');
    }
}
