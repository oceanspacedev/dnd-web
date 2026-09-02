<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable implements FilamentUser, HasName
{
    use HasFactory, Notifiable, SoftDeletes;

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

    public function approval()
    {
        return $this->belongsTo(User::class,'approval_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function divisi()
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

    public function position()
    {
        return $this->belongsTo(Position::class);
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
