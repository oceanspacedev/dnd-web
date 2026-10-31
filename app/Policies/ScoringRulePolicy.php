<?php

namespace App\Policies;

use App\Models\ScoringRule;
use App\Models\User;
use App\Services\DataAccessService;
use Illuminate\Auth\Access\Response;

/**
 * Aturan penilaian kinerja berlaku untuk seluruh badan usaha: hanya super admin (ADMIN dengan akses
 * seluruh badan usaha) yang boleh melihat dan mengelolanya di panel. Pembacaan untuk aplikasi mobile
 * lewat API master (master/scoring-rules) terbuka untuk semua user yang login.
 *
 * Versi yang sudah dipakai bulan yang telah berakhir (ScoringRule::isLocked) tidak bisa diubah atau dihapus;
 * hanya nama dan catatannya (updateDetails). Perubahan aturan dilakukan dengan membuat versi baru.
 * Cek di sini murni (tanpa query); penjaga terakhir ada di event model ScoringRule.
 */
class ScoringRulePolicy
{
    private const LOCKED_MESSAGE = 'Versi aturan yang sudah berlaku terkunci. Buat versi baru (Duplikat jadi versi baru) untuk mengubah aturan.';

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ScoringRule $scoringRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can update the model (seluruh isian; hanya versi yang belum terkunci).
     */
    public function update(User $user, ScoringRule $scoringRule): Response
    {
        return $this->manageUnlocked($user, $scoringRule);
    }

    /**
     * Determine whether the user can rename or annotate the model (juga untuk versi yang terkunci).
     */
    public function updateDetails(User $user, ScoringRule $scoringRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ScoringRule $scoringRule): Response
    {
        return $this->manageUnlocked($user, $scoringRule);
    }

    /**
     * Determine whether the user can bulk delete models.
     */
    public function deleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ScoringRule $scoringRule): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can bulk restore models.
     */
    public function restoreAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ScoringRule $scoringRule): Response
    {
        return $this->manageUnlocked($user, $scoringRule);
    }

    /**
     * Determine whether the user can bulk permanently delete models.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    private function manageUnlocked(User $user, ScoringRule $scoringRule): Response
    {
        if (! $this->canManage($user)) {
            return Response::deny('Hanya admin dengan akses seluruh area yang dapat mengelola aturan penilaian.');
        }

        return $scoringRule->isLocked() ? Response::deny(self::LOCKED_MESSAGE) : Response::allow();
    }

    private function canManage(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }
}
