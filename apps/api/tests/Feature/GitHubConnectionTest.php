<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'feature-test-token-not-real';

    private Pengguna $maintenance;

    private Pengguna $developer;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.api_url' => 'https://api.github.test',
            'services.github.owner' => 'AgilePemdigi-2026',
            'services.github.repository' => 'MaintainGov',
            'services.github.token' => self::TOKEN,
            'services.github.timeout' => 10,
            'services.github.connect_timeout' => 5,
        ]);

        Http::preventStrayRequests();

        $this->maintenance = Pengguna::create([
            'nama' => 'Tim Maintenance',
            'email' => 'maintenance@github.test',
            'password' => Hash::make('password123'),
            'role' => 'Tim Maintenance',
        ]);

        $this->developer = Pengguna::create([
            'nama' => 'Developer',
            'email' => 'developer@github.test',
            'password' => Hash::make('password123'),
            'role' => 'Developer',
        ]);
    }

    public function test_connection_check_rejects_guest(): void
    {
        $this->getJson('/api/v1/github/connection')->assertUnauthorized();
    }

    public function test_authenticated_roles_receive_connected_status_without_token(): void
    {
        Http::fake(['*' => Http::response($this->payload())]);

        foreach ([$this->maintenance, $this->developer] as $user) {
            $response = $this->actingAs($user)->getJson('/api/v1/github/connection');

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.status', 'connected')
                ->assertJsonPath('data.repository.owner', 'AgilePemdigi-2026')
                ->assertJsonPath('data.repository.name', 'MaintainGov')
                ->assertJsonPath('data.repository.full_name', 'AgilePemdigi-2026/MaintainGov')
                ->assertJsonPath('data.repository.default_branch', 'main');

            $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        }
    }

    public function test_github_401_is_returned_as_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/github/connection')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'GITHUB_UNAUTHORIZED')
            ->assertJsonPath('data.status', 'unauthorized')
            ->assertJsonMissingPath('data.repository');
    }

    public function test_github_403_is_returned_as_403_forbidden(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Resource not accessible'], 403)]);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/github/connection')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'GITHUB_FORBIDDEN')
            ->assertJsonPath('data.status', 'forbidden');
    }

    public function test_github_rate_limit_is_returned_as_429_with_retry_after(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '60'])]);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/github/connection')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('error.code', 'GITHUB_RATE_LIMITED')
            ->assertJsonPath('error.retry_after_seconds', 60)
            ->assertJsonPath('data.status', 'rate_limited');
    }

    public function test_network_failure_is_returned_as_safe_503(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out',
        ));

        $response = $this->actingAs($this->developer)->getJson('/api/v1/github/connection');

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'GITHUB_UNAVAILABLE')
            ->assertJsonPath('data.status', 'unavailable');

        $this->assertStringNotContainsString('cURL', $response->getContent());
    }

    public function test_missing_credential_is_safe_and_does_not_call_github(): void
    {
        config(['services.github.token' => '']);

        $response = $this->actingAs($this->developer)->getJson('/api/v1/github/connection');

        $response->assertStatus(503)
            ->assertJsonPath('error.code', 'GITHUB_NOT_CONFIGURED')
            ->assertJsonPath('data.status', 'unavailable');

        Http::assertNothingSent();
    }

    public function test_repository_not_found_is_returned_as_404(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/github/connection')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'GITHUB_REPOSITORY_NOT_FOUND')
            ->assertJsonPath('data.status', 'not_found');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'id' => 123456,
            'name' => 'MaintainGov',
            'full_name' => 'AgilePemdigi-2026/MaintainGov',
            'owner' => ['login' => 'AgilePemdigi-2026'],
            'private' => false,
            'html_url' => 'https://github.com/AgilePemdigi-2026/MaintainGov',
            'default_branch' => 'main',
            'permissions' => ['triage' => true, 'pull' => true],
        ];
    }
}
