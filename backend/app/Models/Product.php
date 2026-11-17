<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    public const PERISHABILITY = ['low', 'medium', 'high'];

    protected $fillable = [
        'name', 'brand', 'barcode', 'category_id', 'image_url', 'price', 'currency', 'quantity',
        'ingredients_text', 'nutriscore', 'nova_group', 'ecoscore', 'is_vegetarian', 'is_vegan',
        'perishability', 'shelf_life_days', 'expires_at', 'nutriments', 'source', 'off_synced_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_vegetarian' => 'boolean',
            'is_vegan' => 'boolean',
            'nutriments' => 'array',
            'expires_at' => 'date',
            'off_synced_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class)->withPivot('level');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(ProductAvailability::class);
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class)->withPivot(['price', 'dish_name']);
    }

    public function preferences(): MorphMany
    {
        return $this->morphMany(Preference::class, 'preferable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Products available in a given month/region (also handles ranges that wrap around the year, e.g. 11 -> 2). */
    public function scopeAvailableIn(Builder $q, int $month, ?string $region = null): Builder
    {
        return $q->whereHas('availabilities', function (Builder $a) use ($month, $region) {
            $a->where('is_available', true)
              ->when($region, fn ($w) => $w->where('region', $region))
              ->where(function ($w) use ($month) {
                  $w->where(fn ($x) => $x->whereColumn('month_from', '<=', 'month_to')
                                         ->where('month_from', '<=', $month)->where('month_to', '>=', $month))
                    ->orWhere(fn ($x) => $x->whereColumn('month_from', '>', 'month_to')
                                           ->where(fn ($y) => $y->where('month_from', '<=', $month)->orWhere('month_to', '>=', $month)));
              });
        });
    }

    /** Excludes products containing the given allergens. */
    public function scopeWithoutAllergens(Builder $q, array $allergenIds): Builder
    {
        if (empty($allergenIds)) {
            return $q;
        }
        return $q->whereDoesntHave('allergens', fn ($a) => $a->whereIn('allergens.id', $allergenIds));
    }

    public function scopeCompatibleWithDiet(Builder $q, ?string $diet): Builder
    {
        return match ($diet) {
            'vegan' => $q->where('is_vegan', true),
            'vegetarian' => $q->where('is_vegetarian', true),
            default => $q,
        };
    }

    /**
     * Checks whether the product is suitable for a user (allergens + diet).
     * @return array{compatible: bool, reasons: string[]}
     */
    public function compatibilityFor(User $user): array
    {
        $reasons = [];
        $userAllergens = $user->allergens->keyBy('id');
        foreach ($this->allergens as $a) {
            if ($userAllergens->has($a->id)) {
                $reasons[] = "Contains allergen: {$a->name}" . ($a->pivot->level === 'traces' ? ' (traces)' : '');
            }
        }
        if ($user->diet === 'vegan' && $this->is_vegan === false) {
            $reasons[] = 'Not vegan';
        }
        if (in_array($user->diet, ['vegan', 'vegetarian'], true) && $this->is_vegetarian === false) {
            $reasons[] = 'Not vegetarian';
        }
        return ['compatible' => empty($reasons), 'reasons' => $reasons];
    }
}