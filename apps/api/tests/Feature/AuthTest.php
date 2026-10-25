<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Pengguna $timMaintenance;
    private Pengguna $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->timMaintenance = Pengguna::create([
            'nama' => 'Budi Tim Maintenance',
            'email' => 'tim@maintaingov.test',
            'password' => Hash::make('password123'),
            'role' => 'Tim Maintenance',
        ]);

        $this->developer = Pengguna::create([
            'nama' => 'Andi Developer',
            'email' => 'dev@maintaingov.test',
            'password' => Hash::make('password123'),
            'role' => 'Developer',
        ]);
    }

    public function test_login_berhasil_dengan_credential_valid(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'tim@maintaingov.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Login berhasil.',
                'data' => [
                    'user_id' => $this->timMaintenance->user_id,
                    'nama' => 'Budi Tim Maintenance',
                    'email' => 'tim@maintaingov.test',
                    'role' => 'Tim Maintenance',
                ],
            ]);

        $this->assertAuthenticatedAs($this->timMaintenance);
    }

    public function test_login_ditolak_jika_field_kosong(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '',
            'password' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_login_ditolak_dengan_email_salah(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'salah@maintaingov.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Email atau password salah.',
            ]);

        $this->assertGuest();
    }

    public function test_login_ditolak_dengan_password_salah(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'tim@maintaingov.test',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Email atau password salah.',
            ]);

        $this->assertGuest();
    }

    public function test_me_mengembalikan_identitas(): void
    {
        $response = $this->actingAs($this->timMaintenance)->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => $this->timMaintenance->user_id,
                    'nama' => 'Budi Tim Maintenance',
                    'role' => 'Tim Maintenance',
                ],
            ]);
    }

    public function test_me_ditolak_tanpa_sesi(): void
    {
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }

    public function test_logout_berhasil(): void
    {
        $response = $this->actingAs($this->timMaintenance)->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Logout berhasil.',
            ]);

        $this->assertGuest();
    }

    public function test_sesi_tidak_aktif_setelah_logout(): void
    {
        $this->actingAs($this->timMaintenance)->postJson('/api/v1/auth/logout');
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }

    public function test_developer_ditolak_akses_tim_maintenance(): void
    {
        $response = $this->actingAs($this->developer)->getJson('/api/v1/tim-maintenance/dashboard');
        $response->assertStatus(403);
    }

    public function test_tim_maintenance_berhasil_akses_dashboard(): void
    {
        $response = $this->actingAs($this->timMaintenance)->getJson('/api/v1/tim-maintenance/dashboard');
        $response->assertStatus(200);
    }

    public function test_csrf_protection_aktif(): void
    {
        $this->app->instance('env', 'local');
        $this->postJson('/api/v1/auth/login', [])->assertStatus(419);
        $this->withSession(['_token' => 'csrf-test-token'])
            ->withHeader('X-CSRF-TOKEN', 'csrf-test-token')
            ->postJson('/api/v1/auth/login', [])->assertStatus(422);
    }

    public function test_remember_token_dirotasi_saat_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'tim@maintaingov.test',
            'password' => 'password123',
            'remember' => true,
        ]);
        $response->assertStatus(200);

        $tokenSebelum = $this->timMaintenance->fresh()->remember_token;
        $this->assertNotNull($tokenSebelum, 'Token remember harus ter-set saat login dengan remember=true');

        $this->postJson('/api/v1/auth/logout')->assertStatus(200);

        $tokenSesudah = $this->timMaintenance->fresh()->remember_token;
        $this->assertNotEquals($tokenSebelum, $tokenSesudah, 'Token remember harus berubah setelah logout');
    }

    public function test_sesi_lama_tidak_berlaku_setelah_logout(): void
    {
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'tim@maintaingov.test',
            'password' => 'password123',
        ]);
        $loginResponse->assertStatus(200);

        $sessionCookie = collect($loginResponse->headers->getCookies())
            ->first(fn ($cookie) => str_contains($cookie->getName(), 'session'));

        $this->assertNotNull($sessionCookie, 'Cookie sesi harus ada setelah login');

        $this->postJson('/api/v1/auth/logout');

        $this->withCookies([
            $sessionCookie->getName() => $sessionCookie->getValue(),
        ])->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
