<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** How many units of `currency` one euro was worth on `date` (ECB reference rate). */
class ExchangeRate extends Model
{
    protected $fillable = ['date', 'currency', 'rate'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rate' => 'float',
        ];
    }
}
