<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Tenant membership and roles (owner/admin/member/viewer). New users receive a password-reset link to set
 * their password. Only owners can grant or revoke the owner role; the last owner can't be removed or demoted.
 */
class MemberController extends Controller
{
    private const ROLES = ['owner', 'admin', 'member', 'viewer'];

    public function index(): JsonResponse
    {
        $members = $this->tenant()->users()->orderBy('users.id')->get()->map(fn (User $u) => $this->present($u))->values();

        return response()->json(['data' => $members]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);
        if ($data['role'] === 'owner' && ! $this->actorIsOwner($request)) {
            return Problem::response('forbidden', 'Only owners can grant the owner role', 403);
        }

        $tenant = $this->tenant();
        $user = User::query()->firstOrCreate(['email' => strtolower($data['email'])], [
            'name' => $data['name'] ?? Str::before($data['email'], '@'),
            'password' => Str::password(32),
            'current_tenant_id' => $tenant->id,
        ]);
        if ($tenant->users()->whereKey($user->id)->exists()) {
            return Problem::response('conflict', 'Already a member', 409);
        }
        $tenant->users()->attach($user->id, ['role' => $data['role']]);
        if ($user->wasRecentlyCreated) {
            Password::sendResetLink(['email' => $user->email]);
        }
        AuditLog::record('member.added', $user, ['role' => $data['role']]);

        return response()->json(['data' => $this->present($tenant->users()->whereKey($user->id)->firstOrFail())], 201);
    }

    public function update(Request $request, string $member): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(self::ROLES)]]);
        $user = $this->member($member);
        $current = $user->pivot->role;

        if (($data['role'] === 'owner' || $current === 'owner') && ! $this->actorIsOwner($request)) {
            return Problem::response('forbidden', 'Only owners can change owner roles', 403);
        }
        if ($current === 'owner' && $data['role'] !== 'owner' && $this->ownerCount() <= 1) {
            return Problem::response('last_owner', 'A tenant must keep at least one owner', 409);
        }

        $this->tenant()->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        AuditLog::record('member.role_changed', $user, ['from' => $current, 'to' => $data['role']]);

        return response()->json(['data' => $this->present($this->member($member))]);
    }

    public function destroy(Request $request, string $member): Response|JsonResponse
    {
        $user = $this->member($member);
        if ($user->pivot->role === 'owner') {
            if (! $this->actorIsOwner($request)) {
                return Problem::response('forbidden', 'Only owners can remove owners', 403);
            }
            if ($this->ownerCount() <= 1) {
                return Problem::response('last_owner', 'A tenant must keep at least one owner', 409);
            }
        }

        $this->tenant()->users()->detach($user->id);
        if ($user->current_tenant_id === $this->tenant()->id) {
            $user->update(['current_tenant_id' => $user->tenants()->value('tenants.id')]);
        }
        $user->tokens()->delete();
        AuditLog::record('member.removed', $user, ['role' => $user->pivot->role]);

        return response()->noContent();
    }

    private function member(string $ulid): User
    {
        $user = $this->tenant()->users()->where('users.ulid', $ulid)->first();
        abort_if(! $user, 404);

        return $user;
    }

    private function ownerCount(): int
    {
        return $this->tenant()->users()->wherePivot('role', 'owner')->count();
    }

    /** API keys with `admin` act as owners (they are tenant-level credentials). */
    private function actorIsOwner(Request $request): bool
    {
        return $request->attributes->get('api_key') !== null || $request->attributes->get('tenant_role') === 'owner';
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        return ['id' => $u->ulid, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role,
            'joined_at' => $u->pivot->created_at?->toIso8601ZuluString()];
    }
}
