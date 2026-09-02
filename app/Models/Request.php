<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Request extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id')->withTrashed();
    }

    public function approveId()
    {
        return $this->belongsTo(User::class, 'approval_id')->withTrashed();
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by')->withTrashed();
    }

    protected function createdAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Carbon::parse($value)->getPreciseTimestamp(3);
            }
        });
    }
    protected function updatedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Carbon::parse($value)->getPreciseTimestamp(3);
    
            }
        });
    }
    protected function approvedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Carbon::parse($value)->getPreciseTimestamp(3);
            }
        });
    }

    protected function deletedAt(): Attribute
    {
        return Attribute::make(get: function ($value) {
            if ($value) {
                return Carbon::parse($value)->getPreciseTimestamp(3);
            }
        });
    }
}
