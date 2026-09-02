<?php

namespace App\Filament\Resources\Kpis;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Actions;
use Filament\Actions\Action;
use Exception;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Kpis\Pages\ListKpis;
use App\Filament\Resources\Kpis\Pages\CreateKpi;
use App\Filament\Resources\Kpis\Pages\EditKpi;
use App\Filament\Resources\Kpis\Pages;
use App\Filament\Resources\Kpis\RelationManagers;
use App\Models\Kpi;
use App\Models\KpiCategory;
use App\Models\KpiDescription;
use App\Models\Position;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\KpiCacheService;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use App\Mail\KpiReminderMail;
use App\Services\WhatsAppService;
use App\Models\KpiReminderSetting;
use App\Models\KpiReminderLog;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class KpiResource extends Resource
{
    protected static ?string $model = Kpi::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-list-bullet';
    protected static ?string $navigationLabel = 'KPI';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        // Check if we're creating a new record or editing an existing one
        $isCreate = !$schema->getRecord();

        if ($isCreate) {
            return static::createForm($schema);
        } else {
            return static::editForm($schema);
        }
    }

    // Form for creating new KPIs (bulk approach)
    public static function createForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('KPI Information')
                    ->schema([
                        Select::make('position_id')
                            ->label('Job Position')
                            ->options(function () {
                                return KpiCacheService::getPositionsForUser();
                            })
                            ->searchable()
                            ->required(),

                        Hidden::make('kpi_type_id')
                            ->default(3),

                        DatePicker::make('date')
                            ->label('Month')
                            ->displayFormat('m/Y')
                            ->format('m/Y')
                            ->native(false)
                            ->required(),
                    ])->columns(2),

                Tabs::make('KPI Categories')
                    ->tabs([
                        Tab::make('MAIN JOB')
                            ->schema([
                                Hidden::make('kpi_category_id_main')
                                    ->default(3),

                                TextInput::make('percentageMain')
                                    ->label('Percentage %')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->placeholder('Enter percentage for Main Job'),

                                Repeater::make('kpi_details_main')
                            ->label('KPI Descriptions')
                            ->table([
                                        TableColumn::make('Deskripsi')
                                            ->markAsRequired(),
                                        TableColumn::make('start')
                                            ->width('15%'),
                                        TableColumn::make('end')
                                            ->width('15%'),
                                        TableColumn::make('Count Type')
                                            ->width('15%')
                                            ->markAsRequired(),
                                        TableColumn::make('Value Plan')
                                            ->width('10%'),
                                        TableColumn::make('Subtasks')
                                            ->width('10%'),
                                                                ])
                            ->schema([
                                        Select::make('kpi_description_id_main')
                                            ->label('KPI Description')
                                            ->searchable()
                                            ->options(function () {
                                                return KpiCacheService::getKpiDescriptionsByCategory(3);
                                            })
                                            ->createOptionForm([
                                                TextInput::make('description')
                                                    ->required(),
                                                Hidden::make('kpi_category_id')
                                                    ->default(3),
                                                Toggle::make('is_negative')
                                                    ->label('Lower is Better (Negative KPI)')
                                                    ->default(false),
                                            ])
                                            ->createOptionUsing(function (array $data) {
                                                $description = KpiDescription::create([
                                                    'description' => $data['description'],
                                                    'kpi_category_id' => $data['kpi_category_id'],
                                                    'is_negative' => $data['is_negative'] ?? false,
                                                ]);

                                                return $description->id;
                                            })
                                            ->required(),

                                        DatePicker::make('startMain')
                                            ->label('Start Date')
                                            ->native(false),

                                        DatePicker::make('endMain')
                                            ->label('End Date')
                                            ->native(false),

                                        Select::make('count_typeMain')
                                            ->label('Count Type')
                                            ->options([
                                                'NON' => 'NON',
                                                'RESULT' => 'RESULT'
                                            ])
                                            ->required()
                                            ->live(),

                                        TextInput::make('value_planMain')
                                            ->label('Value Plan')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(fn(Get $get) => $get('count_typeMain') === 'RESULT')
                                            ->disabled(fn(Get $get) => $get('count_typeMain') !== 'RESULT'),

                                        // Replace Forms\Components\Actions with direct Repeater
                                        Repeater::make('subtasks')
                                            ->label('Subtasks')
                                            ->schema([
                                                TextInput::make('description')
                                                    ->label('Subtask')
                                                    ->required(),
                                            ])
                                            ->columns(1)
                                            ->collapsed()
                                            ->collapsible()
                                            ->itemLabel(fn (array $state): ?string =>
                                                $state['description'] ?? 'New Subtask')
                                            ->defaultItems(0)
                                            ->addActionLabel('Add Subtask'),
                                    ])
                                    ->columnSpan('full')
                                    ->defaultItems(0)
                            ]),

                        Tab::make('ADMINISTRATION')
                            ->schema([
                                Hidden::make('kpi_category_id_adm')
                                    ->default(1),

                                TextInput::make('percentageAdm')
                                    ->label('Percentage %')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->placeholder('Enter percentage for Administration'),

                                Repeater::make('kpi_details_adm')
                            ->label('KPI Descriptions')
                            ->table([
                                        TableColumn::make('Deskripsi')
                                            ->markAsRequired(),
                                        TableColumn::make('start')
                                            ->width('15%'),
                                        TableColumn::make('end')
                                            ->width('15%'),
                                        TableColumn::make('Count Type')
                                            ->width('15%')
                                            ->markAsRequired(),
                                        TableColumn::make('Value Plan')
                                            ->width('10%'),
                                        TableColumn::make('Subtasks')
                                            ->width('10%'),
                                                                ])
                            ->schema([
                                        Select::make('kpi_description_id_adm')
                                            ->label('KPI Description')
                                            ->searchable()
                                            ->options(function () {
                                                return KpiCacheService::getKpiDescriptionsByCategory(1);
                                            })
                                            ->createOptionForm([
                                                TextInput::make('description')
                                                    ->required(),
                                                Hidden::make('kpi_category_id')
                                                    ->default(1),
                                                Toggle::make('is_negative')
                                                    ->label('Lower is Better (Negative KPI)')
                                                    ->default(false),
                                            ])
                                            ->createOptionUsing(function (array $data) {
                                                $description = KpiDescription::create([
                                                    'description' => $data['description'],
                                                    'kpi_category_id' => $data['kpi_category_id'],
                                                    'is_negative' => $data['is_negative'] ?? false,
                                                ]);

                                                return $description->id;
                                            })
                                            ->required(),

                                        DatePicker::make('start')
                                            ->label('Start Date')
                                            ->native(false),

                                        DatePicker::make('end')
                                            ->label('End Date')
                                            ->native(false),

                                        Select::make('count_type')
                                            ->label('Count Type')
                                            ->options([
                                                'NON' => 'NON',
                                                'RESULT' => 'RESULT'
                                            ])
                                            ->required()
                                            ->live(),

                                        TextInput::make('value_plan')
                                            ->label('Value Plan')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(fn(Get $get) => $get('count_type') === 'RESULT')
                                            ->disabled(fn(Get $get) => $get('count_type') !== 'RESULT'),

                                        // Replace Forms\Components\Actions with direct Repeater
                                        Repeater::make('subtasks')
                                            ->label('Subtasks')
                                            ->schema([
                                                TextInput::make('description')
                                                    ->label('Subtask')
                                                    ->required(),
                                            ])
                                            ->columns(1)
                                            ->collapsed()
                                            ->collapsible()
                                            ->itemLabel(fn (array $state): ?string =>
                                                $state['description'] ?? 'New Subtask')
                                            ->defaultItems(0)
                                            ->addActionLabel('Add Subtask'),
                                    ])
                                    ->columnSpan('full')
                                    ->defaultItems(0)
                            ]),

                        Tab::make('REPORTING')
                            ->schema([
                                Hidden::make('kpi_category_id_rep')
                                    ->default(2),

                                TextInput::make('percentageRep')
                                    ->label('Percentage %')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->placeholder('Enter percentage for Reporting'),

                                Repeater::make('kpi_details_rep')
                            ->label('KPI Descriptions')
                            ->table([
                                        TableColumn::make('Deskripsi')
                                            ->markAsRequired(),
                                        TableColumn::make('start')
                                            ->width('15%'),
                                        TableColumn::make('end')
                                            ->width('15%'),
                                        TableColumn::make('Count Type')
                                            ->width('15%')
                                            ->markAsRequired(),
                                        TableColumn::make('Value Plan')
                                            ->width('10%'),
                                        TableColumn::make('Subtasks')
                                            ->width('10%'),
                                                                ])
                            ->schema([
                                        Select::make('kpi_description_id_rep')
                                            ->label('KPI Description')
                                            ->searchable()
                                            ->options(function () {
                                                return KpiCacheService::getKpiDescriptionsByCategory(2);
                                            })
                                            ->createOptionForm([
                                                TextInput::make('description')
                                                    ->required(),
                                                Hidden::make('kpi_category_id')
                                                    ->default(2),
                                                Toggle::make('is_negative')
                                                    ->label('Lower is Better (Negative KPI)')
                                                    ->default(false),
                                            ])
                                            ->createOptionUsing(function (array $data) {
                                                $description = KpiDescription::create([
                                                    'description' => $data['description'],
                                                    'kpi_category_id' => $data['kpi_category_id'],
                                                    'is_negative' => $data['is_negative'] ?? false,
                                                ]);

                                                return $description->id;
                                            })
                                            ->required(),

                                        DatePicker::make('startRep')
                                            ->label('Start Date')
                                            ->native(false),

                                        DatePicker::make('endRep')
                                            ->label('End Date')
                                            ->native(false),

                                        Select::make('count_typeRep')
                                            ->label('Count Type')
                                            ->options([
                                                'NON' => 'NON',
                                                'RESULT' => 'RESULT'
                                            ])
                                            ->required()
                                            ->live(),

                                        TextInput::make('value_planRep')
                                            ->label('Value Plan')
                                            ->numeric()
                                            ->minValue(1)
                                            ->required(fn(Get $get) => $get('count_typeRep') === 'RESULT')
                                            ->disabled(fn(Get $get) => $get('count_typeRep') !== 'RESULT'),

                                        // Replace Forms\Components\Actions with direct Repeater
                                        Repeater::make('subtasks')
                                            ->label('Subtasks')
                                            ->schema([
                                                TextInput::make('description')
                                                    ->label('Subtask')
                                                    ->required(),
                                            ])
                                            ->columns(1)
                                            ->collapsed()
                                            ->collapsible()
                                            ->itemLabel(fn (array $state): ?string =>
                                                $state['description'] ?? 'New Subtask')
                                            ->defaultItems(0)
                                            ->addActionLabel('Add Subtask'),
                                    ])
                                    ->columnSpan('full')
                                    ->defaultItems(0)
                            ]),
                    ])
                    ->columnSpan('full'),
            ]);
    }

    // Form for editing existing KPIs (original form)
    public static function editForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('user_id')
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) =>
                                User::where('nama_lengkap', 'like', "%{$search}%")
                                    ->limit(50)
                                    ->pluck('nama_lengkap', 'id')
                            )
                            ->getOptionLabelUsing(fn ($value): ?string =>
                                User::find($value)?->nama_lengkap
                            )
                            ->required()
                            ->disabled(),
                        DatePicker::make('date')
                            ->label('Periode')
                            ->native(false)
                            ->displayFormat('m/Y')
                            ->required()
                            ->disabled(),
                    ])->columns('2'),

                Section::make()
                    ->schema([
                        Select::make('kpi_category_id')
                            ->label('KPI Category')
                            ->options(function () {
                                return KpiCacheService::getKpiCategories();
                            })
                            ->disabled(),
                        TextInput::make('percentage')
                            ->label('Percentage %')
                            ->required()
                            ->numeric()
                            ->placeholder('Enter Percentage'),

                        Repeater::make('kpi_detail')
                            ->relationship('kpi_detail')
                            ->table([
                                TableColumn::make('Deskripsi')
                                    ->markAsRequired(),
                                TableColumn::make('start')
                                    ->width('12%'),
                                TableColumn::make('end')
                                    ->width('12%'),
                                TableColumn::make('Count Type')
                                    ->width('12%')
                                    ->markAsRequired(),
                                TableColumn::make('Value Plan')
                                    ->width('10%'),
                                TableColumn::make('Subtasks')
                                    ->width('10%'),
                                                        ])
                            ->schema([
                                Select::make('kpi_description_id')
                                    ->label('KPI Description')
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search) =>
                                        KpiDescription::where('description', 'like', "%{$search}%")
                                            ->limit(50)
                                            ->pluck('description', 'id')
                                    )
                                    ->getOptionLabelUsing(fn ($value): ?string =>
                                        KpiDescription::find($value)?->description
                                    )
                                    ->createOptionForm([
                                        TextInput::make('description')
                                            ->required(),
                                        Hidden::make('kpi_category_id')
                                            ->default(fn(Get $get) => $schema->getRecord()?->kpi_category_id),
                                        Toggle::make('is_negative')
                                            ->label('Lower is Better (Negative KPI)')
                                            ->default(false),
                                    ])
                                    ->createOptionUsing(function (array $data) use ($schema) {
                                        $description = KpiDescription::create([
                                            'description' => $data['description'],
                                            'kpi_category_id' => $data['kpi_category_id'] ?? $schema->getRecord()?->kpi_category_id,
                                            'is_negative' => $data['is_negative'] ?? false,
                                        ]);

                                        return $description->id;
                                    })
                                    ->required(),
                                DatePicker::make('start')
                                    ->label('Start Date'),
                                DatePicker::make('end')
                                    ->label('End Date'),
                                Select::make('count_type')
                                    ->live()
                                    ->searchable()
                                    ->label('Count Type')
                                    ->options([
                                        'NON' => 'NON',
                                        'RESULT' => 'RESULT'
                                    ])
                                    ->required()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $set('value_plan', null);
                                    }),
                                TextInput::make('value_plan')
                                    ->numeric()
                                    ->label('Value Plan')
                                    ->minValue(1)
                                    ->required(fn(callable $get) => $get('count_type') === 'RESULT')
                                    ->disabled(fn(callable $get) => $get('count_type') !== 'RESULT'),
                                Actions::make([
                                            Action::make('manage_subtasks')
                                                ->hiddenLabel()
                                                ->icon('heroicon-o-clipboard-document-list')
                                                ->color('primary')
                                                ->size('sm')
                                                ->modalWidth('lg')
                                                ->modalHeading('Manage Subtasks')
                                                ->schema([
                                                    Hidden::make('kpi_detail_id'),
                                                    Repeater::make('subtasks')
                                                        ->schema([
                                                            TextInput::make('description')
                                                                ->label('Subtask')
                                                                ->required()
                                                                ->columnSpanFull(),
                                                        ])
                                                        ->columnSpanFull()
                                                        ->columns(1)
                                                        ->addActionLabel('Add Subtask')
                                                        ->itemLabel(fn (array $state): ?string =>
                                                            $state['description'] ?? 'New Subtask')
                                                        ->defaultItems(0)
                                                        ->reorderable()
                                                        ->lazy()
                                                ])
                                                ->fillForm(function ($record) {
                                                    $subtasks = [];

                                                    if (isset($record->subtasks)) {
                                                        if (is_string($record->subtasks)) {
                                                            try {
                                                                $decoded = json_decode($record->subtasks, true);
                                                                if (is_array($decoded)) {
                                                                    $subtasks = $decoded;
                                                                }
                                                            } catch (Exception $e) {
                                                                // If decoding fails, use empty array
                                                            }
                                                        } elseif (is_array($record->subtasks)) {
                                                            $subtasks = $record->subtasks;
                                                        }
                                                    }

                                                    return [
                                                        'kpi_detail_id' => $record->id,
                                                        'subtasks' => $subtasks,
                                                    ];
                                                })
                                                ->action(function (array $data, $record) {
                                                    $record->subtasks = $data['subtasks'] ?? [];
                                                    $record->save();
                                                }),
                                        ]),
                            ])
                            ->columnSpan('full'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }

    // Rest of the code remains the same
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.position.name')
                    ->searchable(),
                TextColumn::make('user.nama_lengkap')
                    ->searchable(),
                TextColumn::make('kpi_category.name')
                    ->label('Kategori'),
                TextColumn::make('kpi_type.name')
                    ->label('Type'),
                TextColumn::make('date')
                    ->label('Periode')
                    ->dateTime('F Y'),
                TextColumn::make('percentage')
                    ->label('Persentase')
                    ->alignment(Alignment::Center)
                    ->numeric()
                    ->formatStateUsing(fn($state) => "{$state}%"),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading()
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->filters([])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('send_reminder')
                    ->label('Kirim Pengingat')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->role?->name === 'ADMIN')
                    ->modalHeading(fn (Kpi $record) => "Kirim Pengingat Pengisian KPI ke " . ($record->user?->nama_lengkap ?? 'Karyawan'))
                    ->modalSubmitActionLabel('Kirim Pengingat')
                    ->form([
                        Select::make('setting_id')
                            ->label('Aturan Pengingat')
                            ->options(fn (): array => KpiReminderSetting::query()
                                ->where('type', 'pengisian_kpi')
                                ->where('is_active', true)
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Pilih aturan aktif yang menentukan template, tenggat, dan saluran pengiriman.'),
                        CheckboxList::make('channels')
                            ->label('Saluran Pengiriman')
                            ->options([
                                'email' => 'Email',
                                'whatsapp' => 'WhatsApp',
                            ])
                            ->default(['email', 'whatsapp'])
                            ->required()
                            ->helperText('Hanya saluran yang aktif pada pengaturan pengingat yang akan digunakan.'),
                        Textarea::make('custom_message')
                            ->label('Pesan Tambahan / Kustom (Opsional)')
                            ->placeholder('Masukkan pesan tambahan jika ada, atau biarkan kosong untuk menggunakan template standar.')
                            ->rows(4),
                    ])
                    ->action(function (Kpi $record, array $data) {
                        abort_unless(auth()->user()?->role?->name === 'ADMIN', 403);

                        $user = $record->user;
                        if (! $user) {
                            Notification::make()
                                ->title('User tidak ditemukan')
                                ->danger()
                                ->send();

                            return;
                        }

                        $setting = static::resolveActiveReminderSetting(
                            'pengisian_kpi',
                            (int) ($data['setting_id'] ?? 0),
                        );
                        if (! $setting) {
                            return;
                        }

                        $requestedChannels = is_array($data['channels'] ?? null)
                            ? array_values(array_intersect(['email', 'whatsapp'], $data['channels']))
                            : [];
                        $customMsg = trim((string) ($data['custom_message'] ?? ''));

                        $periodDate = Carbon::parse($record->date)->startOfMonth();
                        $tenggatDay = (int) $setting->deadline_day;
                        $deadlineDate = $periodDate->copy()->day(min($tenggatDay, $periodDate->daysInMonth));
                        $tenggatLabel = $deadlineDate->format('d M Y');
                        $periodeLabel = $periodDate->isoFormat('MMMM YYYY');
                        $link = config('app.url', 'http://localhost') . '/admin/kpis';

                        $placeholders = [
                            '{nama}' => $user->nama_lengkap,
                            '{tenggat}' => $tenggatLabel,
                            '{periode}' => $periodeLabel,
                            '{link}' => $link,
                        ];

                        $sentChannels = [];
                        $failedChannels = [];
                        $skippedChannels = [];

                        if (in_array('email', $requestedChannels, true)) {
                            if (! $setting->send_email) {
                                $skippedChannels[] = 'Email (dinonaktifkan pada pengaturan)';
                            } elseif (empty($user->email)) {
                                $skippedChannels[] = 'Email (alamat tidak tersedia)';
                            } else {
                                try {
                                    $subjectTemplate = filled($setting->email_subject)
                                        ? (string) $setting->email_subject
                                        : 'Pengingat Pengisian KPI - {periode}';
                                    $subject = strtr($subjectTemplate, $placeholders);
                                    $bodyTemplate = filled($setting->email_body)
                                        ? (string) $setting->email_body
                                        : KpiReminderSetting::getDefaultEmailTemplate('pengisian_kpi');
                                    $body = strtr($bodyTemplate, $placeholders);
                                    if ($customMsg !== '') {
                                        $body .= "\n\nPesan Tambahan:\n" . $customMsg;
                                    }

                                    Mail::to($user->email)->send(new KpiReminderMail($subject, $body));

                                    $sentChannels[] = 'Email';
                                    static::writeManualReminderLog(
                                        $setting,
                                        $user,
                                        'email',
                                        $user->email,
                                        'sent',
                                    );
                                } catch (\Throwable $exception) {
                                    $failedChannels[] = 'Email';
                                    static::writeManualReminderLog(
                                        $setting,
                                        $user,
                                        'email',
                                        $user->email,
                                        'failed',
                                        $exception->getMessage(),
                                    );
                                }
                            }
                        }

                        if (in_array('whatsapp', $requestedChannels, true)) {
                            if (! $setting->send_whatsapp) {
                                $skippedChannels[] = 'WhatsApp (dinonaktifkan pada pengaturan)';
                            } elseif (empty($user->no_hp)) {
                                $skippedChannels[] = 'WhatsApp (No. HP tidak tersedia)';
                            } else {
                                $waTemplate = filled($setting->whatsapp_template)
                                    ? (string) $setting->whatsapp_template
                                    : KpiReminderSetting::getDefaultWhatsappTemplate('pengisian_kpi');
                                $waMessage = strtr($waTemplate, $placeholders);
                                if ($customMsg !== '') {
                                    $waMessage .= "\n\n*Pesan Tambahan:*\n" . $customMsg;
                                }

                                try {
                                    $result = WhatsAppService::send($user->no_hp, $waMessage);
                                } catch (\Throwable $exception) {
                                    $result = [
                                        'success' => false,
                                        'message' => $exception->getMessage(),
                                    ];
                                }

                                if ($result['success']) {
                                    $sentChannels[] = 'WhatsApp';
                                } else {
                                    $failedChannels[] = 'WhatsApp';
                                }

                                static::writeManualReminderLog(
                                    $setting,
                                    $user,
                                    'whatsapp',
                                    $user->no_hp,
                                    $result['success'] ? 'sent' : 'failed',
                                    $result['success'] ? null : ($result['message'] ?? 'Pengiriman WhatsApp gagal.'),
                                );
                            }
                        }

                        static::notifyManualReminderResult(
                            $user,
                            $sentChannels,
                            $failedChannels,
                            $skippedChannels,
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function resolveActiveReminderSetting(
        string $type,
        int $settingId,
    ): ?KpiReminderSetting
    {
        $setting = KpiReminderSetting::query()
            ->whereKey($settingId)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if ($setting) {
            return $setting;
        }

        Notification::make()
            ->title('Aturan Pengingat Tidak Valid')
            ->body('Aturan Pengisian KPI yang dipilih sudah tidak aktif atau tidak tersedia. Pilih ulang aturan pengingat.')
            ->danger()
            ->send();

        return null;
    }

    protected static function writeManualReminderLog(
        KpiReminderSetting $setting,
        User $user,
        string $channel,
        string $recipient,
        string $status,
        ?string $errorMessage = null,
    ): void {
        try {
            KpiReminderLog::create([
                'kpi_reminder_setting_id' => $setting->id,
                'user_id' => $user->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'status' => $status,
                'error_message' => $errorMessage,
                'sent_at' => Carbon::now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    protected static function notifyManualReminderResult(
        User $user,
        array $sentChannels,
        array $failedChannels,
        array $skippedChannels,
    ): void {
        $sentLabel = implode(', ', $sentChannels);
        $problemLabels = [
            ...array_map(fn (string $channel): string => "{$channel} gagal", $failedChannels),
            ...$skippedChannels,
        ];
        $problemLabel = implode(', ', $problemLabels);

        if (($sentChannels !== []) && ($problemLabels !== [])) {
            Notification::make()
                ->title("Pengingat Terkirim Sebagian ke {$user->nama_lengkap}")
                ->body("Berhasil: {$sentLabel}. Tidak terkirim: {$problemLabel}.")
                ->warning()
                ->send();

            return;
        }

        if ($sentChannels !== []) {
            Notification::make()
                ->title("Pengingat Berhasil Terkirim ke {$user->nama_lengkap}")
                ->body("Saluran: {$sentLabel}.")
                ->success()
                ->send();

            return;
        }

        if ($failedChannels !== []) {
            Notification::make()
                ->title("Gagal Mengirim Pengingat ke {$user->nama_lengkap}")
                ->body("Tidak terkirim: {$problemLabel}.")
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Pengingat Tidak Dikirim')
            ->body($problemLabel !== ''
                ? "Tidak ada saluran yang dapat digunakan: {$problemLabel}."
                : 'Pilih setidaknya satu saluran pengiriman.')
            ->warning()
            ->send();
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
            'index' => ListKpis::route('/'),
            'create' => CreateKpi::route('/create'),
            'edit' => EditKpi::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'user.position',
                'kpi_category',
                'kpi_type',
                'kpi_detail.kpi_description'
            ]);

        $user = Auth::user();
        $role = $user->role?->name;

        if ($role === 'ADMIN') {
            return $query;
        }

        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) Auth::id());
        if ($managedUserIds === []) {
            return $query->whereIn('user_id', []);
        }

        return $query->whereIn('user_id', array_merge([Auth::id()], $managedUserIds));
    }
}
