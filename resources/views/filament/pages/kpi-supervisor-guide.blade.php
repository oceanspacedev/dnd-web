<x-filament-panels::page>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Buku Panduan Operasional KPI - Manajerial</x-slot>
            <x-slot name="description">
                Dokumen kerja untuk peran manajerial dalam menyusun KPI bulanan dan memberi penilaian personel.
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Versi Dokumen</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">v1.1</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target Pembaca</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Atasan (Team Leader s.d. BOD) yang memiliki anggota tim, dan Admin</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cakupan</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Penyusunan KPI + Penilaian Bulanan</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 1 - Menyusun KPI Bulanan</x-slot>
            <x-slot name="description">Urutan komprehensif dari pembuatan KPI sampai validasi akhir sebelum dipakai tim.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Masuk ke sistem dengan akun atasan yang memiliki anggota tim (atau akun Admin), lewat <strong>Username</strong> dan <strong>Kata sandi</strong> atau tombol <strong>WhatsApp</strong> dengan kode OTP. Langkah masuk lewat WhatsApp ada di awal GIF 2.</li>
                <li>Buka menu <strong>KPI</strong>, lalu klik tombol <strong>Buat kpi</strong> di kanan atas.</li>
                <li>Pilih <strong>Job Position</strong> (ketik nama posisi untuk mencari). Setiap opsi berisi nama posisi dan anggota tim yang memegangnya; KPI dibuat sekaligus untuk semua nama tersebut (termasuk Anda bila posisi Anda sama).</li>
                <li>Klik <strong>Month</strong>, lalu klik tanggal mana saja di bulan periode KPI. Kolom menampilkan bulan/tahun, misalnya <strong>10/2026</strong>. Hindari membuat KPI pada tanggal 29-31 (lihat Detail A).</li>
                <li>Pada tab <strong>MAIN JOB</strong>, isi <strong>Percentage %</strong>, klik <strong>Tambahkan ke kPI Descriptions</strong>, lalu isi baris pertama dengan indikator positif bertipe <strong>RESULT</strong> (contoh: Tiket selesai per bulan).</li>
                <li>Isi <strong>Value Plan</strong> untuk KPI RESULT (minimal 1); kolom ini baru aktif setelah <strong>Count Type</strong> diisi RESULT. Klik <strong>Add Subtask</strong> bila aktivitas perlu dipecah (opsional).</li>
                <li>Klik <strong>Tambahkan ke kPI Descriptions</strong> lagi untuk KPI kedua. Jika indikatornya belum ada di daftar, klik ikon <strong>+</strong> di samping kolom <strong>Deskripsi</strong> (KPI Description).</li>
                <li>Di jendela <strong>Buat</strong>, isi <strong>Description</strong> (contoh: Insiden kritikal), nyalakan <strong>Lower is Better (Negative KPI)</strong> bila angka yang lebih kecil lebih baik, lalu klik <strong>Buat</strong>. Indikator baru langsung terpilih.</li>
                <li>Buka tab <strong>ADMINISTRATION</strong>, isi <strong>Percentage %</strong>, tambahkan baris, lalu isi KPI bertipe <strong>NON</strong> untuk tugas administrasi (kolom Value Plan tidak aktif untuk NON).</li>
                <li>Buka tab <strong>REPORTING</strong>, isi <strong>Percentage %</strong>, tambahkan baris, lalu isi KPI bertipe <strong>RESULT</strong> untuk output laporan.</li>
                <li>Kolom <strong>start</strong> dan <strong>end</strong> bersifat opsional; isi hanya jika KPI membutuhkan rentang waktu kerja.</li>
                <li>Isi <strong>Percentage %</strong> di setiap tab, contoh: <strong>MAIN JOB 60%</strong>, <strong>ADMINISTRATION 20%</strong>, <strong>REPORTING 20%</strong>.</li>
                <li>Pastikan total persentase tiga kategori tepat <strong>100%</strong>.</li>
                <li>Pastikan setiap tab (<strong>MAIN JOB</strong>, <strong>ADMINISTRATION</strong>, <strong>REPORTING</strong>) berisi minimal satu baris KPI, setiap baris memiliki <strong>Deskripsi</strong> (KPI Description), dan setiap KPI <strong>RESULT</strong> memiliki <strong>Value Plan</strong>. Tab tanpa baris tidak ditolak sistem: notifikasi <strong>KPI created</strong> tetap muncul, tetapi KPI kategori itu tidak dibuat walaupun persentasenya ikut dihitung ke 100%.</li>
                <li>Klik <strong>Buat</strong> di bawah form untuk menyimpan KPI bulanan.</li>
                <li>Pastikan muncul notifikasi <strong>KPI created</strong> dan daftar KPI menampilkan satu baris per kategori yang berisi indikator untuk setiap karyawan pada posisi itu (tiga baris per karyawan bila ketiga tab terisi). Sistem tidak menolak KPI ganda, jadi jangan membuat KPI posisi dan bulan yang sama dua kali.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">Contoh angka lengkap untuk satu periode:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li><strong>MAIN JOB</strong>: "Tiket selesai per bulan" (RESULT, plan 40) + "Insiden kritikal" (RESULT, plan 3, Negative KPI).</li>
                    <li><strong>ADMINISTRATION</strong>: "Timesheet lengkap" (NON).</li>
                    <li><strong>REPORTING</strong>: "Laporan bulanan tepat waktu" (RESULT, plan 1).</li>
                </ul>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/kpi-flow-lengkap.gif') }}"
                    alt="GIF menyusun KPI Oktober 2026 untuk posisi Admin Gudang dari tombol Buat kpi sampai notifikasi KPI created"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 1. Menyusun KPI satu bulan untuk satu posisi sesuai contoh di atas (end-to-end): Buat kpi, Job Position, Month, tiga tab kategori, indikator baru lewat ikon +, lalu Buat
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Detail Pengisian Form KPI (Field by Field)</x-slot>
            <x-slot name="description">Panduan teknis lengkap agar tidak ada data yang terlewat saat membuat KPI.</x-slot>

            <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">A. Header Form</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Job Position</strong>: posisi target. Opsi menampilkan anggota tim pemegang posisi itu, dan KPI dibuat untuk mereka semua.</li>
                        <li><strong>Month</strong>: bulan periode KPI (biasanya bulan berjalan atau bulan berikutnya). Klik tanggal mana saja di bulan itu; kolom menampilkan bulan/tahun.</li>
                        <li><strong>Hindari membuat KPI pada tanggal 29-31</strong>: tanggal KPI yang tersimpan memakai tanggal hari ini, bukan tanggal yang diklik. Jika bulan tujuan lebih pendek, KPI tercatat di bulan berikutnya (contoh: KPI November yang dibuat 31 Oktober tercatat sebagai Desember).</li>
                        <li><strong>Tipe KPI</strong>: tidak tampil di form; sistem mengisinya otomatis.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">B. Tiap Baris KPI di Tab Kategori</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Setiap tab punya <strong>Percentage %</strong> dan daftar barisnya sendiri. Baris ditambahkan dengan tombol <strong>Tambahkan ke kPI Descriptions</strong>.</li>
                        <li><strong>KPI Description</strong> (kolom Deskripsi): pilih indikator yang sudah ada (ketik untuk mencari), atau buat baru jika belum tersedia.</li>
                        <li><strong>start / end</strong>: opsional, untuk rentang target kerja.</li>
                        <li><strong>Count Type</strong>: pilih <strong>NON</strong> atau <strong>RESULT</strong>.</li>
                        <li><strong>Value Plan</strong>: wajib diisi jika <strong>Count Type = RESULT</strong> (minimal 1); untuk NON kolomnya tidak aktif.</li>
                        <li><strong>Subtasks</strong>: opsional, klik <strong>Add Subtask</strong> untuk memecah aktivitas menjadi langkah kecil.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">C. Jika KPI Description Belum Ada</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>Klik ikon <strong>+</strong> di samping kolom <strong>Deskripsi</strong> (KPI Description); jendela <strong>Buat</strong> terbuka.</li>
                        <li>Isi nama indikator pada field <strong>Description</strong>.</li>
                        <li>Nyalakan <strong>Lower is Better (Negative KPI)</strong> jika indikator bersifat negatif.</li>
                        <li>Klik <strong>Buat</strong>. Indikator baru langsung terpilih di baris tersebut dan masuk ke kategori tab yang sedang dibuka.</li>
                    </ol>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">D. Aturan Isi start &amp; end (Opsional)</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Status field</strong>: <strong>boleh kosong</strong> dan sistem tetap bisa simpan KPI.</li>
                        <li><strong>Kapan diisi</strong>: isi jika KPI punya periode kerja jelas (contoh: implementasi fitur 5-20 Februari).</li>
                        <li><strong>Kapan dikosongkan</strong>: KPI rutin tanpa batas tanggal spesifik lebih tepat dibiarkan kosong.</li>
                        <li><strong>Jika diisi</strong>: gunakan praktik aman <strong>start &lt;= end</strong> agar rentang kerja tidak terbalik.</li>
                        <li><strong>Perilaku sistem saat ini</strong>: start/end hanya informasi tampilan pada checklist, bukan pembatas pengisian.</li>
                        <li><strong>Batas pengisian checklist</strong>: ditentukan oleh periode KPI bulanan (bukan dari start/end).</li>
                    </ul>

                    <div class="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                        <p class="font-semibold">Contoh pengisian:</p>
                        <p class="mt-1"><strong>KPI:</strong> Laporan bulanan tepat waktu | <strong>start:</strong> 2026-10-01 | <strong>end:</strong> 2026-10-31.</p>
                        <p class="mt-1"><strong>KPI:</strong> Timesheet lengkap (rutin) | <strong>start/end:</strong> boleh dikosongkan.</p>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Contoh Lengkap KPI Bulanan (End-to-End)</x-slot>
            <x-slot name="description">Contoh ini bisa dijadikan template awal untuk satu posisi.</x-slot>

            <div class="space-y-4">
                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-500/10 dark:text-indigo-200">
                    Komposisi persentase kategori: <strong>MAIN JOB 60%</strong>, <strong>ADMINISTRATION 20%</strong>, <strong>REPORTING 20%</strong>. Total = <strong>100%</strong>.
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Kategori</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">KPI Description</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Sifat</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Count Type</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Plan</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Subtasks (opsional)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">MAIN JOB</td>
                                <td class="px-4 py-3">Tiket selesai per bulan</td>
                                <td class="px-4 py-3">Positif</td>
                                <td class="px-4 py-3">RESULT</td>
                                <td class="px-4 py-3">40</td>
                                <td class="px-4 py-3">Analisa, Implementasi, QA</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">MAIN JOB</td>
                                <td class="px-4 py-3">Insiden kritikal</td>
                                <td class="px-4 py-3">Negatif</td>
                                <td class="px-4 py-3">RESULT</td>
                                <td class="px-4 py-3">3</td>
                                <td class="px-4 py-3">Monitoring, RCA, Preventive action</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">ADMINISTRATION</td>
                                <td class="px-4 py-3">Timesheet lengkap</td>
                                <td class="px-4 py-3">N/A</td>
                                <td class="px-4 py-3">NON</td>
                                <td class="px-4 py-3">-</td>
                                <td class="px-4 py-3">Submit mingguan</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">REPORTING</td>
                                <td class="px-4 py-3">Laporan bulanan tepat waktu</td>
                                <td class="px-4 py-3">Positif</td>
                                <td class="px-4 py-3">RESULT</td>
                                <td class="px-4 py-3">1</td>
                                <td class="px-4 py-3">Draft, Review, Final publish</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">Subtasks hanya contoh pemecahan aktivitas (tombol <strong>Add Subtask</strong>) dan boleh dikosongkan; GIF 1 tidak mengisinya.</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Contoh Hitung Result (Positif, Negatif, NON)</x-slot>
            <x-slot name="description">Perhitungan ini mengikuti logika sistem yang aktif saat ini.</x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">1) RESULT Positif</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Plan = 40, Actual = 32</p>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">Result = Actual / Plan = 32 / 40 = <strong>0.80 (80%)</strong></p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">2) RESULT Negatif</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Plan = 3, Actual = 5</p>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">Result = Plan / Actual = 3 / 5 = <strong>0.60 (60%)</strong></p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Semakin kecil actual, semakin baik untuk KPI negatif.</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">3) NON</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Status selesai = <strong>1 (100%)</strong></p>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">Status belum selesai = <strong>0 (0%)</strong></p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Skenario Error KPI & Penanganan Detail</x-slot>
            <x-slot name="description">Gunakan bagian ini saat KPI gagal disimpan. Jika klik Buat malah memindahkan form ke tab lain, lihat kolom yang ditandai browser di tab itu: masih ada Percentage %, Count Type, atau Value Plan yang kosong. Teks pesan browser mengikuti bahasa browser.</x-slot>

            <div class="space-y-4">
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 1 - Field Wajib Kosong</h3>
                    <p class="mt-1">Pesan: jika <strong>Percentage %</strong> kosong, browser menahan simpan, membuka tab yang kolomnya kosong, dan menandai kolom itu (<strong>Please fill out this field.</strong>). Browser hanya menandai satu kolom setiap kali dan bisa langsung pindah ke tab lain walaupun Percentage % di tab yang sedang dibuka juga kosong, jadi periksa ketiga tab. Jika Job Position atau Month kosong, muncul notifikasi <strong>Error occurred</strong> yang menyebut field kosong pertama; bila keduanya kosong (seperti di GIF) isinya <strong>The job Position field is required. (and 1 more error)</strong>.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: Percentage % di salah satu tab, atau header form (Job Position, Month), belum diisi.</li>
                        <li><strong>Dampak</strong>: KPI tidak bisa disimpan.</li>
                        <li><strong>Solusi</strong>: Isi Percentage % di ketiga tab, pilih Job Position dan Month, pastikan setiap tab berisi minimal satu baris KPI, lalu klik <strong>Buat</strong> lagi.</li>
                        <li><strong>Perhatian</strong>: tab tanpa baris KPI tidak memunculkan error. Notifikasi <strong>KPI created</strong> tetap muncul, tetapi kategori itu tidak dibuat; bila ketiga tab kosong, tidak ada KPI yang tersimpan.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-required-fields.gif') }}"
                        alt="GIF error field wajib kosong pada form yang sudah berisi satu baris KPI per tab: pesan browser pada Percentage %, lalu notifikasi Error occurred untuk Job Position dan Month"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 2 - KPI Description Belum Dipilih</h3>
                    <p class="mt-1">Pesan: notifikasi <strong>Error occurred</strong> berisi <strong>The kPI Description field is required.</strong></p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: Baris KPI ditambahkan tetapi deskripsi indikator belum diisi.</li>
                        <li><strong>Dampak</strong>: Baris KPI dianggap tidak valid.</li>
                        <li><strong>Solusi</strong>: Pilih indikator yang tersedia (ketik untuk mencari), atau klik ikon <strong>+</strong> di samping kolom Deskripsi untuk membuat indikator baru.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-kpi-description.gif') }}"
                        alt="GIF error KPI Description belum dipilih: notifikasi Error occurred, lalu indikator dipilih dari daftar"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 3 - KPI RESULT Tanpa Value Plan</h3>
                    <p class="mt-1">Pesan: browser menahan simpan dan memindahkan kursor ke <strong>Value Plan</strong> dengan pesan <strong>Please fill out this field.</strong> (tanpa notifikasi). Nilai 0 ditolak dengan pesan <strong>Value must be greater than or equal to 1.</strong></p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: <strong>Count Type = RESULT</strong> tetapi plan belum diisi.</li>
                        <li><strong>Dampak</strong>: Sistem tidak bisa menghitung capaian indikator.</li>
                        <li><strong>Solusi</strong>: Isi <strong>Value Plan</strong> angka target (minimal 1), lalu klik <strong>Buat</strong> lagi.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-value-plan.gif') }}"
                        alt="GIF error value plan kosong pada KPI RESULT: pesan browser pada Value Plan"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 4 - Total Persentase Bukan 100%</h3>
                    <p class="mt-1">Pesan: notifikasi <strong>Validation Error</strong> berisi <strong>Total percentage must be 100%</strong>, bersamaan dengan notifikasi <strong>Error occurred</strong> tanpa keterangan.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: Akumulasi persentase kategori tidak tepat 100 (contoh di GIF: 60 + 20 + 10 = 90).</li>
                        <li><strong>Dampak</strong>: Bobot KPI tidak seimbang dan sistem menolak penyimpanan.</li>
                        <li><strong>Solusi</strong>: Koreksi Percentage % pada MAIN JOB / ADMINISTRATION / REPORTING hingga total tepat 100%, lalu klik <strong>Buat</strong> lagi.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-percentage-total.gif') }}"
                        alt="GIF error total persentase KPI tidak 100 persen: notifikasi Validation Error dan Error occurred, lalu REPORTING dikoreksi menjadi 20"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 2 - Input Penilaian Bulanan Personel</x-slot>
            <x-slot name="description">Proses ini dilakukan setiap bulan untuk melengkapi komponen penilaian tim.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Masuk ke sistem dengan <strong>Username</strong> dan <strong>Kata sandi</strong>, atau klik tombol <strong>WhatsApp</strong> di bawah tulisan <strong>Atau masuk dengan</strong>.</li>
                <li>Untuk masuk lewat WhatsApp: isi <strong>Nomor WhatsApp</strong> yang tercatat sebagai No. HP di data karyawan Anda, lalu klik <strong>Kirim OTP WhatsApp</strong>. Kolom nomor kemudian terkunci dan menampilkan nomor dalam format 62 (0812... menjadi 62812...). Isi 6 digit <strong>Kode OTP</strong> yang masuk ke WhatsApp, lalu klik <strong>Verifikasi dan Masuk</strong>. Kode hanya berlaku sebentar (lihat pesan WhatsApp); jika kedaluwarsa, klik <strong>Ganti nomor atau kirim ulang OTP</strong>.</li>
                <li>Buka menu <strong>Penilaian</strong>, lalu klik <strong>Buat employee review</strong>.</li>
                <li>Pilih personel pada field <strong>Pengguna</strong> (ketik nama untuk mencari); daftar hanya berisi karyawan yang boleh Anda nilai (anggota tim Anda).</li>
                <li>Periksa <strong>Periode</strong>: otomatis terisi bulan berjalan (contoh: Oktober 2026). Ganti lewat pemilih bulan bila menilai bulan lain.</li>
                <li>Isi nilai <strong>Responsivitas</strong> (0-5).</li>
                <li>Isi nilai <strong>Pemecah Masalah</strong> (0-5).</li>
                <li>Isi nilai <strong>Kepedulian</strong> (0-5).</li>
                <li>Isi nilai <strong>Inisiatif</strong> (0-5).</li>
                <li>Klik <strong>Buat</strong>. Muncul notifikasi <strong>Data berhasil dibuat</strong> dan Anda kembali ke daftar Penilaian; pastikan barisnya muncul. Di daftar, nilai Pemecah Masalah tampil di kolom <strong>Pemecahan Masalah</strong> dan nilai Kepedulian di kolom <strong>Kesediaan Membantu</strong>.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">Catatan validasi penilaian:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Sistem mencegah duplikasi untuk kombinasi <strong>Pengguna + Periode</strong>.</li>
                    <li>Jika kombinasi tersebut sudah ada, simpan akan ditolak dan muncul notifikasi <strong>Data sudah ada</strong>.</li>
                    <li>Solusi: gunakan periode yang benar-benar belum ada, atau edit data penilaian yang sudah dibuat.</li>
                    <li>Total nilai 4 aspek dibagi <strong>Poin Maksimal Penilaian</strong> pada versi Aturan Penilaian yang berlaku untuk periode itu, lalu dikali bobot Penilaian. Rinciannya ada di Panduan Aturan Penilaian.</li>
                </ul>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/supervisor-review-flow.gif') }}"
                    alt="GIF masuk lewat WhatsApp dengan kode OTP, lalu input penilaian bulanan untuk anggota tim"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 2. Masuk lewat WhatsApp (kode OTP), lalu input penilaian bulanan untuk anggota tim sampai notifikasi Data berhasil dibuat
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 3 - Export KPI & Copy KPI (Opsional)</x-slot>
            <x-slot name="description">Fitur ini digunakan dari halaman daftar KPI untuk kebutuhan distribusi data dan replikasi template KPI.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Buka menu <strong>KPI</strong> lalu masuk ke halaman daftar.</li>
                <li>Klik tombol <strong>Copy/Export</strong> (ikon titik tiga) di samping tombol <strong>Buat kpi</strong>, lalu pilih <strong>Export KPI</strong> untuk unduh rekap KPI.</li>
                <li>Tentukan tipe export (<strong>Per Divisi</strong> atau <strong>Per User</strong> jika tersedia sesuai hak akses), lalu isi periode dan filter yang dibutuhkan.</li>
                <li>Klik <strong>Export</strong> untuk menghasilkan file.</li>
                <li>Untuk menyalin KPI, pilih <strong>Copy KPI</strong> dari tombol <strong>Copy/Export</strong> yang sama.</li>
                <li>Pilih <strong>Mode Copy</strong>: <strong>Berdasarkan Posisi</strong> (pilih Posisi) atau <strong>Berdasarkan User Individual</strong> (pilih <strong>Copy dari User</strong> dan <strong>Copy ke User</strong>), lalu isi <strong>Dari Bulan</strong> dan <strong>Ke Bulan</strong>. Karyawan yang sudah punya KPI di bulan tujuan dilewati.</li>
                <li>Jalankan proses copy dan cek hasilnya di daftar KPI periode target.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold">Catatan akses:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Opsi yang tampil pada form export/copy mengikuti role pengguna.</li>
                    <li>Jika opsi tertentu tidak muncul, berarti fitur tersebut dibatasi oleh hak akses akun.</li>
                    <li>Admin dengan akses badan usaha tertentu hanya melihat data karyawan di badan usaha tersebut, ditambah data timnya dan dirinya sendiri.</li>
                </ul>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Checklist Validasi Manajerial</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Sebelum Simpan KPI</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Total persentase tiga kategori tepat 100%.</li>
                        <li>Setiap tab kategori (MAIN JOB, ADMINISTRATION, REPORTING) berisi minimal satu baris KPI.</li>
                        <li>Semua baris KPI memiliki deskripsi dan count type.</li>
                        <li>Value plan terisi untuk count type RESULT.</li>
                        <li>Jika Start/End diisi, pastikan tanggal tidak terbalik dan tidak salah input.</li>
                        <li>Periode bulan sudah benar dan KPI posisi itu untuk bulan tersebut belum pernah dibuat (sistem tidak menolak KPI ganda).</li>
                        <li>KPI tidak dibuat pada tanggal 29-31 (lihat Detail A).</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Sebelum Simpan Penilaian</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>User yang dinilai sudah benar.</li>
                        <li>Periode penilaian sudah benar (otomatis bulan berjalan; ganti bila menilai bulan lain).</li>
                        <li>Nilai 4 aspek berada pada rentang 0-5.</li>
                        <li>Pastikan belum ada data dengan kombinasi user + periode yang sama.</li>
                        <li>Data sudah muncul pada list penilaian.</li>
                    </ul>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Aksi Cepat</x-slot>

            <div class="flex flex-wrap gap-3">
                <x-filament::button tag="a" href="{{ $this->getKpiCreateUrl() }}" icon="heroicon-m-plus">
                    Buat KPI Bulanan
                </x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getKpiListUrl() }}" color="gray" icon="heroicon-m-list-bullet">
                    Lihat Daftar KPI
                </x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getReviewCreateUrl() }}" color="success" icon="heroicon-m-star">
                    Input Penilaian Bulanan
                </x-filament::button>
                <x-filament::button tag="a" href="{{ $this->getReviewListUrl() }}" color="gray" icon="heroicon-m-clipboard-document-list">
                    Lihat Riwayat Penilaian
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
