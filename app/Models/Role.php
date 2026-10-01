<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Role extends Model
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

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'level' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Role $role): void {
            if ($role->level !== null && (int) $role->level < 0) {
                throw ValidationException::withMessages([
                    'level' => 'Level grade tidak boleh kurang dari 0.',
                ]);
            }
        });
    }
}
