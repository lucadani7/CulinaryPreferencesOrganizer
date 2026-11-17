<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestaurantController extends Controller
{
    /** GET /api/restaurants?q=&city=&cuisine=&country=&vegetarian=1&vegan=1&product_id=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'cuisine' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'product_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $q = Restaurant::query()->withCount('products');

        if ($term = $request->string('q')->trim()->value()) {
            $like = '%' . $this->escapeLike($term) . '%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('cuisine', 'ilike', $like));
        }
        // city: exact match, ignoring case ("iasi" finds "Iasi")
        $q->when($request->filled('city'), fn ($w) => $w->where('city', 'ilike', $this->escapeLike(trim($request->input('city')))));
        $q->when($request->filled('cuisine'), fn ($w) => $w->where('cuisine', 'ilike', '%' . $this->escapeLike($request->input('cuisine')) . '%'));
        $q->when($request->filled('country'), fn ($w) => $w->where('country', strtoupper($request->input('country'))));
        $q->when($request->boolean('vegetarian'), fn ($w) => $w->where('has_vegetarian_options', true));
        $q->when($request->boolean('vegan'), fn ($w) => $w->where('has_vegan_options', true));
        $q->when($request->filled('product_id'), fn ($w) => $w->whereHas('products', fn ($p) => $p->where('products.id', $request->integer('product_id'))));

        $page = $q->orderBy('name')->paginate($request->integer('per_page', 20))->withQueryString();

        $prefs = $request->user()->preferences()->whereNull('group_id')->where('preferable_type', 'restaurant')
            ->whereIn('preferable_id', $page->getCollection()->pluck('id'))->pluck('sentiment', 'preferable_id');
        $page->getCollection()->each(fn ($r) => $r->setAttribute('my_sentiment', $prefs[$r->id] ?? null));

        return response()->json($page);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        return response()->json($restaurant->load('products:id,name,brand,image_url'));
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = Restaurant::create($this->validated($request) + ['created_by' => $request->user()->id]);
        return response()->json($restaurant, 201);
    }

    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeEdit($request, $restaurant);
        $restaurant->update($this->validated($request, true));
        return response()->json($restaurant);
    }

    public function destroy(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeEdit($request, $restaurant);
        DB::transaction(function () use ($restaurant) {
            // preferences are polymorphic (no foreign key), so they are removed explicitly;
            // product links (product_restaurant) are removed by ON DELETE CASCADE
            $restaurant->preferences()->delete();
            $restaurant->delete();
        });
        return response()->json(null, 204);
    }

    /** POST /api/restaurants/{restaurant}/products  { product_id, dish_name?, price? } */
    public function attachProduct(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeEdit($request, $restaurant);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'dish_name' => ['nullable', 'string', 'max:190'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        $restaurant->products()->syncWithoutDetaching([
            $data['product_id'] => ['dish_name' => $data['dish_name'] ?? null, 'price' => $data['price'] ?? null],
        ]);
        return response()->json($restaurant->load('products:id,name,brand,image_url'));
    }

    public function detachProduct(Request $request, Restaurant $restaurant, int $productId): JsonResponse
    {
        $this->authorizeEdit($request, $restaurant);
        $restaurant->products()->detach($productId);
        return response()->json(null, 204);
    }

    private function authorizeEdit(Request $request, Restaurant $restaurant): void
    {
        $u = $request->user();
        abort_unless($u->isAdmin() || $restaurant->created_by === $u->id, 403, 'You can only modify restaurants you added.');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:190'],
            'cuisine' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'price_level' => ['nullable', 'integer', 'between:1,4'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'has_vegetarian_options' => ['nullable', 'boolean'],
            'has_vegan_options' => ['nullable', 'boolean'],
        ]);

        // NOT NULL columns with default false: an explicit null means "no"
        foreach (['has_vegetarian_options', 'has_vegan_options'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                $data[$col] = false;
            }
        }
        if (! empty($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }
        return $data;
    }

    /** % and _ typed by the user are matched literally, not as LIKE wildcards. */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}