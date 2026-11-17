<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\Category;
use App\Models\Group;
use App\Models\Preference;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedReferenceData();

        // Demo accounts all use the password "password": never create them on a live server
        // unless explicitly asked for (SEED_DEMO=true in .env).
        if (app()->isProduction() && ! env('SEED_DEMO', false)) {
            $this->command?->warn('Production environment: demo users and data were skipped (set SEED_DEMO=true to force).');
            return;
        }
        $this->seedDemoData();
    }

    /** Allergens and categories: needed in every environment (OFF import maps onto them). */
    private function seedReferenceData(): void
    {
        // --- The 14 EU-regulated allergens, with the tags used by Open Food Facts
        $allergens = [
            'Gluten' => 'en:gluten', 'Crustaceans' => 'en:crustaceans', 'Eggs' => 'en:eggs', 'Fish' => 'en:fish',
            'Peanuts' => 'en:peanuts', 'Soybeans' => 'en:soybeans', 'Milk' => 'en:milk', 'Tree nuts' => 'en:nuts',
            'Celery' => 'en:celery', 'Mustard' => 'en:mustard', 'Sesame seeds' => 'en:sesame-seeds',
            'Sulphites' => 'en:sulphur-dioxide-and-sulphites', 'Lupin' => 'en:lupin', 'Molluscs' => 'en:molluscs',
        ];
        foreach ($allergens as $name => $tag) {
            Allergen::updateOrCreate(['off_tag' => $tag], ['name' => $name]);
        }

        // --- Categories (mapped to the OFF taxonomy)
        $cats = [
            ['Dairy', 'en:dairies'], ['Fruits', 'en:fruits'], ['Vegetables', 'en:vegetables'],
            ['Meat', 'en:meats'], ['Fish and seafood', 'en:seafood'], ['Bread and bakery', 'en:breads'],
            ['Beverages', 'en:beverages'], ['Sweets', 'en:sweet-snacks'], ['Cereals', 'en:cereals-and-potatoes'],
            ['Condiments and sauces', 'en:condiments'],
        ];
        foreach ($cats as [$name, $tag]) {
            Category::updateOrCreate(['off_tag' => $tag], ['name' => $name]);
        }
    }

    /** Demo users, products, restaurants, preferences and a shared shopping list. */
    private function seedDemoData(): void
    {
        // --- Demo users
        $admin = User::updateOrCreate(['email' => 'admin@cpo.test'], [
            'name' => 'Administrator', 'password' => 'password', 'role' => 'admin', 'country' => 'RO',
        ]);
        $ana = User::updateOrCreate(['email' => 'ana@cpo.test'], [
            'name' => 'Ana Popescu', 'password' => 'password', 'diet' => 'vegetarian', 'country' => 'RO',
        ]);
        $ion = User::updateOrCreate(['email' => 'ion@cpo.test'], [
            'name' => 'Ion Ionescu', 'password' => 'password', 'country' => 'RO',
        ]);
        $ana->allergens()->syncWithoutDetaching([Allergen::where('off_tag', 'en:peanuts')->value('id') => ['severity' => 'strict']]);
        $ion->allergens()->syncWithoutDetaching([Allergen::where('off_tag', 'en:milk')->value('id') => ['severity' => 'avoid']]);

        $family = Group::firstOrCreate(['name' => 'Popescu Family'], ['owner_id' => $ana->id, 'description' => 'Shared grocery shopping']);
        $family->members()->syncWithoutDetaching([$ana->id => ['role' => 'owner'], $ion->id => ['role' => 'member']]);

        // --- Demo products
        $cat = fn (string $tag) => Category::where('off_tag', $tag)->value('id');
        $alg = fn (string $tag) => Allergen::where('off_tag', $tag)->value('id');

        $products = [
            ['name' => 'Greek yogurt', 'brand' => 'Olympus', 'category' => 'en:dairies', 'price' => 1.49, 'quantity' => '400 g',
             'is_vegetarian' => true, 'is_vegan' => false, 'perishability' => 'high', 'shelf_life_days' => 21, 'nutriscore' => 'b',
             'allergens' => ['en:milk'], 'seasons' => [['RO', 1, 12]]],
            ['name' => 'Jonathan apples', 'brand' => null, 'category' => 'en:fruits', 'price' => 0.99, 'quantity' => '1 kg',
             'is_vegetarian' => true, 'is_vegan' => true, 'perishability' => 'medium', 'shelf_life_days' => 30, 'nutriscore' => 'a',
             'allergens' => [], 'seasons' => [['RO', 9, 3]]],
            ['name' => 'Garden tomatoes', 'brand' => null, 'category' => 'en:vegetables', 'price' => 1.99, 'quantity' => '1 kg',
             'is_vegetarian' => true, 'is_vegan' => true, 'perishability' => 'high', 'shelf_life_days' => 7, 'nutriscore' => 'a',
             'allergens' => [], 'seasons' => [['RO', 6, 9]]],
            ['name' => 'Wholemeal bread', 'brand' => 'Vel Pitar', 'category' => 'en:breads', 'price' => 1.09, 'quantity' => '500 g',
             'is_vegetarian' => true, 'is_vegan' => true, 'perishability' => 'high', 'shelf_life_days' => 4, 'nutriscore' => 'a',
             'allergens' => ['en:gluten', 'en:sesame-seeds'], 'seasons' => [['RO', 1, 12]]],
            ['name' => 'Peanut butter', 'brand' => 'Nutline', 'category' => 'en:sweet-snacks', 'price' => 2.99, 'quantity' => '350 g',
             'is_vegetarian' => true, 'is_vegan' => true, 'perishability' => 'low', 'shelf_life_days' => 365, 'nutriscore' => 'c',
             'allergens' => ['en:peanuts'], 'seasons' => [['RO', 1, 12]]],
            ['name' => 'Chicken breast', 'brand' => 'Agricola', 'category' => 'en:meats', 'price' => 5.59, 'quantity' => '1 kg',
             'is_vegetarian' => false, 'is_vegan' => false, 'perishability' => 'high', 'shelf_life_days' => 5, 'nutriscore' => 'a',
             'allergens' => [], 'seasons' => [['RO', 1, 12]]],
            ['name' => 'Smoked salmon', 'brand' => 'Norvelita', 'category' => 'en:seafood', 'price' => 4.49, 'quantity' => '100 g',
             'is_vegetarian' => false, 'is_vegan' => false, 'perishability' => 'high', 'shelf_life_days' => 14, 'nutriscore' => 'd',
             'allergens' => ['en:fish'], 'seasons' => [['RO', 1, 12]]],
            ['name' => 'Butternut squash', 'brand' => null, 'category' => 'en:vegetables', 'price' => 0.69, 'quantity' => '1 kg',
             'is_vegetarian' => true, 'is_vegan' => true, 'perishability' => 'low', 'shelf_life_days' => 90, 'nutriscore' => 'a',
             'allergens' => [], 'seasons' => [['RO', 9, 12]]],
        ];

        foreach ($products as $p) {
            $product = Product::updateOrCreate(['name' => $p['name']], [
                'brand' => $p['brand'], 'category_id' => $cat($p['category']), 'price' => $p['price'],
                'quantity' => $p['quantity'], 'is_vegetarian' => $p['is_vegetarian'], 'is_vegan' => $p['is_vegan'],
                'perishability' => $p['perishability'], 'shelf_life_days' => $p['shelf_life_days'],
                'nutriscore' => $p['nutriscore'], 'source' => 'manual', 'created_by' => $admin->id,
            ]);
            $product->allergens()->sync(collect($p['allergens'])->mapWithKeys(fn ($t) => [$alg($t) => ['level' => 'contains']])->all());
            $product->availabilities()->delete();
            foreach ($p['seasons'] as [$region, $from, $to]) {
                $product->availabilities()->create(['region' => $region, 'month_from' => $from, 'month_to' => $to]);
            }
        }

        // --- Demo restaurants
        $r1 = Restaurant::updateOrCreate(['name' => 'Bistro Verde'], [
            'cuisine' => 'Vegetarian', 'city' => 'Iasi', 'country' => 'RO', 'price_level' => 2,
            'has_vegetarian_options' => true, 'has_vegan_options' => true, 'latitude' => 47.1585, 'longitude' => 27.6014,
            'created_by' => $admin->id,
        ]);
        $r2 = Restaurant::updateOrCreate(['name' => 'La Mama'], [
            'cuisine' => 'Romanian', 'city' => 'Bucharest', 'country' => 'RO', 'price_level' => 2,
            'has_vegetarian_options' => true, 'latitude' => 44.4268, 'longitude' => 26.1025,
            'created_by' => $admin->id,
        ]);
        $r1->products()->syncWithoutDetaching([Product::where('name', 'Butternut squash')->value('id') => ['dish_name' => 'Cream of squash soup', 'price' => 3.80]]);
        $r2->products()->syncWithoutDetaching([Product::where('name', 'Chicken breast')->value('id') => ['dish_name' => 'Chicken sour soup', 'price' => 4.40]]);

        // --- Demo preferences
        $prefs = [
            [$ana, null, Product::where('name', 'Jonathan apples')->first(), 'love', 5],
            [$ana, null, Product::where('name', 'Greek yogurt')->first(), 'like', 4],
            [$ana, null, Category::where('off_tag', 'en:meats')->first(), 'avoid', 1],
            [$ana, null, $r1, 'love', 5],
            [$ion, null, Product::where('name', 'Chicken breast')->first(), 'love', 5],
            [$ion, null, Category::where('off_tag', 'en:dairies')->first(), 'dislike', 2],
            [$ion, $family, Product::where('name', 'Wholemeal bread')->first(), 'like', 4],
            [$ana, $family, Product::where('name', 'Garden tomatoes')->first(), 'love', 5],
        ];
        foreach ($prefs as [$user, $group, $target, $sentiment, $rating]) {
            Preference::updateOrCreate([
                'user_id' => $user->id, 'group_id' => $group?->id,
                'preferable_type' => $target->getMorphClass(), 'preferable_id' => $target->id,
            ], ['sentiment' => $sentiment, 'rating' => $rating]);
        }

        // --- Demo shopping list
        $list = ShoppingList::firstOrCreate(['name' => 'Weekly groceries', 'user_id' => $ana->id], [
            'group_id' => $family->id, 'planned_for' => now()->addDays(2)->toDateString(), 'budget' => 30,
        ]);
        if ($list->items()->count() === 0) {
            foreach (['Jonathan apples' => 2, 'Wholemeal bread' => 1, 'Greek yogurt' => 3] as $name => $qty) {
                $p = Product::where('name', $name)->first();
                $list->items()->create(['product_id' => $p->id, 'quantity' => $qty, 'unit' => 'pcs', 'unit_price' => $p->price]);
            }
            $list->items()->create(['custom_name' => 'Mint tea', 'quantity' => 1, 'unit' => 'box', 'unit_price' => 1.69]);
        }
    }
}