<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Tests\TestCase;

class UserFilterSearchableTest extends TestCase
{
    public function test_user_table_filters_are_searchable(): void
    {
        $page = resolve(ListUsers::class);
        $table = UserResource::table(Table::make($page));
        $filters = $table->getFilters();

        $filterNames = ['area', 'divisi', 'role', 'position', 'approval'];

        foreach ($filterNames as $filterName) {
            $this->assertArrayHasKey($filterName, $filters, "Filter {$filterName} should exist.");
            $filter = $filters[$filterName];
            $this->assertInstanceOf(SelectFilter::class, $filter);
            $this->assertTrue((bool) $filter->getSearchable(), "Filter {$filterName} should be searchable.");
        }
    }

    public function test_user_table_columns_are_searchable(): void
    {
        $page = resolve(ListUsers::class);
        $table = UserResource::table(Table::make($page));
        $columns = $table->getColumns();

        $columnNames = [
            'nama_lengkap',
            'employee_id',
            'no_hp',
            'email',
            'area.name',
            'divisi.name',
            'position.name',
            'role.name',
            'approval.nama_lengkap',
        ];

        foreach ($columnNames as $colName) {
            $this->assertArrayHasKey($colName, $columns, "Column {$colName} should exist.");
            $column = $columns[$colName];
            $this->assertInstanceOf(TextColumn::class, $column);
            $this->assertTrue($column->isSearchable(), "Column {$colName} should be searchable.");
        }
    }
}
