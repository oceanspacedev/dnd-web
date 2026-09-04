<?php

namespace App\Filament\Resources\KpiDescriptions;

use App\Filament\Resources\KpiDescriptions\Pages\ManageKpiDescriptions;
use App\Models\KpiDescription;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KpiDescriptionResource extends Resource
{
    protected static ?string $model = KpiDescription::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'KPI Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(255),
                TextInput::make('subdescription'),
                Select::make('kpi_category_id')
                    ->relationship('kpi_category', 'name')
                    ->required(),
                Toggle::make('is_negative')
                    ->label('Lower is Better (Negative KPI)')
                    ->default(false)
                    ->required(),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('kpi_category.name'),
                IconColumn::make('is_negative')
                    ->boolean()
                    ->label('Lower is Better'),
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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageKpiDescriptions::route('/'),
        ];
    }
}
