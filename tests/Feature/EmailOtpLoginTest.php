<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\EmailLogin;
use App\Mail\EmailLoginMail;
use App\Models\EmailOtp;
use App\Models\Role;
use App\Models\User;
use App\Services\EmailOtpService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailOtpLoginTest extends TestCase
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

        config()->set('services.whatsapp.api_url');
        config()->set('services.whatsapp.api_key');
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', 'smtp.example.test');
        config()->set('mail.from.address', 'noreply@example.test');

        Mail::fake();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->clearEloquentGuardableColumns();

        parent::tearDown();
    }

    public function test_login_page_exposes_email_entry_point_when_mailer_is_configured(): void
    {
        $this->withoutVite();

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Atau masuk dengan')
            ->assertSeeHtml(route('email-login'))
            ->assertDontSeeHtml(route('phone-login'));
    }

    public function test_login_page_shows_whatsapp_and_email_side_by_side(): void
    {
        $this->withoutVite();

        config()->set('services.whatsapp.api_url', 'https://gateway.example.test/api/v1/messages');
        config()->set('services.whatsapp.api_key', 'test-key');

        $this->get('/admin/login')
            ->assertOk()
            ->assertSeeInOrder(['Atau masuk dengan', 'WhatsApp', 'Email'])
            ->assertSeeHtml(route('phone-login'))
            ->assertSeeHtml(route('email-login'));
    }

    public function test_login_page_hides_email_when_mailer_only_logs_or_stores_messages(): void
    {
        $this->withoutVite();

        foreach (['log', 'array'] as $mailer) {
            config()->set('mail.default', $mailer);

            $this->get('/admin/login')
                ->assertOk()
                ->assertDontSee('Atau masuk dengan')
                ->assertDontSeeHtml(route('email-login'));
        }
    }

    public function test_email_login_redirects_when_mailer_is_not_configured(): void
    {
        config()->set('mail.default', 'log');

        $this->get(route('email-login'))->assertRedirect();
        $this->get(route('email-login.link', ['token' => Str::random(64)]))->assertRedirect();
    }

    public function test_email_login_routes_and_livewire_component_are_registered(): void
    {
        $routes = resolve('router')->getRoutes();

        foreach (['email-login', 'email-login.link'] as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains('web', $route->middleware());
        }

        $this->assertSame(
            EmailLogin::class,
            resolve(ComponentRegistry::class)->getClass('email-login'),
        );
    }

    public function test_user_can_request_a_code_by_email_and_login_with_it(): void
    {
        $user = $this->createUser(['email' => 'Dnd.User@Example.test']);

        $component = Livewire::test(EmailLogin::class)
            ->assertSee('Kirim Kode ke Email')
            ->fillForm(['email' => 'dnd.user@example.TEST'])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertNoRedirect()
            ->assertSet('awaitingOtp', true)
            ->assertSet('data.email', 'dnd.user@example.test')
            ->assertSee('Verifikasi dan Masuk');

        $mail = $this->sentLoginMail('dnd.user@example.test');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $mail->otp);
        $this->assertSame('DnD User', $mail->name);
        $this->assertSame(10, $mail->expiresInMinutes);

        $otp = EmailOtp::query()->where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(EmailOtp::PURPOSE_LOGIN, $otp->purpose);
        $this->assertNotSame($mail->otp, $otp->otp_hash);
        $this->assertNotSame($this->tokenFrom($mail), $otp->token_hash);

        $component
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertSame($user->id, Auth::id());
        $this->assertNotNull($otp->fresh()->verified_at);
    }

    public function test_magic_link_logs_in_without_typing_the_code(): void
    {
        $user = $this->createUser(['email' => 'dnd.user@example.test']);
        $mail = $this->requestCode('dnd.user@example.test');
        $token = $this->tokenFrom($mail);

        $this->assertSame(route('email-login.link', ['token' => $token]), $mail->loginUrl);

        Livewire::test(EmailLogin::class, ['token' => $token])
            ->assertSee('Memproses link masuk')
            ->assertSeeHtml('wire:init="consumeMagicLink"')
            ->call('consumeMagicLink')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertSame($user->id, Auth::id());
    }

    public function test_opening_the_magic_link_url_does_not_consume_it(): void
    {
        $this->withoutVite();

        $this->createUser(['email' => 'dnd.user@example.test']);
        $token = $this->tokenFrom($this->requestCode('dnd.user@example.test'));

        // Pemindai link di email hanya melakukan GET; token baru dipakai saat browser menjalankan wire:init.
        $this->get(route('email-login.link', ['token' => $token]))->assertOk();

        $this->assertFalse(Auth::check());
        $this->assertNull(EmailOtp::query()->latest('id')->firstOrFail()->verified_at);
    }

    public function test_magic_link_and_code_share_one_use(): void
    {
        $this->createUser(['email' => 'dnd.user@example.test']);
        $mail = $this->requestCode('dnd.user@example.test');

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertRedirect('/admin');

        Auth::logout();

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email'])
            ->assertSet('magicToken', null)
            ->assertNoRedirect();

        Livewire::test(EmailLogin::class)
            ->set('awaitingOtp', true)
            ->set('data.email', 'dnd.user@example.test')
            ->set('data.otp', $mail->otp)
            ->call('verify')
            ->assertHasErrors(['data.otp'])
            ->assertNoRedirect();

        $this->assertFalse(Auth::check());
    }

    public function test_expired_or_unknown_magic_link_is_rejected(): void
    {
        $this->createUser(['email' => 'dnd.user@example.test']);
        $token = $this->tokenFrom($this->requestCode('dnd.user@example.test'));

        EmailOtp::query()->update(['expires_at' => now()->subSecond()]);

        foreach ([$token, Str::random(64)] as $candidate) {
            Livewire::test(EmailLogin::class, ['token' => $candidate])
                ->call('consumeMagicLink')
                ->assertHasErrors(['data.email'])
                ->assertNoRedirect();
        }

        $this->assertFalse(Auth::check());
    }

    public function test_wrong_code_is_rejected_and_exhausts_after_max_attempts(): void
    {
        $this->createUser(['email' => 'dnd.user@example.test']);
        $mail = $this->requestCode('dnd.user@example.test');
        $wrongOtp = $mail->otp === '000000' ? '111111' : '000000';

        $component = Livewire::test(EmailLogin::class)
            ->set('awaitingOtp', true)
            ->set('data.email', 'dnd.user@example.test');

        for ($attempt = 1; $attempt <= EmailOtpService::MAX_ATTEMPTS; $attempt++) {
            $component->set('data.otp', $wrongOtp)->call('verify')->assertHasErrors(['data.otp']);
        }

        $component->set('data.otp', $mail->otp)->call('verify')->assertHasErrors(['data.otp']);

        // Kode yang habis percobaannya juga mematikan magic link pada record yang sama.
        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        $this->assertFalse(Auth::check());
    }

    public function test_requesting_a_new_code_invalidates_the_previous_code_and_link(): void
    {
        $this->createUser(['email' => 'dnd.user@example.test']);
        $first = $this->requestCode('dnd.user@example.test');
        $second = $this->requestCode('dnd.user@example.test');

        $this->assertNotSame($first->loginUrl, $second->loginUrl);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($first)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($second)])
            ->call('consumeMagicLink')
            ->assertRedirect('/admin');
    }

    public function test_changing_email_expires_every_pending_login_code(): void
    {
        $user = $this->createUser(['email' => 'dnd.user@example.test']);
        $mail = $this->requestCode('dnd.user@example.test');

        $user->update(['email' => 'new.address@example.test']);

        Livewire::test(EmailLogin::class, ['token' => $this->tokenFrom($mail)])
            ->call('consumeMagicLink')
            ->assertHasErrors(['data.email']);

        $this->assertFalse(Auth::check());
    }

    public function test_unknown_invalid_or_shared_email_cannot_request_a_code(): void
    {
        $this->createUser(['username' => 'first', 'no_hp' => '081111111111', 'email' => 'shared@example.test']);
        $this->createUser(['username' => 'second', 'no_hp' => '082222222222', 'email' => 'SHARED@example.test']);

        foreach (['unknown@example.test', 'shared@example.test'] as $email) {
            Livewire::test(EmailLogin::class)
                ->fillForm(['email' => $email])
                ->call('send')
                ->assertHasErrors(['data.email'])
                ->assertSet('awaitingOtp', false);
        }

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'not-an-email'])
            ->call('send')
            ->assertHasErrors(['data.email']);

        Mail::assertNothingSent();
        $this->assertSame(0, EmailOtp::query()->count());
    }

    public function test_send_requests_are_limited_per_email(): void
    {
        $this->createUser(['email' => 'dnd.user@example.test']);

        for ($request = 1; $request <= 3; $request++) {
            $this->requestCode('dnd.user@example.test');
        }

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'dnd.user@example.test'])
            ->call('send')
            ->assertHasErrors(['data.email'])
            ->assertSet('awaitingOtp', false);

        Mail::assertSentCount(3);
    }

    public function test_failed_delivery_expires_the_code_and_shows_generic_error(): void
    {
        $this->createUser(['email' => 'dnd.user@example.test']);

        Mail::shouldReceive('to')->once()->andThrow(new TransportException('Connection refused'));

        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => 'dnd.user@example.test'])
            ->call('send')
            ->assertHasErrors(['data.email'])
            ->assertSee('Email belum bisa dikirim')
            ->assertSet('awaitingOtp', false);

        $this->assertTrue(EmailOtp::query()->firstOrFail()->expires_at->lte(now()));
    }

    public function test_login_mail_contains_the_code_and_the_magic_link(): void
    {
        $html = (new EmailLoginMail(
            name: 'DnD User',
            otp: '482913',
            loginUrl: 'http://dnd.test/email-login/link/'.str_repeat('a', 64),
            expiresInMinutes: 10,
        ))->render();

        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString('http://dnd.test/email-login/link/'.str_repeat('a', 64), $html);
        $this->assertStringContainsString('10 menit', $html);
    }

    private function requestCode(string $email): EmailLoginMail
    {
        Livewire::test(EmailLogin::class)
            ->fillForm(['email' => $email])
            ->call('send')
            ->assertHasNoFormErrors()
            ->assertSet('awaitingOtp', true);

        return $this->sentLoginMail($email);
    }

    private function sentLoginMail(string $email): EmailLoginMail
    {
        $sent = Mail::sent(EmailLoginMail::class, fn (EmailLoginMail $mail): bool => $mail->hasTo($email));

        $this->assertNotEmpty($sent, "Tidak ada email login ke {$email}.");

        return $sent->last();
    }

    private function tokenFrom(EmailLoginMail $mail): string
    {
        return Str::afterLast($mail->loginUrl, '/');
    }

    private function createUser(array $overrides = []): User
    {
        $role = Role::query()->firstOrCreate(['name' => 'STAFF']);

        return User::query()->create(array_merge([
            'nama_lengkap' => 'DnD User',
            'username' => 'dnd-user',
            'no_hp' => '081234567890',
            'email' => 'dnd.user@example.test',
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
            $table->string('email')->nullable();
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

        Schema::create('email_otps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email');
            $table->string('purpose', 32);
            $table->string('otp_hash');
            $table->char('token_hash', 64)->unique();
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
