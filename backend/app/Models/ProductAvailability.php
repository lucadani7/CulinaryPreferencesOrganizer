<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAvailability extends Model
{
    protected $fillable = ['product_id', 'region', 'month_from', 'month_to', 'is_available', 'notes'];

    protected function casts(): array
    {
        return ['is_available' => 'boolean', 'month_from' => 'integer', 'month_to' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}