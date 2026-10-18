<?php

namespace Tests\Feature;

use App\Filament\Pages\KpiStaffGuide;
use App\Filament\Widgets\ChecklistKPI;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\KpiType;
use App\Models\User;
use App\Services\WhatsAppOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\CreatesDataAccessFixtures;
use Tests\TestCase;

/**
 * Panduan KPI Operasional (/admin/panduan-kpi-bawahan).
 *
 * - SOP 1 menjelaskan dua cara masuk: Username + Kata sandi, dan WhatsApp OTP (halaman /phone-login).
 * - Label yang dikutip panduan harus sama dengan label aplikasi (login WhatsApp, Checklist KPI, Update KPI,
 *   Extra Task), jadi teks panduan dicocokkan dengan sumber tampilannya.
 * - GIF di public/images/kpi-guides direkam ulang lewat tools/guide-gifs/flows/kpi-operasional.js (1100x618).
 */
class KpiStaffGuideTest extends TestCase
{
    use CreatesDataAccessFixtures;
    use RefreshDatabase;

    private const URL = '/admin/panduan-kpi-bawahan';

    /** GIF => keterangan di bawahnya, sesuai urutan di halaman. */
    private const GIFS = [
        'staff-login-flow.gif' => 'GIF 1. Login dengan WhatsApp OTP sampai tab Checklist KPI',
        'staff-checklist-flow.gif' => 'GIF 2. Mengisi checklist bulanan: pilih bulan, centang NON, isi actual RESULT, lalu cek Result dan Total Score',
    ];

    /** Teks yang dikutip panduan, per file sumber tampilan aplikasi yang memuatnya. */
    private const QUOTED_UI_TEXT = [
        'resources/views/auth/login-extra.blade.php' => ['Atau masuk dengan', 'WhatsApp'],
        'app/Filament/Auth/Pages/PhoneLogin.php' => [
            'Masuk dengan WhatsApp',
            'Nomor WhatsApp',
            'Kirim OTP WhatsApp',
            'Kode OTP',
            'Masukkan 6 digit kode yang dikirim ke WhatsApp.',
            'Verifikasi dan Masuk',
            'Ganti nomor atau kirim ulang OTP',
            'Nomor WhatsApp tidak valid.',
            'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
            'OTP tidak valid atau sudah kedaluwarsa.',
            'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.',
            'Terlalu banyak percobaan OTP. Coba lagi sebentar lagi.',
        ],
        'app/Filament/Auth/Concerns/InteractsWithWhatsAppLogin.php' => ['No. HP belum terdaftar untuk login WhatsApp.'],
        'resources/views/filament/widgets/checklist-kpi.blade.php' => [
            'Category Percentage',
            'Skema: NON',
            'Skema: Positif',
            'Skema: Negatif',
            'Batas pengisian',
            'Sisa {{ $deadlineInfo[\'daysLeft\'] }} hari',
            'Ditutup sejak',
            'Update KPI',
            'Value Actual Baru',
            'Simpan',
            'Negatif',
            'label="Extra Task"',
            'Tambah Extra Task',
            'Deskripsi Extra Task',
            'Count Type',
            'Actual Value',
            'label="Hapus Extra Task"',
            'Total',
        ],
        'app/Filament/Resources/Users/UserResource.php' => ["->label('No. HP')", "navigationLabel = 'Karyawan'"],
    ];

    /** Label lama yang tidak ada di aplikasi. */
    private const STALE_TEXT = [
        '<strong>Save</strong>',
        '<strong>Submit</strong>',
        'Extra Task Description',
        '(format month)',
        'admin panel',
        'KPI NEGATIVE',
        'ikon <strong>trash</strong>',
        'Flow login personel operasional',
        'filter masih kosong',
    ];

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetAccessMemos();

        $uls = $this->fixtureArea('ULS');
        $leader = $this->fixtureUser('uls_leader', 'TEAM LEADER', $uls);
        $this->staff = $this->fixtureUser('uls_kasir', 'STAFF', $uls, $leader);

