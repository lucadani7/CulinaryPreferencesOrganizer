<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Preference;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShoppingListController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(ShoppingList::STATUSES)], 'group_id' => ['nullable', 'integer']]);
        $user = $request->user();

        $lists = ShoppingList::query()->visibleTo($user)
            ->with(['group:id,name', 'user:id,name'])
            ->withCount(['items', 'items as checked_count' => fn ($q) => $q->where('is_checked', true)])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('group_id'), fn ($q) => $q->where('group_id', $request->integer('group_id')))
            ->latest()->get();

        $totals = DB::table('shopping_list_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.shopping_list_id', $lists->pluck('id'))
            ->groupBy('i.shopping_list_id')
            ->select('i.shopping_list_id', DB::raw('SUM(i.quantity * COALESCE(i.unit_price, p.price, 0)) as total'))
            ->pluck('total', 'shopping_list_id');

        $lists->each(fn ($l) => $l->setAttribute('estimated_total', round((float) ($totals[$l->id] ?? 0), 2)));

        return response()->json($lists);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->assertGroupAccess($request->user(), $data['group_id'] ?? null);
        $list = ShoppingList::create($data + ['user_id' => $request->user()->id]);
        return response()->json($list, 201);
    }

    public function show(Request $request, ShoppingList $shoppingList): JsonResponse
    {
        $this->authorizeAccess($request, $shoppingList);
        $shoppingList->load([
            'group:id,name', 'user:id,name',
            'items' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            'items.product' => fn ($q) => $q->withTrashed()->with(['allergens:id,name', 'category:id,name']),
        ]);

        // Warnings: products incompatible with any member's restrictions
        $people = $this->audience($shoppingList);
        $warnings = [];
        foreach ($shoppingList->items as $item) {
            if (! $item->product) continue;
            foreach ($people as $person) {
                $c = $item->product->compatibilityFor($person);
                if (! $c['compatible']) {
                    $warnings[] = ['item_id' => $item->id, 'user' => $person->name, 'reasons' => $c['reasons']];
                }
            }
        }

        return response()->json($shoppingList->toArray() + [
            'estimated_total' => round($shoppingList->estimatedTotal(), 2),
            'warnings' => $warnings,
        ]);
    }

    public function update(Request $request, ShoppingList $shoppingList): JsonResponse
    {
        $this->authorizeAccess($request, $shoppingList);
        $user = $request->user();
        $data = $this->validated($request, true);

        if (array_key_exists('group_id', $data) && $data['group_id'] !== $shoppingList->group_id) {
            // moving a list between groups (or making it personal) is the author's decision:
            // otherwise a group member could take someone's list away from them or from the group
            abort_unless($user->isAdmin() || $shoppingList->user_id === $user->id, 403, 'Only the author can move the list to another group.');
            $this->assertGroupAccess($user, $data['group_id']);
        }
        if (array_key_exists('status', $data)) {
            if ($data['status'] === 'completed' && $shoppingList->status !== 'completed') {
                $data['completed_at'] = now();
            } elseif ($data['status'] === 'open') {
                // reopened; archiving keeps the original completion date
                $data['completed_at'] = null;
            }
        }
        $shoppingList->update($data);
        return response()->json($shoppingList);
    }

    public function destroy(Request $request, ShoppingList $shoppingList): JsonResponse
    {
        $u = $request->user();
        abort_unless($u->isAdmin() || $shoppingList->user_id === $u->id, 403, 'Only the author can delete the list.');
        $shoppingList->delete();
        return response()->json(null, 204);
    }

    /** POST /api/shopping-lists/{list}/duplicate */
    public function duplicate(Request $request, ShoppingList $shoppingList): JsonResponse
    {
        $this->authorizeAccess($request, $shoppingList);
        $copy = DB::transaction(function () use ($shoppingList, $request) {
            $copy = $shoppingList->replicate(['status', 'completed_at']);
            $copy->fill(['name' => $shoppingList->name . ' (copy)', 'status' => 'open', 'user_id' => $request->user()->id]);
            $copy->save();
            foreach ($shoppingList->items()->orderBy('position')->orderBy('id')->get() as $item) {
                $copy->items()->create($item->only(['product_id', 'custom_name', 'quantity', 'unit', 'unit_price', 'notes', 'position']));
            }
            return $copy;
        });
        return response()->json($copy->load('items'), 201);
    }

    /**
     * POST /api/shopping-lists/generate
     * Generates a list from preferences (personal or group), respecting the
     * restrictions of everyone involved and, optionally, seasonality.
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'only_seasonal' => ['nullable', 'boolean'],
            'max_items' => ['nullable', 'integer', 'between:1,100'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'planned_for' => ['nullable', 'date'],
        ]);
        $user = $request->user();
        $group = isset($data['group_id']) ? Group::findOrFail($data['group_id']) : null;
        $this->assertGroupAccess($user, $group?->id);

        $people = $group ? $group->members()->with('allergens')->get() : collect([$user->load('allergens')]);
        $peopleIds = $people->pluck('id');

        // Aggregated score per product
        $prefs = Preference::query()->where('preferable_type', 'product')->whereIn('user_id', $peopleIds)
            ->where(fn ($w) => $w->whereNull('group_id')->when($group, fn ($x) => $x->orWhere('group_id', $group->id)))
            ->get(['preferable_id', 'sentiment', 'user_id']);

        $vetoed = $prefs->where('sentiment', 'avoid')->pluck('preferable_id')->unique();
        $scores = $prefs->groupBy('preferable_id')
            ->map(fn (Collection $s) => $s->sum(fn ($p) => Preference::SENTIMENT_SCORE[$p->sentiment]))
            ->filter(fn ($score, $id) => $score > 0 && ! $vetoed->contains($id));

        $allergenIds = $people->flatMap->allergens->pluck('id')->unique()->all();
        $diets = $people->pluck('diet');
        $diet = $diets->contains('vegan') ? 'vegan' : ($diets->contains('vegetarian') ? 'vegetarian' : null);

        $q = Product::query()->whereIn('id', $scores->keys())
            ->withoutAllergens($allergenIds)->compatibleWithDiet($diet);
        if (! empty($data['only_seasonal'])) {
            $q->availableIn(now()->month, $user->country);
        }

        $products = $q->get()->sortByDesc(fn ($p) => $scores[$p->id])->take($data['max_items'] ?? 30)->values();

        // Respect the budget (greedy, in score order)
        if (! empty($data['budget'])) {
            $sum = 0.0;
            $products = $products->filter(function ($p) use (&$sum, $data) {
                $price = (float) ($p->price ?? 0);
                if ($sum + $price > $data['budget']) return false;
                $sum += $price;
                return true;
            })->values();
        }

        $list = DB::transaction(function () use ($data, $user, $group, $products) {
            $list = ShoppingList::create([
                'user_id' => $user->id,
                'group_id' => $group?->id,
                'name' => $data['name'] ?? ('Generated list ' . now()->format('Y-m-d')),
                'budget' => $data['budget'] ?? null,
                'planned_for' => $data['planned_for'] ?? null,
            ]);
            foreach ($products as $i => $p) {
                $list->items()->create([
                    'product_id' => $p->id, 'quantity' => 1, 'unit' => 'pcs',
                    'unit_price' => $p->price, 'position' => $i,
                ]);
            }
            return $list;
        });

        return response()->json($list->load('items.product'), 201);
    }

    /** GET /api/shopping-lists/{list}/export?format=csv|pdf */
    public function export(Request $request, ShoppingList $shoppingList, ExportService $export)
    {
        $this->authorizeAccess($request, $shoppingList);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'pdf'])]])['format'];
        $shoppingList->load([
            'items' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            'items.product' => fn ($q) => $q->withTrashed()->with('category:id,name'),
            'group:id,name',
        ]);

        $columns = [
            'name' => 'Product', 'category' => 'Category', 'quantity' => 'Quantity', 'unit' => 'Unit',
            'unit_price' => 'Unit price', 'total' => 'Total', 'checked' => 'Bought', 'notes' => 'Notes',
        ];
        $rows = $shoppingList->items->map(fn (ShoppingListItem $i) => [
            'name' => $i->displayName(),
            'category' => $i->product?->category?->name,
            'quantity' => (float) $i->quantity,
            'unit' => $i->unit,
            'unit_price' => $i->unit_price !== null ? (float) $i->unit_price : ($i->product?->price !== null ? (float) $i->product->price : null),
            'total' => round($i->lineTotal(), 2),
            'checked' => $i->is_checked,
            'notes' => $i->notes,
        ])->all();

        $name = 'shopping_list_' . $shoppingList->id;
        if ($format === 'csv') {
            return $export->csv($name, $columns, $rows);
        }

        return $export->pdf($name, [
            'title' => $shoppingList->name,
            'description' => trim(($shoppingList->group ? 'Group: ' . $shoppingList->group->name . '. ' : '')
                . ($shoppingList->planned_for ? 'Planned for ' . $shoppingList->planned_for->format('Y-m-d') . '.' : '')),
            'columns' => $columns,
            'rows' => $rows,
        ], 'Shopping list', [
            'Estimated total' => number_format($shoppingList->estimatedTotal(), 2) . ' RON',
            'Budget' => $shoppingList->budget ? number_format((float) $shoppingList->budget, 2) . ' RON' : '—',
            'Checked items' => $shoppingList->items->where('is_checked', true)->count() . ' / ' . $shoppingList->items->count(),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** People whose restrictions apply to the list. */
    private function audience(ShoppingList $list): Collection
    {
        return $list->group
            ? $list->group->members()->with('allergens')->get()
            : collect([$list->user->load('allergens')]);
    }

    private function authorizeAccess(Request $request, ShoppingList $list): void
    {
        abort_unless($list->canBeAccessedBy($request->user()), 403, 'You do not have access to this list.');
    }

    private function assertGroupAccess(User $user, ?int $groupId): void
    {
        if ($groupId) {
            abort_unless($user->isAdmin() || in_array($groupId, $user->groupIds(), true), 403, 'You are not a member of the group.');
        }
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:190'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'status' => ['sometimes', Rule::in(ShoppingList::STATUSES)],
            'planned_for' => ['nullable', 'date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
        ]);
    }
}