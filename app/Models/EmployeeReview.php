<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeReview extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'periode',
        'responsiveness',
        'problem_solver',
        'helpfulness',
        'initiative',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
