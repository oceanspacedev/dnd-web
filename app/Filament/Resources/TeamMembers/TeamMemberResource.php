<?php

namespace App\Filament\Resources\TeamMembers;

use App\Filament\Resources\TeamMembers\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMembers\Pages\EditTeamMember;
use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menu Tim Saya: anggota tim berdasarkan approval line (bawahan langsung + satu level di bawahnya).
 * Memakai form dan tabel UserResource; cakupan badan usaha admin tidak berlaku di sini,
 * kecuali saat anggota tim dipindahkan keluar dari tim (lihat ensureAreaInScope).
 */
class TeamMemberResource extends UserResource
{
    protected static ?string $slug = 'tim-saya';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Tim Saya';

    protected static ?string $modelLabel = 'Anggota Tim';

    protected static ?string $pluralModelLabel = 'Tim Saya';

    public static function canViewAny(): bool
    {
        $actor = static::actor();

        return $actor instanceof User && (new UserPolicy)->canManageTeam($actor);
    }

    public static function canImportUsers(): bool
    {
        return false;
    }

    public static function canViewArchived(): bool
    {
        return false;
    }

    /**
     * Seperti atasan lain, anggota tim boleh ditempatkan di badan usaha mana pun.
     * Batasannya ada di ensureAreaInScope (sisi server).
     */
    protected static function scopeAreaOptions(Builder $query, ?User $record): Builder
    {
        return $query;
    }

    /**
     * Admin badan usaha tertentu boleh menempatkan anggota tim di luar badan usaha yang diizinkan selama karyawan itu
     * tetap anggota timnya (atasannya dirinya sendiri atau bawahan langsungnya), jadi cakupannya tidak bertambah.
     * Selain itu berlaku aturan menu Karyawan: badan usaha yang diizinkan atau badan usaha lama.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function ensureAreaInScope(?User $actor, array $data, ?User $record): void
    {
        if (DataAccessService::isAreaScopedAdmin($actor) && static::staysInActorTeam($actor, $data, $record)) {
            return;
        }

        parent::ensureAreaInScope($actor, $data, $record);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function staysInActorTeam(User $actor, array $data, ?User $record): bool
    {
        $approvalId = array_key_exists('approval_id', $data)
            ? static::nullableUserId($data['approval_id'])
            : static::nullableUserId($record?->getOriginal('approval_id'));

        if ($approvalId === null || ($record?->exists && $approvalId === (int) $record->getKey())) {
            return false;
        }

        $actorId = (int) $actor->getKey();

        return $approvalId === $actorId
            || User::query()->whereKey($approvalId)->where('approval_id', $actorId)->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
            'create' => CreateTeamMember::route('/create'),
            'edit' => EditTeamMember::route('/{record}/edit'),
        ];
    }

    /**
     * Anggota tim yang masih aktif (karyawan terhapus tidak termasuk approval line).
     * Akun ADMIN hanya dikelola super admin, jadi tidak ditampilkan bagi yang lain (sama seperti API).
     */
    public static function getEloquentQuery(): Builder
    {
        $query = static::baseEloquentQuery();
        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
        $query->whereIn($query->qualifyColumn('id'), $managedUserIds);

        if (DataAccessService::hasGlobalAccess(static::actor())) {
            return $query;
        }

        return $query->whereDoesntHave('role', fn (Builder $roleQuery): Builder => $roleQuery->where('name', User::ROLE_ADMIN));
    }
}
