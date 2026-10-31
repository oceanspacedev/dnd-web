<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvExampleTest extends TestCase
{
    public function test_env_example_follows_local_session_cache_and_horizon_queue(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^APP_NAME=DnD$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_LOCALE=id$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_FAKER_LOCALE=id_ID$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_CONNECTION=mysql$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_HOST=127\.0\.0\.1$/m', $env);
        $this->assertMatchesRegularExpression('/^DB_DATABASE=dnd$/m', $env);
        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=database$/m', $env);
        $this->assertMatchesRegularExpression('/^CACHE_STORE=database$/m', $env);
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=redis$/m', $env);
        $this->assertMatchesRegularExpression('/^FILESYSTEM_DISK=local$/m', $env);
        $this->assertMatchesRegularExpression('/^BROADCAST_CONNECTION=log$/m', $env);
        $this->assertMatchesRegularExpression('/^MAIL_MAILER=log$/m', $env);
        $this->assertMatchesRegularExpression('/^MAIL_SCHEME=null$/m', $env);
        $this->assertMatchesRegularExpression('/^REDIS_CLIENT=phpredis$/m', $env);
        $this->assertMatchesRegularExpression('/^REDIS_PASSWORD=null$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_MAINTENANCE_DRIVER=file$/m', $env);
    }

    public function test_env_example_documents_still_used_app_keys(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'KPI_CHECKLIST_LOCK_DAYS',
            'KPI_CACHE_TTL_CATEGORIES_SECONDS',
            'KPI_CACHE_TTL_DESCRIPTIONS_SECONDS',
            'KPI_CACHE_TTL_POSITIONS_SECONDS',
            'KPI_CACHE_TTL_LEADERBOARD_SECONDS',
            'KPI_CACHE_TTL_SCORING_RULES_SECONDS',
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
            'SESSION_CONNECTION',
            'QUEUE_TIMEOUT',
            'QUEUE_MAX_TIME',
            'QUEUE_MEMORY',
            'HORIZON_PATH',
            'LOG_VIEWER_ENABLED',
            'LOG_VIEWER_PATH',
        ] as $key) {
            $this->assertMatchesRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $env,
                "{$key} should remain documented in .env.example"
            );
        }
    }

    public function test_env_example_omits_retired_laravel_and_docker_keys(): void
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
            // Deployment tanpa Docker: kunci Compose/Caddy/1Panel tidak lagi dipakai.
            'CADDY_SITE',
            'ACME_EMAIL',
            'COMPOSE_PROJECT_NAME',
            'WEB_CONTAINER_NAME',
            'ONEPANEL_WEB_PORT',
            'DB_ROOT_PASSWORD',
        ] as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/^#?\s*'.preg_quote($key, '/').'=/m',
                $env,
                "{$key} should not appear in .env.example"
            );
        }
    }
}
