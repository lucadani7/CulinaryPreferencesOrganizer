<?php

namespace Tests\Feature;

use App\Models\Allergen;
use App\Models\Group;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end tests of the REST API against a real PostgreSQL database
 * (ilike, jsonb and NULLS NOT DISTINCT are not available in SQLite).
 * phpunit.xml must use DB_CONNECTION=pgsql and DB_DATABASE=testing (Sail creates "testing").
 * Run: sail artisan test
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_register_login_and_me(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test', 'email' => 'test@x.ro', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated()->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $token = $this->postJson('/api/auth/login', ['email' => 'test@x.ro', 'password' => 'secret123'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('email', 'test@x.ro');
    }

    public function test_guest_cannot_access_api(): void
    {
        $this->getJson('/api/products')->assertUnauthorized();
    }

    public function test_safe_for_me_filter_excludes_user_allergens(): void
    {
        $ana = User::where('email', 'ana@cpo.test')->first(); // allergic to peanuts, vegetarian
        Sanctum::actingAs($ana);

        $names = collect($this->getJson('/api/products?safe_for_me=1&per_page=100')->assertOk()->json('data'))->pluck('name');

        $this->assertNotContains('Peanut butter', $names);
        $this->assertNotContains('Chicken breast', $names);
        $this->assertContains('Jonathan apples', $names);
    }

    public function test_seasonal_filter_handles_wraparound_ranges(): void
    {
        Sanctum::actingAs(User::first());
        // Apples: Sep -> Mar ; Tomatoes: Jun -> Sep
        $jan = collect($this->getJson('/api/products?month=1&region=RO&per_page=100')->json('data'))->pluck('name');
        $this->assertContains('Jonathan apples', $jan);
        $this->assertNotContains('Garden tomatoes', $jan);
    }

    public function test_preference_upsert_and_recommendations(): void
    {
        $ion = User::where('email', 'ion@cpo.test')->first();
        Sanctum::actingAs($ion);
        $p = Product::where('name', 'Butternut squash')->first();

        $this->postJson('/api/preferences', ['target_type' => 'product', 'target_id' => $p->id, 'sentiment' => 'like'])->assertCreated();
        $this->postJson('/api/preferences', ['target_type' => 'product', 'target_id' => $p->id, 'sentiment' => 'love'])->assertOk();

        $this->assertDatabaseCount('preferences', 9);
        $this->assertDatabaseHas('preferences', ['user_id' => $ion->id, 'preferable_id' => $p->id, 'sentiment' => 'love', 'group_id' => null]);

        $this->getJson('/api/preferences/recommendations')->assertOk()->assertJsonStructure(['products', 'month']);
    }

    public function test_group_generated_list_respects_all_members_restrictions(): void
    {
        $ana = User::where('email', 'ana@cpo.test')->first();
        $group = Group::where('name', 'Popescu Family')->first();
        Sanctum::actingAs($ana);

        $list = $this->postJson('/api/shopping-lists/generate', ['group_id' => $group->id])->assertCreated()->json();
        $names = collect($list['items'])->pluck('product.name');

        // Ion is allergic to milk, Ana is vegetarian -> yogurt and chicken must not appear
        $this->assertNotContains('Greek yogurt', $names);
        $this->assertNotContains('Chicken breast', $names);
    }

    public function test_consensus_detects_conflicts(): void
    {
        Sanctum::actingAs(User::where('email', 'ana@cpo.test')->first());
        $group = Group::where('name', 'Popescu Family')->first();
        $this->getJson("/api/groups/{$group->id}/consensus")->assertOk()
            ->assertJsonStructure(['favorites', 'conflicts', 'avoid', 'common_restrictions' => ['diet', 'allergens']])
            ->assertJsonPath('common_restrictions.diet', 'vegetarian');
    }

    public function test_stats_json_csv_and_pdf_exports(): void
    {
        Sanctum::actingAs(User::where('email', 'ana@cpo.test')->first());

        $this->getJson('/api/stats/top-products')->assertOk()->assertJsonStructure(['title', 'columns', 'rows']);

        $csv = $this->get('/api/stats/preferences-by-sentiment/export?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Sentiment', $csv->streamedContent());

        $pdf = $this->get('/api/stats/allergens/export?format=pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
    }

    public function test_shopping_list_export_and_access_control(): void
    {
        $ana = User::where('email', 'ana@cpo.test')->first();
        $list = $ana->shoppingLists()->first();

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $this->getJson("/api/shopping-lists/{$list->id}")->assertForbidden();

        Sanctum::actingAs($ana);
        $this->get("/api/shopping-lists/{$list->id}/export?format=csv")->assertOk();
    }

    public function test_admin_routes_are_protected(): void
    {
        Sanctum::actingAs(User::where('email', 'ana@cpo.test')->first());
        $this->getJson('/api/admin/users')->assertForbidden();

        Sanctum::actingAs(User::where('email', 'admin@cpo.test')->first());
        $this->getJson('/api/admin/users')->assertOk()->assertJsonPath('total', 3);
    }

    public function test_only_the_author_or_an_admin_can_change_a_product(): void
    {
        $product = Product::where('name', 'Greek yogurt')->first(); // created by the admin

        Sanctum::actingAs(User::where('email', 'ion@cpo.test')->first());
        $this->putJson("/api/products/{$product->id}", ['price' => 1])->assertForbidden();
        $this->postJson("/api/products/{$product->id}/sync")->assertForbidden();

        Sanctum::actingAs(User::where('email', 'admin@cpo.test')->first());
        $this->putJson("/api/products/{$product->id}", ['price' => 8.99])->assertOk()->assertJsonPath('price', '8.99');
    }

    public function test_checking_every_item_completes_the_list(): void
    {
        $ana = User::where('email', 'ana@cpo.test')->first();
        Sanctum::actingAs($ana);
        $list = $ana->shoppingLists()->first();

        foreach ($list->items as $item) {
            $this->patchJson("/api/shopping-lists/{$list->id}/items/{$item->id}/toggle")->assertOk();
        }
        $this->assertSame('completed', $list->fresh()->status);

        // a new unchecked item reopens it
        $this->postJson("/api/shopping-lists/{$list->id}/items", ['custom_name' => 'Honey'])->assertCreated();
        $this->assertSame('open', $list->fresh()->status);
    }

    public function test_deleting_a_group_owner_hands_the_group_over(): void
    {
        $ana = User::where('email', 'ana@cpo.test')->first();
        $ion = User::where('email', 'ion@cpo.test')->first();
        $group = Group::where('name', 'Popescu Family')->first();
        $list = $ana->shoppingLists()->where('group_id', $group->id)->first();

        Sanctum::actingAs(User::where('email', 'admin@cpo.test')->first());
        $this->deleteJson("/api/admin/users/{$ana->id}")->assertNoContent();

        $this->assertSame($ion->id, $group->fresh()->owner_id);
        $this->assertSame($ion->id, $list->fresh()->user_id);
    }

    public function test_off_import_maps_allergens_and_diet(): void
    {
        Http::fake([
            '*/api/v2/product/3017620422003.json*' => Http::response([
                'status' => 1,
                'product' => [
                    'code' => '3017620422003', 'product_name' => 'Nutella', 'brands' => 'Ferrero',
                    'nutriscore_grade' => 'e', 'nova_group' => 4,
                    'allergens_tags' => ['en:milk', 'en:nuts', 'en:soybeans'],
                    'ingredients_analysis_tags' => ['en:non-vegan', 'en:vegetarian'],
                    'categories_tags' => ['en:sweet-snacks', 'en:spreads'],
                    'countries_tags' => ['en:romania', 'en:france'],
                    'image_front_url' => 'https://images.openfoodfacts.org/x.jpg',
                ],
            ]),
        ]);
        Sanctum::actingAs(User::first());

        $this->postJson('/api/off/import', ['barcode' => '3017620422003'])->assertCreated()
            ->assertJsonPath('name', 'Nutella')
            ->assertJsonPath('is_vegan', false)
            ->assertJsonPath('is_vegetarian', true)
            ->assertJsonPath('nutriscore', 'e');

        $product = Product::where('barcode', '3017620422003')->first();
        $this->assertTrue($product->allergens->contains(Allergen::where('off_tag', 'en:milk')->first()));
        $this->assertSame('Sweets', $product->category->name);
        $this->assertEqualsCanonicalizing(['RO', 'FR'], $product->availabilities->pluck('region')->all());
    }
}