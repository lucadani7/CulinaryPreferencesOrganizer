<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Allergen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AllergenController extends Controller
{
    /** OFF taxonomy tag: language prefix + slug, e.g. en:gluten, en:sulphur-dioxide-and-sulphites */
    private const OFF_TAG = 'regex:/^[a-z]{2}:[a-z0-9-]+$/';

    public function index(): JsonResponse
    {
        return response()->json(Allergen::withCount(['products', 'users'])->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeTag($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'off_tag' => ['required', 'string', 'max:120', self::OFF_TAG, 'unique:allergens,off_tag'],
        ]);
        return response()->json(Allergen::create($data), 201);
    }

    public function update(Request $request, Allergen $allergen): JsonResponse
    {
        $this->normalizeTag($request);
        $allergen->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'off_tag' => ['sometimes', 'string', 'max:120', self::OFF_TAG, Rule::unique('allergens', 'off_tag')->ignore($allergen->id)],
        ]));
        return response()->json($allergen);
    }

    /**
     * Deleting an allergen also removes it from every user's restrictions, so those users would
     * silently stop getting warnings. That requires an explicit ?force=1.
     */
    public function destroy(Request $request, Allergen $allergen): JsonResponse
    {
        $users = $allergen->users()->count();
        abort_if($users > 0 && ! $request->boolean('force'), 422,
            "{$users} user(s) avoid this allergen. Deleting it removes their restriction; repeat with ?force=1 to confirm.");

        $allergen->delete();
        return response()->json(null, 204);
    }

    /** "EN:Gluten " -> "en:gluten" */
    private function normalizeTag(Request $request): void
    {
        if (is_string($request->input('off_tag'))) {
            $request->merge(['off_tag' => strtolower(trim($request->input('off_tag')))]);
        }
    }
}