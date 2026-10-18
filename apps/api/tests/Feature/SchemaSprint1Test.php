<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaSprint1Test extends TestCase
{
    use RefreshDatabase;

    private function buatUser(): int
    {
        return DB::table('pengguna_sistem')->insertGetId([
            'nama' => 'Penguji', 'email' => 'penguji@maintaingov.test',
            'password' => Hash::make('rahasia-uji'), 'role' => 'Tim Maintenance',
            'created_at' => now(),
        ]);
    }

    private function buatBatch(int $userId): int
    {
        return DB::table('analysis_batch')->insertGetId([
            'user_id' => $userId, 'nama_file' => 'fixture.csv', 'jumlah_data' => 2,
            'status_analisis' => 'Diproses', 'created_at' => now(),
        ]);
    }

    public function test_tabel_dan_kolom_delta_ada(): void
    {
        foreach (['pengguna_sistem', 'analysis_batch', 'maintenance_case', 'ulasan'] as $t) {
            $this->assertTrue(Schema::hasTable($t), "tabel $t belum ada");
        }
        $this->assertTrue(Schema::hasColumns('analysis_batch',
            ['started_at', 'completed_at', 'error_message', 'attempt', 'analysis_version']));
        $this->assertTrue(Schema::hasColumns('ulasan', ['source_review_id', 'case_id']));
    }

    public function test_insert_valid_berhasil(): void
    {
        $u = $this->buatUser();
        $b = $this->buatBatch($u);
        $c = DB::table('maintenance_case')->insertGetId([
            'judul_issue' => 'Login gagal', 'kategori' => 'defect', 'modul' => 'auth',
            'fitur' => 'login', 'severity' => 'High', 'priority_score' => 8.50,
            'ringkasan_masalah' => 'Pengguna tidak dapat masuk', 'status_validasi' => 'Pending',
            'created_at' => now(),
        ]);
        DB::table('ulasan')->insert([
            'batch_id' => $b, 'case_id' => $c, 'source_review_id' => 'R1',
            'nama_reviewer' => 'warga', 'rating' => 1,
            'teks_ulasan' => 'Tidak bisa login sejak update', 'tanggal_ulasan' => now(),
        ]);
        $this->assertSame(1, DB::table('ulasan')->count());
    }

    public function test_duplikat_source_review_dalam_batch_ditolak(): void
    {
        $b = $this->buatBatch($this->buatUser());
        DB::table('ulasan')->insert([
            'batch_id' => $b, 'source_review_id' => 'R1', 'rating' => 3,
            'teks_ulasan' => 'a',
        ]);
        $this->expectException(QueryException::class);   // unique (batch_id, source_review_id)
        DB::table('ulasan')->insert([
            'batch_id' => $b, 'source_review_id' => 'R1', 'rating' => 4,
            'teks_ulasan' => 'b',
        ]);
    }

    public function test_ulasan_boleh_tanpa_case(): void
    {
        $b = $this->buatBatch($this->buatUser());
        DB::table('ulasan')->insert([
            'batch_id' => $b, 'case_id' => null, 'source_review_id' => 'R9',
            'rating' => 5, 'teks_ulasan' => 'pujian umum',
        ]);
        $this->assertNull(DB::table('ulasan')->first()->case_id);
    }

    public function test_case_pending_boleh_tanpa_validator(): void
    {
        $c = DB::table('maintenance_case')->insertGetId([
            'user_id' => null, 'judul_issue' => 'X', 'kategori' => 'other',
            'modul' => 'm', 'fitur' => 'f', 'severity' => 'Low',
            'priority_score' => 1.00, 'ringkasan_masalah' => 'r',
            'status_validasi' => 'Pending', 'created_at' => now(),
        ]);

        $case = DB::table('maintenance_case')->where('case_id', $c)->first();

        $this->assertNotNull($case);
        $this->assertNull($case->user_id);     
        $this->assertNull($case->validated_at); 
    }

    public function test_fk_ulasan_ke_batch_tidak_dikenal_ditolak(): void
    {
        $this->expectException(QueryException::class);
        DB::table('ulasan')->insert([
            'batch_id' => 999999, 'source_review_id' => 'R1', 'rating' => 2,
            'teks_ulasan' => 'x',
        ]);
    }

    public function test_rating_di_luar_rentang_ditolak(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('CHECK constraint diuji pada MySQL.');
        }
        $b = $this->buatBatch($this->buatUser());
        $this->expectException(QueryException::class);
        DB::table('ulasan')->insert([
            'batch_id' => $b, 'source_review_id' => 'R7', 'rating' => 6,
            'teks_ulasan' => 'rating invalid',
        ]);
    }
}