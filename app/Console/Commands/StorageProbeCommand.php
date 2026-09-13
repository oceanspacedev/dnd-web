<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class StorageProbeCommand extends Command
{
    protected $signature = 'app:storage-probe';

    protected $description = 'Verify that the default filesystem can write, read, and delete an object';

    public function handle(): int
    {
        $diskName = (string) config('filesystems.default');
        $path = 'healthchecks/'.Str::uuid().'.txt';
        $payload = bin2hex(random_bytes(32));
        $disk = null;
        $objectCreated = false;
        $probeSucceeded = false;
        $cleanupSucceeded = true;

        try {
            $disk = Storage::disk($diskName);
            $objectCreated = $disk->put($path, $payload);

            if (! $objectCreated) {
                throw new \RuntimeException('Storage write failed.');
            }

            $storedPayload = $disk->get($path);

            if (! is_string($storedPayload) || ! hash_equals($payload, $storedPayload)) {
                throw new \RuntimeException('Storage read verification failed.');
            }

            $probeSucceeded = true;
        } catch (Throwable $exception) {
            Log::error('Default storage probe failed.', [
                'disk' => $diskName,
                'exception' => $exception::class,
            ]);
        } finally {
            if ($objectCreated && $disk !== null) {
                try {
                    if (! $disk->delete($path)) {
                        $cleanupSucceeded = false;
                        Log::warning('Storage probe object cleanup failed.', [
                            'disk' => $diskName,
                            'path' => $path,
                        ]);
                    }
                } catch (Throwable $exception) {
                    $cleanupSucceeded = false;
                    Log::warning('Storage probe object cleanup failed.', [
                        'disk' => $diskName,
                        'path' => $path,
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        if (! $probeSucceeded || ! $cleanupSucceeded) {
            $this->error('Storage default tidak dapat digunakan. Periksa konfigurasi dan izin read/write/delete.');

            return self::FAILURE;
        }

        $this->info("Storage disk [{$diskName}] siap digunakan.");

        return self::SUCCESS;
    }
}
