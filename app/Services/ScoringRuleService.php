<?php

namespace App\Services;

use App\Models\AttendanceComponent;
use App\Models\ScoringRule;
use App\Support\Periode;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\ScoringRuleData;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Resolver aturan penilaian kinerja berversi (scoring_rules) dan master komponen kehadiran.
 *
 * - Semua versi aturan + komponen dibaca SEKALI per request (memo pada scoped binding, aman untuk Octane),
 *   dan disimpan di cache aplikasi dengan kunci berversi (pola KpiCacheService, tanpa Cache::flush).
 *   Kunci juga memuat PAYLOAD_FORMAT; isi cache yang bentuknya tidak dikenal diabaikan dan dibaca ulang.
 *   Semua kunci diberi tanda database koneksi default (databaseTag()), karena beberapa store (mis. file)
 *   mengabaikan CACHE_PREFIX: aplikasi lain yang memakai store cache yang sama dengan database berbeda
 *   (staging, server demo) tidak boleh saling membaca aturan/komponennya.
 * - Periode memakai versi terakhir dengan effective_from <= periode. Periode tidak valid atau sebelum
 *   versi pertama di database memakai aturan lama tertanam di kode (ScoringRuleData::legacy()), sehingga
 *   riwayat tetap memakai aturan lama walaupun baris versi pertama hilang.
 * - Bila tabel belum ada/kosong (skema tes manual, deploy sebelum migrate) dipakai aturan lama tertanam di
 *   kode dan tiga komponen lama; keadaan fallback ini tidak di-cache.
 * - Setelah aturan/komponen disimpan atau dihapus (observer), request itu membaca langsung dari database
 *   tanpa menulis cache; versi cache baru dinaikkan setelah transaksi di-commit (rollback tidak menaikkan
 *   versi), sehingga perubahan yang belum/tidak di-commit tidak pernah masuk ke cache.
 *
 * Penjaga riwayat (versi terkunci, kolom lama komponen) ada di event Eloquent ScoringRule/AttendanceComponent.
 * Penulisan yang melewati event (query builder, saveQuietly, SQL langsung) tidak dijaga dan tidak menaikkan
 * versi cache: panggil clearCache() sesudahnya, dan jangan ubah versi yang sudah berlaku dengan cara itu.
 *
 * Saat ini satu set aturan untuk semua badan usaha. Dimensi lain (mis. per area) kelak cukup menambah
 * kunci pencarian di forPeriode() tanpa mengubah pemanggil.
 */
class ScoringRuleService
{
    private const VERSION_CACHE_KEY = 'scoring_rules_version';

    private const DEFAULT_TTL_SECONDS = 3600;

    /** Naikkan bila bentuk payload cache (toArray/fromArray) berubah agar cache lama tidak terbaca kode baru. */
    private const PAYLOAD_FORMAT = 1;

    private const REQUIRED_RULE_KEYS = ['name', 'effective_from', 'engine', 'kpi_weight', 'attendance_weight', 'review_weight', 'review_max_points', 'attendance_rules'];

    private const REQUIRED_COMPONENT_KEYS = ['code', 'name', 'unit'];

    /** @var list<ScoringRuleData>|null urut effective_from naik */
    private ?array $rules = null;

    /** @var array<string, AttendanceComponentData>|null */
    private ?array $components = null;

    /** @var array<string, ScoringRuleData> */
    private array $byPeriode = [];

    private ?int $version = null;

    /** True setelah aturan/komponen berubah di request ini dan versi cache belum dinaikkan (menunggu commit). */
    private bool $bypassCache = false;

    /**
     * Versi aturan yang berlaku untuk periode ('Y-m', 'Y-n', 'Y-m-d' atau DateTimeInterface).
     */
    public static function forPeriode(mixed $periode): ScoringRuleData
    {
        return resolve(self::class)->resolveForPeriode($periode);
    }

    /**
     * Semua versi aturan, urut effective_from naik.
     *
     * @return list<ScoringRuleData>
     */
    public static function rules(): array
    {
        return resolve(self::class)->loadedRules();
    }

    /**
     * Definisi komponen kehadiran per kode, urut sort_order.
     *
     * @return Collection<string, AttendanceComponentData>
     */
    public static function components(): Collection
    {
        return collect(resolve(self::class)->loadedComponents());
    }

    public static function component(string $code): ?AttendanceComponentData
    {
        return resolve(self::class)->loadedComponents()[$code] ?? null;
    }

    /**
     * Nomor versi cache aturan; disertakan pada kunci cache lain (mis. leaderboard) agar perubahan aturan
     * langsung terlihat.
     */
    public static function cacheVersion(): int
    {
        $instance = resolve(self::class);

        return $instance->version ??= max(1, (int) Cache::get(self::versionKey(), 1));
    }

    /**
     * Invalidasi cache aturan & komponen (dipanggil observer saat data berubah). Entri versi lama kedaluwarsa
     * sendiri; cache aplikasi lain tidak tersentuh.
     */
    public static function clearCache(): void
    {
        Cache::add(self::versionKey(), 1, now()->addYears(10));

        if (Cache::increment(self::versionKey()) === false) {
            Cache::forever(self::versionKey(), 2);
        }

        self::clearMemo();
    }

