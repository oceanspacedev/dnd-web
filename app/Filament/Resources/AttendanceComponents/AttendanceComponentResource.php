<?php

namespace App\Filament\Resources\AttendanceComponents;

use App\Filament\Resources\AttendanceComponents\Pages\ManageAttendanceComponents;
use App\Models\AttendanceComponent;
use App\Services\ScoringRuleService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use WeakMap;

class AttendanceComponentResource extends Resource
{
    protected static ?string $model = AttendanceComponent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Komponen Kehadiran';

    protected static ?string $modelLabel = 'Komponen Kehadiran';

    protected static ?string $pluralModelLabel = 'Komponen Kehadiran';

    protected static ?string $recordTitleAttribute = 'name';

    /** Judul record hanya untuk modal/notifikasi; panel tidak memakai pencarian global. */
    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 8;

    /**
     * Hasil AttendanceComponentPolicy::delete per instance record (dan user) selama satu request: tombol hapus
     * mengevaluasi otorisasi beberapa kali per baris (tampil, nonaktif, tooltip), sedangkan cek pemakaian di
     * data kehadiran memindai attendances.component_counts.
     *
     * @var WeakMap<AttendanceComponent, array<string, Response>>|null
     */
    private static ?WeakMap $deleteResponses = null;

    /**
     * Master komponen kehadiran berlaku lintas badan usaha: khusus super admin (lihat AttendanceComponentPolicy).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->helperText('Huruf kecil, angka, dan garis bawah; diawali huruf (mis. izin_khusus). Tidak bisa diubah setelah dibuat karena dipakai data kehadiran dan aturan penilaian.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(50)
                    ->regex(AttendanceComponent::CODE_PATTERN)
                    ->unique(AttendanceComponent::class, 'code', ignoreRecord: true)
                    ->validationMessages([
                        'regex' => 'Kode hanya boleh huruf kecil, angka, dan garis bawah (diawali huruf, 2-50 karakter).',
                        'unique' => 'Kode komponen ini sudah dipakai komponen lain.',
                    ])
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->label('Nama Komponen')
                    ->placeholder('Contoh: Izin khusus')
                    ->helperText('Nama tampil di form dan tabel kehadiran, laporan, dan judul kolom template/export Excel. Setelah mengganti nama, unduh ulang template agar judul kolomnya ikut berubah.')
                    ->required()
                    ->maxLength(255),
                Select::make('unit')
                    ->label('Satuan')
                    ->options([
                        AttendanceComponent::UNIT_DAY => 'Hari',
                        AttendanceComponent::UNIT_OCCURRENCE => 'Kejadian',
                    ])
                    ->default(AttendanceComponent::UNIT_DAY)
                    ->helperText(fn (?AttendanceComponent $record): string => $record !== null && static::isInUse($record)
                        ? 'Satuan tidak bisa diubah karena komponen sudah dipakai versi aturan atau data kehadiran (versi lama akan ikut tampil dengan satuan baru). Buat komponen baru bila satuannya berbeda.'
                        : 'Penalti pada aturan penilaian dihitung per satuan ini.')
                    // Satuan komponen yang sudah dipakai tetap: aturan dan hitungan lama dicatat dengan satuan itu.
                    ->disabled(fn (?AttendanceComponent $record): bool => $record !== null && static::isInUse($record))
                    ->selectablePlaceholder(false)
                    ->required(),
                Textarea::make('description')
                    ->label('Keterangan')
                    ->rows(3),
                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(65535)
                    ->default(fn (): int => ((int) AttendanceComponent::query()->max('sort_order')) + 1)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->helperText(fn (?AttendanceComponent $record): string => static::deactivationBlockedReason($record)
                        ?? 'Komponen nonaktif tidak ditawarkan lagi di versi aturan baru (juga saat menduplikat versi yang memakainya) dan dapat disembunyikan dari isian kehadiran. Versi aturan dan hitungan yang sudah tersimpan tidak berubah.')
                    // Komponen yang masih dinilai versi aturan bulan lalu/berjalan/terjadwal tidak bisa dinonaktifkan.
                    ->disabled(fn (?AttendanceComponent $record): bool => static::deactivationBlockedReason($record) !== null)
                    ->default(true),
            ])
            ->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama Komponen')
                    ->description(fn (AttendanceComponent $record): ?string => $record->description)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('code')
                    ->label('Kode')
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('unit')
                    ->label('Satuan')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('source')
                    ->label('Sumber Data')
                    ->state(fn (AttendanceComponent $record): string => $record->legacy_column !== null
                        ? 'Kolom bawaan ('.$record->legacy_column.')'
                        : 'Hitungan komponen')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('used_by_rules')
                    ->label('Dipakai Versi Aturan')
                    ->state(fn (AttendanceComponent $record): array => static::rulesUsing((string) $record->code))
                    ->placeholder('Belum dipakai')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status Aktif'),
            ])
            ->recordActions([
                EditAction::make()
                    ->slideOver()
                    ->modalWidth('lg')
                    ->using(fn (AttendanceComponent $record, array $data, EditAction $action): Model => self::saveWithFieldErrors(
                        function () use ($record, $data): AttendanceComponent {
                            $locked = ['code', 'legacy_column'];

                            if (static::isInUse($record)) {
                                $locked[] = 'unit';
                            }

                            if (static::deactivationBlockedReason($record) !== null) {
                                $locked[] = 'is_active';
                            }

                            $record->update(Arr::except($data, $locked));

                            return $record;
                        },
                        $action,
                    )),
                Action::make('deactivate')
                    ->label('Nonaktifkan')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn (AttendanceComponent $record): bool => (bool) $record->is_active)
                    // Ditolak (tombol nonaktif + alasan) selama komponen masih dinilai versi aturan bulan lalu,
                    // bulan berjalan, atau terjadwal (AttendanceComponentPolicy::deactivate).
                    ->authorize('deactivate')
                    ->authorizationTooltip()
                    ->requiresConfirmation()
                    ->modalHeading('Nonaktifkan Komponen?')
                    ->modalDescription('Komponen nonaktif tidak ditawarkan lagi di versi aturan baru (juga saat menduplikat versi yang memakainya) dan dapat disembunyikan dari isian kehadiran. Versi aturan dan hitungan yang sudah tersimpan tidak berubah. Aktifkan lagi bila masih dibutuhkan.')
                    ->action(fn (AttendanceComponent $record) => self::setActive($record, false)),
                Action::make('activate')
                    ->label('Aktifkan')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn (AttendanceComponent $record): bool => ! $record->is_active)
                    ->authorize('update')
                    ->action(fn (AttendanceComponent $record) => self::setActive($record, true)),
                DeleteAction::make()
                    ->authorize(fn (AttendanceComponent $record): Response => static::deleteResponse($record))
                    ->authorizationTooltip()
                    ->modalDescription('Komponen yang belum pernah dipakai aturan penilaian maupun data kehadiran akan dihapus permanen.')
                    ->using(function (AttendanceComponent $record): bool {
                        try {
                            return (bool) $record->delete();
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->title('Komponen tidak dapat dihapus')
                                ->body(self::firstMessage($exception))
                                ->danger()
                                ->send();

                            return false;
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAttendanceComponents::route('/'),
        ];
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Tambah Komponen')
            ->slideOver()
            ->modalWidth('lg')
            ->createAnother(false)
            ->using(fn (array $data, CreateAction $action): Model => self::saveWithFieldErrors(
                fn (): AttendanceComponent => AttendanceComponent::query()->create(Arr::except($data, ['legacy_column'])),
                $action,
            ));
    }

    /**
     * Nama versi aturan yang memakai komponen ini (dari cache aturan, tanpa query per baris).
     *
     * @return list<string>
     */
    public static function rulesUsing(string $code): array
    {
        $names = [];

        foreach (ScoringRuleService::rules() as $rule) {
            if (! $rule->isFallback() && $rule->hasComponent($code)) {
                $names[] = $rule->name.' ('.$rule->effectiveFrom.')';
            }
        }

        return $names;
    }

