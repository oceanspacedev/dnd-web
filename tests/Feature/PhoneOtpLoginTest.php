<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\PhoneLogin;
use App\Filament\Resources\Users\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WhatsAppOtpNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PhoneOtpLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEloquentGuardableColumns();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->clearEloquentGuardableColumns();

        parent::tearDown();
    }

    public function test_login_page_exposes_whatsapp_entry_point(): void
    {
        $this->withoutVite();

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Atau masuk dengan')
            ->assertSee('WhatsApp')
            ->assertSee(route('phone-login'), false);
    }

    public function test_phone_login_route_and_livewire_component_are_registered(): void
    {
        $route = app('router')->getRoutes()->getByName('phone-login');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->middleware());
        $this->assertSame(
            PhoneLogin::class,
            app(ComponentRegistry::class)->getClass('phone-login'),
        );
    }

    public function test_user_with_imported_no_hp_can_request_otp_and_login_via_whatsapp(): void
    {
        $user = $this->createUser();

        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '0812-3456-7890'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', true)
            ->assertSet('data.whatsapp_number', '6281234567890')
            ->assertSee('Verifikasi dan Masuk')
            ->assertDontSee('Kirim OTP WhatsApp');

        $otp = WhatsappOtp::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(WhatsappOtp::PURPOSE_LOGIN, $otp->purpose);
        $this->assertGreaterThan(6, strlen($otp->otp_hash));

        $otp->forceFill([
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinute(),
            'attempt_count' => 0,
            'verified_at' => null,
        ])->save();

        $component
            ->set('data.otp', '123456')
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_wrong_otp_does_not_authenticate_but_the_correct_otp_does(): void
    {
        $user = $this->createUser();

        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);

        $otp = WhatsappOtp::query()->where('user_id', $user->id)->firstOrFail();
        $otp->forceFill([
            'otp_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinute(),
        ])->save();

        $component
            ->set('data.otp', '000000')
            ->call('verify')
            ->assertHasFormErrors(['otp']);

        $this->assertFalse(Auth::check());
        $this->assertSame(1, $otp->fresh()->attempt_count);

        $component
            ->set('data.otp', '123456')
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_formatted_no_hp_is_normalized_once_and_used_directly_for_login(): void
    {
        $user = $this->createUser([
            'no_hp' => '+62 812-3456-7890',
        ]);

        $this->assertSame('081234567890', $user->no_hp);

        $component = Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasNoFormErrors();

        $otp = WhatsappOtp::query()->where('user_id', $user->id)->firstOrFail();
        $otp->forceFill([
            'otp_hash' => Hash::make('654321'),
            'expires_at' => now()->addMinute(),
        ])->save();

        $component
            ->set('data.otp', '654321')
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_login_uses_no_hp_as_the_only_account_number(): void
    {
        $user = $this->createUser([
            'no_hp' => '081399999999',
        ]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number']);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081399999999'])
            ->call('send')
            ->assertHasNoFormErrors();

        $this->assertSame(1, WhatsappOtp::query()->where('user_id', $user->id)->count());
    }

    public function test_an_unknown_or_empty_no_hp_cannot_request_otp(): void
    {
        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '6281399999999'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number']);

        $this->createUser([
            'username' => 'legacy-user',
            'no_hp' => null,
        ]);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number']);

        $this->assertSame(0, WhatsappOtp::query()->count());
    }

    public function test_wrong_expired_or_exhausted_otp_is_rejected(): void
    {
        $user = $this->createUser();
        $otp = $this->createOtp($user, '123456');

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->set('awaitingOtp', true)
            ->set('data.otp', '000000')
            ->call('verify')
            ->assertHasFormErrors(['otp']);

        $this->assertFalse(Auth::check());
        $this->assertSame(1, $otp->fresh()->attempt_count);

        $otp->forceFill(['expires_at' => now()->subSecond()])->save();

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->set('awaitingOtp', true)
            ->set('data.otp', '123456')
            ->call('verify')
            ->assertHasFormErrors(['otp']);

        $otp->forceFill([
            'expires_at' => now()->addMinute(),
            'attempt_count' => 5,
        ])->save();

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->set('awaitingOtp', true)
            ->set('data.otp', '123456')
            ->call('verify')
            ->assertHasFormErrors(['otp']);

        $this->assertFalse(Auth::check());
    }

    public function test_send_requests_are_limited_independently_by_number(): void
    {
        $this->createUser();
        $numberKey = hash('sha256', '6281234567890');
        RateLimiter::clear("whatsapp-otp:filament:number:5m:{$numberKey}");
        RateLimiter::clear("whatsapp-otp:filament:number:1h:{$numberKey}");

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            Livewire::test(PhoneLogin::class)
                ->fillForm(['whatsapp_number' => '081234567890'])
                ->call('send')
                ->assertHasNoFormErrors();
        }

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number']);

        $this->assertSame(3, WhatsappOtp::query()->count());
    }

    public function test_a_verified_otp_can_only_be_consumed_once(): void
    {
        $user = $this->createUser();
        $otp = $this->createOtp($user, '123456');
        $service = app(\App\Services\WhatsAppOtpService::class);

        $this->assertNotNull($service->verify(
            $user,
            '6281234567890',
            WhatsappOtp::PURPOSE_LOGIN,
            '123456',
        ));
        $this->assertNull($service->verify(
            $user,
            '6281234567890',
            WhatsappOtp::PURPOSE_LOGIN,
            '123456',
        ));
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_issuing_a_new_otp_invalidates_the_previous_code(): void
    {
        $user = $this->createUser();
        $service = app(\App\Services\WhatsAppOtpService::class);

        $first = $service->issue($user, '6281234567890', WhatsappOtp::PURPOSE_LOGIN);
        $second = $service->issue($user, '6281234567890', WhatsappOtp::PURPOSE_LOGIN);

        $this->assertTrue($first['otp_record']->fresh()->expires_at->lte(now()));
        $this->assertGreaterThan(
            $first['otp_record']->id,
            $second['otp_record']->id,
        );
    }

    public function test_changing_no_hp_expires_every_pending_login_otp(): void
    {
        $user = $this->createUser();
        $otp = $this->createOtp($user, '123456');

        $user->update(['no_hp' => '+62 813-9876-5432']);

        $this->assertSame('081398765432', $user->fresh()->no_hp);
        $this->assertTrue($otp->fresh()->expires_at->lte(now()));
    }

    public function test_only_an_admin_can_change_no_hp_through_the_user_form(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $manager = $this->createUser([
            'username' => 'manager',
            'role_id' => $managerRole->id,
            'no_hp' => null,
        ]);
        Auth::login($manager);
        $managerPayload = UserResource::mutateAuthorizedData([
            'no_hp' => '081398765432',
        ]);

        $this->assertArrayNotHasKey('no_hp', $managerPayload);

        Auth::logout();
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $admin = $this->createUser([
            'username' => 'admin',
            'role_id' => $adminRole->id,
            'no_hp' => null,
        ]);
        Auth::login($admin);

        $adminPayload = UserResource::mutateAuthorizedData([
            'no_hp' => '+62 813-9876-5432',
        ]);

        $this->assertSame('081398765432', $adminPayload['no_hp']);
    }

    public function test_a_non_admin_cannot_assign_the_admin_role_through_the_user_form(): void
    {
        $managerRole = Role::query()->create(['name' => 'MANAGER']);
        $adminRole = Role::query()->create(['name' => 'ADMIN']);
        $manager = $this->createUser([
            'username' => 'manager-role-guard',
            'role_id' => $managerRole->id,
            'no_hp' => null,
        ]);
        Auth::login($manager);

        $this->expectException(ValidationException::class);

        UserResource::mutateAuthorizedData(['role_id' => $adminRole->id]);
    }

    public function test_failed_delivery_expires_otp_and_shows_generic_error(): void
    {
        $user = $this->createUser();
        $notifier = Mockery::mock(WhatsAppOtpNotificationService::class);
        $notifier->shouldReceive('sendOtp')
            ->once()
            ->andThrow(new RuntimeException('Gateway unavailable.'));
        $this->app->instance(WhatsAppOtpNotificationService::class, $notifier);

        Livewire::test(PhoneLogin::class)
            ->fillForm(['whatsapp_number' => '081234567890'])
            ->call('send')
            ->assertHasFormErrors(['whatsapp_number']);

        $otp = WhatsappOtp::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertTrue($otp->expires_at->lte(now()));
        $this->assertFalse(Auth::check());
    }

    public function test_phone_login_does_not_expose_separate_verification_routes(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertNull($routes->getByName('phone-login.verify'));
        $this->assertNull($routes->getByName('phone-login.verify.submit'));
    }

    private function createUser(array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'STAFF']);

        return User::query()->create(array_merge([
            'nama_lengkap' => 'DnD User',
            'username' => 'dnd-user',
            'no_hp' => '081234567890',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'area_id' => 1,
            'divisi_id' => 1,
            'd' => true,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ], $overrides));
    }

    private function createOtp(User $user, string $plainOtp): WhatsappOtp
    {
        return WhatsappOtp::query()->create([
            'user_id' => $user->id,
            'whatsapp_number' => '6281234567890',
            'purpose' => WhatsappOtp::PURPOSE_LOGIN,
            'otp_hash' => Hash::make($plainOtp),
            'expires_at' => now()->addMinute(),
            'attempt_count' => 0,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nama_lengkap');
            $table->string('username')->unique();
            $table->string('no_hp')->nullable()->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('area_id');
            $table->unsignedBigInteger('divisi_id');
            $table->boolean('d')->default(true);
            $table->boolean('wn')->default(false);
            $table->boolean('wr')->default(false);
            $table->boolean('mn')->default(false);
            $table->boolean('mr')->default(false);
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('whatsapp_otps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('whatsapp_number', 20);
            $table->string('purpose', 32);
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamps();
        });
    }

    private function clearEloquentGuardableColumns(): void
    {
        $property = new \ReflectionProperty(Model::class, 'guardableColumns');
        $property->setValue(null, []);
    }
}
