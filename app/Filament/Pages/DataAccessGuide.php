<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Areas\AreaResource;
use App\Filament\Resources\TeamMembers\TeamMemberResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Area;
use App\Models\User;
use App\Services\DataAccessService;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

/**
 * Panduan akses data per badan usaha (menu Karyawan) dan Tim Saya (approval line).
 * Kartu "Akses Data Anda" dihitung langsung dari akun yang login; ringkasan admin hanya untuk super admin.
 */
class DataAccessGuide extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Panduan Akses Data';

    protected static string|\UnitEnum|null $navigationGroup = 'Panduan';

    protected static ?int $navigationSort = 83;

    protected static ?string $slug = 'panduan-akses-data';

    protected static ?string $title = 'Panduan Akses Data Karyawan';

    protected string $view = 'filament.pages.data-access-guide';

    /**
     * @var array{
     *     role: string,
     *     status: string,
     *     status_color: string,
     *     scope: string,
     *     area_names: list<string>,
     *     home_area: string|null,
     *     employees_count: int|null,
     *     team_count: int|null
     * }|null
     */
    protected ?array $viewerAccessMemo = null;

    /**
     * Admin dengan akses badan usaha (menu Karyawan) atau atasan dengan tim (menu Tim Saya).
     */
    public static function canAccess(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        return UserResource::canViewAny() || TeamMemberResource::canViewAny();
    }

    protected function viewer(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function isSuperAdmin(): bool
    {
        return DataAccessService::hasGlobalAccess($this->viewer());
    }

    /**
     * Kolom matriks yang mewakili viewer: super_admin, scoped_admin, supervisor, atau staff.
     */
    public function getViewerRoleKey(): string
    {
        return $this->getViewerAccess()['role'];
    }

    /**
     * Ringkasan akses akun yang sedang login, hanya dari data yang memang terlihat olehnya.
     *
     * @return array{
     *     role: string,
     *     status: string,
     *     status_color: string,
     *     scope: string,
     *     area_names: list<string>,
     *     home_area: string|null,
     *     employees_count: int|null,
     *     team_count: int|null
     * }
     */
    public function getViewerAccess(): array
    {
        if ($this->viewerAccessMemo !== null) {
            return $this->viewerAccessMemo;
        }

        $viewer = $this->viewer();

        [$role, $status, $color] = match (true) {
            DataAccessService::hasGlobalAccess($viewer) => ['super_admin', 'Super Admin (Semua Badan Usaha)', 'success'],
            DataAccessService::isAreaScopedAdmin($viewer) => ['scoped_admin', 'Admin Badan Usaha Tertentu', 'warning'],
            DataAccessService::isTeamSupervisor($viewer) => ['supervisor', 'Atasan (Tim Saya)', 'info'],
            default => ['staff', 'Staf', 'gray'],
        };

        $allowedAreaIds = DataAccessService::allowedAreaIds($viewer);
        $scope = match (true) {
            $allowedAreaIds === null => 'all',
            $allowedAreaIds === [] => 'none',
            default => 'selected',
        };

        $areaNames = $scope === 'selected'
            ? Area::query()->whereKey($allowedAreaIds)->orderBy('name')->pluck('name')->map(static fn ($name): string => (string) $name)->values()->all()
            : [];

        $employeesCount = null;

        if (UserResource::canViewAny()) {
            $employeesQuery = UserResource::getEloquentQuery();
            $employeesCount = $employeesQuery->whereNull($employeesQuery->qualifyColumn('deleted_at'))->count();
        }

        return $this->viewerAccessMemo = [
            'role' => $role,
            'status' => $status,
            'status_color' => $color,
            'scope' => $scope,
            'area_names' => $areaNames,
            'home_area' => $viewer?->area?->name,
            'employees_count' => $employeesCount,
            'team_count' => TeamMemberResource::canViewAny() ? TeamMemberResource::getEloquentQuery()->count() : null,
        ];
    }

    /**
     * Kondisi akses data akun ADMIN saat ini. Hanya untuk super admin (yang memang melihat seluruh karyawan).
     *
     * @return array{global_count: int, scoped: list<array{name: string, areas: list<string>}>}|null
     */
    public function getAdminAccessSummary(): ?array
    {
        if (! $this->isSuperAdmin()) {
            return null;
        }

        $admins = User::query()
            ->whereHas('role', fn (Builder $role): Builder => $role->where('name', User::ROLE_ADMIN))
            ->with(['dataAccessAreas' => fn ($areas) => $areas->orderBy('areas.name')])
            ->orderBy('nama_lengkap')
            ->get(['id', 'nama_lengkap', 'data_access']);

        $scoped = $admins
            ->filter(fn (User $admin): bool => $admin->data_access === User::DATA_ACCESS_SELECTED)
            ->map(fn (User $admin): array => [
                'name' => (string) $admin->nama_lengkap,
                'areas' => $admin->dataAccessAreas->pluck('name')->map(static fn ($name): string => (string) $name)->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'global_count' => $admins->count() - count($scoped),
            'scoped' => $scoped,
        ];
    }

    /**
     * Siapa melihat apa: baris modul x kolom peran.
     *
     * @return list<array{module: string, super_admin: string, scoped_admin: string, supervisor: string, staff: string}>
     */
    public function getAccessMatrix(): array
    {
        return [
            [
                'module' => 'Menu Karyawan',
                'super_admin' => 'Seluruh karyawan, termasuk akun ADMIN dan tab Arsip.',
                'scoped_admin' => 'Karyawan yang Area-nya termasuk badan usaha yang diberikan (tanpa akun ADMIN), termasuk tab Arsip.',
                'supervisor' => 'Tidak tersedia.',
                'staff' => 'Tidak tersedia.',
            ],
            [
                'module' => 'Menu Tim Saya',
                'super_admin' => 'Anggota timnya sendiri (jika punya bawahan).',
                'scoped_admin' => 'Anggota timnya sendiri (jika punya bawahan), di badan usaha mana pun (tanpa akun ADMIN).',
                'supervisor' => 'Bawahan langsung + satu level di bawahnya (tanpa akun ADMIN).',
                'staff' => 'Tidak tersedia.',
            ],
            [
                'module' => 'KPI',
                'super_admin' => 'Semua karyawan.',
                'scoped_admin' => 'Badan usaha yang diberikan + bawahan sesuai Approval Line + dirinya sendiri. KPI akun ADMIN lain hanya bisa dilihat.',
                'supervisor' => 'Bawahan langsung + satu level di bawahnya (sesuai Approval Line), termasuk akun ADMIN yang tidak tampil di menu Tim Saya, + KPI miliknya. KPI milik sendiri hanya tampil di menu KPI jika Anda punya bawahan; selain itu lihat Checklist KPI di Dashboard.',
                'staff' => 'KPI miliknya lewat Checklist KPI di Dashboard.',
            ],
            [
                'module' => 'Kehadiran & Penilaian',
                'super_admin' => 'Semua karyawan.',
                'scoped_admin' => 'Badan usaha yang diberikan + bawahan sesuai Approval Line + dirinya sendiri. Data akun ADMIN, termasuk miliknya sendiri, hanya bisa dilihat.',
                'supervisor' => 'Bawahan langsung + satu level di bawahnya (sesuai Approval Line), termasuk akun ADMIN yang tidak tampil di menu Tim Saya.',
                'staff' => 'Tidak tersedia.',
            ],
            [
                'module' => 'Cutpoint',
                'super_admin' => 'Semua karyawan.',
                'scoped_admin' => 'Badan usaha yang diberikan + bawahan sesuai Approval Line + dirinya sendiri. Data akun ADMIN, termasuk miliknya sendiri, hanya bisa dilihat.',
                'supervisor' => 'Milik sendiri (lihat saja).',
                'staff' => 'Milik sendiri (lihat saja).',
            ],
            [
                'module' => 'Jurnal Harian',
                'super_admin' => 'Semua karyawan.',
                'scoped_admin' => 'Badan usaha yang diberikan + bawahan sesuai Approval Line + dirinya sendiri. Jurnal akun ADMIN lain hanya bisa dilihat.',
                'supervisor' => 'Milik sendiri + melihat jurnal bawahan langsung dan satu level di bawahnya (sesuai Approval Line), termasuk akun ADMIN yang tidak tampil di menu Tim Saya. Jurnal mereka tidak bisa diubah.',
                'staff' => 'Milik sendiri.',
            ],
            [
                'module' => 'Leaderboard KPI (Dashboard) & export-nya',
                'super_admin' => 'Seluruh perusahaan.',
                'scoped_admin' => 'Badan usaha yang diberikan + bawahan sesuai Approval Line + dirinya sendiri.',
                'supervisor' => 'Seluruh perusahaan.',
                'staff' => 'Seluruh perusahaan.',
            ],
            [
                'module' => 'Menu Settings & KPI Settings',
                'super_admin' => 'Ya.',
                'scoped_admin' => 'Tidak.',
                'supervisor' => 'Tidak.',
                'staff' => 'Tidak.',
            ],
            [
                'module' => 'Memberi Jabatan ADMIN & mengatur Akses Data',
                'super_admin' => 'Ya. ADMIN baru otomatis Badan Usaha Tertentu dengan Area-nya sendiri; Semua Badan Usaha perlu centang konfirmasi.',
                'scoped_admin' => 'Tidak.',
                'supervisor' => 'Tidak.',
                'staff' => 'Tidak.',
            ],
            [
                'module' => 'Import karyawan (Excel / JSON Talenta)',
                'super_admin' => 'Ya.',
                'scoped_admin' => 'Tidak.',
                'supervisor' => 'Tidak.',
                'staff' => 'Tidak.',
            ],
        ];
    }

    public function getEmployeesUrl(): ?string
    {
        return UserResource::canViewAny() ? UserResource::getUrl('index') : null;
    }

    public function getTeamUrl(): ?string
    {
        return TeamMemberResource::canViewAny() ? TeamMemberResource::getUrl('index') : null;
    }

    public function getAreaSettingsUrl(): ?string
    {
        return AreaResource::canViewAny() ? AreaResource::getUrl('index') : null;
    }

    public function getPositionGuideUrl(): ?string
    {
        return UserPositionGuide::canAccess() ? UserPositionGuide::getUrl() : null;
    }
}
