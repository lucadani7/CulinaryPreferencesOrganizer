<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Allergen extends Model
{
    protected $fillable = ['name', 'off_tag'];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('level');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('severity');
    }
}