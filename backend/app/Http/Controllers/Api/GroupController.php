<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Preference;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = $user->isAdmin() && $request->boolean('all') ? Group::query() : $user->groups();
        return response()->json($q->with('owner:id,name')->withCount(['members', 'shoppingLists'])->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();
        $group = DB::transaction(function () use ($data, $user) {
            $group = Group::create($data + ['owner_id' => $user->id]);
            $group->members()->attach($user->id, ['role' => 'owner']);
            return $group;
        });
        return response()->json($group->load('members:id,name,email'), 201);
    }

    public function show(Request $request, Group $group): JsonResponse
    {
        $this->authorizeMember($request, $group);
        return response()->json($group->load([
            'owner:id,name',
            'members' => fn ($m) => $m->select('users.id', 'name', 'email', 'diet')->with('allergens:id,name'),
        ]));
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $this->authorizeOwner($request, $group);
        $group->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]));
        return response()->json($group);
    }

    public function destroy(Request $request, Group $group): JsonResponse
    {
        $this->authorizeOwner($request, $group);
        $group->delete();
        return response()->json(null, 204);
    }

    /** POST /api/groups/{group}/members { email } */
    public function addMember(Request $request, Group $group): JsonResponse
    {
        $this->authorizeOwner($request, $group);
        // emails are stored in lowercase (see AuthController)
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => ['required', 'email', 'exists:users,email']]);
        $user = User::where('email', $data['email'])->firstOrFail();

        // attaching only new members: re-adding someone (e.g. the owner) must not change their role
        if (! $group->hasMember($user)) {
            $group->members()->attach($user->id, ['role' => 'member']);
        }
        return response()->json($group->load('members:id,name,email'));
    }

    public function removeMember(Request $request, Group $group, User $user): JsonResponse
    {
        $me = $request->user();
        abort_unless($group->isOwner($me) || $me->id === $user->id || $me->isAdmin(), 403);
        abort_if($group->owner_id === $user->id, 422, 'The owner cannot be removed from the group.');
        $group->members()->detach($user->id);
        return response()->json(null, 204);
    }

    /**
     * GET /api/groups/{group}/consensus
     * Aggregates the members' personal + group preferences: score, agreement, conflicts
     * and common restrictions (union of allergens + strictest diet).
     */
    public function consensus(Request $request, Group $group): JsonResponse
    {
        $this->authorizeMember($request, $group);
        $members = $group->members()->with('allergens:id,name')->get();
        $memberIds = $members->pluck('id');

        $prefs = Preference::query()
            ->whereIn('user_id', $memberIds)
            ->where(fn ($w) => $w->whereNull('group_id')->orWhere('group_id', $group->id))
            ->with(['preferable', 'user:id,name'])->get()
            // skip preferences whose target was deleted (e.g. a soft-deleted product)
            ->filter(fn ($p) => $p->preferable !== null);

        $items = $prefs->groupBy(fn ($p) => $p->preferable_type . ':' . $p->preferable_id)
            ->map(function ($set) use ($members) {
                $first = $set->first();
                // if both a personal and a group preference exist, the group preference wins
                $byUser = $set->sortBy(fn ($p) => $p->group_id ? 1 : 0)->keyBy('user_id');
                $scores = $byUser->map(fn ($p) => Preference::SENTIMENT_SCORE[$p->sentiment]);
                $hasPositive = $scores->contains(fn ($s) => $s > 0);
                $hasNegative = $scores->contains(fn ($s) => $s < 0);
                return [
                    'type' => $first->preferable_type,
                    'id' => $first->preferable_id,
                    'name' => $first->preferable->name,
                    'score' => $scores->sum(),
                    'votes' => $scores->count(),
                    'coverage' => round($scores->count() / max($members->count(), 1), 2),
                    'conflict' => $hasPositive && $hasNegative,
                    'vetoed' => $byUser->contains(fn ($p) => $p->sentiment === 'avoid'),
                    'opinions' => $byUser->map(fn ($p) => ['user' => $p->user->name, 'sentiment' => $p->sentiment, 'rating' => $p->rating])->values(),
                ];
            })
            ->sortByDesc('score')->values();

        $diets = $members->pluck('diet');
        $strictestDiet = $diets->contains('vegan') ? 'vegan' : ($diets->contains('vegetarian') ? 'vegetarian' : ($diets->contains('pescatarian') ? 'pescatarian' : 'omnivore'));

        return response()->json([
            'group' => $group->only(['id', 'name']),
            'members' => $members->count(),
            'common_restrictions' => [
                'diet' => $strictestDiet,
                'allergens' => $members->flatMap->allergens->unique('id')->map->only(['id', 'name'])->values(),
            ],
            'favorites' => $items->filter(fn ($i) => $i['score'] > 0 && ! $i['vetoed'])->values(),
            'conflicts' => $items->where('conflict', true)->values(),
            'avoid' => $items->filter(fn ($i) => $i['vetoed'] || $i['score'] < 0)->values(),
        ]);
    }

    private function authorizeMember(Request $request, Group $group): void
    {
        $u = $request->user();
        abort_unless($u->isAdmin() || $group->hasMember($u), 403, 'You are not a member of this group.');
    }

    private function authorizeOwner(Request $request, Group $group): void
    {
        $u = $request->user();
        abort_unless($u->isAdmin() || $group->isOwner($u), 403, 'Only the group owner can perform this operation.');
    }
}