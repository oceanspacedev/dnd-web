<?php

namespace App\Filament\Resources\ScoringRules;

use App\Filament\Resources\ScoringRules\Pages\CreateScoringRule;
use App\Filament\Resources\ScoringRules\Pages\EditScoringRule;
use App\Filament\Resources\ScoringRules\Pages\ListScoringRules;
use App\Filament\Resources\ScoringRules\Pages\ViewScoringRule;
use App\Models\ScoringRule;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\ScoringRuleData;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class ScoringRuleResource extends Resource
{
    protected static ?string $model = ScoringRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Aturan Penilaian';

    protected static ?string $modelLabel = 'Aturan Penilaian';

    protected static ?string $pluralModelLabel = 'Aturan Penilaian';

    protected static ?string $recordTitleAttribute = 'name';

    /** Judul record hanya untuk breadcrumb/modal; panel tidak memakai pencarian global. */
    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 7;

    /**
     * Poin penilaian tertinggi yang bisa dicapai: 4 aspek (responsiveness, problem_solver, helpfulness,
     * initiative) × nilai tertinggi 5. Poin maksimal di bawah ini membuat skor Penilaian melebihi bobotnya.
     */
    public const REVIEW_REACHABLE_POINTS = 20;

    /** Metrik hasil simulasi => label. */
    private const SIMULATION_METRICS = [
        'base_pct' => 'Rumus dasar',
        'penalty' => 'Penalti',
        'final_pct' => 'Pencapaian akhir',
        'attendance_score' => 'Skor Kehadiran',
        'review_score' => 'Skor Penilaian',
        'kpi_score' => 'Skor KPI',
        'total' => 'Total',
    ];

    private const WEIGHT_FIELDS = [
        'kpi_weight' => 'Bobot KPI',
        'attendance_weight' => 'Bobot Kehadiran',
        'review_weight' => 'Bobot Penilaian',
    ];

    /**
     * Aturan penilaian berlaku lintas badan usaha: khusus super admin (lihat ScoringRulePolicy).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Versi Aturan')
                    ->description('Versi berlaku mulai bulan yang dipilih sampai ada versi berikutnya. Bulan sebelumnya tetap memakai versinya sendiri.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Versi')
                            ->placeholder('Contoh: Aturan Kehadiran Januari 2027')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('effective_from')
                            ->label('Berlaku Mulai')
                            ->type('month')
                            ->required()
                            ->regex('/^\d{4}-(0[1-9]|1[0-2])$/')
                            ->extraInputAttributes(fn (): array => ['min' => Periode::current()])
                            ->unique(ScoringRule::class, 'effective_from', ignoreRecord: true)
                            ->validationMessages([
                                'regex' => 'Periode berlaku harus berformat YYYY-MM.',
                                'unique' => 'Sudah ada versi aturan yang berlaku mulai bulan ini.',
                            ])
                            ->rules([
                                fn (?ScoringRule $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                    if ($record?->isLocked() || ! is_string($value) || ! Periode::isValid($value)) {
                                        return;
                                    }

                                    if (strcmp($value, Periode::current()) < 0) {
                                        $fail('Versi aturan hanya boleh berlaku mulai bulan berjalan ('.ScoringRulePresenter::periodeLabel(Periode::current()).') atau sesudahnya. Bulan yang sudah lewat tetap memakai versinya sendiri.');
                                    }
                                },
                            ])
                            ->disabled(fn (?ScoringRule $record): bool => (bool) $record?->isLocked())
                            ->helperText(fn (?ScoringRule $record): string => $record?->isLocked()
                                ? 'Versi ini terkunci karena sudah dipakai bulan yang telah berakhir. Buat versi baru untuk mengubah aturan.'
                                : 'Paling awal bulan berjalan ('.ScoringRulePresenter::periodeLabel(Periode::current()).'). Versi terkunci setelah bulan pertamanya berakhir.'),
                        Textarea::make('notes')
                            ->label('Catatan')
                            ->placeholder('Contoh: dasar keputusan, ringkasan perubahan dari versi sebelumnya.')
                            ->helperText('Catatan internal: hanya terlihat oleh super admin, tidak dikirim ke aplikasi mobile.')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Bobot Nilai Akhir')
                    ->description('Nilai akhir = skor KPI + skor Kehadiran + skor Penilaian. Total bobot harus 100.')
                    ->schema([
                        self::weightInput('kpi_weight')
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    $total = self::weightTotal([$value, $get('attendance_weight'), $get('review_weight')]);

                                    if ($total !== null && abs($total - 100) > 0.001) {
                                        $fail('Total bobot KPI, Kehadiran, dan Penilaian harus 100 (saat ini '.ScoringRulePresenter::number($total).').');
                                    }
                                },
                            ]),
                        self::weightInput('attendance_weight'),
                        self::weightInput('review_weight'),
                        TextEntry::make('weight_total')
                            ->label('Total Bobot')
                            ->state(fn (Get $get): string => ScoringRulePresenter::number(self::weightTotal([$get('kpi_weight'), $get('attendance_weight'), $get('review_weight')], lenient: true) ?? 0).' / 100')
                            ->badge()
                            ->color(fn (Get $get): string => abs((self::weightTotal([$get('kpi_weight'), $get('attendance_weight'), $get('review_weight')], lenient: true) ?? 0) - 100) > 0.001 ? 'danger' : 'success'),
                        TextInput::make('review_max_points')
                            ->label('Poin Maksimal Penilaian')
                            ->helperText('Skor Penilaian = total poin penilaian / poin maksimal × bobot Penilaian. Paling kecil '.self::REVIEW_REACHABLE_POINTS.' (4 aspek × nilai tertinggi 5) agar skor Penilaian tidak melebihi bobotnya.')
                            ->integer()
                            ->minValue(self::REVIEW_REACHABLE_POINTS)
                            ->maxValue(65535)
                            ->validationMessages([
                                'min' => 'Poin maksimal penilaian tidak boleh lebih kecil dari poin tertinggi yang bisa dicapai ('.self::REVIEW_REACHABLE_POINTS.' = 4 aspek × nilai 5); skor Penilaian akan melebihi bobotnya.',
                            ])
                            ->required()
                            ->live(debounce: 500)
                            ->columnSpan(2),
                    ])
                    ->columns(4),

                Section::make('Aturan Kehadiran')
                    ->description('Rumus dasar = (hari kerja − jumlah komponen yang mengurangi rumus dasar) / hari kerja × 100. Pencapaian akhir = rumus dasar − Σ (jumlah × penalti), minimal 0. Skor Kehadiran = pencapaian akhir / 100 × bobot Kehadiran.')
                    ->schema([
                        Repeater::make('attendance_rules')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Komponen'),
                                TableColumn::make('Kurangi rumus dasar')
                                    ->alignment(Alignment::Center)
                                    ->width('20%'),
                                TableColumn::make('Penalti per hari/kejadian')
                                    ->width('30%'),
                            ])
                            ->schema([
                                Hidden::make('component')
                                    ->required(),
                                TextEntry::make('component_label')
                                    ->hiddenLabel()
                                    ->state(fn (Get $get): string => self::componentLabel($get('component'))),
                                Toggle::make('reduces_base')
                                    ->hiddenLabel()
                                    ->live(),
                                TextInput::make('penalty_points')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->step(0.01)
                                    ->required()
                                    ->suffix(fn (Get $get): string => 'poin/'.(ScoringRuleService::component((string) $get('component'))->unit ?? 'hari'))
                                    ->live(debounce: 500),
                            ])
                            ->default(fn (): array => static::attendanceRuleRows(self::latestRule()->attendanceRules ?? [], keepInactiveComponents: false))
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ]),

                Section::make('Simulasi')
                    ->description('Coba isian di atas sebelum disimpan dan bandingkan dengan versi yang berlaku bulan ini. Angka simulasi tidak disimpan.')
                    ->hiddenOn('view')
                    ->collapsible()
                    ->schema([
                        Group::make(fn (): array => self::simulationInputs())
                            ->statePath('simulation')
                            ->dehydrated(false)
                            ->validatedWhenNotDehydrated(false)
                            ->columns(4),
                        Grid::make(2)
                            ->schema([
                                Fieldset::make('Versi ini (belum disimpan)')
                                    ->schema(self::simulationEntries('draft'))
                                    ->columns(1)
                                    ->columnSpan(1),
                                Fieldset::make(fn (): string => 'Berlaku bulan ini: '.static::currentRule()->name)
                                    ->schema(self::simulationEntries('current'))
                                    ->columns(1)
                                    ->columnSpan(1),
                            ]),
                    ]),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('effective_from')
                    ->label('Berlaku Mulai')
                    ->formatStateUsing(fn (?string $state): string => ScoringRulePresenter::periodeLabel($state))
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Nama Versi')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (ScoringRule $record): string => ScoringRulePresenter::status((string) $record->effective_from))
                    ->formatStateUsing(fn (string $state): string => ScoringRulePresenter::statusLabel($state))
                    ->color(fn (string $state): string => ScoringRulePresenter::statusColor($state))
                    ->tooltip(fn (ScoringRule $record): string => $record->isLocked()
                        ? 'Terkunci: hanya nama dan catatan yang bisa diubah. Buat versi baru untuk mengubah aturan.'
                        : 'Belum terkunci: masih bisa diubah atau dihapus sampai bulan pertamanya berakhir.')
                    ->badge(),
                TextColumn::make('weights')
                    ->label('Bobot')
                    ->state(fn (ScoringRule $record): string => ScoringRulePresenter::weightsSummary(ScoringRuleData::fromModel($record)))
                    ->description(fn (ScoringRule $record): string => 'Poin maks. penilaian '.$record->review_max_points),
                TextColumn::make('components')
                    ->label('Aturan Kehadiran')
                    ->state(fn (ScoringRule $record): array => ScoringRulePresenter::componentSummaries(ScoringRuleData::fromModel($record)))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->limitList(3)
                    ->expandableLimitedList(),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y, H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('effective_from', 'desc')
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (ScoringRule $record): bool => ! static::canEdit($record)),
                EditAction::make(),
                ActionGroup::make([
                    static::editDetailsAction(),
                    static::duplicateAction(),
                    static::deleteAction(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScoringRules::route('/'),
            'create' => CreateScoringRule::route('/create'),
            'view' => ViewScoringRule::route('/{record}'),
            'edit' => EditScoringRule::route('/{record}/edit'),
        ];
    }

    /**
     * "Duplikat jadi versi baru": buka form buat versi dengan isian dari versi ini dan bulan berlaku berikutnya.
     */
    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label('Duplikat jadi versi baru')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->authorize(fn (): bool => static::canCreate())
            ->url(fn (ScoringRule $record): string => static::getUrl('create', ['duplicate' => $record->getKey()]));
    }

    /**
     * Versi terkunci: hanya nama dan catatan yang boleh diubah (ScoringRule::EDITABLE_WHEN_LOCKED).
     */
    public static function editDetailsAction(): Action
    {
        return Action::make('editDetails')
            ->label('Ubah nama & catatan')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (ScoringRule $record): bool => $record->isLocked())
            ->authorize('updateDetails')
            ->modalHeading('Ubah Nama & Catatan Versi')
            ->modalDescription('Versi ini sudah terkunci: parameter penilaian tidak bisa diubah. Buat versi baru untuk mengubah aturan.')
            ->modalWidth('lg')
            ->fillForm(fn (ScoringRule $record): array => [
                'name' => $record->name,
                'notes' => $record->notes,
            ])
            ->schema([
                TextInput::make('name')
                    ->label('Nama Versi')
                    ->required()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->helperText('Catatan internal: hanya terlihat oleh super admin, tidak dikirim ke aplikasi mobile.')
                    ->rows(3),
            ])
            ->action(function (ScoringRule $record, array $data, Action $action): void {
                $record->fill(Arr::only($data, ['name', 'notes']));

                static::saveWithFieldErrors(fn () => $record->save(), 'mountedActions.'.array_key_last($action->getLivewire()->mountedActions).'.data');

                Notification::make()
                    ->title('Nama dan catatan versi disimpan')
                    ->success()
                    ->send();
            })
            ->after(function ($livewire): void {
                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['name', 'notes']);
                }
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->using(function (ScoringRule $record): bool {
                try {
                    return (bool) $record->delete();
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Versi aturan tidak dapat dihapus')
                        ->body(self::firstMessage($exception))
                        ->danger()
                        ->send();

                    return false;
                }
            });
    }

    /**
     * Isian form dari versi yang tersimpan.
     *
     * @param  bool  $offerActiveComponents  tawarkan komponen aktif yang belum ada di versi ini (form ubah/duplikat)
     * @param  bool  $keepInactiveComponents  pertahankan komponen nonaktif yang ada di versi ini (false untuk versi baru)
     * @return array<string, mixed>
     */
    public static function formData(ScoringRule $rule, bool $offerActiveComponents = true, bool $keepInactiveComponents = true): array
    {
        $data = ScoringRuleData::fromModel($rule);
        $rows = static::attendanceRuleRows($data->attendanceRules, $offerActiveComponents, $keepInactiveComponents);

        return [
            'name' => $rule->name,
            'effective_from' => $rule->effective_from,
            'notes' => $rule->notes,
            'kpi_weight' => $data->kpiWeight,
            'attendance_weight' => $data->attendanceWeight,
            'review_weight' => $data->reviewWeight,
            'review_max_points' => $data->reviewMaxPoints,
            'attendance_rules' => $rows,
            'simulation' => ScoringRuleSimulator::defaultInputs(self::simulationComponentCodes()),
        ];
    }

    /**
     * Versi sumber isian form buat versi: $duplicateOf ("Duplikat jadi versi baru"), atau versi terakhir.
     */
    public static function newVersionSource(?ScoringRule $duplicateOf = null): ?ScoringRule
    {
        return $duplicateOf ?? ScoringRule::query()->orderByDesc('effective_from')->first();
    }

    /**
     * Isian awal form buat versi: salinan $duplicateOf ("Duplikat jadi versi baru"), atau parameter versi
     * terakhir bila dibuat dari tombol Buat. Bulan berlaku = bulan kosong pertama sesudah bulan berjalan.
     * Komponen yang sudah dinonaktifkan tidak ikut disalin (lihat inactiveComponentsLeftOut()).
     *
     * @return array<string, mixed>
     */
    public static function newVersionFormData(?ScoringRule $duplicateOf = null): array
    {
        $source = static::newVersionSource($duplicateOf);
        $data = $source ? static::formData($source, keepInactiveComponents: false) : [
            'kpi_weight' => ScoringRuleData::legacy()->kpiWeight,
            'attendance_weight' => ScoringRuleData::legacy()->attendanceWeight,
            'review_weight' => ScoringRuleData::legacy()->reviewWeight,
            'review_max_points' => ScoringRuleData::legacy()->reviewMaxPoints,
            'attendance_rules' => static::attendanceRuleRows(ScoringRuleData::legacy()->attendanceRules, keepInactiveComponents: false),
            'simulation' => ScoringRuleSimulator::defaultInputs(self::simulationComponentCodes()),
        ];

        return [
            ...$data,
            'name' => $duplicateOf ? 'Salinan dari '.$duplicateOf->name : null,
            'effective_from' => static::nextAvailablePeriode(),
            'notes' => $duplicateOf ? 'Diduplikat dari versi '.ScoringRulePresenter::periodeLabel((string) $duplicateOf->effective_from).'.' : null,
        ];
    }

    /**
     * Komponen versi sumber yang tidak ikut ke versi baru karena sudah nonaktif (atau tidak ada lagi di master),
     * mis. 'Mangkir: 5 poin/hari'.
     *
     * @return list<string>
     */
    public static function inactiveComponentsLeftOut(?ScoringRule $source): array
    {
        if ($source === null) {
            return [];
        }

        $rule = ScoringRuleData::fromModel($source);
        $leftOut = [];

        foreach ($rule->componentCodes() as $code) {
            if (! (ScoringRuleService::component($code)->isActive ?? false)) {
                $leftOut[] = ScoringRulePresenter::componentSummary($rule, $code);
            }
        }

        return $leftOut;
    }

    /**
     * Bulan pertama sesudah bulan berjalan yang belum punya versi aturan.
     */
    public static function nextAvailablePeriode(): string
    {
        $candidate = ScoringRulePresenter::nextPeriode(Periode::current());
        $taken = array_flip(ScoringRule::query()
            ->where('effective_from', '>=', $candidate)
            ->pluck('effective_from')
            ->map(fn ($value): string => (string) $value)
            ->all());

        while (isset($taken[$candidate])) {
            $candidate = ScoringRulePresenter::nextPeriode($candidate);
        }

        return $candidate;
    }

    /**
     * Baris tabel aturan kehadiran: satu per komponen (urut sort_order). Komponen yang ada di versi ini ikut
     * dengan nilainya (yang sudah nonaktif hanya bila $keepInactiveComponents, yaitu saat melihat/mengubah
     * versi itu sendiri); komponen aktif lain ditawarkan dengan nilai 0.
     *
     * @param  array<string, array{reduces_base: bool, penalty_points: float}>  $existing
     * @return list<array{component: string, reduces_base: bool, penalty_points: int|float}>
     */
    public static function attendanceRuleRows(array $existing, bool $offerActiveComponents = true, bool $keepInactiveComponents = true): array
    {
        $components = ScoringRuleService::components();
        $rows = [];

        foreach ($components as $code => $component) {
            if (isset($existing[$code]) && ($keepInactiveComponents || $component->isActive)) {
                $rows[] = self::attendanceRuleRow($code, $existing[$code]);
            } elseif ($offerActiveComponents && $component->isActive) {
                $rows[] = self::attendanceRuleRow($code, ['reduces_base' => false, 'penalty_points' => 0.0]);
            }
        }

        if ($keepInactiveComponents) {
            foreach ($existing as $code => $parameters) {
                if (! $components->has($code)) {
                    $rows[] = self::attendanceRuleRow($code, $parameters);
                }
            }
        }

        return $rows;
    }

    /**
     * Data form siap disimpan: attendance_rules jadi daftar {component, reduces_base, penalty_points}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataForSave(array $data): array
    {
        unset($data['simulation']);

        if (array_key_exists('attendance_rules', $data)) {
            $data['attendance_rules'] = array_values(array_map(
                static fn (mixed $row): mixed => is_array($row) ? [
                    'component' => $row['component'] ?? null,
                    'reduces_base' => (bool) ($row['reduces_base'] ?? false),
                    'penalty_points' => $row['penalty_points'] ?? null,
                ] : $row,
                (array) $data['attendance_rules'],
            ));
        }

        return $data;
    }

    /**
     * Jalankan penyimpanan; ValidationException dari penjaga model (ScoringRule::saving) ditampilkan pada
     * field form yang sesuai ({statePath}.{kolom}) dan sebagai notifikasi.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    public static function saveWithFieldErrors(Closure $callback, string $statePath = 'data'): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Aturan penilaian belum disimpan')
                ->body(self::firstMessage($exception))
                ->danger()
                ->send();

            $messages = [];

            foreach ($exception->errors() as $key => $keyMessages) {
                $messages["{$statePath}.{$key}"] = $keyMessages;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Versi aturan yang berlaku bulan ini (pembanding simulasi).
     */
    public static function currentRule(): ScoringRuleData
    {
        return ScoringRuleService::forPeriode(Periode::current());
    }

    /**
     * Hasil simulasi dari state form saat ini untuk sisi 'draft' (isian form) atau 'current' (versi berlaku).
     *
     * @return array<string, mixed>
     */
    public static function simulationResult(Get $get, string $side): array
    {
        $inputs = $get('simulation');
        $inputs = is_array($inputs) ? $inputs : [];

        $rule = $side === 'current'
            ? static::currentRule()
            : ScoringRuleSimulator::draftRule([
                'name' => $get('name'),
                'effective_from' => $get('effective_from'),
                'kpi_weight' => $get('kpi_weight'),
                'attendance_weight' => $get('attendance_weight'),
                'review_weight' => $get('review_weight'),
                'review_max_points' => $get('review_max_points'),
                'attendance_rules' => $get('attendance_rules'),
            ]);

        return ScoringRuleSimulator::run($inputs, $rule);
    }

    private static function weightInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->label(self::WEIGHT_FIELDS[$name])
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->step(0.01)
            ->rule('decimal:0,2')
            ->validationMessages(['decimal' => 'Bobot maksimal 2 angka di belakang koma.'])
            ->suffix('%')
            ->required()
            ->live(debounce: 500);
    }

    /**
     * Jumlah bobot (dibulatkan 2 desimal seperti kolom decimal(6,2)); null bila ada isian bukan angka
     * (kecuali $lenient: isian kosong dianggap 0 untuk tampilan total).
     *
     * @param  array<int, mixed>  $weights
     */
    private static function weightTotal(array $weights, bool $lenient = false): ?float
    {
        $total = 0.0;

        foreach ($weights as $weight) {
            if (! is_numeric($weight)) {
                if ($lenient) {
                    continue;
                }

                return null;
            }

            $total += round((float) $weight, 2);
        }

        return $total;
    }

    /**
     * Isian simulasi: hari kerja, jumlah per komponen, total poin penilaian, nilai KPI mentah.
     *
     * @return array<int, TextInput>
     */
    private static function simulationInputs(): array
    {
        $inputs = [
            TextInput::make('work_days')
                ->label('Hari kerja')
                ->numeric()
                ->minValue(0)
                ->default(ScoringRuleSimulator::DEFAULT_WORK_DAYS)
                ->live(debounce: 400),
        ];

        foreach (self::simulationComponentCodes() as $code) {
            $component = ScoringRuleService::component($code);
            $inputs[] = TextInput::make("counts.{$code}")
                ->label(($component->name ?? $code).' · jumlah '.($component->unit ?? 'hari'))
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->live(debounce: 400);
        }

        $inputs[] = TextInput::make('review_points')
            ->label('Total poin penilaian')
            ->numeric()
            ->minValue(0)
            ->default(ScoringRuleSimulator::DEFAULT_REVIEW_POINTS)
            ->live(debounce: 400);
        $inputs[] = TextInput::make('kpi_raw')
            ->label('Nilai KPI mentah')
            ->numeric()
            ->minValue(0)
            ->suffix('%')
            ->default(ScoringRuleSimulator::DEFAULT_KPI_RAW)
            ->live(debounce: 400);

        return $inputs;
    }

    /**
     * @return array<int, TextEntry>
     */
    private static function simulationEntries(string $side): array
    {
        $entries = [];

        foreach (self::SIMULATION_METRICS as $metric => $label) {
            $entries[] = TextEntry::make("simulation_{$side}_{$metric}")
                ->label($label)
                ->inlineLabel()
                ->state(fn (Get $get): string => ScoringRuleSimulator::format(static::simulationResult($get, $side), $metric))
                ->color(fn (Get $get): ?string => ScoringRuleSimulator::exceedsLimit(static::simulationResult($get, $side), $metric) ? 'danger' : null)
                ->weight($metric === 'total' ? 'bold' : null);
        }

        return $entries;
    }

    /**
     * Komponen yang bisa diisi di simulasi: komponen aktif dan komponen yang dipakai versi aturan mana pun
     * (termasuk versi yang berlaku bulan ini dan versi yang sedang diduplikat), urut sort_order.
     *
     * @return list<string>
     */
    private static function simulationComponentCodes(): array
    {
        $used = [];

        foreach (ScoringRuleService::rules() as $rule) {
            $used += $rule->attendanceRules;
        }

        return ScoringRuleService::components()
            ->filter(fn (AttendanceComponentData $component, string $code): bool => $component->isActive || isset($used[$code]))
            ->keys()
            ->values()
            ->all();
    }

    private static function componentLabel(mixed $code): string
    {
        $component = is_string($code) ? ScoringRuleService::component($code) : null;

        if ($component === null) {
            return is_scalar($code) ? (string) $code : '-';
        }

        return $component->name.' · per '.$component->unit.($component->isActive ? '' : ' (nonaktif)');
    }

    private static function latestRule(): ?ScoringRuleData
    {
        $rules = ScoringRuleService::rules();

        return $rules === [] ? null : $rules[array_key_last($rules)];
    }

    /**
     * @param  array{reduces_base: bool, penalty_points: float}  $parameters
     * @return array{component: string, reduces_base: bool, penalty_points: int|float}
     */
    private static function attendanceRuleRow(string $code, array $parameters): array
    {
        $penalty = $parameters['penalty_points'];

        return [
            'component' => $code,
            'reduces_base' => $parameters['reduces_base'],
            // 5.0 => 5 agar tampil "5" di form.
            'penalty_points' => floor($penalty) === $penalty ? (int) $penalty : $penalty,
        ];
    }

    private static function firstMessage(ValidationException $exception): string
    {
        $first = Arr::first(Arr::flatten($exception->errors()));

        return is_string($first) ? $first : $exception->getMessage();
    }
}
