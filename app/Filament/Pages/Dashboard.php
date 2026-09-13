<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    public string $activeTab = 'checklist';

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function mount(): void
    {
        // Set the active tab from query parameter if present
        $tab = request()->query('tab');
        if ($tab && in_array($tab, ['checklist', 'leaderboard'])) {
            $this->activeTab = $tab;
        }
    }

    public function getWidgets(): array
    {
        return [];
    }
}
