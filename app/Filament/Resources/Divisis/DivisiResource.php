<?php

namespace App\Filament\Resources\Divisis;

use App\Filament\Resources\Divisis\Pages\ManageDivisis;
use App\Models\Divisi;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DivisiResource extends Resource
{
    protected static ?string $model = Divisi::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('area_id')
                    ->searchable()
                    ->preload()
                    ->relationship('area', 'name')
                    ->required(),
                TextInput::make('name')
                    ->label('Nama Divisi')
                    ->required()
                    ->maxLength(255),
                Select::make('manager_id')
                    ->label('Kepala Divisi / Manager')
                    ->helperText('Atasan default bagi anggota divisi ini jika tidak ada aturan khusus.')
                    ->relationship('manager', 'nama_lengkap')
                    ->searchable()
                    ->preload()
                    ->nullable(),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Divisi')
                    ->searchable(),
                TextColumn::make('manager.nama_lengkap')
                    ->label('Kepala Divisi')
                    ->placeholder('Belum diatur')
                    ->searchable(),
                TextColumn::make('area.name')
                    ->label('Area')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('md'),
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
            'index' => ManageDivisis::route('/'),
        ];
    }
}
