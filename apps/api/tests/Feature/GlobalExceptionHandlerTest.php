<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SRS 3.4.5: error global ber-envelope JSON Bahasa Indonesia, hanya untuk
 * request yang berkomunikasi via Accept: application/json.
 */
class GlobalExceptionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_salahan_mengembalikan_envelope_endpoint_tidak_ditemukan(): void
    {
        // Frontend salah memanggil route backlogs (huruf 's')
        $response = $this->getJson('/api/v1/maintenance-backlogs');

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Endpoint tidak ditemukan.', 'data' => null]);
    }

    public function test_envelope_404_hanya_untuk_request_json(): void
    {
        // Tanpa Accept: application/json, envelope Bahasa Indonesia tidak berlaku;
        // request tetap 404 (format default Laravel), tidak ada pesan teknis thatberupa enum_ident.
        $response = $this->get('/api/v1/maintenance-backlogs');

        $response->assertStatus(404);
        $this->assertNotSame('Endpoint tidak ditemukan.', $response->json('message'));
    }

    public function test_sqlstate_mengembalikan_envelope_kesalahan_database_tanpa_detail_teknis(): void
    {
        // Route sementara yang melempar QueryException asli (tabel tidak ada)
        Route::get('/api/v1/__db-boom', fn () => DB::select('select * from tabel_tidak_ada_xyz'))
            ->middleware(['auth', 'role:Tim Maintenance']);

        $response = $this->actingAs(\App\Models\Pengguna::query()->create([
            'nama' => 'Budi Tim', 'email' => 'probe'.time().'@maintaingov.test',
            'password' => Hash::make('password123'), 'role' => 'Tim Maintenance',
        ]))->getJson('/api/v1/__db-boom');

        $response->assertStatus(500)
            ->assertJson(['success' => false, 'message' => 'Terjadi kesalahan pada database.', 'data' => null]);
        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body,
            'Detail SQLSTATE tidak boleh ikut terkirim ke client.');
    }

    public function test_envelope_database_hanya_untuk_request_json(): void
    {
        Route::get('/api/v1/__db-boom-plain', fn () => DB::select('select * from tabel_tidak_ada_xyz'));

        $response = $this->get('/api/v1/__db-boom-plain');

        $response->assertStatus(500);
        $this->assertNotSame('Terjadi kesalahan pada database.', $response->json('message'));
    }
}
