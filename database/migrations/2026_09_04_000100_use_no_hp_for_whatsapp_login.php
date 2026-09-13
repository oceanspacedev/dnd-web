<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $updates = [];
        $owners = [];
        $invalidUserIds = [];

        foreach (DB::table('users')->orderBy('id')->get(['id', 'no_hp']) as $user) {
            $rawNumber = trim((string) ($user->no_hp ?? ''));

            if ($rawNumber === '') {
                $updates[(int) $user->id] = null;

                continue;
            }

            $number = $this->toLocal($rawNumber);

            if (! $number) {
                $invalidUserIds[] = (int) $user->id;

                continue;
            }

            $updates[(int) $user->id] = $number;
            $owners[$number][] = (int) $user->id;
        }

        if ($invalidUserIds !== []) {
            throw new RuntimeException(
                'Normalisasi No. HP dibatalkan. Format nomor tidak valid pada user ID: '
                .implode(', ', $invalidUserIds).'.',
            );
        }

        $duplicates = array_filter(
            $owners,
            fn (array $userIds): bool => count($userIds) > 1,
        );

        if ($duplicates !== []) {
            $details = collect($duplicates)
                ->map(fn (array $userIds, string $number): string => $number.' (user ID '.implode(', ', $userIds).')')
                ->implode('; ');

            throw new RuntimeException(
                'Normalisasi No. HP dibatalkan karena ada nomor duplikat: '.$details.'.',
            );
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $userId => $number) {
                DB::table('users')
                    ->where('id', $userId)
                    ->update(['no_hp' => $number]);
            }
        });

        if (! Schema::hasIndex('users', 'users_no_hp_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('no_hp');
            });
        }

        $hasWhatsappNumber = Schema::hasColumn('users', 'whatsapp_number');
        $hasWhatsappVerifiedAt = Schema::hasColumn('users', 'whatsapp_verified_at');

        if ($hasWhatsappNumber || $hasWhatsappVerifiedAt) {
            Schema::table('users', function (Blueprint $table) use ($hasWhatsappNumber, $hasWhatsappVerifiedAt): void {
                if ($hasWhatsappNumber && Schema::hasIndex('users', 'users_whatsapp_number_unique')) {
                    $table->dropUnique('users_whatsapp_number_unique');
                }

                $columns = array_filter([
                    $hasWhatsappNumber ? 'whatsapp_number' : null,
                    $hasWhatsappVerifiedAt ? 'whatsapp_verified_at' : null,
                ]);

                $table->dropColumn(array_values($columns));
            });
        }
    }

    public function down(): void
    {
        // Keep normalized contact data and never recreate the removed interim
        // WhatsApp columns; only the uniqueness constraint is reversible.
        if (Schema::hasIndex('users', 'users_no_hp_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_no_hp_unique');
            });
        }
    }

    private function toLocal(string $value): ?string
    {
        if (preg_match('/^\+?[0-9\s().-]+$/', $value) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($digits, '0062')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $canonical = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '620')) {
            $canonical = '62'.substr($digits, 3);
        } elseif (str_starts_with($digits, '8')) {
            $canonical = '62'.$digits;
        } else {
            $canonical = $digits;
        }

        if (preg_match('/^628\d{8,12}$/', $canonical) !== 1) {
            return null;
        }

        return '0'.substr($canonical, 2);
    }
};
