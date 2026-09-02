<?php

namespace App\Filament\Resources\EmployeeReviews;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\EmployeeReviews\Pages\ListEmployeeReviews;
use App\Filament\Resources\EmployeeReviews\Pages\CreateEmployeeReview;
use App\Filament\Resources\EmployeeReviews\Pages\EditEmployeeReview;
use App\Filament\Resources\EmployeeReviews\Pages;
use App\Filament\Resources\EmployeeReviews\RelationManagers;
use App\Models\EmployeeReview;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
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

class EmployeeReviewResource extends Resource
{
    protected static ?string $model = EmployeeReview::class;
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-star';
    protected static ?string $navigationLabel = 'Penilaian';
    protected static ?int $navigationSort = 2;


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

                                if (auth()->user()->role?->name !== 'ADMIN') {
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

                        TextInput::make('responsiveness')
                            ->label('Responsivitas')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->default(0)
                            ->placeholder('Masukkan angka responsivitas (1-5)')
                            ->helperText('Masukkan angka antara 0 dan 5'),

                        TextInput::make('problem_solver')
                            ->label('Pemecah Masalah')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->default(0)
                            ->placeholder('Masukkan angka pemecah masalah (1-5)')
                            ->helperText('Masukkan angka antara 0 dan 5'),

                        TextInput::make('helpfulness')
                            ->label('Kepedulian')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->default(0)
                            ->placeholder('Masukkan angka kepedulian (1-5)')
                            ->helperText('Masukkan angka antara 0 dan 5'),

                        TextInput::make('initiative')
                            ->label('Inisiatif')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(5)
                            ->default(0)
                            ->placeholder('Masukkan angka inisiatif (1-5)')
                            ->helperText('Masukkan angka antara 0 dan 5'),
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
                TextColumn::make('responsiveness')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->label('Responsivitas')
                    ->numeric(),
                TextColumn::make('problem_solver')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->label('Pemecahan Masalah')
                    ->numeric(),
                TextColumn::make('helpfulness')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->label('Kesediaan Membantu')
                    ->numeric(),
                TextColumn::make('initiative')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->label('Inisiatif')
                    ->numeric(),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading()
            ->filters([
                SelectFilter::make('periode')
                    ->preload()
                    ->searchable()
                    ->options(function () {
                        return EmployeeReview::distinct('periode')
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
            'index' => ListEmployeeReviews::route('/'),
            'create' => CreateEmployeeReview::route('/create'),
            'edit' => EditEmployeeReview::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()->role?->name !== 'ADMIN') {
            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            if ($managedUserIds === []) {
                return $query->whereIn('user_id', []);
            }

            $query->whereIn('user_id', $managedUserIds)
                ->whereNull('deleted_at');
        }

        return $query;
    }
}
