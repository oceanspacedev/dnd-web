<x-filament-panels::page>
    <div class="w-full space-y-6">
        {{-- Section 1: Ringkasan --}}
        <x-filament::section>
            <x-slot name="heading">Panduan Ubah Posisi Karyawan</x-slot>
            <x-slot name="description">
                Panduan operasional bagi Atasan dan Administrator untuk pemindahan posisi jabatan karyawan, baik perorangan maupun secara massal (bulk update).
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
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Bawahan Langsung & Tim</p>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 2: Langkah SOP --}}
        <x-filament::section>
            <x-slot name="heading">SOP - Cara Mengubah Posisi Banyak Karyawan Sekaligus</x-slot>
            <x-slot name="description">Langkah-langkah praktis mengubah posisi hingga belasan karyawan hanya dalam satu kali proses.</x-slot>

            <ol class="list-decimal space-y-3 pl-5 text-sm text-gray-700 dark:text-gray-300">
                <li>
                    Buka menu <strong>Karyawan</strong> (atau <strong>Tim Saya</strong> bagi Atasan non-admin).
                </li>
                <li>
                    Temukan karyawan yang akan dipindahkan posisinya menggunakan kolom <strong>Pencarian</strong> atau menu <strong>Filter</strong>.
                </li>
                <li>
                    <strong>Centang kotak seleksi</strong> di samping kiri nama-nama karyawan yang ingin dipindahkan (bisa memilih banyak orang sekaligus).
                </li>
                <li>
                    Klik tombol menu <strong>Aksi Terpilih</strong> yang muncul di tabel, lalu pilih opsi <strong>Ubah Posisi Massal</strong>.
                </li>
                <li>
                    Pada panel samping (<em>slide-over</em>), pilih nama <strong>Posisi Baru</strong> yang dituju dari dropdown pencarian.
                </li>
                <li>
                    Klik tombol <strong>Terapkan Posisi Baru</strong> untuk menyimpan perubahan. Sistem akan otomatis memperbarui posisi semua karyawan yang dipilih secara bersamaan.
                </li>
            </ol>

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
                        <li><strong>Administrator</strong>: Memiliki hak mutasi untuk seluruh karyawan di seluruh divisi dan cabang.</li>
                    </ul>
                </div>
            </div>
        </x-filament::section>

        {{-- Section 4: Aksi Cepat --}}
        <x-filament::section>
            <x-slot name="heading">Aksi Cepat</x-slot>
            <div class="flex flex-wrap gap-3">
                <x-filament::button tag="a" :href="\App\Filament\Resources\Users\UserResource::getUrl('index')" icon="heroicon-m-users">
                    Buka Halaman Karyawan
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
