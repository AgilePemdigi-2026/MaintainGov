<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MaintenanceCaseValidationTest extends TestCase
{
    use RefreshDatabase;

    private array $akun;

    protected function setUp(): void
    {
        parent::setUp();

        $this->akun = [
            'tim' => $this->buatPengguna('Tim Maintenance'),
            'developer' => $this->buatPengguna('Developer'),
            'uploader' => $this->buatPengguna('Tim Maintenance'),
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

    private function buatCase(int $jumlahUlasan = 2, array $override = []): int
    {
        $batchId = DB::table('analysis_batch')->insertGetId([
            'user_id' => $this->akun['uploader'], 'nama_file' => 'ulasan.csv',
            'jumlah_data' => $jumlahUlasan, 'status_analisis' => 'Selesai',
            'source_sha256' => str_replace('.', '', (string) microtime(true)).'test',
            'request_key' => 'key-'.microtime(true).'test', 'created_at' => now(),
        ], 'batch_id');

        $caseId = DB::table('maintenance_case')->insertGetId(array_merge([
            'judul_issue' => 'Notifikasi tidak muncul',
            'kategori' => 'defect', 'modul' => 'Notifikasi', 'fitur' => 'Push Notifikasi',
            'severity' => 'High', 'priority_score' => 8.50,
            'ringkasan_masalah' => 'Notifikasi update layanan tidak diterima warga.',
            'status_validasi' => 'Pending', 'created_at' => now(),
        ], $override), 'case_id');

        for ($i = 1; $i <= $jumlahUlasan; $i++) {
            DB::table('ulasan')->insert([
                'batch_id' => $batchId, 'case_id' => $caseId,
                'source_review_id' => "r-$caseId-$i", 'rating' => 2,
                'teks_ulasan' => "Aplikasi gagal kirim notifikasi $i",
                'tanggal_ulasan' => now(),
            ]);
        }

        return $caseId;
    }

    private function validasiSebagai(string $aktor, int $caseId, string $keputusan)
    {
        return $this->actingAsPengguna($aktor)
            ->postJson("/api/v1/maintenance-cases/$caseId/validate", ['keputusan' => $keputusan]);
    }

    private function actingAsPengguna(string $aktor)
    {
        return $this->actingAs(\App\Models\Pengguna::query()->findOrFail($this->akun[$aktor]));
    }

    /** ------------ List & detail ------------ */

    public function test_list_maintenance_cases_dengan_filter_status_pending(): void
    {
        $pending = $this->buatCase();
        $lainnya = $this->buatCase(override: ['status_validasi' => 'Valid', 'user_id' => $this->akun['tim'], 'validated_at' => now()]);
        $this->buatCase(override: ['status_validasi' => 'Tidak Valid', 'user_id' => $this->akun['tim'], 'validated_at' => now()]);

        $response = $this->actingAsPengguna('tim')->getJson('/api/v1/maintenance-cases?status=Pending');

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
            ->assertJson(['success' => true, 'meta' => ['total' => 1]]);

        $ids = collect($response->json('data'))->pluck('case_id');
        $this->assertNotContains($lainnya, $ids, 'Hanya case Pending yang tampil pada filter status=Pending.');
    }

    public function test_list_default_hanya_menampilkan_case_pending(): void
    {
        $this->buatCase();
        $this->buatCase(override: ['status_validasi' => 'Valid', 'user_id' => $this->akun['tim'], 'validated_at' => now()]);
        $this->buatCase(override: ['status_validasi' => 'Tidak Valid', 'user_id' => $this->akun['tim'], 'validated_at' => now()]);

        // Tanpa ?status=: AC1 default hanya Pending
        $response = $this->actingAsPengguna('tim')->getJson('/api/v1/maintenance-cases');

        $response->assertStatus(200)->assertJson(['success' => true, 'meta' => ['total' => 1]]);
        $this->assertSame('Pending', collect($response->json('data'))->first()['status_validasi']);
    }

    public function test_list_validasi_per_page_di_luar_batas_maksimum(): void
    {
        $this->buatCase();

        // Konvensi input salah: HTTP 422 + envelope {success, message, data}
        $this->actingAsPengguna('tim')->getJson('/api/v1/maintenance-cases?per_page=500')->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_detail_case_menampilkan_ulasan_sumber(): void
    {
        $caseId = $this->buatCase(3);

        $response = $this->actingAsPengguna('tim')->getJson("/api/v1/maintenance-cases/$caseId");

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals($caseId, $data['case']['case_id']);
        $this->assertEquals('Pending', $data['case']['status_validasi']);
        $this->assertCount(3, $data['reviews']);
        $this->assertSame('Aplikasi gagal kirim notifikasi 1', $data['reviews'][0]['teks_ulasan']);
    }

    public function test_detail_case_tidak_ditemukan(): void
    {
        $response = $this->actingAsPengguna('tim')->getJson('/api/v1/maintenance-cases/99999');

        $response->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Case tidak ditemukan.', 'data' => null]);
    }

    /** ------------ Validasi Ya ------------ */

    public function test_keputusan_ya_membentuk_lima_kolom_wajib_dan_satu_backlog(): void
    {
        $caseId = $this->buatCase();

        $response = $this->validasiSebagai('tim', $caseId, 'Ya');

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Case valid. Backlog dibentuk.'])
            ->assertJsonPath('data.status_validasi', 'Valid');

        $case = DB::table('maintenance_case')->where('case_id', $caseId)->first();
        $this->assertSame('Valid', $case->status_validasi);
        $this->assertSame($this->akun['tim'], (int) $case->user_id, 'Validator harus tercatat pada case.');
        $this->assertNotNull($case->validated_at);

        $this->assertSame(1, DB::table('maintenance_backlog')->count(),
            'Keputusan Ya harus membentuk tepat satu backlog.');
        $backlog = DB::table('maintenance_backlog')->where('case_id', $caseId)->first();
        $this->assertNotNull($backlog);
        $this->assertSame('Ready for Development', $backlog->status);
        $this->assertNotNull($backlog->created_at);
        $this->assertNotNull($backlog->updated_at);
        $this->assertEquals($backlog->backlog_id, $response->json('data.backlog_id'));
    }

    public function test_keputusan_ya_pada_case_sudah_valid_ditolak_409(): void
    {
        $caseId = $this->buatCase();
        $this->validasiSebagai('tim', $caseId, 'Ya')->assertStatus(200);

        $this->validasiSebagai('tim', $caseId, 'Ya')->assertStatus(409)
            ->assertJson(['success' => false]);
        $this->validasiSebagai('tim', $caseId, 'Tidak')->assertStatus(409);

        $this->assertSame(1, DB::table('maintenance_backlog')->count(),
            'Validasi ulang tidak boleh membentuk backlog tambahan.');
        $case = DB::table('maintenance_case')->where('case_id', $caseId)->first();
        $this->assertSame('Valid', $case->status_validasi, 'Status case tidak boleh berubah tanpa validasi berjalan.');
    }

    /** ------------ Validasi Tidak ------------ */

    public function test_keputusan_tidak_mengubah_status_tanpa_backlog(): void
    {
        $caseId = $this->buatCase();

        $response = $this->validasiSebagai('tim', $caseId, 'Tidak');

        $response->assertStatus(200)->assertJsonPath('data.status_validasi', 'Tidak Valid');

        $case = DB::table('maintenance_case')->where('case_id', $caseId)->first();
        $this->assertSame('Tidak Valid', $case->status_validasi);
        $this->assertSame($this->akun['tim'], (int) $case->user_id);
        $this->assertNotNull($case->validated_at);
        $this->assertNull($response->json('data.backlog_id'));
        $this->assertSame(0, DB::table('maintenance_backlog')->count(),
            'Case Tidak Valid tidak boleh membentuk backlog.');
    }

    public function test_keputusan_tidak_pada_case_sudah_tidak_valid_ditolak_409(): void
    {
        $caseId = $this->buatCase();
        $this->validasiSebagai('tim', $caseId, 'Tidak')->assertStatus(200);

        $this->validasiSebagai('tim', $caseId, 'Tidak')->assertStatus(409)
            ->assertJson(['success' => false]);
        $this->assertSame(0, DB::table('maintenance_backlog')->count());
    }

    /** ------------ Konflik unique & rollback ------------ */

    public function test_konflik_unique_mengembalikan_409_dan_mengembalikan_case_ke_pending(): void
    {
        $caseId = $this->buatCase();

        // Simulasi backlog sudah terbentuk oleh proses lain sebelum validasi
        DB::table('maintenance_backlog')->insert([
            'case_id' => $caseId, 'status' => 'Ready for Development',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $backlogAwal = DB::table('maintenance_backlog')->where('case_id', $caseId)->value('backlog_id');

        $response = $this->validasiSebagai('tim', $caseId, 'Ya');

        $response->assertStatus(409)->assertJson([
            'success' => false,
            'message' => 'Backlog untuk case ini sudah ada.',
            'data' => null,
        ]);

        $case = DB::table('maintenance_case')->where('case_id', $caseId)->first();
        $this->assertSame('Pending', $case->status_validasi, 'Validasi gagal harus rollback dan case tetap Pending.');
        $this->assertNull($case->user_id, 'Validator tidak boleh tercatat saat validasi gagal.');
        $this->assertNull($case->validated_at);
        $this->assertSame(1, DB::table('maintenance_backlog')->count());
        $this->assertSame($backlogAwal, DB::table('maintenance_backlog')->where('case_id', $caseId)->value('backlog_id'));
    }

    /** ------------ Otorisasi ------------ */

    public function test_developer_ditolak_403(): void
    {
        $caseId = $this->buatCase();

        $this->actingAsPengguna('developer')->getJson('/api/v1/maintenance-cases')->assertStatus(403);
        $this->actingAsPengguna('developer')->getJson("/api/v1/maintenance-cases/$caseId")->assertStatus(403);
        $this->validasiSebagai('developer', $caseId, 'Ya')->assertStatus(403);

        $this->assertSame(0, DB::table('maintenance_backlog')->count());
        $this->assertSame('Pending', DB::table('maintenance_case')->where('case_id', $caseId)->first()->status_validasi);
    }

    public function test_anonim_ditolak_401(): void
    {
        $caseId = $this->buatCase();

        $this->getJson('/api/v1/maintenance-cases')->assertStatus(401);
        $this->getJson("/api/v1/maintenance-cases/$caseId")->assertStatus(401);
        $this->postJson("/api/v1/maintenance-cases/$caseId/validate", ['keputusan' => 'Ya'])->assertStatus(401);

        $this->assertSame('Pending', DB::table('maintenance_case')->where('case_id', $caseId)->first()->status_validasi);
    }

    public function test_keputusan_di_luar_ya_tidak_ditolak_422(): void
    {
        $caseId = $this->buatCase();

        $this->validasiSebagai('tim', $caseId, 'Mungkin')->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->postJson("/api/v1/maintenance-cases/$caseId/validate", [])->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertSame('Pending', DB::table('maintenance_case')->where('case_id', $caseId)->first()->status_validasi);
    }
}
