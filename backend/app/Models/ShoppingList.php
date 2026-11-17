<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShoppingList extends Model
{
    public const STATUSES = ['open', 'completed', 'archived'];

    protected $fillable = ['user_id', 'group_id', 'name', 'status', 'planned_for', 'budget', 'completed_at'];

    protected function casts(): array
    {
        return [
            'planned_for' => 'date',
            'completed_at' => 'datetime',
            'budget' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function items(): HasMany
    {
        // no orderBy on the relation: aggregates (count/max) would fail on PostgreSQL with ORDER BY
        return $this->hasMany(ShoppingListItem::class);
    }

    /** Lists visible to the user: their own + those of the groups they belong to. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $groupIds = $user->groupIds();
        return $q->where(fn ($w) => $w->where('user_id', $user->id)
            ->when($groupIds, fn ($x) => $x->orWhereIn('group_id', $groupIds)));
    }

    public function canBeAccessedBy(User $user): bool
    {
        return $user->isAdmin()
            || $this->user_id === $user->id
            || ($this->group_id && in_array($this->group_id, $user->groupIds(), true));
    }

    public function estimatedTotal(): float
    {
        return (float) $this->items->sum(fn (ShoppingListItem $i) => $i->lineTotal());
    }
}