<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'diet' => ['nullable', Rule::in(User::DIETS)],
            'country' => ['nullable', 'string', 'size:2'],
        ]);

        // null values would override the column defaults (diet = omnivore) and violate NOT NULL
        $data = array_filter($data, fn ($v) => $v !== null);
        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }

        $user = User::create($data + ['role' => 'user']);
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json(['user' => $this->profile($user), 'token' => $token], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account is deactivated.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('spa')->plainTextToken;

        return response()->json(['user' => $this->profile($user), 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'diet' => ['sometimes', Rule::in(User::DIETS)],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'locale' => ['sometimes', 'string', 'max:5'],
            'allergens' => ['sometimes', 'array'],
            'allergens.*.id' => ['required_with:allergens', 'integer', 'exists:allergens,id'],
            'allergens.*.severity' => ['nullable', Rule::in(['avoid', 'strict'])],
        ]);

        if (isset($data['country'])) {
            $data['country'] = strtoupper($data['country']);
        }
        $user->update(collect($data)->except('allergens')->all());

        if (array_key_exists('allergens', $data)) {
            $user->allergens()->sync(collect($data['allergens'])
                ->mapWithKeys(fn ($a) => [$a['id'] => ['severity' => $a['severity'] ?? 'avoid']])->all());
        }

        return response()->json($this->profile($user->fresh()));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $user = $request->user();
        $user->update(['password' => $data['password']]);

        // log out every other device; the current session keeps working
        $currentId = data_get($user->currentAccessToken(), 'id');
        $user->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    /** Emails are stored in lowercase, so "Ana@CPO.test" and "ana@cpo.test" are the same account. */
    private function normalizeEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
    }

    private function profile(User $user): array
    {
        $user->load(['allergens:id,name,off_tag', 'groups:id,name']);
        return $user->only(['id', 'name', 'email', 'role', 'is_active', 'diet', 'country', 'locale', 'last_login_at', 'created_at'])
            + [
                'allergens' => $user->allergens->map(fn ($a) => [
                    'id' => $a->id, 'name' => $a->name, 'off_tag' => $a->off_tag, 'severity' => $a->pivot->severity,
                ]),
                'groups' => $user->groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'role' => $g->pivot->role]),
            ];
    }
}