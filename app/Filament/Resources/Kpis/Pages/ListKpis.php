<?php

namespace App\Filament\Resources\Kpis\Pages;

use App\Exports\KpiMonthlyExport;
use App\Exports\KpiPerDivisionExport;
use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Divisi;
use App\Models\Kpi;
use App\Models\Position;
use App\Models\User;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Facades\Excel;

class ListKpis extends ListRecords
{
    protected static string $resource = KpiResource::class;

    protected static ?string $title = 'KPI';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ActionGroup::make([
                Action::make('export')
                    ->label('Export KPI')
                    ->icon('heroicon-s-arrow-down-tray')
                    ->schema([
                        Select::make('periode')
                            ->label('Periode')
                            ->options([
                                'year' => 'Per Tahun',
                                'month' => 'Per Bulan',
                            ])
                            ->default('year')
                            ->required()
                            ->live(),
                        Select::make('export_by')
                            ->label('Tipe Export')
                            ->options(function (): array {
                                if (DataAccessService::isAdmin(auth()->user())) {
                                    return [
                                        'division' => 'Per Divisi',
                                        'user' => 'Per User',
                                    ];
                                }

                                return [
                                    'user' => 'Per User',
                                ];
                            })
                            ->default(fn () => DataAccessService::isAdmin(auth()->user()) ? 'division' : 'user')
                            ->required()
                            ->live(),
                        Select::make('divisi')
                            ->label('Divisi')
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => static::exportDivisionOptions())
                            ->visible(fn ($get) => $get('export_by') === 'division' && DataAccessService::isAdmin(auth()->user())),
                        Select::make('user_id')
                            ->label('User')
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => static::exportUserOptions())
                            ->visible(fn ($get) => $get('export_by') === 'user' && static::canChooseOtherUsers()),
                        TextInput::make('tahun')
                            ->label('Tahun')
                            ->maxLength(4)
                            ->default(fn () => now()->format('Y'))
                            ->regex('/^\d{4}$/')
                            ->validationMessages([
                                'regex' => 'Format yang valid adalah tahun (YYYY).',
                            ])
                            ->visible(fn ($get) => $get('periode') === 'year')
                            ->required(fn ($get) => $get('periode') === 'year'),
                        TextInput::make('bulan')
                            ->label('Bulan')
                            ->maxLength(7)
                            ->default(fn () => now()->format('Y-m'))
                            ->regex('/^\d{4}-\d{2}$/')
                            ->validationMessages([
                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                            ])
                            ->extraInputAttributes(['type' => 'month'])
                            ->visible(fn ($get) => $get('periode') === 'month')
                            ->required(fn ($get) => $get('periode') === 'month'),
                    ])
                    ->slideOver()
                    ->modalWidth('md')
                    ->modalHeading('Export KPI')
                    ->modalButton('Export')
                    ->action(function (array $data) {
                        $actor = auth()->user();
                        $isYear = ($data['periode'] ?? 'year') === 'year';
                        $exportByUser = ($data['export_by'] ?? 'division') === 'user';
                        $userId = $exportByUser && ! empty($data['user_id']) ? (int) $data['user_id'] : null;
                        $user = $userId ? User::where('id', $userId)->first() : null;
                        $isAdmin = DataAccessService::isAdmin($actor);
                        $hasGlobalAccess = DataAccessService::hasGlobalAccess($actor);
                        $managedUserIds = $isAdmin
                            ? []
                            : ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());

                        // Batas karyawan yang boleh masuk file export. null = tanpa batas (super admin).
                        $allowedUserIds = static::exportAllowedUserIds();

                        if (! $isAdmin && ! $exportByUser) {
                            Notification::make()
                                ->title('Tipe export tidak valid')
                                ->body('Non-admin hanya dapat export per user dalam scope approval_id (bawahan langsung + satu level).')
                                ->warning()
                                ->send();

                            return;
                        }

                        if ($exportByUser && ! $isAdmin && $userId) {
                            $isAllowedUser = in_array($userId, $managedUserIds, true);

                            if (! $isAllowedUser) {
                                Notification::make()
                                    ->title('User tidak valid untuk export')
                                    ->body('Anda hanya dapat export KPI user dalam scope approval_id (bawahan langsung + satu level).')
                                    ->warning()
                                    ->send();

                                return;
                            }
                        }

                        // Admin badan usaha tertentu: user harus terlihat (badan usaha yang diizinkan + tim + diri sendiri).
                        if ($exportByUser && $isAdmin && ! $hasGlobalAccess && $userId && ! in_array($userId, $allowedUserIds ?? [], true)) {
                            Notification::make()
                                ->title('User tidak valid untuk export')
                                ->body('Anda hanya dapat export KPI karyawan di badan usaha yang Anda kelola atau tim Anda.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $requestedDivisiId = $exportByUser ? null : ($data['divisi'] ?? null);

                        if (filled($requestedDivisiId) && ! $hasGlobalAccess && ! array_key_exists((int) $requestedDivisiId, static::exportDivisionOptions())) {
                            Notification::make()
                                ->title('Divisi tidak valid untuk export')
                                ->body('Anda hanya dapat export divisi yang berisi karyawan di badan usaha yang Anda kelola.')
                                ->warning()
                                ->send();

                            return;
                        }

                        $divisiId = filled($requestedDivisiId) ? $requestedDivisiId : ($user?->divisi_id ?? $actor->divisi_id);
                        $divisi = Divisi::where('id', $divisiId)->first();
                        $divisionName = $user && $user->divisi ? $user->divisi->name : ($divisi->name ?? $actor->divisi?->name ?? 'Semua');
                        $fileSuffix = $user ? '_'.$user->nama_lengkap : '';

                        if ($isYear) {
                            $year = $data['tahun'];
                            $filename = 'KPI_'.$divisionName.'_'.$year.$fileSuffix.'.xlsx';

                            return Excel::download(
                                new KpiMonthlyExport($year, (string) $divisiId, $userId, $allowedUserIds),
                                $filename
                            );
                        }

                        $month = $data['bulan'];
                        $filename = 'KPI_'.$divisionName.'_'.$month.$fileSuffix.'.xlsx';

                        return Excel::download(
                            new KpiPerDivisionExport($month, (string) $divisiId, $userId, $allowedUserIds),
                            $filename
                        );
                    }),
                Action::make('copy')
                    ->label('Copy KPI')
                    ->icon('heroicon-o-document-duplicate')
                    ->schema([
                        Grid::make(['default' => 1])
                            ->schema([
                                Radio::make('copy_mode')
                                    ->label('Pilih Mode Copy')
                                    ->options([
                                        'position' => 'Berdasarkan Posisi',
                                        'individual' => 'Berdasarkan User Individual',
                                    ])
                                    ->default('position')
                                    ->inline()
                                    ->required()
                                    ->live(),

                                Select::make('position')
                                    ->label('Posisi')
                                    ->searchable()
                                    ->preload()
                                    ->options(function (): array {
                                        $options = [];
                                        $actor = auth()->user();

                                        if (DataAccessService::hasGlobalAccess($actor)) {
                                            $positions = Position::with('user')->get();
                                        } elseif (DataAccessService::isAreaScopedAdmin($actor)) {
                                            // Hanya karyawan yang terlihat oleh admin badan usaha tertentu.
                                            $positions = Position::with([
                                                'user' => function ($q) use ($actor) {
                                                    DataAccessService::scopeOwnedRecords($q->getQuery(), $actor, 'users.id');
                                                },
                                            ])
                                                ->whereHas('user', function ($q) use ($actor) {
                                                    DataAccessService::scopeOwnedRecords($q, $actor, 'users.id');
                                                })
                                                ->get();
                                        } else {
                                            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
                                            if ($managedUserIds === []) {
                                                return [];
                                            }

                                            $positions = Position::with([
                                                'user' => function ($q) use ($managedUserIds) {
                                                    $q->whereIn('id', $managedUserIds);
                                                },
                                            ])
                                                ->whereHas('user', function ($q) use ($managedUserIds) {
                                                    $q->whereIn('id', $managedUserIds);
                                                })
                                                ->get();
                                        }

                                        foreach ($positions as $position) {
                                            $userNames = collect($position->user)->map(function ($user) {
                                                return $user->nama_lengkap;
                                            })->implode(', ');

                                            $options[$position->id] = $position->name.' - '.$userNames;
                                        }

                                        return $options;
                                    })
                                    ->visible(fn ($get) => $get('copy_mode') === 'position')
                                    ->required(fn ($get) => $get('copy_mode') === 'position'),

                                Select::make('source_users')
                                    ->label('Copy dari User')
                                    ->searchable()
                                    ->preload()
                                    ->options(fn (): array => static::copyUserOptions())
                                    ->visible(fn ($get) => $get('copy_mode') === 'individual')
                                    ->required(fn ($get) => $get('copy_mode') === 'individual'),

                                Select::make('target_users')
                                    ->label('Copy ke User')
                                    ->searchable()
                                    ->multiple()
                                    ->preload()
                                    ->options(fn (): array => static::copyUserOptions())
                                    ->visible(fn ($get) => $get('copy_mode') === 'individual')
                                    ->required(fn ($get) => $get('copy_mode') === 'individual'),

                                Grid::make(['default' => 2])
                                    ->schema([
                                        TextInput::make('tanggal1')
                                            ->label('Dari Bulan')
                                            ->maxLength(7)
                                            ->default(fn () => now()->format('Y-m'))
                                            ->regex('/^\d{4}-\d{2}$/')
                                            ->validationMessages([
                                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                                            ])
                                            ->extraInputAttributes(['type' => 'month'])
                                            ->required(),
                                        TextInput::make('tanggal2')
                                            ->label('Ke Bulan')
                                            ->maxLength(7)
                                            ->default(fn () => now()->addMonthNoOverflow()->format('Y-m'))
                                            ->regex('/^\d{4}-\d{2}$/')
                                            ->validationMessages([
                                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                                            ])
                                            ->extraInputAttributes(['type' => 'month'])
                                            ->required(),
                                    ]),
                            ]),
                    ])
                    ->slideOver()
                    ->modalWidth('md')
                    ->modalHeading('Copy KPI')
                    ->action(function (array $data) {
                        try {
                            // '!' resets the day to 1; plain 'Y-m' keeps today's day and rolls over on the 29th-31st.
                            $fromDate = Date::createFromFormat('!Y-m', $data['tanggal1']);
                            $toDate = Date::createFromFormat('!Y-m', $data['tanggal2']);
                            $copyMode = $data['copy_mode'];
                            $actor = auth()->user();
                            // Hanya super admin yang tidak dibatasi; id dari form selalu divalidasi ulang di sini.
                            $hasGlobalAccess = DataAccessService::hasGlobalAccess($actor);
                            $allowedUserIds = [];

                            if (DataAccessService::isAreaScopedAdmin($actor)) {
                                $allowedUserIds = DataAccessService::visibleUserIds($actor) ?? [];
                            } elseif (! $hasGlobalAccess) {
                                $allowedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
                            }

                            $copiedCount = 0;
                            $skippedCount = 0;

                            if ($copyMode === 'position') {
                                // Copy berdasarkan posisi (logika lama)
                                $usersQuery = User::where('position_id', $data['position']);
                                if (! $hasGlobalAccess) {
                                    $usersQuery->whereIn('id', $allowedUserIds);
                                }
                                $users = $usersQuery->pluck('id');

                                $kpis = Kpi::whereMonth('date', $fromDate->month)
                                    ->whereYear('date', $fromDate->year)
                                    ->whereIn('user_id', $users)
                                    ->get();

                                // Get existing KPIs for the target month to avoid duplicates
                                $existingKpis = Kpi::whereMonth('date', $toDate->month)
                                    ->whereYear('date', $toDate->year)
                                    ->whereIn('user_id', $users)
                                    ->pluck('user_id')
                                    ->toArray();

                                foreach ($kpis as $kpi) {
                                    // Skip if KPI already exists for this user in target month
                                    if (in_array($kpi->user_id, $existingKpis)) {
                                        $skippedCount++;

                                        continue;
                                    }

                                    $newKpi = $kpi->replicate();
                                    $newKpi->date = $toDate;
                                    $newKpi->save();

                                    foreach ($kpi->kpi_detail as $kpiDetail) {
                                        $newKpiDetail = $kpiDetail->replicate();
                                        $newKpiDetail->kpi_id = $newKpi->id;
                                        $newKpiDetail->start = null;
                                        $newKpiDetail->end = null;
                                        $newKpiDetail->value_actual = null;
                                        $newKpiDetail->value_result = 0;
                                        $newKpiDetail->save();
                                    }

                                    $copiedCount++;
                                }
                            } else {
                                // Copy berdasarkan user individual (logika baru)
                                $sourceUsers = collect(filled($data['source_users'] ?? null) ? [$data['source_users']] : [])
                                    ->map(static fn ($id) => (int) $id);
                                $targetUsers = collect($data['target_users'] ?? [])
                                    ->map(static fn ($id) => (int) $id);

                                if (! $hasGlobalAccess) {
                                    $allowedSet = array_fill_keys($allowedUserIds, true);
                                    $sourceUsers = $sourceUsers->filter(static fn ($id) => isset($allowedSet[$id]));
                                    $targetUsers = $targetUsers->filter(static fn ($id) => isset($allowedSet[$id]));
                                }

                                $sourceUsers = $sourceUsers->unique()->values()->all();
                                $targetUsers = $targetUsers->unique()->values()->all();

                                if ($sourceUsers === [] || $targetUsers === []) {
                                    Notification::make()
                                        ->title('User tidak valid untuk copy KPI')
                                        ->body('Pastikan source dan target user berada dalam cakupan Anda (badan usaha yang dikelola atau bawahan langsung + satu level).')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                // Get KPIs from source users
                                $kpis = Kpi::whereMonth('date', $fromDate->month)
                                    ->whereYear('date', $fromDate->year)
                                    ->whereIn('user_id', $sourceUsers)
                                    ->get();

                                // Get existing KPIs for target users in target month to avoid duplicates
                                $existingKpis = Kpi::whereMonth('date', $toDate->month)
                                    ->whereYear('date', $toDate->year)
                                    ->whereIn('user_id', $targetUsers)
                                    ->pluck('user_id')
                                    ->toArray();

                                foreach ($kpis as $kpi) {
                                    foreach ($targetUsers as $targetUserId) {
                                        // Skip if KPI already exists for this target user in target month
                                        if (in_array($targetUserId, $existingKpis)) {
                                            $skippedCount++;

                                            continue;
                                        }

                                        $newKpi = $kpi->replicate();
                                        $newKpi->user_id = $targetUserId;
                                        $newKpi->date = $toDate;
                                        $newKpi->save();

                                        foreach ($kpi->kpi_detail as $kpiDetail) {
                                            $newKpiDetail = $kpiDetail->replicate();
                                            $newKpiDetail->kpi_id = $newKpi->id;
                                            $newKpiDetail->start = null;
                                            $newKpiDetail->end = null;
                                            $newKpiDetail->value_actual = null;
                                            $newKpiDetail->value_result = 0;
                                            $newKpiDetail->save();
                                        }

                                        $copiedCount++;
                                    }
                                }
                            }

                            $message = "KPI copied successfully. {$copiedCount} KPI(s) copied";
                            if ($skippedCount > 0) {
                                $message .= ", {$skippedCount} KPI(s) skipped (already exists)";
                            }

                            Notification::make()
                                ->title($message)
                                ->success()
                                ->send();

                            return to_route('filament.admin.resources.kpis.index');
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('Error copying KPI')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
                ->label('Copy/Export')
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->button(),
        ];
    }

    /**
     * Opsi user untuk export: super admin semua (kecuali id 1); admin badan usaha tertentu
     * hanya karyawan yang terlihat; non-admin bawahan langsung + satu level.
     *
     * @return array<int, string>
     */
    protected static function exportUserOptions(): array
    {
        $actor = auth()->user();

        if (DataAccessService::hasGlobalAccess($actor)) {
            return User::where('id', '<>', 1)
                ->orderBy('nama_lengkap')
                ->pluck('nama_lengkap', 'id')
                ->toArray();
        }

        if (DataAccessService::isAreaScopedAdmin($actor)) {
            return static::visibleUsersQuery()
                ->orderBy('nama_lengkap')
                ->pluck('nama_lengkap', 'id')
                ->toArray();
        }

        $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
        if ($managedUserIds === []) {
            return [];
        }

        return User::whereIn('id', $managedUserIds)
            ->orderBy('nama_lengkap')
            ->pluck('nama_lengkap', 'id')
            ->toArray();
    }

    /**
     * Opsi divisi untuk export per divisi. Admin badan usaha tertentu hanya melihat divisi yang berisi
     * karyawan yang terlihat olehnya (berdasarkan users.divisi_id, bukan divisis.area_id).
     *
     * @return array<int, string>
     */
    protected static function exportDivisionOptions(): array
    {
        $actor = auth()->user();

        if (DataAccessService::hasGlobalAccess($actor)) {
            return Divisi::pluck('name', 'id')->toArray();
        }

        if (! DataAccessService::isAreaScopedAdmin($actor)) {
            return [];
        }

        return Divisi::query()
            ->whereIn('id', static::visibleUsersQuery()->whereNotNull('divisi_id')->select('divisi_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /**
     * Batas user di dalam file export. null = tanpa batas (super admin); admin badan usaha tertentu =
     * badan usaha yang diizinkan + tim + diri sendiri; non-admin = diri sendiri + bawahan.
     *
     * @return array<int>|null
     */
    protected static function exportAllowedUserIds(): ?array
    {
        return DataAccessService::visibleUserIds(auth()->user());
    }

    /**
     * Pilihan user tampil untuk admin, atasan (termasuk siapa pun yang punya bawahan), dan role selain STAFF
     * seperti sebelumnya; opsi dan validasi tetap dibatasi cakupan.
     */
    protected static function canChooseOtherUsers(): bool
    {
        $actor = auth()->user();

        return DataAccessService::isAdmin($actor)
            || DataAccessService::isTeamSupervisor($actor)
            || $actor?->role?->name !== 'STAFF';
    }

    /**
     * Opsi user untuk Copy KPI (sumber dan target).
     *
     * @return array<int, string>
     */
    protected static function copyUserOptions(): array
    {
        $options = [];
        $actor = auth()->user();

        if (DataAccessService::hasGlobalAccess($actor)) {
            $users = User::all();
        } elseif (DataAccessService::isAreaScopedAdmin($actor)) {
            $users = static::visibleUsersQuery()->get();
        } else {
            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
            if ($managedUserIds === []) {
                return [];
            }

            $users = User::whereIn('id', $managedUserIds)->get();
        }

        $users->loadMissing('position');

        foreach ($users as $user) {
            $positionName = $user->position ? $user->position->name : 'No Position';
            $options[$user->id] = $user->nama_lengkap.' ('.$positionName.')';
        }

        return $options;
    }

    /**
     * User yang terlihat oleh aktor: badan usaha yang diizinkan + tim + diri sendiri (super admin tanpa batas).
     *
     * @return Builder<User>
     */
    protected static function visibleUsersQuery(): Builder
    {
        return DataAccessService::scopeOwnedRecords(User::query(), auth()->user(), 'users.id');
    }
}
