<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function userIn($tenant, string $role = 'owner', string $email = 'owner@example.com'): User
{
    $user = User::query()->create(['name' => 'U', 'email' => $email, 'password' => 'correct-horse-battery', 'current_tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id, ['role' => $role]);

    return $user;
}

it('issues personal access tokens that resolve the user\'s current tenant', function () {
    [$tenant] = tenantWithKey();
    userIn($tenant);

    $this->postJson('/api/v1/auth/token', ['email' => 'owner@example.com', 'password' => 'wrong', 'device_name' => 'cli'])->assertStatus(422);
    $token = $this->postJson('/api/v1/auth/token', ['email' => 'owner@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'cli'])
        ->assertCreated()->assertJsonPath('data.user.tenants.0.role', 'owner')->json('data.token');

    $this->getJson('/api/v1/auth/me', bearer($token))->assertOk()->assertJsonPath('data.current_tenant_id', $tenant->ulid);
    $this->getJson('/api/v1/searches', bearer($token))->assertOk();
    $this->getJson('/api/v1/api-keys', bearer($token))->assertOk(); // owner → admin abilities

    $this->postJson('/api/v1/auth/logout', [], bearer($token))->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/auth/me', bearer($token))->assertStatus(401);
});

it('maps member roles to abilities', function () {
    [$tenant] = tenantWithKey();
    userIn($tenant, 'viewer', 'viewer@example.com');
    $token = $this->postJson('/api/v1/auth/token', ['email' => 'viewer@example.com', 'password' => 'correct-horse-battery', 'device_name' => 'x'])->json('data.token');

    $this->getJson('/api/v1/searches', bearer($token))->assertOk();
    $this->postJson('/api/v1/searches', ['title' => 'x'], bearer($token))->assertStatus(403);
});

it('switches only to tenants the user belongs to', function () {
    [$a] = tenantWithKey();
    [$b] = tenantWithKey();
    [$c] = tenantWithKey();
    $user = userIn($a);
    $b->users()->attach($user->id, ['role' => 'member']);
    $token = $user->createToken('t')->plainTextToken;

    $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $b->ulid], bearer($token))->assertOk()->assertJsonPath('data.current_tenant_id', $b->ulid);
    $this->postJson('/api/v1/auth/switch-tenant', ['tenant_id' => $c->ulid], bearer($token))->assertStatus(404);
});

it('keeps self-signup disabled by default and supports it when enabled', function () {
    tenantWithKey(); // seeds plans
    $payload = ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1', 'company' => 'Ana Co'];

    $this->postJson('/api/v1/auth/register', $payload)->assertStatus(403);

    config(['pricelens.auth.self_signup' => true]);
    $res = $this->postJson('/api/v1/auth/register', $payload)->assertCreated();
    expect($res->json('data.api_key'))->toStartWith('pl_live_')->and($res->json('data.user.tenants.0'))->toMatchArray(['role' => 'owner', 'plan' => 'starter']);
});

it('manages members with owner safeguards and audit logs', function () {
    Notification::fake();
    [$tenant, $key] = tenantWithKey();
    $owner = userIn($tenant);
    $adminToken = (function () use ($tenant) {
        $admin = userIn($tenant, 'admin', 'admin@example.com');

        return $admin->createToken('t')->plainTextToken;
    })();

    $new = $this->postJson('/api/v1/members', ['email' => 'new@example.com', 'role' => 'member'], bearer($key))->assertCreated()->json('data');
    Notification::assertSentTo(User::query()->where('email', 'new@example.com')->first(), ResetPassword::class);
    $this->postJson('/api/v1/members', ['email' => 'new@example.com', 'role' => 'member'], bearer($key))->assertStatus(409);

    // Admins can't touch owners; the last owner can't be demoted.
    $this->patchJson("/api/v1/members/{$owner->ulid}", ['role' => 'viewer'], bearer($adminToken))->assertStatus(403);
    $this->patchJson("/api/v1/members/{$new['id']}", ['role' => 'owner'], bearer($adminToken))->assertStatus(403);
    $this->patchJson("/api/v1/members/{$owner->ulid}", ['role' => 'admin'], bearer($key))->assertStatus(409);

    $this->patchJson("/api/v1/members/{$new['id']}", ['role' => 'viewer'], bearer($adminToken))->assertOk()->assertJsonPath('data.role', 'viewer');
    $this->deleteJson("/api/v1/members/{$new['id']}", [], bearer($key))->assertNoContent();
    $this->getJson('/api/v1/members', bearer($key))->assertJsonCount(2, 'data');

    expect(AuditLog::acrossTenants()->where('tenant_id', $tenant->id)->pluck('action')->all())
        ->toContain('member.added', 'member.role_changed', 'member.removed');
});

it('resets passwords via emailed tokens', function () {
    Notification::fake();
    [$tenant] = tenantWithKey();
    $user = userIn($tenant);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(202);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@example.com'])->assertStatus(202);

    $token = Password::createToken($user);
    $this->postJson('/api/v1/auth/reset-password', ['email' => 'owner@example.com', 'token' => $token,
        'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertOk();
    $this->postJson('/api/v1/auth/token', ['email' => 'owner@example.com', 'password' => 'brand-new-password', 'device_name' => 'x'])->assertCreated();
});
