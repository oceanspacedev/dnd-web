<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bring auth tables created by historical migrations in line with the
     * schemas published by Laravel 12 and Sanctum 4.
     */
    public function up(): void
    {
        if (Schema::hasTable('password_reset_tokens') && ! Schema::hasIndex('password_reset_tokens', ['email'], 'primary')) {
            // Password reset is disabled. Remove any obsolete tokens so the
            // one-token-per-email primary key can be created deterministically.
            DB::table('password_reset_tokens')->delete();

            foreach (Schema::getIndexes('password_reset_tokens') as $index) {
                if ($index['columns'] === ['email'] && ! $index['primary']) {
                    Schema::table('password_reset_tokens', function (Blueprint $table) use ($index): void {
                        $table->dropIndex($index['name']);
                    });
                }
            }

            Schema::table('password_reset_tokens', function (Blueprint $table): void {
                $table->primary('email');
            });
        }

        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                $table->text('name')->change();
            });
        }
    }

    /**
     * Restore the schemas used by the historical migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                $table->string('name')->change();
            });
        }

        if (Schema::hasTable('password_reset_tokens') && Schema::hasIndex('password_reset_tokens', ['email'], 'primary')) {
            Schema::table('password_reset_tokens', function (Blueprint $table): void {
                $table->dropPrimary(['email']);
                $table->index('email');
            });
        }
    }
};
