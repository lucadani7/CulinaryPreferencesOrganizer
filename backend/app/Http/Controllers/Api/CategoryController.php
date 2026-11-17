<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Category::withCount('products')->with('parent:id,name')->orderBy('name')->get()
        );
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json($category->load(['parent', 'children'])->loadCount('products'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);

        return response()->json(Category::create($data), 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $this->validated($request, $category);
        if (! empty($data['parent_id'])) {
            $this->assertNoCycle($category, (int) $data['parent_id']);
        }
        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $data['slug'] = $this->uniqueSlug($data['slug'], $category->id);
        }

        $category->update($data);
        return response()->json($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        DB::transaction(function () use ($category) {
            // preferences are polymorphic (no foreign key), so they are removed explicitly;
            // products and subcategories keep existing, with category_id / parent_id set to NULL
            $category->preferences()->delete();
            $category->delete();
        });

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn([$category?->id])],
            'off_tag' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /** "dairy", then "dairy-2", "dairy-3"... so two categories with the same name never collide. */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'category';
        $slug = $base;
        for ($i = 2; Category::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }
        return $slug;
    }

    /** A category cannot be moved under one of its own subcategories. */
    private function assertNoCycle(Category $category, int $parentId): void
    {
        for ($id = $parentId; $id !== null; $id = Category::whereKey($id)->value('parent_id')) {
            if ($id === $category->id) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be placed under one of its own subcategories.']);
            }
        }
    }
}