<x-filament-panels::page>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Buku Panduan Operasional KPI - Personel Operasional</x-slot>
            <x-slot name="description">
                Dokumen kerja end-to-end untuk pengisian checklist KPI bulanan agar data kinerja valid dan tepat waktu.
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Versi Dokumen</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">v1.1</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target Pembaca</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Personel / Kontributor / Team Member</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cakupan</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Login + Pengisian Checklist + Validasi Bulanan</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 1 - Login dan Akses Checklist KPI</x-slot>
            <x-slot name="description">Langkah awal sebelum masuk ke proses pengisian KPI.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Buka halaman login admin panel.</li>
                <li>Masukkan username dan password akun Anda.</li>
                <li>Klik tombol <strong>Masuk</strong>.</li>
                <li>Pastikan berhasil masuk ke dashboard.</li>
                <li>Buka tab <strong>Checklist KPI</strong>.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/staff-login-flow.gif') }}"
                    alt="GIF flow login personel operasional"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 1. Flow login personel operasional
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 2 - Alur End-to-End Pengisian Checklist KPI Bulanan</x-slot>
            <x-slot name="description">Ikuti urutan ini dari awal sampai final review agar semua indikator bulan tersebut selesai diisi.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Buka tab <strong>Checklist KPI</strong>.</li>
                <li>Pilih periode di filter <strong>Bulan</strong> (format month).</li>
                <li>Pastikan tabel KPI muncul per kategori: <strong>MAIN JOB</strong>, <strong>ADMINISTRATION</strong>, <strong>REPORTING</strong>.</li>
                <li>Baca tiap baris: <strong>Description</strong>, <strong>Type</strong>, <strong>Plan</strong>, <strong>Start</strong>, dan <strong>End</strong>.</li>
                <li>Untuk tipe <strong>NON</strong>, klik tombol status (ikon centang) untuk toggle selesai/belum selesai.</li>
                <li>Untuk tipe <strong>RESULT</strong>, klik aksi update, isi <strong>Value Actual</strong>, lalu klik <strong>Save</strong>.</li>
                <li>Pastikan kolom <strong>Result</strong> berubah otomatis setelah simpan.</li>
                <li>Ulangi langkah hingga semua indikator pada bulan tersebut terisi.</li>
                <li>Jika KPI detail tertentu tidak tercapai karena ada penugasan lain yang diminta untuk diselesaikan, gunakan <strong>Extra Task</strong> untuk mencatat pekerjaan pengganti tersebut.</li>
                <li>Lakukan pengecekan akhir: tidak ada indikator yang terlewat, nilai actual sudah benar, dan hasil prosentase sudah muncul.</li>
                <li>Selesaikan input sebelum batas pengisian periode berakhir.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold">Rumus result yang dipakai sistem:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li><strong>NON</strong>: selesai = 1 (100%), belum selesai = 0 (0%).</li>
                    <li><strong>RESULT Positif</strong>: result = actual / plan.</li>
                    <li><strong>RESULT Negatif</strong>: result = plan / actual (actual minimum dihitung 1).</li>
                </ul>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/staff-checklist-flow.gif') }}"
                    alt="GIF flow pengisian checklist KPI personel operasional"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 2. Flow end-to-end pengisian checklist KPI
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Panduan Kolom Checklist KPI</x-slot>
            <x-slot name="description">Fungsi tiap kolom agar pengisian tidak salah interpretasi.</x-slot>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Kolom</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Cara Membaca / Isi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Status</td>
                            <td class="px-4 py-3">Aksi utama untuk update KPI: toggle untuk NON, buka modal update untuk RESULT.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Description</td>
                            <td class="px-4 py-3">Deskripsi indikator dari penanggung jawab, termasuk subtasks jika tersedia.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Start / End</td>
                            <td class="px-4 py-3">Informasi rentang waktu dari penyusun KPI, bisa kosong dan tidak memblokir pengisian checklist.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Type</td>
                            <td class="px-4 py-3"><strong>NON</strong> untuk selesai/tidak, <strong>RESULT</strong> untuk input nilai actual numerik.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Plan</td>
                            <td class="px-4 py-3">Target nilai untuk KPI bertipe RESULT.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Actual</td>
                            <td class="px-4 py-3">Nilai aktual yang Anda isi pada modal update KPI.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Result</td>
                            <td class="px-4 py-3">Hasil perhitungan otomatis sistem berdasarkan type, plan, dan actual.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Action</td>
                            <td class="px-4 py-3">Tombol <strong>Extra Task</strong> digunakan saat KPI detail belum tercapai karena ada pekerjaan lain yang diminta diselesaikan, dan kondisi tombol tersedia (RESULT, result &gt; 0, result &lt; 100%, periode terbuka).</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Aturan Penting Pengisian</x-slot>
            <x-slot name="description">Aturan sistem yang harus dipahami sebelum dan saat mengisi KPI.</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/30 dark:bg-amber-500/10">
                    <h3 class="text-sm font-semibold text-amber-900 dark:text-amber-200">Deadline Periode</h3>
                    <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">
                        Checklist KPI hanya dapat diubah sampai <strong>tanggal 5 bulan berikutnya</strong> dari periode KPI.
                    </p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Field Start / End</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        <strong>Start</strong> dan <strong>End</strong> bersifat informatif pada checklist. Keduanya bisa kosong dan bukan syarat wajib untuk mengisi result.
                    </p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Update KPI RESULT</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Gunakan aksi update pada baris KPI RESULT.</li>
                        <li>Isi <strong>Value Actual</strong> angka valid (boleh desimal).</li>
                        <li>Klik <strong>Save</strong>, lalu cek kolom Result berubah.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Checklist Pribadi Akhir</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Semua indikator bulan berjalan sudah diisi.</li>
                        <li>Result untuk tipe RESULT sudah muncul.</li>
                        <li>Status NON sudah sesuai progres kerja aktual.</li>
                        <li>Jika pakai extra task, detail tambahan sudah benar.</li>
                        <li>Tidak ada indikator yang terlewat.</li>
                    </ul>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 3 - Extra Task (Opsional)</x-slot>
            <x-slot name="description">Gunakan fitur ini saat KPI detail belum tercapai karena ada pekerjaan lain dari penanggung jawab yang harus diprioritaskan.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Identifikasi KPI RESULT yang tidak tercapai karena ada pekerjaan pengganti dari penanggung jawab.</li>
                <li>Pada baris KPI tersebut, klik tombol <strong>Extra Task</strong>.</li>
                <li>Isi <strong>Extra Task Description</strong> dengan nama pekerjaan pengganti yang benar-benar dikerjakan.</li>
                <li>Pilih <strong>Count Type</strong>:
                    <strong>NON</strong> untuk checklist selesai/tidak,
                    <strong>RESULT</strong> untuk input angka.
                </li>
                <li>Jika memilih RESULT, isi <strong>Actual Value</strong>.</li>
                <li>Klik <strong>Submit</strong>.</li>
                <li>Cek baris <strong>Extra Task</strong> muncul di bawah KPI parent dan nilai parent ikut diperbarui.</li>
                <li>Jika ada salah input, hapus extra task dengan ikon <strong>trash</strong> selama periode masih terbuka.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-gray-900/30 dark:text-gray-300">
                <p class="font-semibold text-gray-900 dark:text-gray-100">Catatan kontrol data:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Gunakan extra task untuk penugasan pengganti yang benar-benar terjadi, bukan sekadar menutup KPI yang tidak tercapai.</li>
                    <li>Deskripsi extra task harus spesifik agar alasan penggantian pekerjaan mudah diaudit.</li>
                </ul>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Skenario Kendala & Solusi Cepat</x-slot>
            <x-slot name="description">Rujukan jika saat pengisian ada perilaku yang terlihat tidak sesuai.</x-slot>

            <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">1) KPI Tidak Muncul di Checklist</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>Penyebab umum</strong>: filter bulan tidak sesuai periode KPI.</li>
                        <li><strong>Solusi</strong>: ubah filter bulan, lalu cek ulang.</li>
                        <li><strong>Jika tetap kosong</strong>: koordinasikan dengan penanggung jawab agar KPI periode tersebut dibuat/ditugaskan.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">2) Tombol Menjadi Ikon Kunci (Locked)</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>Penyebab umum</strong>: periode checklist sudah ditutup sistem.</li>
                        <li><strong>Aturan sistem</strong>: edit dibatasi sampai tanggal 5 bulan berikutnya dari periode KPI.</li>
                        <li><strong>Solusi</strong>: minta bantuan penanggung jawab/admin jika ada kebutuhan koreksi setelah periode ditutup.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">3) Hasil Result Tidak Berubah</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>Penyebab umum</strong>: value actual belum tersimpan atau nilai yang dimasukkan tidak sesuai.</li>
                        <li><strong>Solusi</strong>: ulangi aksi update, pastikan field <strong>Value Actual</strong> terisi angka, lalu klik <strong>Save</strong>.</li>
                        <li><strong>Catatan</strong>: untuk KPI NEGATIVE, actual lebih kecil akan menghasilkan result lebih baik.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">4) Tombol Extra Task Tidak Muncul</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>Penyebab umum</strong>: KPI bukan tipe RESULT, hasil belum diinput, atau periodenya sudah terkunci.</li>
                        <li><strong>Aturan tampil</strong>: tombol muncul untuk KPI RESULT dengan result di atas 0 namun masih di bawah 100%.</li>
                        <li><strong>Solusi</strong>: pastikan result KPI sudah terisi dan masih dalam periode edit.</li>
                    </ul>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Aksi Cepat</x-slot>

            <div class="flex flex-wrap gap-3">
                <x-filament::button tag="a" href="{{ $this->getChecklistUrl() }}" icon="heroicon-m-clipboard-document-check">
                    Buka Checklist KPI
                </x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getLeaderboardUrl() }}" color="gray" icon="heroicon-m-trophy">
                    Lihat Leaderboard
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
