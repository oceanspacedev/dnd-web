<?php

namespace App\Models;

use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Date;

class Kpi extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [
        'id',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @return BelongsTo<KpiCategory, $this> */
    public function kpi_category(): BelongsTo
    {
        return $this->belongsTo(KpiCategory::class);
    }

    /** @return BelongsTo<KpiType, $this> */
    public function kpi_type(): BelongsTo
    {
        return $this->belongsTo(KpiType::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<KpiDetail, $this> */
    public function kpi_detail(): HasMany
    {
        return $this->hasMany(KpiDetail::class, 'kpi_id');
    }

    /**
     * Pemilik KPI untuk cek cakupan data (DataAccessService::canSeeUser): pakai relasi user yang sudah
     * di-eager-load agar tidak ada query users.area_id per baris; selain itu id-nya
     * (relasi belum dimuat, karyawan soft-deleted, atau user_id sudah diubah).
     */
    public function ownerForAccessCheck(): User|int|string|null
    {
        if ($this->relationLoaded('user')
            && $this->user instanceof User
            && (int) $this->user->getKey() === (int) $this->user_id) {
            return $this->user;
        }

        return $this->user_id;
    }

    public function isChecklistLockedFor(?User $user = null): bool
    {
        $user ??= auth()->user();

        // Semua admin bebas dari batas periode; cakupan record tetap dicek lewat KpiPolicy.
        if (DataAccessService::isAdmin($user)) {
            return false;
        }

        if (! $this->date) {
            return false;
        }

        $kpiMonth = Date::parse($this->date)->startOfMonth();
        $lockDays = max(0, (int) config('kpi.checklist_lock_days', 5));
        $deadline = $kpiMonth->copy()->endOfMonth()->addDays($lockDays)->endOfDay();

        return Date::now()->greaterThan($deadline);
    }

    /**
     * Get a formatted list of KPI details with their subtasks
     *
     * @return Attribute<array<array-key, list<mixed>>|null, never>
     */
    protected function descriptionsWithSubtasks(): Attribute
    {
        return Attribute::make(get: function () {
            if (! $this->kpi_detail || $this->kpi_detail->isEmpty()) {
                return null;
            }
            $output = [];
            foreach ($this->kpi_detail as $detail) {
                $description = $detail->kpi_description?->description ?? 'N/A';

                $subtasks = [];
                if (! empty($detail->subtasks) && is_array($detail->subtasks)) {
                    foreach ($detail->subtasks as $subtask) {
                        if (isset($subtask['description'])) {
                            $subtasks[] = $subtask['description'];
                        }
                    }
                }

                $output[$description] = $subtasks;
            }

            return $output;
        });
    }
}
