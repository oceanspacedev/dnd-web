<?php

// Demo database for guide GIF recordings: fake people only (Faker id_ID, fixed seed), no production data.
// Two layers, both run with the environment from env.sh (WhatsApp -> local stub, mail -> log, cache -> array):
//   base  setup-db.sh: DB_DATABASE=dnd_demo_panduan php demo_seed.php
//         migrate:fresh + accounts, employees, attendance and reviews; dumped to .work/demo_base.sql for every guide.
//   kpi   reset.sh, KPI guides only: DB_DATABASE=dnd_demo_kpimanajerial|dnd_demo_kpioperasional php demo_seed.php kpi
//         adds KPI masters, KPIs and demo WhatsApp numbers on top of a restored demo_base.sql (seedKpiLayer below).

use App\Models\Area;
use App\Models\Attendance;
use App\Models\Divisi;
use App\Models\EmployeeReview;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\KpiType;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiScoringService;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$repo = dirname(__DIR__, 2);
chdir($repo);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// .env holds the real WhatsApp gateway: refuse unless env.sh pointed it at the local stub (setup-db.sh / reset.sh do).
if (config('services.whatsapp.api_url') !== getenv('WAG_URL')
    || preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string) config('services.whatsapp.api_url')) !== 1) {
    fwrite(STDERR, "Refusing to seed: WAG_URL is not the local WhatsApp stub (source env.sh first)\n");
    exit(1);
}

