<?php

namespace Tests\Feature\Central;

use App\Models\PlatformUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class CentralProfileTest extends TestCase
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

    private function centralUser(array $overrides = []): PlatformUser
    {
        // role_id and status are not in $fillable, so they are assigned directly
        // rather than mass-assigned, otherwise they would be silently dropped.
        $attributes = array_merge([
            'name' => 'Platform Admin',
            'email' => 'admin-'.uniqid().'@mfukopro.test',
            'password' => 'password123',
            'status' => 'active',
            'role_id' => 'super-admin',
        ], $overrides);

        $user = new PlatformUser;
        $user->setConnection('master');

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        $user->save();

        return $user;
    }

    private function asCentral(PlatformUser $user): self
    {
        $this->actingAs($user, 'platform');

        return $this;
    }

    public function test_profile_update_accepts_post(): void
    {
        // Regression: POST api/v1/central/profile/update previously returned 405
        // "The POST method is not supported" because no such route existed and the
        // SPA catch-all in web.php matched the URI for GET only.
        $user = $this->centralUser();

        $response = $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/profile/update', [
                'staff_fall_name' => 'Updated Name',
            ]);

        $response->assertOk();
        $this->assertSame('Updated Name', $response->json('payload.staff_fall_name'));
        $this->assertSame('Updated Name', $user->fresh()->name);
    }

    public function test_profile_show_returns_mapped_payload(): void
    {
        $user = $this->centralUser(['name' => 'Jane Platform']);

        $response = $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->getJson('/api/v1/central/profile');

        $response->assertOk();
        $response->assertJsonPath('payload.staff_fall_name', 'Jane Platform');
        $response->assertJsonPath('payload.staff_email', $user->email);
        $response->assertJsonPath('payload.system_role', 'super-admin');
    }

    public function test_password_is_hashed_on_update(): void
    {
        $user = $this->centralUser();

        $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/profile/update', ['password' => 'new-secret-123'])
            ->assertOk();

        $fresh = $user->fresh();
        $this->assertNotSame('new-secret-123', $fresh->password);
        $this->assertTrue(Hash::check('new-secret-123', $fresh->password));
    }

    public function test_avatar_upload_is_stored_and_url_returned(): void
    {
        Storage::fake('public');
        $user = $this->centralUser();

        $response = $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->post('/api/v1/central/profile/update', [
                'avatar' => UploadedFile::fake()->image('me.jpg'),
            ], ['Accept' => 'application/json']);

        $response->assertOk();
        $path = $user->fresh()->avatar;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('payload.avatar_url'));
    }

    public function test_role_cannot_be_escalated_from_profile(): void
    {
        $user = $this->centralUser(['role_id' => 'editor']);

        $response = $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/profile/update', [
                'system_role' => 'super-admin',
            ]);

        $response->assertStatus(422);
        $this->assertSame('editor', $user->fresh()->role_id);
    }

    public function test_last_active_platform_account_cannot_delete_itself(): void
    {
        PlatformUser::on('master')->update(['status' => 'inactive']);
        $user = $this->centralUser();

        $response = $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/profile/delete');

        $response->assertStatus(422);
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_profile_delete_soft_deletes_when_others_remain(): void
    {
        $this->centralUser(['name' => 'Other Admin']);
        $user = $this->centralUser();

        $response = $this->asCentral($user)
            ->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/profile/delete');

        $response->assertOk();
        $this->assertNotNull($user->fresh()->deleted_at);
    }
}
