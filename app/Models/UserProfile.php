<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    protected $fillable = [
        'user_id',
        'age',
        'gender',
        'weight',
        'height',
        'goal',
        'activity_level',
        'calorie_target'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
