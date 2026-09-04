<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class StoredSpreadsheetUpload
{
    public static function import(Import $import, mixed $upload, string $directory): void
    {
        $path = self::validatedPath($upload, $directory);
        $diskName = (string) (config('filament.default_filesystem_disk') ?: config('filesystems.default', 'local'));
        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            throw new RuntimeException('File import tidak ditemukan. Silakan unggah ulang file.');
        }

        try {
            Excel::import($import, $path, $diskName);
        } finally {
            try {
                if (! $disk->delete($path)) {
                    Log::warning('Spreadsheet import cleanup failed.', [
                        'disk' => $diskName,
                        'path' => $path,
                    ]);
                }
            } catch (Throwable $exception) {
                Log::warning('Spreadsheet import cleanup failed.', [
                    'disk' => $diskName,
                    'path' => $path,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private static function validatedPath(mixed $upload, string $directory): string
    {
        if (is_array($upload)) {
            $upload = reset($upload);
        }

        if (! is_string($upload) || trim($upload) === '') {
            throw new UnexpectedValueException('File upload tidak valid. Silakan unggah ulang file.');
        }

        $directory = trim($directory, '/');
        $path = ltrim(trim($upload), '/');
        $prefix = $directory.'/';
        $relativePath = str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix))
            : '';

        if (
            $directory === ''
            || $relativePath === ''
            || str_contains($relativePath, '/')
            || str_contains($relativePath, '\\')
        ) {
            throw new UnexpectedValueException('Lokasi file import tidak valid. Silakan unggah ulang file.');
        }

        return $path;
    }
}
