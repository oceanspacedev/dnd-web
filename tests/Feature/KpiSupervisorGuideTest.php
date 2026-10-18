<?php

namespace Tests\Feature;

use App\Filament\Pages\KpiSupervisorGuide;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Panduan KPI Manajerial (/admin/panduan-kpi-atasan).
 *
 * The GIFs in public/images/kpi-guides are recorded from the demo app by tools/guide-gifs/flows/kpi-manajerial.js;
 * the text has to name the same buttons, messages and login steps the GIFs show.
 */
class KpiSupervisorGuideTest extends TestCase
{
    use RefreshDatabase;

    /** In page order: GIF 1 (SOP 1), the four error GIFs, GIF 2 (SOP 2). */
    private const GIFS = [
        'kpi-flow-lengkap.gif',
        'kpi-error-required-fields.gif',
        'kpi-error-kpi-description.gif',
        'kpi-error-value-plan.gif',
        'kpi-error-percentage-total.gif',
        'supervisor-review-flow.gif',
    ];

    public function test_page_is_configured_as_panduan_kpi_manajerial(): void
    {
        $this->assertSame('Panduan KPI Manajerial', KpiSupervisorGuide::getNavigationLabel());
        $this->assertSame('Panduan', KpiSupervisorGuide::getNavigationGroup());
        $this->assertSame('panduan-kpi-atasan', KpiSupervisorGuide::getSlug());
        $this->assertSame('Panduan KPI Manajerial', (new KpiSupervisorGuide)->getTitle());
    }

    public function test_guide_renders_every_gif_in_order_with_its_caption(): void
    {
        $page = $this->renderGuide();

        foreach (self::GIFS as $gif) {
            $path = 'images/kpi-guides/'.$gif;

            $this->assertFileExists(public_path($path));
            $page->assertSeeHtml('<img src="'.asset($path).'"');

            // Re-recorded at the guide-gifs size (the old slideshows were a different size).
            [$width, $height] = getimagesize(public_path($path));
            $this->assertSame([1100, 618], [$width, $height], $gif);
        }

        $page->assertSeeHtmlInOrder(array_map(fn (string $gif) => asset('images/kpi-guides/'.$gif), self::GIFS));
        $page->assertSeeInOrder(['GIF 1.', 'GIF 2.']);
        $page->assertSee('GIF 2. Masuk lewat WhatsApp (kode OTP), lalu input penilaian bulanan untuk anggota tim');
    }

    public function test_sop_1_names_the_current_kpi_form_controls(): void
    {
        $this->renderGuide()
            ->assertSee('Atasan (Team Leader s.d. BOD) yang memiliki anggota tim, dan Admin')
            ->assertSeeHtml('tombol <strong>WhatsApp</strong> dengan kode OTP')
            ->assertSeeHtml('klik tombol <strong>Buat kpi</strong> di kanan atas')
            ->assertSeeHtml('klik <strong>Tambahkan ke kPI Descriptions</strong>')
            ->assertSeeHtml('klik ikon <strong>+</strong> di samping kolom <strong>Deskripsi</strong> (KPI Description)')
            ->assertSeeHtml('nyalakan <strong>Lower is Better (Negative KPI)</strong>')
            ->assertSeeHtml('misalnya <strong>10/2026</strong>')
            ->assertSeeHtml('Klik <strong>Buat</strong> di bawah form')
            ->assertSeeHtml('notifikasi <strong>KPI created</strong>')
            ->assertSee('Sistem tidak menolak KPI ganda')
            // CreateKpi skips a category whose tab has no rows but still reports "KPI created".
            ->assertSee('berisi minimal satu baris KPI')
            ->assertSee('satu baris per kategori yang berisi indikator')
            // The KPI date takes today's day number (m/Y parsing), so the 29th-31st can roll into the next month.
            ->assertSeeHtml('<strong>Hindari membuat KPI pada tanggal 29-31</strong>')
            ->assertDontSee('tersimpan sebagai bulan/tahun')
            // GIF 1 adds no subtasks, so the example table marks them as optional.
            ->assertSee('Subtasks (opsional)')
            ->assertDontSee('Create option')
            ->assertDontSee('Penanggung Jawab');
    }

    public function test_error_scenarios_quote_the_messages_the_app_shows(): void
    {
        $this->renderGuide()
            ->assertSeeHtml('<strong>Please fill out this field.</strong>')
            ->assertSeeHtml('<strong>The job Position field is required. (and 1 more error)</strong>')
            ->assertSee('menyebut field kosong pertama; bila keduanya kosong (seperti di GIF)')
            ->assertSee('pastikan setiap tab berisi minimal satu baris KPI')
            ->assertSee('tab tanpa baris KPI tidak memunculkan error')
            ->assertSeeHtml('<strong>The kPI Description field is required.</strong>')
            ->assertSeeHtml('<strong>Value must be greater than or equal to 1.</strong>')
            ->assertSeeHtml('notifikasi <strong>Validation Error</strong> berisi <strong>Total percentage must be 100%</strong>')
            ->assertSeeHtml('notifikasi <strong>Error occurred</strong> tanpa keterangan');
    }

    public function test_sop_2_covers_whatsapp_otp_login_and_the_review_form(): void
    {
        $this->renderGuide()
            ->assertSeeInOrder(['Atau masuk dengan', 'Nomor WhatsApp', 'Kirim OTP WhatsApp', 'Kode OTP', 'Verifikasi dan Masuk', 'Ganti nomor atau kirim ulang OTP'])
            ->assertSeeHtml('klik <strong>Buat employee review</strong>')
            ->assertSee('menampilkan nomor dalam format 62')
            ->assertSee('otomatis terisi bulan berjalan (contoh: Oktober 2026)')
            // The list headers differ from the form labels (Pemecah Masalah / Kepedulian).
            ->assertSeeHtml('kolom <strong>Pemecahan Masalah</strong>')
            ->assertSeeHtml('kolom <strong>Kesediaan Membantu</strong>')
            ->assertSeeHtml('notifikasi <strong>Data berhasil dibuat</strong>')
            ->assertSeeHtml('<strong>Poin Maksimal Penilaian</strong>')
            ->assertSeeHtml('Klik tombol <strong>Copy/Export</strong>')
            ->assertDontSee('YYYY-MM');
    }

    private function renderGuide(): Testable
    {
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $admin = User::query()->create([
            'nama_lengkap' => 'Admin Test',
            'username' => 'adm_'.uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $adminRole->id,
            'area_id' => 1,
            'divisi_id' => 1,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $this->actingAs($admin);

        return Livewire::test(KpiSupervisorGuide::class)->assertSuccessful();
    }
}