    /**
     * AttendanceComponentPolicy::delete untuk user yang login, sekali per instance record dalam satu request.
     */
    public static function deleteResponse(AttendanceComponent $record): Response
    {
        self::$deleteResponses ??= new WeakMap;
        $user = (string) auth()->id();
        $responses = self::$deleteResponses[$record] ?? [];

        if (! isset($responses[$user])) {
            $responses[$user] = Gate::inspect('delete', $record);
            self::$deleteResponses[$record] = $responses;
        }

        return $responses[$user];
    }

    /**
     * Komponen bawaan, atau sudah dipakai versi aturan / data kehadiran (sama dengan alasan tidak bisa dihapus).
     */
    public static function isInUse(AttendanceComponent $record): bool
    {
        return $record->exists && static::deleteResponse($record)->denied();
    }

    /**
     * Alasan komponen aktif belum bisa dinonaktifkan (AttendanceComponentPolicy::deactivate); null bila boleh
     * atau komponen sudah nonaktif/baru.
     */
    public static function deactivationBlockedReason(?AttendanceComponent $record): ?string
    {
        if ($record === null || ! $record->exists || ! $record->getOriginal('is_active')) {
            return null;
        }

        $response = Gate::inspect('deactivate', $record);

        return $response->allowed() ? null : ($response->message() ?? 'Komponen belum bisa dinonaktifkan.');
    }

    /**
     * ValidationException dari penjaga model AttendanceComponent ditampilkan pada field modal yang sesuai.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    private static function saveWithFieldErrors(Closure $callback, Action $action): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $statePath = 'mountedActions.'.array_key_last($action->getLivewire()->mountedActions).'.data';
            $messages = [];

            foreach ($exception->errors() as $key => $keyMessages) {
                $messages["{$statePath}.{$key}"] = $keyMessages;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    private static function setActive(AttendanceComponent $record, bool $active): void
    {
        $blocked = $active ? null : static::deactivationBlockedReason($record);

        if ($blocked !== null) {
            Notification::make()
                ->title('Komponen belum bisa dinonaktifkan')
                ->body($blocked)
                ->danger()
                ->send();

            return;
        }

        $record->update(['is_active' => $active]);

        Notification::make()
            ->title($active ? 'Komponen diaktifkan' : 'Komponen dinonaktifkan')
            ->success()
            ->send();
    }

    private static function firstMessage(ValidationException $exception): string
    {
        $first = Arr::first(Arr::flatten($exception->errors()));

        return is_string($first) ? $first : $exception->getMessage();
    }
}
