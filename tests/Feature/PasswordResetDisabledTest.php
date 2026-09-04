<?php

namespace Tests\Feature;

use Tests\TestCase;

class PasswordResetDisabledTest extends TestCase
{
    public function test_the_admin_registration_route_is_not_available(): void
    {
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_the_admin_login_page_does_not_show_a_registration_link(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSeeHtml('/admin/register');
    }

    public function test_the_admin_password_reset_request_route_is_not_available(): void
    {
        $this->get('/admin/password-reset/request')->assertNotFound();
    }

    public function test_the_admin_login_page_does_not_show_a_password_reset_link(): void
    {
        $this->get('/admin/login')->assertOk()->assertDontSeeHtml('/admin/password-reset/request');
    }
}
