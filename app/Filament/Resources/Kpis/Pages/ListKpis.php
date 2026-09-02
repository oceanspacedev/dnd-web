<?php

namespace App\Filament\Resources\Kpis\Pages;

use Filament\Actions\CreateAction;
use Filament\Schemas\Components\Grid;
use Exception;
use App\Exports\KpiMonthlyExport;
use App\Exports\KpiPerDivisionExport;
use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Divisi;
use App\Models\Kpi;
use App\Models\Position;
use App\Services\ApprovalScopeService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\ActionSize;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class ListKpis extends ListRecords
{
    protected static string $resource = KpiResource::class;
    protected static ?string $title = "KPI";

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
                                if (auth()->user()->role_id == 1) {
                                    return [
                                        'division' => 'Per Divisi',
                                        'user' => 'Per User',
                                    ];
                                }

                                return [
                                    'user' => 'Per User',
                                ];
                            })
                            ->default(fn() => auth()->user()->role_id == 1 ? 'division' : 'user')
                            ->required()
                            ->live(),
                        Select::make('divisi')
                            ->label('Divisi')
                            ->searchable()
                            ->preload()
                            ->options(Divisi::pluck('name', 'id'))
                            ->visible(fn ($get) => $get('export_by') === 'division' && auth()->user()->role_id == 1),
                        Select::make('user_id')
                            ->label('User')
                            ->searchable()
                            ->preload()
                            ->options(function (): array {
                                if (auth()->user()->role_id == 1) {
                                    return User::where('id', '<>', 1)
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
                            })
                            ->visible(fn ($get) => $get('export_by') === 'user' && auth()->user()->role_id != 2),
                        TextInput::make('tahun')
                            ->label('Tahun')
                            ->maxLength(4)
                            ->default(fn() => now()->format('Y'))
                            ->regex('/^\d{4}$/')
                            ->validationMessages([
                                'regex' => 'Format yang valid adalah tahun (YYYY).',
                            ])
                            ->visible(fn ($get) => $get('periode') === 'year')
                            ->required(fn ($get) => $get('periode') === 'year'),
                        TextInput::make('bulan')
                            ->label('Bulan')
                            ->maxLength(7)
                            ->default(fn() => now()->format('Y-m'))
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
                        $isYear = ($data['periode'] ?? 'year') === 'year';
                        $exportByUser = ($data['export_by'] ?? 'division') === 'user';
                        $userId = $exportByUser && !empty($data['user_id']) ? (int) $data['user_id'] : null;
                        $user = $userId ? User::where('id', $userId)->first() : null;
                        $isAdmin = auth()->user()->role_id == 1;
                        $managedUserIds = $isAdmin
                            ? []
                            : ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());

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

                        $divisiId = $data['divisi'] ?? ($user?->divisi_id ?? auth()->user()->divisi_id);
                        $divisi = Divisi::where('id', $divisiId)->first();
                        $divisionName = $user && $user->divisi ? $user->divisi->name : ($divisi->name ?? auth()->user()->divisi->name);
                        $fileSuffix = $user ? '_' . $user->nama_lengkap : '';

                        if ($isYear) {
                            $year = $data['tahun'];
                            $filename = 'KPI_' . $divisionName . '_' . $year . $fileSuffix . '.xlsx';

                            return Excel::download(
                                new KpiMonthlyExport($year, (string) $divisiId, $userId),
                                $filename
                            );
                        }

                        $month = $data['bulan'];
                        $filename = 'KPI_' . $divisionName . '_' . $month . $fileSuffix . '.xlsx';

                        return Excel::download(
                            new KpiPerDivisionExport($month, (string) $divisiId, $userId),
                            $filename
                        );
                    }),
                Action::make('copy')
                    ->label('Copy KPI')
                    ->icon('heroicon-o-document-duplicate')
                    ->schema([
                        Grid::make(['default' => 1,])
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

                                        if (auth()->user()->role?->name === 'ADMIN') {
                                            $positions = Position::with('user')->get();
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

                                            $options[$position->id] = $position->name . ' - ' . $userNames;
                                        }

                                        return $options;
                                    })
                                    ->visible(fn ($get) => $get('copy_mode') === 'position')
                                    ->required(fn ($get) => $get('copy_mode') === 'position'),

                                Select::make('source_users')
                                    ->label('Copy dari User')
                                    ->searchable()
                                    ->preload()
                                    ->options(function (): array {
                                        $options = [];

                                        if (auth()->user()->role?->name === 'ADMIN') {
                                            $users = User::all();
                                        } else {
                                            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
                                            if ($managedUserIds === []) {
                                                return [];
                                            }

                                            $users = User::whereIn('id', $managedUserIds)->get();
                                        }

                                        foreach ($users as $user) {
                                            $positionName = $user->position ? $user->position->name : 'No Position';
                                            $options[$user->id] = $user->nama_lengkap . ' (' . $positionName . ')';
                                        }

                                        return $options;
                                    })
                                    ->visible(fn ($get) => $get('copy_mode') === 'individual')
                                    ->required(fn ($get) => $get('copy_mode') === 'individual'),

                                Select::make('target_users')
                                    ->label('Copy ke User')
                                    ->searchable()
                                    ->multiple()
                                    ->preload()
                                    ->options(function (): array {
                                        $options = [];

                                        if (auth()->user()->role?->name === 'ADMIN') {
                                            $users = User::all();
                                        } else {
                                            $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
                                            if ($managedUserIds === []) {
                                                return [];
                                            }

                                            $users = User::whereIn('id', $managedUserIds)->get();
                                        }

                                        foreach ($users as $user) {
                                            $positionName = $user->position ? $user->position->name : 'No Position';
                                            $options[$user->id] = $user->nama_lengkap . ' (' . $positionName . ')';
                                        }

                                        return $options;
                                    })
                                    ->visible(fn ($get) => $get('copy_mode') === 'individual')
                                    ->required(fn ($get) => $get('copy_mode') === 'individual'),

                                Grid::make(['default' => 2,])
                                    ->schema([
                                        TextInput::make('tanggal1')
                                            ->label('Dari Bulan')
                                            ->maxLength(7)
                                            ->default(fn() => now()->format('Y-m'))
                                            ->regex('/^\d{4}-\d{2}$/')
                                            ->validationMessages([
                                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                                            ])
                                            ->extraInputAttributes(['type' => 'month'])
                                            ->required(),
                                        TextInput::make('tanggal2')
                                            ->label('Ke Bulan')
                                            ->maxLength(7)
                                            ->default(fn() => now()->addMonth()->format('Y-m'))
                                            ->regex('/^\d{4}-\d{2}$/')
                                            ->validationMessages([
                                                'regex' => 'Format yang valid adalah tahun-bulan (YYYY-MM).',
                                            ])
                                            ->extraInputAttributes(['type' => 'month'])
                                            ->required(),
                                    ])
                            ])
                    ])
                    ->slideOver()
                    ->modalWidth('md')
                    ->modalHeading('Copy KPI')
                    ->action(function (array $data) {
                        try {
                            $fromDate = Carbon::createFromFormat('Y-m', $data['tanggal1'])->startOfMonth();
                            $toDate = Carbon::createFromFormat('Y-m', $data['tanggal2'])->startOfMonth();
                            $copyMode = $data['copy_mode'];
                            $isAdmin = auth()->user()->role?->name === 'ADMIN';
                            $allowedUserIds = [];

                            if (! $isAdmin) {
                                $allowedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) auth()->id());
                            }

                            $copiedCount = 0;
                            $skippedCount = 0;

                            if ($copyMode === 'position') {
                                // Copy berdasarkan posisi (logika lama)
                                $usersQuery = User::where('position_id', $data['position']);
                                if (! $isAdmin) {
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

                                if (! $isAdmin) {
                                    $allowedSet = array_fill_keys($allowedUserIds, true);
                                    $sourceUsers = $sourceUsers->filter(static fn ($id) => isset($allowedSet[$id]));
                                    $targetUsers = $targetUsers->filter(static fn ($id) => isset($allowedSet[$id]));
                                }

                                $sourceUsers = $sourceUsers->unique()->values()->all();
                                $targetUsers = $targetUsers->unique()->values()->all();

                                if ($sourceUsers === [] || $targetUsers === []) {
                                    Notification::make()
                                        ->title('User tidak valid untuk copy KPI')
                                        ->body('Pastikan source dan target user berada dalam scope approval_id (bawahan langsung + satu level).')
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

                            return redirect()->route('filament.admin.resources.kpis.index');
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
}