$layer = $argv[1] ?? 'base';
if ($layer === 'kpi') {
    $db = config('database.connections.'.config('database.default').'.database');
    if (! in_array($db, ['dnd_demo_kpimanajerial', 'dnd_demo_kpioperasional'], true)) {
        fwrite(STDERR, "Refusing to seed the KPI layer: DB_DATABASE is not dnd_demo_kpimanajerial or dnd_demo_kpioperasional\n");
        exit(1);
    }
    echo json_encode(seedKpiLayer(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(0);
}
if ($layer !== 'base') {
    fwrite(STDERR, "usage: php demo_seed.php [kpi]\n");
    exit(2);
}

// migrate:fresh drops every table, so only ever run it on the demo database (a cached config would ignore DB_DATABASE).
if (config('database.connections.mysql.database') !== 'dnd_demo_panduan'
    || config('database.connections.'.config('database.default').'.database') !== 'dnd_demo_panduan') {
    fwrite(STDERR, "Refusing to seed: DB_DATABASE is not dnd_demo_panduan\n");
    exit(1);
}

Artisan::call('migrate:fresh', ['--force' => true]);
echo trim(Artisan::output()) === '' ? "migrated\n" : "migrated (see output)\n";

$faker = Factory::create('id_ID');
$faker->seed(20261018);
$password = 'uji-lokal-2026';

$roles = [];
foreach (['ADMIN' => 100, 'STAFF' => 10, 'TEAM LEADER' => 20, 'COORDINATOR' => 30, 'MANAGER' => 40, 'CHIEF' => 50, 'BOD' => 60] as $name => $level) {
    $roles[$name] = Role::query()->create(['name' => $name, 'level' => $level]);
}

$areas = [];
foreach (['COO', 'CSO', 'ULS', 'GMD'] as $name) {
    $areas[$name] = Area::query()->create(['name' => $name]);
}

$divisis = [
    'COO' => Divisi::query()->create(['name' => 'HCM', 'area_id' => $areas['COO']->id]),
    'COO-FAM' => Divisi::query()->create(['name' => 'FAM', 'area_id' => $areas['COO']->id]),
    'CSO' => Divisi::query()->create(['name' => 'RETAIL', 'area_id' => $areas['CSO']->id]),
    'ULS' => Divisi::query()->create(['name' => 'RETAIL ULS', 'area_id' => $areas['ULS']->id]),
    'GMD' => Divisi::query()->create(['name' => 'RETAIL GMD', 'area_id' => $areas['GMD']->id]),
];

$positions = [];
foreach (['Staf HRD', 'Staf Keuangan', 'Kasir', 'Pramuniaga', 'Kepala Toko', 'Supervisor Toko', 'Admin Gudang', 'Staf Gudang'] as $name) {
    $positions[$name] = Position::query()->create(['name' => $name]);
}

$sequence = 0;
$make = function (string $username, string $name, string $role, string $area, ?User $approval, string $position, array $extra = []) use (&$sequence, $roles, $areas, $divisis, $positions, $password): User {
    $sequence++;
    $divisiKey = $area === 'COO' && $position === 'Staf Keuangan' ? 'COO-FAM' : $area;

    return User::query()->create(array_merge([
        'employee_id' => sprintf('2026.%02d.%03d', $areas[$area]->id, $sequence),
        'nama_lengkap' => strtoupper($name),
        'username' => $username,
        'password' => $password,
        'role_id' => $roles[$role]->id,
        'area_id' => $areas[$area]->id,
        'divisi_id' => $divisis[$divisiKey]->id,
        'position_id' => $positions[$position]->id,
        'approval_id' => $approval?->id,
        'd' => true, 'dr' => false, 'wn' => false, 'wr' => false, 'mn' => false, 'mr' => false,
    ], $extra))->refresh();
};

// Akun demo per peran. demo_admin_bu mulai sebagai staf COO: GIF 1 Panduan Akses Data menjadikannya ADMIN (Akses
// Data otomatis Badan Usaha Tertentu = COO, lalu diganti ULS + GMD), GIF 2 login sebagai admin itu.
$superAdmin = $make('demo_superadmin', 'Admin Pusat', 'ADMIN', 'COO', null, 'Staf HRD', ['data_access' => User::DATA_ACCESS_ALL]);
$scopedAdmin = $make('demo_admin_bu', 'Admin Badan Usaha', 'STAFF', 'COO', null, 'Staf HRD');
$manager = $make('demo_manager', $faker->name('male'), 'MANAGER', 'COO', null, 'Supervisor Toko');
$teamLeader = $make('demo_teamleader', $faker->name('female'), 'TEAM LEADER', 'ULS', $manager, 'Kepala Toko');

// Karyawan contoh di setiap badan usaha.
$plan = [
    ['ULS', $teamLeader, 'Kasir'], ['ULS', $teamLeader, 'Pramuniaga'], ['ULS', $teamLeader, 'Pramuniaga'],
    ['ULS', $teamLeader, 'Admin Gudang'], ['GMD', $teamLeader, 'Kasir'],
    ['GMD', $manager, 'Kepala Toko'], ['GMD', $manager, 'Pramuniaga'], ['GMD', $manager, 'Staf Gudang'],
    ['CSO', $manager, 'Kasir'], ['CSO', $manager, 'Pramuniaga'], ['CSO', $manager, 'Staf Gudang'],
    ['COO', $manager, 'Staf HRD'], ['COO', $manager, 'Staf Keuangan'], ['COO', $manager, 'Staf Keuangan'],
    ['ULS', $manager, 'Supervisor Toko'], ['GMD', $teamLeader, 'Pramuniaga'],
];
$staff = [];
foreach ($plan as $i => [$area, $approval, $position]) {
    $staff[] = $make('demo_staf_'.($i + 1), $faker->name($i % 2 === 0 ? 'female' : 'male'), 'STAFF', $area, $approval, $position);
}

// Contoh data kehadiran & penilaian periode September 2026 (aturan lama).
foreach ($staff as $i => $employee) {
    Attendance::query()->create([
        'user_id' => $employee->id, 'periode' => '2026-09', 'work_days' => 22,
        'late_less_30' => $i % 4, 'late_more_30' => $i % 7 === 0 ? 1 : 0, 'sick_days' => $i % 5 === 0 ? 1 : 0,
    ]);
    EmployeeReview::query()->create([
        'user_id' => $employee->id, 'periode' => '2026-09',
        'responsiveness' => 3 + $i % 3, 'problem_solver' => 3 + ($i + 1) % 3, 'helpfulness' => 4, 'initiative' => 3 + $i % 2,
    ]);
}

echo json_encode([
    'users' => User::query()->count(),
    'accounts' => ['demo_superadmin', 'demo_admin_bu', 'demo_manager', 'demo_teamleader', 'demo_staf_1'],
    'team_leader' => $teamLeader->nama_lengkap,
    'scoring_rules' => DB::table('scoring_rules')->pluck('effective_from'),
    'components' => DB::table('attendance_components')->count(),
], JSON_PRETTY_PRINT).PHP_EOL;

/**
 * Lapisan KPI untuk Panduan KPI Manajerial (dnd_demo_kpimanajerial) dan Panduan KPI Operasional
 * (dnd_demo_kpioperasional). reset.sh menjalankannya setelah memulihkan demo_base.sql. Lapisan ini sengaja tidak masuk
 * ke dump bersama: kolom No. HP tampil di daftar Karyawan, jadi nomor WhatsApp demo (dan KPI) akan mengubah GIF tiga
 * panduan lain. Tanpa Faker dan tanpa user baru: semua isi tetap, hanya bulan mengikuti jam komputer.
 *
 * - kpi_types 1 Harian / 2 Mingguan / 3 Bulanan dan kpi_categories 1 ADMINISTRATION / 2 REPORTING / 3 MAIN JOB,
 *   dengan id dipaksa: form Buat Kpi dan widget Checklist KPI memakai id ini.
 * - Deskripsi KPI contoh per kategori. "Insiden kritikal" sengaja tidak ada: GIF Manajerial membuatnya lewat tombol +.
 * - KPI Bulanan posisi Kasir untuk IFA PURWANTI (demo_staf_1) dan ICHA RAHAYU HANDAYANI S.IP (demo_staf_5), seperti
 *   yang dibuat demo_teamleader untuk posisi Kasir: bulan berjalan (belum diisi) dan bulan lalu (sudah diisi, periode
 *   checklist sudah ditutup). Admin Gudang (PRIMA LURHUR HAKIM) tidak punya KPI: GIF Manajerial membuatnya.
 * - No. HP WhatsApp palsu: demo_staf_1 0812-0000-0101 (login OTP Panduan KPI Operasional) dan demo_manager
 *   0812-0000-0003 (cadangan kalau GIF Manajerial perlu login OTP). Disimpan lewat model, jadi formatnya 08… seperti app.
 *
 * @return array<string, mixed>
 */
function seedKpiLayer(): array
{
    if (KpiType::withTrashed()->exists() || KpiCategory::withTrashed()->exists() || Kpi::withTrashed()->exists()) {
        fwrite(STDERR, "Refusing to seed the KPI layer: the DB already has KPI data (run reset.sh)\n");
        exit(1);
    }

    $month = CarbonImmutable::now()->startOfMonth();
    $previous = $month->subMonthNoOverflow();

    return DB::transaction(function () use ($month, $previous): array {
        foreach ([1 => 'Harian', 2 => 'Mingguan', 3 => 'Bulanan'] as $id => $name) {
            KpiType::query()->forceCreate(['id' => $id, 'name' => $name]);
        }
        foreach ([1 => 'ADMINISTRATION', 2 => 'REPORTING', 3 => 'MAIN JOB'] as $id => $name) {
            KpiCategory::query()->forceCreate(['id' => $id, 'name' => $name]);
        }

        // Urutan pembuatan = urutan pilihan di form Buat Kpi. Contoh SOP 1 Panduan KPI Manajerial ada di depan.
        $descriptions = [];
        foreach ([
            [1, 'Timesheet lengkap', false],
            [1, 'Arsip surat jalan lengkap', false],
            [1, 'Input penjualan harian ke sistem', false],
            [2, 'Laporan bulanan tepat waktu', false],
            [2, 'Laporan stok opname bulanan', false],
            [2, 'Kirim laporan stok mingguan ke Kepala Toko', false],
            [3, 'Tiket selesai per bulan', false],
            [3, 'Penerimaan barang sesuai jadwal', false],
            [3, 'Menjalankan SOP buka dan tutup kasir', false],
            [3, 'Jumlah transaksi member baru', false],
            [3, 'Selisih kas akhir shift', true],
        ] as [$categoryId, $text, $negative]) {
            $descriptions[$text] = KpiDescription::query()->create([
                'description' => $text, 'kpi_category_id' => $categoryId, 'is_negative' => $negative,
            ]);
        }

        // KPI posisi Kasir: kategori => [persentase, [deskripsi, count_type, value_plan, subtasks]]. Total 60/20/20 = 100.
        $kasir = [
            1 => [20, [['Input penjualan harian ke sistem', 'NON', null, null]]],
            2 => [20, [['Kirim laporan stok mingguan ke Kepala Toko', 'NON', null, null]]],
            3 => [60, [
                ['Menjalankan SOP buka dan tutup kasir', 'NON', null, [['description' => 'Hitung modal awal kasir'], ['description' => 'Setor kas akhir shift']]],
                ['Jumlah transaksi member baru', 'RESULT', 40, null],
                ['Selisih kas akhir shift', 'RESULT', 3, null],
            ]],
        ];
        // Hasil bulan lalu per user, urut seperti baris $kasir: NON = selesai (1) / belum (0), RESULT = Actual.
        $previousActuals = [
            'demo_staf_1' => [1, 1, 1, 38, 3],
            'demo_staf_5' => [1, 0, 1, 35, 4],
        ];

        $kpis = 0;
        $details = 0;
        foreach ($previousActuals as $username => $actuals) {
            $user = User::query()->where('username', $username)->firstOrFail();
            foreach ([[$month, null], [$previous, $actuals]] as [$date, $filled]) {
                $row = 0;
                foreach ($kasir as $categoryId => [$percentage, $rows]) {
                    $kpi = Kpi::query()->create([
                        'user_id' => $user->id, 'kpi_type_id' => 3, 'kpi_category_id' => $categoryId,
                        'date' => $date->format('Y-m-d 00:00:00'), 'percentage' => $percentage,
                    ]);
                    $kpis++;
                    foreach ($rows as [$text, $countType, $plan, $subtasks]) {
                        $description = $descriptions[$text];
                        $actual = $filled === null || $countType === 'NON' ? null : $filled[$row];
                        $result = match (true) {
                            $filled === null => 0,
                            $countType === 'NON' => $filled[$row],
                            default => KpiScoringService::calculateResultValue((float) $plan, (float) $actual, (bool) $description->is_negative),
                        };
                        KpiDetail::query()->create([
                            'kpi_id' => $kpi->id, 'kpi_description_id' => $description->id, 'count_type' => $countType,
                            'value_plan' => $plan, 'value_actual' => $actual, 'value_result' => $result, 'is_extra_task' => 0,
                            'start' => $countType === 'RESULT' ? $date->format('Y-m-d') : null,
                            'end' => $countType === 'RESULT' ? $date->endOfMonth()->format('Y-m-d') : null,
                            'subtasks' => $subtasks,
                        ]);
                        $details++;
                        $row++;
                    }
                }
            }
        }

        $phones = ['demo_staf_1' => '0812-0000-0101', 'demo_manager' => '0812-0000-0003'];
        foreach ($phones as $username => $phone) {
            User::query()->where('username', $username)->firstOrFail()->update(['no_hp' => $phone]);
        }

        return [
            'db' => DB::connection()->getDatabaseName(),
            'months' => [$month->format('Y-m'), $previous->format('Y-m')],
            'kpi_types' => KpiType::query()->pluck('name', 'id'),
            'kpi_categories' => KpiCategory::query()->pluck('name', 'id'),
            'kpi_descriptions' => KpiDescription::query()->count(),
            'kpis' => $kpis,
            'kpi_details' => $details,
            'whatsapp' => User::query()->whereIn('username', array_keys($phones))->pluck('no_hp', 'username'),
        ];
    });
}
