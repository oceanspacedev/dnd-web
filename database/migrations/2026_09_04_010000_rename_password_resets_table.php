<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade installations that ran the legacy password reset migration.
     */
    public function up(): void
    {
        if (Schema::hasTable('password_resets') && ! Schema::hasTable('password_reset_tokens')) {
            Schema::rename('password_resets', 'password_reset_tokens');
        }
    }

    /**
     * Restore the legacy table name when this compatibility migration is rolled back.
     */
    public function down(): void
    {
        if (Schema::hasTable('password_reset_tokens') && ! Schema::hasTable('password_resets')) {
            Schema::rename('password_reset_tokens', 'password_resets');
        }
    }
};
