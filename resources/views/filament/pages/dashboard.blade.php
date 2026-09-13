<!-- filepath: /Users/apriansyahrs/Documents/Code/complete_selular/web-dnd/resources/views/filament/pages/dashboard.blade.php -->
<x-filament-panels::page>
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$activeTab === 'checklist'"
            icon="heroicon-o-clipboard-document-check"
            wire:click="setActiveTab('checklist')"
        >
            Checklist KPI
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'leaderboard'"
            icon="heroicon-o-trophy"
            wire:click="setActiveTab('leaderboard')"
        >
            Leaderboard KPI
        </x-filament::tabs.item>
    </x-filament::tabs>

    <div class="mt-4">
        @if($activeTab === 'checklist')
            @livewire(\App\Filament\Widgets\ChecklistKPI::class)
        @elseif($activeTab === 'leaderboard')
            @livewire(\App\Filament\Widgets\LeaderboardKPI::class)
        @endif
    </div>
</x-filament-panels::page>