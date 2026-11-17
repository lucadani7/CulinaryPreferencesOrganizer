<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Restaurant extends Model
{
    protected $fillable = [
        'name', 'cuisine', 'address', 'city', 'country', 'latitude', 'longitude', 'price_level',
        'website', 'phone', 'has_vegetarian_options', 'has_vegan_options', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'has_vegetarian_options' => 'boolean',
            'has_vegan_options' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot(['price', 'dish_name']);
    }

    public function preferences(): MorphMany
    {
        return $this->morphMany(Preference::class, 'preferable');
    }
}
