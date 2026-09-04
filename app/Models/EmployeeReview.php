<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
