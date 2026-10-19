<?php

namespace Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        self::clearEloquentGuardableColumns();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::clearEloquentGuardableColumns();
    }

    /**
     * Eloquent menyimpan daftar kolom tabel per model secara static untuk model ber-$guarded. Tes yang membangun
     * skema sendiri (mis. tabel users versi kecil) tidak boleh mewariskan daftar itu ke tes berikutnya: kolom yang
     * tidak ada di daftar lama diam-diam dibuang saat create/fill.
     */
    private static function clearEloquentGuardableColumns(): void
    {
        (new ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);
    }
}
