<?php

namespace App\Filament\Resources\Cutpoints;

use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Models\Cutpoint;
use App\Models\User;
use App\Policies\CutpointPolicy;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CutpointResource extends Resource
{
    protected static ?string $model = Cutpoint::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scissors';

    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    public static function canAccessAllCutpoints(?User $user = null): bool
    {
        $user ??= auth()->user();

        return DataAccessService::hasGlobalAccess($user);
    }

    /**
     * @return array<int>
     */
    public static function getManagedCutpointUserIds(?User $user = null): array
    {
        $user ??= auth()->user();
        if (! $user) {
            return [];
        }

        if (static::canAccessAllCutpoints($user)) {
            return [];
        }

        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);
    }

    /**
     * Batasi query users ke karyawan yang boleh dikelola cutpoint-nya oleh user (opsi form, $writable = true)
     * atau yang cutpoint-nya terlihat di daftar (filter, $writable = false).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public static function scopeSelectableUsers(Builder $query, ?User $user, bool $writable = true): Builder
    {
        if (static::canAccessAllCutpoints($user)) {
            return $query;
        }

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Admin per badan usaha: karyawan di badan usaha yang diizinkan, tim approval, dan dirinya sendiri;
        // untuk menulis tanpa akun ADMIN (termasuk dirinya), lihat CutpointPolicy.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            DataAccessService::scopeOwnedRecords($query, $user, $query->qualifyColumn('id'));

            return $writable
                ? $query->whereDoesntHave('role', fn (Builder $role) => $role->where('name', User::ROLE_ADMIN))
                : $query;
        }

        $managedUserIds = static::getManagedCutpointUserIds($user);

        return $managedUserIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($query->qualifyColumn('id'), $managedUserIds);
    }

    /**
     * Validasi server untuk pilihan karyawan (opsi, label, rule): jangan percaya state Livewire.
     */
    public static function canSelectUser(?User $user, mixed $userId): bool
    {
        $userId = filter_var($userId, FILTER_VALIDATE_INT);

        if (! $user || $userId === false) {
            return false;
        }

        if (static::canAccessAllCutpoints($user)) {
            return true;
        }

        if (DataAccessService::isAreaScopedAdmin($user)) {
            return CutpointPolicy::canAreaScopedAdminWriteFor($user, $userId);
        }

        return in_array($userId, static::getManagedCutpointUserIds($user), true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi User')
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search): array {
                                $query = User::query()
                                    ->whereNull('deleted_at')
                                    ->orderBy('nama_lengkap')
                                    ->limit(50);

                                if ($search !== '') {
                                    $query->where(function (Builder $builder) use ($search): void {
                                        $builder
                                            ->where('nama_lengkap', 'like', "%{$search}%")
                                            ->orWhere('username', 'like', "%{$search}%");
                                    });
                                }

                                return static::scopeSelectableUsers($query, auth()->user())
                                    ->pluck('nama_lengkap', 'id')
                                    ->all();
                            })
                            // Label hanya untuk karyawan dalam cakupan (nilai lain ditolak validasi Select).
                            ->getOptionLabelUsing(fn ($value): ?string => static::canSelectUser(auth()->user(), $value)
                                ? User::query()->whereKey($value)->value('nama_lengkap')
                                : null)
                            ->required()
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail): void {
                                    if (! static::canSelectUser(auth()->user(), $value)) {
                                        $fail('User tidak valid untuk cutpoint.');

                                        return;
                                    }

                                    $exists = User::query()
                                        ->whereKey($value)
                                        ->whereNull('deleted_at')
                                        ->exists();

                                    if (! $exists) {
                                        $fail('User tidak valid untuk cutpoint.');
                                    }
                                };
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                // Posisi/divisi hanya dibaca untuk karyawan dalam cakupan.
                                $user = static::canSelectUser(auth()->user(), $state)
                                    ? User::with(['position', 'divisi'])->find($state)
                                    : null;
                                $set('user_position', $user?->position?->name ?? '-');
                                $set('user_divisi', $user?->divisi?->name ?? '-');
                            })
                            ->columnSpanFull(),
                        Grid::make(2)
                            ->schema([
                                TextInput::make('user_position')
                                    ->label('Posisi')
                                    ->default('-')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpan(1),
                                TextInput::make('user_divisi')
                                    ->label('Divisi')
                                    ->default('-')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->columnSpan(1),
                            ]),
                    ]),
                Section::make('Input Cutpoint')
                    ->schema([
                        TextInput::make('periode')
                            ->label('Periode')
                            ->required()
                            ->maxLength(7)
                            ->default(fn () => now()->format('Y-m'))
                            ->regex('/^\d{4}-\d{2}$/')
                            ->validationMessages([
                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                            ])
                            ->extraInputAttributes(['type' => 'month'])
                            ->rule('date_format:Y-m'),
                        TextInput::make('point')
                            ->required()
                            ->numeric(),
                        TextInput::make('keterangan')
                            ->maxLength(255),
                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.nama_lengkap')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('point')
                    ->numeric(),
                TextColumn::make('periode')
                    ->searchable(),
                TextColumn::make('keterangan')
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label('User')
                    ->relationship(
                        name: 'user',
                        titleAttribute: 'nama_lengkap',
                        // Filter mengikuti cakupan daftar (termasuk akun ADMIN yang hanya bisa dilihat).
                        modifyQueryUsing: fn (Builder $query): Builder => static::scopeSelectableUsers(
                            $query->whereNull('deleted_at')->orderBy('nama_lengkap'),
                            auth()->user(),
                            writable: false,
                        ),
                    )
                    ->searchable()
                    ->visible(fn () => auth()->user()?->can('create', Cutpoint::class)),
                Filter::make('periode')
                    ->schema([
                        TextInput::make('periode')
                            ->label('Periode')
                            ->maxLength(7)
                            ->default(fn () => now()->format('Y-m'))
                            ->regex('/^\d{4}-\d{2}$/')
                            ->validationMessages([
                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                            ])
                            ->extraInputAttributes(['type' => 'month'])
                            ->rule('date_format:Y-m'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        if (filled($data['periode'] ?? null)) {
                            $query->where('periode', 'like', "%{$data['periode']}%");
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('md'),
                DeleteAction::make(),
            ]);
        // ->bulkActions([
        //     Tables\Actions\BulkActionGroup::make([
        //         Tables\Actions\DeleteBulkAction::make(),
        //     ]),
        // ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCutpoints::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (static::canAccessAllCutpoints($user)) {
            return $query;
        }

        // Admin per badan usaha: badan usaha yang diizinkan, tim approval, dan dirinya sendiri. Hak edit/hapus
        // dicek per baris dari badan usaha & role pemilik: muat sekaligus agar tidak ada query per baris.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            return DataAccessService::scopeOwnedRecords($query, $user, $query->qualifyColumn('user_id'))
                ->with('user.role');
        }

        if ($user?->can('create', Cutpoint::class)) {
            $managedUserIds = static::getManagedCutpointUserIds($user);
            if ($managedUserIds === []) {
                return $query->whereIn('user_id', []);
            }

            return $query->whereIn('user_id', $managedUserIds);
        }

        if (! $user) {
            return $query->whereIn('user_id', []);
        }

        return $query->where('user_id', $user->id);
    }
}
