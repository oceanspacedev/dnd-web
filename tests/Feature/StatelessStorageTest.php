<?php

namespace Tests\Feature;

use App\Support\StoredSpreadsheetUpload;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use UnexpectedValueException;

class StatelessStorageTest extends TestCase
{
    public function test_s3_disk_uses_the_aws_flysystem_adapter(): void
    {
        $this->assertTrue(config('filesystems.disks.s3.throw'));
        $this->assertTrue(config('filesystems.disks.s3.report'));

        config()->set([
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.region' => 'us-east-1',
            'filesystems.disks.s3.bucket' => 'test-bucket',
            'filesystems.disks.s3.endpoint' => 'https://storage.example.test',
            'filesystems.disks.s3.use_path_style_endpoint' => true,
        ]);
        Storage::forgetDisk('s3');

        $this->assertInstanceOf(
            AwsS3V3Adapter::class,
            Storage::disk('s3')->getAdapter(),
        );
    }

    public function test_spreadsheet_import_uses_the_local_disk_and_removes_the_source_file(): void
    {
        config()->set('filament.default_filesystem_disk', 'local');
        Storage::fake('local');
        Excel::fake();

        $path = 'imports/users/users.xlsx';
        $import = new class implements Import {};

        Storage::disk('local')->put($path, 'spreadsheet');

        StoredSpreadsheetUpload::import($import, [$path], 'imports/users');

        Excel::assertImported(
            $path,
            'local',
            fn (Import $actualImport): bool => $actualImport === $import,
        );
        Storage::disk('local')->assertMissing($path);
    }

    public function test_spreadsheet_import_uses_the_shared_disk_and_removes_the_source_object(): void
    {
        config()->set('filament.default_filesystem_disk', 's3');
        Storage::fake('s3');
        Excel::fake();

        $path = 'imports/users/users.xlsx';
        $import = new class implements Import {};

        Storage::disk('s3')->put($path, 'spreadsheet');

        StoredSpreadsheetUpload::import($import, [$path], 'imports/users');

        Excel::assertImported(
            $path,
            's3',
            fn (Import $actualImport): bool => $actualImport === $import,
        );
        Storage::disk('s3')->assertMissing($path);
    }

    public function test_spreadsheet_import_rejects_an_object_outside_its_directory(): void
    {
        config()->set('filament.default_filesystem_disk', 's3');
        Storage::fake('s3');
        Excel::fake();

        $path = 'filament_exports/private.xlsx';
        Storage::disk('s3')->put($path, 'spreadsheet');

        $this->expectException(UnexpectedValueException::class);

        StoredSpreadsheetUpload::import(
            new class implements Import {},
            $path,
            'imports/users',
        );
    }

    public function test_excel_keeps_local_temp_files_when_the_default_disk_is_not_s3(): void
    {
        $this->assertNull(config('excel.temporary_files.remote_disk'));
    }

    public function test_storage_probe_writes_reads_and_removes_its_object(): void
    {
        config()->set('filesystems.default', 'probe');
        Storage::fake('probe');

        $this->artisan('app:storage-probe')
            ->expectsOutput('Storage disk [probe] siap digunakan.')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('probe')->allFiles());
    }
}
