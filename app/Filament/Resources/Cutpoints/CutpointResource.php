<?php

namespace App\Filament\Resources\Cutpoints;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use App\Filament\Resources\Cutpoints\Pages\ManageCutpoints;
use App\Filament\Resources\Cutpoints\Pages;
use App\Models\Cutpoint;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CutpointResource extends Resource
{
    protected static ?string $model = Cutpoint::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-scissors';

    public static function canAccessAllCutpoints(?User $user = null): bool
    {
        $user ??= auth()->user();

        return (bool) $user && $user->role?->name === 'ADMIN';
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

                                if (! static::canAccessAllCutpoints(auth()->user())) {
                                    $managedUserIds = static::getManagedCutpointUserIds(auth()->user());
                                    if ($managedUserIds === []) {
                                        return [];
                                    }

                                    $query->whereIn('id', $managedUserIds);
                                }

                                return $query->pluck('nama_lengkap', 'id')->all();
                            })
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()
                                ->whereKey($value)
                                ->value('nama_lengkap'))
                            ->required()
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail): void {
                                    $user = auth()->user();

                                    if (! $value || ! $user) {
                                        $fail('User tidak valid untuk cutpoint.');

                                        return;
                                    }

                                    $query = User::query()
                                        ->whereKey($value)
                                        ->whereNull('deleted_at');

                                    if (! static::canAccessAllCutpoints($user)) {
                                        $managedUserIds = static::getManagedCutpointUserIds($user);

                                        if ($managedUserIds === [] || ! in_array((int) $value, $managedUserIds, true)) {
                                            $fail('User tidak valid untuk cutpoint.');
                                        }

                                        return;
                                    }

                                    if (! $query->exists()) {
                                        $fail('User tidak valid untuk cutpoint.');
                                    }
                                };
                            })
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $user = User::with(['position', 'divisi'])->find($state);
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
                        modifyQueryUsing: function (Builder $query): Builder {
                            $query->whereNull('deleted_at')->orderBy('nama_lengkap');

                            if (! static::canAccessAllCutpoints(auth()->user())) {
                                $managedUserIds = static::getManagedCutpointUserIds(auth()->user());

                                return $managedUserIds === []
                                    ? $query->whereRaw('1 = 0')
                                    : $query->whereIn('id', $managedUserIds);
                            }

                            return $query;
                        },
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
