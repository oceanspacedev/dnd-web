@php
    $access = $this->getViewerAccess();
    $isSuperAdmin = $this->isSuperAdmin();
    $adminSummary = $this->getAdminAccessSummary();
    $viewerRole = $this->getViewerRoleKey();
    $roleColumns = [
        'super_admin' => 'Super Admin',
        'scoped_admin' => 'Admin Badan Usaha Tertentu',
        'supervisor' => 'Atasan',
        'staff' => 'Staf',
    ];
    $employeesUrl = $this->getEmployeesUrl();
    $teamUrl = $this->getTeamUrl();
    $areaSettingsUrl = $this->getAreaSettingsUrl();
    $positionGuideUrl = $this->getPositionGuideUrl();
    // GIF 1 hanya tampil di SOP super admin, jadi penomoran GIF berikutnya mengikuti viewer.
    $gifNumber = $isSuperAdmin ? 2 : 1;
@endphp

<x-filament-panels::page>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        {{-- Ringkasan --}}
        <x-filament::section>
            <x-slot name="heading">Buku Panduan Akses Data Karyawan</x-slot>
            <x-slot name="description">
                Penjelasan siapa dapat melihat dan mengelola data karyawan: akses per badan usaha untuk admin (menu Karyawan) dan akses tim untuk atasan (menu Tim Saya).
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Versi Dokumen</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">v1.0 (Oktober 2026)</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target Pembaca</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Super Admin, Admin Badan Usaha & Atasan</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cakupan</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Akses per Badan Usaha + Tim Saya</p>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold flex items-center gap-2">
                    <x-filament::icon icon="heroicon-m-information-circle" class="h-5 w-5 text-sky-600 dark:text-sky-400" />
                    Istilah yang dipakai di panduan ini:
                </p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li><strong>Badan usaha</strong> = data di menu <strong>Settings &gt; Area</strong>. Badan usaha seorang karyawan adalah isi field <strong>Area</strong> pada form karyawan. Field <strong>Divisi</strong> tidak menentukan badan usaha: pilihannya berisi semua divisi dan tidak harus sama dengan Area karyawan.</li>
                    <li><strong>Super admin</strong> = karyawan dengan Jabatan <strong>ADMIN</strong> dan Akses Data <strong>Semua Badan Usaha</strong>.</li>
                    <li><strong>Admin badan usaha tertentu</strong> = karyawan dengan Jabatan <strong>ADMIN</strong> dan Akses Data <strong>Badan Usaha Tertentu</strong>.</li>
                    <li><strong>Atasan</strong> = Jabatan TEAM LEADER, COORDINATOR, MANAGER, CHIEF, BOD, atau siapa pun yang dipilih sebagai <strong>Approval Line</strong> karyawan lain.</li>
                </ul>
            </div>
        </x-filament::section>

        {{-- Akses data viewer --}}
        <x-filament::section>
            <x-slot name="heading">Akses Data Anda</x-slot>
            <x-slot name="description">Dihitung langsung dari akun yang sedang login, sehingga selalu sesuai pengaturan terbaru.</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status Akun</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-filament::badge :color="$access['status_color']">{{ $access['status'] }}</x-filament::badge>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Badan usaha Anda sendiri (field Area): <strong>{{ $access['home_area'] ?? '-' }}</strong>
                    </p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Akses Badan Usaha</p>
                    @if ($access['scope'] === 'all')
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Semua badan usaha</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Anda dapat melihat dan mengelola data seluruh badan usaha.</p>
                    @elseif ($access['scope'] === 'selected')
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($access['area_names'] as $areaName)
                                <x-filament::badge color="warning">{{ $areaName }}</x-filament::badge>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Ditambah anggota Tim Saya dan data Anda sendiri.</p>
                    @elseif ($access['role'] === 'scoped_admin')
                        <div class="mt-2 flex flex-wrap gap-2">
                            <x-filament::badge color="danger">Belum dipilih</x-filament::badge>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Badan usaha untuk akun Anda belum dipilih, sehingga menu Karyawan tidak tersedia. Hubungi super admin untuk mengisi field <strong>Badan Usaha</strong> pada akun Anda.</p>
                    @else
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Tidak ada akses badan usaha</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Data yang Anda kelola mengikuti anggota tim di menu Tim Saya.</p>
                    @endif
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Menu Karyawan</p>
                    @if ($access['employees_count'] !== null)
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($access['employees_count'], 0, ',', '.') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">karyawan aktif yang terlihat di menu Karyawan (belum termasuk tab Arsip).</p>
                    @else
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Tidak tersedia</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Menu Karyawan khusus admin yang memiliki akses badan usaha.</p>
                    @endif
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Menu Tim Saya</p>
                    @if ($access['team_count'] !== null)
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($access['team_count'], 0, ',', '.') }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">anggota tim (bawahan langsung + satu level di bawahnya).</p>
                    @else
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Tidak tersedia</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Belum ada karyawan yang Approval Line-nya Anda.</p>
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- SOP super admin --}}
        @if ($isSuperAdmin)
            <x-filament::section>
                <x-slot name="heading">SOP 1 - Memberi Admin Akses ke Badan Usaha Tertentu (Khusus Super Admin)</x-slot>
                <x-slot name="description">Contoh: admin hanya boleh mengelola karyawan badan usaha ULS dan GMD.</x-slot>

                <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>Pastikan badan usaha yang dituju sudah ada di menu <strong>Settings &gt; Area</strong>.</li>
                    <li>Pastikan field <strong>Area</strong> setiap karyawan sudah benar, karena field inilah yang menentukan karyawan masuk badan usaha mana.</li>
                    <li>Buka menu <strong>Karyawan</strong>, cari nama karyawan yang akan dijadikan admin, lalu klik <strong>Ubah</strong>.</li>
                    <li>Pada bagian <strong>Identitas Pengguna</strong>, ubah field <strong>Jabatan</strong> menjadi <strong>ADMIN</strong>.</li>
                    <li>
                        Bagian <strong>Akses Data</strong> akan muncul dengan <strong>Cakupan Badan Usaha</strong> sudah terpilih <strong>Badan Usaha Tertentu</strong>, dan field <strong>Badan Usaha</strong> sudah terisi Area karyawan itu sendiri (mis. COO).
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Jabatan ADMIN tidak pernah otomatis menjadi super admin. Selama belum disimpan, mengganti Area karyawan ini ikut mengganti isian Badan Usaha bila isian itu masih kosong atau hanya berisi Area sebelumnya.</p>
                    </li>
                    <li>Atur field <strong>Badan Usaha</strong>: hapus badan usaha yang tidak dikelola admin ini dengan tanda silang di pilihannya (mis. COO), lalu klik isiannya dan pilih badan usaha yang dikelola (mis. GMD dan ULS). Minimal satu badan usaha (wajib diisi).</li>
                    <li>
                        Klik <strong>Simpan</strong>.
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Setelah menjadi ADMIN, karyawan ini tidak lagi tampil di menu Tim Saya atasannya maupun di menu Karyawan admin badan usaha tertentu; profilnya hanya dapat diubah super admin.</p>
                    </li>
                    <li>Cek tabel Karyawan: kolom <strong>Akses Data</strong> pada baris admin tersebut menampilkan nama badan usaha yang dipilih.</li>
                    <li>Minta admin tersebut memuat ulang halaman. Menu <strong>Karyawan</strong> miliknya sekarang hanya berisi karyawan di badan usaha tersebut.</li>
                </ol>

                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Aturan penting:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Bagian <strong>Akses Data</strong> hanya muncul untuk super admin dan hanya jika Jabatan karyawan adalah <strong>ADMIN</strong>. Jabatan lain tidak memakai akses badan usaha.</li>
                        <li>Karyawan yang baru dijadikan ADMIN selalu mendapat <strong>Badan Usaha Tertentu</strong> dengan Area-nya sendiri, termasuk lewat <strong>Import User</strong> (Excel) dan lewat API bila akses data tidak dikirim. Setelah Import User, menu Karyawan menampilkan peringatan <strong>N Admin Dibatasi ke Badan Usaha Tertentu</strong> berisi baris dan badan usaha setiap admin tersebut.</li>
                        <li>Memilih <strong>Semua Badan Usaha</strong> (super admin) untuk karyawan yang belum super admin wajib mencentang <strong>Saya yakin menjadikan karyawan ini super admin</strong>. Lihat SOP 2.</li>
                        <li>Mengubah Area admin yang sudah ada tidak mengubah daftar Badan Usaha-nya. Ubah daftar itu sendiri bila perlu.</li>
                        <li>Hanya super admin yang dapat memberikan Jabatan <strong>ADMIN</strong>, mengubah Akses Data, dan mengelola akun ADMIN.</li>
                        <li>Anda tidak dapat membatasi akses akun Anda sendiri. Sistem akan menolak dengan pesan <strong>Anda tidak dapat membatasi akses data akun Anda sendiri.</strong></li>
                        <li>Super admin terakhir tidak dapat melepas Jabatan ADMIN dari akunnya sendiri (pesan: <strong>Minimal harus ada satu admin dengan akses seluruh badan usaha.</strong>). Jangan menghapus akun super admin terakhir: sistem tidak mencegahnya di panel web.</li>
                        <li>Perubahan langsung berlaku saat admin tersebut membuka atau memuat ulang halaman (di aplikasi mobile: saat data dimuat ulang). Tidak perlu logout.</li>
                    </ul>
                </div>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                    <img src="{{ asset('images/panduan-akses-data/akses-data-atur-admin.gif') }}"
                        alt="GIF super admin mengubah Jabatan karyawan COO menjadi ADMIN: Akses Data langsung terisi Badan Usaha Tertentu dengan COO (Area karyawan itu), lalu COO dihapus dan GMD serta ULS dipilih di field Badan Usaha, disimpan, dan kolom Akses Data menampilkan GMD, ULS"
                        class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                    <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                        GIF 1. Super admin menjadikan karyawan COO sebagai ADMIN: Akses Data otomatis Badan Usaha Tertentu dengan COO, lalu diganti GMD dan ULS dan kolom Akses Data dicek
                    </p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">SOP 2 - Mengubah atau Mencabut Akses (Khusus Super Admin)</x-slot>
                <x-slot name="description">Semua perubahan dilakukan dari form karyawan di menu Karyawan.</x-slot>

                <div class="grid gap-4 md:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Tambah / kurangi badan usaha</h3>
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Klik <strong>Ubah</strong> pada admin, lalu tambah atau hapus pilihan di field <strong>Badan Usaha</strong>, kemudian <strong>Simpan</strong>.</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Jadikan super admin</h3>
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Pada <strong>Cakupan Badan Usaha</strong>, pilih <strong>Semua Badan Usaha</strong>, centang <strong>Saya yakin menjadikan karyawan ini super admin</strong>, lalu <strong>Simpan</strong>. Admin tersebut melihat seluruh data, dapat membuka menu Settings termasuk Aturan Penilaian, dan dapat memberi Jabatan ADMIN.</p>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Tanpa centang, form tidak tersimpan (pesan <strong>Centang konfirmasi ini untuk memberi akses Semua Badan Usaha.</strong>). Mengganti pilihan cakupan menghapus centangnya. Akun yang sudah super admin tidak diminta konfirmasi.</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Cabut hak admin</h3>
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Ubah <strong>Jabatan</strong> ke selain ADMIN, lalu <strong>Simpan</strong>. Daftar badan usahanya otomatis dihapus; selanjutnya ia hanya mengelola anggota Tim Saya (jika punya).</p>
                    </div>
                </div>

                @if ($adminSummary !== null)
                    <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-gray-900/40">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">Kondisi akses admin saat ini</p>
                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            <strong>{{ $adminSummary['global_count'] }}</strong> super admin dan
                            <strong>{{ count($adminSummary['scoped']) }}</strong> admin badan usaha tertentu.
                        </p>

                        @if ($adminSummary['scoped'] !== [])
                            <ul class="mt-3 space-y-2 text-sm text-gray-700 dark:text-gray-300">
                                @foreach ($adminSummary['scoped'] as $scopedAdmin)
                                    <li class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $scopedAdmin['name'] }}</span>
                                        @forelse ($scopedAdmin['areas'] as $areaName)
                                            <x-filament::badge color="warning">{{ $areaName }}</x-filament::badge>
                                        @empty
                                            <x-filament::badge color="danger">Belum dipilih</x-filament::badge>
                                        @endforelse
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                            Tips: di menu Karyawan, filter <strong>Jabatan = ADMIN</strong> lalu lihat kolom <strong>Akses Data</strong>. Admin berstatus <strong>Belum dipilih</strong> tidak dapat membuka menu Karyawan sampai badan usahanya diisi.
                        </p>
                    </div>
                @endif
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">Pengaturan Akses Data</x-slot>

                <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                    <p class="font-semibold flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-lock-closed" class="h-5 w-5 text-sky-600 dark:text-sky-400" />
                        Khusus super admin
                    </p>
                    <p class="mt-1">
                        Pemberian Jabatan ADMIN dan pengaturan badan usaha (bagian <strong>Akses Data</strong> pada form karyawan) hanya dapat dilakukan oleh super admin. Jika akses Anda perlu ditambah atau dikurangi, hubungi super admin.
                    </p>
                </div>
            </x-filament::section>
        @endif

        {{-- Admin badan usaha tertentu --}}
        <x-filament::section>
            <x-slot name="heading">Admin Badan Usaha Tertentu: Apa yang Terlihat & Batasannya</x-slot>
            <x-slot name="description">Berlaku sama di panel web dan aplikasi mobile.</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-eye" class="h-4 w-4 text-success-600 dark:text-success-400" />
                        Yang terlihat
                    </h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Data karyawan yang <strong>Area</strong>-nya termasuk badan usaha yang diberikan.</li>
                        <li>Ditambah anggota <strong>Tim Saya</strong> (sesuai Approval Line) dan datanya sendiri, walaupun berada di badan usaha lain.</li>
                        <li>Berlaku di modul <strong>KPI</strong>, <strong>Kehadiran</strong>, <strong>Penilaian</strong>, <strong>Cutpoint</strong>, <strong>Jurnal Harian</strong>, <strong>Leaderboard KPI</strong> di Dashboard, termasuk export dan template/import Kehadiran & Penilaian.</li>
                        <li>Menu <strong>Karyawan</strong> hanya berisi karyawan di badan usaha yang diberikan (termasuk tab <strong>Arsip</strong>). Anggota tim di badan usaha lain dikelola dari menu <strong>Tim Saya</strong>.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-no-symbol" class="h-4 w-4 text-danger-600 dark:text-danger-400" />
                        Yang tidak bisa dilakukan
                    </h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Membuka menu <strong>Settings</strong> dan <strong>KPI Settings</strong> (Area, Divisi, Role, Aturan Approval, Aturan Penilaian, Komponen Kehadiran, Pengaturan Pengingat KPI, dan master lainnya).</li>
                        <li>Memberi Jabatan <strong>ADMIN</strong>, mengubah Akses Data, atau mengelola akun ADMIN. Akun ADMIN tidak tampil di menu Karyawan dan Tim Saya miliknya.</li>
                        <li>Mengubah data milik akun ADMIN lain (KPI, Kehadiran, Penilaian, Cutpoint, Jurnal Harian). Kehadiran, Penilaian, dan Cutpoint miliknya sendiri juga dicatat oleh super admin, sedangkan KPI dan Jurnal Harian miliknya sendiri tetap bisa ia ubah.</li>
                        <li>Memindahkan karyawan ke badan usaha di luar cakupannya dari menu <strong>Karyawan</strong>: pilihan field <strong>Area</strong> dibatasi ke badan usaha yang diberikan. Pengecualian: di menu <strong>Tim Saya</strong>, anggota tim boleh ditempatkan di badan usaha mana pun selama Approval Line-nya tetap admin tersebut atau bawahan langsungnya.</li>
                        <li>Memilih Approval Line yang membuat karyawan di luar cakupannya masuk ke Tim Saya.</li>
                        <li>Import karyawan (<strong>Import User</strong> Excel dan <strong>Import JSON (Talenta)</strong> di menu Karyawan). Fitur ini khusus super admin; <strong>Export User</strong> tetap tersedia sesuai cakupannya.</li>
                    </ul>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/panduan-akses-data/akses-data-hasil-admin.gif') }}"
                    alt="GIF tampilan admin badan usaha tertentu yang hanya melihat karyawan ULS dan GMD tanpa menu Settings"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF {{ $gifNumber++ }}. Tampilan admin badan usaha ULS dan GMD: tanpa menu Settings, menu Karyawan dan filter Area hanya berisi ULS dan GMD
                </p>
            </div>
        </x-filament::section>

        {{-- Tim Saya --}}
        <x-filament::section>
            <x-slot name="heading">Tim Saya untuk Atasan</x-slot>
            <x-slot name="description">Menu Tim Saya berisi anggota tim berdasarkan Approval Line, bukan berdasarkan badan usaha.</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-users" class="h-4 w-4 text-primary-600 dark:text-primary-400" />
                        Siapa anggota Tim Saya
                    </h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li><strong>Bawahan langsung</strong>: karyawan yang field <strong>Approval Line</strong>-nya adalah Anda.</li>
                        <li><strong>Satu level di bawahnya</strong>: karyawan yang Approval Line-nya adalah bawahan langsung Anda.</li>
                        <li>Level yang lebih dalam dan karyawan yang sudah dihapus tidak ditampilkan.</li>
                        <li>Akun ADMIN tidak ditampilkan di daftar Tim Saya (kecuali bagi super admin). Namun KPI, Kehadiran, Penilaian, dan Jurnal Harian akun ADMIN yang menjadi bawahan Anda tetap muncul di menu masing-masing, sesuai Approval Line.</li>
                        <li>Menu muncul untuk Jabatan TEAM LEADER, COORDINATOR, MANAGER, CHIEF, BOD, atau siapa pun yang punya bawahan.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-shield-check" class="h-4 w-4 text-success-600 dark:text-success-400" />
                        Yang dapat dikelola atasan
                    </h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Melihat dan mengubah profil serta posisi anggota tim, termasuk <strong>Ubah Posisi Massal</strong>.</li>
                        <li>Menghapus anggota tim (<strong>Hapus</strong>). Karyawan yang dihapus hilang dari Tim Saya dan hanya bisa dipulihkan dari tab <strong>Arsip</strong> di menu Karyawan, oleh super admin atau admin yang badan usahanya mencakup karyawan tersebut.</li>
                        <li>Mengelola <strong>KPI</strong>, <strong>Kehadiran</strong>, dan <strong>Penilaian</strong> bawahan langsung dan satu level di bawahnya (sesuai Approval Line), termasuk akun ADMIN yang menjadi bawahan walaupun tidak tampil di Tim Saya. Bagi admin badan usaha tertentu, data akun ADMIN hanya bisa dilihat.</li>
                        <li>Melihat <strong>Jurnal Harian</strong> anggota tim (tidak dapat mengubah atau menghapusnya).</li>
                        <li>Saat atasan menambah anggota baru dari Tim Saya, <strong>Approval Line</strong> otomatis terisi nama atasan tersebut.</li>
                    </ul>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">Admin yang juga atasan</p>
                <p class="mt-1">Admin badan usaha tertentu yang punya bawahan melihat kedua menu: <strong>Karyawan</strong> untuk badan usahanya dan <strong>Tim Saya</strong> untuk anggota timnya, termasuk yang berada di badan usaha lain.</p>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/panduan-akses-data/akses-data-tim-saya.gif') }}"
                    alt="GIF atasan membuka menu Tim Saya dan melihat anggota tim di badan usaha lain yang Approval Line-nya atasan tersebut"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF {{ $gifNumber++ }}. Atasan membuka Tim Saya: anggota di ULS dan GMD tampil karena Approval Line-nya atasan tersebut
                </p>
            </div>
        </x-filament::section>

        {{-- Matriks --}}
        <x-filament::section>
            <x-slot name="heading">Siapa Melihat Apa</x-slot>
            <x-slot name="description">Ringkasan hak akses per peran. Kolom yang ditandai "Anda" sesuai akun yang sedang login.</x-slot>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Menu / Data</th>
                            @foreach ($roleColumns as $roleKey => $roleLabel)
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">
                                    <span class="flex flex-wrap items-center gap-2">
                                        {{ $roleLabel }}
                                        @if ($roleKey === $viewerRole)
                                            <x-filament::badge color="primary">Anda</x-filament::badge>
                                        @endif
                                    </span>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($this->getAccessMatrix() as $row)
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">{{ $row['module'] }}</td>
                                @foreach (array_keys($roleColumns) as $roleKey)
                                    <td @class([
                                        'px-4 py-3 text-gray-700 dark:text-gray-300',
                                        'bg-primary-50 dark:bg-primary-500/10' => $roleKey === $viewerRole,
                                    ])>{{ $row[$roleKey] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Beberapa akun yang ditunjuk khusus (misalnya pengelola kehadiran atau cutpoint) dapat memiliki akses tambahan.
            </p>
        </x-filament::section>

        {{-- FAQ --}}
        <x-filament::section>
            <x-slot name="heading">FAQ & Penanganan Masalah</x-slot>
            <x-slot name="description">Cek bagian ini jika data atau menu tidak muncul seperti yang diharapkan.</x-slot>

            <div class="space-y-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">1. Karyawan tidak muncul di menu Karyawan</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300">
                        <li><strong>Cek field Area</strong> karyawan tersebut. Jika Area-nya bukan badan usaha yang diberikan ke admin, karyawan tidak akan tampil.</li>
                        @if ($isSuperAdmin)
                            <li><strong>Cek Akses Data admin</strong> (kolom Akses Data di tabel Karyawan). Tambahkan badan usahanya bila perlu.</li>
                        @else
                            <li>Jika karyawan berada di badan usaha lain, <strong>minta super admin</strong> menambahkan badan usaha tersebut ke Akses Data Anda.</li>
                        @endif
                        <li>Jika karyawan berjabatan <strong>ADMIN</strong>, ia hanya terlihat oleh super admin.</li>
                        <li>Jika karyawan sudah dihapus, cari di tab <strong>Arsip</strong>.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">2. Karyawan tidak muncul di menu Tim Saya</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300">
                        <li><strong>Cek field Approval Line</strong> karyawan tersebut. Isinya harus Anda, atau bawahan langsung Anda.</li>
                        <li>Karyawan yang berada lebih dari dua tingkat di bawah Anda tidak ditampilkan.</li>
                        <li>Karyawan yang sudah dihapus (lewat <strong>Hapus</strong>) tidak ditampilkan di Tim Saya, karena Tim Saya tidak punya tab Arsip. Minta super admin atau admin yang badan usahanya mencakup karyawan tersebut memulihkannya dari tab <strong>Arsip</strong> di menu Karyawan.</li>
                        <li>Akun ADMIN tidak ditampilkan di daftar Tim Saya (kecuali bagi super admin), tetapi datanya tetap muncul di menu KPI, Kehadiran, Penilaian, dan Jurnal Harian.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">3. Menu Settings tidak terlihat</h3>
                    <p class="mt-2 text-gray-700 dark:text-gray-300">Menu <strong>Settings</strong> dan <strong>KPI Settings</strong> (master data dan pengaturan) khusus super admin. Admin badan usaha tertentu dan atasan memang tidak melihatnya. Hubungi super admin jika ada master data yang perlu diubah.</p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">4. Tidak bisa mengubah data akun ADMIN</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-700 dark:text-gray-300">
                        <li>Profil akun ADMIN (form karyawan) hanya dapat diubah oleh super admin.</li>
                        <li>Admin badan usaha tertentu hanya dapat melihat KPI, Kehadiran, Penilaian, Cutpoint, dan Jurnal Harian milik akun ADMIN lain.</li>
                        <li>Untuk akunnya sendiri, Kehadiran, Penilaian, dan Cutpoint dicatat oleh super admin, sedangkan KPI dan Jurnal Harian miliknya sendiri tetap bisa ia ubah.</li>
                        <li>Atasan dengan Jabatan selain ADMIN yang menjadi Approval Line seorang ADMIN (atau satu level di atasnya) tetap dapat mengelola KPI, Kehadiran, dan Penilaian ADMIN tersebut, walaupun akun itu tidak tampil di menu Tim Saya.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">5. Opsi ADMIN tidak ada di field Jabatan, atau bagian Akses Data tidak muncul</h3>
                    <p class="mt-2 text-gray-700 dark:text-gray-300">Keduanya khusus super admin. Bagian <strong>Akses Data</strong> juga baru muncul setelah field <strong>Jabatan</strong> diisi <strong>ADMIN</strong>.</p>
                </div>

                @if ($isSuperAdmin)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">6. Admin baru hanya melihat badan usahanya sendiri</h3>
                        <p class="mt-2 text-gray-700 dark:text-gray-300">Ini memang aturannya: karyawan yang dijadikan ADMIN (dari form, Import User, atau API tanpa akses data) mendapat <strong>Badan Usaha Tertentu</strong> dengan Area-nya sendiri, bukan Semua Badan Usaha. Tambahkan badan usaha lain di field <strong>Badan Usaha</strong>, atau jadikan super admin dengan <strong>Semua Badan Usaha</strong> dan centang konfirmasinya (SOP 2).</p>
                    </div>
                @endif

                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    <h3 class="font-semibold">{{ $isSuperAdmin ? 7 : 6 }}. Pesan saat menyimpan form karyawan</h3>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong>Badan usaha berada di luar cakupan akses data Anda.</strong> Pilih Area yang termasuk badan usaha Anda.</li>
                        <li><strong>Approval Line berada di luar cakupan akses data Anda.</strong> Pilih atasan dari daftar yang tersedia.</li>
                        <li><strong>Approval Line ini akan memasukkan karyawan di luar cakupan akses data Anda ke Tim Saya.</strong> Pilih atasan lain.</li>
                        <li><strong>Hanya admin dengan akses seluruh badan usaha yang dapat memberikan role ADMIN.</strong> Minta super admin melakukannya.</li>
                        @if ($isSuperAdmin)
                            <li><strong>Centang konfirmasi ini untuk memberi akses Semua Badan Usaha.</strong> Semua Badan Usaha dipilih tanpa mencentang <strong>Saya yakin menjadikan karyawan ini super admin</strong>. Centang bila memang ingin menjadikan super admin, atau pilih <strong>Badan Usaha Tertentu</strong>.</li>
                        @endif
                    </ul>
                </div>
            </div>
        </x-filament::section>

        {{-- Aksi cepat --}}
        <x-filament::section>
            <x-slot name="heading">Aksi Cepat</x-slot>

            <div class="flex flex-wrap gap-3">
                @if ($employeesUrl)
                    <x-filament::button tag="a" :href="$employeesUrl" icon="heroicon-m-user-group">
                        Buka Menu Karyawan
                    </x-filament::button>
                @endif
                @if ($teamUrl)
                    <x-filament::button tag="a" :href="$teamUrl" color="gray" icon="heroicon-m-users">
                        Buka Menu Tim Saya
                    </x-filament::button>
                @endif
                @if ($areaSettingsUrl)
                    <x-filament::button tag="a" :href="$areaSettingsUrl" color="gray" icon="heroicon-m-building-office-2">
                        Buka Settings &gt; Area
                    </x-filament::button>
                @endif
                @if ($positionGuideUrl)
                    <x-filament::button tag="a" :href="$positionGuideUrl" color="gray" icon="heroicon-m-book-open">
                        Panduan Ubah Posisi
                    </x-filament::button>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
