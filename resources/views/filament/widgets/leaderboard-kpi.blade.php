<x-filament-widgets::widget>
    <x-filament::section>
        <div class="space-y-6">
            @php
                $defaultMonth = $this->month ?? now()->format('Y-m');
                $defaultYear = $this->month ? substr($this->month, 0, 4) : now()->format('Y');
                $areas = $this->getAreas();
                $divisions = $this->getDivisions();
                $leaderboardData = $this->getLeaderboardData();
            @endphp

            <!-- Header and Filters Section -->
            <div class="flex flex-col space-y-4 sm:space-y-0 sm:flex-row sm:justify-between sm:items-start">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100 sm:text-2xl">Leaderboard KPI</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Bandingkan metrik kinerja antar karyawan</p>
                </div>

                <!-- Responsive Filters -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 w-full sm:w-auto">
                    <div class="w-full sm:w-40 md:w-48">
                        <label class="sr-only" for="leaderboard-month">Bulan</label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                            id="leaderboard-month"
                            type="month"
                            wire:model.live="month"
                            placeholder="Choose Month"
                            class="w-full"
                            max="{{ now()->format('Y-m') }}"
                        />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="w-full sm:w-40 md:w-48">
                        <label class="sr-only" for="leaderboard-area">Area</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="leaderboard-area" wire:model.live="area" class="w-full">
                                <option value="">All Areas</option>
                                @foreach($areas as $area)
                                    <option value="{{ $area->id }}">{{ $area->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    <div class="w-full sm:w-40 md:w-48">
                        <label class="sr-only" for="leaderboard-division">Divisi</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="leaderboard-division" wire:model.live="division" class="w-full" searchable>
                                <option value="">All Divisions</option>
                                @foreach($divisions as $division)
                                    <option value="{{ $division->id }}">{{ $division->name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="w-full sm:w-40 md:w-48 flex items-end">
                        <x-filament::button
                            icon="heroicon-m-arrow-down-tray"
                            color="gray"
                            class="w-full"
                            x-on:click="$dispatch('open-modal', { id: 'leaderboard-export-modal' })"
                        >
                            Export KPI
                        </x-filament::button>
                    </div>

                </div>
            </div>

            <x-filament::modal id="leaderboard-export-modal" width="md">
                <x-slot name="heading">Export KPI</x-slot>
                <x-slot name="description">Unduh data KPI leaderboard ke file Excel.</x-slot>

                <div x-data="{
                    periodType: 'month',
                    month: @js($defaultMonth),
                    year: @js($defaultYear),
                    area: @js($this->area ?? ''),
                    division: @js($this->division ?? '')
                }">
                    <form
                        method="GET"
                        target="_blank"
                        action="{{ route('leaderboard.export') }}"
                        class="space-y-4"
                    >
                        <input type="hidden" name="period_type" x-model="periodType" />

                        <div class="space-y-1.5">
                            <label for="export_period_type" class="text-sm font-medium text-gray-700 dark:text-gray-200">Periode</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="export_period_type" x-model="periodType">
                                    <option value="month">Per Bulan</option>
                                    <option value="year">Per Tahun</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div x-show="periodType === 'month'" x-cloak class="space-y-1.5">
                            <label for="export_month" class="text-sm font-medium text-gray-700 dark:text-gray-200">Bulan</label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    id="export_month"
                                    type="month"
                                    name="month"
                                    x-model="month"
                                    x-bind:disabled="periodType !== 'month'"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <div x-show="periodType === 'year'" x-cloak class="space-y-1.5">
                            <label for="export_year" class="text-sm font-medium text-gray-700 dark:text-gray-200">Tahun</label>
                            <x-filament::input.wrapper>
                                <x-filament::input
                                    id="export_year"
                                    type="text"
                                    name="year"
                                    x-model="year"
                                    x-bind:disabled="periodType !== 'year'"
                                    maxlength="4"
                                    placeholder="YYYY"
                                />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="space-y-1.5">
                            <label for="export_area" class="text-sm font-medium text-gray-700 dark:text-gray-200">Area</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="export_area" name="area" x-model="area">
                                    <option value="">Semua Area</option>
                                    @foreach($areas as $area)
                                        <option value="{{ $area->id }}">{{ $area->name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="space-y-1.5">
                            <label for="export_division" class="text-sm font-medium text-gray-700 dark:text-gray-200">Divisi</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="export_division" name="division" x-model="division">
                                    <option value="">Semua Divisi</option>
                                    @foreach($divisions as $division)
                                        <option value="{{ $division->id }}">{{ $division->name }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <x-filament::button color="gray" x-on:click="close">
                                Batal
                            </x-filament::button>
                            <x-filament::button type="submit" icon="heroicon-m-arrow-down-tray" color="primary">
                                Export
                            </x-filament::button>
                        </div>
                    </form>
                </div>
            </x-filament::modal>

            <!-- Mobile Card Layout (visible only on small screens) -->
            <div class="block sm:hidden space-y-4">
                @foreach($leaderboardData as $index => $data)
                    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm dark:shadow-gray-900/20 overflow-hidden hover:bg-primary-50 dark:hover:bg-primary-900/30 transition-colors">
                        <div class="flex items-center p-4 bg-gray-50 dark:bg-gray-800/80 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300 flex items-center justify-center text-lg font-bold">
                                {{ $index + 1 }}
                            </div>
                            <div class="ml-4">
                                <h3 class="font-medium text-gray-900 dark:text-gray-200">{{ $data['user']->nama_lengkap }}</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $data['user']->divisi->name ?? 'N/A' }} · {{ $data['user']->area->name ?? 'N/A' }}</p>
                            </div>
                        </div>
                        <div class="p-4 space-y-3">
                            <div class="grid grid-cols-2 gap-3 text-sm">
                                <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">KPI Score (70%)</p>
                                    <p class="font-medium text-gray-900 dark:text-gray-200">{{ number_format($data['kpiScore'], 2) }}</p>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Attendance (15%)</p>
                                    <p class="font-medium text-gray-900 dark:text-gray-200">{{ number_format($data['attendanceScore'], 2) }}</p>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Activity (15%)</p>
                                    <p class="font-medium text-gray-900 dark:text-gray-200">{{ number_format($data['activityScore'], 2) }}</p>
                                </div>
                                <div class="bg-primary-50 dark:bg-primary-900/30 p-3 rounded">
                                    <p class="text-xs text-primary-700 dark:text-primary-400">Total Score</p>
                                    <p class="font-bold text-primary-800 dark:text-primary-300">{{ number_format($data['totalScore'], 2) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Desktop Table Layout (hidden on small screens) -->
            <div class="hidden sm:block rounded-lg border border-gray-200 dark:border-gray-700 shadow-sm dark:shadow-gray-900/20">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-white/5">
                                <th class="px-4 py-3 font-medium text-left text-gray-500 dark:text-gray-400">Position</th>
                                <th class="px-4 py-3 font-medium text-left text-gray-500 dark:text-gray-400">Name</th>
                                <th class="px-4 py-3 font-medium text-left text-gray-500 dark:text-gray-400">Division</th>
                                <th class="px-4 py-3 font-medium text-left text-gray-500 dark:text-gray-400">Area</th>
                                <th class="px-4 py-3 font-medium text-center text-gray-500 dark:text-gray-400">KPI Score (70%)</th>
                                <th class="px-4 py-3 font-medium text-center text-gray-500 dark:text-gray-400">Attendance (15%)</th>
                                <th class="px-4 py-3 font-medium text-center text-gray-500 dark:text-gray-400">Activity (15%)</th>
                                <th class="px-4 py-3 font-medium text-center text-gray-500 dark:text-gray-400">Cutpoint</th>
                                <th class="px-4 py-3 font-medium text-center text-gray-500 dark:text-gray-400">Total Score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($leaderboardData as $index => $data)
                                <tr class="{{ $index % 2 ? 'bg-gray-50 dark:bg-white/5' : 'bg-white dark:bg-gray-800' }} hover:bg-primary-50 dark:hover:bg-primary-900/30 transition-colors">
                                    <td class="px-4 py-3">
                                        <div class="w-8 h-8 rounded-full bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300 flex items-center justify-center font-medium">
                                            {{ $index + 1 }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-900 dark:text-gray-200">{{ $data['user']->nama_lengkap }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $data['user']->divisi->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-400">{{ $data['user']->area->name ?? 'N/A' }}</td>
                                    <td class="px-4 py-3 text-center text-gray-800 dark:text-gray-300">{{ number_format($data['kpiScore'], 2) }}</td>
                                    <td class="px-4 py-3 text-center text-gray-800 dark:text-gray-300">{{ number_format($data['attendanceScore'], 2) }}</td>
                                    <td class="px-4 py-3 text-center text-gray-800 dark:text-gray-300">{{ number_format($data['activityScore'], 2) }}</td>
                                    <td class="px-4 py-3 text-center text-red-600 dark:text-red-400 font-semibold">
                                        {{ $data['cutpoint'] > 0 ? number_format($data['cutpoint'], 2) : '-' }}
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="font-medium px-2 py-1 bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300 rounded">
                                            {{ number_format($data['totalScore'], 2) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
