<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentComposeTest extends TestCase
{
    public function test_env_example_follows_laravel_database_and_local_defaults(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_NAME=DnD$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_LOCALE=id$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_FAKER_LOCALE=id_ID$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=sqlite$/m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=database$/m', $env);
        $this->assertMatchesRegularExpression('/^CACHE_STORE=database$/m', $env);
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=database$/m', $env);
        $this->assertMatchesRegularExpression('/^FILESYSTEM_DISK=local$/m', $env);
        $this->assertMatchesRegularExpression('/^BROADCAST_CONNECTION=log$/m', $env);
        $this->assertMatchesRegularExpression('/^MAIL_MAILER=log$/m', $env);
        $this->assertMatchesRegularExpression('/^MAIL_SCHEME=null$/m', $env);
        $this->assertMatchesRegularExpression('/^REDIS_CLIENT=phpredis$/m', $env);
        $this->assertMatchesRegularExpression('/^REDIS_PASSWORD=null$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_MAINTENANCE_DRIVER=file$/m', $env);
    }

    public function test_env_example_documents_still_used_app_and_compose_keys(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'KPI_CHECKLIST_LOCK_DAYS',
            'KPI_CACHE_TTL_CATEGORIES_SECONDS',
            'KPI_CACHE_TTL_DESCRIPTIONS_SECONDS',
            'KPI_CACHE_TTL_POSITIONS_SECONDS',
            'KPI_CACHE_TTL_LEADERBOARD_SECONDS',
            'KPI_REMINDER_CACHE_STORE',
            'WAG_URL',
            'WAG_TOKEN',
            'WA_CONNECT_TIMEOUT',
            'WA_API_TIMEOUT',
            'WA_OTP_EXPIRES_IN',
            'OPENAI_API_KEY',
            'OPENAI_URL',
            'OPENAI_STORE',
            'API_VERSION',
            'SCRAMBLE_DEV_TOOLS',
            'SCRAMBLE_CACHE_STORE',
            'AWS_ENDPOINT',
            'AWS_URL',
            'REDIS_DB',
            'REDIS_CACHE_DB',
            'REDIS_SESSION_DB',
            'DB_ROOT_PASSWORD',
            'COMPOSE_PROFILES',
            'SESSION_CONNECTION',
            'CADDY_SITE',
            'ACME_EMAIL',
            'COMPOSE_PROJECT_NAME',
            'WEB_CONTAINER_NAME',
            'ONEPANEL_WEB_BIND',
            'ONEPANEL_WEB_PORT',
            'LB_BIND',
            'LB_PORT',
            'DATA_BIND',
            'DB_PUBLISH_PORT',
            'REDIS_PUBLISH_PORT',
            'OCTANE_WORKERS',
            'OCTANE_MAX_REQUESTS',
            'OCTANE_MAX_EXECUTION_TIME',
            'FRANKENPHP_MAX_WAIT_TIME',
            'QUEUE_TIMEOUT',
            'QUEUE_MAX_TIME',
            'QUEUE_MEMORY',
        ] as $key) {
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $env,
                "{$key} should remain documented in .env.example"
            );
        }
    }

    public function test_env_example_omits_retired_laravel_and_unused_keys(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'CACHE_DRIVER',
            'FILESYSTEM_DRIVER',
            'BROADCAST_DRIVER',
            'QUEUE_DRIVER',
            'MAIL_ENCRYPTION',
            'API_KEY_NOTIFICATION',
            'PUSHER_APP_ID',
            'PUSHER_APP_KEY',
            'PUSHER_APP_SECRET',
            'PUSHER_APP_CLUSTER',
            'MIX_PUSHER_APP_KEY',
            'MIX_PUSHER_APP_CLUSTER',
            'APP_TIMEZONE',
            'WA_API_URL',
            'WA_API_KEY',
        ] as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $env,
                "{$key} should not appear in .env.example"
            );
        }
    }

    public function test_compose_defaults_to_laravel_single_instance_drivers(): void
    {
        $compose = (string) file_get_contents(base_path('compose.yaml'));

        $this->assertStringContainsString('SESSION_DRIVER: ${SESSION_DRIVER:-database}', $compose);
        $this->assertStringContainsString('CACHE_STORE: ${CACHE_STORE:-database}', $compose);
        $this->assertStringContainsString('QUEUE_CONNECTION: ${QUEUE_CONNECTION:-database}', $compose);
        $this->assertStringContainsString('FILESYSTEM_DISK: ${FILESYSTEM_DISK:-local}', $compose);
        $this->assertStringContainsString('APP_MAINTENANCE_STORE: ${APP_MAINTENANCE_STORE:-database}', $compose);
        $this->assertStringContainsString('storage_data:/app/storage', $compose);
        $this->assertStringContainsString('DB_QUEUE_RETRY_AFTER: ${DB_QUEUE_RETRY_AFTER:-360}', $compose);
        $this->assertStringContainsString('WAG_URL: ${WAG_URL:-${WA_API_URL:-https://waghub.mekayastudio.com}}', $compose);
        $this->assertStringContainsString('WAG_TOKEN: ${WAG_TOKEN:-${WA_API_KEY:-}}', $compose);
        $this->assertStringContainsString("profiles:\n      - redis", $compose);
        $this->assertStringContainsString('required: false', $compose);

        $this->assertDoesNotMatchRegularExpression('/REDIS_PASSWORD: \$\{REDIS_PASSWORD:\?/', $compose);
        $this->assertDoesNotMatchRegularExpression('/AWS_ACCESS_KEY_ID: \$\{AWS_ACCESS_KEY_ID:\?/', $compose);
        $this->assertDoesNotMatchRegularExpression('/AWS_BUCKET: \$\{AWS_BUCKET:\?/', $compose);
        $this->assertDoesNotMatchRegularExpression('/SESSION_DRIVER:\s*redis\s*$/m', $compose);
        $this->assertDoesNotMatchRegularExpression('/FILESYSTEM_DISK:\s*s3\s*$/m', $compose);
    }

    public function test_scale_overlay_enables_redis_without_forcing_object_storage(): void
    {
        $compose = (string) file_get_contents(base_path('compose.scale.yaml'));

        $this->assertStringContainsString('SESSION_DRIVER: ${SESSION_DRIVER:-redis}', $compose);
        $this->assertStringContainsString('profiles: !override []', $compose);
        $this->assertStringNotContainsString('FILESYSTEM_DISK: ${FILESYSTEM_DISK:-s3}', $compose);
    }

    public function test_multi_server_overlays_default_to_shared_redis_and_s3(): void
    {
        $app = (string) file_get_contents(base_path('compose.app.yaml'));
        $expose = (string) file_get_contents(base_path('compose.data-expose.yaml'));

        $this->assertStringContainsString('FILESYSTEM_DISK: ${FILESYSTEM_DISK:-s3}', $app);
        $this->assertStringContainsString('SESSION_DRIVER: ${SESSION_DRIVER:-redis}', $app);
        $this->assertStringContainsString('FILESYSTEM_DISK: ${FILESYSTEM_DISK:-s3}', $expose);
        $this->assertStringContainsString('profiles: !override []', $expose);
    }
}
