<?php

namespace Tests\Feature;

use App\Exports\AttendanceImportTemplateExport;
use App\Filament\Pages\ScoringRulesGuide;
use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\AttendanceComponent;
use App\Models\ScoringRule;
use App\Models\User;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceCountInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Panduan Aturan Penilaian (/admin/panduan-aturan-penilaian).
 *
 * - Semua user panel boleh membuka (semua dinilai); tamu diarahkan ke login.
 * - Bagian input/import Kehadiran hanya bagi yang bisa membuka menu Kehadiran; bagian pengaturan Aturan
 *   Penilaian & Komponen Kehadiran hanya bagi super admin.
 * - Aturan, jadwal versi, contoh hitung, dan kolom template dibaca langsung dari data aturan yang tersimpan.
 *
 * Jam dibekukan 17 Oktober 2026: versi "Aturan Lama" (2000-01) berlaku, "Aturan Kehadiran November 2026"
 * (2026-11) terjadwal (seed migration).
 */
class ScoringRulesGuideTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const URL = '/admin/panduan-aturan-penilaian';

    private const ATTENDANCE_FORM_HEADING = 'SOP 1 - Mengisi Data Kehadiran Satu per Satu';

    private const ATTENDANCE_IMPORT_HEADING = 'SOP 2 - Import Kehadiran dari Excel';

    private const SKIPPED_ROWS_HEADING = 'Baris Dilewati &amp; Peringatan Import';

    private const RULES_HEADING = 'SOP 3 - Mengubah Aturan Penilaian dengan Aman (Super Admin)';

    private const COMPONENTS_HEADING = 'SOP 4 - Mengelola Komponen Kehadiran (Super Admin)';

    /** GIF contoh langkah di public/images/panduan-aturan-penilaian: SOP 1-2 (Kehadiran) dan SOP 3-4 (super admin). */
    private const ATTENDANCE_GIFS = ['kehadiran-isi-form.gif', 'kehadiran-import.gif'];

    private const SETTINGS_GIFS = ['aturan-versi-baru.gif', 'komponen-tambah.gif'];

    /** Catatan internal versi "Aturan Lama" dari seed migration: tidak boleh tampil di panduan. */
    private const LEGACY_NOTES = 'Kolom Sakit berisi sakit dan izin sehingga tetap dipenalti 5 poin per hari.';

    private User $superAdmin;

    private User $scopedAdmin;

    private User $manager;

    private User $staff;

    private User $attendanceClerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Date::create(2026, 10, 17, 9));
        Cache::flush();
        $this->resetAccessMemos();

        $coo = $this->fixtureArea('COO');
        $uls = $this->fixtureArea('ULS');

        $this->superAdmin = $this->fixtureGlobalAdmin('super_admin', $coo);
        $this->scopedAdmin = $this->fixtureScopedAdmin('ojan', $coo, [$uls]);
        $this->manager = $this->fixtureUser('uls_manager', 'MANAGER', $uls);
        $this->staff = $this->fixtureUser('uls_staff', 'STAFF', $uls, $this->manager);
        // User khusus kehadiran (AttendancePolicy::GLOBAL_ATTENDANCE_USERNAMES): role STAFF tanpa tim.
        $this->attendanceClerk = $this->fixtureUser('darkini', 'STAFF', $coo);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();

        parent::tearDown();
    }

    public function test_page_is_registered_in_the_panduan_menu(): void
    {
        $this->assertSame('Panduan Aturan Penilaian', ScoringRulesGuide::getNavigationLabel());
        $this->assertSame('Panduan', ScoringRulesGuide::getNavigationGroup());
        $this->assertSame('panduan-aturan-penilaian', ScoringRulesGuide::getSlug());
        $this->assertSame(84, ScoringRulesGuide::getNavigationSort());
        $this->assertSame('heroicon-o-book-open', ScoringRulesGuide::getNavigationIcon());
        $this->assertSame('Panduan Aturan Penilaian Kinerja', (new ScoringRulesGuide)->getTitle());
    }

    public function test_every_signed_in_user_can_open_the_guide_and_guests_are_sent_to_login(): void
    {
        foreach ($this->allUsers() as $label => $user) {
            $this->signIn($user);

            $this->assertTrue(ScoringRulesGuide::canAccess(), $label);
            $this->get(self::URL)
                ->assertOk()
                ->assertSee('Panduan Aturan Penilaian Kinerja')
                ->assertSee('Rumus Nilai Akhir');
        }

        auth()->logout();
        $this->resetAccessMemos();

        $this->assertFalse(ScoringRulesGuide::canAccess());
        $this->get(self::URL)->assertRedirect('/admin/login');
    }

    public function test_sections_follow_what_each_viewer_can_open(): void
    {
        // [bagian Kehadiran, bagian pengaturan super admin]
        $matrix = [
            'super admin' => [$this->superAdmin, true, true],
            'admin per badan usaha' => [$this->scopedAdmin, true, false],
            'atasan' => [$this->manager, true, false],
            'user khusus kehadiran' => [$this->attendanceClerk, true, false],
            'staf' => [$this->staff, false, false],
        ];

        foreach ($matrix as $label => [$user, $attendance, $settings]) {
            $data = $this->viewData($user);

            $this->assertSame($attendance, $data['canInputAttendance'], $label);
            $this->assertSame($settings, $data['canManageRules'], $label);
            $this->assertSame($attendance, $data['attendanceUrl'] !== null, $label);
            $this->assertSame($settings, $data['scoringRulesUrl'] !== null, $label);
            $this->assertSame($settings, $data['componentsUrl'] !== null, $label);
            $this->assertSame($attendance, $data['templateColumns'] !== [], $label);
            $this->assertSame($settings, $data['componentStatuses'] !== [], $label);

            $page = $this->render($user)
                ->assertSee('Aturan yang Berlaku Bulan Ini')
                ->assertSee('Contoh Perhitungan')
                ->assertSee('Lihat Leaderboard')
                ->assertDontSee(self::LEGACY_NOTES);

            foreach ([self::ATTENDANCE_FORM_HEADING, self::ATTENDANCE_IMPORT_HEADING, 'Buka Menu Kehadiran', 'Kenapa ada baris yang dilewati saat import?'] as $text) {
                $attendance ? $page->assertSee($text) : $page->assertDontSee($text);
            }

            $attendance ? $page->assertSeeHtml(self::SKIPPED_ROWS_HEADING) : $page->assertDontSeeHtml(self::SKIPPED_ROWS_HEADING);

            foreach ([self::RULES_HEADING, self::COMPONENTS_HEADING, 'Buka Aturan Penilaian', 'Buka Komponen Kehadiran', 'Penguncian', 'Kenapa komponen tidak bisa dinonaktifkan atau dihapus?'] as $text) {
                $settings ? $page->assertSee($text) : $page->assertDontSee($text);
            }
        }
    }

    public function test_sop_gifs_exist_and_follow_the_sections_each_viewer_can_open(): void
    {
        foreach ([...self::ATTENDANCE_GIFS, ...self::SETTINGS_GIFS] as $gif) {
            $this->assertFileExists(public_path('images/panduan-aturan-penilaian/'.$gif));
        }

        // [GIF SOP Kehadiran, GIF SOP super admin]
        $matrix = [
            'super admin' => [$this->superAdmin, true, true],
            'admin per badan usaha' => [$this->scopedAdmin, true, false],
            'atasan' => [$this->manager, true, false],
            'user khusus kehadiran' => [$this->attendanceClerk, true, false],
            'staf' => [$this->staff, false, false],
        ];

        foreach ($matrix as $label => [$user, $attendance, $settings]) {
            $page = $this->render($user);

            foreach ([[self::ATTENDANCE_GIFS, $attendance], [self::SETTINGS_GIFS, $settings]] as [$gifs, $visible]) {
                foreach ($gifs as $gif) {
                    $src = 'src="'.asset('images/panduan-aturan-penilaian/'.$gif).'"';
                    $visible ? $page->assertSeeHtml($src) : $page->assertDontSeeHtml($src);
                }
            }
        }

        $this->render($this->superAdmin)
            ->assertSeeHtmlInOrder([
                self::ATTENDANCE_FORM_HEADING,
                'kehadiran-isi-form.gif',
                'GIF 1. Mengisi data kehadiran bulan lalu untuk satu karyawan',
                self::ATTENDANCE_IMPORT_HEADING,
                'kehadiran-import.gif',
                'GIF 2. Download template dan import file yang sudah diisi untuk periode bulan lalu',
                self::RULES_HEADING,
                'aturan-versi-baru.gif',
                'GIF 3. Membuat versi aturan baru',
                self::COMPONENTS_HEADING,
                'komponen-tambah.gif',
                'GIF 4. Menambah komponen kehadiran baru',
            ])
            ->assertSeeHtml('alt="GIF mengisi kehadiran satu karyawan:')
            ->assertSeeHtml('alt="GIF import kehadiran:')
            ->assertSeeHtml('alt="GIF membuat versi aturan baru:')
            ->assertSeeHtml('alt="GIF menambah komponen kehadiran:');
    }

    public function test_attendance_scope_describes_the_viewers_own_access(): void
    {
        $this->render($this->attendanceClerk)
            ->assertSee('Seluruh karyawan di semua badan usaha.')
            ->assertDontSee('Kehadiran akun ADMIN (termasuk akun Anda sendiri) hanya dapat dicatat oleh super admin.');

        $this->render($this->scopedAdmin)
            ->assertSee('Karyawan di badan usaha ULS dan anggota tim Anda menurut Approval Line, kecuali akun ADMIN')
            ->assertDontSee('Karyawan di badan usaha COO')
            ->assertSee('Kehadiran akun ADMIN (termasuk akun Anda sendiri) hanya dapat dicatat oleh super admin.');

        $this->render($this->manager)
            ->assertSee('Anggota tim Anda menurut Approval Line (bawahan langsung dan satu level di bawahnya).')
            ->assertDontSee('Seluruh karyawan di semua badan usaha.');
    }

    public function test_current_rule_schedule_and_versions_are_read_live(): void
    {
        $data = $this->viewData($this->staff);

        $this->assertSame('Aturan Lama', $data['currentRule']['name']);
        // Bulan penanda 2000-01 versi pertama tampil sebagai "Sejak awal", bukan "Januari 2000".
        $this->assertSame('Sejak awal', $data['currentRule']['startLabel']);
        $this->assertTrue($data['currentRule']['sinceStart']);
        $this->assertSame('KPI 70 · Kehadiran 15 · Penilaian 15', $data['currentRule']['weightsText']);
        $this->assertSame('Aturan Kehadiran November 2026', $data['nextRule']['name']);
        $this->assertSame('November 2026', $data['nextRule']['startLabel']);
        $this->assertFalse($data['nextRule']['sinceStart']);
        $this->assertNull($data['nextRule']['following']);
        $this->assertSame(['Terjadwal', 'Berlaku'], array_column($data['versions'], 'statusLabel'));
        $this->assertSame(['November 2026', 'Sejak awal'], array_column($data['versions'], 'startLabel'));
        $this->assertSame('November 2026', $data['izinFromLabel']);

        // Nama seperti di form Kehadiran: Aturan Lama mencatat izin bersama sakit (Sakit/Izin), Izin tidak bisa diisi.
        $sick = collect($data['currentRule']['components'])->firstWhere('name', 'Sakit/Izin');
        $this->assertTrue($sick['counted']);
        $this->assertTrue($sick['usable']);
        $this->assertTrue($sick['reducesBase']);
        $this->assertSame('5 poin/hari', $sick['penaltyText']);
        $izin = collect($data['currentRule']['components'])->firstWhere('name', 'Izin');
        $this->assertFalse($izin['counted']);
        $this->assertFalse($izin['usable']);

        $this->render($this->staff)
            ->assertSee('Versi berikutnya sudah dijadwalkan: Aturan Kehadiran November 2026 (mulai November 2026)')
            ->assertSee('Data bulan November 2026 sampai ada versi berikutnya dinilai dengan versi ini')
            ->assertDontSee('dan sesudahnya dinilai dengan versi ini')
            ->assertSee('Izin: 5 poin/hari')
            ->assertSee('Tidak dipakai versi ini: isiannya nonaktif di form Kehadiran dan kolomnya tidak ada di template periode versi ini.')
            ->assertDontSee('Tidak dinilai versi ini (jumlahnya tidak memengaruhi nilai).')
            ->assertSee('Dipakai sejak awal, sebelum ada versi lain')
            ->assertSee('Bila di menu lain tertulis Januari 2000 (2000-01), itu hanya penanda versi pertama')
            ->assertSee('Tercatat Januari 2000 (penanda versi pertama)')
            ->assertDontSee('Berlaku mulai Januari 2000');

        // Desember 2026: versi November berlaku, belum ada jadwal berikutnya.
        $this->travelTo(Date::create(2026, 12, 5, 9));
        $data = $this->viewData($this->staff);

        $this->assertSame('Aturan Kehadiran November 2026', $data['currentRule']['name']);
        $this->assertSame('November 2026', $data['currentRule']['startLabel']);
        $this->assertFalse($data['currentRule']['sinceStart']);
        $this->assertNull($data['nextRule']);
        $this->assertSame(['Berlaku', 'Riwayat'], array_column($data['versions'], 'statusLabel'));

        $cuti = collect($data['currentRule']['components'])->firstWhere('name', 'Cuti tahunan');
        $this->assertTrue($cuti['counted']);
        $this->assertTrue($cuti['usable']);
        $this->assertTrue($cuti['noEffect']);
        $this->assertNotNull(collect($data['currentRule']['components'])->firstWhere('name', 'Sakit'));

        $this->render($this->staff)->assertDontSee('Versi berikutnya sudah dijadwalkan');

        // Versi baru yang disimpan langsung tampil sebagai jadwal berikutnya.
        $this->createRule('2027-01', 'Aturan Uji Januari 2027', 60, 20, 20);
        $data = $this->viewData($this->staff);

        $this->assertSame('Aturan Uji Januari 2027', $data['nextRule']['name']);
        $this->assertSame('KPI 60 · Kehadiran 20 · Penilaian 20', $data['nextRule']['weightsText']);
        $this->assertSame(['Terjadwal', 'Berlaku', 'Riwayat'], array_column($data['versions'], 'statusLabel'));
    }

    public function test_scheduled_version_card_stops_at_the_next_scheduled_version(): void
    {
        // Oktober 2026: November 2026 dan Januari 2027 sama-sama terjadwal.
        $this->createRule('2027-01', 'Aturan Uji Januari 2027', 60, 20, 20);
        $data = $this->viewData($this->staff);

        $this->assertSame('Aturan Kehadiran November 2026', $data['nextRule']['name']);
        $this->assertSame(['name' => 'Aturan Uji Januari 2027', 'startLabel' => 'Januari 2027'], $data['nextRule']['following']);

        $this->render($this->staff)
            ->assertSee('Data bulan November 2026 sampai sebelum Januari 2027 dinilai dengan versi ini; mulai Januari 2027 berlaku Aturan Uji Januari 2027')
            ->assertDontSee('sampai ada versi berikutnya dinilai dengan versi ini');
    }

    public function test_izin_listed_without_penalty_is_recorded_separately_like_the_attendance_form(): void
    {
        // Versi November dihapus (belum terkunci) lalu versi Desember dibuat seperti duplikat Aturan Lama: form
        // mencantumkan izin dengan penalti 0, sakit tetap mengurangi rumus dasar dan dipenalti 5 poin/hari.
        ScoringRule::query()->where('effective_from', '2026-11')->firstOrFail()->delete();
        $this->createRule('2026-12', 'Aturan Uji Desember 2026', 70, 15, 15, [
            ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
            ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 3],
            ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 5],
            ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 0],
        ]);

        // Form Kehadiran, template, import, dan API: izin yang tercantum (walau penalti 0) diisi sendiri, Sakit bukan
        // lagi "Sakit/Izin". Panduan mengikuti aturan yang sama.
        $december = ScoringRuleService::forPeriode('2026-12');
        $this->assertFalse(AttendanceCountInput::recordsIzinInSick($december));
        $this->assertTrue(AttendanceCountInput::isUsedBy('izin', $december));

        $data = $this->viewData($this->attendanceClerk);

        $this->assertSame('Desember 2026', $data['izinFromLabel']);
        $this->assertSame('Aturan Uji Desember 2026', $data['nextExample']['ruleName']);
        $this->assertFalse($data['nextExample']['izinInSick']);
        $this->assertSame(['Terlambat ≤ 30 menit', 'Terlambat > 30 menit', 'Sakit', 'Izin'], array_column($data['nextExample']['rows'], 'name'));
        // Izin 1 hari tercatat tanpa penalti: penalti 2×1 + 1×3 + 1×5 + 1×0.
        $this->assertSame('2 × 1 + 1 × 3 + 1 × 5 + 1 × 0', $data['nextExample']['penaltyTerms']);
        $this->assertTrue($data['example']['izinInSick']);

        $this->render($this->attendanceClerk)
            ->assertSee('Mulai periode Desember 2026, izin diisi di kolom')
            ->assertSee('Kenapa sakit dan izin dihitung berbeda antar bulan?');

        // Versi sesudahnya yang menilai izin tidak menggeser bulan izin dicatat sendiri.
        $this->createRule('2027-02', 'Aturan Uji Februari 2027', 70, 15, 15, [
            ['component' => 'sick_days', 'reduces_base' => true, 'penalty_points' => 0],
            ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 5],
        ]);

        $this->assertSame('Desember 2026', $this->viewData($this->attendanceClerk)['izinFromLabel']);
    }

    public function test_guide_without_a_version_listing_izin_keeps_izin_in_sakit_izin(): void
    {
        ScoringRule::query()->where('effective_from', '2026-11')->firstOrFail()->delete();
        ScoringRuleService::clearCache();

        $data = $this->viewData($this->attendanceClerk);

        $this->assertNull($data['izinFromLabel']);
        $this->assertNull($data['izinUnusedExample']);
        $this->assertTrue($data['example']['izinInSick']);

        $this->render($this->attendanceClerk)
            ->assertDontSee('izin diisi di kolom')
            ->assertDontSee('Kenapa sakit dan izin dihitung berbeda antar bulan?');
    }

    public function test_lock_status_and_component_status_are_shown_to_super_admin(): void
    {
        $data = $this->viewData($this->superAdmin);

        $this->assertSame([
            'Masih bisa diubah atau dihapus sampai akhir November 2026',
            'Terkunci: hanya nama dan catatan yang bisa diubah',
        ], array_column($data['versions'], 'lockText'));
        // Bulan kosong pertama sesudah Oktober 2026 (November sudah dipakai).
        $this->assertSame('Desember 2026', $data['nextAvailablePeriodeLabel']);

        $statuses = collect($data['componentStatuses'])->keyBy('code');
        $this->assertSame(['"Aturan Kehadiran November 2026" (mulai November 2026, penalti 5 poin/hari)'], $statuses['izin']['scoredBy']);
        $this->assertSame([], $statuses['cuti_tahunan']['scoredBy']);
        $this->assertTrue($statuses['sick_days']['isBuiltIn']);
        $this->assertNotSame([], $statuses['sick_days']['scoredBy']);

        $this->render($this->superAdmin)
            ->assertSee('Masih bisa diubah atau dihapus sampai akhir November 2026')
            ->assertSee('Belum, masih dinilai')
            ->assertSee('Desember 2026')
            ->assertDontSee(self::LEGACY_NOTES)
            // Versi terjadwal yang belum terkunci diubah lewat Ubah, bukan dibuatkan versi baru.
            ->assertDontSee('Aturan tidak diubah di tempat')
            ->assertSee('Versi Terjadwal atau yang baru mulai bulan ini masih bisa diubah')
            ->assertSee('Buat versi baru (langkah 4 dst.) hanya untuk bulan yang belum punya versi.')
            ->assertSee('klik ikon titik tiga (⋮) di ujung kanan baris')
            ->assertDontSee('menu aksi baris')
            // Versi baru boleh mulai bulan berjalan bila bulan itu belum punya versi.
            ->assertSee('mulai bulan berjalan atau sesudahnya (bila bulan itu belum punya versi)')
            ->assertDontSee('mulai bulan berikutnya')
            // Nonaktifkan baru bisa sebulan sesudah versi baru mulai; hapus/satuan terhalang walau penalti 0.
            ->assertSee('tunggu sampai bulan sesudah bulan pertama versi baru itu')
            ->assertSee('belum tercantum di versi aturan mana pun (termasuk dengan penalti 0)');
    }

    public function test_worked_example_is_computed_with_the_version_in_effect_this_month(): void
    {
        // Oktober 2026, "Aturan Lama": izin belum dinilai terpisah sehingga dicatat di kolom Sakit (1 sakit + 1 izin).
        $data = $this->viewData($this->staff);
        $example = $data['example'];

        $this->assertSame('Aturan Lama', $example['ruleName']);
        $this->assertTrue($example['izinInSick']);
        $this->assertSame(['Terlambat ≤ 30 menit', 'Terlambat > 30 menit', 'Sakit/Izin'], array_column($example['rows'], 'name'));
        $this->assertSame(5, $example['reducingCount']);
        $this->assertSame('77,27', $example['basePct']);
        $this->assertSame('2 × 1 + 1 × 3 + 2 × 5', $example['penaltyTerms']);
        $this->assertSame('15', $example['penalty']);
        $this->assertSame('62,27', $example['finalPct']);
        $this->assertSame('9,34', $example['attendanceScore']);
        $this->assertSame('12', $example['reviewScore']);
        $this->assertSame('63', $example['kpiScore']);
        $this->assertSame('84,34', $example['total']);

        // Versi terjadwal November 2026: angka sama dengan contoh di README (izin di kolom sendiri).
        $next = $data['nextExample'];
        $this->assertSame('Aturan Kehadiran November 2026', $next['ruleName']);
        $this->assertFalse($next['izinInSick']);
        $this->assertSame('81,82', $next['basePct']);
        $this->assertSame('10', $next['penalty']);
        $this->assertSame('71,82', $next['finalPct']);
        $this->assertSame('10,77', $next['attendanceScore']);
        $this->assertSame('85,77', $next['total']);

        // Data contoh memakai nama dan satuan komponen yang tersimpan.
        $this->assertSame(
            ['Terlambat ≤ 30 menit 2 kejadian', 'Terlambat > 30 menit 1 kejadian', 'Sakit 1 hari', 'Izin 1 hari'],
            $data['exampleEvents'],
        );

        $this->render($this->staff)
            ->assertSee('Terlambat ≤ 30 menit 2 kejadian, Terlambat > 30 menit 1 kejadian, Sakit 1 hari, Izin 1 hari.')
            ->assertSee('Versi ini belum memakai izin sebagai komponen sendiri')
            ->assertSee('84,34')
            ->assertSee('Perbandingan dengan versi terjadwal Aturan Kehadiran November 2026 (mulai November 2026)')
            ->assertSee('85,77')
            // Nama kolom Leaderboard dan label tabel Penilaian supaya angka/aspek bisa dicocokkan.
            ->assertSee('Leaderboard menulis desimal dengan titik (84.34 sama dengan 84,34).')
            // Export KPI (LeaderboardExportController) menulis Total Score tanpa Cutpoint dan tanpa akun ADMIN.
            ->assertSeeHtml('kolom <strong>Total Score</strong> di file itu adalah nilai akhir <strong>sebelum</strong> dikurangi Cutpoint, dan akun ADMIN tidak ikut di file itu.')
            ->assertSeeHtml('Skor Penilaian = <strong>Activity</strong>')
            ->assertSee('(di tabel: Pemecahan Masalah)')
            ->assertSee('(di tabel: Kesediaan Membantu)')
            ->assertSee('artinya sama dengan');

        // Desember 2026: contoh memakai versi November (izin dan sakit di kolom masing-masing).
        $this->travelTo(Date::create(2026, 12, 5, 9));
        $example = $this->viewData($this->staff)['example'];

        $this->assertSame('Aturan Kehadiran November 2026', $example['ruleName']);
        $this->assertFalse($example['izinInSick']);
        $this->assertSame(['Terlambat ≤ 30 menit', 'Terlambat > 30 menit', 'Sakit', 'Izin'], array_column($example['rows'], 'name'));
        $this->assertSame(4, $example['reducingCount']);
        $this->assertSame('2 × 1 + 1 × 3 + 1 × 0 + 1 × 5', $example['penaltyTerms']);
        $this->assertSame('81,82', $example['basePct']);
        $this->assertSame('71,82', $example['finalPct']);
        $this->assertSame('10,77', $example['attendanceScore']);
        $this->assertSame('85,77', $example['total']);
        $this->assertNull($this->viewData($this->staff)['nextExample']);

        // Versi baru mulai Januari 2027 dengan bobot dan penalti lain: contoh langsung mengikuti.
        $this->travelTo(Date::create(2027, 1, 10, 9));
        $this->createRule('2027-01', 'Aturan Uji Januari 2027', 60, 20, 20, [
            ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 2],
            ['component' => 'late_more_30', 'reduces_base' => true, 'penalty_points' => 4],
            ['component' => 'sick_days', 'reduces_base' => false, 'penalty_points' => 0],
            ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 3],
        ]);
        $example = $this->viewData($this->staff)['example'];

        $this->assertSame('Aturan Uji Januari 2027', $example['ruleName']);
        $this->assertSame(3, $example['reducingCount']);
        $this->assertSame('86,36', $example['basePct']);
        $this->assertSame('11', $example['penalty']);
        $this->assertSame('75,36', $example['finalPct']);
        $this->assertSame('15,07', $example['attendanceScore']);
        $this->assertSame('16', $example['reviewScore']);
        $this->assertSame('54', $example['kpiScore']);
        $this->assertSame('85,07', $example['total']);
    }

    public function test_import_section_lists_the_live_template_columns_per_periode(): void
    {
        $columns = $this->viewData($this->attendanceClerk)['templateColumns'];

        // Semua kolom yang bisa muncul, dengan periode template yang memuatnya (Aturan Lama sebelum November 2026).
        $this->assertSame([
            'ID karyawan' => 'Semua periode',
            'Nama Lengkap' => 'Semua periode',
            'Periode' => 'Semua periode',
            'Hari Kerja' => 'Semua periode',
            'Late Less 30 min' => 'Semua periode',
            'Late More 30 min' => 'Semua periode',
            'Sakit/Izin' => 'Sebelum November 2026',
            'Sakit' => 'Mulai November 2026',
            'Izin [izin]' => 'Mulai November 2026',
            'Mangkir (tanpa keterangan) [mangkir]' => 'Mulai November 2026',
            'Cuti tahunan [cuti_tahunan]' => 'Mulai November 2026',
            'Cuti lainnya [cuti_lainnya]' => 'Mulai November 2026',
        ], array_column($columns, 'periodes', 'heading'));

        // Kolom template sama persis dengan file template tiap periode.
        $this->assertSame(
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit/Izin'],
            (new AttendanceImportTemplateExport([], '2026-09'))->headings(),
        );
        $this->assertSame(
            ['ID karyawan', 'Nama Lengkap', 'Periode', 'Hari Kerja', 'Late Less 30 min', 'Late More 30 min', 'Sakit',
                'Izin [izin]', 'Mangkir (tanpa keterangan) [mangkir]', 'Cuti tahunan [cuti_tahunan]', 'Cuti lainnya [cuti_lainnya]'],
            (new AttendanceImportTemplateExport([], '2026-11'))->headings(),
        );

        $fills = array_column($columns, 'fill', 'heading');
        $this->assertStringContainsString('Sudah terisi periode yang dipilih saat Download Template (format YYYY-MM, mis. 2026-09)', $fills['Periode']);
        $this->assertStringContainsString('sakit ditambah hari izin', $fills['Sakit/Izin']);
        $this->assertStringNotContainsString('Template terisi bulan berjalan', $fills['Periode']);

        $this->render($this->attendanceClerk)
            ->assertSee('Mulai periode November 2026, izin diisi di kolom')
            ->assertSee('Izin [izin]')
            ->assertSeeHtml('mis. "Izin [izin]"')
            ->assertSee('2026-09')
            ->assertSee('attendance_import_template_2026-09.xlsx')
            // Isi form: tulisan kecil di kanan atas isian, sama dengan hint form Kehadiran untuk periode bawaan.
            ->assertSee('"Penalti 5 poin/hari, mengurangi hari kerja", atau "Tanpa penalti"')
            ->assertSee('"Tidak dipakai di aturan periode ini"', false)
            ->assertDontSee('Keterangan di samping tiap isian')
            // Import: alasan dilewati dan peringatan kolom sesuai pesan import.
            ->assertSee('Kolom komponen berisi teks, desimal, angka negatif, atau lebih dari 999.')
            ->assertSee('ID karyawan kosong atau tidak dikenal, dan ada beberapa karyawan dengan nama sama.')
            ->assertSee('Isi atau perbaiki ID karyawan dari template terbaru.')
            ->assertSee('Aman bila kolom itu memang bukan data kehadiran')
            ->assertDontSee('Aman diabaikan')
            ->assertSee('Jangan import ulang file yang sama apa adanya')
            ->assertDontSee('lalu import ulang file yang sama.')
            ->assertSee('hanya untuk tiga kolom lama (Late Less 30 min, Late More 30 min, dan Sakit)');

        // Nama komponen diubah: judul kolom ikut berubah. Komponen baru belum tercantum di versi aturan mana pun,
        // jadi belum ada di template mana pun.
        AttendanceComponent::query()->where('code', 'mangkir')->firstOrFail()->update(['name' => 'Alpa']);
        AttendanceComponent::query()->create([
            'code' => 'dinas_luar',
            'name' => 'Dinas luar',
            'unit' => AttendanceComponent::UNIT_DAY,
            'sort_order' => 20,
            'is_active' => true,
        ]);
        ScoringRuleService::clearCache();

        $headings = array_column($this->viewData($this->attendanceClerk)['templateColumns'], 'heading');

        $this->assertContains('Alpa [mangkir]', $headings);
        $this->assertNotContains('Dinas luar [dinas_luar]', $headings);
        $this->assertNotContains('Mangkir (tanpa keterangan) [mangkir]', $headings);

        // Setelah dicantumkan di versi aturan (penalti 0 boleh), kolomnya ada di template periode versi itu.
        $this->createRule('2027-01', 'Aturan Uji Januari 2027', 70, 15, 15, [
            ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
            ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 5],
            ['component' => 'dinas_luar', 'reduces_base' => false, 'penalty_points' => 0],
        ]);
        $periodes = array_column($this->viewData($this->attendanceClerk)['templateColumns'], 'periodes', 'heading');

        $this->assertSame('Mulai Januari 2027', $periodes['Dinas luar [dinas_luar]']);
        $this->assertSame('November 2026 sampai sebelum Januari 2027', $periodes['Alpa [mangkir]']);
        $this->assertSame('Mulai November 2026', $periodes['Izin [izin]']);
        $this->assertContains('Dinas luar [dinas_luar]', (new AttendanceImportTemplateExport([], '2027-01'))->headings());

        // Nama izin diubah: contoh judul kolom dan data contoh ikut nama baru.
        AttendanceComponent::query()->where('code', 'izin')->firstOrFail()->update(['name' => 'Izin pribadi']);
        ScoringRuleService::clearCache();

        $this->assertContains('Izin pribadi 1 hari', $this->viewData($this->attendanceClerk)['exampleEvents']);
        $this->render($this->attendanceClerk)->assertSeeHtml('mis. "Izin pribadi [izin]"');
    }

    /**
     * SOP 1 dan SOP 2 menjelaskan form Kehadiran, modal Download Template, dan modal Import apa adanya: periode bawaan
     * bulan lalu, isian yang tidak dipakai aturan periode nonaktif, Sakit/Izin, konfirmasi bulan yang belum selesai,
     * dan alasan baris dilewati.
     */
    public function test_attendance_sops_match_the_form_template_and_import(): void
    {
        $data = $this->viewData($this->attendanceClerk);

        $this->assertSame('2026-09', $data['previousPeriode']);
        $this->assertSame(Periode::previous(), $data['previousPeriode']);
        $this->assertSame('September 2026', $data['previousPeriodeLabel']);
        $this->assertTrue($data['previousIzinInSick']);
        $this->assertSame(
            'Aturan penilaian Oktober 2026 belum memakai Izin, tetapi kolomnya diisi. Untuk periode ini izin dicatat di kolom Sakit/Izin: jumlahkan angka Izin ke kolom Sakit/Izin, lalu kosongkan kolom Izin. Bila periodenya yang keliru, perbaiki periodenya.',
            $data['izinUnusedExample'],
        );

        $this->render($this->attendanceClerk)
            ->assertSeeHtml('<strong>Periode</strong> sudah terisi bulan lalu (September 2026)')
            ->assertSeeHtml('isian sakit berlabel <strong>Sakit/Izin</strong>')
            ->assertSee('Isian komponen dikosongkan')
            ->assertSeeHtml('<strong>Buat &amp; buat lainnya</strong>')
            ->assertSee('Periode dan Jumlah Hari Kerja tetap terisi')
            ->assertSeeHtml('<strong>Download Template Kehadiran</strong>, field <strong>Periode</strong> sudah terisi bulan lalu (September 2026)')
            ->assertSeeHtml('pilih <strong>Periode yang diimport</strong> (sudah terisi bulan lalu, September 2026)')
            ->assertSee('Periode ini belum selesai')
            ->assertSee('Periode ini belum dimulai')
            ->assertSee('Saya yakin mengimport data kehadiran periode ...')
            ->assertSee('Import Kehadiran September 2026: 20 data ditambahkan, 1 data dilewati.')
            ->assertSee('File berisi N baris periode [bulan], bukan [bulan] yang dipilih; baris tersebut dilewati.')
            ->assertSee('Tidak ada data [bulan] di file. Periksa keterangan di bawah.')
            ->assertSee('Contoh pesan lengkap: '.$data['izinUnusedExample'])
            ->assertSee('Kenapa import meminta centang konfirmasi?')
            ->assertSee('Kenapa isian komponen di form Kehadiran abu-abu dan tidak bisa diisi?')
            ->assertDontSee('Template terisi bulan berjalan')
            ->assertDontSee('Bila mengisi data bulan lalu, ganti');

        // Aplikasi: form terisi bulan lalu dengan Sakit/Izin dan Izin nonaktif (tulisan yang dikutip panduan).
        $this->signIn($this->attendanceClerk);
        Livewire::test(CreateAttendance::class)
            ->assertFormSet(['periode' => '2026-09'])
            ->assertFormFieldExists('sick_days', fn (TextInput $field): bool => $field->getLabel() === 'Sakit/Izin (hari)')
            ->assertFormFieldDisabled('component_counts.izin')
            ->assertSee(AttendanceCountInput::UNUSED_COMPONENT_HINT)
            ->fillForm(['component_counts.izin' => 0, 'periode' => '2026-11'])
            ->assertFormFieldEnabled('component_counts.izin');

        // Modal Download Template dan Import: periode bawaan bulan lalu; bulan berjalan meminta konfirmasi.
        Livewire::test(ListAttendances::class)
            ->mountAction('downloadTemplate')
            ->assertActionDataSet(['periode' => '2026-09'])
            ->assertMountedActionModalSee('Download Template Kehadiran');
        Livewire::test(ListAttendances::class)
            ->mountAction('import')
            ->assertActionDataSet(['periode' => '2026-09'])
            ->assertMountedActionModalSee('Periode yang diimport')
            ->assertMountedActionModalSee('Import Data Absensi')
            ->setActionData(['periode' => '2026-10'])
            ->assertMountedActionModalSee('Periode ini belum selesai')
            ->assertMountedActionModalSee('Saya yakin mengimport data kehadiran periode Oktober 2026.')
            ->setActionData(['periode' => '2026-11'])
            ->assertMountedActionModalSee('Periode ini belum dimulai');
    }

    /**
     * Pengaturan super admin: komponen baru belum bisa diisi sampai dicantumkan di versi aturan.
     */
    public function test_component_sop_explains_that_new_components_need_a_rule_version(): void
    {
        $this->render($this->superAdmin)
            ->assertSee('Di form Kehadiran isiannya masih nonaktif dengan keterangan bahwa komponen belum dicantumkan di versi aturan mana pun')
            ->assertSee('Komponen yang tercantum di versi ini, termasuk yang penaltinya 0, bisa diisi untuk periode versi ini')
            ->assertDontSee('Komponen langsung muncul di form Kehadiran, template Excel');

        AttendanceComponent::query()->create([
            'code' => 'dinas_luar',
            'name' => 'Dinas luar',
            'unit' => AttendanceComponent::UNIT_DAY,
            'sort_order' => 20,
            'is_active' => true,
        ]);
        ScoringRuleService::clearCache();

        $this->assertFalse(AttendanceCountInput::isListedInAnyRule('dinas_luar'));
        $this->assertNotContains('Dinas luar [dinas_luar]', (new AttendanceImportTemplateExport([], '2026-11'))->headings());
    }

    /**
     * @return array<string, User>
     */
    private function allUsers(): array
    {
        return [
            'super admin' => $this->superAdmin,
            'admin per badan usaha' => $this->scopedAdmin,
            'atasan' => $this->manager,
            'staf' => $this->staff,
            'user khusus kehadiran' => $this->attendanceClerk,
        ];
    }

    private function signIn(User $user): void
    {
        $this->resetAccessMemos();
        $this->actingAs($user);
    }

    private function render(User $user): Testable
    {
        $this->signIn($user);

        return Livewire::test(ScoringRulesGuide::class)->assertSuccessful();
    }

    /**
     * Data view panduan untuk user ini (getViewData() sengaja protected).
     *
     * @return array<string, mixed>
     */
    private function viewData(User $user): array
    {
        $page = $this->render($user)->instance();
        $method = new ReflectionMethod($page, 'getViewData');

        return $method->invoke($page);
    }

    /**
     * @param  list<array{component: string, reduces_base: bool, penalty_points: int|float}>|null  $attendanceRules
     */
    private function createRule(string $effectiveFrom, string $name, float $kpi, float $attendance, float $review, ?array $attendanceRules = null): void
    {
        ScoringRule::query()->create([
            'name' => $name,
            'effective_from' => $effectiveFrom,
            'kpi_weight' => $kpi,
            'attendance_weight' => $attendance,
            'review_weight' => $review,
            'review_max_points' => 20,
            'attendance_rules' => $attendanceRules ?? [
                ['component' => 'late_less_30', 'reduces_base' => true, 'penalty_points' => 1],
                ['component' => 'izin', 'reduces_base' => false, 'penalty_points' => 5],
            ],
        ]);

        ScoringRuleService::clearCache();
        $this->resetAccessMemos();
    }
}