    /**
     * Aturan/komponen baru saja disimpan atau dihapus, mungkin di dalam transaksi yang belum di-commit
     * (dipanggil observer, juga setelah rollback). Sisa request ini membaca langsung dari database dan
     * tidak membaca/menulis cache aplikasi sampai clearCache() dipanggil setelah commit.
     */
    public static function markChanged(): void
    {
        $instance = resolve(self::class);
        $instance->resetMemo();
        $instance->bypassCache = true;
    }

    /**
     * Kosongkan memo per request (tes, atau setelah aturan diubah di request yang sama).
     */
    public static function clearMemo(): void
    {
        $instance = resolve(self::class);
        $instance->resetMemo();
        $instance->bypassCache = false;
    }

    private function resetMemo(): void
    {
        $this->rules = null;
        $this->components = null;
        $this->byPeriode = [];
        $this->version = null;
    }

    private function resolveForPeriode(mixed $periode): ScoringRuleData
    {
        $normalized = Periode::normalize($periode);
        $memoKey = $normalized ?? '';

        if (isset($this->byPeriode[$memoKey])) {
            return $this->byPeriode[$memoKey];
        }

        $selected = null;

        if ($normalized !== null) {
            foreach ($this->loadedRules() as $rule) {
                if (strcmp($rule->effectiveFrom, $normalized) > 0) {
                    break;
                }

                $selected = $rule;
            }
        }

        // Periode tidak valid atau sebelum versi pertama di database (mis. data sampah 1900-01/1970-01):
        // aturan lama tertanam di kode, bukan versi database paling awal.
        return $this->byPeriode[$memoKey] = $selected ?? ScoringRuleData::legacy();
    }

    /**
     * @return list<ScoringRuleData>
     */
    private function loadedRules(): array
    {
        $this->load();

        return $this->rules;
    }

    /**
     * @return array<string, AttendanceComponentData>
     */
    private function loadedComponents(): array
    {
        $this->load();

        return $this->components;
    }

    private static function versionKey(): string
    {
        return self::VERSION_CACHE_KEY.':'.self::databaseTag();
    }

    /**
     * Tanda pendek database koneksi default (nama koneksi + database), untuk memisahkan kunci cache antar
     * database yang berbagi store cache.
     */
    private static function databaseTag(): string
    {
        $connection = (string) config('database.default');

        return substr(sha1($connection.'|'.(string) config("database.connections.{$connection}.database")), 0, 12);
    }

    private function load(): void
    {
        if ($this->rules !== null && $this->components !== null) {
            return;
        }

        $key = 'scoring_rules:'.self::databaseTag().':f'.self::PAYLOAD_FORMAT.':v'.self::cacheVersion().':payload';
        $payload = $this->bypassCache ? null : Cache::get($key);

        if (! self::isValidPayload($payload)) {
            $payload = $this->readFromDatabase();

            // Fallback (tabel belum ada/kosong) tidak di-cache agar langsung pulih setelah migrate;
            // perubahan yang belum di-commit juga tidak.
            if ($payload['complete'] && ! $this->bypassCache) {
                Cache::put($key, $payload, $this->ttl());
            }
        }

        $rules = array_map(
            static fn (array $rule): ScoringRuleData => ScoringRuleData::fromArray($rule),
            $payload['rules'],
        );
        usort($rules, static fn (ScoringRuleData $a, ScoringRuleData $b): int => strcmp($a->effectiveFrom, $b->effectiveFrom));

        $components = [];
        foreach ($payload['components'] as $component) {
            $data = AttendanceComponentData::fromArray($component);
            $components[$data->code] = $data;
        }

        $this->rules = $rules === [] ? [ScoringRuleData::legacy()] : array_values($rules);
        $this->components = $components === [] ? AttendanceComponentData::legacyDefaults() : $components;
    }

    /**
     * @phpstan-assert-if-true array{rules: list<array<string, mixed>>, components: list<array<string, mixed>>} $payload
     */
    private static function isValidPayload(mixed $payload): bool
    {
        if (! is_array($payload)
            || ! isset($payload['rules'], $payload['components'])
            || ! is_array($payload['rules'])
            || ! is_array($payload['components'])) {
            return false;
        }

        foreach ([[$payload['rules'], self::REQUIRED_RULE_KEYS], [$payload['components'], self::REQUIRED_COMPONENT_KEYS]] as [$entries, $requiredKeys]) {
            foreach ($entries as $entry) {
                if (! is_array($entry) || array_diff($requiredKeys, array_keys($entry)) !== []) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{complete: bool, rules: list<array<string, mixed>>, components: list<array<string, mixed>>}
     */
    private function readFromDatabase(): array
    {
        $rules = [];
        $components = [];

        if (Schema::hasTable('scoring_rules')) {
            $rules = ScoringRule::query()
                ->orderBy('effective_from')
                ->get()
                ->map(static fn (ScoringRule $rule): array => ScoringRuleData::fromModel($rule)->toArray())
                ->values()
                ->all();
        }

        if (Schema::hasTable('attendance_components')) {
            $components = AttendanceComponent::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (AttendanceComponent $component): array => AttendanceComponentData::fromModel($component)->toArray())
                ->values()
                ->all();
        }

        return [
            'complete' => $rules !== [] && $components !== [],
            'rules' => $rules,
            'components' => $components,
        ];
    }

    private function ttl(): int
    {
        return max(1, (int) config('kpi.cache_ttl_seconds.scoring_rules', self::DEFAULT_TTL_SECONDS));
    }
}
