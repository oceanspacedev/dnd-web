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
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">v1.0</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target Pembaca</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Manajer / Koordinator / Penanggung Jawab</p>
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
                <li>Masuk ke sistem menggunakan akun berizin manajerial.</li>
                <li>Buka menu <strong>KPI</strong>, lalu klik tombol <strong>Create</strong>.</li>
                <li>Isi <strong>Job Position</strong> sesuai jabatan yang akan menerima KPI.</li>
                <li>Pilih <strong>Month</strong> untuk periode KPI.</li>
                <li>Pada tab <strong>MAIN JOB</strong>, isi KPI pertama bertipe <strong>RESULT</strong> dengan indikator positif (contoh: tiket selesai).</li>
                <li>Isi <strong>Value Plan</strong> untuk KPI RESULT (minimal 1), lalu lengkapi <strong>Subtasks</strong> jika diperlukan.</li>
                <li>Tambahkan KPI kedua pada <strong>MAIN JOB</strong>; jika indikator belum ada, klik <strong>Create option</strong> di field <strong>KPI Description</strong>.</li>
                <li>Saat membuat indikator baru yang bersifat menekan angka (misal insiden), aktifkan <strong>Lower is Better (Negative KPI)</strong>.</li>
                <li>Lanjut ke tab <strong>ADMINISTRATION</strong> dan isi KPI bertipe <strong>NON</strong> untuk tugas administrasi kepatuhan.</li>
                <li>Lanjut ke tab <strong>REPORTING</strong> dan isi KPI bertipe <strong>RESULT</strong> untuk output laporan.</li>
                <li><strong>Start Date</strong> dan <strong>End Date</strong> bersifat opsional; isi hanya jika KPI membutuhkan rentang waktu kerja.</li>
                <li>Atur komposisi kategori, contoh operasional: <strong>MAIN JOB 60%</strong>, <strong>ADMINISTRATION 20%</strong>, <strong>REPORTING 20%</strong>.</li>
                <li>Pastikan total persentase tiga kategori tepat <strong>100%</strong>.</li>
                <li>Pastikan semua baris memiliki <strong>KPI Description</strong> dan setiap KPI <strong>RESULT</strong> memiliki <strong>Value Plan</strong>.</li>
                <li>Klik <strong>Create</strong> untuk menyimpan KPI bulanan.</li>
                <li>Verifikasi notifikasi sukses dan pastikan data muncul di daftar KPI.</li>
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
                    alt="GIF flow komprehensif pembuatan KPI manajerial"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 1. Flow komprehensif penyusunan KPI manajerial (end-to-end)
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Detail Pengisian Form KPI (Field by Field)</x-slot>
            <x-slot name="description">Panduan teknis lengkap agar tidak ada data yang terlewat saat create KPI.</x-slot>

            <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">A. Header Form</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Job Position</strong>: pilih posisi jabatan target.</li>
                        <li><strong>Month</strong>: periode KPI dalam bulan berjalan.</li>
                        <li><strong>KPI Type</strong>: sistem menggunakan tipe KPI operasional.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">B. Tiap Baris KPI di Tab Kategori</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>KPI Description</strong>: pilih indikator yang sudah ada, atau buat baru jika belum tersedia.</li>
                        <li><strong>Start Date / End Date</strong>: opsional, untuk rentang target kerja.</li>
                        <li><strong>Count Type</strong>: pilih <strong>NON</strong> atau <strong>RESULT</strong>.</li>
                        <li><strong>Value Plan</strong>: wajib diisi jika <strong>Count Type = RESULT</strong> (minimal 1).</li>
                        <li><strong>Subtasks</strong>: opsional, untuk memecah aktivitas menjadi langkah kecil.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">C. Jika KPI Description Belum Ada</h3>
                    <ol class="mt-2 list-decimal space-y-1 pl-5">
                        <li>Pada field <strong>KPI Description</strong>, klik opsi <strong>Create option</strong> (ikon tambah).</li>
                        <li>Isi nama indikator pada field <strong>Description</strong>.</li>
                        <li>Centang <strong>Lower is Better (Negative KPI)</strong> jika indikator bersifat negatif.</li>
                        <li>Simpan opsi baru, lalu lanjutkan pengisian baris KPI.</li>
                    </ol>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">D. Aturan Isi Start Date & End Date (Opsional)</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Status field</strong>: <strong>boleh kosong</strong> dan sistem tetap bisa simpan KPI.</li>
                        <li><strong>Kapan diisi</strong>: isi jika KPI punya periode kerja jelas (contoh: implementasi fitur 5-20 Februari).</li>
                        <li><strong>Kapan dikosongkan</strong>: KPI rutin tanpa batas tanggal spesifik lebih tepat dibiarkan kosong.</li>
                        <li><strong>Jika diisi</strong>: gunakan praktik aman <strong>Start Date <= End Date</strong> agar rentang kerja tidak terbalik.</li>
                        <li><strong>Perilaku sistem saat ini</strong>: Start/End hanya informasi tampilan pada checklist, bukan pembatas pengisian.</li>
                        <li><strong>Batas pengisian checklist</strong>: ditentukan oleh periode KPI bulanan (bukan dari Start/End).</li>
                    </ul>

                    <div class="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                        <p class="font-semibold">Contoh pengisian:</p>
                        <p class="mt-1"><strong>KPI:</strong> Laporan bulanan tepat waktu | <strong>Start Date:</strong> 2026-02-01 | <strong>End Date:</strong> 2026-02-28.</p>
                        <p class="mt-1"><strong>KPI:</strong> Timesheet lengkap (rutin) | <strong>Start/End:</strong> boleh dikosongkan.</p>
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
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Subtasks</th>
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
            <x-slot name="description">Gunakan bagian ini saat proses create KPI gagal atau muncul pesan validasi.</x-slot>

            <div class="space-y-4">
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 1 - Field Wajib Kosong</h3>
                    <p class="mt-1">Pesan umum: <strong>The job position field is required</strong> dan <strong>The month field is required</strong>.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: Header form belum lengkap.</li>
                        <li><strong>Dampak</strong>: KPI tidak bisa disimpan.</li>
                        <li><strong>Solusi</strong>: Isi Job Position dan Month, lalu submit ulang.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-required-fields.gif') }}"
                        alt="GIF error field wajib kosong saat create KPI"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 2 - KPI Description Belum Dipilih</h3>
                    <p class="mt-1">Pesan umum: <strong>The kpi description field is required</strong>.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: Baris KPI ditambahkan tetapi deskripsi indikator belum diisi.</li>
                        <li><strong>Dampak</strong>: Baris KPI dianggap tidak valid.</li>
                        <li><strong>Solusi</strong>: Pilih indikator yang tersedia, atau gunakan <strong>Create option</strong> untuk membuat indikator baru.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-kpi-description.gif') }}"
                        alt="GIF error KPI Description belum dipilih"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 3 - KPI RESULT Tanpa Value Plan</h3>
                    <p class="mt-1">Pesan umum: <strong>The value plan field is required</strong>.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: <strong>Count Type = RESULT</strong> tetapi plan belum diisi.</li>
                        <li><strong>Dampak</strong>: Sistem tidak bisa menghitung capaian indikator.</li>
                        <li><strong>Solusi</strong>: Isi <strong>Value Plan</strong> angka target (minimal 1), lalu submit ulang.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-value-plan.gif') }}"
                        alt="GIF error value plan kosong pada KPI RESULT"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">Error 4 - Total Persentase Bukan 100%</h3>
                    <p class="mt-1">Pesan umum: <strong>Validation error: Total percentage must be 100%</strong>.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Penyebab</strong>: Akumulasi persentase kategori tidak tepat 100.</li>
                        <li><strong>Dampak</strong>: Bobot KPI tidak seimbang dan sistem menolak penyimpanan.</li>
                        <li><strong>Solusi</strong>: Koreksi persentase pada MAIN JOB / ADMINISTRATION / REPORTING hingga total tepat 100%.</li>
                    </ul>
                    <img src="{{ asset('images/kpi-guides/kpi-error-percentage-total.gif') }}"
                        alt="GIF error total persentase KPI tidak 100 persen"
                        class="mt-3 w-full rounded-lg border border-red-200 dark:border-red-300/30" />
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 2 - Input Penilaian Bulanan Personel</x-slot>
            <x-slot name="description">Proses ini dilakukan setiap bulan untuk melengkapi komponen penilaian tim.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Buka menu <strong>Penilaian</strong>, lalu klik <strong>Create</strong>.</li>
                <li>Pilih personel pada field <strong>Pengguna</strong>.</li>
                <li>Isi field <strong>Periode</strong> dengan format <strong>YYYY-MM</strong>.</li>
                <li>Isi nilai <strong>Responsivitas</strong> (0-5).</li>
                <li>Isi nilai <strong>Pemecah Masalah</strong> (0-5).</li>
                <li>Isi nilai <strong>Kepedulian</strong> (0-5).</li>
                <li>Isi nilai <strong>Inisiatif</strong> (0-5).</li>
                <li>Klik simpan, lalu cek daftar penilaian untuk memastikan data masuk.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">Catatan validasi penilaian:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Sistem mencegah duplikasi untuk kombinasi <strong>Pengguna + Periode</strong>.</li>
                    <li>Jika kombinasi tersebut sudah ada, simpan akan ditolak dan muncul notifikasi <strong>Data sudah ada</strong>.</li>
                    <li>Solusi: gunakan periode yang benar-benar belum ada, atau edit data penilaian yang sudah dibuat.</li>
                </ul>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/kpi-guides/supervisor-review-flow.gif') }}"
                    alt="GIF flow input penilaian bulanan manajerial"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 2. Flow input penilaian bulanan manajerial
                </p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">SOP 3 - Export KPI & Copy KPI (Opsional)</x-slot>
            <x-slot name="description">Fitur ini digunakan dari halaman daftar KPI untuk kebutuhan distribusi data dan replikasi template KPI.</x-slot>

            <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>Buka menu <strong>KPI</strong> lalu masuk ke halaman daftar.</li>
                <li>Klik menu aksi header, pilih <strong>Export KPI</strong> untuk unduh rekap KPI.</li>
                <li>Tentukan tipe export (<strong>Per Divisi</strong> atau <strong>Per User</strong> jika tersedia sesuai hak akses), lalu isi periode dan filter yang dibutuhkan.</li>
                <li>Klik <strong>Export</strong> untuk menghasilkan file.</li>
                <li>Untuk menyalin template KPI, pilih aksi <strong>Copy KPI</strong> pada halaman yang sama.</li>
                <li>Tentukan mode copy (berdasarkan posisi atau user), pilih sumber, pilih target, lalu periode tujuan.</li>
                <li>Jalankan proses copy dan cek hasilnya di daftar KPI periode target.</li>
            </ol>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold">Catatan akses:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Opsi yang tampil pada form export/copy mengikuti role pengguna.</li>
                    <li>Jika opsi tertentu tidak muncul, berarti fitur tersebut dibatasi oleh hak akses akun.</li>
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
                        <li>Semua baris KPI memiliki deskripsi dan count type.</li>
                        <li>Value plan terisi untuk count type RESULT.</li>
                        <li>Jika Start/End diisi, pastikan tanggal tidak terbalik dan tidak salah input.</li>
                        <li>Periode bulan sudah benar.</li>
                    </ul>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Sebelum Simpan Penilaian</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>User yang dinilai sudah benar.</li>
                        <li>Periode penilaian mengikuti kebutuhan evaluasi dan format <strong>YYYY-MM</strong> (tidak wajib bulan berjalan).</li>
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
