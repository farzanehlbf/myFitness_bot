<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meal extends Model
{
    protected $fillable = [
        'user_id',
        'meal_type',
        'meal_time',
        'total_calories',
        'total_protein'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(MealItem::class);
    }
}
