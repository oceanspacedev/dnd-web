<?php

namespace App\Policies;

use App\Filament\Resources\ScoringRules\ScoringRulePresenter;
use App\Models\Attendance;
use App\Models\AttendanceComponent;
use App\Models\User;
use App\Services\DataAccessService;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Auth\Access\Response;

/**
 * Master komponen kehadiran berlaku untuk seluruh badan usaha: hanya super admin (ADMIN dengan akses
 * seluruh badan usaha) yang boleh melihat dan mengelolanya di panel. Pembacaan untuk aplikasi mobile
 * lewat API master (master/attendance-components) terbuka untuk semua user yang login.
 *
 * Komponen bawaan (punya kolom lama di tabel attendances) dan komponen yang sudah dipakai versi aturan
 * atau data kehadiran tidak bisa dihapus; nonaktifkan saja. Penjaga terakhir ada di event model.
 *
 * Komponen yang masih dinilai (penalti > 0 atau mengurangi rumus dasar) oleh versi aturan bulan lalu, bulan
 * berjalan, atau versi terjadwal tidak bisa dinonaktifkan (deactivate): isian kehadirannya masih dibutuhkan
 * karena hitungannya memengaruhi nilai.
 */
class AttendanceComponentPolicy
{
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
    public function view(User $user, AttendanceComponent $attendanceComponent): bool
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
     * Determine whether the user can update the model (kode tidak bisa diubah; dijaga form dan model).
     */
    public function update(User $user, AttendanceComponent $attendanceComponent): bool
    {
        return $this->canManage($user);
    }

    /**
     * Determine whether the user can deactivate the model (tidak bila masih dinilai versi aturan yang dipakai
     * bulan lalu, bulan berjalan, atau terjadwal).
     */
    public function deactivate(User $user, AttendanceComponent $attendanceComponent): Response
    {
        if (! $this->canManage($user)) {
            return Response::deny('Hanya admin dengan akses seluruh badan usaha yang dapat mengelola komponen kehadiran.');
        }

        $rules = self::rulesStillScoring((string) $attendanceComponent->getOriginal('code'));

        if ($rules === []) {
            return Response::allow();
        }

        return Response::deny('Komponen masih dinilai versi aturan '.implode(', ', $rules).' sehingga belum bisa dinonaktifkan.'
            .' Buat dulu versi aturan baru dengan penalti 0 dan tanpa mengurangi rumus dasar untuk komponen ini;'
            .' komponen bisa dinonaktifkan setelah tidak ada lagi versi yang menilainya untuk bulan lalu, bulan berjalan, maupun bulan mendatang.');
    }

    /**
     * Versi aturan yang masih menilai komponen (penalti > 0 atau mengurangi rumus dasar) dan dipakai bulan lalu,
     * bulan berjalan, atau terjadwal. Bulan lalu ikut karena data kehadiran sebuah bulan baru diinput setelah
     * bulannya selesai. Dibaca dari cache aturan (tanpa query).
     *
     * @return list<string> mis. 'Aturan Kehadiran November 2026 (mulai November 2026, penalti 5 poin/hari)'
     */
    public static function rulesStillScoring(string $code): array
    {
        $from = ScoringRulePresenter::previousPeriode(Periode::current());
        $rules = [ScoringRuleService::forPeriode($from)];

        foreach (ScoringRuleService::rules() as $rule) {
            if (strcmp($rule->effectiveFrom, $from) > 0) {
                $rules[] = $rule;
            }
        }

        $unit = ScoringRuleService::component($code)->unit ?? AttendanceComponent::UNIT_DAY;
        $names = [];

        foreach ($rules as $rule) {
            if (! self::scores($rule, $code)) {
                continue;
            }

            $effects = [];

            if ($rule->penaltyPoints($code) > 0) {
                $effects[] = 'penalti '.ScoringRulePresenter::number($rule->penaltyPoints($code)).' poin/'.$unit;
            }

            if ($rule->reducesBase($code)) {
                $effects[] = 'mengurangi rumus dasar';
            }

            $names[] = '"'.$rule->name.'" (mulai '.ScoringRulePresenter::periodeLabel($rule->effectiveFrom).', '.implode(', ', $effects).')';
        }

        return array_values(array_unique($names));
    }

    /**
     * Determine whether the user can delete the model (hanya komponen yang belum pernah dipakai).
     */
    public function delete(User $user, AttendanceComponent $attendanceComponent): Response
    {
        if (! $this->canManage($user)) {
            return Response::deny('Hanya admin dengan akses seluruh badan usaha yang dapat mengelola komponen kehadiran.');
        }

        return $this->usageResponse($attendanceComponent);
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
    public function restore(User $user, AttendanceComponent $attendanceComponent): bool
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
    public function forceDelete(User $user, AttendanceComponent $attendanceComponent): Response
    {
        return $this->delete($user, $attendanceComponent);
    }

    /**
     * Determine whether the user can bulk permanently delete models.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $this->canManage($user);
    }

    /**
     * Alasan komponen tidak bisa dihapus (pesan yang sama dengan penjaga model), atau allow bila belum dipakai.
     */
    private function usageResponse(AttendanceComponent $component): Response
    {
        $code = (string) $component->getOriginal('code');

        if ($component->getOriginal('legacy_column') !== null) {
            return Response::deny('Komponen bawaan kolom kehadiran tidak dapat dihapus. Nonaktifkan saja.');
        }

        $rules = collect(ScoringRuleService::rules())
            ->filter(static fn ($rule): bool => ! $rule->isFallback() && $rule->hasComponent($code));

        if ($rules->isNotEmpty()) {
            return Response::deny('Komponen sudah dipakai aturan penilaian ('.$rules->pluck('name')->implode(', ').') sehingga tidak dapat dihapus. Nonaktifkan saja.');
        }

        // Kode tervalidasi pola [a-z0-9_], aman dipakai sebagai path JSON.
        if (preg_match(AttendanceComponent::CODE_PATTERN, $code) === 1
            && Attendance::withTrashed()->whereNotNull("component_counts->{$code}")->exists()) {
            return Response::deny('Komponen sudah dipakai data kehadiran sehingga tidak dapat dihapus. Nonaktifkan saja.');
        }

        return Response::allow();
    }

    private static function scores(ScoringRuleData $rule, string $code): bool
    {
        return $rule->hasComponent($code) && ($rule->penaltyPoints($code) > 0 || $rule->reducesBase($code));
    }

    private function canManage(User $user): bool
    {
        return DataAccessService::hasGlobalAccess($user);
    }
}
