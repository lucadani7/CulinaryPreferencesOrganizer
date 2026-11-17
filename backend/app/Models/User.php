<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['user', 'admin'];
    public const DIETS = ['omnivore', 'vegetarian', 'vegan', 'pescatarian'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'is_active', 'diet', 'country', 'locale', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class)->withPivot('severity');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withPivot(['role', 'joined_at']);
    }

    public function ownedGroups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(Preference::class);
    }

    public function shoppingLists(): HasMany
    {
        return $this->hasMany(ShoppingList::class);
    }

    /** IDs of the groups the user belongs to. */
    public function groupIds(): array
    {
        return $this->groups()->pluck('groups.id')->all();
    }
}