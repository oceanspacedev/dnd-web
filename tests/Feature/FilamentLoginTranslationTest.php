<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FilamentLoginTranslationTest extends TestCase
{
    public function test_failed_login_message_uses_the_filament_4_translation_key(): void
    {
        $login = new class extends Login
        {
            public function failLogin(): never
            {
                $this->throwFailureValidationException();
            }
        };

        try {
            $login->failLogin();
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('filament-panels::auth/pages/login.messages.failed'),
                $exception->errors()['data.login'][0],
            );

            return;
        }

        $this->fail('The login page did not throw a validation exception.');
    }
}
