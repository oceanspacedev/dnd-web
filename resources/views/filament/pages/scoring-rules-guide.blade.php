<x-filament-panels::page>
    <div class="mx-auto w-full max-w-6xl space-y-6">
        {{-- Ringkasan --}}
        <x-filament::section>
            <x-slot name="heading">Ringkasan Aturan Penilaian Kinerja</x-slot>
            <x-slot name="description">
                Cara sistem menghitung nilai kinerja bulanan dari KPI, Kehadiran, dan Penilaian. Aturan, bobot, dan contoh angka di halaman ini dibaca langsung dari pengaturan yang tersimpan, jadi selalu sama dengan perhitungan sistem.
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Aturan Bulan Ini ({{ $currentPeriodeLabel }})</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $currentRule['name'] }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $currentRule['sinceStart'] ? 'Dipakai sejak awal, sebelum ada versi lain' : 'Berlaku mulai '.$currentRule['startLabel'] }}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Bobot Nilai Akhir</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $currentRule['weightsText'] }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Total bobot selalu 100</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Bagian untuk Anda</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                        Rumus &amp; Contoh Hitung{{ $canInputAttendance ? ' + Input Kehadiran' : '' }}{{ $canManageRules ? ' + Pengaturan Aturan' : '' }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Bagian lain tampil sesuai menu yang bisa Anda buka</p>
                </div>
            </div>
        </x-filament::section>

        {{-- Rumus --}}
        <x-filament::section>
            <x-slot name="heading">Rumus Nilai Akhir</x-slot>
            <x-slot name="description">Nilai kinerja bulanan terdiri dari tiga bagian. Bobot di bawah adalah bobot bulan ini ({{ $currentPeriodeLabel }}).</x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">1) KPI</h3>
                        <x-filament::badge color="primary">Bobot {{ $currentRule['weights']['kpi'] }}</x-filament::badge>
                    </div>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Skor KPI = nilai KPI (%) × bobot KPI / 100.</p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Paling tinggi sebesar bobot KPI, walaupun nilai KPI di atas 100%.</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">2) Kehadiran</h3>
                        <x-filament::badge color="primary">Bobot {{ $currentRule['weights']['attendance'] }}</x-filament::badge>
                    </div>
                    <ol class="mt-2 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
                        <li><strong>Rumus dasar</strong> = (hari kerja − jumlah komponen yang <em>mengurangi rumus dasar</em>) / hari kerja × 100.</li>
                        <li><strong>Penalti</strong> = jumlah × penalti per hari/kejadian, dijumlahkan untuk semua komponen.</li>
                        <li><strong>Pencapaian akhir</strong> = rumus dasar − penalti, paling kecil 0.</li>
                        <li><strong>Skor Kehadiran</strong> = pencapaian akhir / 100 × bobot Kehadiran.</li>
                    </ol>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Di form Kehadiran, tulisan "mengurangi hari kerja" artinya sama dengan <em>mengurangi rumus dasar</em>.</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">3) Penilaian</h3>
                        <x-filament::badge color="primary">Bobot {{ $currentRule['weights']['review'] }}</x-filament::badge>
                    </div>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">Skor Penilaian = total poin / poin maksimal ({{ $currentRule['reviewMaxPoints'] }}) × bobot Penilaian.</p>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Total poin = <strong>Responsivitas</strong> + <strong>Pemecah Masalah</strong> (di tabel: Pemecahan Masalah) + <strong>Kepedulian</strong> (di tabel: Kesediaan Membantu) + <strong>Inisiatif</strong>, masing-masing 0-5, diisi di menu Penilaian.</p>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                <p class="font-semibold">Nilai akhir = Skor KPI + Skor Kehadiran + Skor Penilaian (paling tinggi 100).</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Belum ada data Kehadiran bulan itu, atau Hari Kerja 0: Skor Kehadiran 0.</li>
                    <li>Belum ada Penilaian bulan itu: Skor Penilaian 0.</li>
                    <li>Di Leaderboard, potongan <strong>Cutpoint</strong> bulan itu (bila ada) dikurangkan dari nilai akhir.</li>
                    <li>Nama kolom di Leaderboard: Skor KPI = <strong>KPI Score</strong>, Skor Kehadiran = <strong>Attendance</strong>, Skor Penilaian = <strong>Activity</strong>, nilai akhir setelah Cutpoint = <strong>Total Score</strong>. Leaderboard menulis desimal dengan titik (84.34 sama dengan 84,34).</li>
                </ul>
            </div>
        </x-filament::section>

        {{-- Aturan berlaku --}}
        <x-filament::section>
            <x-slot name="heading">Aturan yang Berlaku Bulan Ini</x-slot>
            <x-slot name="description">Versi aturan untuk {{ $currentPeriodeLabel }}, dibaca langsung dari menu Aturan Penilaian.</x-slot>

            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $currentRule['name'] }}</h3>
                    <x-filament::badge color="success">Berlaku</x-filament::badge>
                </div>
                <div class="mt-3 grid gap-4 md:grid-cols-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Mulai Berlaku</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $currentRule['startLabel'] }}</p>
                        @if ($currentRule['sinceStart'])
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Bila di menu lain tertulis {{ $sinceStartPeriodeLabel }} ({{ $sinceStartPeriode }}), itu hanya penanda versi pertama, bukan bulan mulai sebenarnya.</p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Bobot</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $currentRule['weightsText'] }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Poin Maksimal Penilaian</p>
                        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $currentRule['reviewMaxPoints'] }}</p>
                    </div>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Komponen Kehadiran</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Mengurangi Rumus Dasar</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Penalti</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($currentRule['components'] as $component)
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $component['name'] }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">(per {{ $component['unit'] }})</span>
                                    @if (filled($component['description']))
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $component['description'] }}</span>
                                    @endif
                                </td>
                                @if ($component['counted'])
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $component['reducesBase'] ? 'Ya' : 'Tidak' }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                        {{ $component['penaltyText'] }}
                                        @if ($component['noEffect'])
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">Dicatat, tetapi tidak memengaruhi nilai.</span>
                                        @endif
                                    </td>
                                @elseif ($component['usable'])
                                    <td colspan="2" class="px-4 py-3 text-gray-500 dark:text-gray-400">Tidak dinilai versi ini (jumlahnya tidak memengaruhi nilai).</td>
                                @else
                                    <td colspan="2" class="px-4 py-3 text-gray-500 dark:text-gray-400">Tidak dipakai versi ini: isiannya nonaktif di form Kehadiran dan kolomnya tidak ada di template periode versi ini.</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($nextRule)
                <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                    <p class="font-semibold flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-calendar-days" class="h-5 w-5 text-sky-600 dark:text-sky-400" />
                        Versi berikutnya sudah dijadwalkan: {{ $nextRule['name'] }} (mulai {{ $nextRule['startLabel'] }})
                    </p>
                    <p class="mt-2">Bobot: {{ $nextRule['weightsText'] }} · Poin maksimal penilaian {{ $nextRule['reviewMaxPoints'] }}.</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        @foreach ($nextRule['summaries'] as $summary)
                            <li>{{ $summary }}</li>
                        @endforeach
                    </ul>
                    @if ($nextRule['following'])
                        <p class="mt-2">Data bulan {{ $nextRule['startLabel'] }} sampai sebelum {{ $nextRule['following']['startLabel'] }} dinilai dengan versi ini; mulai {{ $nextRule['following']['startLabel'] }} berlaku {{ $nextRule['following']['name'] }} (lihat tabel Riwayat &amp; Jadwal Versi Aturan). Data bulan sebelumnya tetap memakai versinya sendiri.</p>
                    @else
                        <p class="mt-2">Data bulan {{ $nextRule['startLabel'] }} sampai ada versi berikutnya dinilai dengan versi ini (lihat tabel Riwayat &amp; Jadwal Versi Aturan). Data bulan sebelumnya tetap memakai versinya sendiri.</p>
                    @endif
                </div>
            @endif
        </x-filament::section>

        {{-- Semua versi --}}
        <x-filament::section>
            <x-slot name="heading">Riwayat &amp; Jadwal Versi Aturan</x-slot>
            <x-slot name="description">Setiap versi berlaku mulai bulan tertentu sampai ada versi berikutnya.</x-slot>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Berlaku Mulai</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Nama Versi</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                            <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Bobot</th>
                            @if ($canManageRules)
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Penguncian</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($versions as $version)
                            @php
                                // Teks berwarna, bukan badge: badge terpotong ("Terj...") di kolom tabel yang sempit.
                                $statusClass = match ($version['statusColor']) {
                                    'success' => 'text-success-600 dark:text-success-400',
                                    'info' => 'text-sky-600 dark:text-sky-400',
                                    default => 'text-gray-500 dark:text-gray-400',
                                };
                            @endphp
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $version['startLabel'] }}
                                    @if ($version['sinceStart'])
                                        <span class="block text-xs font-medium text-gray-500 dark:text-gray-400">Tercatat {{ $sinceStartPeriodeLabel }} (penanda versi pertama)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $version['name'] }}</td>
                                <td class="px-4 py-3 font-semibold {{ $statusClass }}">{{ $version['statusLabel'] }}</td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $version['weightsText'] }}</td>
                                @if ($canManageRules)
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $version['lockText'] }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">Setiap bulan dinilai dengan versinya sendiri</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>Nilai sebuah bulan selalu dihitung dengan versi yang berlaku untuk bulan tersebut, bukan versi hari ini.</li>
                    <li>Versi baru hanya berlaku mulai bulannya, sehingga nilai bulan-bulan sebelumnya tidak ikut berubah.</li>
                    <li><strong>Berlaku</strong> = dipakai bulan ini, <strong>Terjadwal</strong> = mulai bulan mendatang, <strong>Riwayat</strong> = sudah digantikan versi berikutnya.</li>
                </ul>
            </div>
        </x-filament::section>

        {{-- Contoh hitung --}}
        <x-filament::section>
            <x-slot name="heading">Contoh Perhitungan</x-slot>
            <x-slot name="description">Dihitung otomatis dengan rumus sistem (sama dengan Leaderboard) memakai {{ $currentRule['name'] }}, versi yang berlaku bulan ini.</x-slot>

            @if ($example === null)
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/30 dark:bg-red-500/10 dark:text-red-200">
                    Contoh tidak dapat dihitung untuk versi ini. Hubungi super admin untuk memeriksa pengaturan Aturan Penilaian.
                </div>
            @else
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-700 dark:border-white/10 dark:bg-gray-900/40 dark:text-gray-300">
                    <p class="font-semibold text-gray-900 dark:text-gray-100">Data contoh satu karyawan dalam sebulan:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Hari kerja {{ $example['workDays'] }} hari.</li>
                        <li>{{ implode(', ', $exampleEvents) }}.</li>
                        <li>Nilai KPI {{ $example['kpiRaw'] }}%.</li>
                        <li>Total poin penilaian {{ $example['reviewPoints'] }} dari {{ $example['reviewMaxPoints'] }}.</li>
                    </ul>
                </div>

                <p class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">Isian Kehadiran menurut versi ini:</p>
                <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Komponen</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Jumlah</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Mengurangi Rumus Dasar</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Penalti</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Poin Penalti</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($example['rows'] as $row)
                                <tr class="bg-white dark:bg-gray-900/30">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $row['name'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                        {{ $row['count'] }}
                                        @if ($row['note'])
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['note'] }}</span>
                                        @endif
                                    </td>
                                    @if ($row['counted'])
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['reducesBase'] ? 'Ya' : 'Tidak' }}</td>
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['penaltyPerUnit'] }}</td>
                                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $row['penalty'] }}</td>
                                    @else
                                        <td colspan="3" class="px-4 py-3 text-gray-500 dark:text-gray-400">Tidak dinilai versi ini.</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($example['izinInSick'])
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                        Versi ini belum memakai izin sebagai komponen sendiri, jadi izin dicatat di <strong>{{ $sickIzinLabel }}</strong> bersama sakit (sakit + izin).
                        @if ($izinFromLabel)
                            Mulai {{ $izinFromLabel }}, izin dicatat sendiri di <strong>{{ $izinName }}</strong>.
                        @endif
                    </div>
                @endif

                <ol class="mt-4 list-decimal space-y-3 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>
                        <strong>Rumus dasar</strong> = ({{ $example['workDays'] }} − {{ $example['reducingCount'] }}) / {{ $example['workDays'] }} × 100 = <strong>{{ $example['basePct'] }}%</strong>
                        @if ($example['reducingTerms'] !== '')
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $example['reducingCount'] }} = {{ $example['reducingTerms'] }} (komponen yang mengurangi rumus dasar).</span>
                        @else
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Tidak ada komponen contoh yang mengurangi rumus dasar pada versi ini.</span>
                        @endif
                    </li>
                    <li><strong>Penalti</strong> = {{ $example['penaltyTerms'] }} = <strong>{{ $example['penalty'] }} poin</strong></li>
                    <li>
                        <strong>Pencapaian akhir</strong> = {{ $example['basePct'] }}% − {{ $example['penalty'] }} = <strong>{{ $example['finalPct'] }}%</strong>
                        @if ($example['finalClamped'])
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Hasil di bawah 0 dihitung 0.</span>
                        @endif
                    </li>
                    <li><strong>Skor Kehadiran</strong> = {{ $example['finalPct'] }} / 100 × {{ $example['attendanceWeight'] }} = <strong>{{ $example['attendanceScore'] }}</strong></li>
                    <li><strong>Skor Penilaian</strong> = {{ $example['reviewPoints'] }} / {{ $example['reviewMaxPoints'] }} × {{ $example['reviewWeight'] }} = <strong>{{ $example['reviewScore'] }}</strong></li>
                    <li>
                        <strong>Skor KPI</strong> = {{ $example['kpiRaw'] }} × {{ $example['kpiWeight'] }} / 100 = <strong>{{ $example['kpiScore'] }}</strong>
                        @if ($example['kpiCapped'])
                            <span class="block text-xs text-gray-500 dark:text-gray-400">Hasil {{ $example['kpiUncapped'] }} dibatasi sebesar bobot KPI ({{ $example['kpiWeight'] }}).</span>
                        @endif
                    </li>
                </ol>

                <div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900 dark:border-indigo-400/30 dark:bg-indigo-500/10 dark:text-indigo-200">
                    <p>Nilai akhir = {{ $example['kpiScore'] }} + {{ $example['attendanceScore'] }} + {{ $example['reviewScore'] }} = <strong class="text-base">{{ $example['total'] }}</strong></p>
                </div>

                @if ($nextExample)
                    <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-400/30 dark:bg-sky-500/10 dark:text-sky-100">
                        <p class="font-semibold">Perbandingan dengan versi terjadwal {{ $nextExample['ruleName'] }} (mulai {{ $nextExample['startLabel'] }})</p>
                        <p class="mt-1">
                            Kejadian yang sama menghasilkan rumus dasar {{ $nextExample['basePct'] }}%, penalti {{ $nextExample['penalty'] }} poin,
                            Skor Kehadiran <strong>{{ $nextExample['attendanceScore'] }}</strong>, dan nilai akhir <strong>{{ $nextExample['total'] }}</strong>.
                        </p>
                    </div>
                @endif
            @endif
        </x-filament::section>

        @if ($canInputAttendance)
            {{-- SOP Kehadiran: form --}}
            <x-filament::section>
                <x-slot name="heading">SOP 1 - Mengisi Data Kehadiran Satu per Satu</x-slot>
                <x-slot name="description">Untuk menambah atau memperbaiki data kehadiran satu karyawan di menu Kehadiran.</x-slot>

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Karyawan yang Bisa Anda Isi</p>
                        <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $attendanceScope }}</p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Aturan Menurut Periode Data</p>
                        <ul class="mt-1 space-y-1 text-sm text-gray-900 dark:text-gray-100">
                            <li>Data {{ $previousPeriodeLabel }}: <strong>{{ $previousRuleName }}</strong></li>
                            <li>Data {{ $currentPeriodeLabel }}: <strong>{{ $currentRule['name'] }}</strong></li>
                        </ul>
                    </div>
                </div>

                <ol class="mt-4 list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>Buka menu <strong>Kehadiran</strong>, lalu klik <strong>Buat Kehadiran</strong>.</li>
                    <li>Pilih karyawan di field <strong>Pengguna</strong> (hanya karyawan yang boleh Anda isi datanya).</li>
                    <li><strong>Periode</strong> sudah terisi bulan lalu ({{ $previousPeriodeLabel }}), karena kehadiran direkap untuk bulan yang sudah selesai. Ganti hanya bila mengisi data bulan lain; isi dengan bulan data, bukan bulan Anda menginput. Di bawahnya tampil versi aturan untuk periode tersebut.</li>
                    <li>Isi <strong>Jumlah Hari Kerja</strong> (1 sampai {{ $maxWorkDays }}).</li>
                    <li>
                        Isi jumlah tiap komponen (0 bila tidak ada). Tulisan kecil di kanan atas tiap isian menunjukkan perlakuannya pada aturan periode itu, mis. {{ $formHintExamples }}. Tulisan di bawah isian adalah keterangan komponennya.
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Isian komponen yang tidak dipakai aturan periode itu berwarna abu-abu (nonaktif) dengan tulisan "{{ $unusedHint }}" dan tidak ikut disimpan. Isian berubah langsung saat Periode diganti; angka yang sudah diketik di isian yang menjadi nonaktif dikosongkan dan muncul notifikasi <strong>Isian komponen dikosongkan</strong>.</p>
                    </li>
                    <li>Klik <strong>Buat</strong>, atau <strong>Buat &amp; buat lainnya</strong> untuk langsung mengisi karyawan berikutnya: Periode dan Jumlah Hari Kerja tetap terisi, sedangkan Pengguna dan jumlah komponen dikosongkan. Satu karyawan hanya punya satu data per periode; jika sudah ada, muncul notifikasi <strong>Data sudah ada</strong>. Perbaiki data yang ada lewat tombol <strong>Ubah</strong> di tabel.</li>
                    <li>Periksa kolom <strong>Skor Kehadiran</strong> di tabel. Arahkan kursor ke angkanya untuk melihat rincian: rumus dasar, penalti, dan bobot.</li>
                </ol>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                    <img src="{{ asset('images/panduan-aturan-penilaian/kehadiran-isi-form.gif') }}"
                        alt="GIF mengisi kehadiran satu karyawan: klik Buat Kehadiran, Periode sudah terisi bulan lalu dengan versi aturannya, pilih karyawan, isi Jumlah Hari Kerja, Terlambat, dan Sakit/Izin (sakit + izin untuk periode itu), isian Izin dan komponen lain yang tidak dipakai aturan periode itu abu-abu, klik Buat, lalu periksa Skor Kehadiran di tabel"
                        class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                    <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                        GIF 1. Mengisi data kehadiran bulan lalu untuk satu karyawan: isian yang tidak dipakai aturan periode itu nonaktif, lalu Skor Kehadiran tampil di tabel
                    </p>
                </div>

                @if ($izinFromLabel)
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                        <p class="font-semibold">Sakit dan izin</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li>Data periode {{ $izinFromLabel }} dan sesudahnya: izin diisi di <strong>{{ $izinName }}</strong>, bukan di <strong>{{ $sickName }}</strong>.</li>
                            <li>Data periode sebelum {{ $izinFromLabel }}: isian sakit berlabel <strong>{{ $sickIzinLabel }}</strong> dan berisi sakit + izin, seperti cara lama. Isian <strong>{{ $izinName }}</strong> nonaktif untuk periode itu.</li>
                            <li>Di tabel Kehadiran, kolom <strong>{{ $sickName }}</strong> pada data sebelum {{ $izinFromLabel }} berisi sakit + izin. Arahkan kursor ke judul kolom atau angkanya untuk melihat keterangannya.</li>
                            <li>Data lama yang masih menyimpan angka di isian yang tidak dipakai aturannya (mis. {{ $izinName }} untuk periode sebelum {{ $izinFromLabel }}) tetap bisa dibuka lewat <strong>Ubah</strong>: isian itu aktif dengan tulisan "Tersimpan N, tidak dihitung untuk periode ini". Jumlahkan angkanya ke {{ $sickIzinLabel }}, lalu isi 0. Periode data seperti itu tidak bisa dipindah ke bulan yang aturannya tidak memakai isian tersebut sebelum angkanya dikosongkan.</li>
                        </ul>
                    </div>
                @endif
            </x-filament::section>

            {{-- SOP Kehadiran: import --}}
            <x-filament::section>
                <x-slot name="heading">SOP 2 - Import Kehadiran dari Excel</x-slot>
                <x-slot name="description">Untuk mengisi data banyak karyawan sekaligus.</x-slot>

                <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>Buka menu <strong>Kehadiran</strong>, klik <strong>Download Template</strong>. Di jendela <strong>Download Template Kehadiran</strong>, field <strong>Periode</strong> sudah terisi bulan lalu ({{ $previousPeriodeLabel }}); ganti bila mengisi data bulan lain. Di bawahnya tampil versi aturan periode itu dan cara mencatat sakit dan izin. Klik <strong>Download</strong>.</li>
                    <li>File (mis. {{ $templateFileName }}) berisi karyawan yang boleh Anda isi datanya, kolom <strong>Periode</strong> sudah terisi periode yang dipilih, dan hanya kolom komponen yang dipakai aturan periode itu (lihat tabel kolom di bawah). Satu file untuk satu periode; untuk periode lain, download template periode tersebut.</li>
                    <li>Isi <strong>Hari Kerja</strong> (wajib, 1 sampai {{ $maxWorkDays }}) dan jumlah tiap komponen (bilangan bulat 0 sampai {{ $maxCount }}; kosong dianggap 0).</li>
                    <li>Karyawan yang tidak diisi cukup dibiarkan kosong (Hari Kerja dan semua komponen kosong). Baris itu diabaikan, bukan dianggap gagal.</li>
                    <li>Jangan ubah judul kolom. Kolom komponen dikenali dari kode di dalam kurung siku, mis. "{{ $izinHeading ?? 'Nama [kode]' }}".</li>
                    <li>Simpan file (.xlsx atau .xls), lalu klik <strong>Import/Export</strong> → <strong>Import</strong>. Di jendela <strong>Import Data Absensi</strong>, pilih <strong>Periode yang diimport</strong> (sudah terisi bulan lalu, {{ $previousPeriodeLabel }}) yang sama dengan kolom Periode di file. Pilih file di <strong>Upload File Excel:</strong> dan klik <strong>Import</strong>.</li>
                    <li>Bila Periode yang diimport adalah bulan berjalan ({{ $currentPeriodeLabel }}) atau sesudahnya, muncul peringatan <strong>Periode ini belum selesai</strong> (bulan berjalan) atau <strong>Periode ini belum dimulai</strong> (bulan sesudahnya), dan Anda wajib mencentang <strong>Saya yakin mengimport data kehadiran periode ...</strong> sebelum import. Centangnya hilang bila periode diganti. Kehadiran biasanya direkap untuk bulan yang sudah selesai, jadi periksa lagi bulannya.</li>
                    <li>Baca notifikasinya, mis. <strong>Import Kehadiran {{ $previousPeriodeLabel }}: 20 data ditambahkan, 1 data dilewati.</strong> Notifikasi menyebut periode yang diimport, jumlah data ditambahkan, dilewati, dan baris yang belum diisi, alasan untuk 5 baris pertama yang dilewati, serta peringatan. Bila ada alasan atau peringatan, notifikasi tetap tampil sampai ditutup.</li>
                    <li>Perbaiki baris yang dilewati, lalu import ulang hanya baris itu: hapus baris yang sudah berhasil masuk dari file, atau kosongkan <strong>Hari Kerja</strong> dan semua kolom komponennya (baris kosong diabaikan). Jangan import ulang file yang sama apa adanya: baris yang sudah masuk akan dilewati dengan alasan "sudah ada", dan karena notifikasi hanya menampilkan alasan 5 baris pertama yang dilewati, kesalahan lain bisa tidak terlihat.</li>
                </ol>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                    <img src="{{ asset('images/panduan-aturan-penilaian/kehadiran-import.gif') }}"
                        alt="GIF import kehadiran: Download Template dengan Periode bulan lalu, lalu Import/Export, Import, Periode yang diimport sudah terisi bulan lalu, unggah file template yang sudah diisi, klik Import, dan baca notifikasi Import Kehadiran periode itu: jumlah data ditambahkan, dilewati, serta baris template yang belum diisi"
                        class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                    <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                        GIF 2. Download template dan import file yang sudah diisi untuk periode bulan lalu, lalu membaca notifikasi hasilnya
                    </p>
                </div>

                <p class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">Kolom template (kolom komponen mengikuti aturan periode template):</p>
                <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Judul Kolom</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Ada di Template Periode</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Cara Mengisi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($templateColumns as $column)
                                <tr class="bg-white dark:bg-gray-900/30">
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{{ $column['heading'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $column['periodes'] }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $column['fill'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Aturan penting import</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Data karyawan + periode yang sudah ada <strong>tidak ditimpa</strong>. Untuk mengoreksi, pakai tombol <strong>Ubah</strong> di menu Kehadiran.</li>
                        <li>Hanya baris untuk <strong>Periode yang diimport</strong> yang masuk. Sel Periode yang kosong diisi periode itu; baris dengan periode lain dilewati dan diringkas dalam satu peringatan.</li>
                        <li>Baris ganda (karyawan + periode sama) dalam satu file: hanya baris pertama yang diimport.</li>
                        <li>Sheet lain di file yang tidak memuat judul template (ID karyawan, Nama Lengkap, Periode, Hari Kerja) tidak diimport.</li>
                        <li>Periode harus berada di antara tahun {{ $minYear }} dan {{ $maxYear }}.</li>
                        <li>Kolom komponen yang tidak dipakai aturan periode baris itu harus kosong atau 0. Bila berisi angka lebih dari 0, barisnya dilewati (tidak disimpan) dengan alasannya. Template lama yang berisi semua kolom tetap bisa diimport selama kolom itu kosong.</li>
                        @if ($izinFromLabel && $izinHeading)
                            <li>Mulai periode {{ $izinFromLabel }}, izin diisi di kolom <strong>{{ $izinHeading }}</strong>, bukan di kolom <strong>{{ $sickHeading }}</strong>. Template periode sebelumnya tidak punya kolom {{ $izinHeading }}; kolom ke-7-nya berjudul <strong>{{ $sickIzinLabel }}</strong> dan berisi sakit + izin.</li>
                        @endif
                        <li>Setelah nama komponen diubah, atau komponen baru dicantumkan di versi aturan, download ulang template agar kolomnya ikut berubah.</li>
                    </ul>
                </div>
            </x-filament::section>

            {{-- Alasan baris dilewati --}}
            <x-filament::section>
                <x-slot name="heading">Baris Dilewati &amp; Peringatan Import</x-slot>
                <x-slot name="description">Pesan yang muncul di notifikasi import dan cara memperbaikinya.</x-slot>

                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Pesan di Notifikasi</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Penyebab</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Cara Memperbaiki</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Data kehadiran karyawan ini untuk periode tersebut sudah ada.</td>
                                <td class="px-4 py-3">Data karyawan + periode itu sudah tersimpan sebelumnya.</td>
                                <td class="px-4 py-3">Koreksi lewat tombol <strong>Ubah</strong> di menu Kehadiran. Import tidak pernah menimpa data.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Hari Kerja kosong / harus bilangan bulat 1 sampai {{ $maxWorkDays }}.</td>
                                <td class="px-4 py-3">Komponen diisi tetapi Hari Kerja kosong, atau isinya bukan angka 1-{{ $maxWorkDays }}.</td>
                                <td class="px-4 py-3">Isi Hari Kerja dengan bilangan bulat 1 sampai {{ $maxWorkDays }}.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Nilai [kolom] harus bilangan bulat 0 sampai {{ $maxCount }}.</td>
                                <td class="px-4 py-3">Kolom komponen berisi teks, desimal, angka negatif, atau lebih dari {{ $maxCount }}.</td>
                                <td class="px-4 py-3">Isi angka bulat, atau kosongkan bila 0.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Periode "..." tidak dikenali / di luar rentang tahun {{ $minYear }}-{{ $maxYear }}.</td>
                                <td class="px-4 py-3">Sel Periode salah format, atau urutan kolom bergeser. (Sel Periode yang kosong diisi Periode yang diimport.)</td>
                                <td class="px-4 py-3">Isi Periode dengan format YYYY-MM (mis. {{ $previousPeriode }}), kosongkan, atau pakai urutan kolom template.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Aturan penilaian [bulan] belum memakai [komponen], tetapi kolomnya diisi. (Bila tidak ada versi sesudahnya yang memakai komponen itu: "tidak memakai".)</td>
                                <td class="px-4 py-3">
                                    Kolom komponen yang tidak dipakai aturan periode baris itu berisi angka lebih dari 0, mis. file dari template periode lain atau template lama berisi semua kolom.
                                    @if ($izinUnusedExample)
                                        <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Contoh pesan lengkap: {{ $izinUnusedExample }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($izinFromLabel && $izinHeading)
                                        Izin untuk periode sebelum {{ $izinFromLabel }}: jumlahkan angka di kolom {{ $izinHeading }} ke kolom {{ $sickIzinLabel }}, lalu kosongkan kolom {{ $izinHeading }}.
                                    @endif
                                    Komponen lain: kosongkan kolomnya. Bila periodenya yang keliru, perbaiki periodenya. Lebih aman: download template periode itu.
                                </td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Karyawan tidak ditemukan. Periksa ID karyawan dan Nama Lengkap.</td>
                                <td class="px-4 py-3">ID karyawan dan nama tidak cocok dengan karyawan aktif.</td>
                                <td class="px-4 py-3">Salin ID karyawan dan nama dari template terbaru.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Nama "..." dipakai lebih dari satu karyawan.</td>
                                <td class="px-4 py-3">ID karyawan kosong atau tidak dikenal, dan ada beberapa karyawan dengan nama sama.</td>
                                <td class="px-4 py-3">Isi atau perbaiki ID karyawan dari template terbaru.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Karyawan di luar cakupan akses data Anda.</td>
                                <td class="px-4 py-3">Karyawan bukan bagian dari karyawan yang boleh Anda isi.</td>
                                <td class="px-4 py-3">Hapus barisnya, atau minta pengisi yang berwenang memasukkannya.</td>
                            </tr>
                            @if ($isAreaScopedAdmin)
                                <tr class="bg-white dark:bg-gray-900/30">
                                    <td class="px-4 py-3">Kehadiran akun ADMIN (termasuk akun Anda sendiri) hanya dapat dicatat oleh super admin.</td>
                                    <td class="px-4 py-3">Baris milik akun ADMIN.</td>
                                    <td class="px-4 py-3">Hapus barisnya dan minta super admin yang mencatat.</td>
                                </tr>
                            @endif
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">ID karyawan "..." milik karyawan yang sudah dihapus.</td>
                                <td class="px-4 py-3">Karyawan sudah tidak aktif.</td>
                                <td class="px-4 py-3">Hapus baris tersebut.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Baris ganda: karyawan dan periode ini sudah diisi di baris sebelumnya pada file yang sama.</td>
                                <td class="px-4 py-3">Karyawan + periode yang sama muncul lebih dari sekali di file.</td>
                                <td class="px-4 py-3">Gabungkan menjadi satu baris. Hanya baris pertama yang diimport.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3">Kolom Periode / Hari Kerja / ID karyawan dan Nama Lengkap tidak ditemukan di baris judul.</td>
                                <td class="px-4 py-3">Judul kolom diubah atau dihapus.</td>
                                <td class="px-4 py-3">Pakai judul kolom persis seperti template.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">Peringatan di notifikasi import:</p>
                <ul class="mt-2 list-disc space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>
                        <strong>File berisi N baris periode [bulan], bukan [bulan] yang dipilih; baris tersebut dilewati.</strong> Kolom Periode di file berbeda dengan <strong>Periode yang diimport</strong>. Baris itu tidak disimpan dan tidak dicantumkan satu per satu, tetapi ikut dihitung sebagai data dilewati. Bila semua baris berbeda periode, judul notifikasinya <strong>Tidak ada data [bulan] di file. Periksa keterangan di bawah.</strong>
                        <span class="mt-1 block">Bila datanya memang untuk bulan di file, import ulang file itu dan pilih bulan tersebut. Bila datanya untuk bulan yang dipilih, perbaiki atau kosongkan kolom Periode di file.</span>
                    </li>
                    <li><strong>Kolom "..." tidak ada di file sehingga dianggap 0</strong> (data tetap diimport): file memakai template lama yang belum punya kolom komponen yang dinilai pada periode itu. Download template periode itu, lalu isi kolom tersebut lewat tombol <strong>Ubah</strong> untuk data yang sudah masuk.</li>
                    <li><strong>Kolom "..." berisi nilai, tetapi aturan penilaian periode tersebut tidak menghitung komponen ini</strong> (data tetap diimport): hanya untuk tiga kolom lama ({{ $legacyColumnsText }}) bila versi aturan periode itu tidak mencantumkannya. Angkanya disimpan tanpa memengaruhi skor; periksa apakah seharusnya diisi di kolom lain. Kolom komponen lain yang tidak dipakai aturan membuat barisnya dilewati (lihat tabel di atas).</li>
                    <li><strong>Sheet "..." tidak diimport</strong>: sheet itu tidak memuat judul template. Wajar untuk sheet catatan atau rekap; pindahkan data kehadiran ke sheet template bila perlu.</li>
                    <li><strong>Kolom tidak dikenal diabaikan</strong>: ada kolom yang judulnya tidak dikenali. Aman bila kolom itu memang bukan data kehadiran (mis. No, Divisi). Bila itu kolom komponen yang judulnya berubah atau salah ketik, angkanya tidak masuk: perbaiki judulnya sesuai template, lalu koreksi data yang sudah masuk lewat tombol <strong>Ubah</strong>.</li>
                </ul>
            </x-filament::section>
        @endif

        @if ($canManageRules)
            {{-- SOP super admin: aturan --}}
            <x-filament::section>
                <x-slot name="heading">SOP 3 - Mengubah Aturan Penilaian dengan Aman (Super Admin)</x-slot>
                <x-slot name="description">Versi yang sudah dipakai bulan yang telah berakhir tidak pernah diubah. Versi Terjadwal atau yang baru mulai bulan ini masih bisa diubah; bulan yang belum punya versi diberi versi baru yang berlaku mulai bulan tertentu.</x-slot>

                <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>Bila perlu komponen baru (mis. dinas luar), tambahkan dulu di menu <strong>Komponen Kehadiran</strong> (lihat SOP 4).</li>
                    <li>Buka menu <strong>Settings</strong> → <strong>Aturan Penilaian</strong>.</li>
                    <li>Jika bulan yang ingin diubah sudah punya versi <strong>Terjadwal</strong> atau <strong>Berlaku</strong> yang belum terkunci (lihat kolom <strong>Penguncian</strong> di tabel Riwayat &amp; Jadwal Versi Aturan di atas), klik <strong>Ubah</strong> pada baris versi itu, atur isiannya seperti langkah 7 sampai 9, lalu simpan. Buat versi baru (langkah 4 dst.) hanya untuk bulan yang belum punya versi.</li>
                    <li>Pada baris versi terakhir, klik ikon titik tiga (⋮) di ujung kanan baris, lalu pilih <strong>Duplikat jadi versi baru</strong>. Bisa juga klik <strong>Buat Versi Baru</strong> di kanan atas; isiannya disalin dari versi terakhir.</li>
                    <li>Isi <strong>Nama Versi</strong>, mis. "Aturan Kehadiran Januari 2027".</li>
                    <li>Pilih <strong>Berlaku Mulai</strong>: bulan yang belum punya versi, paling awal bulan berjalan ({{ $currentPeriodeLabel }}). Field ini otomatis terisi bulan kosong pertama sesudah bulan berjalan ({{ $nextAvailablePeriodeLabel }}). Pilih bulan berjalan hanya bila aturan baru memang harus dipakai untuk data bulan ini.</li>
                    <li>Atur <strong>Bobot KPI</strong>, <strong>Bobot Kehadiran</strong>, dan <strong>Bobot Penilaian</strong> sampai <strong>Total Bobot</strong> tepat 100 (maksimal 2 angka di belakang koma). <strong>Poin Maksimal Penilaian</strong> paling kecil {{ $minReviewMaxPoints }}.</li>
                    <li>
                        Pada tabel <strong>Aturan Kehadiran</strong>, atur tiap komponen: <strong>Kurangi rumus dasar</strong> (ya/tidak) dan <strong>Penalti per hari/kejadian</strong>. Penalti 0 dan tidak mengurangi rumus dasar berarti komponen tidak memengaruhi nilai.
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Komponen yang tercantum di versi ini, termasuk yang penaltinya 0, bisa diisi untuk periode versi ini di form Kehadiran, template, import, dan API; komponen yang tidak tercantum dinonaktifkan untuk periode itu. Bila {{ $izinName }} tercantum, izin dicatat sendiri dan isian {{ $sickName }} tidak lagi berlabel {{ $sickIzinLabel }}.</p>
                    </li>
                    <li>Buka bagian <strong>Simulasi</strong>: isi contoh hari kerja, jumlah komponen, total poin penilaian, dan nilai KPI mentah. Bandingkan kolom <strong>Versi ini (belum disimpan)</strong> dengan <strong>Berlaku bulan ini</strong>. Angka merah berarti skor melebihi bobotnya.</li>
                    <li>Isi <strong>Catatan</strong> (dasar keputusan, ringkasan perubahan), lalu klik <strong>Buat</strong>.</li>
                    <li>Periksa daftar: versi baru berstatus <strong>Terjadwal</strong> sampai bulannya tiba (atau langsung <strong>Berlaku</strong> bila mulai bulan ini).</li>
                </ol>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                    <img src="{{ asset('images/panduan-aturan-penilaian/aturan-versi-baru.gif') }}"
                        alt="GIF membuat versi aturan baru: ikon titik tiga pada baris versi terakhir, Duplikat jadi versi baru, ubah Nama Versi, Catatan, dan penalti Terlambat lebih dari 30 menit dari 3 menjadi 2 poin, isi 1 kejadian di Simulasi lalu bandingkan penalti, Skor Kehadiran, dan Total dengan versi yang berlaku bulan ini, klik Buat, lalu versi baru berstatus Terjadwal"
                        class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                    <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                        GIF 3. Membuat versi aturan baru dengan Duplikat jadi versi baru dan mengecek Simulasi sebelum disimpan
                    </p>
                </div>

                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-400/30 dark:bg-amber-500/10 dark:text-amber-200">
                    <p class="font-semibold">Apa yang terkunci</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>Versi terjadwal atau yang mulai berlaku bulan ini masih bisa diubah (<strong>Ubah</strong>) atau dihapus.</li>
                        <li>Setelah bulan pertamanya berakhir, versi <strong>terkunci</strong>: hanya nama dan catatan yang bisa diubah lewat <strong>Ubah nama &amp; catatan</strong>, dan versi tidak bisa dihapus.</li>
                        <li>Bulan yang sudah lewat tidak bisa diberi versi baru. Untuk mengubah aturan yang sudah terkunci, buat versi baru dengan <strong>Duplikat jadi versi baru</strong> mulai bulan berjalan atau sesudahnya (bila bulan itu belum punya versi).</li>
                        <li>Status penguncian tiap versi saat ini ada di kolom <strong>Penguncian</strong> pada tabel Riwayat &amp; Jadwal Versi Aturan di atas.</li>
                    </ul>
                </div>
            </x-filament::section>

            {{-- SOP super admin: komponen --}}
            <x-filament::section>
                <x-slot name="heading">SOP 4 - Mengelola Komponen Kehadiran (Super Admin)</x-slot>
                <x-slot name="description">Komponen baru bisa ditambahkan tanpa perubahan program. Penalti tiap komponen diatur per versi di Aturan Penilaian.</x-slot>

                <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
                    <li>Buka menu <strong>Settings</strong> → <strong>Komponen Kehadiran</strong>, klik <strong>Tambah Komponen</strong>.</li>
                    <li>Isi <strong>Kode</strong>: huruf kecil, angka, dan garis bawah, diawali huruf (mis. dinas_luar). Kode tidak bisa diubah setelah dibuat.</li>
                    <li>Isi <strong>Nama Komponen</strong>, pilih <strong>Satuan</strong> (Hari atau Kejadian), lalu <strong>Keterangan</strong> dan <strong>Urutan</strong> bila perlu. Pastikan <strong>Aktif</strong> menyala.</li>
                    <li>Klik <strong>Buat</strong>. Komponen langsung muncul di form versi aturan baru. Di form Kehadiran isiannya masih nonaktif dengan keterangan bahwa komponen belum dicantumkan di versi aturan mana pun, dan kolomnya ("Nama [kode]") belum ada di template.</li>
                    <li>Cantumkan komponen di versi aturan (SOP 3). Komponen baru bisa diisi di form Kehadiran, template, import, dan API untuk periode versi yang mencantumkannya (penalti 0 boleh bila hanya dicatat), dan baru memengaruhi nilai bila dinilai di versi itu (penalti lebih dari 0 atau mengurangi rumus dasar).</li>
                </ol>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-gray-900/40">
                    <img src="{{ asset('images/panduan-aturan-penilaian/komponen-tambah.gif') }}"
                        alt="GIF menambah komponen kehadiran: klik Tambah Komponen, isi Kode, Nama Komponen, Satuan, dan Keterangan, pastikan Aktif menyala, klik Buat, lalu komponen baru tampil dengan keterangan Belum dipakai"
                        class="w-full rounded-lg border border-gray-200 dark:border-white/10" />
                    <p class="mt-2 text-center text-xs text-gray-500 dark:text-gray-400">
                        GIF 4. Menambah komponen kehadiran baru (belum bisa diisi maupun dinilai sampai dicantumkan di versi aturan)
                    </p>
                </div>

                <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Perubahan</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Kapan Boleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-medium">Ganti nama</td>
                                <td class="px-4 py-3">Kapan saja. Download ulang template agar judul kolomnya ikut berubah; file lama tetap terbaca karena import mencocokkan kode.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-medium">Ubah kode</td>
                                <td class="px-4 py-3">Tidak pernah, karena kode dipakai data kehadiran dan aturan penilaian.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-medium">Ubah satuan</td>
                                <td class="px-4 py-3">Hanya selama komponen belum tercantum di versi aturan mana pun (termasuk dengan penalti 0) dan belum punya data kehadiran (komponen bawaan tidak bisa). Setiap versi baru yang dibuat lewat form mencantumkan semua komponen aktif, jadi periksa Satuan sebelum membuat versi baru. Buat komponen baru bila satuannya berbeda.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-medium">Nonaktifkan</td>
                                <td class="px-4 py-3">Hanya bila tidak ada versi aturan bulan lalu, bulan berjalan, atau terjadwal yang masih menilainya. Buat dulu versi baru dengan penalti 0 dan tanpa mengurangi rumus dasar untuk komponen itu. Komponen itu tidak langsung bisa dinonaktifkan setelah versi baru disimpan: tunggu sampai bulan sesudah bulan pertama versi baru itu, saat versi lama tidak lagi dipakai bulan lalu maupun bulan berjalan. Lihat kolom <strong>Bisa Dinonaktifkan?</strong> di bawah.</td>
                            </tr>
                            <tr class="bg-white dark:bg-gray-900/30">
                                <td class="px-4 py-3 font-medium">Hapus</td>
                                <td class="px-4 py-3">Hanya komponen yang belum tercantum di versi aturan mana pun (termasuk dengan penalti 0) dan belum punya data kehadiran. Komponen bawaan dan yang sudah dipakai cukup dinonaktifkan.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">Status komponen saat ini:</p>
                <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Komponen</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                                <th class="px-4 py-3 text-left font-semibold text-gray-700 dark:text-gray-200">Bisa Dinonaktifkan?</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($componentStatuses as $status)
                                <tr class="bg-white dark:bg-gray-900/30">
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $status['name'] }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">Kode {{ $status['code'] }} · per {{ $status['unit'] }}{{ $status['isBuiltIn'] ? ' · bawaan' : '' }}</span>
                                    </td>
                                    <td class="px-4 py-3 font-semibold {{ $status['isActive'] ? 'text-success-600 dark:text-success-400' : 'text-gray-500 dark:text-gray-400' }}">
                                        {{ $status['isActive'] ? 'Aktif' : 'Nonaktif' }}
                                    </td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                        @if (! $status['isActive'])
                                            Sudah nonaktif.
                                        @elseif ($status['scoredBy'] === [])
                                            Ya.
                                        @else
                                            Belum, masih dinilai {{ implode(', ', $status['scoredBy']) }}.
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        {{-- FAQ --}}
        <x-filament::section>
            <x-slot name="heading">Pertanyaan yang Sering Muncul</x-slot>

            <div class="space-y-4 text-sm text-gray-700 dark:text-gray-300">
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa nilai bulan lalu tidak berubah setelah aturan diubah?</h3>
                    <p class="mt-2">Setiap bulan dihitung dengan versi aturan yang berlaku untuk bulan itu. Versi baru hanya berlaku mulai bulannya, jadi bulan-bulan sebelumnya tetap memakai versi lamanya dan nilainya tidak berubah. Untuk mengoreksi nilai bulan lalu, perbaiki datanya (Kehadiran, Penilaian, atau KPI), bukan aturannya.</p>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                    <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa Skor Kehadiran saya 0?</h3>
                    <p class="mt-2">Data Kehadiran bulan itu belum diinput, atau Hari Kerja-nya 0. Skor juga bisa 0 bila penalti lebih besar dari rumus dasar. Hubungi pengisi Kehadiran di unit Anda untuk memeriksa datanya.</p>
                </div>

                @if ($izinFromLabel)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa sakit dan izin dihitung berbeda antar bulan?</h3>
                        <p class="mt-2">Sebelum {{ $izinFromLabel }}, sakit dan izin dicatat bersama di {{ $sickIzinLabel }} (di tabel Kehadiran: kolom {{ $sickName }}) dan dinilai dengan versi aturan sebelumnya. Mulai {{ $izinFromLabel }}, izin dicatat sendiri di {{ $izinName }} dan masing-masing dinilai sesuai versi yang berlaku (lihat tabel aturan di atas).</p>
                    </div>
                @endif

                @if ($canInputAttendance)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa isian komponen di form Kehadiran abu-abu dan tidak bisa diisi?</h3>
                        <p class="mt-2">
                            Versi aturan untuk Periode yang dipilih tidak memakai komponen itu (tulisan "{{ $unusedHint }}"). Periksa dulu Periode-nya: form terisi bulan lalu ({{ $previousPeriodeLabel }}).
                            @if ($izinFromLabel)
                                Untuk periode sebelum {{ $izinFromLabel }}, izin diisi di {{ $sickIzinLabel }}.
                            @endif
                            Komponen yang belum dicantumkan di versi aturan mana pun selalu abu-abu sampai super admin mencantumkannya.
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa baris import dilewati karena aturan penilaian belum memakai suatu komponen?</h3>
                        <p class="mt-2">
                            Kolom komponen itu berisi angka, padahal versi aturan untuk periode barisnya tidak memakai komponen tersebut, sehingga barisnya tidak disimpan. Download template periode itu agar kolomnya sesuai.
                            @if ($izinFromLabel)
                                Contoh paling sering: izin untuk periode sebelum {{ $izinFromLabel }} harus dijumlahkan ke kolom {{ $sickIzinLabel }}, lalu kolom {{ $izinHeading ?? $izinName }} dikosongkan.
                            @endif
                        </p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa import meminta centang konfirmasi?</h3>
                        <p class="mt-2">Periode yang diimport adalah bulan berjalan ({{ $currentPeriodeLabel }}) atau bulan sesudahnya, yang belum selesai. Kehadiran biasanya direkap untuk bulan yang sudah selesai ({{ $previousPeriodeLabel }}). Bila bulannya keliru, ganti <strong>Periode yang diimport</strong>; bila memang benar, centang konfirmasinya.</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa ada baris yang dilewati saat import?</h3>
                        <p class="mt-2">Setiap baris yang dilewati punya alasan di notifikasi import. Yang paling sering: data karyawan + periode itu sudah ada, Hari Kerja kosong, karyawan tidak ditemukan, format Periode salah, periode baris berbeda dengan Periode yang diimport, atau kolom komponen yang tidak dipakai aturan periodenya terisi. Lihat tabel <strong>Baris Dilewati &amp; Peringatan Import</strong> di atas, perbaiki barisnya, lalu import ulang file yang hanya berisi baris yang diperbaiki (lihat SOP 2 langkah terakhir).</p>
                    </div>
                @endif

                @if ($canManageRules)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa komponen tidak bisa dinonaktifkan atau dihapus?</h3>
                        <p class="mt-2">Komponen tidak bisa dinonaktifkan selama masih dinilai versi aturan bulan lalu, bulan berjalan, atau terjadwal, karena data kehadirannya masih dibutuhkan untuk menghitung nilai. Komponen bawaan, komponen yang sudah tercantum di versi aturan mana pun (termasuk dengan penalti 0), dan komponen yang sudah punya data kehadiran tidak bisa dihapus, cukup dinonaktifkan. Arahkan kursor ke tombol yang nonaktif di menu Komponen Kehadiran untuk melihat alasannya.</p>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900/30">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Kenapa versi aturan tidak bisa diubah?</h3>
                        <p class="mt-2">Versi itu sudah terkunci karena bulan pertamanya sudah berakhir. Hanya nama dan catatannya yang bisa diubah. Buat versi baru dengan <strong>Duplikat jadi versi baru</strong> mulai bulan berjalan atau sesudahnya (bila bulan itu belum punya versi). Bila bulan itu sudah punya versi Terjadwal, ubah saja versi tersebut lewat <strong>Ubah</strong>.</p>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Aksi Cepat --}}
        <x-filament::section>
            <x-slot name="heading">Aksi Cepat</x-slot>

            <div class="flex flex-wrap gap-3">
                @if ($attendanceUrl)
                    <x-filament::button tag="a" :href="$attendanceUrl" icon="heroicon-m-calendar-date-range">
                        Buka Menu Kehadiran
                    </x-filament::button>
                @endif
                @if ($scoringRulesUrl)
                    <x-filament::button tag="a" :href="$scoringRulesUrl" color="gray" icon="heroicon-m-scale">
                        Buka Aturan Penilaian
                    </x-filament::button>
                @endif
                @if ($componentsUrl)
                    <x-filament::button tag="a" :href="$componentsUrl" color="gray" icon="heroicon-m-clipboard-document-list">
                        Buka Komponen Kehadiran
                    </x-filament::button>
                @endif
                <x-filament::button tag="a" :href="$leaderboardUrl" color="gray" icon="heroicon-m-trophy">
                    Lihat Leaderboard
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
