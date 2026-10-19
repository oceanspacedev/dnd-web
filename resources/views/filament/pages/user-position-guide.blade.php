<x-filament-panels::page>
    <div class="w-full space-y-6">
        {{-- Section 1: Ringkasan --}}
        <x-filament::section>
            <x-slot name="heading">Panduan Ubah Posisi Karyawan</x-slot>
            <x-slot name="description">
                Panduan operasional bagi Atasan dan Admin untuk pemindahan posisi jabatan karyawan, baik perorangan maupun secara massal (bulk update), sesuai cakupan akses masing-masing.
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Target Pengguna</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Atasan (Semua Level) & Admin</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Metode Eksekusi</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Aksi Massal (Tabel Karyawan)</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cakupan Akses</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Tim Saya (Atasan) & Badan Usaha (Admin)</p>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 2: Langkah SOP --}}
        <x-filament::section>
            <x-slot name="heading">SOP - Cara Mengubah Posisi Banyak Karyawan Sekaligus</x-slot>
            <x-slot name="description">Langkah-langkah praktis mengubah posisi hingga belasan karyawan hanya dalam satu kali proses.</x-slot>

            <ol class="list-decimal space-y-3 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>
                    Buka menu <strong>Karyawan</strong> (khusus Admin, berisi karyawan di badan usaha yang menjadi cakupan akses datanya) atau menu <strong>Tim Saya</strong> (Atasan, berisi anggota tim sesuai Approval Line).
                </li>
                <li>
                    Temukan karyawan yang akan dipindahkan posisinya menggunakan kolom <strong>Pencarian</strong> atau menu <strong>Filter</strong>.
                </li>
                <li>
                    <strong>Centang kotak seleksi</strong> di samping kiri nama-nama karyawan yang ingin dipindahkan (bisa memilih banyak orang sekaligus).
                </li>
                <li>
                    Klik tombol menu <strong>Tindakan</strong> yang muncul di atas tabel, lalu pilih opsi <strong>Ubah Posisi Massal</strong>.
                </li>
                <li>
                    Pada panel samping (<em>slide-over</em>), pilih nama <strong>Posisi Baru</strong> yang dituju dari dropdown pencarian.
                </li>
                <li>
                    Klik tombol <strong>Terapkan Posisi Baru</strong> untuk menyimpan perubahan. Sistem akan otomatis memperbarui posisi semua karyawan yang dipilih secara bersamaan.
                </li>
            </ol>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/panduan-ubah-posisi/ubah-posisi-massal.gif') }}"
                    alt="GIF admin memfilter Area, mencentang beberapa karyawan, lalu menerapkan Ubah Posisi Massal di menu Karyawan"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 1. Admin: filter Area, centang karyawan, lalu Tindakan &gt; Ubah Posisi Massal (menu Karyawan)
                </p>
            </div>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/panduan-ubah-posisi/ubah-posisi-tim-saya.gif') }}"
                    alt="GIF atasan membuka menu Tim Saya, mencentang anggota tim, lalu menerapkan Ubah Posisi Massal"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 2. Atasan: ubah posisi beberapa anggota tim sekaligus melalui menu Tim Saya
                </p>
            </div>

            <div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold flex items-center gap-2">
                    <x-filament::icon icon="heroicon-m-light-bulb" class="h-5 w-5 text-sky-600 dark:text-sky-400" />
                    Tips Praktis:
                </p>
                <p class="mt-1">
                    Jika nama posisi baru belum ada di daftar, Anda dapat langsung membuatnya melalui tombol <strong>tambah (+)</strong> di samping form pilihan posisi baru tanpa perlu pindah halaman.
                </p>
            </div>
        </x-filament::section>

        {{-- Section 2b: Ubah posisi satu karyawan --}}
        <x-filament::section>
            <x-slot name="heading">SOP - Cara Mengubah Posisi Satu Karyawan</x-slot>
            <x-slot name="description">Gunakan cara ini bila hanya satu karyawan yang pindah posisi.</x-slot>

            <ol class="list-decimal space-y-3 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>
                    Buka menu <strong>Karyawan</strong> (Admin) atau <strong>Tim Saya</strong> (Atasan), lalu ketik nama karyawan pada kolom <strong>Cari</strong>.
                </li>
                <li>
                    Klik tombol <strong>Ubah</strong> di ujung kanan baris karyawan tersebut (geser tabel ke kanan bila tombolnya belum terlihat).
                </li>
                <li>
                    Pada form karyawan, pilih posisi yang baru di kolom <strong>Posisi</strong>.
                </li>
                <li>
                    Klik <strong>Simpan</strong>. Notifikasi <strong>Data berhasil disimpan</strong> menandakan posisi karyawan sudah diperbarui.
                </li>
            </ol>

            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                <img src="{{ asset('images/panduan-ubah-posisi/ubah-posisi-perorangan.gif') }}"
                    alt="GIF mencari satu karyawan, membuka tombol Ubah, mengganti Posisi, lalu menyimpan"
                    class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                    GIF 3. Ubah posisi satu karyawan: Cari, Ubah, pilih Posisi, lalu Simpan
                </p>
            </div>
        </x-filament::section>

        {{-- Section 3: Tips Pencarian & Ketentuan Akses --}}
        <x-filament::section>
            <x-slot name="heading">Panduan Pencarian & Ketentuan Hak Akses</x-slot>
            <x-slot name="description">Penjelasan fitur pencarian serta wewenang mutasi posisi di sistem.</x-slot>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4 text-primary-600 dark:text-primary-400" />
                        Pencarian & Filter Cepat
                    </h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li><strong>Pencarian Utama</strong>: Ketik nama karyawan, posisi jabatan, atau divisi langsung pada kolom cari di atas tabel.</li>
                        <li><strong>Filter Area & Divisi</strong>: Seluruh dropdown filter kini dilengkapi fitur pencarian kata kunci agar mudah memilih opsi tanpa harus mencari manual.</li>
                        <li><strong>Pilih Semua</strong>: Centang kotak di kepala tabel untuk menandai seluruh karyawan di halaman aktif sekaligus.</li>
                    </ul>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-shield-check" class="h-4 w-4 text-success-600 dark:text-success-400" />
                        Wewenang Atasan
                    </h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li><strong>Hirarki Bawahan</strong>: Atasan (Team Leader, Koordinator, Manager, Chief, BOD) berwenang memindahkan posisi karyawan yang berada di bawah struktur timnya.</li>
                        <li><strong>Keamanan Sistem</strong>: Atasan tidak dapat mengutak-atik posisi karyawan di luar struktur bawahannya atau atasan lainnya.</li>
                        <li><strong>Admin Semua Badan Usaha</strong>: Memiliki hak mutasi untuk seluruh karyawan di semua badan usaha melalui menu <strong>Karyawan</strong>.</li>
                        <li><strong>Admin Badan Usaha Tertentu</strong>: Menu <strong>Karyawan</strong> hanya berisi karyawan pada badan usaha yang diberikan kepadanya. Anggota timnya sendiri tetap dapat dikelola melalui menu <strong>Tim Saya</strong>.</li>
                    </ul>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 4: Aksi Cepat --}}
        <x-filament::section>
            <x-slot name="heading">Aksi Cepat</x-slot>
            <div class="flex flex-wrap gap-3">
                <x-filament::button tag="a" :href="$this->getUserListUrl()" icon="heroicon-m-users">
                    {{ $this->getUserListLabel() }}
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
