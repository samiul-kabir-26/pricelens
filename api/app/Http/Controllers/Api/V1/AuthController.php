<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Problem;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard users (Sanctum). Stateful SPA clients get a session cookie from /auth/login; server-side clients
 * (e.g. a BFF) use /auth/token for a personal access token. Machine clients keep using tenant API keys.
 */
class AuthController extends Controller
{
    /** Session login for first-party SPA origins (SANCTUM_STATEFUL_DOMAINS). */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        if (! $request->hasSession()) {
            return Problem::response('stateless_request', 'Session login requires a stateful origin', 400,
                'Use POST /api/v1/auth/token from non-browser clients.');
        }
        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return response()->json(['data' => $this->profile($user)]);
    }

    /** Personal access token (Bearer) for server-side dashboard clients. */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User|null $user */
        $user = User::query()->where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $token = $user->createToken($data['device_name'], ['*'], now()->addDays(30));

        return response()->json(['data' => ['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at?->toIso8601ZuluString(),
            'user' => $this->profile($user)]], 201);
    }

    public function logout(Request $request): Response
    {
        $user = $request->user('sanctum');
        $token = $user?->currentAccessToken();
        if ($token instanceof Model) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return response()->json(['data' => $this->profile($user)]);
    }

    public function switchTenant(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'string', 'ulid']]);
        /** @var User $user */
        $user = $request->user('sanctum');
        $tenant = $user->tenants()->where('tenants.ulid', $data['tenant_id'])->first();
        if (! $tenant) {
            return Problem::response('not_found', 'Resource not found', 404);
        }
        $user->update(['current_tenant_id' => $tenant->id]);

        return response()->json(['data' => $this->profile($user->refresh())]);
    }

    /** Optional self-serve signup (PRICELENS_SELF_SIGNUP=true): user + tenant on the default plan + admin API key. */
    public function register(Request $request): JsonResponse
    {
        if (! config('pricelens.auth.self_signup')) {
            return Problem::response('signup_disabled', 'Self-serve signup is disabled', 403, 'Tenants are provisioned by an administrator.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
            'company' => ['required', 'string', 'max:255'],
        ]);

        [$user, $tenant, $key] = DB::transaction(function () use ($data) {
            $plan = Plan::query()->where('code', config('pricelens.defaults.plan'))->firstOrFail();
            $tenant = Tenant::query()->create([
                'name' => $data['company'], 'slug' => Str::slug($data['company']).'-'.Str::lower(Str::random(5)), 'plan_id' => $plan->id,
                'retention_days' => (int) config('pricelens.defaults.retention_days'),
            ]);
            $user = User::query()->create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'current_tenant_id' => $tenant->id]);
            $tenant->users()->attach($user->id, ['role' => 'owner']);
            [, $key] = ApiKey::generate($tenant, 'Default admin key', ['admin']);

            return [$user, $tenant, $key];
        });

        $token = $user->createToken('signup', ['*'], now()->addDays(30))->plainTextToken;

        return response()->json(['data' => ['user' => $this->profile($user), 'token' => $token, 'api_key' => $key]], 201);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        // Same response whether or not the address exists (no account enumeration).
        return response()->json(['data' => ['status' => 'If the address exists, a reset link has been sent.']], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => __($status)]);
        }

        return response()->json(['data' => ['status' => 'Password updated.']]);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        return [
            'id' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'current_tenant_id' => $user->currentTenant?->ulid,
            'tenants' => $user->tenants()->with('plan')->get()->map(fn (Tenant $t) => [
                'id' => $t->ulid, 'name' => $t->name, 'slug' => $t->slug, 'role' => $t->pivot->role, 'plan' => $t->plan?->code,
            ])->values()->all(),
        ];
    }
}
