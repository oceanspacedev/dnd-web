<?php

namespace App\Filament\Auth\Pages;

use App\Filament\Auth\Concerns\InteractsWithWhatsAppLogin;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\WhatsAppOtpService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsIconAlias;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @property-read Action $loginAction
 * @property-read Schema $form
 */
class PhoneLogin extends SimplePage
{
    use InteractsWithWhatsAppLogin;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public bool $awaitingOtp = false;

    public function mount(): void
    {
        if (Filament::getCurrentPanel() === null) {
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::bootCurrentPanel();
        }

        if (Filament::auth()->check()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        $this->maxWidth = 'full';
        $this->form->fill();
    }

    public function send(WhatsAppOtpService $otpService): void
    {
        $data = $this->form->getState();
        $number = $this->normalizeWhatsAppNumber($data['whatsapp_number'] ?? null);

        if (! $number) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => 'Nomor WhatsApp tidak valid.',
            ]);
        }

        $this->throttleOtpSend($number);

        $user = $this->findEligibleWebUserByWhatsApp($number);

        if (! $user) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => $this->unavailableWhatsAppMessage(),
            ]);
        }

        try {
            $otpService->issue($user, $number, WhatsappOtp::PURPOSE_LOGIN);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => 'OTP belum bisa dikirim ke WhatsApp. Coba lagi sebentar lagi.',
            ]);
        }

        $this->awaitingOtp = true;
        $this->form->fill([
            'whatsapp_number' => $number,
            'otp' => null,
        ]);
    }

    public function verify(WhatsAppOtpService $otpService): void
    {
        $data = $this->form->getState();
        $number = $this->normalizeWhatsAppNumber($data['whatsapp_number'] ?? null);

        if ($number) {
            $this->throttleOtpVerification($number);
        }

        $user = $number ? $this->findEligibleWebUserByWhatsApp($number) : null;

        if (! $user) {
            throw ValidationException::withMessages([
                'data.whatsapp_number' => $this->unavailableWhatsAppMessage(),
            ]);
        }

        $verifiedUser = DB::transaction(function () use ($otpService, $user, $number, $data): ?User {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedUser || ! $this->userMatchesWhatsAppNumber($lockedUser, $number)) {
                return null;
            }

            $otpRecord = $otpService->verify(
                $lockedUser,
                $number,
                WhatsappOtp::PURPOSE_LOGIN,
                (string) ($data['otp'] ?? ''),
            );

            return $otpRecord ? $lockedUser : null;
        });

        if (! $verifiedUser) {
            throw ValidationException::withMessages([
                'data.otp' => 'OTP tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        Filament::auth()->login($verifiedUser);
        session()->regenerate();

        $this->redirect('/admin');
    }

    public function submit(WhatsAppOtpService $otpService): void
    {
        if ($this->awaitingOtp) {
            $this->verify($otpService);

            return;
        }

        $this->send($otpService);
    }

    public function changePhone(): void
    {
        $this->awaitingOtp = false;
        $this->form->fill();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('whatsapp_number')
                ->label('Nomor WhatsApp')
                ->tel()
                ->required()
                ->maxLength(30)
                ->autocomplete('tel')
                ->disabled(fn (): bool => $this->awaitingOtp)
                ->dehydrated()
                ->autofocus(),
            TextInput::make('otp')
                ->label('Kode OTP')
                ->helperText('Masukkan 6 digit kode yang dikirim ke WhatsApp.')
                ->required()
                ->numeric()
                ->length(6)
                ->autocomplete('one-time-code')
                ->autofocus()
                ->visible(fn (): bool => $this->awaitingOtp),
        ]);
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->link()
            ->label('Kembali ke halaman masuk')
            ->icon(match (__('filament-panels::layout.direction')) {
                'rtl' => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN_RTL) ?? Heroicon::ArrowRight,
                default => FilamentIcon::resolve(PanelsIconAlias::PAGES_PASSWORD_RESET_REQUEST_PASSWORD_RESET_ACTIONS_LOGIN) ?? Heroicon::ArrowLeft,
            })
            ->url(filament()->getLoginUrl());
    }

    public function getTitle(): string|Htmlable
    {
        return 'Masuk dengan WhatsApp';
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Masuk dengan WhatsApp';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSubmitFormAction(),
        ];
    }

    protected function getSubmitFormAction(): Action
    {
        return Action::make('submit')
            ->label(fn (): string => $this->awaitingOtp ? 'Verifikasi dan Masuk' : 'Kirim OTP WhatsApp')
            ->submit('submit');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->awaitingOtp) {
            return Action::make('changePhone')
                ->link()
                ->label('Ganti nomor atau kirim ulang OTP')
                ->action('changePhone');
        }

        if (! filament()->hasLogin()) {
            return null;
        }

        return $this->loginAction;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('submit')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment($this->getFormActionsAlignment())
                        ->fullWidth($this->hasFullWidthFormActions())
                        ->key('form-actions'),
                ]),
        ]);
    }

    public function getView(): string
    {
        return 'filament.auth.phone-login';
    }

    public function hasLogo(): bool
    {
        return false;
    }

    protected function throttleOtpSend(string $number): void
    {
        $numberKey = hash('sha256', $number);
        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["whatsapp-otp:filament:number:5m:{$numberKey}", 3, 300],
            ["whatsapp-otp:filament:number:1h:{$numberKey}", 10, 3600],
            ["whatsapp-otp:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.whatsapp_number', 'Terlalu banyak permintaan OTP. Coba lagi sebentar lagi.');
    }

    protected function throttleOtpVerification(string $number): void
    {
        $numberKey = hash('sha256', $number);
        $ipKey = hash('sha256', (string) request()->ip());

        $this->consumeRateLimits([
            ["whatsapp-verify:filament:number:5m:{$numberKey}", 15, 300],
            ["whatsapp-verify:filament:ip:1m:{$ipKey}", 20, 60],
        ], 'data.otp', 'Terlalu banyak percobaan OTP. Coba lagi sebentar lagi.');
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $limits
     */
    protected function consumeRateLimits(array $limits, string $field, string $message): void
    {
        $limited = false;

        foreach ($limits as [$key, $maxAttempts, $decaySeconds]) {
            if (RateLimiter::hit($key, $decaySeconds) > $maxAttempts) {
                $limited = true;
            }
        }

        if ($limited) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
