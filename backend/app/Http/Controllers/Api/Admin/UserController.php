<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\ShoppingList;
use App\Models\User;
use App\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserController extends Controller
{
    /** GET /api/admin/users?q=&role=&active=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in(User::ROLES)],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $q = User::query()->withCount(['preferences', 'shoppingLists', 'groups']);
        if ($term = $request->string('q')->trim()->value()) {
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        }
        $q->when($request->filled('role'), fn ($w) => $w->where('role', $request->input('role')));
        $q->when($request->filled('active'), fn ($w) => $w->where('is_active', $request->boolean('active')));

        return response()->json($q->orderBy('name')->paginate($request->integer('per_page', 25))->withQueryString());
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(['allergens:id,name', 'groups:id,name'])
            ->loadCount(['preferences', 'shoppingLists', 'groups']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::in(User::ROLES)],
            'diet' => ['nullable', Rule::in(User::DIETS)],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        // null would override the column default (diet = omnivore) and violate NOT NULL
        $data = array_filter($data, fn ($v) => $v !== null);
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }
        return response()->json(User::create($data), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'diet' => ['sometimes', Rule::in(User::DIETS)],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'password' => ['sometimes', Password::min(8)],
        ]);

        $me = $request->user();
        if ($user->id === $me->id) {
            abort_if(($data['role'] ?? 'admin') !== 'admin', 422, 'You cannot revoke your own administrator role.');
            abort_if(($data['is_active'] ?? true) === false, 422, 'You cannot deactivate your own account.');
        }
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        $user->update($data);

        // a deactivated account or a password reset by an admin logs the user out everywhere
        if (($data['is_active'] ?? true) === false || (isset($data['password']) && $user->id !== $me->id)) {
            $user->tokens()->delete();
        }
        return response()->json($user);
    }

    /**
     * Deleting a user would cascade-delete the groups they own and the group lists they wrote,
     * taking data away from the other members. Ownership and those lists are handed over first.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');

        DB::transaction(function () use ($user) {
            foreach (Group::where('owner_id', $user->id)->get() as $group) {
                $heir = $group->members()->where('users.id', '!=', $user->id)
                    ->orderBy('group_user.joined_at')->first();

                if (! $heir) {
                    $group->delete();               // nobody else in the group
                    continue;
                }
                $group->update(['owner_id' => $heir->id]);
                $group->members()->updateExistingPivot($heir->id, ['role' => 'owner']);
            }

            // group lists written by the user stay with the group, under the (new) owner
            ShoppingList::where('user_id', $user->id)->whereNotNull('group_id')->with('group')->get()
                ->each(fn (ShoppingList $list) => $list->group
                    ? $list->update(['user_id' => $list->group->owner_id])
                    : null);

            $user->tokens()->delete();
            $user->delete();                        // personal lists and preferences go with the account
        });

        return response()->json(null, 204);
    }

    /** GET /api/admin/users/export?format=csv|pdf */
    public function export(Request $request, ExportService $export): StreamedResponse|Response
    {
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'pdf'])]])['format'];
        $columns = [
            'id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'role' => 'Role', 'is_active' => 'Active',
            'diet' => 'Diet', 'preferences_count' => 'Preferences', 'shopping_lists_count' => 'Lists',
            'created_at' => 'Created at',
        ];
        $rows = User::withCount(['preferences', 'shoppingLists'])->orderBy('id')->get()
            ->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role,
                'is_active' => $u->is_active, 'diet' => $u->diet,
                'preferences_count' => $u->preferences_count, 'shopping_lists_count' => $u->shopping_lists_count,
                'created_at' => $u->created_at?->format('Y-m-d'),
            ])->all();

        return $format === 'csv'
            ? $export->csv('users', $columns, $rows)
            : $export->pdf('users', ['title' => 'Users', 'description' => 'List of registered users.', 'columns' => $columns, 'rows' => $rows], 'Administration');
    }

    /** Emails are stored in lowercase (see AuthController). */
    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }
}