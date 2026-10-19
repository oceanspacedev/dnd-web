<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * tools/guide-gifs (rekam GIF panduan) menjalankan migrate:fresh dan mengekspor semua karyawan, jadi hanya boleh
 * memakai database dnd_demo_*. Cek config saja tidak cukup: DB_URL digabung ke config koneksi saat koneksi dibuka,
 * sehingga config masih menyebut database demo sementara query berjalan di database dalam URL.
 */
class GuideGifsDemoDatabaseGuardTest extends TestCase
{
    private string|false $originalDbDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $helper = base_path('tools/guide-gifs/demo_db.php');
        $this->assertFileExists($helper);
        require_once $helper;

        $this->originalDbDatabase = getenv('DB_DATABASE');
        putenv('DB_DATABASE=dnd_demo_posisi');
    }

    protected function tearDown(): void
    {
        putenv($this->originalDbDatabase === false ? 'DB_DATABASE' : 'DB_DATABASE='.$this->originalDbDatabase);
        DB::purge((string) config('database.default'));

        parent::tearDown();
    }

    public function test_a_database_url_is_refused_although_the_config_names_the_demo_database(): void
    {
        $connection = (string) config('database.default');
        config([
            "database.connections.{$connection}.database" => 'dnd_demo_posisi',
            "database.connections.{$connection}.url" => 'mysql://nobody@127.0.0.1:1/some_real_db',
        ]);
        DB::purge($connection);

        // Config masih menyebut database demo, koneksi yang dibuka memakai database dari URL (tanpa benar-benar connect).
        $this->assertSame('dnd_demo_posisi', config("database.connections.{$connection}.database"));
        $this->assertSame('some_real_db', DB::connection()->getDatabaseName());

        $this->assertSame(
            'a database URL (DB_URL) is configured: it would replace DB_DATABASE when the connection opens',
            guideGifsDemoDatabaseProblem(),
        );
    }

    public function test_db_database_must_be_an_allowed_demo_database_and_match_the_config(): void
    {
        putenv('DB_DATABASE=dnd_feature_test');
        $this->assertSame('DB_DATABASE is dnd_feature_test, expected dnd_demo_*', guideGifsDemoDatabaseProblem());

        putenv('DB_DATABASE=dnd_demo_posisi');
        $this->assertSame(
            'DB_DATABASE is dnd_demo_posisi, expected dnd_demo_panduan',
            guideGifsDemoDatabaseProblem(['dnd_demo_panduan']),
        );
        $this->assertSame(
            'app is configured for :memory:, expected the demo DB dnd_demo_posisi from DB_DATABASE',
            guideGifsDemoDatabaseProblem(),
        );
    }

    public function test_the_live_connection_must_answer_with_the_demo_database(): void
    {
        // Config tanpa URL dan bernama database demo, tetapi koneksinya tidak bisa menjawab select database(): ditolak.
        $connection = (string) config('database.default');
        config(["database.connections.{$connection}.database" => 'dnd_demo_posisi']);
        DB::purge($connection);

        $this->assertSame('cannot open dnd_demo_posisi (run setup-db.sh first)', guideGifsDemoDatabaseProblem());
    }

    public function test_every_script_checks_the_live_database_before_it_writes_or_reads_people(): void
    {
        $this->assertCheckedBefore('demo_seed.php', "guideGifsDemoDatabaseProblem(['dnd_demo_panduan'])", "Artisan::call('migrate:fresh'");
        $this->assertCheckedBefore('demo_seed.php', "guideGifsDemoDatabaseProblem(['dnd_demo_kpimanajerial', 'dnd_demo_kpioperasional'])", 'json_encode(seedKpiLayer()');
        $this->assertCheckedBefore('flows/aturan-template.php', "guideGifsDemoDatabaseProblem(['dnd_demo_aturan'])", 'Excel::raw(');
        $this->assertCheckedBefore('guard.php', 'guideGifsDemoDatabaseProblem()', 'ScoringRuleService::clearCache()');

        // env.sh mengosongkan DB_URL untuk semua proses (shell script dan flow lewat SAFE_ENV di lib.js).
        $this->assertMatchesRegularExpression('/^export DB_URL=$/m', (string) file_get_contents(base_path('tools/guide-gifs/env.sh')));
        $this->assertStringContainsString("'CACHE_STORE', 'DB_URL']", (string) file_get_contents(base_path('tools/guide-gifs/lib.js')));
    }

    private function assertCheckedBefore(string $script, string $check, string $action): void
    {
        $source = (string) file_get_contents(base_path('tools/guide-gifs/'.$script));
        $checkAt = strpos($source, $check);
        $actionAt = strpos($source, $action);

        $this->assertNotFalse($checkAt, "{$script} harus memanggil {$check}");
        $this->assertNotFalse($actionAt, "{$script} tidak lagi berisi {$action}");
        $this->assertLessThan($actionAt, $checkAt, "{$script}: {$check} harus berjalan sebelum {$action}");
    }
}
