<?php

namespace Tests\Feature\Central;

use App\Models\PlatformUser;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * Exercises the real token auth path rather than actingAs(), which sets the
 * request user resolver directly and therefore hides whether the central
 * middleware actually binds the authenticated user for downstream controllers.
 */
class CentralAuthResolutionTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.central_domain' => 'central.mfukopro.test',
            'app.central_domains' => ['central.mfukopro.test'],
        ]);
    }

    private function centralUser(): PlatformUser
    {
        $user = new PlatformUser;
        $user->setConnection('master');
        $user->name = 'Token Admin';
        $user->email = 'token-'.uniqid().'@mfukopro.test';
        $user->password = 'password123';
        $user->status = 'active';
        $user->role_id = 'super-admin';
        $user->save();

        return $user;
    }

    private function headers(PlatformUser $user): array
    {
        return [
            'X-Forwarded-Host' => 'central.mfukopro.test',
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    public function test_request_user_is_resolved_for_profile_show(): void
    {
        $user = $this->centralUser();

        $response = $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/central/profile');

        $response->assertOk();
        $response->assertJsonPath('payload.id', $user->id);
    }

    public function test_request_user_is_resolved_for_profile_update(): void
    {
        // Regression: $request->user() was null under real token auth because the
        // central middleware resolved the user without binding it to the request,
        // producing "Attempt to read property \"id\" on null".
        $user = $this->centralUser();

        $response = $this->withHeaders($this->headers($user))
            ->postJson('/api/v1/central/profile/update', [
                'staff_fall_name' => 'Renamed Via Token',
            ]);

        $response->assertOk();
        $this->assertSame('Renamed Via Token', $user->fresh()->name);
    }

    public function test_request_user_is_resolved_for_me(): void
    {
        $user = $this->centralUser();

        $response = $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/central/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.id', $user->id);
    }

    public function test_me_exposes_a_resolvable_avatar_url(): void
    {
        // The top-bar avatar hydrates from /me. Without an appended avatar_url the
        // client only sees the bare storage path and renders a broken image.
        $user = $this->centralUser();
        $user->avatar = 'avatars/example.jpg';
        $user->save();

        $response = $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/central/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.avatar_url', config('app.url').'/storage/avatars/example.jpg');
    }

    public function test_me_avatar_url_is_null_without_an_avatar(): void
    {
        $user = $this->centralUser();

        $response = $this->withHeaders($this->headers($user))
            ->getJson('/api/v1/central/me');

        $response->assertOk();
        $response->assertJsonPath('data.user.avatar_url', null);
    }
}
