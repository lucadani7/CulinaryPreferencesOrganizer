<?php

use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AllergenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\OpenFoodFactsController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImageController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\ShoppingListItemController;
use App\Http\Controllers\Api\StatisticsController;
use Illuminate\Support\Facades\Route;

/*
| REST API — /api prefix (configured in bootstrap/app.php)
| Authentication: Bearer token (Laravel Sanctum)
|
| Rate limits: throttle:<max requests>,<minutes>,<prefix>. The prefix gives every limit its own
| counter; without it all throttles of the same user share one counter and add up.
*/

Route::get('/health', fn () => ['status' => 'ok', 'time' => now()->toIso8601String()]);

// 10 attempts / minute / IP against password guessing
Route::prefix('auth')->middleware('throttle:10,1,auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // --- Account
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::put('/me/password', [AuthController::class, 'changePassword']);
    });

    // --- Catalog (read for everyone, admin-only writes for taxonomies)
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);
    Route::get('/allergens', [AllergenController::class, 'index']);

    Route::apiResource('products', ProductController::class);
    Route::post('/products/{product}/images', [ProductImageController::class, 'store']);
    Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy']);
    Route::post('/products/{product}/sync', [OpenFoodFactsController::class, 'sync'])->middleware('throttle:60,1,off-product');

    Route::apiResource('restaurants', RestaurantController::class);
    Route::post('/restaurants/{restaurant}/products', [RestaurantController::class, 'attachProduct']);
    Route::delete('/restaurants/{restaurant}/products/{productId}', [RestaurantController::class, 'detachProduct'])->whereNumber('productId');

    // --- Open Food Facts
    // OFF allows 10 searches/min and 100 product reads/min per client (responses are also cached)
    Route::prefix('off')->group(function () {
        Route::get('/search', [OpenFoodFactsController::class, 'search'])->middleware('throttle:10,1,off-search');
        Route::get('/products/{barcode}', [OpenFoodFactsController::class, 'show'])
            ->where('barcode', '\d{6,14}')->middleware('throttle:60,1,off-product');
        Route::post('/import', [OpenFoodFactsController::class, 'import'])->middleware('throttle:60,1,off-product');
    });

    // --- Preferences
    Route::get('/preferences/recommendations', [PreferenceController::class, 'recommendations']);
    Route::apiResource('preferences', PreferenceController::class)->except('show');

    // --- Groups
    Route::apiResource('groups', GroupController::class);
    Route::post('/groups/{group}/members', [GroupController::class, 'addMember']);
    Route::delete('/groups/{group}/members/{user}', [GroupController::class, 'removeMember']);
    Route::get('/groups/{group}/consensus', [GroupController::class, 'consensus']);

    // --- Shopping lists
    Route::post('/shopping-lists/generate', [ShoppingListController::class, 'generate']);
    Route::apiResource('shopping-lists', ShoppingListController::class)->parameters(['shopping-lists' => 'shoppingList']);
    Route::post('/shopping-lists/{shoppingList}/duplicate', [ShoppingListController::class, 'duplicate']);
    Route::get('/shopping-lists/{shoppingList}/export', [ShoppingListController::class, 'export']);
    Route::post('/shopping-lists/{shoppingList}/items', [ShoppingListItemController::class, 'store']);
    Route::patch('/shopping-lists/{shoppingList}/items/{item}', [ShoppingListItemController::class, 'update']);
    Route::patch('/shopping-lists/{shoppingList}/items/{item}/toggle', [ShoppingListItemController::class, 'toggle']);
    Route::delete('/shopping-lists/{shoppingList}/items/{item}', [ShoppingListItemController::class, 'destroy']);

    // --- Statistics + export
    Route::get('/stats', [StatisticsController::class, 'index']);
    Route::get('/stats/export/all', [StatisticsController::class, 'exportAll']);
    Route::get('/stats/{report}', [StatisticsController::class, 'show']);
    Route::get('/stats/{report}/export', [StatisticsController::class, 'export']);

    // --- Administration
    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('/users/export', [AdminUserController::class, 'export']);
        Route::apiResource('users', AdminUserController::class);

        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::post('/allergens', [AllergenController::class, 'store']);
        Route::put('/allergens/{allergen}', [AllergenController::class, 'update']);
        Route::delete('/allergens/{allergen}', [AllergenController::class, 'destroy']);
    });
});