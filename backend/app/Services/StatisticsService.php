<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Preference;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Every report returns the same tabular structure, so it can be rendered
 * as a chart (JSON) or exported (CSV / PDF) without extra code.
 *
 * @phpstan-type Report array{key: string, title: string, description: string, columns: array<string,string>, rows: array<int, array<string, mixed>>, chart: string}
 */
class StatisticsService
{
    public const REPORTS = [
        'overview' => 'General overview',
        'preferences-by-sentiment' => 'Preferences by sentiment',
        'top-products' => 'Top rated products',
        'categories' => 'Category popularity',
        'allergens' => 'Allergens: products and restrictions',
        'nutriscore' => 'Nutri-Score distribution',
        'perishability' => 'Products by perishability',
        'seasonality' => 'Products available per month',
        'spending' => 'Estimated monthly spending',
        'shopping-top-items' => 'Most purchased products',
    ];

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @var array{type: string, user: User, group: ?Group} */
    private array $scope;

    /**
     * @param string $scope me | group | global (global is admin-only)
     */
    public function forScope(User $user, string $scope = 'me', ?Group $group = null): static
    {
        if ($scope === 'global' && ! $user->isAdmin()) {
            $scope = 'me';
        }
        if ($scope === 'group' && ! $group) {
            throw new InvalidArgumentException('Group is missing.');
        }
        $this->scope = ['type' => $scope, 'user' => $user, 'group' => $group];
        return $this;
    }

    public function scopeLabel(): string
    {
        return match ($this->scope['type']) {
            'global' => 'All users',
            'group' => 'Group: ' . $this->scope['group']->name,
            default => 'User: ' . $this->scope['user']->name,
        };
    }

