<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * GET /api/products
     * Filters: q, category_id, source, perishability, nutriscore, vegan, vegetarian,
     *         month, region, safe_for_me, exclude_allergens[], restaurant_id, sort, per_page
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::in(['manual', 'off'])],
            'perishability' => ['nullable', Rule::in(Product::PERISHABILITY)],
            'nutriscore' => ['nullable', 'string'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'region' => ['nullable', 'string', 'max:64'],
            'exclude_allergens' => ['nullable', 'array'],
            'exclude_allergens.*' => ['integer'],
            'sort' => ['nullable', Rule::in(['name', '-name', 'price', '-price', 'created_at', '-created_at', 'nutriscore'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $user = $request->user();
        $userAllergenIds = $user->allergens()->pluck('allergens.id')->all();
        $q = Product::query()->with(['category:id,name', 'allergens:id,name,off_tag']);

        if ($term = $request->string('q')->trim()->value()) {
            // % and _ typed by the user are matched literally, not as LIKE wildcards
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)
                ->orWhere('brand', 'ilike', $like)
                ->orWhere('barcode', $term));
        }
        $q->when($request->filled('category_id'), function ($w) use ($request) {
            $id = (int) $request->input('category_id');
            $ids = DB::table('categories')->where('id', $id)->orWhere('parent_id', $id)->pluck('id');
            $w->whereIn('category_id', $ids);
        });
        $q->when($request->filled('source'), fn ($w) => $w->where('source', $request->input('source')));
        $q->when($request->filled('perishability'), fn ($w) => $w->where('perishability', $request->input('perishability')));
        $q->when($request->filled('nutriscore'), fn ($w) => $w->whereIn('nutriscore',
            array_intersect(str_split(strtolower($request->input('nutriscore'))), ['a', 'b', 'c', 'd', 'e'])));
        $q->when($request->boolean('vegan'), fn ($w) => $w->where('is_vegan', true));
        $q->when($request->boolean('vegetarian'), fn ($w) => $w->where('is_vegetarian', true));
        $q->when($request->filled('month'), fn ($w) => $w->availableIn((int) $request->input('month'),
            $request->filled('region') ? $this->normalizeRegion($request->input('region')) : null));
        $q->when($request->filled('restaurant_id'), fn ($w) => $w->whereHas('restaurants', fn ($r) => $r->where('restaurants.id', $request->integer('restaurant_id'))));
        $q->when($request->filled('exclude_allergens'), fn ($w) => $w->withoutAllergens(array_map('intval', $request->input('exclude_allergens'))));

        // Only products safe for the current user (allergens + diet)
        if ($request->boolean('safe_for_me')) {
            $q->withoutAllergens($userAllergenIds)->compatibleWithDiet($user->diet);
        }

        $sort = $request->input('sort', 'name');
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        $sort === 'nutriscore' ? $q->orderByRaw('nutriscore asc nulls last') : $q->orderBy($col, $dir);

        $page = $q->paginate($request->integer('per_page', 20))->withQueryString();

        // Attach the user's preference to each product on the page
        $prefs = $user->preferences()->whereNull('group_id')->where('preferable_type', 'product')
            ->whereIn('preferable_id', $page->getCollection()->pluck('id'))->pluck('sentiment', 'preferable_id');

        $page->getCollection()->transform(function (Product $p) use ($prefs, $userAllergenIds) {
            $p->setAttribute('my_sentiment', $prefs[$p->id] ?? null);
            $p->setAttribute('has_my_allergens', $p->allergens->pluck('id')->intersect($userAllergenIds)->isNotEmpty());
            return $p;
        });

        return response()->json($page);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $product->load(['category', 'allergens', 'images', 'availabilities', 'restaurants', 'creator:id,name']);
        $user = $request->user()->load('allergens');

        $stats = $product->preferences()
            ->select('sentiment', DB::raw('count(*) as total'))->groupBy('sentiment')->pluck('total', 'sentiment');

        return response()->json($product->toArray() + [
            'compatibility' => $product->compatibilityFor($user),
            'my_preference' => $user->preferences()->whereNull('group_id')
                ->where('preferable_type', 'product')->where('preferable_id', $product->id)->first(),
            'preference_stats' => $stats,
            'avg_rating' => round((float) $product->preferences()->avg('rating'), 2) ?: null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $product = DB::transaction(function () use ($data, $request) {
            $product = Product::create($data + ['created_by' => $request->user()->id, 'source' => 'manual', 'currency' => 'EUR']);
            $this->syncRelations($product, $data);
            return $product;
        });

        return response()->json($product->load(['category', 'allergens', 'availabilities']), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeEdit($request, $product);
        $data = $this->validated($request, $product);
        DB::transaction(function () use ($product, $data) {
            $product->update($data);
            $this->syncRelations($product, $data);
        });

        return response()->json($product->fresh(['category', 'allergens', 'availabilities']));
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeEdit($request, $product);
        $product->delete();
        return response()->json(null, 204);
    }

    private function authorizeEdit(Request $request, Product $product): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $product->created_by === $user->id, 403, 'You can only modify products you added.');
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $req = $product ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:190'],
            'brand' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:32', Rule::unique('products', 'barcode')->ignore($product?->id)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'image_url' => ['nullable', 'url', 'max:1024'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            // prices are stored in euro; other currencies are only displayed (converted at the ECB rate)
            'currency' => ['nullable', 'string', Rule::in(['EUR', 'eur'])],
            'quantity' => ['nullable', 'string', 'max:60'],
            'ingredients_text' => ['nullable', 'string', 'max:10000'],
            'nutriscore' => ['nullable', Rule::in(['a', 'b', 'c', 'd', 'e'])],
            'nova_group' => ['nullable', 'integer', 'between:1,4'],
            'ecoscore' => ['nullable', Rule::in(['a', 'b', 'c', 'd', 'e'])],
            'is_vegetarian' => ['nullable', 'boolean'],
            'is_vegan' => ['nullable', 'boolean'],
            'perishability' => ['nullable', Rule::in(Product::PERISHABILITY)],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'expires_at' => ['nullable', 'date'],
            'allergens' => ['sometimes', 'array'],
            'allergens.*.id' => ['required_with:allergens', 'integer', 'exists:allergens,id'],
            'allergens.*.level' => ['nullable', Rule::in(['contains', 'traces'])],
            'availabilities' => ['sometimes', 'array'],
            'availabilities.*.region' => ['required_with:availabilities', 'string', 'max:64'],
            'availabilities.*.month_from' => ['required_with:availabilities', 'integer', 'between:1,12'],
            'availabilities.*.month_to' => ['required_with:availabilities', 'integer', 'between:1,12'],
            'availabilities.*.is_available' => ['nullable', 'boolean'],
            'availabilities.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        // these columns are NOT NULL with a default: an explicit null means "use the default"
        foreach (['currency', 'perishability'] as $col) {
            if (array_key_exists($col, $data) && $data[$col] === null) {
                unset($data[$col]);
            }
        }
        if (isset($data['currency'])) {
            $data['currency'] = 'EUR';
        }
        return $data;
    }

    private function syncRelations(Product $product, array $data): void
    {
        if (array_key_exists('allergens', $data)) {
            $product->allergens()->sync(collect($data['allergens'])
                ->mapWithKeys(fn ($a) => [$a['id'] => ['level' => $a['level'] ?? 'contains']])->all());
        }
        if (array_key_exists('availabilities', $data)) {
            $product->availabilities()->delete();
            $product->availabilities()->createMany(array_map(fn ($a) => [
                'region' => $this->normalizeRegion($a['region']),
                'month_from' => $a['month_from'],
                'month_to' => $a['month_to'],
                'is_available' => $a['is_available'] ?? true,
                'notes' => $a['notes'] ?? null,
            ], $data['availabilities']));
        }
    }

    /** Country codes are stored in uppercase ("ro" -> "RO"); region names are kept as typed. */
    private function normalizeRegion(string $region): string
    {
        $region = trim($region);
        return strlen($region) === 2 ? strtoupper($region) : $region;
    }
}