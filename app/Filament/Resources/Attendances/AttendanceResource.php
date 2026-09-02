<?php

namespace App\Filament\Resources\Attendances;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages;
use App\Filament\Resources\Attendances\RelationManagers;
use App\Models\Attendance;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;
    private const GLOBAL_ATTENDANCE_USERNAMES = ['darkini'];

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-calendar-date-range';
    protected static ?string $navigationLabel = 'Kehadiran';
    protected static ?int $navigationSort = 3;

    public static function canAccessAllAttendance(?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->role?->name === 'ADMIN') {
            return true;
        }

        return in_array(strtolower((string) $user->username), self::GLOBAL_ATTENDANCE_USERNAMES, true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('user_id')
                            ->preload()
                            ->searchable()
                            ->options(function () {
                                $query = User::query()
                                    ->whereNull('deleted_at')
                                    ->orderBy('nama_lengkap');

                                if (! static::canAccessAllAttendance(auth()->user())) {
                                    $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
                                    if ($managedUserIds === []) {
                                        return [];
                                    }

                                    $query->whereIn('id', $managedUserIds);
                                }

                                return $query->pluck('nama_lengkap', 'id');
                            })
                            ->label('Pengguna')
                            ->required()
                            ->columnSpan(2),

                        TextInput::make('periode')
                            ->label('Periode')
                            ->required()
                            ->maxLength(7)
                            ->default(fn() => now()->format('Y-m'))
                            ->regex('/^\d{4}-\d{2}$/')
                            ->validationMessages([
                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                            ])
                            ->extraInputAttributes(['type' => 'month'])
                            ->columnSpan(2),

                        TextInput::make('work_days')
                            ->label('Jumlah Hari Kerja')
                            ->required()
                            ->maxLength(2)
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Masukkan jumlah hari kerja'),

                        TextInput::make('late_less_30')
                            ->label('Terlambat < 30 Menit')
                            ->required()
                            ->maxLength(2)
                            ->numeric()
                            ->default(0)
                            ->placeholder('Masukkan jumlah terlambat < 30 menit'),

                        TextInput::make('late_more_30')
                            ->label('Terlambat > 30 Menit')
                            ->required()
                            ->maxLength(2)
                            ->numeric()
                            ->default(0)
                            ->placeholder('Masukkan jumlah terlambat > 30 menit'),

                        TextInput::make('sick_days')
                            ->label('Jumlah Hari Sakit')
                            ->required()
                            ->maxLength(2)
                            ->numeric()
                            ->default(0)
                            ->placeholder('Masukkan jumlah hari sakit'),
                    ])->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.nama_lengkap')
                    ->label('Nama Lengkap')
                    ->searchable(),
                TextColumn::make('user.employee_id')
                    ->label('ID Karyawan')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.area.name')
                    ->label('Area')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.divisi.name')
                    ->label('Divisi')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.position.name')
                    ->label('Posisi')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('periode')
                    ->width('10%')
                    ->alignment(Alignment::Center),
                TextColumn::make('work_days')
                    ->width('10%')
                    ->alignment(Alignment::Center)
                    ->label('Hari Kerja')
                    ->numeric(),
                TextColumn::make('late_less_30')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->label(' Keterlambatan < 30 Menit')
                    ->numeric(),
                TextColumn::make('late_more_30')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->label('Keterlambatan > 30 Menit')
                    ->numeric(),
                TextColumn::make('sick_days')
                    ->width('10%')
                    ->alignment(Alignment::Center)
                    ->label('Sakit/Izin')
                    ->numeric(),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading()
            ->filters([
                SelectFilter::make('periode')
                    ->preload()
                    ->searchable()
                    ->options(function () {
                        return Attendance::distinct('periode')
                            ->pluck('periode', 'periode')
                            ->toArray();
                    })
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListAttendances::route('/'),
            'create' => CreateAttendance::route('/create'),
            'edit' => EditAttendance::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! static::canAccessAllAttendance(auth()->user())) {
            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            if ($managedUserIds === []) {
                return $query->whereIn('user_id', []);
            }

            $query->whereIn('user_id', $managedUserIds);
        }

        return $query;
    }
}
