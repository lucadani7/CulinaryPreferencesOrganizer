<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Services\OpenFoodFactsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OpenFoodFactsController extends Controller
{
    public function __construct(private readonly OpenFoodFactsService $off) {}

    /** GET /api/off/search?q=...&page=1&country=romania */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'between:1,50'],
            'country' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $result = $this->off->search($data['q'], $data['page'] ?? 1, $data['page_size'] ?? 20, $data['country'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        // Mark products that are already imported
        $existing = Product::whereIn('barcode', array_column($result['products'], 'barcode'))->pluck('id', 'barcode');
        $result['products'] = array_map(fn ($p) => $p + ['local_id' => $existing[$p['barcode']] ?? null], $result['products']);

        return response()->json($result);
    }

    /** GET /api/off/products/{barcode} */
    public function show(string $barcode): JsonResponse
    {
        try {
            $product = $this->off->fetch($barcode);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
        abort_if(! $product, 404, 'Product not found in Open Food Facts.');

        return response()->json($product + ['local_id' => Product::where('barcode', $product['barcode'])->value('id')]);
    }

    /** POST /api/off/import  { barcode } */
    public function import(Request $request): JsonResponse
    {
        $data = $request->validate(['barcode' => ['required', 'string', 'regex:/^\d{6,14}$/']]);
        $user = $request->user();

        // Already in the catalog and not ours to edit: return it as it is instead of overwriting
        // the owner's corrections (price, perishability, name...) with fresh OFF data.
        $existing = Product::where('barcode', $data['barcode'])->first();
        if ($existing && ! $this->canEdit($user, $existing)) {
            return response()->json($existing->load(['category', 'allergens', 'images', 'availabilities']));
        }

        try {
            $product = $this->off->import($data['barcode'], $user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($product, $product->wasRecentlyCreated ? 201 : 200);
    }

    /** POST /api/products/{product}/sync — re-syncs the data from OFF */
    public function sync(Request $request, Product $product): JsonResponse
    {
        abort_unless($this->canEdit($request->user(), $product), 403, 'You can only sync products you added.');
        abort_unless($product->barcode, 422, 'The product has no barcode.');

        try {
            return response()->json($this->off->import($product->barcode));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function canEdit(User $user, Product $product): bool
    {
        return $user->isAdmin() || $product->created_by === $user->id;
    }
}