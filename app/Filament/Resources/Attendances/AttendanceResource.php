<?php

namespace App\Filament\Resources\Attendances;

use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static ?string $navigationLabel = 'Kehadiran';

    protected static ?int $navigationSort = 3;

    /**
     * Seluruh data kehadiran: super admin atau user khusus kehadiran (lihat AttendancePolicy).
     */
    public static function canAccessAllAttendance(?User $user = null): bool
    {
        return AttendancePolicy::canManageAllAttendance($user ?? auth()->user());
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

                                // Opsi karyawan = yang boleh dicatat kehadirannya oleh user (Select menolak nilai di luar opsi).
                                return AttendancePolicy::scopeWritableUsers($query, auth()->user())
                                    ->pluck('nama_lengkap', 'id');
                            })
                            ->label('Pengguna')
                            ->required()
                            ->columnSpan(2),

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
                        // Periode hanya dari data kehadiran yang terlihat oleh user.
                        return static::getEloquentQuery()
                            ->distinct()
                            ->pluck('periode', 'periode')
                            ->toArray();
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Setiap baris dicek dengan AttendancePolicy::delete: admin per badan usaha melihat data
                    // akun ADMIN (termasuk dirinya) tetapi tidak boleh menghapusnya.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
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
        $user = auth()->user();
        $query = AttendancePolicy::scopeVisible(parent::getEloquentQuery(), $user);

        // Hak edit/hapus admin per badan usaha dicek per baris dari badan usaha & role pemilik:
        // muat sekaligus agar tidak ada query per baris.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            $query->with('user.role');
        }

        return $query;
    }
}
