<?php

namespace App\Filament\Resources\ApprovalRules;

use App\Filament\Resources\ApprovalRules\Pages\ManageApprovalRules;
use App\Models\ApprovalRule;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ApprovalRuleResource extends Resource
{
    protected static ?string $model = ApprovalRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-pointing-in';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Aturan Approval';

    protected static ?string $modelLabel = 'Aturan Approval';

    protected static ?string $pluralModelLabel = 'Aturan Approval';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return auth()->user()?->role?->name === 'ADMIN';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Aturan')
                    ->placeholder('Contoh: Staf Operasional Surabaya / Tim Leader Marketing')
                    ->required()
                    ->maxLength(255)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (blank($get('area_id')) && blank($get('divisi_id')) && blank($get('role_id')) && blank($get('position_id'))) {
                                $fail('Isi minimal satu kriteria: Area, Divisi, Jabatan, atau Posisi.');
                            }
                        },
                    ]),

                Select::make('approver_id')
                    ->label('Approver / Atasan')
                    ->relationship('approver', 'nama_lengkap')
                    ->helperText('User yang ditunjuk menjadi approval line bagi karyawan yang memenuhi aturan ini.')
                    ->searchable()
                    ->preload()
                    ->required(),

                Select::make('area_id')
                    ->label('Area (Opsional)')
                    ->relationship('area', 'name')
                    ->helperText('Kosongkan jika aturan ini berlaku untuk semua area. Minimal satu kriteria wajib diisi.')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Select::make('divisi_id')
                    ->label('Divisi (Opsional)')
                    ->relationship('divisi', 'name')
                    ->helperText('Kosongkan jika aturan ini berlaku untuk semua divisi.')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Select::make('role_id')
                    ->label('Jabatan / Role (Opsional)')
                    ->relationship('role', 'name')
                    ->helperText('Kosongkan jika aturan ini berlaku untuk semua jabatan.')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                Select::make('position_id')
                    ->label('Posisi (Opsional)')
                    ->relationship('position', 'name')
                    ->helperText('Kosongkan jika aturan ini berlaku untuk semua posisi.')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                TextInput::make('priority')
                    ->label('Prioritas')
                    ->helperText('Aturan yang lebih spesifik selalu menang. Prioritas hanya memecah seri bila jumlah kriteria sama. Nilai lebih tinggi dipilih lebih dulu, lalu aturan yang lebih dulu dibuat.')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->helperText('Nonaktifkan jika aturan ini sementara tidak digunakan.')
                    ->default(true),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Aturan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('approver.nama_lengkap')
                    ->label('Approver / Atasan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('area.name')
                    ->label('Area')
                    ->placeholder('Semua Area')
                    ->sortable(),

                TextColumn::make('divisi.name')
                    ->label('Divisi')
                    ->placeholder('Semua Divisi')
                    ->sortable(),

                TextColumn::make('role.name')
                    ->label('Jabatan')
                    ->placeholder('Semua Jabatan')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('position.name')
                    ->label('Posisi')
                    ->placeholder('Semua Posisi')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('priority')
                    ->label('Prioritas')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->relationship('area', 'name')
                    ->label('Area'),

                SelectFilter::make('divisi')
                    ->relationship('divisi', 'name')
                    ->label('Divisi'),

                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('lg'),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageApprovalRules::route('/'),
        ];
    }
}
