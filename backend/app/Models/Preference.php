<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Preference extends Model
{
    public const SENTIMENTS = ['love', 'like', 'neutral', 'dislike', 'avoid'];
    public const TARGETS = ['product', 'category', 'restaurant'];

    /** Numeric score used in aggregations / statistics. */
    public const SENTIMENT_SCORE = ['love' => 2, 'like' => 1, 'neutral' => 0, 'dislike' => -1, 'avoid' => -2];

    protected $fillable = ['user_id', 'group_id', 'preferable_type', 'preferable_id', 'sentiment', 'rating', 'notes'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function preferable(): MorphTo
    {
        return $this->morphTo();
    }
}