<?php

namespace App\Models;

use App\Services\DataAccessService;
use App\Support\WhatsAppNumber;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'ADMIN';

    /** Admin melihat seluruh badan usaha. */
    public const DATA_ACCESS_ALL = 'all';

    /** Admin hanya melihat badan usaha di relasi dataAccessAreas. */
    public const DATA_ACCESS_SELECTED = 'selected';

    /** data_access diisi eksplisit sejak penyimpanan terakhir (fill, update, forceFill, atau assignment). */
    private bool $dataAccessAssigned = false;

    /** Setelah simpan, beri akses badan usaha milik karyawan ini (promosi ADMIN tanpa pilihan akses). */
    private bool $grantOwnAreaAfterSave = false;

    protected static function booted(): void
    {
        // Fail closed: karyawan lama yang dipromosikan menjadi ADMIN tanpa memilih akses data tidak
        // boleh diam-diam menjadi super admin (default kolom 'all'). Batasi ke badan usahanya sendiri.
        // User yang langsung dibuat sebagai ADMIN (factory, seeder) tidak tersentuh.
        static::saving(function (User $user): void {
            if (! $user->exists || $user->dataAccessAssigned || ! $user->isBeingPromotedToAdmin()) {
                return;
            }

            $user->data_access = self::DATA_ACCESS_SELECTED;
            $user->grantOwnAreaAfterSave = true;
        });

        static::saved(function (User $user): void {
            $user->dataAccessAssigned = false;

            if ($user->grantOwnAreaAfterSave) {
                $user->grantOwnAreaAfterSave = false;
                $user->grantOwnAreaAccess();
            }
        });

        static::updated(function (User $user): void {
            if (! $user->wasChanged('no_hp') || ! Schema::hasTable('whatsapp_otps')) {
                return;
            }

            WhatsappOtp::query()
                ->where('user_id', $user->getKey())
                ->whereNull('verified_at')
                ->update(['expires_at' => now()]);
        });
    }

    /** @return Attribute<never, mixed> */
    protected function noHp(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value): ?string {
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return null;
                }

                if (is_bool($value) || (! is_scalar($value) && ! $value instanceof \Stringable)) {
                    throw new InvalidArgumentException('No. HP tidak valid.');
                }

                $number = WhatsAppNumber::toLocal((string) $value);

                if (! $number) {
                    throw new InvalidArgumentException('No. HP harus berupa nomor WhatsApp Indonesia yang valid.');
                }

                return $number;
            },
        );
    }

    /**
     * Hanya menandai bahwa data_access diisi eksplisit pada penyimpanan ini; nilainya tidak diubah.
     *
     * @return Attribute<never, mixed>
     */
    protected function dataAccess(): Attribute
    {
        return Attribute::make(
            set: function (mixed $value): mixed {
                $this->dataAccessAssigned = true;

                return $value;
            },
        );
    }

    public static function isAdminRoleId(mixed $roleId): bool
    {
        if (blank($roleId) || ! is_scalar($roleId)) {
            return false;
        }

        return Role::query()->whereKey($roleId)->value('name') === self::ROLE_ADMIN;
    }

    /**
     * Role berubah dari non-ADMIN menjadi ADMIN pada penyimpanan ini.
     */
    protected function isBeingPromotedToAdmin(): bool
    {
        return $this->isDirty('role_id')
            && self::isAdminRoleId($this->getAttribute('role_id'))
            && ! self::isAdminRoleId($this->getOriginal('role_id'));
    }

    /**
     * Akses data badan usaha milik karyawan ini (users.area_id) bila belum ada badan usaha yang dipilih.
     */
    public function grantOwnAreaAccess(): void
    {
        if (filled($this->area_id) && ! $this->dataAccessAreas()->exists()) {
            $this->dataAccessAreas()->attach((int) $this->area_id);
        }

        $this->unsetRelation('dataAccessAreas');
        DataAccessService::clearMemo();
    }

    /**
     * Ringkasan daftar badan usaha tersimpan untuk catatan import ADMIN Badan Usaha Tertentu,
     * mis. "ULS" atau "GMD; daftar badan usaha lama dipertahankan" bila bukan badan usaha sendiri.
     */
    public function restrictedAccessSummary(): string
    {
        $areas = $this->dataAccessAreas()
            ->orderBy('areas.name')
            ->get(['areas.id', 'areas.name']);

        if ($areas->isEmpty()) {
            return 'belum ada badan usaha';
        }

        $names = $areas->pluck('name')->implode(', ');
        $isOwnAreaOnly = $areas->count() === 1 && (int) $areas->first()->getKey() === (int) $this->area_id;

        return $isOwnAreaOnly ? $names : "{$names}; daftar badan usaha lama dipertahankan";
    }

    public function getFilamentName(): string
    {
        return "{$this->nama_lengkap}";
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $guarded = [
        'id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'created_at',
        'updated_at',
    ];

    /** @param Builder<User> $query */
    #[Scope]
    protected function filter(Builder $query): void
    {
        if (request('search')) {
            $query->where('nama_lengkap', 'like', '%'.request('search').'%');
        }
    }

    /** @return BelongsTo<User, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_id');
    }

    /** @return BelongsTo<Area, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Badan usaha yang boleh diakses admin dengan akses data 'selected'.
     *
     * @return BelongsToMany<Area, $this>
     */
    public function dataAccessAreas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'user_area_access')->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role?->name === self::ROLE_ADMIN;
    }

    /** @return BelongsTo<Divisi, $this> */
    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class);
    }

    /** @return HasMany<Daily, $this> */
    public function daily(): HasMany
    {
        return $this->hasMany(Daily::class);
    }

    /** @return HasMany<Weekly, $this> */
    public function weekly(): HasMany
    {
        return $this->hasMany(Weekly::class);
    }

    /** @return HasMany<Monthly, $this> */
    public function monthly(): HasMany
    {
        return $this->hasMany(Monthly::class);
    }

    /** @return HasMany<Request, $this> */
    public function request(): HasMany
    {
        return $this->hasMany(Request::class);
    }

    /** @return HasMany<Cutpoint, $this> */
    public function cutpoint(): HasMany
    {
        return $this->hasMany(Cutpoint::class);
    }

    /** @return HasMany<Overopen, $this> */
    public function overopen(): HasMany
    {
        return $this->hasMany(Overopen::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** @return HasMany<WorkJournal, $this> */
    public function workJournals(): HasMany
    {
        return $this->hasMany(WorkJournal::class);
    }

    /** @return HasOne<Attendance, $this> */
    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }

    /** @return HasOne<EmployeeReview, $this> */
    public function employeeReview(): HasOne
    {
        return $this->hasOne(EmployeeReview::class);
    }

    /** @return HasMany<Kpi, $this> */
    public function kpi(): HasMany
    {
        return $this->hasMany(Kpi::class);
    }

    protected function casts(): array
    {
        return [
            'd' => 'boolean',
            'dr' => 'boolean',
            'mn' => 'boolean',
            'mr' => 'boolean',
            'password' => 'hashed',
            'wn' => 'boolean',
            'wr' => 'boolean',
        ];
    }
}
