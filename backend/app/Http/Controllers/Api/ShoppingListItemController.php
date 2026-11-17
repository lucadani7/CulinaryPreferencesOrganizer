<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShoppingListItemController extends Controller
{
    public function store(Request $request, ShoppingList $shoppingList): JsonResponse
    {
        $this->authorizeAccess($request, $shoppingList);
        $data = $request->validate([
            // soft-deleted products cannot be added
            'product_id' => ['required_without:custom_name', 'nullable', 'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at')],
            'custom_name' => ['required_without:product_id', 'nullable', 'string', 'max:190'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'unit' => ['nullable', 'string', 'max:16'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $quantity = $data['quantity'] ?? 1;
        $unit = $data['unit'] ?? 'pcs';

        // The same product (unchecked, same unit) is already on the list: increase its quantity.
        // Different units are kept apart: "2 pcs" + "1 kg" is not "3 pcs".
        if (! empty($data['product_id'])) {
            $existing = $shoppingList->items()
                ->where('product_id', $data['product_id'])
                ->where('unit', $unit)
                ->where('is_checked', false)
                ->first();
            if ($existing) {
                $existing->increment('quantity', $quantity);
                return response()->json($existing->fresh('product'));
            }
        }

        $item = $shoppingList->items()->create([
            'product_id' => $data['product_id'] ?? null,
            'custom_name' => $data['custom_name'] ?? null,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $data['unit_price']
                ?? (! empty($data['product_id']) ? Product::find($data['product_id'])?->price : null),
            'notes' => $data['notes'] ?? null,
            'position' => (int) $shoppingList->items()->max('position') + 1,
        ]);
        // a new unchecked item reopens a completed list
        $this->autoComplete($shoppingList);

        return response()->json($item->load('product'), 201);
    }

    public function update(Request $request, ShoppingList $shoppingList, ShoppingListItem $item): JsonResponse
    {
        $this->authorizeItem($request, $shoppingList, $item);
        $data = $request->validate([
            'custom_name' => ['nullable', 'string', 'max:190'],
            'quantity' => ['sometimes', 'numeric', 'min:0.01', 'max:100000'],
            'unit' => ['sometimes', 'string', 'max:16'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_checked' => ['sometimes', 'boolean'],
        ]);

        // a free-text item must keep its name, otherwise it would show up as "—"
        abort_if(! $item->product_id && array_key_exists('custom_name', $data) && blank($data['custom_name']),
            422, 'An item without a product needs a name.');

        if (array_key_exists('is_checked', $data)) {
            $data['checked_by'] = $data['is_checked'] ? $request->user()->id : null;
            $data['checked_at'] = $data['is_checked'] ? now() : null;
        }
        $item->update($data);
        $this->autoComplete($shoppingList);

        return response()->json($item->fresh('product'));
    }

    /** PATCH /api/shopping-lists/{list}/items/{item}/toggle */
    public function toggle(Request $request, ShoppingList $shoppingList, ShoppingListItem $item): JsonResponse
    {
        $this->authorizeItem($request, $shoppingList, $item);
        $checked = ! $item->is_checked;
        $item->update([
            'is_checked' => $checked,
            'checked_by' => $checked ? $request->user()->id : null,
            'checked_at' => $checked ? now() : null,
        ]);
        $this->autoComplete($shoppingList);

        return response()->json($item->fresh('product'));
    }

    public function destroy(Request $request, ShoppingList $shoppingList, ShoppingListItem $item): JsonResponse
    {
        $this->authorizeItem($request, $shoppingList, $item);
        $item->delete();
        // removing the last unchecked item completes the list
        $this->autoComplete($shoppingList);
        return response()->json(null, 204);
    }

    /** The list automatically becomes "completed" when all items are checked (and vice versa). */
    private function autoComplete(ShoppingList $list): void
    {
        $total = $list->items()->count();
        $checked = $list->items()->where('is_checked', true)->count();
        if ($total > 0 && $total === $checked && $list->status === 'open') {
            $list->update(['status' => 'completed', 'completed_at' => now()]);
        } elseif ($checked < $total && $list->status === 'completed') {
            $list->update(['status' => 'open', 'completed_at' => null]);
        }
    }

    private function authorizeAccess(Request $request, ShoppingList $list): void
    {
        abort_unless($list->canBeAccessedBy($request->user()), 403, 'You do not have access to this list.');
        abort_if($list->status === 'archived', 422, 'The list is archived.');
    }

    private function authorizeItem(Request $request, ShoppingList $list, ShoppingListItem $item): void
    {
        abort_unless($item->shopping_list_id === $list->id, 404);
        $this->authorizeAccess($request, $list);
    }
}