<?php

namespace App\Models;

use App\Support\WhatsAppNumber;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static function booted(): void
    {
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

    public function scopeFilter($query)
    {
        if(request('search')){
            $query->where('nama_lengkap',"like",'%'.request('search').'%');
        }
    }

    /** @return BelongsTo<User, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(User::class,'approval_id');
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

    /** @return BelongsTo<Divisi, $this> */
    public function divisi(): BelongsTo
    {
        return $this->belongsTo(Divisi::class);
    }

    public function daily()
    {
        return $this->hasMany(Daily::class);
    }

    public function weekly()
    {
        return $this->hasMany(Weekly::class);
    }

    public function monthly()
    {
        return $this->hasMany(Monthly::class);
    }

    public function request()
    {
        return $this->hasMany(Request::class);
    }

    public function cutpoint()
    {
        return $this->hasMany(Cutpoint::class);
    }

    public function overopen()
    {
        return $this->hasMany(Overopen::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function workJournals()
    {
        return $this->hasMany(WorkJournal::class);
    }


    public function attendance()
    {
        return $this->hasOne(Attendance::class);
    }

    public function employeeReview()
    {
        return $this->hasOne(EmployeeReview::class);
    }

    public function kpi()
    {
        return $this->hasMany(Kpi::class);
    }
    /**
     * The attributes that should be cast.
     *
     * @return array
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
