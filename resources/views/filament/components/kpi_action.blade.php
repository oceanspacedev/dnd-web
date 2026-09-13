@php
    $canEditKpiDetail = $canEditKpiDetail ?? true;
@endphp

@if ($kpiDetail->count_type === 'NON')
    @if ($canEditKpiDetail)
        <form wire:submit.prevent="changeKpiStatus('{{ $kpiDetail->id }}', 'monthly')">
            <x-filament::icon-button
                type="submit"
                icon="heroicon-o-check-circle"
                color="{{ $kpiDetail->value_result != null ? 'success' : 'danger' }}"
                label="Toggle KPI Status"
            />
        </form>
    @else
        <x-filament::icon-button
            icon="heroicon-o-lock-closed"
            color="gray"
            label="Periode checklist sudah ditutup"
            disabled
        />
    @endif
@elseif ($kpiDetail->count_type === 'RESULT')
    @if ($canEditKpiDetail)
        <x-filament::icon-button
            icon="heroicon-o-check-circle"
            color="{{ $kpiDetail->value_result != null ? 'success' : 'danger' }}"
            wire:click="openUpdateModal({{ $kpiDetail->id }})"
            label="Update KPI Result"
        />
    @else
        <x-filament::icon-button
            icon="heroicon-o-lock-closed"
            color="gray"
            label="Periode checklist sudah ditutup"
            disabled
        />
    @endif
@endif
