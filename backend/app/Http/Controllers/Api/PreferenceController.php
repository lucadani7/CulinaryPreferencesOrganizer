<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Group;
use App\Models\Preference;
use App\Models\Product;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreferenceController extends Controller
{
    private const MODELS = ['product' => Product::class, 'category' => Category::class, 'restaurant' => Restaurant::class];

    /** GET /api/preferences?group_id=&type=&sentiment= */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'group_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(Preference::TARGETS)],
            'sentiment' => ['nullable', Rule::in(Preference::SENTIMENTS)],
        ]);
        $user = $request->user();

        $q = Preference::query()->with(['user:id,name', 'preferable' => function (MorphTo $m) {
            $m->morphWith([Product::class => ['category:id,name']]);
        }]);

        if ($request->filled('group_id')) {
            $group = Group::findOrFail($request->integer('group_id'));
            abort_unless($user->isAdmin() || $group->hasMember($user), 403);
            $q->where('group_id', $group->id);
        } else {
            $q->where('user_id', $user->id)->whereNull('group_id');
        }

        $q->when($request->filled('type'), fn ($w) => $w->where('preferable_type', $request->input('type')))
          ->when($request->filled('sentiment'), fn ($w) => $w->where('sentiment', $request->input('sentiment')));

        return response()->json($q->latest()->get());
    }

    /** POST /api/preferences — creates or updates the preference for a target */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(Preference::TARGETS)],
            'target_id' => ['required', 'integer'],
            'sentiment' => ['required', Rule::in(Preference::SENTIMENTS)],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
        ]);
        $user = $request->user();

        $model = self::MODELS[$data['target_type']];
        abort_unless($model::whereKey($data['target_id'])->exists(), 422, 'The preference target does not exist.');

        if (! empty($data['group_id'])) {
            abort_unless(Group::findOrFail($data['group_id'])->hasMember($user), 403, 'You are not a member of the group.');
        }

        // only the fields that were sent are changed: clicking a sentiment button must not
        // wipe the notes the user wrote earlier
        $values = ['sentiment' => $data['sentiment']]
            + array_intersect_key($data, array_flip(['rating', 'notes']));

        $pref = Preference::updateOrCreate([
            'user_id' => $user->id,
            'group_id' => $data['group_id'] ?? null,
            'preferable_type' => $data['target_type'],
            'preferable_id' => $data['target_id'],
        ], $values);

        return response()->json($pref->load('preferable'), $pref->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Preference $preference): JsonResponse
    {
        abort_unless($preference->user_id === $request->user()->id, 403);
        $preference->update($request->validate([
            'sentiment' => ['sometimes', Rule::in(Preference::SENTIMENTS)],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]));
        return response()->json($preference->load('preferable'));
    }

    public function destroy(Request $request, Preference $preference): JsonResponse
    {
        $u = $request->user();
        abort_unless($preference->user_id === $u->id || $u->isAdmin(), 403);
        $preference->delete();
        return response()->json(null, 204);
    }

    /**
     * GET /api/preferences/recommendations
     * Products from liked categories, compatible with restrictions, available this month,
     * that the user has not rated yet.
     */
    public function recommendations(Request $request): JsonResponse
    {
        $user = $request->user()->load('allergens');
        $mine = $user->preferences()->whereNull('group_id');

        $likedCategories = (clone $mine)->where('preferable_type', 'category')->whereIn('sentiment', ['love', 'like'])->pluck('preferable_id');
        $avoidCategories = (clone $mine)->where('preferable_type', 'category')->whereIn('sentiment', ['dislike', 'avoid'])->pluck('preferable_id');
        $likedProductCats = Product::whereIn('id', (clone $mine)->where('preferable_type', 'product')
            ->whereIn('sentiment', ['love', 'like'])->pluck('preferable_id'))->pluck('category_id')->filter();
        $rated = (clone $mine)->where('preferable_type', 'product')->pluck('preferable_id');

        $categories = $likedCategories->merge($likedProductCats)->unique()->diff($avoidCategories)->values();

        $q = Product::query()->with('category:id,name')
            ->whereNotIn('id', $rated)
            ->withoutAllergens($user->allergens->pluck('id')->all())
            ->compatibleWithDiet($user->diet)
            ->when($avoidCategories->isNotEmpty(), fn ($w) => $w->where(fn ($x) => $x->whereNull('category_id')->orWhereNotIn('category_id', $avoidCategories)));

        if ($categories->isNotEmpty()) {
            $q->whereIn('category_id', $categories);
        }

        $month = now()->month;
        $seasonal = (clone $q)->availableIn($month, $user->country)->orderByRaw('nutriscore asc nulls last')->limit(12)->get();
        $result = $seasonal->count() >= 6 ? $seasonal : $seasonal->merge(
            $q->whereNotIn('id', $seasonal->pluck('id'))->orderByRaw('nutriscore asc nulls last')->limit(12 - $seasonal->count())->get()
        );

        return response()->json([
            'based_on_categories' => Category::whereIn('id', $categories)->pluck('name'),
            'month' => $month,
            'products' => $result->values(),
        ]);
    }
}