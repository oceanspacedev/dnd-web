<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentComposeTest extends TestCase
{
    public function test_env_example_follows_laravel_database_and_local_defaults(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^SESSION_DRIVER=database$/m', $env);
        $this->assertMatchesRegularExpression('/^CACHE_STORE=database$/m', $env);
        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION=database$/m', $env);
        $this->assertMatchesRegularExpression('/^FILESYSTEM_DISK=local$/m', $env);
        $this->assertMatchesRegularExpression('/^APP_MAINTENANCE_DRIVER=file$/m', $env);
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
