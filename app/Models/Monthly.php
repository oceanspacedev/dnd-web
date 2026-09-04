<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;

class Monthly extends Model
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

    /** @return Attribute<int, never> */
    protected function date(): Attribute
    {
        return Attribute::make(get: function ($value) {
            return Date::parse($value)->getPreciseTimestamp(3);
        });
    }

    /** @return Attribute<int, never> */
    protected function createdAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            return Date::parse($value)->getPreciseTimestamp(3);
        });
    }

    /** @return Attribute<int, never> */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            return Date::parse($value)->getPreciseTimestamp(3);
        });
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
