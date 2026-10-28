<?php

namespace App\Filament\Auth\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

trait InteractsWithEmailLogin
{
    protected function normalizeEmail(mixed $value): ?string
    {
        $email = Str::lower(trim(is_scalar($value) ? (string) $value : ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * Kolom users.email tidak unik, jadi email yang dipakai lebih dari satu user tidak bisa dipakai login.
     */
    protected function findEligibleWebUserByEmail(string $email): ?User
    {
        $matches = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();

        return $user && $this->canLoginToPanel($user) ? $user : null;
    }

    protected function canLoginToPanel(User $user): bool
    {
        $panel = Filament::getCurrentPanel() ?? Filament::getPanel('admin');

        return $user->canAccessPanel($panel);
    }

    protected function userMatchesEmail(User $user, string $email): bool
    {
        return filled($user->email)
            && Str::lower(trim((string) $user->email)) === $email;
    }

    protected function unavailableEmailMessage(): string
    {
        return 'Email belum terdaftar untuk login.';
    }
}
