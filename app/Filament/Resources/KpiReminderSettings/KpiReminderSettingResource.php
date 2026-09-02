<?php

namespace App\Filament\Resources\KpiReminderSettings;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\CheckboxList;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Filament\Resources\KpiReminderSettings\Pages\ListKpiReminderSettings;
use App\Filament\Resources\KpiReminderSettings\Pages\CreateKpiReminderSetting;
use App\Filament\Resources\KpiReminderSettings\Pages\EditKpiReminderSetting;
use App\Models\KpiReminderSetting;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

class KpiReminderSettingResource extends Resource
{
    protected static ?string $model = KpiReminderSetting::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-bell-alert';

    protected static string | \UnitEnum | null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Pengaturan Pengingat KPI';

    protected static ?string $pluralModelLabel = 'Pengaturan Pengingat KPI';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role?->name === 'ADMIN';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role?->name === 'ADMIN';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Konfigurasi Pengingat & Tenggat KPI')
                    ->description('Atur judul, target pengguna, dan jadwal pengingat otomatis')
                    ->schema([
                        TextInput::make('title')
                            ->label('Judul Aturan Pengingat')
                            ->placeholder('Contoh: Pengingat Pengisian KPI Bulanan')
                            ->required()
                            ->columnSpan(2),
                        Select::make('type')
                            ->label('Target Pengingat')
                            ->options([
                                'pembuatan_kpi' => 'Pembuatan KPI — Untuk Atasan yang belum membuat KPI tim',
                                'pengisian_kpi' => 'Pengisian KPI — Untuk Karyawan yang belum mengisi aktual KPI',
                            ])
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, callable $set) {
                                if ($state) {
                                    $set('email_body', KpiReminderSetting::getDefaultEmailTemplate($state));
                                    $set('whatsapp_template', KpiReminderSetting::getDefaultWhatsappTemplate($state));
                                }
                            })
                            ->columnSpan(2),
                        TextInput::make('deadline_day')
                            ->label('Tanggal Batas Akhir (1–31)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(31)
                            ->required()
                            ->suffix('Setiap Bulan')
                            ->helperText('Contoh: 25 untuk batas akhir tanggal 25'),
                        Toggle::make('is_active')
                            ->label('Status Aturan Aktif')
                            ->helperText('Matikan jika pengingat ingin dihentikan sementara')
                            ->default(true),
                        CheckboxList::make('reminder_days_before')
                            ->label('Kirim Pengingat Sebelum Tenggat')
                            ->options([
                                3 => 'H-3 (3 Hari Sebelum)',
                                2 => 'H-2 (2 Hari Sebelum)',
                                1 => 'H-1 (1 Hari Sebelum)',
                                0 => 'Hari H (Tanggal Tenggat)',
                            ])
                            ->columns(2)
                            ->columnSpan(2),
                        Toggle::make('send_overdue_reminder')
                            ->label('Kirim Pengingat Terlambat (Setelah Tenggat)')
                            ->default(true)
                            ->columnSpan(2),
                    ])
                    ->collapsible()
                    ->columnSpan(2)
                    ->columns(2),

                Flex::make([
                    Group::make([
                        Section::make('Template Email')
                            ->schema([
                                Toggle::make('send_email')
                                    ->label('Aktifkan Notifikasi Email')
                                    ->default(true),
                                TextInput::make('email_subject')
                                    ->label('Subjek Email')
                                    ->default('Pengingat KPI - DnD System')
                                    ->required(),
                                Textarea::make('email_body')
                                    ->label('Isi Pesan Email')
                                    ->rows(12)
                                    ->helperText('Placeholder: {nama}, {tenggat}, {periode}, {link}'),
                            ])
                            ->collapsible()
                            ->columns(1),

                        Section::make('Template WhatsApp')
                            ->schema([
                                Toggle::make('send_whatsapp')
                                    ->label('Aktifkan Notifikasi WhatsApp')
                                    ->default(true),
                                Textarea::make('whatsapp_template')
                                    ->label('Isi Pesan WhatsApp')
                                    ->rows(12)
                                    ->helperText('Placeholder: {nama}, {tenggat}, {periode}, {link}'),
                            ])
                            ->collapsible()
                            ->columns(1),
                    ])
                        ->columns(1)
                        ->columnSpan(1),
                ])
            ])
            ->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Judul Pengaturan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label('Tipe Pengingat')
                    ->formatStateUsing(fn ($state) => $state === 'pembuatan_kpi' ? 'Pembuatan KPI (Atasan)' : 'Pengisian KPI (Karyawan)')
                    ->badge()
                    ->color(fn ($state) => $state === 'pembuatan_kpi' ? 'warning' : 'info'),
                TextColumn::make('deadline_day')
                    ->label('Batas Akhir')
                    ->formatStateUsing(fn ($state) => "Tgl {$state} / Bulan")
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                IconColumn::make('send_email')
                    ->label('Email')
                    ->boolean(),
                IconColumn::make('send_whatsapp')
                    ->label('WhatsApp')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Status Aktif')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y, H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('test_send')
                    ->label('Uji Kirim (Test)')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Jalankan Pengingat KPI Sekarang?')
                    ->modalDescription('Proses ini akan mengecek user yang belum membuat/mengisi KPI dan memicu pengiriman pengingat saat ini.')
                    ->action(function (KpiReminderSetting $record) {
                        Artisan::call('kpi:send-reminders', [
                            '--setting-id' => $record->id,
                        ]);

                        Notification::make()
                            ->title('Proses Pengingat KPI Selesai')
                            ->body('Pengingat telah diproses. Hasil telah dicatat di log pengiriman.')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKpiReminderSettings::route('/'),
            'create' => CreateKpiReminderSetting::route('/create'),
            'edit' => EditKpiReminderSetting::route('/{record}/edit'),
        ];
    }
}
