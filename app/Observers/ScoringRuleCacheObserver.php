<?php

namespace App\Observers;

use App\Services\ScoringRuleService;
use Illuminate\Database\Eloquent\Model;

/**
 * Invalidasi cache aturan penilaian setiap kali versi aturan (ScoringRule) atau master komponen
 * kehadiran (AttendanceComponent) berubah.
 *
 * - Langsung: memo request ini dikosongkan dan request ini berhenti memakai cache aplikasi, sehingga
 *   perubahan yang belum di-commit hanya terlihat oleh request yang membuatnya dan tidak pernah di-cache.
 * - Setelah commit: versi cache dinaikkan (request lain memuat ulang data yang sudah di-commit).
 * - Setelah rollback: memo dikosongkan lagi; versi cache tidak berubah karena isinya tetap data yang ter-commit.
 * Tanpa transaksi, ketiganya berjalan seketika seperti sebelumnya.
 */
class ScoringRuleCacheObserver
{
    public function saved(Model $model): void
    {
        $this->invalidate($model);
    }

    public function deleted(Model $model): void
    {
        $this->invalidate($model);
    }

    private function invalidate(Model $model): void
    {
        ScoringRuleService::markChanged();

        $connection = $model->getConnection();
        $connection->afterCommit(static fn () => ScoringRuleService::clearCache());
        $connection->afterRollBack(static fn () => ScoringRuleService::markChanged());
    }
}
