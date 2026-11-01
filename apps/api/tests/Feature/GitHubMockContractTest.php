<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubMockContractTest extends TestCase
{
    use RefreshDatabase;

    private Pengguna $developer;

    private Pengguna $maintenance;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.mock_enabled' => true]);
        Http::preventStrayRequests();

        $this->developer = Pengguna::create([
            'nama' => 'Developer Mock',
            'email' => 'developer.mock@github.test',
            'password' => Hash::make('password123'),
            'role' => 'Developer',
        ]);

        $this->maintenance = Pengguna::create([
            'nama' => 'Maintenance Mock',
            'email' => 'maintenance.mock@github.test',
            'password' => Hash::make('password123'),
            'role' => 'Tim Maintenance',
        ]);
    }

    public function test_mock_routes_require_developer_role(): void
    {
        $this->getJson('/api/v1/developer/github-mock/issues')->assertUnauthorized();

        $this->actingAs($this->maintenance)
            ->getJson('/api/v1/developer/github-mock/issues')
            ->assertForbidden();
    }

    public function test_issue_list_is_deterministic_and_marked_as_mock(): void
    {
        $response = $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues');

        $response->assertOk()
            ->assertHeader('X-Mock-Data', 'true')
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.is_mock', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.issue_id', 101)
            ->assertJsonPath('data.1.status', 'Ready for Pull Request')
            ->assertJsonPath('data.2.status', 'Sedang Dikerjakan');

        Http::assertNothingSent();
    }

    public function test_issue_list_supports_empty_status_filter(): void
    {
        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues?status='.urlencode('Ready for Merge'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.is_mock', true);
    }

    public function test_issue_detail_contains_repository_and_branch_information(): void
    {
        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/102')
            ->assertOk()
            ->assertJsonPath('data.issue_id', 102)
            ->assertJsonPath('data.repository.full_name', 'AgilePemdigi-2026/MaintainGov')
            ->assertJsonPath('data.source_branch', 'fix/login-session')
            ->assertJsonPath('data.push_status', 'detected')
            ->assertJsonPath('data.is_mock', true);
    }

    public function test_claim_success_is_mock_only(): void
    {
        $response = $this->actingAs($this->developer)
            ->postJson('/api/v1/developer/github-mock/issues/101/claim');

        $response->assertOk()
            ->assertHeader('X-Mock-Data', 'true')
            ->assertJsonPath('data.status', 'Sedang Dikerjakan')
            ->assertJsonPath('data.assigned_to.user_id', $this->developer->user_id)
            ->assertJsonPath('data.is_mock', true);

        $this->assertStringContainsString('tidak benar-benar diubah', (string) $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_claim_conflict_uses_srs_message(): void
    {
        $this->actingAs($this->developer)
            ->postJson('/api/v1/developer/github-mock/issues/103/claim')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_ALREADY_ASSIGNED')
            ->assertJsonPath('error.message', 'Issue telah diambil Developer lain');
    }

    public function test_push_status_is_read_only_and_explains_external_push(): void
    {
        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/101/push-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'not_detected')
            ->assertJsonPath('data.branch', null)
            ->assertJsonPath('data.is_mock', true);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/102/push-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'detected')
            ->assertJsonPath('data.last_push.commit', 'mock-commit-login-001');
    }

    public function test_branches_require_ready_for_pull_request_status(): void
    {
        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/102/branches')
            ->assertOk()
            ->assertJsonPath('data.source_branch', 'fix/login-session')
            ->assertJsonPath('data.suggested_target_branch', 'main')
            ->assertJsonFragment(['name' => 'main', 'is_default' => true])
            ->assertJsonPath('data.is_mock', true);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/101/branches')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_READY_FOR_PULL_REQUEST');
    }

    public function test_pull_request_list_has_empty_and_open_states(): void
    {
        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/101/pull-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues/102/pull-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'Open')
            ->assertJsonPath('data.0.github_pr_number', null)
            ->assertJsonPath('data.0.github_pr_url', null)
            ->assertJsonPath('data.0.is_mock', true);
    }

    public function test_create_pull_request_is_a_stub_and_never_calls_github(): void
    {
        $response = $this->actingAs($this->developer)
            ->postJson('/api/v1/developer/github-mock/issues/102/pull-requests', [
                'source_branch' => 'fix/login-session',
                'target_branch' => 'main',
                'title' => 'Perbaikan sesi login',
                'description' => 'Menyelaraskan masa berlaku sesi dengan konfigurasi aplikasi.',
            ]);

        $response->assertStatus(201)
            ->assertHeader('X-Mock-Data', 'true')
            ->assertJsonPath('data.status', 'Open')
            ->assertJsonPath('data.github_pr_number', null)
            ->assertJsonPath('data.github_pr_url', null)
            ->assertJsonPath('data.is_mock', true)
            ->assertJsonPath('data.created_by.user_id', $this->developer->user_id);

        $this->assertStringContainsString('tidak dibuat di GitHub', (string) $response->json('message'));
        Http::assertNothingSent();
    }

    public function test_create_pull_request_validates_branch_and_issue_status(): void
    {
        $this->actingAs($this->developer)
            ->postJson('/api/v1/developer/github-mock/issues/102/pull-requests', [
                'source_branch' => 'wrong-branch',
                'target_branch' => 'main',
                'title' => 'Judul',
                'description' => 'Deskripsi',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_branch');

        $this->actingAs($this->developer)
            ->postJson('/api/v1/developer/github-mock/issues/101/pull-requests', [
                'source_branch' => 'fix/login-session',
                'target_branch' => 'main',
                'title' => 'Judul',
                'description' => 'Deskripsi',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ISSUE_NOT_READY_FOR_PULL_REQUEST');
    }

    public function test_mock_routes_can_be_disabled(): void
    {
        config(['services.github.mock_enabled' => false]);

        $this->actingAs($this->developer)
            ->getJson('/api/v1/developer/github-mock/issues')
            ->assertNotFound();
    }
}
