<?php

namespace App\Filament\Resources\Users;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages;
use App\Filament\Resources\Users\RelationManagers;
use App\Models\Divisi;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 4;

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
                        Select::make('area_id')
                            ->preload()
                            ->searchable()
                            ->relationship('area', 'name')
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
                                if (!$area_id) {
                                    return [];
                                }
                                return Divisi::where('area_id', $area_id)
                                    ->pluck('name', 'id');
                            })
                            ->label('Divisi')
                            ->required(),
                        Select::make('role_id')
                            ->preload()
                            ->searchable()
                            ->relationship('role', 'name')
                            ->label('Jabatan')
                            ->required(),
                        Select::make('position_id')
                            ->preload()
                            ->searchable()
                            ->relationship('position', 'name')
                            ->label('Posisi')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required(),
                            ]),
                        Select::make('approval_id')
                            ->preload()
                            ->searchable()
                            ->options(function (?User $record) {
                                if (! $record) {
                                    $currentUser = auth()->user();
                                    if (! $currentUser) {
                                        return [];
                                    }

                                    if ($currentUser->role?->name === 'ADMIN') {
                                        return User::whereNull('deleted_at')
                                            ->orderBy('nama_lengkap')
                                            ->pluck('nama_lengkap', 'id');
                                    }

                                    return [(int) $currentUser->id => $currentUser->nama_lengkap];
                                }

                                $query = User::whereNull('deleted_at')
                                    ->orderBy('nama_lengkap');

                                if ($record) {
                                    $query->where('id', '!=', $record->id);
                                }

                                return $query->pluck('nama_lengkap', 'id');
                            })
                            ->default(fn () => auth()->id())
                            ->disabled(fn (?User $record): bool => $record === null && auth()->user()?->role?->name !== 'ADMIN')
                            ->dehydrated()
                            ->label('Approval')
                            ->helperText(fn (?User $record): string => $record === null
                                ? 'Otomatis mengikuti user login saat create (admin bisa pilih).'
                                : 'Bisa dipilih lintas divisi sesuai struktur approval.')
                            ->columnSpan(2),
                    ])
                    ->collapsible()
                    ->columnSpan(2)
                    ->columns(2),

                Flex::make([
                    Group::make([
                        Section::make('Login')
                            ->schema([
                                TextInput::make('username')
                                    ->label('Username')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->dehydrateStateUsing(fn($state) => strtolower($state))
                                    ->regex('/^[\S]+$/')
                                    ->validationMessages([
                                        'regex' => 'Username tidak boleh mengandung spasi',
                                    ])
                                    ->helperText('Username tidak boleh mengandung spasi'),
                                TextInput::make('password')
                                    ->label('Kata Sandi')
                                    ->password()
                                    ->dehydrateStateUsing(fn($state) => Hash::make($state))
                                    ->dehydrated(fn($state) => filled($state))
                                    ->maxLength(255)
                                    ->label('Password')
                                    ->placeholder('Masukkan password')
                                    ->required(fn(string $context): bool => $context === 'create')
                                    ->revealable(),
                            ])
                            ->collapsible()
                            ->columns(1)
                            ->columnSpan(2),
                    ])
                        ->columns(1)
                        ->columnSpan(2),
                ])
            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama_lengkap')
                    ->label('Nama Lengkap')
                    ->searchable(),
                TextColumn::make('employee_id')
                    ->label('ID Karyawan')
                    ->searchable(),
                TextColumn::make('area.name'),
                TextColumn::make('divisi.name'),
                TextColumn::make('position.name')
                    ->label('Posisi'),
                TextColumn::make('role.name')
                    ->label('Jabatan'),
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
                TextColumn::make('approval.nama_lengkap'),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->label('Area')
                    ->relationship('area', 'name'),
                SelectFilter::make('divisi')
                    ->label('Divisi')
                    ->relationship('divisi', 'name'),
                SelectFilter::make('role')
                    ->label('Jabatan')
                    ->relationship('role', 'name'),
                SelectFilter::make('position')
                    ->label('Posisi')
                    ->relationship('position', 'name'),
                SelectFilter::make('approval')
                    ->label('Approval')
                    ->relationship('approval', 'nama_lengkap'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        if (auth()->user()->role?->name === 'ADMIN') {
            return $query;
        }

        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
        if ($managedUserIds === []) {
            return $query->whereIn('id', []);
        }

        return $query->whereIn('id', $managedUserIds);
    }

    public static function getNavigationLabel(): string
    {
        if (auth()->user()->role?->name === 'ADMIN') {
            return 'Karyawan';
        }

        return 'Tim Saya';
    }
}
