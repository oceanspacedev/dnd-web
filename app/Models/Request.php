<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;

class Request extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function approveId(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    /** @return Attribute<int|null, never> */
    protected function createdAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Date::parse($value)->getPreciseTimestamp(3);
            }
        });
    }

    /** @return Attribute<int|null, never> */
    protected function updatedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Date::parse($value)->getPreciseTimestamp(3);

            }
        });
    }

    /** @return Attribute<int|null, never> */
    protected function approvedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Date::parse($value)->getPreciseTimestamp(3);
            }
        });
    }

    /** @return Attribute<int|null, never> */
    protected function deletedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Date::parse($value)->getPreciseTimestamp(3);
            }
        });
    }
}
