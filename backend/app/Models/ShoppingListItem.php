<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListItem extends Model
{
    protected $fillable = [
        'shopping_list_id', 'product_id', 'custom_name', 'quantity', 'unit', 'unit_price',
        'is_checked', 'checked_by', 'checked_at', 'notes', 'position',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'is_checked' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function displayName(): string
    {
        return $this->custom_name ?: ($this->product?->name ?? '—');
    }

    public function lineTotal(): float
    {
        $price = $this->unit_price ?? $this->product?->price;
        return $price === null ? 0.0 : (float) $price * (float) $this->quantity;
    }
}