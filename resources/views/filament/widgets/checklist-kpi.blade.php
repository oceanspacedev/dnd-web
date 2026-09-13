<x-filament-widgets::widget>
    <x-filament::section>
        @php
            $kpisData = $this->getKpis();
        @endphp
        <div class="mb-6 sm:mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-gray-900 dark:text-gray-100 sm:text-2xl">Checklist
                        KPI</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Lacak indikator kinerja dan status
                        penyelesaian Anda</p>
                </div>

                <!-- Filter Controls -->
                <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                    <div class="flex flex-col sm:flex-row gap-3 w-full">
                        @if (auth()->user()->role_id != 2)
                            <label class="sr-only" for="checklist-user">User</label>
                            <x-filament::input.wrapper class="w-full sm:w-52">
                                <x-filament::input.select id="checklist-user" wire:model.live="user_id" class="w-full">
                                    <option value="">--Choose User--</option>
                                    @foreach ($kpisData['users'] as $user)
                                        <option value="{{ $user->id }}">{{ $user->nama_lengkap }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        @endif

                        <label class="sr-only" for="checklist-month">Bulan</label>
                        <x-filament::input.wrapper class="w-full sm:w-40">
                            <x-filament::input id="checklist-month" type="month" wire:model.live="month"
                                placeholder="Pilih Bulan" class="w-full" max="{{ now()->format('Y-m') }}" />
                        </x-filament::input.wrapper>
                    </div>
                </div>
            </div>
        </div>

        @foreach ($kpisData['groupedKpis'] as $yearMonth => $groupedKpisByCategory)
            @php
                $yearMonthText = Carbon\Carbon::parse($yearMonth)->format('F Y');
                $userKpi = $groupedKpisByCategory->first()->first();
            @endphp

            @php
                $deadlineInfo = $this->getDeadlineInfo($yearMonth);
            @endphp

            <div class="mb-6 sm:mb-10">
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 mb-3 sm:mb-4">
                    <h3
                        class="text-base sm:text-lg font-semibold text-primary-700 dark:text-primary-400 bg-primary-50 dark:bg-primary-900/30 px-3 py-1.5 sm:px-4 sm:py-2 rounded-md inline-block">
                        {{ $yearMonthText }}
                    </h3>

                    @if ($deadlineInfo['isClosed'])
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-danger-50 dark:bg-danger-500/10 text-danger-700 dark:text-danger-400 ring-1 ring-inset ring-danger-600/10 dark:ring-danger-500/20">
                            <x-heroicon-m-lock-closed class="w-3.5 h-3.5" />
                            Ditutup sejak {{ $deadlineInfo['deadline']->format('d M Y') }}
                        </span>
                    @elseif (! $deadlineInfo['isAdmin'] && $deadlineInfo['daysLeft'] <= 5)
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-warning-50 dark:bg-warning-500/10 text-warning-700 dark:text-warning-400 ring-1 ring-inset ring-warning-600/10 dark:ring-warning-500/20">
                            <x-heroicon-m-clock class="w-3.5 h-3.5" />
                            Sisa {{ $deadlineInfo['daysLeft'] }} hari — batas {{ $deadlineInfo['deadline']->format('d M Y') }}
                        </span>
                    @elseif (! $deadlineInfo['isAdmin'])
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 ring-1 ring-inset ring-gray-500/10 dark:ring-white/10">
                            <x-heroicon-m-calendar class="w-3.5 h-3.5" />
                            Batas pengisian {{ $deadlineInfo['deadline']->format('d M Y') }}
                        </span>
                    @endif
                </div>

                @foreach ($groupedKpisByCategory as $categoryName => $kpis)
                    <div
                        class="mb-6 sm:mb-8 bg-white dark:bg-gray-800 rounded-xl shadow-sm dark:shadow-gray-900/20 border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div
                            class="bg-gray-50 dark:bg-gray-800/80 p-3 sm:p-5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 sm:gap-3 border-b border-gray-200 dark:border-gray-700">
                            <h4 class="font-semibold text-gray-800 dark:text-gray-200 text-sm sm:text-base">
                                {{ $categoryName }}</h4>
                            <span
                                class="text-xs sm:text-sm font-medium px-2 py-1 sm:px-3 sm:py-1.5 bg-primary-100 dark:bg-primary-900/50 text-primary-700 dark:text-primary-300 rounded-full">
                                Category Percentage: {{ $kpis->first()->percentage }}%
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-fixed">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-800/80">
                                        <th scope="col"
                                            class="w-[5%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Status</th>
                                        <th scope="col"
                                            class="px-3 sm:px-6 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-left">
                                            Description</th>
                                        <th scope="col"
                                            class="w-[10%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Start</th>
                                        <th scope="col"
                                            class="w-[10%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            End</th>
                                        <th scope="col"
                                            class="w-[5%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Type</th>
                                        <th scope="col"
                                            class="w-[5%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Plan</th>
                                        <th scope="col"
                                            class="w-[5%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Actual</th>
                                        <th scope="col"
                                            class="w-[5%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Result</th>
                                        <th scope="col"
                                            class="w-[5%] px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider text-center">
                                            Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach ($kpis as $kpi)
                                        @foreach ($kpi->kpi_detail->where('is_extra_task', 0) as $kpiDetail)
                                            @php
                                                $canEditKpiDetail = $this->canEditKpiDate($kpi->date);
                                            @endphp
                                            <tr class="hover:bg-gray-50 dark:hover:bg-white/5 transition-colors" wire:key="kpi-detail-{{ $kpiDetail->id }}">
                                                <td class="px-2 sm:px-4 py-2 sm:py-4 flex items-center justify-center">
                                                    @include('filament.components.kpi_action', [
                                                        'kpiDetail' => $kpiDetail,
                                                        'canEditKpiDetail' => $canEditKpiDetail,
                                                    ])
                                                </td>
                                                <td
                                                    class="px-3 sm:px-6 py-2 sm:py-4 text-xs sm:text-sm text-gray-900 dark:text-gray-200">
                                                    <p class="font-medium">
                                                        {{ $kpiDetail->kpi_description->description }}
                                                    </p>
                                                    <p class="mt-1 text-2xs text-gray-500 dark:text-gray-400">
                                                        @if ($kpiDetail->count_type === 'NON')
                                                            Skema: NON — selesai/tidak (0/1).
                                                        @elseif ($kpiDetail->kpi_description?->is_negative)
                                                            Skema: Negatif — semakin kecil semakin baik.
                                                        @else
                                                            Skema: Positif — semakin besar semakin baik.
                                                        @endif
                                                    </p>

                                                    {{-- Display subtasks if they exist (handle both array and JSON string) --}}
                                                    @php
                                                        $subtasksData = null;
                                                        if (!empty($kpiDetail->subtasks)) {
                                                            if (is_string($kpiDetail->subtasks)) {
                                                                $subtasksData = json_decode($kpiDetail->subtasks, true);
                                                            } elseif (is_array($kpiDetail->subtasks)) {
                                                                $subtasksData = $kpiDetail->subtasks;
                                                            }
                                                        }
                                                    @endphp

                                                    @if(!empty($subtasksData) && is_array($subtasksData))
                                                        <div class="mt-2 ml-3 space-y-1">
                                                            @foreach($subtasksData as $subtask)
                                                                <div class="text-2xs text-gray-600 dark:text-gray-400">
                                                                    - {{ $subtask['description'] ?? '' }}
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </td>

                                                {{-- Rest of the columns remain unchanged --}}
                                                <td
                                                    class="px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs text-gray-600 dark:text-gray-400 text-center">
                                                    {{ $kpiDetail->start ? Carbon\Carbon::parse($kpiDetail->start)->format('d M Y') : '-' }}
                                                </td>
                                                <td
                                                    class="px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs text-gray-600 dark:text-gray-400 text-center">
                                                    {{ $kpiDetail->end ? Carbon\Carbon::parse($kpiDetail->end)->format('d M Y') : '-' }}
                                                </td>
                                                <td
                                                    class="px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs text-gray-700 dark:text-gray-300 text-center font-medium">
                                                    {{ $kpiDetail->count_type }}</td>
                                                <td
                                                    class="px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs text-gray-600 dark:text-gray-400 text-center">
                                                    {{ $kpiDetail->value_plan }}</td>
                                                <td
                                                    class="px-2 sm:px-4 py-2 sm:py-4 text-2xs sm:text-xs text-gray-700 dark:text-gray-300 text-center font-medium">
                                                    {{ $kpiDetail->value_actual }}</td>
                                                <td class="px-2 sm:px-4 py-2 sm:py-4 text-center">
                                                    @if ($kpiDetail->count_type === 'NON')
                                                        <span
                                                            class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-full text-2xs sm:text-xs {{ $kpiDetail->value_result == 1 ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' }}">
                                                            {{ $kpiDetail->value_result == 1 ? '100%' : number_format($kpiDetail->value_result * 100, 2) . '%' }}
                                                        </span>
                                                    @elseif($kpiDetail->count_type === 'RESULT')
                                                        <span
                                                            class="px-1.5 py-0.5 sm:px-2.5 sm:py-1 rounded-full text-2xs sm:text-xs {{ $kpiDetail->value_result >= 1 ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400' }}">
                                                            {{ number_format($kpiDetail->value_result * 100, 2) }}%
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="px-2 sm:px-4 py-2 sm:py-4 text-center">
                                                    @if ($canEditKpiDetail && $kpiDetail->count_type === 'RESULT' && $kpiDetail->value_result > 0 && $kpiDetail->value_result < 1)
                                                        <x-filament::icon-button
                                                            icon="heroicon-o-plus-circle"
                                                            color="warning"
                                                            size="sm"
                                                            label="Extra Task"
                                                            wire:click="openExtraTaskModal('{{ $kpiDetail->id }}')"
                                                        />
                                                    @endif
                                                </td>
                                            </tr>

                                            {{-- Extra Task children rows - rendered as sub-rows in the same table --}}
                                            @foreach ($kpiDetail->children as $extraTask)
                                                <tr class="bg-warning-50/40 dark:bg-warning-500/5 border-l-2 border-l-warning-400 dark:border-l-warning-600" wire:key="kpi-extra-{{ $extraTask->id }}">
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-center">
                                                        <span class="text-warning-400 dark:text-warning-500 text-xs">↳</span>
                                                    </td>
                                                    <td class="px-3 sm:px-6 py-2 sm:py-3 text-xs sm:text-sm text-gray-700 dark:text-gray-300">
                                                        <div class="flex items-center gap-2">
                                                            <span class="inline-flex items-center rounded-full bg-warning-100 dark:bg-warning-900/40 px-1.5 py-0.5 text-2xs font-medium text-warning-700 dark:text-warning-400">
                                                                Extra
                                                            </span>
                                                            <span class="font-medium">{{ $extraTask->kpi_description->description ?? '-' }}</span>
                                                        </div>
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-2xs sm:text-xs text-gray-500 dark:text-gray-400 text-center">-</td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-2xs sm:text-xs text-gray-500 dark:text-gray-400 text-center">-</td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-2xs sm:text-xs text-gray-600 dark:text-gray-400 text-center font-medium">
                                                        {{ $extraTask->count_type }}
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-2xs sm:text-xs text-gray-500 dark:text-gray-400 text-center">-</td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-2xs sm:text-xs text-gray-600 dark:text-gray-400 text-center font-medium">
                                                        {{ $extraTask->value_actual ?? '-' }}
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-center">-</td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 text-center">
                                                        @if ($canEditKpiDetail)
                                                            <x-filament::icon-button
                                                                icon="heroicon-m-trash"
                                                                color="danger"
                                                                size="xs"
                                                                label="Hapus Extra Task"
                                                                wire:click="deleteExtraTask('{{ $extraTask->id }}')"
                                                            />
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach

                <div
                    class="mt-6 sm:mt-8 p-4 sm:p-6 bg-gradient-to-r from-primary-50 to-primary-100 dark:from-primary-900/20 dark:to-primary-800/20 rounded-xl shadow-inner dark:shadow-inner-gray-900/10">
                    <div class="flex flex-col sm:flex-row justify-between sm:items-center">
                        <div class="mb-3 sm:mb-0">
                            <span class="text-sm sm:text-base font-medium text-gray-600 dark:text-gray-300">Total
                                Score</span>
                            <p class="text-xs sm:text-sm text-gray-500 dark:text-gray-400">Kinerja keseluruhan pada
                                bulan {{ $yearMonthText }}</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <span
                                class="text-2xl sm:text-3xl font-bold text-primary-700 dark:text-primary-400">{{ number_format($kpisData['totalScore'] * 100, 2) }}</span>
                            <p class="text-xs sm:text-sm font-medium text-primary-600 dark:text-primary-400">points</p>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </x-filament::section>

    <x-filament::modal id="updateKpiModal" width="md">
        <x-slot name="heading">Update KPI</x-slot>
        <x-slot name="description">Perbarui nilai actual untuk KPI ini</x-slot>

        <form wire:submit.prevent="submitUpdateKpi" class="space-y-4">
            <div wire:key="update-kpi-modal-{{ $updateModalKey }}">
                @if (isset($this->kpiDetail))
                    @php
                        $isNegative = $this->kpiDetail->kpi_description?->is_negative ?? false;
                        $currentResult = $this->kpiDetail->value_result;
                    @endphp

                    <div class="rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 overflow-hidden">
                        <div class="px-4 py-3">
                            <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                {{ $this->kpiDetail->kpi_description->description ?? '' }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                <span class="inline-flex items-center rounded-md bg-primary-50 dark:bg-primary-500/10 px-2 py-0.5 text-xs font-medium text-primary-700 dark:text-primary-400 ring-1 ring-inset ring-primary-600/10 dark:ring-primary-500/20">
                                    {{ $this->kpiDetail->count_type ?? '-' }}
                                </span>
                                @if ($isNegative)
                                    <span class="inline-flex items-center rounded-md bg-warning-50 dark:bg-warning-500/10 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-400 ring-1 ring-inset ring-warning-600/10 dark:ring-warning-500/20">
                                        Negatif
                                    </span>
                                @endif
                                <span class="inline-flex items-center rounded-md bg-gray-50 dark:bg-white/5 px-2 py-0.5 text-xs font-medium text-gray-600 dark:text-gray-400 ring-1 ring-inset ring-gray-500/10 dark:ring-white/10">
                                    {{ isset($this->kpiDetail->kpi) ? Carbon\Carbon::parse($this->kpiDetail->kpi->date)->format('F Y') : '-' }}
                                </span>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 divide-x divide-gray-200 dark:divide-white/10 border-t border-gray-200 dark:border-white/10">
                            <div class="px-4 py-2.5 text-center">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Plan</p>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $this->kpiDetail->value_plan ?? '-' }}</p>
                            </div>
                            <div class="px-4 py-2.5 text-center">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Actual</p>
                                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $this->kpiDetail->value_actual ?? '-' }}</p>
                            </div>
                            <div class="px-4 py-2.5 text-center">
                                <p class="text-xs text-gray-500 dark:text-gray-400">Result</p>
                                <p class="text-sm font-semibold {{ $currentResult >= 1 ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400' }}">
                                    {{ $currentResult !== null ? number_format($currentResult * 100, 2) . '%' : '-' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label for="update_value_actual" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            Value Actual Baru
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="update_value_actual"
                                type="number"
                                wire:model="value_actual"
                                step="0.01"
                                required
                                placeholder="Masukkan nilai actual"
                            />
                        </x-filament::input.wrapper>
                        @error('value_actual')
                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @if ($isNegative)
                                Semakin kecil nilai, semakin baik hasilnya (plan / actual).
                            @else
                                Result dihitung otomatis: actual / plan.
                            @endif
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <x-filament::button color="gray" x-on:click="close">
                    Batal
                </x-filament::button>
                <x-filament::button type="submit" color="primary"
                    wire:loading.attr="disabled" wire:target="submitUpdateKpi">
                    <span wire:loading.remove wire:target="submitUpdateKpi">Simpan</span>
                    <span wire:loading wire:target="submitUpdateKpi" class="inline-flex items-center gap-1.5">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        Menyimpan...
                    </span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::modal>

    <x-filament::modal id="extraTaskModal" width="md">
        <x-slot name="heading">Tambah Extra Task</x-slot>
        <x-slot name="description">Catat pekerjaan pengganti untuk KPI yang belum tercapai.</x-slot>

        <form wire:submit.prevent="submitExtraTask" id="extraTaskForm" class="space-y-4">
            <input type="hidden" wire:model="parent_id">

            <div class="space-y-1.5">
                <label for="description" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    Deskripsi Extra Task
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input wire:model="description" id="description" placeholder="Contoh: Migrasi data legacy ke sistem baru" />
                </x-filament::input.wrapper>
                @error('description')
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-4 {{ $count_type === 'RESULT' ? 'sm:grid-cols-2' : '' }}">
                <div class="space-y-1.5">
                    <label for="count_type" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                        Count Type
                    </label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="count_type" id="count_type">
                            <option value="NON">NON (Selesai / Tidak)</option>
                            <option value="RESULT">RESULT (Input Angka)</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @if ($count_type === 'RESULT')
                    <div class="space-y-1.5">
                        <label for="value_actual" class="text-sm font-medium text-gray-700 dark:text-gray-200">
                            Actual Value
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="number"
                                wire:model="value_actual"
                                id="value_actual"
                                step="0.01"
                                placeholder="0"
                            />
                        </x-filament::input.wrapper>
                        @error('value_actual')
                            <p class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <x-filament::button color="gray" x-on:click="close">
                    Batal
                </x-filament::button>
                <x-filament::button type="submit" color="primary"
                    wire:loading.attr="disabled" wire:target="submitExtraTask">
                    <span wire:loading.remove wire:target="submitExtraTask">Simpan</span>
                    <span wire:loading wire:target="submitExtraTask" class="inline-flex items-center gap-1.5">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        Menyimpan...
                    </span>
                </x-filament::button>
            </div>
        </form>
    </x-filament::modal>

</x-filament-widgets::widget>
