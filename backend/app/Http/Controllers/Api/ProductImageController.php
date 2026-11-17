<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductImageController extends Controller
{
    private const TYPES = ['front', 'ingredients', 'nutrition', 'packaging', 'other'];

    /** POST /api/products/{product}/images  (multipart: image | json: url) */
    public function store(Request $request, Product $product): JsonResponse
    {
        $this->authorizeEdit($request, $product);
        $data = $request->validate([
            // explicit list: SVG is excluded because it can contain scripts
            'image' => ['required_without:url', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'url' => ['required_without:image', 'url:http,https', 'max:1024'],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'set_as_main' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store("products/{$product->id}", 'public');
            $url = $this->disk()->url($path);
            $uploaded = true;
        } else {
            $url = $data['url'];
            $uploaded = false;
        }

        $image = $product->images()->create([
            'url' => $url, 'type' => $data['type'] ?? 'other', 'is_uploaded' => $uploaded,
        ]);

        if ($request->boolean('set_as_main') || ! $product->image_url) {
            $product->update(['image_url' => $url]);
        }

        return response()->json($image, 201);
    }

    public function destroy(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $product->id, 404);
        $this->authorizeEdit($request, $product);

        if ($image->is_uploaded) {
            // ".../storage/products/5/abc.jpg" -> "products/5/abc.jpg" (works even if APP_URL changed)
            $relative = Str::after((string) parse_url($image->url, PHP_URL_PATH), '/storage/');
            $this->disk()->delete($relative);
        }
        if ($product->image_url === $image->url) {
            $product->update(['image_url' => $product->images()->where('id', '!=', $image->id)->value('url')]);
        }
        $image->delete();

        return response()->json(null, 204);
    }

    /**
     * Storage::disk() is typed as the generic Filesystem contract, which has no url() method;
     * the real object is a FilesystemAdapter, and saying so keeps editors (Intelephense) happy.
     */
    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        return $disk;
    }

    private function authorizeEdit(Request $request, Product $product): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $product->created_by === $user->id, 403, 'You can only change images of products you added.');
    }
}