    /** @return Report */
    public function report(string $key): array
    {
        if (! array_key_exists($key, self::REPORTS)) {
            throw new InvalidArgumentException("Unknown report: {$key}");
        }
        $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $key))));
        return ['key' => $key, 'title' => self::REPORTS[$key]] + $this->{$method}();
    }

    // ---------------------------------------------------------------- scope filters

    private function prefQuery(): QueryBuilder
    {
        $q = DB::table('preferences');
        return match ($this->scope['type']) {
            'global' => $q,
            'group' => $q->where(fn ($w) => $w->where('preferences.group_id', $this->scope['group']->id)
                ->orWhere(fn ($x) => $x->whereNull('preferences.group_id')
                    ->whereIn('preferences.user_id', $this->scope['group']->members()->pluck('users.id')))),
            default => $q->where('preferences.user_id', $this->scope['user']->id),
        };
    }

    private function listQuery(): QueryBuilder
    {
        $q = DB::table('shopping_lists');
        return match ($this->scope['type']) {
            'global' => $q,
            'group' => $q->where('shopping_lists.group_id', $this->scope['group']->id),
            default => $q->where(fn ($w) => $w->where('shopping_lists.user_id', $this->scope['user']->id)
                ->orWhereIn('shopping_lists.group_id', $this->scope['user']->groupIds())),
        };
    }

    private function userIds(): array
    {
        return match ($this->scope['type']) {
            'global' => User::pluck('id')->all(),
            'group' => $this->scope['group']->members()->pluck('users.id')->all(),
            default => [$this->scope['user']->id],
        };
    }

    // ---------------------------------------------------------------- reports

    private function overview(): array
    {
        $lists = $this->listQuery();
        $rows = [
            ['indicator' => 'Products in catalog', 'value' => Product::count()],
            ['indicator' => 'Products imported from Open Food Facts', 'value' => Product::where('source', 'off')->count()],
            ['indicator' => 'Recorded preferences', 'value' => $this->prefQuery()->count()],
            ['indicator' => 'Shopping lists', 'value' => (clone $lists)->count()],
            ['indicator' => 'Completed lists', 'value' => (clone $lists)->where('status', 'completed')->count()],
            ['indicator' => 'List items', 'value' => DB::table('shopping_list_items')
                ->whereIn('shopping_list_id', (clone $lists)->select('id'))->count()],
            ['indicator' => 'Restaurants', 'value' => DB::table('restaurants')->count()],
        ];
        if ($this->scope['type'] === 'global') {
            $rows[] = ['indicator' => 'Users', 'value' => User::count()];
            $rows[] = ['indicator' => 'Groups', 'value' => Group::count()];
        }
        return [
            'description' => 'Key indicators for ' . $this->scopeLabel(),
            'columns' => ['indicator' => 'Indicator', 'value' => 'Value'],
            'rows' => $rows,
            'chart' => 'none',
        ];
    }

    private function preferencesBySentiment(): array
    {
        $labels = ['love' => 'Love', 'like' => 'Like', 'neutral' => 'Neutral', 'dislike' => 'Dislike', 'avoid' => 'Avoid'];
        $counts = $this->prefQuery()->select('sentiment', DB::raw('count(*) as total'))
            ->groupBy('sentiment')->pluck('total', 'sentiment');
        $rows = [];
        foreach ($labels as $k => $label) {
            $rows[] = ['sentiment' => $label, 'total' => (int) ($counts[$k] ?? 0)];
        }
        return [
            'description' => 'Number of preferences for each sentiment.',
            'columns' => ['sentiment' => 'Sentiment', 'total' => 'Count'],
            'rows' => $rows,
            'chart' => 'pie',
        ];
    }

    private function topProducts(): array
    {
        $case = 'CASE preferences.sentiment ' . collect(Preference::SENTIMENT_SCORE)
            ->map(fn ($v, $k) => "WHEN '{$k}' THEN {$v}")->implode(' ') . ' ELSE 0 END';

        $rows = $this->prefQuery()
            ->join('products', 'products.id', '=', 'preferences.preferable_id')
            ->where('preferences.preferable_type', 'product')
            ->whereNull('products.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.brand')
            ->select('products.name', 'products.brand',
                DB::raw("SUM({$case}) as score"),
                DB::raw('ROUND(AVG(preferences.rating)::numeric, 2) as avg_rating'),
                DB::raw('COUNT(*) as votes'))
            ->orderByDesc('score')->orderByDesc('votes')
            ->limit(15)->get()
            ->map(fn ($r) => [
                'name' => $r->name, 'brand' => $r->brand ?? '—', 'score' => (int) $r->score,
                'avg_rating' => $r->avg_rating !== null ? (float) $r->avg_rating : null, 'votes' => (int) $r->votes,
            ])->all();

        return [
            'description' => 'Score = Σ(love=2, like=1, neutral=0, dislike=−1, avoid=−2).',
            'columns' => ['name' => 'Product', 'brand' => 'Brand', 'score' => 'Score', 'avg_rating' => 'Average rating', 'votes' => 'Votes'],
            'rows' => $rows,
            'chart' => 'bar',
        ];
    }

    private function categories(): array
    {
        $prefs = $this->prefQuery();
        $direct = (clone $prefs)->where('preferable_type', 'category')
            ->select('preferable_id as category_id', 'sentiment');
        $viaProduct = (clone $prefs)->where('preferable_type', 'product')
            ->join('products', 'products.id', '=', 'preferences.preferable_id')
            ->whereNotNull('products.category_id')
            ->select('products.category_id', 'preferences.sentiment');

        $union = $direct->unionAll($viaProduct);

        $rows = DB::query()->fromSub($union, 'p')
            ->join('categories', 'categories.id', '=', 'p.category_id')
            ->groupBy('categories.name')
            ->select('categories.name',
                DB::raw("SUM(CASE WHEN p.sentiment IN ('love','like') THEN 1 ELSE 0 END) as positive"),
                DB::raw("SUM(CASE WHEN p.sentiment IN ('dislike','avoid') THEN 1 ELSE 0 END) as negative"),
                DB::raw('COUNT(*) as total'))
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'positive' => (int) $r->positive, 'negative' => (int) $r->negative, 'total' => (int) $r->total])
            ->all();

        return [
            'description' => 'Direct category preferences + preferences for products in the category.',
            'columns' => ['name' => 'Category', 'positive' => 'Positive', 'negative' => 'Negative', 'total' => 'Total'],
            'rows' => $rows,
            'chart' => 'stacked',
        ];
    }

    private function allergens(): array
    {
        $userIds = $this->userIds();
        $rows = DB::table('allergens')
            ->leftJoin('allergen_product', 'allergen_product.allergen_id', '=', 'allergens.id')
            ->groupBy('allergens.id', 'allergens.name')
            ->select('allergens.id', 'allergens.name', DB::raw('COUNT(DISTINCT allergen_product.product_id) as products'))
            ->orderBy('allergens.name')->get();

        $restricted = DB::table('allergen_user')->whereIn('user_id', $userIds)
            ->select('allergen_id', DB::raw('COUNT(*) as users'))->groupBy('allergen_id')->pluck('users', 'allergen_id');

        return [
            'description' => 'How many products contain each allergen and how many users avoid it.',
            'columns' => ['name' => 'Allergen', 'products' => 'Products containing it', 'users' => 'Users with restriction'],
            'rows' => $rows->map(fn ($r) => [
                'name' => $r->name, 'products' => (int) $r->products, 'users' => (int) ($restricted[$r->id] ?? 0),
            ])->filter(fn ($r) => $r['products'] > 0 || $r['users'] > 0)->values()->all(),
            'chart' => 'bar',
        ];
    }

    private function nutriscore(): array
    {
        $counts = Product::query()->select(DB::raw("COALESCE(nutriscore, '?') as grade"), DB::raw('count(*) as total'))
            ->groupBy('grade')->pluck('total', 'grade');
        $rows = [];
        foreach (['a', 'b', 'c', 'd', 'e', '?'] as $g) {
            $rows[] = ['grade' => $g === '?' ? 'Unknown' : strtoupper($g), 'total' => (int) ($counts[$g] ?? 0)];
        }
        return [
            'description' => 'Nutritional quality of the products in the catalog (A = best).',
            'columns' => ['grade' => 'Nutri-Score', 'total' => 'Products'],
            'rows' => $rows,
            'chart' => 'bar',
        ];
    }

    private function perishability(): array
    {
        $labels = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
        $data = Product::query()->select('perishability',
            DB::raw('count(*) as total'), DB::raw('ROUND(AVG(shelf_life_days)::numeric, 1) as avg_days'))
            ->groupBy('perishability')->get()->keyBy('perishability');
        return [
            'description' => 'Number of products and average shelf life per perishability level.',
            'columns' => ['level' => 'Perishability', 'total' => 'Products', 'avg_days' => 'Average shelf life (days)'],
            'rows' => collect($labels)->map(fn ($label, $k) => [
                'level' => $label,
                'total' => (int) ($data[$k]->total ?? 0),
                'avg_days' => isset($data[$k]) && $data[$k]->avg_days !== null ? (float) $data[$k]->avg_days : null,
            ])->values()->all(),
            'chart' => 'bar',
        ];
    }

    private function seasonality(): array
    {
        $region = $this->scope['user']->country ?: 'RO';
        $rows = [];
        foreach (range(1, 12) as $m) {
            $rows[] = [
                'month' => self::MONTHS[$m - 1],
                'products' => Product::query()->availableIn($m, $region)->count(),
            ];
        }
        return [
            'description' => "Products available each month, region {$region}.",
            'columns' => ['month' => 'Month', 'products' => 'Available products'],
            'rows' => $rows,
            'chart' => 'line',
        ];
    }

    private function spending(): array
    {
        // a list counts in the month it was planned for, or the month it was created if it has no date
        $listDate = 'COALESCE(l.planned_for, l.created_at::date)';
        $from = now()->subMonths(11)->startOfMonth();

        $lists = $this->listQuery()->select('id');
        $data = DB::table('shopping_list_items as i')
            ->join('shopping_lists as l', 'l.id', '=', 'i.shopping_list_id')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('l.id', $lists)
            ->whereRaw("{$listDate} >= ?", [$from->toDateString()])
            ->groupBy('ym')
            ->select(DB::raw("to_char({$listDate}, 'YYYY-MM') as ym"),
                DB::raw('SUM(i.quantity * COALESCE(i.unit_price, p.price, 0)) as total'),
                DB::raw('SUM(CASE WHEN i.is_checked THEN i.quantity * COALESCE(i.unit_price, p.price, 0) ELSE 0 END) as bought'))
            ->orderBy('ym')->get()->keyBy('ym');

        $rows = [];
        for ($d = $from->copy(); $d <= now(); $d->addMonth()) {
            $ym = $d->format('Y-m');
            $rows[] = [
                'month' => self::MONTHS[$d->month - 1] . ' ' . $d->year,
                'planned' => round((float) ($data[$ym]->total ?? 0), 2),
                'bought' => round((float) ($data[$ym]->bought ?? 0), 2),
            ];
        }
        return [
            'description' => 'Estimated value (RON) of shopping lists, planned vs. checked off, last 12 months.',
            'columns' => ['month' => 'Month', 'planned' => 'Planned (RON)', 'bought' => 'Bought (RON)'],
            'rows' => $rows,
            'chart' => 'line',
        ];
    }

    private function shoppingTopItems(): array
    {
        $lists = $this->listQuery()->select('id');
        $rows = DB::table('shopping_list_items as i')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.shopping_list_id', $lists)
            ->groupBy('label')
            ->select(DB::raw('COALESCE(p.name, i.custom_name) as label'),
                DB::raw('COUNT(*) as occurrences'), DB::raw('SUM(i.quantity) as quantity'))
            ->orderByDesc('occurrences')->limit(15)->get()
            ->map(fn ($r) => ['label' => $r->label, 'occurrences' => (int) $r->occurrences, 'quantity' => (float) $r->quantity])
            ->all();
        return [
            'description' => 'Products that appear most often in shopping lists.',
            'columns' => ['label' => 'Product', 'occurrences' => 'Occurrences in lists', 'quantity' => 'Total quantity'],
            'rows' => $rows,
            'chart' => 'bar',
        ];
    }
}