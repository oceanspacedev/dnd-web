<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_user_for_the_application_schema(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->getKey());
        $this->assertNotSame('', $user->nama_lengkap);
        $this->assertNotSame('', $user->username);
        $this->assertTrue($user->d);
    }
}
