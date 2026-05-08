<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealItem extends Model
{
    protected $fillable = [
        'meal_id',
        'name',
        'calories',
        'protein'
    ];

    public function meal()
    {
        return $this->belongsTo(Meal::class);
    }
}
