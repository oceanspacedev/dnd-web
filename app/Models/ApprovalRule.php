<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ApprovalRule extends Model
{
    use HasFactory;

    protected $guarded = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ApprovalRule $rule): void {
            if (! $rule->hasAnyCriterion()) {
                throw ValidationException::withMessages([
                    'area_id' => 'Isi minimal satu kriteria: Area, Divisi, Jabatan, atau Posisi.',
                ]);
            }

            if ($rule->priority !== null && (int) $rule->priority < 0) {
                throw ValidationException::withMessages([
                    'priority' => 'Prioritas tidak boleh kurang dari 0.',
                ]);
            }
        });
    }

    public function hasAnyCriterion(): bool
    {
        return $this->area_id !== null
            || $this->divisi_id !== null
            || $this->position_id !== null
            || $this->role_id !== null;
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<Divisi, $this> */
    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
