<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaintenanceBacklogTest extends TestCase
{
    use RefreshDatabase;

    private array $akun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akun = [
            'tim' => $this->buatPengguna('Tim Maintenance'),
            'developer' => $this->buatPengguna('Developer'),
        ];
    }

    /** ------------ Helper ------------ */
    private function buatPengguna(string $role): int
    {
        return DB::table('pengguna_sistem')->insertGetId([
            'nama' => "Pengguna $role",
            'email' => strtolower(str_replace(' ', '', $role)).'@'.microtime(true).'maintaingov.test',
            'password' => Hash::make('password123'),
            'role' => $role,
        ], 'user_id');
    }

    private function buatBacklog(array $caseOverride = [], array $backlogOverride = []): array
    {
        $caseId = DB::table('maintenance_case')->insertGetId(array_merge([
            'judul_issue' => 'Notifikasi tidak muncul',
            'kategori' => 'defect', 'modul' => 'Notifikasi', 'fitur' => 'Push Notifikasi',
            'severity' => 'High', 'priority_score' => 9.00,
            'ringkasan_masalah' => 'Notifikasi update layanan tidak diterima warga.',
            'status_validasi' => 'Valid', 'created_at' => now(),
        ], $caseOverride), 'case_id');

        $backlogId = DB::table('maintenance_backlog')->insertGetId(array_merge([
            'case_id' => $caseId, 'status' => 'Ready for Development',
            'created_at' => now(), 'updated_at' => now(),
        ], $backlogOverride), 'backlog_id');

        return ['case_id' => $caseId, 'backlog_id' => $backlogId];
    }

    private function tim()
    {
        return $this->actingAs(Pengguna::query()->findOrFail($this->akun['tim']));
    }

    private function developer()
    {
        return $this->actingAs(Pengguna::query()->findOrFail($this->akun['developer']));
    }

    /** ------------ List & urutan & paginasi ------------ */
    public function test_list_urutan_prioritas_high_medium_low_lalu_backlog_id(): void
    {
        $high = $this->buatBacklog(['severity' => 'High', 'priority_score' => 9.00]);
        $medium = $this->buatBacklog(['severity' => 'Medium', 'priority_score' => 5.50]);
        $low = $this->buatBacklog(['severity' => 'Low', 'priority_score' => 2.00]);
        $high2 = $this->buatBacklog(['severity' => 'High', 'priority_score' => 8.00]);

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'meta' => ['total' => 4]]);

        // High dulu (skor tertinggi dulu), lalu Medium, lalu Low; High dengan skor tauan mengikuti backlog_id
        $urutan = collect($response->json('data'))->pluck('backlog_id')->all();
        $this->assertSame([
            $high['backlog_id'], $high2['backlog_id'], $medium['backlog_id'], $low['backlog_id'],
        ], $urutan);
    }

    public function test_list_paginasi_per_page_maks_100(): void
    {
        $this->buatBacklog();

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog?per_page=100');
        $response->assertStatus(200)->assertJson(['meta' => ['per_page' => 100]]);

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog?per_page=101');
        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_list_filter_status_opsional_tervalidasi(): void
    {
        $aktif = $this->buatBacklog();
        $this->buatBacklog(backlogOverride: ['status' => 'Selesai']);

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog?status=Sedang Dikerjakan');
        $response->assertStatus(200)->assertJson(['meta' => ['total' => 0]]);

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog?status=Selesai');
        $response->assertStatus(200)->assertJson(['meta' => ['total' => 1]]);

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog?status=NggakJelas');
        $response->assertStatus(422)->assertJson(['success' => false]);
    }

    /** ------------ Detail ------------ */
    public function test_detail_menampilkan_backlog_dan_ringkasan_case(): void
    {
        $ids = $this->buatBacklog();

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog/'.$ids['backlog_id']);

        $response->assertStatus(200)->assertJson(['success' => true, 'message' => 'Detail maintenance backlog.']);
        $data = $response->json('data');
        $this->assertEquals($ids['backlog_id'], $data['backlog_id']);
        $this->assertEquals($ids['case_id'], $data['case_id']);
        $this->assertSame('Ready for Development', $data['status']);
        $this->assertSame('Notifikasi update layanan tidak diterima warga.', $data['ringkasan_masalah']);
    }

    public function test_detail_tidak_ditemukan_404_bahasa_indonesia(): void
    {
        $response = $this->tim()->getJson('/api/v1/maintenance-backlog/99999');

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Backlog tidak ditemukan.', 'data' => null]);
    }

    /** ------------ PATCH ------------ */
    public function test_patch_sukses_mengubah_field_dan_updated_at_backlog(): void
    {
        $ids = $this->buatBacklog(backlogOverride: ['updated_at' => '2020-01-01 00:00:00']);
        $updatedAtAwal = DB::table('maintenance_backlog')->where('backlog_id', $ids['backlog_id'])->value('updated_at');

        $response = $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'priority' => 'Medium',
            'severity' => 'Medium',
            'modul' => 'Modul Notifikasi',
            'ringkasan_masalah' => 'Ringkasan hasil edit Tim Maintenance.',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Backlog diperbarui.']);
        $this->assertEquals(5.50, $response->json('data.case.priority_score'));
        $this->assertSame('Ringkasan hasil edit Tim Maintenance.', $response->json('data.case.ringkasan_masalah'));
        $this->assertEquals($ids['backlog_id'], $response->json('data.backlog_id'));

        $case = DB::table('maintenance_case')->where('case_id', $ids['case_id'])->first();
        $this->assertSame('Ringkasan hasil edit Tim Maintenance.', $case->ringkasan_masalah);
        $this->assertSame('Modul Notifikasi', $case->modul);
        $this->assertSame('Medium', $case->severity);
        $this->assertEquals(5.50, (float) $case->priority_score);

        $backlog = DB::table('maintenance_backlog')->where('backlog_id', $ids['backlog_id'])->first();
        $this->assertSame('Ready for Development', $backlog->status);
        $this->assertNotEquals($updatedAtAwal, $backlog->updated_at, 'updated_at backlog harus berubah via timestamps.');
        $this->assertNotNull($backlog->updated_at);
    }

    public function test_patch_ringkasan_masalah_wajib_dan_priority_terbatas(): void
    {
        $ids = $this->buatBacklog();

        $response = $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'modul' => 'Modul Notifikasi',
        ]);
        $response->assertStatus(422)->assertJson(['success' => false]);

        $response = $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'priority' => 'Kritis',
            'ringkasan_masalah' => 'Ringkasan baru.',
        ]);
        $response->assertStatus(422)->assertJson(['success' => false]);

        // Temuan audit #2: pesan detail harus Bahasa Indonesia dan menyebut field
        $pesanPrioritas = (array) $response->json('errors.priority');
        $this->assertNotEmpty($pesanPrioritas);
        $this->assertStringContainsString(
            'Nilai priority harus salah satu dari',
            (string) reset($pesanPrioritas),
            'Pesan error priority harus Bahasa Indonesia dan menyebut field priority.'
        );
    }

    public function test_patch_menerima_field_kontrak_frontend_dan_skor_langsung(): void
    {
        $ids = $this->buatBacklog();

        $response = $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'judul' => 'Judul hasil integrasi frontend',
            'modul' => 'Modul hasil integrasi',
            'fitur' => 'Fitur hasil integrasi',
            'severity' => 'Low',
            'priority_score' => 3.40,
            'ringkasan_masalah' => 'Ringkasan hasil integrasi frontend.',
            'recommendation' => 'Rekomendasi hasil integrasi frontend.',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Backlog diperbarui.']);

        $case = DB::table('maintenance_case')->where('case_id', $ids['case_id'])->first();
        $this->assertSame('Judul hasil integrasi frontend', $case->judul_issue);
        $this->assertSame('Modul hasil integrasi', $case->modul);
        $this->assertSame('Fitur hasil integrasi', $case->fitur);
        $this->assertSame('Low', $case->severity);
        $this->assertEquals(3.40, (float) $case->priority_score);
        $this->assertSame('Ringkasan hasil integrasi frontend.', $case->ringkasan_masalah);
        $this->assertSame('Rekomendasi hasil integrasi frontend.', $case->recommendation);
    }

    public function test_patch_dapat_mengosongkan_recommendation(): void
    {
        $ids = $this->buatBacklog(['recommendation' => 'Rekomendasi awal.']);

        $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'ringkasan_masalah' => 'Ringkasan baru.',
            'recommendation' => null,
        ])->assertStatus(200);

        $this->assertNull(
            DB::table('maintenance_case')->where('case_id', $ids['case_id'])->value('recommendation'),
        );
    }

    public function test_patch_lalu_get_membaca_kembali_nilai_sama(): void
    {
        // W13-01: write-read verify — hasil PATCH harus terbaca kembali
        // identik lewat GET detail (sumber bacaan frontend).
        $ids = $this->buatBacklog(backlogOverride: ['updated_at' => '2020-01-01 00:00:00']);
        $updatedAtAwal = DB::table('maintenance_backlog')
            ->where('backlog_id', $ids['backlog_id'])->value('updated_at');

        $payload = [
            'judul' => 'Judul write-read verify',
            'modul' => 'Modul verify',
            'fitur' => 'Fitur verify',
            'severity' => 'Medium',
            'priority' => 'Medium',
            'ringkasan_masalah' => 'Ringkasan write-read verify.',
            'recommendation' => 'Rekomendasi verify.',
        ];

        $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], $payload)
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Backlog diperbarui.']);

        $response = $this->tim()->getJson('/api/v1/maintenance-backlog/'.$ids['backlog_id']);
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame($payload['judul'], $data['judul_issue']);
        $this->assertSame($payload['modul'], $data['modul']);
        $this->assertSame($payload['fitur'], $data['fitur']);
        $this->assertSame($payload['severity'], $data['severity']);
        $this->assertSame($payload['ringkasan_masalah'], $data['ringkasan_masalah']);
        $this->assertSame($payload['recommendation'], $data['recommendation']);
        // priority label Medium dimap ke bucket D-W12-02-01
        $this->assertEquals(5.50, (float) $data['priority_score']);
        // updated_at backlog berubah dan dibaca ulang oleh GET
        $this->assertNotEquals($updatedAtAwal, $data['updated_at'],
            'GET membaca updated_at backlog hasil PATCH via timestamps.');
    }

    public function test_list_status_kosong_menampilkan_semua_backlog(): void
    {
        // Hardening W13-01: ?status= (nilai kosong) bukan 422; status opsional
        // berarti semua status tampil.
        $this->buatBacklog();
        $this->buatBacklog(backlogOverride: ['status' => 'Selesai']);

        $this->tim()->getJson('/api/v1/maintenance-backlog?status=')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'meta' => ['total' => 2]]);
    }

    public function test_patch_status_sudah_dikerjakan_ditolak_409_br12(): void
    {
        $ids = $this->buatBacklog(backlogOverride: ['status' => 'Sedang Dikerjakan']);

        $response = $this->tim()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'ringkasan_masalah' => 'Coba edit saat sudah Dikerjakan.',
        ]);

        $response->assertStatus(409)->assertJson(['success' => false]);

        $case = DB::table('maintenance_case')->where('case_id', $ids['case_id'])->first();
        $this->assertSame('Notifikasi update layanan tidak diterima warga.', $case->ringkasan_masalah,
            'Field case tidak boleh berubah saat PATCH ditolak BR-12.');
    }

    public function test_patch_backlog_tidak_ditemukan_404(): void
    {
        $response = $this->tim()->patchJson('/api/v1/maintenance-backlog/99999', [
            'ringkasan_masalah' => 'Ringkasan untuk id yang tidak ada.',
        ]);

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Backlog tidak ditemukan.', 'data' => null]);
    }

    /** ------------ Otorisasi ------------ */
    public function test_developer_dapat_membaca_tetapi_tidak_dapat_mengubah_backlog(): void
    {
        $ids = $this->buatBacklog();

        $this->developer()->getJson('/api/v1/maintenance-backlog')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'meta' => ['total' => 1]]);
        $this->developer()->getJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['backlog_id' => $ids['backlog_id']]]);
        $this->developer()->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'ringkasan_masalah' => 'Kecobaan Developer.',
        ])->assertStatus(403);

        $this->assertSame('Notifikasi update layanan tidak diterima warga.',
            DB::table('maintenance_case')->where('case_id', $ids['case_id'])->first()->ringkasan_masalah);
    }

    public function test_anonim_ditolak_401_pada_ketiga_endpoint(): void
    {
        $ids = $this->buatBacklog();

        $this->getJson('/api/v1/maintenance-backlog')->assertStatus(401);
        $this->getJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'])->assertStatus(401);
        $this->patchJson('/api/v1/maintenance-backlog/'.$ids['backlog_id'], [
            'ringkasan_masalah' => 'Kecobaan tanpa sesi.',
        ])->assertStatus(401);

        $this->assertSame('Notifikasi update layanan tidak diterima warga.',
            DB::table('maintenance_case')->where('case_id', $ids['case_id'])->first()->ringkasan_masalah);
    }
}
