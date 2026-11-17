<?php

namespace App\Services;

use App\Models\Allergen;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Open Food Facts open data + mapping onto the local model.
 */
class OpenFoodFactsService
{
    private const FIELDS = [
        'code', 'product_name', 'product_name_en', 'brands', 'quantity', 'image_front_url', 'image_url',
        'image_ingredients_url', 'image_nutrition_url', 'image_packaging_url', 'ingredients_text',
        'ingredients_text_en', 'nutriscore_grade', 'nova_group', 'ecoscore_grade', 'allergens_tags',
        'traces_tags', 'categories_tags', 'ingredients_analysis_tags', 'nutriments', 'countries_tags',
    ];

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('openfoodfacts.base_url'))
            ->withHeaders(['User-Agent' => config('openfoodfacts.user_agent')])
            ->timeout(config('openfoodfacts.timeout'))
            ->acceptJson()
            // retry only on network errors and 5xx; a 404 or 429 must not be repeated (OFF rate limits)
            ->retry(2, 500, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    /** GET request to OFF; network failures become a RuntimeException the controllers already handle. */
    private function get(string $path, array $query): Response
    {
        try {
            return $this->client()->get($path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('Open Food Facts cannot be reached right now.');
        }
    }

    /**
     * Full-text search in OFF. Returns normalized products (without saving them locally).
     * @return array{count: int, page: int, page_size: int, products: array<int, array>}
     */
    public function search(string $query, int $page = 1, int $pageSize = 20, ?string $country = null): array
    {
        $params = [
            'search_terms' => $query,
            'search_simple' => 1,
            'action' => 'process',
            'json' => 1,
            'page' => $page,
            'page_size' => min($pageSize, 50),
            'fields' => implode(',', self::FIELDS),
        ];
        if ($country) {
            $params['tagtype_0'] = 'countries';
            $params['tag_contains_0'] = 'contains';
            $params['tag_0'] = strtolower($country);
        }

        $key = 'off:search:' . md5(json_encode($params));

        return Cache::remember($key, config('openfoodfacts.cache_ttl'), function () use ($params) {
            $res = $this->get('/cgi/search.pl', $params);
            if (! $res->successful()) {
                throw new RuntimeException('Open Food Facts is not responding (HTTP ' . $res->status() . ')');
            }
            $json = $res->json();
            return [
                'count' => (int) ($json['count'] ?? 0),
                'page' => (int) ($json['page'] ?? $params['page']),
                'page_size' => (int) ($json['page_size'] ?? $params['page_size']),
                'products' => array_map(fn ($p) => $this->normalize($p), $json['products'] ?? []),
            ];
        });
    }

    /** Product details by barcode (or null if it does not exist in OFF). $fresh skips the cache. */
    public function fetch(string $barcode, bool $fresh = false): ?array
    {
        $barcode = preg_replace('/\D/', '', $barcode);
        $key = "off:product:{$barcode}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, config('openfoodfacts.cache_ttl'), function () use ($barcode) {
            $res = $this->get("/api/v2/product/{$barcode}.json", ['fields' => implode(',', self::FIELDS)]);
            if ($res->status() === 404) {
                return null;
            }
            if (! $res->successful()) {
                throw new RuntimeException('Open Food Facts is not responding (HTTP ' . $res->status() . ')');
            }
            $json = $res->json();
            if (($json['status'] ?? 0) !== 1 || empty($json['product'])) {
                return null;
            }
            return $this->normalize($json['product'] + ['code' => $barcode]);
        });
    }

    /** Imports (or re-syncs) an OFF product into the local database. */
    public function import(string $barcode, ?User $user = null): Product
    {
        $data = $this->fetch($barcode, fresh: true);
        if (! $data) {
            throw new RuntimeException("Product with barcode {$barcode} was not found in Open Food Facts.");
        }

        return DB::transaction(function () use ($data, $user) {
            $product = Product::withTrashed()->firstOrNew(['barcode' => $data['barcode']]);
            if ($product->trashed()) {
                $product->restore();
            }

            $product->fill([
                'name' => $data['name'] ?: ('Product ' . $data['barcode']),
                'brand' => $data['brand'],
                'quantity' => $data['quantity'],
                'image_url' => $data['image_url'],
                'ingredients_text' => $data['ingredients_text'],
                'nutriscore' => $data['nutriscore'],
                'nova_group' => $data['nova_group'],
                'ecoscore' => $data['ecoscore'],
                'is_vegan' => $data['is_vegan'],
                'is_vegetarian' => $data['is_vegetarian'],
                'nutriments' => $data['nutriments'],
                'category_id' => $product->category_id ?? $this->matchCategory($data['categories_tags']),
                'source' => 'off',
                'off_synced_at' => now(),
            ]);
            if (! $product->exists) {
                $product->created_by = $user?->id;
                $product->perishability = $this->guessPerishability($data['categories_tags']);
            }
            $product->save();

            // Allergens (contains) + traces
            $sync = [];
            foreach ($data['allergens_tags'] as $tag) {
                $sync[$this->allergenId($tag)] = ['level' => 'contains'];
            }
            foreach ($data['traces_tags'] as $tag) {
                $id = $this->allergenId($tag);
                $sync[$id] ??= ['level' => 'traces'];
            }
            $product->allergens()->sync($sync);

            // Images
            $product->images()->where('is_uploaded', false)->delete();
            foreach ($data['images'] as $type => $url) {
                $product->images()->create(['type' => $type, 'url' => $url]);
            }

            // Geographic availability (countries where it is sold, all year round)
            if ($product->availabilities()->count() === 0) {
                foreach (array_slice($data['countries'], 0, 10) as $cc) {
                    $product->availabilities()->create(['region' => $cc, 'month_from' => 1, 'month_to' => 12]);
                }
            }

            return $product->load(['category', 'allergens', 'images', 'availabilities']);
        });
    }

    /** Converts the raw OFF response into a stable structure. */
    public function normalize(array $p): array
    {
        $analysis = $p['ingredients_analysis_tags'] ?? [];
        $tri = function (string $yes, string $no) use ($analysis): ?bool {
            if (in_array($yes, $analysis, true)) return true;
            if (in_array($no, $analysis, true)) return false;
            return null;
        };

        $images = array_filter([
            'front' => $p['image_front_url'] ?? $p['image_url'] ?? null,
            'ingredients' => $p['image_ingredients_url'] ?? null,
            'nutrition' => $p['image_nutrition_url'] ?? null,
            'packaging' => $p['image_packaging_url'] ?? null,
        ]);

        $nutriments = Arr::only($p['nutriments'] ?? [], [
            'energy-kcal_100g', 'fat_100g', 'saturated-fat_100g', 'carbohydrates_100g',
            'sugars_100g', 'fiber_100g', 'proteins_100g', 'salt_100g',
        ]);

        $countries = collect($p['countries_tags'] ?? [])
            ->map(fn ($t) => $this->countryCode($t))->filter()->unique()->values()->all();

        return [
            'barcode' => (string) ($p['code'] ?? ''),
            'name' => ($p['product_name_en'] ?? null) ?: ($p['product_name'] ?? null),
            'brand' => isset($p['brands']) ? trim(explode(',', $p['brands'])[0]) : null,
            'quantity' => $p['quantity'] ?? null,
            'image_url' => $images['front'] ?? null,
            'images' => $images,
            'ingredients_text' => ($p['ingredients_text_en'] ?? null) ?: ($p['ingredients_text'] ?? null),
            'nutriscore' => $this->grade($p['nutriscore_grade'] ?? null),
            'nova_group' => isset($p['nova_group']) ? (int) $p['nova_group'] : null,
            'ecoscore' => $this->grade($p['ecoscore_grade'] ?? null),
            'is_vegan' => $tri('en:vegan', 'en:non-vegan'),
            'is_vegetarian' => $tri('en:vegetarian', 'en:non-vegetarian'),
            'allergens_tags' => array_values($p['allergens_tags'] ?? []),
            'traces_tags' => array_values($p['traces_tags'] ?? []),
            'categories_tags' => array_values($p['categories_tags'] ?? []),
            'nutriments' => $nutriments ?: null,
            'countries' => $countries,
        ];
    }

    private function grade(?string $g): ?string
    {
        $g = strtolower((string) $g);
        return in_array($g, ['a', 'b', 'c', 'd', 'e'], true) ? $g : null;
    }

    private function allergenId(string $tag): int
    {
        $name = ucfirst(str_replace('-', ' ', preg_replace('/^[a-z]{2}:/', '', $tag)));
        return Allergen::firstOrCreate(['off_tag' => $tag], ['name' => $name])->id;
    }

    /** Picks the most specific local category matching an OFF tag. */
    private function matchCategory(array $tags): ?int
    {
        if (! $tags) return null;
        $local = Category::whereIn('off_tag', $tags)->get(['id', 'off_tag']);
        if ($local->isEmpty()) return null;
        // OFF tags are ordered from general to specific -> the last match is the most specific
        $positions = array_flip($tags);
        return $local->sortByDesc(fn ($c) => $positions[$c->off_tag] ?? -1)->first()->id;
    }

    private function guessPerishability(array $tags): string
    {
        $high = ['en:dairies', 'en:meats', 'en:seafood', 'en:fresh-foods', 'en:breads', 'en:fresh-vegetables', 'en:fresh-fruits'];
        $low = ['en:canned-foods', 'en:dried-products', 'en:pastas', 'en:rices', 'en:beverages', 'en:condiments'];
        if (array_intersect($tags, $high)) return 'high';
        if (array_intersect($tags, $low)) return 'low';
        return 'medium';
    }

    private function countryCode(string $tag): ?string
    {
        static $map = [
            'en:romania' => 'RO', 'en:moldova' => 'MD', 'en:france' => 'FR', 'en:germany' => 'DE', 'en:italy' => 'IT',
            'en:spain' => 'ES', 'en:united-kingdom' => 'GB', 'en:united-states' => 'US', 'en:hungary' => 'HU',
            'en:bulgaria' => 'BG', 'en:poland' => 'PL', 'en:austria' => 'AT', 'en:belgium' => 'BE',
            'en:netherlands' => 'NL', 'en:switzerland' => 'CH', 'en:greece' => 'GR', 'en:portugal' => 'PT',
            'en:canada' => 'CA', 'en:czech-republic' => 'CZ', 'en:serbia' => 'RS', 'en:ukraine' => 'UA',
        ];
        return $map[$tag] ?? null;
    }
}