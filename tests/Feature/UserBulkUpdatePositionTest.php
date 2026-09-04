<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class UserBulkUpdatePositionTest extends TestCase
{
    public function test_user_resource_table_registers_update_position_bulk_action(): void
    {
        $page = app(ListUsers::class);
        $table = UserResource::table(Table::make($page));
        $toolbarActions = $table->getToolbarActions();

        $bulkGroup = collect($toolbarActions)->first(fn ($action) => $action instanceof BulkActionGroup);
        $this->assertNotNull($bulkGroup, 'BulkActionGroup should be registered in toolbarActions.');

        $flatActions = $bulkGroup->getFlatActions();
        $this->assertArrayHasKey('update_position', $flatActions, 'Bulk action update_position should exist.');

        $bulkAction = $flatActions['update_position'];
        $this->assertInstanceOf(BulkAction::class, $bulkAction);
        $this->assertSame('Ubah Posisi Massal', $bulkAction->getLabel());
        $this->assertTrue($bulkAction->isModalSlideOver());
        $modalWidth = $bulkAction->getModalWidth();
        $this->assertSame('md', is_object($modalWidth) && property_exists($modalWidth, 'value') ? $modalWidth->value : (string) $modalWidth);
    }

    public function test_update_position_bulk_action_updates_records(): void
    {
        $role = Role::first() ?? Role::create(['name' => 'STAFF']);
        $area = \App\Models\Area::first() ?? \App\Models\Area::create(['name' => 'Test Area']);
        $divisi = \App\Models\Divisi::first() ?? \App\Models\Divisi::create(['name' => 'Test Divisi', 'area_id' => $area->id]);
        $oldPosition = Position::firstOrCreate(['name' => 'Posisi Lama']);
        $newPosition = Position::firstOrCreate(['name' => 'Posisi Baru Testing']);

        $user1 = User::create([
            'nama_lengkap' => 'Karyawan Test 1',
            'username' => 'testuser1_' . uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $oldPosition->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $user2 = User::create([
            'nama_lengkap' => 'Karyawan Test 2',
            'username' => 'testuser2_' . uniqid(),
            'password' => bcrypt('password'),
            'role_id' => $role->id,
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $oldPosition->id,
            'd' => false,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ]);

        $page = app(ListUsers::class);
        $table = UserResource::table(Table::make($page));
        $toolbarActions = $table->getToolbarActions();
        $bulkGroup = collect($toolbarActions)->first(fn ($action) => $action instanceof BulkActionGroup);
        $bulkAction = $bulkGroup->getFlatActions()['update_position'];

        $records = new Collection([$user1, $user2]);
        $bulkAction->call(['records' => $records, 'data' => ['position_id' => $newPosition->id]]);

        $this->assertEquals($newPosition->id, $user1->fresh()->position_id);
        $this->assertEquals($newPosition->id, $user2->fresh()->position_id);

        // Clean up
        $user1->forceDelete();
        $user2->forceDelete();
        $newPosition->delete();
        $oldPosition->delete();
    }
}
