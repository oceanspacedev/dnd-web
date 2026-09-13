<?php

namespace App\Filament\Auth\Concerns;

use App\Models\User;
use App\Support\WhatsAppNumber;
use Filament\Facades\Filament;

trait InteractsWithWhatsAppLogin
{
    protected function normalizeWhatsAppNumber(mixed $value): ?string
    {
        $number = WhatsAppNumber::normalize(is_scalar($value) ? (string) $value : null);

        return WhatsAppNumber::isValid($number) ? $number : null;
    }

    protected function findEligibleWebUserByWhatsApp(string $number): ?User
    {
        $localNumber = WhatsAppNumber::toLocal($number);

        if (! $localNumber) {
            return null;
        }

        $matches = User::query()
            ->where('no_hp', $localNumber)
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();
        $panel = Filament::getCurrentPanel() ?? Filament::getPanel('admin');

        if (! $user || ! $user->canAccessPanel($panel)) {
            return null;
        }

        return $user;
    }

    protected function userMatchesWhatsAppNumber(User $user, string $number): bool
    {
        return filled($user->no_hp)
            && WhatsAppNumber::normalize((string) $user->no_hp) === $number;
    }

    protected function unavailableWhatsAppMessage(): string
    {
        return 'No. HP belum terdaftar untuk login WhatsApp.';
    }
}
