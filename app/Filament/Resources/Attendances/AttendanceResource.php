<?php

namespace App\Filament\Resources\Attendances;

use App\Filament\Resources\Attendances\Pages\CreateAttendance;
use App\Filament\Resources\Attendances\Pages\EditAttendance;
use App\Filament\Resources\Attendances\Pages\ListAttendances;
use App\Models\Attendance;
use App\Models\User;
use App\Policies\AttendancePolicy;
use App\Services\DataAccessService;
use App\Services\PerformanceScoreCalculator;
use App\Services\ScoringRuleService;
use App\Support\Periode;
use App\Support\Scoring\AttendanceComponentData;
use App\Support\Scoring\AttendanceCountInput;
use App\Support\Scoring\AttendanceScore;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AttendanceResource extends Resource
{
    protected static ?string $model = Attendance::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-date-range';

    protected static ?string $navigationLabel = 'Kehadiran';

    protected static ?string $modelLabel = 'Kehadiran';

    protected static ?string $pluralModelLabel = 'Kehadiran';

    protected static ?int $navigationSort = 3;

    /**
     * Seluruh data kehadiran: super admin atau user khusus kehadiran (lihat AttendancePolicy).
     */
    public static function canAccessAllAttendance(?User $user = null): bool
    {
        return AttendancePolicy::canManageAllAttendance($user ?? auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('user_id')
                            ->preload()
                            ->searchable()
                            ->options(function () {
                                $query = User::query()
                                    ->whereNull('deleted_at')
                                    ->orderBy('nama_lengkap');

                                // Opsi karyawan = yang boleh dicatat kehadirannya oleh user (Select menolak nilai di luar opsi).
                                return AttendancePolicy::scopeWritableUsers($query, auth()->user())
                                    ->pluck('nama_lengkap', 'id');
                            })
                            ->label('Pengguna')
                            ->required()
                            ->columnSpan(2),

                        static::periodeInput('periode', 'Periode')
                            // Aturan penilaian dipilih dari periode data: tampilkan versi yang berlaku, dan isian
                            // komponen ikut berubah (aktif/nonaktif, label Sakit vs Sakit/Izin) tanpa menyimpan.
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Get $get, Set $set, ?Attendance $record): void {
                                static::resetUnusedComponentFields($get('periode'), $get, $set, $record);
                                // Konfirmasi pemisahan Sakit/Izin selalu dicentang ulang setelah periode diganti.
                                $set('confirm_sick_split', false);
                            })
                            ->helperText(fn (Get $get): ?string => static::ruleDescription($get('periode')))
                            ->columnSpan(2),

                        TextInput::make('work_days')
                            ->label('Jumlah Hari Kerja')
                            ->required()
                            ->maxLength(2)
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(AttendanceCountInput::MAX_WORK_DAYS)
                            ->placeholder('Masukkan jumlah hari kerja'),

                        // Satu field per komponen kehadiran (master data): komponen baru tidak butuh perubahan kode.
                        ...static::componentFields(),

                        static::sickIzinSplitConfirmation(),
                    ])->columns(4),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.nama_lengkap')
                    ->label('Nama Lengkap')
                    ->searchable(),
                TextColumn::make('user.employee_id')
                    ->label('ID Karyawan')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.area.name')
                    ->label('Area')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.divisi.name')
                    ->label('Divisi')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user.position.name')
                    ->label('Posisi')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('periode')
                    ->width('10%')
                    ->alignment(Alignment::Center),
                TextColumn::make('work_days')
                    ->width('10%')
                    ->alignment(Alignment::Center)
                    ->label('Hari Kerja')
                    ->numeric(),
                ...static::componentColumns(),
                TextColumn::make('attendance_score')
                    ->label('Skor Kehadiran')
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    // Dihitung dengan versi aturan periode baris itu (memo per periode, tanpa query per baris).
                    ->state(fn (Attendance $record): float => static::attendanceScore($record)->score)
                    ->numeric(decimalPlaces: 2)
                    ->tooltip(fn (Attendance $record): string => static::attendanceScoreTooltip($record)),
            ])
            ->defaultSort('created_at', 'desc')
            ->deferLoading()
            ->filters([
                SelectFilter::make('periode')
                    ->preload()
                    ->searchable()
                    ->options(function () {
                        // Periode hanya dari data kehadiran yang terlihat oleh user.
                        return static::getEloquentQuery()
                            ->distinct()
                            ->pluck('periode', 'periode')
                            ->toArray();
                    }),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Setiap baris dicek dengan AttendancePolicy::delete: admin per badan usaha melihat data
                    // akun ADMIN (termasuk dirinya) tetapi tidak boleh menghapusnya.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
                ]),
            ]);
    }

    /**
     * Isian periode 'YYYY-MM' (input bulan) yang dipakai form Kehadiran, modal Download Template, dan modal
     * Import: wajib, default bulan lalu (data kehadiran direkap untuk bulan yang sudah selesai), tahun 2000-2100.
     */
    public static function periodeInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->required()
            ->maxLength(7)
            ->default(fn (): string => Periode::previous())
            ->regex('/^\d{4}-\d{2}$/')
            ->validationMessages([
                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
            ])
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (($error = AttendanceCountInput::periodeError($value)) !== null) {
                        $fail($error);
                    }
                },
            ])
            ->extraInputAttributes(['type' => 'month']);
    }

    /**
     * Field hitungan per komponen yang bisa diisi (urut sort_order): komponen aktif ditambah komponen nonaktif
     * yang masih dinilai salah satu versi aturan (AttendanceCountInput::inputComponents()), agar data periode
     * versi itu tetap bisa diisi/dikoreksi. Komponen lama terikat ke kolomnya (late_less_30, late_more_30,
     * sick_days), komponen lain ke component_counts.{kode}.
     *
     * Field komponen yang tidak dipakai versi aturan periode di form (AttendanceCountInput::isUsedBy()) dinonaktifkan:
     * tidak ikut disimpan (hitungan tersimpan dipertahankan EditAttendance) dan berubah langsung saat periode
     * diganti. Label & keterangan Sakit/Izin mengikuti periode (Sakit vs Sakit/Izin).
     *
     * Pengecualian (edit): komponen yang tidak dipakai tetapi menyimpan hitungan > 0 (data lama, mis. izin pada
     * Oktober 2026) tetap bisa diisi agar HR bisa mengosongkannya, dengan hint "Tersimpan N, ...". Nilainya hanya
     * boleh 0, atau tetap sama dengan nilai tersimpan selama periode data tidak diganti (data lama tetap bisa
     * diubah bagian lainnya). Memindah data ke periode yang aturannya tidak memakai hitungan itu ditolak dengan
     * langkah perbaikannya (izin: jumlahkan ke Sakit/Izin lalu isi 0), agar penaltinya tidak hilang diam-diam.
     *
     * @return list<TextInput>
     */
    public static function componentFields(): array
    {
        return AttendanceCountInput::inputComponents()
            ->map(fn (AttendanceComponentData $component): TextInput => TextInput::make(static::componentStatePath($component->code))
                ->label(fn (Get $get): string => AttendanceCountInput::componentLabel($component, AttendanceCountInput::ruleFor($get('periode')))
                    ." ({$component->unit})".($component->isActive ? '' : ' - nonaktif'))
                ->helperText(fn (Get $get, ?Attendance $record): ?string => static::componentHelperText($component, $get('periode'), $record))
                ->hint(fn (Get $get, ?Attendance $record): ?string => static::componentRuleHint($component, $get('periode'), $record))
                ->disabled(fn (Get $get, ?Attendance $record): bool => ! static::isComponentUsedBy($component->code, $get('periode'))
                    && static::storedCount($record, $component->code) === 0)
                ->integer()
                ->minValue(0)
                // Batas sama dengan import & API: data yang diterima di sana tetap bisa disimpan ulang dari form.
                ->maxValue(AttendanceCountInput::MAX_COUNT)
                ->rules([
                    fn (Get $get, ?Attendance $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($component, $get, $record): void {
                        if (($error = static::unusedComponentError($component->code, $value, $get('periode'), $record)) !== null) {
                            $fail($error);
                        }
                    },
                ])
                ->validationMessages([
                    'integer' => 'Isi dengan bilangan bulat.',
                    'min' => 'Nilai minimal 0.',
                    'max' => 'Nilai maksimal '.AttendanceCountInput::MAX_COUNT.'.',
                ])
                ->default(0)
                ->placeholder('0')
                // Data lama tanpa hitungan komponen ini (component_counts null) tampil sebagai 0.
                ->afterStateHydrated(function (TextInput $field, mixed $state): void {
                    if (blank($state)) {
                        $field->state(0);
                    }
                })
                // Kolom hitungan NOT NULL: kosong disimpan sebagai 0.
                ->dehydrateStateUsing(fn (mixed $state): int => blank($state) ? 0 : (int) $state))
            ->values()
            ->all();
    }

    /**
     * Konfirmasi saat data yang menyimpan Sakit/Izin > 0 (aturan yang mencatat izin bersama sakit) dipindah ke
     * periode yang mencatat izin sendiri (mis. Oktober -> November 2026): tanpa dipisah, hari izin di Sakit/Izin
     * dinilai sebagai sakit dan penaltinya hilang diam-diam. HR mengisi Sakit (sakit saja) dan Izin, lalu
     * mencentang konfirmasi ini; tidak disimpan ke data.
     */
    public static function sickIzinSplitConfirmation(): Checkbox
    {
        return Checkbox::make('confirm_sick_split')
            ->label('Sakit/Izin tersimpan sudah saya pisah menjadi Sakit dan Izin')
            ->helperText(fn (Get $get, ?Attendance $record): ?string => static::sickIzinSplitMessage($record, $get('periode')))
            ->visible(fn (Get $get, ?Attendance $record): bool => static::sickIzinSplitMessage($record, $get('periode')) !== null)
            ->required()
            ->accepted()
            ->validationMessages([
                'accepted' => 'Centang konfirmasi ini setelah Sakit/Izin dipisah menjadi Sakit dan Izin.',
                'required' => 'Centang konfirmasi ini setelah Sakit/Izin dipisah menjadi Sakit dan Izin.',
            ])
            ->dehydrated(false)
            ->columnSpanFull();
    }

    /**
     * Langkah memisahkan Sakit/Izin tersimpan bila periode data di form dipindah ke periode yang mencatat izin
     * sendiri (AttendanceCountInput::movesIzinOutOfSick()); null bila tidak perlu (form tambah, Sakit/Izin 0, atau
     * periode tidak berpindah aturan).
     */
    public static function sickIzinSplitMessage(?Attendance $record, mixed $periode): ?string
    {
        $sickDays = static::storedCount($record, AttendanceCountInput::SICK_CODE);
        $normalized = AttendanceCountInput::periode($periode);

        if ($sickDays === 0 || $normalized === null || ! AttendanceCountInput::movesIzinOutOfSick($record?->periode, $normalized)) {
            return null;
        }

        return AttendanceCountInput::sickIzinSplitMessage(
            $sickDays,
            (string) Periode::normalize($record?->periode),
            $normalized,
            AttendanceCountInput::CONTEXT_FORM,
        );
    }

    public static function componentStatePath(string $code): string
    {
        return AttendanceCountInput::storageColumn($code) ?? "component_counts.{$code}";
    }

    /**
     * Kolom tabel per komponen yang bisa diisi (sama dengan field form): komponen lama tampil seperti
     * sebelumnya, komponen lain bisa ditampilkan lewat toggle kolom.
     *
     * @return list<TextColumn>
     */
    public static function componentColumns(): array
    {
        return AttendanceCountInput::inputComponents()
            ->map(function (AttendanceComponentData $component): TextColumn {
                $column = AttendanceCountInput::storageColumn($component->code);

                return TextColumn::make($column ?? "component_{$component->code}")
                    ->label($component->name.($component->isActive ? '' : ' (nonaktif)'))
                    ->width('10%')
                    ->wrapHeader()
                    ->alignment(Alignment::Center)
                    ->state(fn (Attendance $record): int => $record->countFor($component->code))
                    ->numeric()
                    // Kolom Sakit pada periode yang izinnya dicatat bersama sakit berisi sakit + izin.
                    ->headerTooltip(fn (): ?string => static::componentHeaderTooltip($component))
                    ->tooltip(fn (Attendance $record): ?string => static::componentCellTooltip($component, $record))
                    ->toggleable(isToggledHiddenByDefault: $column === null);
            })
            ->values()
            ->all();
    }

    /**
     * Skor kehadiran baris menurut versi aturan periodenya (bukan aturan bulan berjalan).
     */
    public static function attendanceScore(Attendance $record): AttendanceScore
    {
        return PerformanceScoreCalculator::attendanceFor($record, ScoringRuleService::forPeriode($record->periode));
    }

    public static function attendanceScoreTooltip(Attendance $record): string
    {
        $rule = ScoringRuleService::forPeriode($record->periode);
        $score = static::attendanceScore($record);

        if (! $score->hasWorkDays) {
            return "{$rule->name}: hari kerja 0, skor 0.";
        }

        return sprintf(
            '%s: dasar %s%% - penalti %s = %s%% x bobot %s',
            $rule->name,
            AttendanceCountInput::formatNumber($score->basePct),
            AttendanceCountInput::formatNumber($score->penaltyPoints),
            AttendanceCountInput::formatNumber($score->finalPct),
            AttendanceCountInput::formatNumber($score->weight),
        );
    }

    /**
     * Keterangan versi aturan yang berlaku untuk periode di form.
     */
    public static function ruleDescription(mixed $periode): ?string
    {
        $normalized = Periode::normalize($periode);

        if ($normalized === null) {
            return null;
        }

        $rule = ScoringRuleService::forPeriode($normalized);

        return "Aturan penilaian: {$rule->name} (berlaku sejak {$rule->effectiveFrom}, bobot kehadiran "
            .AttendanceCountInput::formatNumber($rule->attendanceWeight).'%).';
    }

    /**
     * Tooltip judul kolom Sakit bila ada versi aturan yang mencatat izin bersama sakit, mis. "Sebelum November 2026
     * kolom ini berisi sakit + izin (Sakit/Izin)."; null untuk kolom lain atau bila semua versi memakai Izin.
     */
    public static function componentHeaderTooltip(AttendanceComponentData $component): ?string
    {
        if ($component->code !== AttendanceCountInput::SICK_CODE) {
            return null;
        }

        $lastWithoutIzin = null;
        $firstWithIzin = null;

        foreach (ScoringRuleService::rules() as $rule) {
            if (AttendanceCountInput::recordsIzinInSick($rule)) {
                $lastWithoutIzin = $rule->effectiveFrom;
            } else {
                $firstWithIzin ??= $rule->effectiveFrom;
            }
        }

        if ($lastWithoutIzin === null) {
            return null;
        }

        $content = 'kolom ini berisi sakit + izin ('.AttendanceCountInput::SICK_IZIN_LABEL.').';

        if ($firstWithIzin === null) {
            return ucfirst($content);
        }

        return strcmp($lastWithoutIzin, $firstWithIzin) < 0
            ? 'Sebelum '.Periode::label($firstWithIzin).' '.$content
            : 'Pada periode yang aturannya belum memakai Izin, '.$content;
    }

    /**
     * Tooltip sel kolom komponen di tabel: kolom Sakit pada periode yang izinnya dicatat bersama sakit
     * (sebelum November 2026) berisi sakit + izin; selain itu tanpa tooltip.
     */
    public static function componentCellTooltip(AttendanceComponentData $component, Attendance $record): ?string
    {
        $periode = AttendanceCountInput::periode($record->periode);

        if ($component->code !== AttendanceCountInput::SICK_CODE || $periode === null
            || ! AttendanceCountInput::recordsIzinInSick(ScoringRuleService::forPeriode($periode))) {
            return null;
        }

        return AttendanceCountInput::SICK_IZIN_LABEL.': untuk periode '.Periode::label($periode).' izin dicatat bersama sakit.';
    }

    /**
     * Hint isian komponen: perlakuan komponen di versi aturan periode form, atau UNUSED_COMPONENT_HINT bila
     * versi itu tidak memakainya ("Tersimpan N, tidak dihitung untuk periode ini" bila data yang diubah masih
     * menyimpan hitungannya); null bila periode belum valid.
     */
    public static function componentRuleHint(AttendanceComponentData $component, mixed $periode, ?Attendance $record = null): ?string
    {
        $rule = AttendanceCountInput::ruleFor($periode);

        if ($rule === null) {
            return null;
        }

        if (AttendanceCountInput::isUsedBy($component->code, $rule)) {
            return AttendanceCountInput::ruleSummary($component, $rule);
        }

        $stored = static::storedCount($record, $component->code);

        return $stored > 0
            ? "Tersimpan {$stored}, tidak dihitung untuk periode ini"
            : AttendanceCountInput::UNUSED_COMPONENT_HINT;
    }

    /**
     * Keterangan isian komponen (AttendanceCountInput::componentHelperText()); komponen yang tidak dipakai tetapi
     * masih menyimpan hitungan > 0 diberi langkah mengosongkannya (izin: jumlahkan ke Sakit/Izin dulu).
     */
    public static function componentHelperText(AttendanceComponentData $component, mixed $periode, ?Attendance $record = null): ?string
    {
        $rule = AttendanceCountInput::ruleFor($periode);

        if ($rule !== null && ! AttendanceCountInput::isUsedBy($component->code, $rule) && static::storedCount($record, $component->code) > 0) {
            return $component->code === AttendanceCountInput::IZIN_CODE
                ? 'Untuk periode ini izin dicatat di '.AttendanceCountInput::SICK_IZIN_LABEL.': jumlahkan ke '.AttendanceCountInput::SICK_IZIN_LABEL.', lalu isi 0.'
                : 'Aturan periode ini tidak menghitung komponen ini: isi 0 untuk mengosongkannya.';
        }

        return AttendanceCountInput::componentHelperText($component, $rule);
    }

    /**
     * Hitungan tersimpan komponen pada data yang diubah; 0 pada form tambah.
     */
    public static function storedCount(?Attendance $record, string $code): int
    {
        return $record?->exists === true ? $record->countFor($code) : 0;
    }

    /**
     * Pesan bila isian komponen yang tidak dipakai versi aturan periode form bernilai > 0; null bila boleh disimpan.
     * Data yang periodenya tidak diganti boleh tetap menyimpan nilai lamanya (data lama tetap bisa diubah); selain
     * itu nilainya harus 0 (lihat componentFields()).
     */
    public static function unusedComponentError(string $code, mixed $value, mixed $periode, ?Attendance $record): ?string
    {
        $normalized = AttendanceCountInput::periode($periode);
        $stored = static::storedCount($record, $code);

        // Tanpa hitungan tersimpan, isian yang tidak dipakai aturan nonaktif dan tidak disimpan (Filament tetap
        // memvalidasi isian nonaktif): tidak perlu ditolak.
        if ($normalized === null || $stored === 0 || ! is_numeric($value) || (int) $value <= 0
            || AttendanceCountInput::isUsedBy($code, ScoringRuleService::forPeriode($normalized))) {
            return null;
        }

        if (AttendanceCountInput::periode($record?->periode) === $normalized && (int) $value === $stored) {
            return null;
        }

        return AttendanceCountInput::unusedComponentsMessage([$code], $normalized, AttendanceCountInput::CONTEXT_FORM);
    }

    /**
     * Isian komponen bisa diisi untuk periode form. Periode yang belum valid tidak menonaktifkan apa pun
     * (validasi periode yang menolak).
     */
    public static function isComponentUsedBy(string $code, mixed $periode): bool
    {
        $rule = AttendanceCountInput::ruleFor($periode);

        return $rule === null || AttendanceCountInput::isUsedBy($code, $rule);
    }

    /**
     * Setelah periode diganti, isian komponen yang menjadi nonaktif (tidak dipakai versi aturan periode baru dan
     * tidak menyimpan hitungan) kembali ke 0: isian nonaktif tidak disimpan, jadi angka yang sempat diketik tidak
     * boleh tetap tampil seolah-olah akan disimpan. Angka > 0 yang dikosongkan diberitahukan (izin: dijumlahkan
     * ke Sakit/Izin). Isian yang tetap aktif karena menyimpan hitungan tidak diubah (divalidasi saat simpan).
     */
    public static function resetUnusedComponentFields(mixed $periode, Get $get, Set $set, ?Attendance $record): void
    {
        $normalized = AttendanceCountInput::periode($periode);
        $cleared = [];

        foreach (AttendanceCountInput::inputComponents() as $code => $component) {
            if (static::isComponentUsedBy($code, $periode) || static::storedCount($record, $code) > 0) {
                continue;
            }

            $path = static::componentStatePath($code);
            $typed = $get($path);

            if (is_numeric($typed) && (int) $typed > 0) {
                $cleared[$code] = "{$component->name} ".((int) $typed)." {$component->unit}";
            }

            $set($path, 0);
        }

        if ($cleared === [] || $normalized === null) {
            return;
        }

        Notification::make()
            ->title('Isian komponen dikosongkan')
            ->body(AttendanceCountInput::joinList(array_values($cleared)).' dikosongkan. '
                .AttendanceCountInput::unusedComponentsSentence(array_keys($cleared), $normalized)
                .(isset($cleared[AttendanceCountInput::IZIN_CODE]) ? ' Untuk periode ini izin dijumlahkan ke '.AttendanceCountInput::SICK_IZIN_LABEL.'.' : ''))
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
            'index' => ListAttendances::route('/'),
            'create' => CreateAttendance::route('/create'),
            'edit' => EditAttendance::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = AttendancePolicy::scopeVisible(parent::getEloquentQuery(), $user);

        // Hak edit/hapus admin per badan usaha dicek per baris dari badan usaha & role pemilik:
        // muat sekaligus agar tidak ada query per baris.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            $query->with('user.role');
        }

        return $query;
    }
}