        $this->resetAccessMemos();
    }

    protected function tearDown(): void
    {
        $this->resetAccessMemos();
        parent::tearDown();
    }

    public function test_page_is_registered_in_panduan_group(): void
    {
        $this->assertSame('Panduan KPI Operasional', KpiStaffGuide::getNavigationLabel());
        $this->assertSame('Panduan', KpiStaffGuide::getNavigationGroup());
        $this->assertSame('panduan-kpi-bawahan', KpiStaffGuide::getSlug());
    }

    public function test_guest_is_redirected_and_staff_sees_both_login_options(): void
    {
        $this->get(self::URL)->assertRedirect();

        $this->actingAs($this->staff);

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('Login (Username atau WhatsApp OTP) + Pengisian Checklist + Validasi Bulanan')
            ->assertSeeInOrder([
                'SOP 1 - Login dan Akses Checklist KPI',
                'Opsi A - Username dan Kata sandi',
                'Masuk ke akun Anda',
                'Username',
                'Kata sandi',
                'Masuk',
                'Opsi B - WhatsApp OTP',
                'Atau masuk dengan',
                'Masuk dengan WhatsApp',
                'Nomor WhatsApp',
                'Kirim OTP WhatsApp',
                'Kode OTP',
                'Verifikasi dan Masuk',
                'Dasbor',
                'Checklist KPI',
                'Catatan login WhatsApp:',
                'No. HP',
                'Karyawan',
                'Ganti nomor atau kirim ulang OTP',
            ])
            ->assertSeeInOrder([
                '4) Ikon Extra Task Tidak Muncul',
                '5) Login WhatsApp Gagal',
                'Nomor WhatsApp tidak valid.',
                'No. HP belum terdaftar untuk login WhatsApp.',
                'OTP tidak valid atau sudah kedaluwarsa.',
                'Terlalu banyak percobaan OTP. Coba lagi sebentar lagi.',
                'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.',
                'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
            ])
            // GIF 1 menampilkan pesan OTP sebagai kartu di samping form: panduan menjelaskan bahwa itu pesan di HP.
            ->assertSee('Pesan WhatsApp di HP Anda (contoh)')
            ->assertSee('bukan bagian halaman login');
    }

    public function test_every_whatsapp_login_error_message_is_explained_in_kendala_5(): void
    {
        $phoneLogin = file_get_contents(base_path('app/Filament/Auth/Pages/PhoneLogin.php'));
        $concern = file_get_contents(base_path('app/Filament/Auth/Concerns/InteractsWithWhatsAppLogin.php'));

        // ValidationException::withMessages(['data.x' => '...']) dan consumeRateLimits(..., 'data.x', '...').
        preg_match_all("/'data\\.\w+'\s*(?:=>|,)\s*'([^']+)'/", $phoneLogin, $direct);
        preg_match("/function unavailableWhatsAppMessage\(\)[^{]*\{\s*return '([^']+)';/", $concern, $unavailable);

        $messages = array_values(array_unique([...$direct[1], $unavailable[1] ?? '']));
        $this->assertNotContains('', $messages, 'Pesan unavailableWhatsAppMessage() tidak ditemukan.');
        $this->assertCount(6, $messages, 'Jumlah pesan error login WhatsApp berubah: perbarui Kendala 5 di Panduan KPI Operasional.');

        $this->actingAs($this->staff);
        $html = $this->get(self::URL)->assertOk()->getContent();
        $kendala = substr($html, strpos($html, '5) Login WhatsApp Gagal'));

        foreach ($messages as $message) {
            $this->assertStringContainsString(e($message), $kendala, "Kendala 5 belum menjelaskan \"{$message}\".");
        }
    }

    public function test_checklist_month_filter_opens_on_the_current_month(): void
    {
        // SOP 2 langkah 2 dan GIF 2: filter bulan (<input type="month">, format Y-m) langsung berisi bulan berjalan,
        // dan bulan sebelumnya tampil terkunci ("Ditutup sejak").
        $this->travelTo(Carbon::create(2026, 10, 18, 9));

        foreach ([1 => 'ADMINISTRATION', 2 => 'REPORTING', 3 => 'MAIN JOB'] as $id => $name) {
            KpiCategory::query()->forceCreate(['id' => $id, 'name' => $name]);
        }
        KpiType::query()->forceCreate(['id' => 3, 'name' => 'Bulanan']);

        $this->makeKpi('Tugas Oktober', '2026-10-01');
        $this->makeKpi('Tugas September', '2026-09-01');

        $this->actingAs($this->staff);
        Livewire::actingAs($this->staff);

        Livewire::test(ChecklistKPI::class)
            ->assertSet('month', '2026-10')
            ->assertSeeHtml('max="2026-10"')
            ->assertSee('October 2026')
            ->assertSee('Batas pengisian 05 Nov 2026')
            ->assertSee('Tugas Oktober')
            ->assertDontSee('Tugas September')
            ->set('month', '2026-09')
            ->assertSee('Ditutup sejak 05 Oct 2026')
            ->assertSee('Tugas September')
            ->assertDontSee('Tugas Oktober')
            ->set('month', '2026-10')
            ->assertSee('Tugas Oktober');
    }

    public function test_checklist_steps_use_current_labels(): void
    {
        $this->actingAs($this->staff);

        $page = Livewire::test(KpiStaffGuide::class)
            ->assertSuccessful()
            ->assertSeeInOrder([
                'SOP 2 - Alur End-to-End Pengisian Checklist KPI Bulanan',
                'Oktober 2026',
                'Category Percentage',
                'Skema',
                'Update KPI',
                'Value Actual Baru',
                'Simpan',
                'Total Score',
                'Batas pengisian',
                'Sisa N hari',
            ])
            ->assertSee('Total Score <strong>91.00</strong>', false)
            ->assertSeeInOrder([
                'SOP 3 - Extra Task (Opsional)',
                'ikon plus oranye',
                'Tambah Extra Task',
                'Deskripsi Extra Task',
                'Count Type',
                'Actual Value',
                'Simpan',
                'Hapus Extra Task',
            ])
            ->assertSee('Ditutup sejak');

        foreach (self::STALE_TEXT as $stale) {
            $page->assertDontSee($stale, false);
        }
    }

    public function test_gifs_exist_at_recording_size_and_are_captioned_in_order(): void
    {
        $this->actingAs($this->staff);

        $page = Livewire::test(KpiStaffGuide::class)->assertSuccessful();

        foreach (array_keys(self::GIFS) as $gif) {
            $path = 'images/kpi-guides/'.$gif;

            $this->assertFileExists(public_path($path));
            $size = getimagesize(public_path($path));
            $this->assertSame([1100, 618], [$size[0], $size[1]], $gif.' harus direkam 1100x618 (tools/guide-gifs).');
            $page->assertSeeHtml('<img src="'.asset($path).'"');
        }

        $page->assertSeeInOrder(array_values(self::GIFS));
    }

    public function test_quoted_ui_texts_match_the_app(): void
    {
        foreach (self::QUOTED_UI_TEXT as $file => $texts) {
            $source = file_get_contents(base_path($file));

            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $source, "{$file} tidak lagi memuat \"{$text}\": sesuaikan Panduan KPI Operasional.");
            }
        }

        // Angka yang disebut panduan: 5 kali salah, 3 permintaan per 5 menit dan 10 per jam, 15 percobaan per 5 menit,
        // batas tanggal 5 bulan berikutnya.
        $this->assertSame(5, WhatsAppOtpService::MAX_ATTEMPTS);
        $phoneLogin = file_get_contents(base_path('app/Filament/Auth/Pages/PhoneLogin.php'));
        $this->assertMatchesRegularExpression('/whatsapp-otp:filament:number:5m:\{\$numberKey\}", 3, 300\]/', $phoneLogin);
        $this->assertMatchesRegularExpression('/whatsapp-otp:filament:number:1h:\{\$numberKey\}", 10, 3600\]/', $phoneLogin);
        $this->assertMatchesRegularExpression('/whatsapp-verify:filament:number:5m:\{\$numberKey\}", 15, 300\]/', $phoneLogin);
        $this->assertSame(5, (int) config('kpi.checklist_lock_days', 5));
    }

    private function makeKpi(string $description, string $date): Kpi
    {
        $kpiDescription = KpiDescription::query()->create([
            'kpi_category_id' => 3,
            'description' => $description,
            'is_negative' => false,
        ]);

        $kpi = Kpi::query()->create([
            'user_id' => $this->staff->id,
            'kpi_category_id' => 3,
            'kpi_type_id' => 3,
            'date' => $date,
            'percentage' => 100,
        ]);

        $kpi->kpi_detail()->create([
            'kpi_description_id' => $kpiDescription->id,
            'count_type' => 'NON',
        ]);

        return $kpi;
    }
}
