<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /** @return HasMany<User, $this> */
    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Divisi, $this> */
    public function divisi(): HasMany
    {
        return $this->hasMany(Divisi::class);
    }
}
