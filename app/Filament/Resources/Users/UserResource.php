<?php

namespace App\Filament\Resources\Users;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages;
use App\Filament\Resources\Users\RelationManagers;
use App\Models\Divisi;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use App\Mail\KpiReminderMail;
use App\Services\WhatsAppService;
use App\Models\KpiReminderSetting;
use App\Models\KpiReminderLog;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Pengguna')
                    ->schema([
                        TextInput::make('employee_id')
                            ->label('ID Karyawan')
                            ->maxLength(255),
                        TextInput::make('nama_lengkap')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('no_hp')
                            ->label('No. HP')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        Select::make('area_id')
                            ->preload()
                            ->searchable()
                            ->relationship('area', 'name')
                            ->live()
                            ->label('Area')
                            ->required()
                            ->afterStateUpdated(function ($state, callable $set) {
                                $set('divisi_id', null);
                            }),
                        Select::make('divisi_id')
                            ->preload()
                            ->searchable()
                            ->live()
                            ->options(function (callable $get) {
                                $area_id = $get('area_id');
                                if (!$area_id) {
                                    return [];
                                }
                                return Divisi::where('area_id', $area_id)
                                    ->pluck('name', 'id');
                            })
                            ->label('Divisi')
                            ->required(),
                        Select::make('role_id')
                            ->preload()
                            ->searchable()
                            ->relationship('role', 'name')
                            ->label('Jabatan')
                            ->required(),
                        Select::make('position_id')
                            ->preload()
                            ->searchable()
                            ->relationship('position', 'name')
                            ->label('Posisi')
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->required(),
                            ]),
                        Select::make('approval_id')
                            ->preload()
                            ->searchable()
                            ->options(function (?User $record) {
                                if (! $record) {
                                    $currentUser = auth()->user();
                                    if (! $currentUser) {
                                        return [];
                                    }

                                    if ($currentUser->role?->name === 'ADMIN') {
                                        return User::whereNull('deleted_at')
                                            ->orderBy('nama_lengkap')
                                            ->pluck('nama_lengkap', 'id');
                                    }

                                    return [(int) $currentUser->id => $currentUser->nama_lengkap];
                                }

                                $query = User::whereNull('deleted_at')
                                    ->orderBy('nama_lengkap');

                                if ($record) {
                                    $query->where('id', '!=', $record->id);
                                }

                                return $query->pluck('nama_lengkap', 'id');
                            })
                            ->default(fn () => auth()->id())
                            ->disabled(fn (?User $record): bool => $record === null && auth()->user()?->role?->name !== 'ADMIN')
                            ->dehydrated()
                            ->label('Approval')
                            ->helperText(fn (?User $record): string => $record === null
                                ? 'Otomatis mengikuti user login saat create (admin bisa pilih).'
                                : 'Bisa dipilih lintas divisi sesuai struktur approval.')
                            ->columnSpan(2),
                    ])
                    ->collapsible()
                    ->columnSpan(2)
                    ->columns(2),

                Flex::make([
                    Group::make([
                        Section::make('Login')
                            ->schema([
                                TextInput::make('username')
                                    ->label('Username')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->dehydrateStateUsing(fn($state) => strtolower($state))
                                    ->regex('/^[\S]+$/')
                                    ->validationMessages([
                                        'regex' => 'Username tidak boleh mengandung spasi',
                                    ])
                                    ->helperText('Username tidak boleh mengandung spasi'),
                                TextInput::make('password')
                                    ->label('Kata Sandi')
                                    ->password()
                                    ->dehydrateStateUsing(fn($state) => Hash::make($state))
                                    ->dehydrated(fn($state) => filled($state))
                                    ->maxLength(255)
                                    ->label('Password')
                                    ->placeholder('Masukkan password')
                                    ->required(fn(string $context): bool => $context === 'create')
                                    ->revealable(),
                            ])
                            ->collapsible()
                            ->columns(1)
                            ->columnSpan(2),
                    ])
                        ->columns(1)
                        ->columnSpan(2),
                ])
            ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nama_lengkap')
                    ->label('Nama Lengkap')
                    ->searchable(),
                TextColumn::make('employee_id')
                    ->label('ID Karyawan')
                    ->searchable(),
                TextColumn::make('no_hp')
                    ->label('No. HP')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('area.name'),
                TextColumn::make('divisi.name'),
                TextColumn::make('position.name')
                    ->label('Posisi'),
                TextColumn::make('role.name')
                    ->label('Jabatan'),
                // Tables\Columns\IconColumn::make('d')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('dr')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('wn')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('wr')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('mn')
                //     ->boolean(),
                // Tables\Columns\IconColumn::make('mr')
                //     ->boolean(),
                TextColumn::make('approval.nama_lengkap'),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->label('Area')
                    ->relationship('area', 'name'),
                SelectFilter::make('divisi')
                    ->label('Divisi')
                    ->relationship('divisi', 'name'),
                SelectFilter::make('role')
                    ->label('Jabatan')
                    ->relationship('role', 'name'),
                SelectFilter::make('position')
                    ->label('Posisi')
                    ->relationship('position', 'name'),
                SelectFilter::make('approval')
                    ->label('Approval')
                    ->relationship('approval', 'nama_lengkap'),
            ])
            ->recordActions([
                Action::make('send_reminder')
                    ->label('Kirim Pengingat KPI')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->role?->name === 'ADMIN')
                    ->modalHeading(fn (User $record) => "Kirim Pengingat KPI ke {$record->nama_lengkap}")
                    ->modalSubmitActionLabel('Kirim Pengingat')
                    ->form([
                        Select::make('type')
                            ->label('Tipe Pengingat')
                            ->options([
                                'pengisian_kpi' => 'Pengisian KPI (Untuk Karyawan)',
                                'pembuatan_kpi' => 'Pembuatan KPI (Untuk Atasan)',
                            ])
                            ->default('pengisian_kpi')
                            ->required(),
                        CheckboxList::make('channels')
                            ->label('Saluran Pengiriman')
                            ->options([
                                'email' => 'Email',
                                'whatsapp' => 'WhatsApp',
                            ])
                            ->default(['email', 'whatsapp'])
                            ->required(),
                        Textarea::make('custom_message')
                            ->label('Pesan Tambahan / Kustom (Opsional)')
                            ->placeholder('Masukkan pesan tambahan jika ada, atau biarkan kosong untuk menggunakan template standar.')
                            ->rows(4),
                    ])
                    ->action(function (User $record, array $data) {
                        $type = $data['type'];
                        $channels = $data['channels'] ?? [];
                        $customMsg = trim($data['custom_message'] ?? '');

                        $setting = KpiReminderSetting::where('type', $type)->where('is_active', true)->first();

                        $tenggatDay = $setting ? $setting->deadline_day : ($type === 'pembuatan_kpi' ? 5 : 25);
                        $deadlineDate = Carbon::today()->day(min($tenggatDay, Carbon::today()->daysInMonth));
                        $tenggatLabel = $deadlineDate->format('d M Y');
                        $periodeLabel = Carbon::now()->isoFormat('MMMM YYYY');
                        $link = config('app.url', 'http://localhost') . '/admin/kpis';

                        $placeholders = [
                            '{nama}' => $record->nama_lengkap,
                            '{tenggat}' => $tenggatLabel,
                            '{periode}' => $periodeLabel,
                            '{link}' => $link,
                        ];

                        $sentCount = 0;
                        $failedCount = 0;

                        if (in_array('email', $channels, true)) {
                            if (empty($record->email)) {
                                Notification::make()
                                    ->title('Email tidak tersedia')
                                    ->body("User {$record->nama_lengkap} belum memiliki alamat email.")
                                    ->warning()
                                    ->send();
                            } else {
                                try {
                                    $subject = $setting ? strtr($setting->email_subject, $placeholders) : "Pengingat KPI - {$periodeLabel}";
                                    $bodyTemplate = $setting ? $setting->email_body : KpiReminderSetting::getDefaultEmailTemplate($type);
                                    $body = strtr($bodyTemplate, $placeholders);
                                    if ($customMsg !== '') {
                                        $body .= "\n\nPesan Tambahan:\n" . $customMsg;
                                    }

                                    Mail::to($record->email)->send(new KpiReminderMail($subject, $body));

                                    if ($setting) {
                                        KpiReminderLog::create([
                                            'kpi_reminder_setting_id' => $setting->id,
                                            'user_id' => $record->id,
                                            'channel' => 'email',
                                            'recipient' => $record->email,
                                            'status' => 'sent',
                                            'sent_at' => Carbon::now(),
                                        ]);
                                    }
                                    $sentCount++;
                                } catch (\Throwable $e) {
                                    $failedCount++;
                                }
                            }
                        }

                        if (in_array('whatsapp', $channels, true)) {
                            if (empty($record->no_hp)) {
                                Notification::make()
                                    ->title('No. HP tidak tersedia')
                                    ->body("User {$record->nama_lengkap} belum memiliki No. HP.")
                                    ->warning()
                                    ->send();
                            } else {
                                $waTemplate = $setting ? $setting->whatsapp_template : KpiReminderSetting::getDefaultWhatsappTemplate($type);
                                $waMessage = strtr($waTemplate, $placeholders);
                                if ($customMsg !== '') {
                                    $waMessage .= "\n\n*Pesan Tambahan:*\n" . $customMsg;
                                }

                                $res = WhatsAppService::send($record->no_hp, $waMessage);

                                if ($setting) {
                                    KpiReminderLog::create([
                                        'kpi_reminder_setting_id' => $setting->id,
                                        'user_id' => $record->id,
                                        'channel' => 'whatsapp',
                                        'recipient' => $record->no_hp,
                                        'status' => $res['success'] ? 'sent' : 'failed',
                                        'error_message' => $res['success'] ? null : $res['message'],
                                        'sent_at' => Carbon::now(),
                                    ]);
                                }

                                if ($res['success']) {
                                    $sentCount++;
                                } else {
                                    $failedCount++;
                                }
                            }
                        }

                        if ($sentCount > 0) {
                            Notification::make()
                                ->title("Pengingat Berhasil Terkirim ke {$record->nama_lengkap}")
                                ->success()
                                ->send();
                        } elseif ($failedCount > 0) {
                            Notification::make()
                                ->title("Gagal Mengirim Pengingat")
                                ->danger()
                                ->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);

        if (auth()->user()->role?->name === 'ADMIN') {
            return $query;
        }

        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
        if ($managedUserIds === []) {
            return $query->whereIn('id', []);
        }

        return $query->whereIn('id', $managedUserIds);
    }

    public static function getNavigationLabel(): string
    {
        if (auth()->user()->role?->name === 'ADMIN') {
            return 'Karyawan';
        }

        return 'Tim Saya';
    }
}
