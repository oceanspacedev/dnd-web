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
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">v1.2</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target Pembaca</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Personel / Kontributor / Team Member</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cakupan</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Login (Username atau WhatsApp OTP) + Pengisian Checklist + Validasi Bulanan</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 1 - Login dan Akses Checklist KPI</x-slot>
            <x-slot name="description">Pilih salah satu cara masuk: Username dan Kata sandi, atau WhatsApp OTP untuk akun yang No. HP WhatsApp-nya sudah terdaftar.</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Opsi A - Username dan Kata sandi</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Buka halaman login DnD (<strong>Masuk ke akun Anda</strong>).</li>
                        <li>Isi <strong>Username</strong> dan <strong>Kata sandi</strong> akun Anda.</li>
                        <li>Klik tombol <strong>Masuk</strong>.</li>
                    </ol>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Opsi B - WhatsApp OTP</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Di halaman login, klik tombol <strong>WhatsApp</strong> di bawah tulisan <strong>Atau masuk dengan</strong>.</li>
                        <li>Di halaman <strong>Masuk dengan WhatsApp</strong>, isi <strong>Nomor WhatsApp</strong> yang terdaftar, misalnya 081234567890 (awalan 08, 62, atau +62 sama-sama diterima).</li>
                        <li>Klik <strong>Kirim OTP WhatsApp</strong>. Nomor Anda tampil dalam format 62&hellip; dan kolom <strong>Kode OTP</strong> muncul.</li>
                        <li>Buka pesan WhatsApp di HP Anda yang berisi kode OTP 6 digit, isi kode itu di <strong>Kode OTP</strong>, lalu klik <strong>Verifikasi dan Masuk</strong>.</li>
                    </ol>
                </div>
            </div>

            <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Setelah berhasil masuk, halaman <strong>Dasbor</strong> terbuka dengan tab <strong>Checklist KPI</strong> langsung aktif.</li>
                <li>Jika yang terbuka tab <strong>Leaderboard KPI</strong>, klik tab <strong>Checklist KPI</strong>.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold">Catatan login WhatsApp:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Tombol <strong>WhatsApp</strong> hanya tampil saat layanan WhatsApp aktif. Jika tidak tampil, masuk dengan Username dan Kata sandi.</li>
                    <li>Nomor WhatsApp diambil dari kolom <strong>No. HP</strong> di data <strong>Karyawan</strong> dan hanya dapat diisi atau diubah oleh admin.</li>
                    <li>Kode OTP hanya berlaku singkat (lihat keterangan <em>Berlaku &hellip;</em> di pesan WhatsApp) dan hanya bisa dipakai sekali. Jika kode kedaluwarsa atau nomor salah, klik <strong>Ganti nomor atau kirim ulang OTP</strong>.</li>
                    <li>Lupa kata sandi? Gunakan WhatsApp OTP bila No. HP sudah terdaftar, atau hubungi admin.</li>
                    <li>Jangan bagikan kode OTP kepada siapa pun, termasuk atasan atau admin.</li>
                </ul>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/staff-login-flow.gif') }}"
                    alt="GIF login personel operasional dengan WhatsApp OTP sampai tab Checklist KPI"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 1. Login dengan WhatsApp OTP sampai tab Checklist KPI
                </p>
                <p class="mt-1 text-center text-xs text-gray-500 dark:text-gray-400">
                    Kartu <em>Pesan WhatsApp di HP Anda (contoh)</em> di GIF hanya ilustrasi pesan yang masuk ke WhatsApp di HP Anda, bukan bagian halaman login.
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 2 - Alur End-to-End Pengisian Checklist KPI Bulanan</x-slot>
            <x-slot name="description">Ikuti urutan ini dari awal sampai final review agar semua indikator bulan tersebut selesai diisi.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Buka tab <strong>Checklist KPI</strong>.</li>
                <li>Pilih periode di filter bulan (pojok kanan atas kartu <strong>Checklist KPI</strong>). Saat dibuka, filter langsung berisi bulan berjalan, misalnya <strong>Oktober 2026</strong>. Checklist bulan sebelumnya masih bisa diisi sampai tanggal 5 bulan berjalan; setelah itu bertanda <strong>Ditutup sejak &lt;tanggal&gt;</strong> dan hanya bisa dilihat, seperti September 2026 di GIF 2. Jadi pada tanggal 1-5, pilih bulan lalu lebih dulu untuk menyelesaikan checklist yang belum lengkap, lalu kembali ke bulan berjalan.</li>
                <li>Pastikan tabel KPI muncul per kategori: <strong>MAIN JOB</strong>, <strong>ADMINISTRATION</strong>, <strong>REPORTING</strong>, masing-masing dengan <strong>Category Percentage</strong>.</li>
                <li>Baca tiap baris: <strong>Description</strong> beserta keterangan <strong>Skema</strong> (NON, Positif, atau Negatif), <strong>Type</strong>, <strong>Plan</strong>, <strong>Start</strong>, dan <strong>End</strong>.</li>
                <li>Untuk tipe <strong>NON</strong>, klik ikon centang di kolom Status untuk toggle selesai/belum selesai. Ikon merah berubah menjadi hijau dan Result menjadi <strong>100%</strong>.</li>
                <li>Untuk tipe <strong>RESULT</strong>, klik ikon centang di kolom Status untuk membuka jendela <strong>Update KPI</strong>, isi <strong>Value Actual Baru</strong>, lalu klik <strong>Simpan</strong>.</li>
                <li>Pastikan kolom <strong>Actual</strong> dan <strong>Result</strong> berubah otomatis setelah simpan, ikon status menjadi hijau, dan <strong>Total Score</strong> di bagian bawah ikut diperbarui.</li>
                <li>Ulangi langkah hingga semua indikator pada bulan tersebut terisi.</li>
                <li>Jika KPI detail tertentu tidak tercapai karena ada penugasan lain yang diminta untuk diselesaikan, gunakan <strong>Extra Task</strong> untuk mencatat pekerjaan pengganti tersebut.</li>
                <li>Lakukan pengecekan akhir: tidak ada indikator yang terlewat, nilai actual sudah benar, dan hasil prosentase sudah muncul.</li>
                <li>Selesaikan input sebelum batas pengisian berakhir. Tanggalnya tampil di label <strong>Batas pengisian</strong> di samping nama bulan, dan berubah menjadi <strong>Sisa N hari</strong> saat tinggal 5 hari atau kurang.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold">Rumus result yang dipakai sistem:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li><strong>NON</strong>: selesai = 1 (100%), belum selesai = 0 (0%).</li>
                    <li><strong>RESULT Positif</strong>: result = actual / plan.</li>
                    <li><strong>RESULT Negatif</strong>: result = plan / actual (actual minimum dihitung 1).</li>
                    <li><strong>Total Score</strong>: tiap kategori menyumbang Category Percentage &times; rata-rata Result indikatornya. Contoh GIF 2: MAIN JOB 60% &times; rata-rata (100% + 80% + 75%) = 51, ditambah ADMINISTRATION 20 dan REPORTING 20, sehingga Total Score <strong>91.00</strong>.</li>
                </ul>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/staff-checklist-flow.gif') }}"
                    alt="GIF pengisian checklist KPI bulanan: pilih bulan, centang NON, isi Value Actual Baru untuk RESULT, lalu cek Result dan Total Score"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 2. Mengisi checklist bulanan: pilih bulan, centang NON, isi actual RESULT, lalu cek Result dan Total Score
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
                            <td class="px-4 py-3">Aksi utama untuk update KPI: klik ikon centang untuk toggle NON atau membuka jendela <strong>Update KPI</strong> untuk RESULT. Ikon merah berarti belum diisi, hijau berarti sudah diisi, dan ikon kunci abu-abu berarti periode sudah ditutup.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Description</td>
                            <td class="px-4 py-3">Deskripsi indikator dari penanggung jawab, keterangan Skema (NON, Positif, atau Negatif), serta subtasks jika tersedia.</td>
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
                            <td class="px-4 py-3">Nilai aktual yang Anda isi di field <strong>Value Actual Baru</strong> pada jendela Update KPI.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Result</td>
                            <td class="px-4 py-3">Hasil perhitungan otomatis sistem berdasarkan type, plan, dan actual. Label hijau berarti target tercapai (100%), kuning berarti belum.</td>
                        </tr>
                        <tr class="bg-white dark:bg-gray-900/30">
                            <td class="px-4 py-3">Action</td>
                            <td class="px-4 py-3">Ikon plus oranye (<strong>Extra Task</strong>) digunakan saat KPI detail belum tercapai karena ada pekerjaan lain yang diminta diselesaikan. Ikon hanya tampil untuk KPI RESULT dengan result di atas 0 dan di bawah 100% selama periode masih terbuka.</td>
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
                        <li>Klik ikon centang di kolom Status pada baris KPI RESULT untuk membuka jendela <strong>Update KPI</strong>.</li>
                        <li>Isi <strong>Value Actual Baru</strong> dengan angka valid (boleh desimal).</li>
                        <li>Klik <strong>Simpan</strong>, lalu cek kolom Actual dan Result berubah.</li>
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
                <li>Pada baris KPI tersebut, klik ikon plus oranye (<strong>Extra Task</strong>) di kolom Action.</li>
                <li>Di jendela <strong>Tambah Extra Task</strong>, isi <strong>Deskripsi Extra Task</strong> dengan nama pekerjaan pengganti yang benar-benar dikerjakan.</li>
                <li>Pilih <strong>Count Type</strong>:
                    <strong>NON</strong> untuk checklist selesai/tidak,
                    <strong>RESULT</strong> untuk input angka.
                </li>
                <li>Jika memilih RESULT, isi <strong>Actual Value</strong>.</li>
                <li>Klik <strong>Simpan</strong>.</li>
                <li>Cek baris <strong>Extra Task</strong> muncul di bawah KPI parent dan nilai parent ikut diperbarui.</li>
                <li>Jika ada salah input, hapus extra task dengan ikon tempat sampah merah (<strong>Hapus Extra Task</strong>) selama periode masih terbuka. Penghapusan langsung terjadi tanpa konfirmasi, dan nilai KPI parent dihitung ulang.</li>
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
                        <li><strong>Penyebab umum</strong>: periode checklist sudah ditutup sistem, ditandai label merah <strong>Ditutup sejak &lt;tanggal&gt;</strong> di samping nama bulan.</li>
                        <li><strong>Aturan sistem</strong>: edit dibatasi sampai tanggal 5 bulan berikutnya dari periode KPI.</li>
                        <li><strong>Solusi</strong>: minta bantuan penanggung jawab/admin jika ada kebutuhan koreksi setelah periode ditutup.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">3) Hasil Result Tidak Berubah</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>Penyebab umum</strong>: value actual belum tersimpan atau nilai yang dimasukkan tidak sesuai.</li>
                        <li><strong>Solusi</strong>: klik lagi ikon centang di kolom Status, pastikan field <strong>Value Actual Baru</strong> terisi angka, lalu klik <strong>Simpan</strong>.</li>
                        <li><strong>Catatan</strong>: untuk KPI dengan skema <strong>Negatif</strong>, actual lebih kecil akan menghasilkan result lebih baik.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">4) Ikon Extra Task Tidak Muncul</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>Penyebab umum</strong>: KPI bukan tipe RESULT, hasil belum diinput, atau periodenya sudah terkunci.</li>
                        <li><strong>Aturan tampil</strong>: ikon plus oranye muncul untuk KPI RESULT dengan result di atas 0 namun masih di bawah 100%.</li>
                        <li><strong>Solusi</strong>: pastikan result KPI sudah terisi dan masih dalam periode edit.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-400/30 dark:bg-red-500/10">
                    <h3 class="font-semibold text-red-900 dark:text-red-200">5) Login WhatsApp Gagal</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-red-900 dark:text-red-200">
                        <li><strong>&ldquo;Nomor WhatsApp tidak valid.&rdquo;</strong> Format nomor salah. Isi nomor seluler Indonesia, misalnya 081234567890.</li>
                        <li><strong>&ldquo;No. HP belum terdaftar untuk login WhatsApp.&rdquo;</strong> Nomor belum diisi di data karyawan atau berbeda. Minta admin memperbarui No. HP, atau masuk dengan Username dan Kata sandi.</li>
                        <li><strong>&ldquo;OTP tidak valid atau sudah kedaluwarsa.&rdquo;</strong> Kode salah, sudah dipakai, atau melewati masa berlaku. Klik <strong>Ganti nomor atau kirim ulang OTP</strong> lalu minta kode baru. Setelah 5 kali salah, kode tidak berlaku lagi.</li>
                        <li><strong>&ldquo;Terlalu banyak percobaan OTP. Coba lagi sebentar lagi.&rdquo;</strong> Kode OTP terlalu sering dimasukkan (maksimal 15 kali per 5 menit untuk satu nomor). Tunggu beberapa menit, lalu klik <strong>Ganti nomor atau kirim ulang OTP</strong> untuk meminta kode baru.</li>
                        <li><strong>&ldquo;Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.&rdquo;</strong> Permintaan kode dibatasi, maksimal 3 kali per 5 menit dan 10 kali per jam untuk satu nomor. Tunggu beberapa menit; jika masih ditolak, tunggu hingga 1 jam atau masuk dengan Username dan Kata sandi.</li>
                        <li><strong>&ldquo;OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.&rdquo;</strong> Layanan WhatsApp sedang bermasalah. Coba lagi nanti atau gunakan Username dan Kata sandi.</li>
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
