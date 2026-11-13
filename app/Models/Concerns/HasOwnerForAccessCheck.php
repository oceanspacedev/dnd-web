<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data milik karyawan (kolom user_id) yang cakupannya dicek per baris di daftar.
 *
 * Relasi user tidak memuat karyawan yang sudah dihapus (soft delete), sehingga cek cakupan untuk baris
 * seperti itu jatuh ke query per baris. Daftar yang butuh cek per baris (admin per badan usaha) memuat
 * trashedUser sekaligus, lalu ownerForAccessCheck() memakainya.
 *
 * @property int|string|null $user_id
 */
trait HasOwnerForAccessCheck
{
    /**
     * Pemilik yang sudah dihapus (soft delete). Hanya untuk cek akses; tampilan tetap memakai relasi user.
     *
     * @return BelongsTo<User, $this>
     */
    public function trashedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->onlyTrashed();
    }

    /**
     * Pemilik data untuk cek cakupan (DataAccessService::canSeeUser / canWriteForUser): model User dari relasi
     * yang sudah dimuat (user, atau trashedUser untuk pemilik yang dihapus) agar tidak ada query per baris;
     * selain itu id-nya (relasi belum dimuat atau user_id sudah diubah).
     */
    public function ownerForAccessCheck(): User|int|string|null
    {
        foreach (['user', 'trashedUser'] as $relation) {
            $owner = $this->relationLoaded($relation) ? $this->getRelation($relation) : null;

            if ($owner instanceof User && (int) $owner->getKey() === (int) $this->user_id) {
                return $owner;
            }
        }

        return $this->user_id;
    }
}
