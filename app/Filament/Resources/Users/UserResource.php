<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\SendKpiReminderEmailJob;
use App\Jobs\SendKpiReminderWhatsAppJob;
use App\Models\Divisi;
use App\Models\KpiReminderSetting;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\ApprovalResolverService;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use App\Support\WhatsAppNumber;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Menu Karyawan: karyawan di badan usaha (users.area_id) yang menjadi cakupan akses data admin.
 * Menu Tim Saya (TeamMemberResource) memakai form dan tabel yang sama dengan query approval line.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Karyawan';

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Karyawan';

    protected static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return DataAccessService::canAccessEmployees(static::actor());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    /**
     * Role ADMIN dan pengaturan akses data hanya dikelola admin dengan akses seluruh badan usaha.
     */
    public static function canManageDataAccess(?User $actor = null): bool
    {
        $actor ??= static::actor();

        return $actor instanceof User && (new UserPolicy)->manageDataAccess($actor);
    }

    /**
     * Import Excel/JSON mencocokkan karyawan lintas badan usaha, jadi khusus super admin.
     */
    public static function canImportUsers(): bool
    {
        return DataAccessService::hasGlobalAccess(static::actor());
    }

    /**
     * Tab Arsip (karyawan yang dihapus) tersedia di menu Karyawan.
     */
    public static function canViewArchived(): bool
    {
        return true;
    }

    public static function isAdminRoleId(mixed $roleId): bool
    {
        if (blank($roleId) || ! is_scalar($roleId)) {
            return false;
        }

        return Role::query()->whereKey($roleId)->value('name') === User::ROLE_ADMIN;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Pengguna')
                    ->schema([
                        TextInput::make('employee_id')
                            ->label('ID Karyawan')
                            ->maxLength(255),
                        TextInput::make('nama_lengkap')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('no_hp')
                            ->label('No. HP')
                            ->placeholder('Contoh: 081234567890')
                            ->helperText('Dipakai sebagai nomor kontak sekaligus login WhatsApp melalui OTP.')
                            ->tel()
                            ->maxLength(20)
                            // Admin (semua atau badan usaha tertentu) boleh mengubah No. HP; record sudah dibatasi cakupannya.
                            ->disabled(fn (): bool => ! DataAccessService::isAdmin(static::actor()))
                            ->dehydrated(fn (): bool => DataAccessService::isAdmin(static::actor()))
                            ->dehydrateStateUsing(fn ($state): ?string => filled($state)
                                ? (WhatsAppNumber::toLocal((string) $state) ?? (string) $state)
                                : null)
                            ->rule(function (?User $record) {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    if (blank($value)) {
                                        return;
                                    }

                                    $number = WhatsAppNumber::toLocal((string) $value);

                                    if (! $number) {
                                        $fail('Format No. HP WhatsApp Indonesia tidak valid, contoh 081234567890.');

                                        return;
                                    }

                                    $exists = User::withTrashed()
                                        ->where('no_hp', $number)
                                        ->when($record?->id, fn (Builder $query, int $id): Builder => $query->whereKeyNot($id))
                                        ->exists();

                                    if ($exists) {
                                        $fail('No. HP sudah digunakan user lain.');
                                    }
                                };
                            }),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Select::make('area_id')
                            ->preload()
                            ->searchable()
                            ->relationship(
                                'area',
                                'name',
                                modifyQueryUsing: fn (Builder $query, ?User $record): Builder => static::scopeAreaOptions($query, $record),
                            )
                            ->live()
                            ->label('Area')
                            ->required()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('divisi_id', null);
                            }),
                        Select::make('divisi_id')
                            ->preload()
                            ->searchable()
                            ->live()
                            ->options(function (callable $get) {
                                $area_id = $get('area_id');
                                if (! $area_id) {
                                    return [];
                                }

                                return Divisi::where('area_id', $area_id)
                                    ->pluck('name', 'id');
                            })
                            ->label('Divisi')
                            ->required()
                            ->afterStateUpdated(function ($state, callable $get, callable $set, ?User $record) {
                                if (! $state) {
                                    return;
                                }

                                static::suggestApprovalLine(
                                    $get,
                                    $set,
                                    $record,
                                    divisiId: (int) $state,
                                    areaId: $get('area_id') ? (int) $get('area_id') : null,
                                    roleId: $get('role_id') ? (int) $get('role_id') : null,
                                    positionId: $get('position_id') ? (int) $get('position_id') : null,
                                );
                            }),
                        Select::make('role_id')
                            ->preload()
                            ->searchable()
                            ->relationship(
                                'role',
                                'name',
                                // Role ADMIN hanya dapat diberikan admin dengan akses seluruh badan usaha.
                                modifyQueryUsing: fn (Builder $query): Builder => static::canManageDataAccess()
                                    ? $query
                                    : $query->where('name', '!=', User::ROLE_ADMIN),
                            )
                            ->label('Jabatan')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $get, callable $set, ?User $record) {
                                if (! $state) {
                                    return;
                                }

                                static::suggestApprovalLine(
                                    $get,
                                    $set,
                                    $record,
                                    divisiId: $get('divisi_id') ? (int) $get('divisi_id') : null,
                                    areaId: $get('area_id') ? (int) $get('area_id') : null,
                                    roleId: (int) $state,
                                    positionId: $get('position_id') ? (int) $get('position_id') : null,
                                );
                            }),
                        Select::make('position_id')
                            ->preload()
                            ->searchable()
                            ->live()
                            ->relationship('position', 'name')
                            ->label('Posisi')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required(),
                            ])
                            ->afterStateUpdated(function ($state, callable $get, callable $set, ?User $record) {
                                if (! $state) {
                                    return;
                                }

                                static::suggestApprovalLine(
                                    $get,
                                    $set,
                                    $record,
                                    divisiId: $get('divisi_id') ? (int) $get('divisi_id') : null,
                                    areaId: $get('area_id') ? (int) $get('area_id') : null,
                                    roleId: $get('role_id') ? (int) $get('role_id') : null,
                                    positionId: (int) $state,
                                );
                            }),
                        Hidden::make('suggested_approval_id')
                            ->dehydrated(false),
                        Select::make('approval_id')
                            ->preload()
                            ->searchable()
                            ->required(function (callable $get) {
                                $roleId = $get('role_id');
                                if (! $roleId) {
                                    return false;
                                }
                                $role = Role::find($roleId);

                                return (bool) ($role?->requires_approval);
                            })
                            ->options(function (callable $get, ?User $record) {
                                $currentUser = static::actor();
                                if (! $currentUser) {
                                    return [];
                                }

                                if ($record === null && ! DataAccessService::isAdmin($currentUser)) {
                                    $roleLabel = $currentUser->role?->name ? " ({$currentUser->role->name})" : '';

                                    return [(int) $currentUser->id => $currentUser->nama_lengkap.$roleLabel];
                                }

                                $query = User::with(['role', 'divisi'])
                                    ->whereNull('deleted_at')
                                    ->orderBy('nama_lengkap');

                                if ($record) {
                                    $query->where('id', '!=', $record->id);
                                }

                                // Admin badan usaha tertentu: karyawan di cakupannya, tim, dirinya sendiri,
                                // ditambah atasan yang sedang terpasang dan saran matriks approval.
                                $selectableIds = static::selectableApproverIds($currentUser, $record === null);
                                if ($selectableIds !== null) {
                                    $extraIds = array_values(array_filter([
                                        static::nullableUserId($record?->getOriginal('approval_id')),
                                        static::suggestedApproverId([
                                            'divisi_id' => $get('divisi_id'),
                                            'area_id' => $get('area_id'),
                                            'role_id' => $get('role_id'),
                                            'position_id' => $get('position_id'),
                                        ], $record),
                                    ]));

                                    $query->whereIn('id', array_values(array_unique([...$selectableIds, ...$extraIds])));
                                }

                                $allUsers = $query->get();

                                $formatUser = fn (User $u): string => $u->role?->name
                                    ? "{$u->nama_lengkap} ({$u->role->name})"
                                    : $u->nama_lengkap;

                                $selectedDivisiId = $get('divisi_id') ?? $record?->divisi_id;
                                $managementRoles = [User::ROLE_ADMIN, 'BOD', 'CHIEF', 'MANAGER', 'COORDINATOR', 'TEAM LEADER'];

                                if ($selectedDivisiId) {
                                    $sameDivisi = [];
                                    $managementCross = [];
                                    $otherUsers = [];

                                    foreach ($allUsers as $u) {
                                        $label = $formatUser($u);
                                        $isSameDivisi = (int) $u->divisi_id === (int) $selectedDivisiId;
                                        $isManagement = in_array(strtoupper((string) $u->role?->name), $managementRoles, true);

                                        if ($isSameDivisi) {
                                            $sameDivisi[$u->id] = $label;
                                        } elseif ($isManagement) {
                                            $divisiName = $u->divisi?->name ? " - Divisi {$u->divisi->name}" : '';
                                            $managementCross[$u->id] = "{$label}{$divisiName}";
                                        } else {
                                            $divisiName = $u->divisi?->name ? " - Divisi {$u->divisi->name}" : '';
                                            $otherUsers[$u->id] = "{$label}{$divisiName}";
                                        }
                                    }

                                    $groups = [];
                                    if (! empty($sameDivisi)) {
                                        $groups['Satu Divisi'] = $sameDivisi;
                                    }
                                    if (! empty($managementCross)) {
                                        $groups['Atasan Manajemen & Lintas Divisi'] = $managementCross;
                                    }
                                    if (! empty($otherUsers)) {
                                        $groups['Karyawan Lainnya (Lintas Divisi)'] = $otherUsers;
                                    }

                                    return $groups;
                                }

                                $management = [];
                                $others = [];

                                foreach ($allUsers as $u) {
                                    $label = $formatUser($u);
                                    $isManagement = in_array(strtoupper((string) $u->role?->name), $managementRoles, true);

                                    if ($isManagement) {
                                        $management[$u->id] = $label;
                                    } else {
                                        $others[$u->id] = $label;
                                    }
                                }

                                $groups = [];
                                if (! empty($management)) {
                                    $groups['Atasan & Manajemen'] = $management;
                                }
                                if (! empty($others)) {
                                    $groups['Karyawan Lainnya'] = $others;
                                }

                                return $groups;
                            })
                            ->default(fn () => auth()->id())
                            ->disabled(fn (?User $record): bool => $record === null && ! DataAccessService::isAdmin(static::actor()))
                            ->dehydrated()
                            ->label('Approval Line')
                            ->helperText(function (callable $get, ?User $record): string {
                                $divisiId = $get('divisi_id') ? (int) $get('divisi_id') : null;
                                $areaId = $get('area_id') ? (int) $get('area_id') : null;

                                if (! static::suggestionInputsInScope(static::actor(), $divisiId, $areaId, $record)) {
                                    return static::approvalLineDefaultHelperText($record === null);
                                }

                                return static::approvalLineSuggestionText(
                                    $divisiId,
                                    $areaId,
                                    $get('role_id') ? (int) $get('role_id') : null,
                                    $get('position_id') ? (int) $get('position_id') : null,
                                    $record?->id ? (int) $record->id : null,
                                    $record === null,
                                );
                            })
                            ->columnSpan(2),
                    ])
                    ->collapsible()
                    ->columnSpan(2)
                    ->columns(2),

                // Akses data per badan usaha hanya berlaku untuk role ADMIN dan hanya diatur super admin.
                Section::make('Akses Data')
                    ->description('Tentukan badan usaha yang datanya dapat dilihat dan dikelola admin ini.')
                    ->visible(fn (Get $get): bool => static::canManageDataAccess() && static::isAdminRoleId($get('role_id')))
                    ->schema([
                        ToggleButtons::make('data_access')
                            ->label('Cakupan Badan Usaha')
                            ->options([
                                User::DATA_ACCESS_ALL => 'Semua Badan Usaha',
                                User::DATA_ACCESS_SELECTED => 'Badan Usaha Tertentu',
                            ])
                            ->default(User::DATA_ACCESS_ALL)
                            ->inline()
                            ->live()
                            ->required()
                            ->rule(function (?User $record) {
                                return function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    if ($value === User::DATA_ACCESS_SELECTED && static::isActorRecord($record)) {
                                        $fail('Anda tidak dapat membatasi akses data akun Anda sendiri.');
                                    }
                                };
                            }),
                        Select::make('dataAccessAreas')
                            ->label('Badan Usaha')
                            ->relationship('dataAccessAreas', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->helperText('Admin melihat karyawan di badan usaha ini, ditambah anggota timnya sendiri.')
                            ->visible(fn (Get $get): bool => $get('data_access') === User::DATA_ACCESS_SELECTED)
                            ->required(fn (Get $get): bool => $get('data_access') === User::DATA_ACCESS_SELECTED),
                    ])
                    ->collapsible()
                    ->columnSpan(2)
                    ->columns(1),

                Flex::make([
                    Group::make([
                        Section::make('Login')
                            ->schema([
                                TextInput::make('username')
                                    ->label('Username')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->dehydrateStateUsing(fn ($state) => strtolower($state))
                                    ->regex('/^[\S]+$/')
                                    ->validationMessages([
                                        'regex' => 'Username tidak boleh mengandung spasi',
                                    ])
                                    ->helperText('Username tidak boleh mengandung spasi'),
                                TextInput::make('password')
                                    ->label('Kata Sandi')
                                    ->password()
                                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->maxLength(255)
                                    ->label('Password')
                                    ->placeholder('Masukkan password')
                                    ->required(fn (string $context): bool => $context === 'create')
                                    ->revealable(),
                            ])
                            ->collapsible()
                            ->columns(1)
                            ->columnSpan(2),
                    ])
                        ->columns(1)
                        ->columnSpan(2),
                ]),
            ])->columns(3);
    }

    /**
     * Penjaga sisi server untuk Create/Edit: jangan percaya state Livewire (field disabled/hidden bisa diubah klien).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateAuthorizedData(array $data, ?User $record = null): array
    {
        $actor = static::actor();

        if (
            array_key_exists('role_id', $data)
            && static::isAdminRoleId($data['role_id'])
            && ! static::canManageDataAccess($actor)
        ) {
            throw ValidationException::withMessages([
                'data.role_id' => 'Hanya admin dengan akses seluruh badan usaha yang dapat memberikan role ADMIN.',
            ]);
        }

        $data = static::mutateDataAccessData($data, $actor, $record);

        static::ensureAreaInScope($actor, $data, $record);
        static::ensureApprovalInScope($actor, $data, $record);

        if (! DataAccessService::isAdmin($actor)) {
            unset($data['no_hp']);

            return $data;
        }

        if (array_key_exists('no_hp', $data)) {
            if (blank($data['no_hp'])) {
                $data['no_hp'] = null;

                return $data;
            }

            $number = WhatsAppNumber::toLocal((string) $data['no_hp']);

            if (! $number) {
                throw ValidationException::withMessages([
                    'data.no_hp' => 'Format No. HP WhatsApp Indonesia tidak valid.',
                ]);
            }

            $data['no_hp'] = $number;
        }

        return $data;
    }

    /**
     * data_access hanya disimpan dari super admin dan hanya bermakna untuk role ADMIN.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function mutateDataAccessData(array $data, ?User $actor, ?User $record): array
    {
        // Daftar badan usaha disimpan lewat relasi form (hanya terlihat oleh super admin), bukan kolom users.
        unset($data['dataAccessAreas']);

        if (! static::canManageDataAccess($actor)) {
            unset($data['data_access']);
        }

        if (! array_key_exists('role_id', $data)) {
            return $data;
        }

        if (! static::isAdminRoleId($data['role_id'])) {
            static::ensureAnotherGlobalAdminRemains($actor, $record);
            $data['data_access'] = User::DATA_ACCESS_ALL;

            return $data;
        }

        if (blank($data['data_access'] ?? null)) {
            unset($data['data_access']);

            return $data;
        }

        if (! in_array($data['data_access'], [User::DATA_ACCESS_ALL, User::DATA_ACCESS_SELECTED], true)) {
            throw ValidationException::withMessages([
                'data.data_access' => 'Pilihan akses data tidak valid.',
            ]);
        }

        if ($data['data_access'] === User::DATA_ACCESS_SELECTED && static::isActorRecord($record, $actor)) {
            throw ValidationException::withMessages([
                'data.data_access' => 'Anda tidak dapat membatasi akses data akun Anda sendiri.',
            ]);
        }

        return $data;
    }

    /**
     * Super admin terakhir tidak boleh melepas role ADMIN dari akunnya sendiri (sama seperti API).
     */
    protected static function ensureAnotherGlobalAdminRemains(?User $actor, ?User $record): void
    {
        if (! static::isActorRecord($record, $actor) || ! DataAccessService::hasGlobalAccess($record)) {
            return;
        }

        $otherGlobalAdminExists = User::query()
            ->whereKeyNot($record->getKey())
            ->whereHas('role', fn (Builder $roleQuery) => $roleQuery->where('name', User::ROLE_ADMIN))
            ->where(fn (Builder $access) => $access
                ->whereNull('data_access')
                ->orWhere('data_access', '!=', User::DATA_ACCESS_SELECTED))
            ->exists();

        if (! $otherGlobalAdminExists) {
            throw ValidationException::withMessages([
                'data.role_id' => 'Minimal harus ada satu admin dengan akses seluruh badan usaha.',
            ]);
        }
    }

    protected static function isActorRecord(?User $record, ?User $actor = null): bool
    {
        $actor ??= static::actor();

        return $record?->exists && $actor instanceof User && (int) $record->getKey() === (int) $actor->getKey();
    }

    /**
     * Setelah simpan: akun non-ADMIN selalu 'all' tanpa daftar badan usaha; daftar hanya disimpan untuk 'selected'.
     */
    public static function normalizeDataAccess(User $record): void
    {
        $record->unsetRelation('role');
        $isAdmin = $record->isAdmin();

        if (! $isAdmin && $record->data_access !== null && $record->data_access !== User::DATA_ACCESS_ALL) {
            $record->forceFill(['data_access' => User::DATA_ACCESS_ALL])->save();
        }

        if (! $isAdmin || $record->data_access !== User::DATA_ACCESS_SELECTED) {
            $record->dataAccessAreas()->detach();
        }

        DataAccessService::clearMemo();
    }

    /**
     * Admin badan usaha tertentu hanya boleh memilih badan usaha yang diizinkan (atau mempertahankan yang lama).
     * Super admin dan atasan non-admin memakai aturan sebelumnya.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function ensureAreaInScope(?User $actor, array $data, ?User $record): void
    {
        if (blank($data['area_id'] ?? null) || ! DataAccessService::isAreaScopedAdmin($actor)) {
            return;
        }

        if ($record?->exists && (int) $record->getOriginal('area_id') === (int) $data['area_id']) {
            return;
        }

        if (DataAccessService::canAccessArea($actor, $data['area_id'])) {
            return;
        }

        throw ValidationException::withMessages([
            'data.area_id' => 'Badan usaha berada di luar cakupan akses data Anda.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function ensureApprovalInScope(?User $actor, array $data, ?User $record): void
    {
        if (blank($data['approval_id'] ?? null)) {
            return;
        }

        $approvalId = (int) $data['approval_id'];

        // Atasan yang sudah terpasang boleh dipertahankan.
        if ($record?->exists && static::nullableUserId($record->getOriginal('approval_id')) === $approvalId) {
            return;
        }

        // Berlaku juga untuk saran matriks: perubahan approval tidak boleh memperluas Tim Saya admin ke luar cakupannya.
        if (static::approvalWidensActorScope($actor, $record?->exists ? (int) $record->getKey() : null, $approvalId)) {
            throw ValidationException::withMessages([
                'data.approval_id' => static::widenedScopeMessage(),
            ]);
        }

        if (User::query()->whereKey($approvalId)->exists()) {
            $selectableIds = static::selectableApproverIds($actor, ! $record?->exists);

            if ($selectableIds === null || in_array($approvalId, $selectableIds, true)) {
                return;
            }

            // Saran matriks approval tetap diterima walau atasannya di luar cakupan admin.
            if (DataAccessService::isAdmin($actor) && static::suggestedApproverId($data, $record, $actor) === $approvalId) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'data.approval_id' => 'Approval Line berada di luar cakupan akses data Anda.',
        ]);
    }

    /**
     * Admin badan usaha tertentu tidak boleh memperluas cakupannya lewat approval line.
     *
     * Tim Saya = bawahan langsung + satu level di bawahnya. Menjadikan dirinya atasan karyawan X
     * memasukkan X dan bawahan langsung X ke timnya; menjadikan bawahan langsungnya atasan X memasukkan X.
     * Semua karyawan yang masuk itu harus sudah terlihat olehnya (badan usaha yang diizinkan, tim, atau diri sendiri).
     * Super admin dan atasan non-admin memakai aturan sebelumnya.
     */
    public static function approvalWidensActorScope(?User $actor, ?int $recordId, int $approvalId): bool
    {
        if ($recordId === null || ! DataAccessService::isAreaScopedAdmin($actor)) {
            return false;
        }

        $actorId = (int) $actor->getKey();

        if ($approvalId === $actorId) {
            $joiningIds = [
                $recordId,
                ...User::query()
                    ->where('approval_id', $recordId)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all(),
            ];
        } elseif (User::query()->whereKey($approvalId)->where('approval_id', $actorId)->exists()) {
            $joiningIds = [$recordId];
        } else {
            return false;
        }

        foreach ($joiningIds as $joiningId) {
            if (! DataAccessService::canSeeUser($actor, $joiningId)) {
                return true;
            }
        }

        return false;
    }

    protected static function widenedScopeMessage(): string
    {
        return 'Approval Line ini akan memasukkan karyawan di luar cakupan akses data Anda ke Tim Saya. Pilih atasan lain.';
    }

    /**
     * Id user yang boleh dipilih sebagai Approval Line oleh actor. null = seluruh user aktif.
     *
     * - Super admin: seluruh user.
     * - Admin badan usaha tertentu: karyawan di badan usahanya, tim, dan dirinya sendiri.
     * - Atasan non-admin: saat membuat karyawan dikunci ke dirinya sendiri; saat edit seperti sebelumnya.
     *
     * @return array<int>|null
     */
    public static function selectableApproverIds(?User $actor, bool $creating = false): ?array
    {
        if (! $actor instanceof User) {
            return [];
        }

        if (DataAccessService::hasGlobalAccess($actor)) {
            return null;
        }

        if (DataAccessService::isAdmin($actor)) {
            return DataAccessService::visibleUserIds($actor);
        }

        return $creating ? [(int) $actor->getKey()] : null;
    }

    /**
     * Saran matriks approval untuk kombinasi divisi/badan usaha/jabatan/posisi pada form.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function suggestedApproverId(array $data, ?User $record, ?User $actor = null): ?int
    {
        $value = fn (string $key): ?int => static::nullableUserId($data[$key] ?? $record?->getAttribute($key));

        if (! static::suggestionInputsInScope($actor ?? static::actor(), $value('divisi_id'), $value('area_id'), $record)) {
            return null;
        }

        $suggested = ApprovalResolverService::resolve(
            divisiId: $value('divisi_id'),
            areaId: $value('area_id'),
            roleId: $value('role_id'),
            positionId: $value('position_id'),
            excludeUserId: $record?->exists ? (int) $record->getKey() : null,
        );

        return $suggested ? (int) $suggested->getKey() : null;
    }

    /**
     * Admin badan usaha tertentu: saran approval hanya dihitung dari badan usaha dan divisi yang boleh ia pakai
     * (badan usaha yang diizinkan atau milik record saat ini, divisi di badan usaha itu atau divisi record saat ini).
     * State Livewire yang dimanipulasi tidak boleh membuka nama atasan di badan usaha lain.
     */
    protected static function suggestionInputsInScope(?User $actor, ?int $divisiId, ?int $areaId, ?User $record): bool
    {
        if (! DataAccessService::isAreaScopedAdmin($actor)) {
            return true;
        }

        if ($areaId === null) {
            return false;
        }

        $isRecordArea = $record?->exists && static::nullableUserId($record->getOriginal('area_id')) === $areaId;

        if (! $isRecordArea && ! DataAccessService::canAccessArea($actor, $areaId)) {
            return false;
        }

        if ($divisiId === null) {
            return true;
        }

        if ($record?->exists && static::nullableUserId($record->getOriginal('divisi_id')) === $divisiId) {
            return true;
        }

        return Divisi::query()->whereKey($divisiId)->where('area_id', $areaId)->exists();
    }

    /**
     * Opsi badan usaha untuk admin badan usaha tertentu: badan usaha yang diizinkan + badan usaha record saat ini.
     */
    protected static function scopeAreaOptions(Builder $query, ?User $record): Builder
    {
        $actor = static::actor();

        if (! DataAccessService::isAreaScopedAdmin($actor)) {
            return $query;
        }

        $allowedAreaIds = DataAccessService::allowedAreaIds($actor) ?? [];
        $currentAreaId = $record?->exists ? $record->getOriginal('area_id') : null;

        return $query->where(function (Builder $scoped) use ($allowedAreaIds, $currentAreaId): void {
            $scoped->whereIn($scoped->qualifyColumn('id'), $allowedAreaIds);

            if (filled($currentAreaId)) {
                $scoped->orWhere($scoped->qualifyColumn('id'), $currentAreaId);
            }
        });
    }

    /**
     * Opsi filter tabel untuk admin badan usaha tertentu: hanya nilai yang dipakai baris di menu ini.
     */
    protected static function scopeFilterOptions(Builder $query, string $userColumn): Builder
    {
        if (! DataAccessService::isAreaScopedAdmin(static::actor())) {
            return $query;
        }

        $visibleRows = static::getEloquentQuery();
        $column = $visibleRows->qualifyColumn($userColumn);

        return $query->whereIn(
            $query->getModel()->getQualifiedKeyName(),
            $visibleRows->select($column)->whereNotNull($column),
        );
    }

    /**
     * Record masih dalam cakupan actor (policy update: badan usaha, tim, atau super admin).
     */
    protected static function isRecordInActorScope(User $record): bool
    {
        $actor = static::actor();

        return $actor instanceof User && $actor->can('update', $record);
    }

    public static function dataAccessLabel(User $record): string
    {
        if (! $record->isAdmin()) {
            return '-';
        }

        if ($record->data_access !== User::DATA_ACCESS_SELECTED) {
            return 'Semua';
        }

        $areaNames = $record->dataAccessAreas->pluck('name')->sort()->implode(', ');

        return $areaNames !== '' ? $areaNames : 'Belum dipilih';
    }

    public static function createsApprovalCycle(int $recordId, int $approvalId): bool
    {
        return ApprovalResolverService::createsApprovalCycle($recordId, $approvalId);
    }

    public static function shouldApplySuggestedApproval(mixed $currentApprovalId, mixed $previousSuggestionId, ?int $recordId, int $authId): bool
    {
        if (blank($currentApprovalId)) {
            return true;
        }

        if (filled($previousSuggestionId) && (int) $currentApprovalId === (int) $previousSuggestionId) {
            return true;
        }

        return $recordId === null
            && blank($previousSuggestionId)
            && (int) $currentApprovalId === $authId;
    }

    public static function approvalLineSuggestionText(
        ?int $divisiId,
        ?int $areaId,
        ?int $roleId,
        ?int $positionId,
        ?int $recordId,
        bool $creating,
    ): string {
        $outcome = ApprovalResolverService::resolveOutcome(
            divisiId: $divisiId,
            areaId: $areaId,
            roleId: $roleId,
            positionId: $positionId,
            excludeUserId: $recordId,
        );
        $approver = $outcome['approver'];

        if ($approver instanceof User && is_string($outcome['label'])) {
            return "Disarankan otomatis: {$outcome['label']} ({$approver->nama_lengkap}). Anda tetap dapat mengubahnya sesuai kebutuhan.";
        }

        if ($outcome['blocked_by_cycle']) {
            return 'Aturan yang cocok membentuk siklus approval, jadi tidak ada saran otomatis. Pilih atasan lain secara manual.';
        }

        if ($outcome['excluded_self']) {
            return 'Aturan yang cocok menunjuk karyawan ini sendiri, jadi tidak ada saran otomatis. Pilih atasan lain secara manual.';
        }

        return static::approvalLineDefaultHelperText($creating);
    }

    public static function approvalLineDefaultHelperText(bool $creating): string
    {
        return $creating
            ? 'Dikelompokkan berdasarkan divisi yang dipilih, atau pilih atasan lintas divisi.'
            : 'Dikelompokkan berdasarkan divisi terpilih, atau pilih atasan lintas divisi.';
    }

    /**
     * @param  callable(string): mixed  $get
     * @param  callable(string, mixed): void  $set
     */
    public static function suggestApprovalLine(
        callable $get,
        callable $set,
        ?User $record,
        ?int $divisiId,
        ?int $areaId,
        ?int $roleId,
        ?int $positionId,
    ): void {
        $actor = static::actor();

        if (! DataAccessService::isAdmin($actor) || ! static::suggestionInputsInScope($actor, $divisiId, $areaId, $record)) {
            return;
        }

        $recordId = $record?->id ? (int) $record->id : null;
        $suggested = ApprovalResolverService::resolve(
            divisiId: $divisiId,
            areaId: $areaId,
            roleId: $roleId,
            positionId: $positionId,
            excludeUserId: $recordId,
        );

        if ($suggested === null) {
            return;
        }

        if (static::shouldApplySuggestedApproval(
            $get('approval_id'),
            $get('suggested_approval_id'),
            $recordId,
            (int) auth()->id(),
        )) {
            $set('approval_id', $suggested->id);
        }

        $set('suggested_approval_id', $suggested->id);
    }

    /**
     * @param  Collection<int, User>  $records
     * @return array{updated: int, skipped_cycles: int, skipped_self: int, skipped_scope: int}
     */
    public static function assignApprovalLine(Collection $records, int $targetApproverId): array
    {
        $actor = static::actor();

        $result = DB::transaction(function () use ($records, $targetApproverId, $actor): array {
            $updated = 0;
            $skippedCycles = 0;
            $skippedSelf = 0;
            $skippedScope = 0;

            foreach ($records as $record) {
                if ((int) $record->id === $targetApproverId) {
                    $skippedSelf++;

                    continue;
                }

                if (static::createsApprovalCycle((int) $record->id, $targetApproverId)) {
                    $skippedCycles++;

                    continue;
                }

                // Admin badan usaha tertentu tidak boleh menarik karyawan di luar cakupannya ke Tim Saya.
                if (static::approvalWidensActorScope($actor, (int) $record->id, $targetApproverId)) {
                    $skippedScope++;

                    continue;
                }

                $record->update(['approval_id' => $targetApproverId]);
                $updated++;
            }

            return [
                'updated' => $updated,
                'skipped_cycles' => $skippedCycles,
                'skipped_self' => $skippedSelf,
                'skipped_scope' => $skippedScope,
            ];
        });

        ApprovalScopeService::clearMemo();

        return $result;
    }

    /**
     * @param  array{updated: int, skipped_cycles: int, skipped_self: int, skipped_scope?: int}  $result
     * @return array{title: string, body: string, status: 'success'|'warning'}
     */
    public static function approvalLineAssignmentMessage(array $result, string $approverName): array
    {
        $parts = [];

        if ($result['updated'] > 0) {
            $parts[] = "{$result['updated']} karyawan berhasil dipindahkan ke atasan {$approverName}.";
        }

        if ($result['skipped_cycles'] > 0) {
            $parts[] = "{$result['skipped_cycles']} karyawan dilewati karena relasi approval membentuk siklus.";
        }

        if ($result['skipped_self'] > 0) {
            $parts[] = "{$result['skipped_self']} karyawan dilewati karena tidak boleh menjadi atasan dirinya sendiri.";
        }

        if (($result['skipped_scope'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_scope']} karyawan dilewati karena akan memasukkan karyawan di luar cakupan akses data Anda ke Tim Saya.";
        }

        if ($parts === []) {
            $parts[] = 'Tidak ada karyawan yang diubah.';
        }

        return [
            'title' => $result['updated'] > 0 ? 'Approval Line Berhasil Diperbarui' : 'Approval Line Tidak Diubah',
            'body' => implode(' ', $parts),
            'status' => $result['updated'] > 0 ? 'success' : 'warning',
        ];
    }

    /**
     * @param  Collection<int, User>  $records
     * @return array{updated: int, unchanged: int, skipped_cycles: int, skipped_self: int, skipped_scope: int, unresolved: int}
     */
    public static function syncApprovalLines(Collection $records): array
    {
        $records = $records
            ->unique(fn (User $user): int => (int) $user->getKey())
            ->sortBy(fn (User $user): int => (int) $user->getKey())
            ->values();

        /** @var array<int, int|null> $originalApprovalIds */
        $originalApprovalIds = [];
        foreach ($records as $record) {
            $originalApprovalIds[(int) $record->getKey()] = static::nullableUserId($record->approval_id);
        }

        // Each pass only promotes someone to a better approver that is safe
        // against the approval links already stored. The cap stops a corrupt
        // graph from looping if that assumption ever fails.
        $safetyCap = max(1, $records->count() * 20);
        $actor = static::actor();

        DB::transaction(function () use ($records, $safetyCap, $actor): void {
            for ($pass = 0; $pass < $safetyCap; $pass++) {
                $changed = false;

                foreach ($records as $record) {
                    $record->refresh();
                    $suggested = static::resolveRecordOutcome($record)['approver'];

                    if (! $suggested instanceof User) {
                        continue;
                    }

                    if ((int) $record->approval_id === (int) $suggested->id) {
                        continue;
                    }

                    // Saran matriks pun tidak boleh menarik karyawan di luar cakupan admin badan usaha tertentu ke Tim Saya.
                    if (static::approvalWidensActorScope($actor, (int) $record->getKey(), (int) $suggested->id)) {
                        continue;
                    }

                    $record->update(['approval_id' => $suggested->id]);
                    $changed = true;
                }

                if (! $changed) {
                    break;
                }
            }
        });

        $updated = 0;
        $unchanged = 0;
        $skippedCycles = 0;
        $skippedSelf = 0;
        $skippedScope = 0;
        $unresolved = 0;

        foreach ($records as $record) {
            $record->refresh();
            $outcome = static::resolveRecordOutcome($record);
            $suggested = $outcome['approver'];
            $original = $originalApprovalIds[(int) $record->getKey()] ?? null;
            $current = static::nullableUserId($record->approval_id);

            if ($suggested instanceof User && $current === (int) $suggested->id) {
                if ($original === $current) {
                    $unchanged++;
                } else {
                    $updated++;
                }

                continue;
            }

            if ($current !== $original) {
                $updated++;

                continue;
            }

            if ($suggested instanceof User && static::approvalWidensActorScope($actor, (int) $record->getKey(), (int) $suggested->id)) {
                $skippedScope++;

                continue;
            }

            if ($outcome['blocked_by_cycle']) {
                $skippedCycles++;

                continue;
            }

            if ($outcome['excluded_self']) {
                $skippedSelf++;

                continue;
            }

            $unresolved++;
        }

        ApprovalScopeService::clearMemo();

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped_cycles' => $skippedCycles,
            'skipped_self' => $skippedSelf,
            'skipped_scope' => $skippedScope,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @return array{approver: User|null, blocked_by_cycle: bool, excluded_self: bool, source: string|null, rule_name: string|null, label: string|null}
     */
    protected static function resolveRecordOutcome(User $record): array
    {
        return ApprovalResolverService::resolveOutcome(
            divisiId: $record->divisi_id ? (int) $record->divisi_id : null,
            areaId: $record->area_id ? (int) $record->area_id : null,
            roleId: $record->role_id ? (int) $record->role_id : null,
            positionId: $record->position_id ? (int) $record->position_id : null,
            excludeUserId: (int) $record->id,
        );
    }

    protected static function nullableUserId(mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param  array{updated: int, unchanged: int, skipped_cycles: int, skipped_self: int, skipped_scope?: int, unresolved: int}  $result
     * @return array{title: string, body: string, status: 'success'|'warning'}
     */
    public static function syncApprovalLinesMessage(array $result): array
    {
        $parts = [];

        if ($result['updated'] > 0) {
            $parts[] = "{$result['updated']} karyawan disesuaikan dengan matriks aturan.";
        }

        if ($result['unchanged'] > 0) {
            $parts[] = "{$result['unchanged']} karyawan sudah sesuai dengan aturan.";
        }

        if ($result['skipped_cycles'] > 0) {
            $parts[] = "{$result['skipped_cycles']} karyawan dilewati karena relasi approval membentuk siklus.";
        }

        if (($result['skipped_self'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_self']} karyawan dilewati karena tidak boleh menjadi atasan dirinya sendiri.";
        }

        if (($result['skipped_scope'] ?? 0) > 0) {
            $parts[] = "{$result['skipped_scope']} karyawan dilewati karena atasan sesuai aturan akan memasukkan karyawan di luar cakupan akses data Anda ke Tim Saya.";
        }

        if ($result['unresolved'] > 0) {
            $parts[] = "{$result['unresolved']} karyawan tidak memiliki aturan atau Kepala Divisi yang cocok.";
        }

        if ($parts === []) {
            $parts[] = 'Tidak ada karyawan yang dievaluasi.';
        }

        return [
            'title' => $result['updated'] > 0 ? 'Sinkronisasi Approval Selesai' : 'Tidak Ada Approval Line yang Berubah',
            'body' => implode(' ', $parts),
            'status' => $result['updated'] > 0 ? 'success' : 'warning',
        ];
    }

    /**
     * @param  array{title: string, body: string, status: 'success'|'warning'}  $message
     */
    protected static function sendResultNotification(array $message): void
    {
        $notification = Notification::make()
            ->title($message['title'])
            ->body($message['body']);

        if ($message['status'] === 'warning') {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => static::canManageDataAccess()
                ? $query->with('dataAccessAreas')
                : $query)
            ->columns([
                TextColumn::make('nama_lengkap')
                    ->label('Nama Lengkap')
                    ->searchable(),
                TextColumn::make('employee_id')
                    ->label('ID Karyawan')
                    ->searchable(),
                TextColumn::make('no_hp')
                    ->label('No. HP')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('area.name')
                    ->label('Area')
                    ->searchable(),
                TextColumn::make('divisi.name')
                    ->label('Divisi')
                    ->searchable(),
                TextColumn::make('position.name')
                    ->label('Posisi')
                    ->searchable(),
                TextColumn::make('role.name')
                    ->label('Jabatan')
                    ->searchable(),
                // Tables\Columns\IconColumn::make('d')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('dr')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('wn')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('wr')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('mn')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('mr')
                //     ->boolean(),
                TextColumn::make('approval.nama_lengkap')
                    ->label('Approval Line')
                    ->searchable(),
                TextColumn::make('data_access')
                    ->label('Akses Data')
                    ->badge()
                    ->state(fn (User $record): string => static::dataAccessLabel($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Semua' => 'success',
                        '-' => 'gray',
                        default => 'warning',
                    })
                    ->visible(fn (): bool => static::canManageDataAccess()),
            ])
            ->filters([
                // Admin badan usaha tertentu hanya melihat opsi yang dipakai baris di menunya (bukan divisis.area_id).
                SelectFilter::make('area')
                    ->label('Area')
                    ->relationship('area', 'name', fn (Builder $query): Builder => static::scopeFilterOptions($query, 'area_id'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('divisi')
                    ->label('Divisi')
                    ->relationship('divisi', 'name', fn (Builder $query): Builder => static::scopeFilterOptions($query, 'divisi_id'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('role')
                    ->label('Jabatan')
                    ->relationship('role', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('position')
                    ->label('Posisi')
                    ->relationship('position', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('approval')
                    ->label('Approval Line')
                    ->relationship('approval', 'nama_lengkap', fn (Builder $query): Builder => static::scopeFilterOptions($query, 'approval_id'))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                Action::make('send_reminder')
                    ->label('Kirim Pengingat KPI')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (User $record): bool => DataAccessService::isAdmin(static::actor())
                        && ! $record->trashed()
                        && static::isRecordInActorScope($record))
                    ->modalHeading(fn (User $record) => "Kirim Pengingat KPI ke {$record->nama_lengkap}")
                    ->modalSubmitActionLabel('Kirim Pengingat')
                    ->form([
                        Select::make('type')
                            ->label('Tipe Pengingat')
                            ->options([
                                'pengisian_kpi' => 'Pengisian KPI (Untuk Karyawan)',
                                'pembuatan_kpi' => 'Pembuatan KPI (Untuk Atasan)',
                            ])
                            ->default('pengisian_kpi')
                            ->live()
                            ->afterStateUpdated(fn (callable $set) => $set('setting_id', null))
                            ->required(),
                        Select::make('setting_id')
                            ->label('Aturan Pengingat')
                            ->options(fn (Get $get): array => KpiReminderSetting::query()
                                ->where('type', (string) ($get('type') ?: 'pengisian_kpi'))
                                ->where('is_active', true)
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Pilih aturan aktif yang menentukan template, tenggat, dan saluran pengiriman.'),
                        CheckboxList::make('channels')
                            ->label('Saluran Pengiriman')
                            ->options([
                                'email' => 'Email',
                                'whatsapp' => 'WhatsApp',
                            ])
                            ->default(['email', 'whatsapp'])
                            ->required()
                            ->helperText('Hanya saluran yang aktif pada pengaturan pengingat yang akan digunakan.'),
                        Textarea::make('custom_message')
                            ->label('Pesan Tambahan / Kustom (Opsional)')
                            ->placeholder('Masukkan pesan tambahan jika ada, atau biarkan kosong untuk menggunakan template standar.')
                            ->rows(4),
                    ])
                    ->action(function (User $record, array $data) {
                        // Admin mana pun boleh mengirim, tetapi karyawan harus berada dalam cakupannya.
                        abort_unless(DataAccessService::isAdmin(static::actor()) && static::isRecordInActorScope($record), 403);

                        if ($record->trashed()) {
                            Notification::make()
                                ->title('Pengingat Tidak Dikirim')
                                ->body('Pengingat tidak dapat dikirim ke user yang sudah dihapus.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $type = $data['type'];
                        $setting = static::resolveActiveReminderSetting(
                            $type,
                            (int) ($data['setting_id'] ?? 0),
                        );
                        if (! $setting) {
                            return;
                        }

                        $requestedChannels = is_array($data['channels'] ?? null)
                            ? array_values(array_intersect(['email', 'whatsapp'], $data['channels']))
                            : [];
                        $customMsg = trim((string) ($data['custom_message'] ?? ''));

                        $tenggatDay = (int) $setting->deadline_day;
                        $deadlineDate = Date::today()->day(min($tenggatDay, Date::today()->daysInMonth));
                        $tenggatLabel = $deadlineDate->format('d M Y');
                        $periodeLabel = Date::now()->isoFormat('MMMM YYYY');
                        $link = config('app.url', 'http://localhost').'/admin/kpis';

                        $placeholders = [
                            '{nama}' => $record->nama_lengkap,
                            '{tenggat}' => $tenggatLabel,
                            '{periode}' => $periodeLabel,
                            '{link}' => $link,
                        ];

                        $sentChannels = [];
                        $failedChannels = [];
                        $skippedChannels = [];

                        if (in_array('email', $requestedChannels, true)) {
                            if (! $setting->send_email) {
                                $skippedChannels[] = 'Email (dinonaktifkan pada pengaturan)';
                            } elseif (empty($record->email)) {
                                $skippedChannels[] = 'Email (alamat tidak tersedia)';
                            } else {
                                $subjectTemplate = filled($setting->email_subject)
                                    ? (string) $setting->email_subject
                                    : 'Pengingat KPI - {periode}';
                                $subject = strtr($subjectTemplate, $placeholders);
                                $bodyTemplate = filled($setting->email_body)
                                    ? (string) $setting->email_body
                                    : KpiReminderSetting::getDefaultEmailTemplate($type);
                                $body = strtr($bodyTemplate, $placeholders);
                                if ($customMsg !== '') {
                                    $body .= "\n\nPesan Tambahan:\n".$customMsg;
                                }

                                SendKpiReminderEmailJob::dispatch(
                                    $setting->id,
                                    $record->id,
                                    $record->email,
                                    $subject,
                                    $body,
                                );
                                $sentChannels[] = 'Email';
                            }
                        }

                        if (in_array('whatsapp', $requestedChannels, true)) {
                            if (! $setting->send_whatsapp) {
                                $skippedChannels[] = 'WhatsApp (dinonaktifkan pada pengaturan)';
                            } elseif (empty($record->no_hp)) {
                                $skippedChannels[] = 'WhatsApp (No. HP tidak tersedia)';
                            } else {
                                $waTemplate = filled($setting->whatsapp_template)
                                    ? (string) $setting->whatsapp_template
                                    : KpiReminderSetting::getDefaultWhatsappTemplate($type);
                                $waMessage = strtr($waTemplate, $placeholders);
                                if ($customMsg !== '') {
                                    $waMessage .= "\n\n*Pesan Tambahan:*\n".$customMsg;
                                }

                                SendKpiReminderWhatsAppJob::dispatch(
                                    $setting->id,
                                    $record->id,
                                    (string) $record->no_hp,
                                    $waMessage,
                                );
                                $sentChannels[] = 'WhatsApp';
                            }
                        }

                        static::notifyManualReminderResult(
                            $record,
                            $sentChannels,
                            $failedChannels,
                            $skippedChannels,
                        );
                    }),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('update_position')
                        ->label('Ubah Posisi Massal')
                        ->icon('heroicon-o-briefcase')
                        ->color('primary')
                        ->slideOver()
                        ->modalWidth('md')
                        ->modalHeading('Ubah Posisi Karyawan Terpilih')
                        ->modalDescription('Pilih posisi baru yang akan diterapkan ke seluruh karyawan yang telah dicentang.')
                        ->modalSubmitActionLabel('Terapkan Posisi Baru')
                        ->authorizeIndividualRecords('update')
                        ->form([
                            Select::make('position_id')
                                ->label('Posisi Baru')
                                ->options(fn (): array => Position::orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->createOptionForm([
                                    TextInput::make('name')
                                        ->label('Nama Posisi Baru')
                                        ->required(),
                                ])
                                ->createOptionUsing(fn (array $data): int => (int) Position::create($data)->getKey()),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $position = Position::find($data['position_id']);
                            $positionName = $position?->name ?? 'posisi baru';
                            $count = $records->count();

                            $records->each(function (User $record) use ($data): void {
                                $record->update([
                                    'position_id' => $data['position_id'],
                                ]);
                            });

                            Notification::make()
                                ->title('Posisi Berhasil Diperbarui')
                                ->body("{$count} karyawan berhasil dipindahkan ke posisi {$positionName}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('update_approval_line')
                        ->label('Ubah Approval Line Massal')
                        ->icon('heroicon-o-user-plus')
                        ->color('warning')
                        ->visible(fn (): bool => DataAccessService::isAdmin(static::actor()))
                        ->authorize(fn (): bool => DataAccessService::isAdmin(static::actor()))
                        ->authorizationMessage('Hanya admin yang dapat mengubah Approval Line secara massal.')
                        ->slideOver()
                        ->modalWidth('md')
                        ->modalHeading('Ubah Atasan / Approval Line Karyawan Terpilih')
                        ->modalDescription('Pilih atasan baru yang akan ditetapkan untuk seluruh karyawan yang telah dicentang.')
                        ->modalSubmitActionLabel('Terapkan Atasan Baru')
                        ->authorizeIndividualRecords('update')
                        ->form([
                            Select::make('approval_id')
                                ->label('Approval Line Baru')
                                ->options(function (): array {
                                    $selectableIds = static::selectableApproverIds(static::actor());

                                    return User::whereNull('deleted_at')
                                        ->when($selectableIds !== null, fn (Builder $query): Builder => $query->whereIn('id', $selectableIds))
                                        ->orderBy('nama_lengkap')
                                        ->pluck('nama_lengkap', 'id')
                                        ->all();
                                })
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $targetApproverId = (int) $data['approval_id'];
                            $selectableIds = static::selectableApproverIds(static::actor());
                            abort_unless($selectableIds === null || in_array($targetApproverId, $selectableIds, true), 403);
                            $approver = User::find($targetApproverId);
                            $approverName = $approver?->nama_lengkap ?? 'atasan baru';
                            $result = static::assignApprovalLine($records, $targetApproverId);

                            static::sendResultNotification(
                                static::approvalLineAssignmentMessage($result, $approverName),
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('sync_approval_rules')
                        ->label('Sinkronkan Sesuai Matriks Aturan')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->visible(fn (): bool => DataAccessService::isAdmin(static::actor()))
                        ->authorize(fn (): bool => DataAccessService::isAdmin(static::actor()))
                        ->authorizationMessage('Hanya admin yang dapat menyinkronkan Approval Line.')
                        ->requiresConfirmation()
                        ->modalHeading('Sinkronkan Approval Line Sesuai Matriks Aturan')
                        ->modalDescription('Sistem akan mengevaluasi aturan matriks approval dan Kepala Divisi yang berlaku untuk setiap karyawan yang dicentang, lalu memperbarui Approval Line mereka secara otomatis.')
                        ->modalSubmitActionLabel('Sinkronkan Sekarang')
                        ->authorizeIndividualRecords('update')
                        ->action(function (Collection $records): void {
                            static::sendResultNotification(
                                static::syncApprovalLinesMessage(static::syncApprovalLines($records)),
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    // Periksa policy per karyawan (UserPolicy tidak punya deleteAny/restoreAny/forceDeleteAny).
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
                    RestoreBulkAction::make()
                        ->authorizeIndividualRecords(),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
                ]),
            ]);
    }

    protected static function resolveActiveReminderSetting(
        string $type,
        int $settingId,
    ): ?KpiReminderSetting {
        $setting = KpiReminderSetting::query()
            ->whereKey($settingId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if ($setting) {
            return $setting;
        }

        $typeLabel = $type === 'pembuatan_kpi' ? 'Pembuatan KPI' : 'Pengisian KPI';

        Notification::make()
            ->title('Aturan Pengingat Tidak Valid')
            ->body("Aturan {$typeLabel} yang dipilih sudah tidak aktif atau tidak tersedia. Pilih ulang aturan pengingat.")
            ->danger()
            ->send();

        return null;
    }

    protected static function notifyManualReminderResult(
        User $user,
        array $sentChannels,
        array $failedChannels,
        array $skippedChannels,
    ): void {
        $sentLabel = implode(', ', $sentChannels);
        $problemLabels = [
            ...array_map(fn (string $channel): string => "{$channel} gagal", $failedChannels),
            ...$skippedChannels,
        ];
        $problemLabel = implode(', ', $problemLabels);

        if (($sentChannels !== []) && ($problemLabels !== [])) {
            Notification::make()
                ->title("Pengingat Terkirim Sebagian ke {$user->nama_lengkap}")
                ->body("Berhasil: {$sentLabel}. Tidak terkirim: {$problemLabel}.")
                ->warning()
                ->send();

            return;
        }

        if ($sentChannels !== []) {
            Notification::make()
                ->title("Pengingat Berhasil Terkirim ke {$user->nama_lengkap}")
                ->body("Saluran: {$sentLabel}.")
                ->success()
                ->send();

            return;
        }

        if ($failedChannels !== []) {
            Notification::make()
                ->title("Gagal Mengirim Pengingat ke {$user->nama_lengkap}")
                ->body("Tidak terkirim: {$problemLabel}.")
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Pengingat Tidak Dikirim')
            ->body($problemLabel !== ''
                ? "Tidak ada saluran yang dapat digunakan: {$problemLabel}."
                : 'Pilih setidaknya satu saluran pengiriman.')
            ->warning()
            ->send();
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * Query dasar tanpa pembatasan menu (dipakai juga oleh TeamMemberResource).
     *
     * @return Builder<User>
     */
    protected static function baseEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    /**
     * Karyawan: super admin melihat seluruh karyawan (termasuk arsip); admin badan usaha tertentu
     * hanya karyawan di badan usahanya (termasuk arsip) tanpa akun ADMIN; selain itu tidak ada baris.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = static::baseEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        $actor = static::actor();

        if (DataAccessService::hasGlobalAccess($actor)) {
            return $query;
        }

        if (! DataAccessService::canAccessEmployees($actor)) {
            return $query->whereRaw('1 = 0');
        }

        return DataAccessService::scopeEmployees($query, $actor)
            ->whereDoesntHave('role', fn (Builder $roleQuery): Builder => $roleQuery->where('name', User::ROLE_ADMIN));
    }
}
