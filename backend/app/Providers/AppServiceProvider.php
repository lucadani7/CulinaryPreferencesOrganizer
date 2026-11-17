<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Polymorphic relations store short aliases instead of PHP class names.
        // enforceMorphMap is strict: EVERY model used in a polymorphic relation must be listed,
        // including User, which Sanctum uses for personal_access_tokens.tokenable_type.
        Relation::enforceMorphMap([
            'product' => Product::class,
            'category' => Category::class,
            'restaurant' => Restaurant::class,
            'user' => User::class,
        ]);
    }
}