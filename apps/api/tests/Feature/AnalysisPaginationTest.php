<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AnalysisPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'Tim Maintenance'): Pengguna
    {
        return Pengguna::create(['nama' => 'Penguji', 'email' => fake()->unique()->safeEmail(), 'password' => 'password123', 'role' => $role]);
    }

    private function batch(Pengguna $user): int
    {
        return DB::table('analysis_batch')->insertGetId(['user_id' => $user->user_id, 'nama_file' => 'fixture.csv', 'jumlah_data' => 201, 'status_analisis' => 'Selesai', 'created_at' => now()], 'batch_id');
    }

    public function test_reviews_after_the_first_two_hundred_are_accessible(): void
    {
        $user = $this->user();
        $batch = $this->batch($user);
        for ($i = 1; $i <= 201; $i++) {
            DB::table('ulasan')->insert(['batch_id' => $batch, 'source_review_id' => 'source-'.$i, 'teks_ulasan' => 'Ulasan '.$i, 'rating' => 3, 'tanggal_ulasan' => now(), 'sumber' => 'csv', 'analysis_result' => json_encode(['sentimen' => 'neutral', 'kategori' => 'other'])]);
        }

        $this->actingAs($user)->getJson('/api/v1/analysis-batches/'.$batch.'/reviews?page=9&per_page=25')
            ->assertOk()->assertJsonPath('meta.total', 201)->assertJsonPath('meta.last_page', 9)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_review_id', 'source-201')->assertJsonPath('data.0.analysis_result.sentimen', 'neutral');
        $this->getJson('/api/v1/analysis-batches/'.$batch.'/status')->assertOk()->assertJsonPath('data.case_count', 0)
            ->assertJsonMissingPath('data.reviews')->assertJsonMissingPath('data.cases');
    }

    public function test_case_pagination_has_stable_priority_order_and_scoped_sources(): void
    {
        $user = $this->user();
        $batch = $this->batch($user);
        $ids = [];
        for ($i = 1; $i <= 201; $i++) {
            $ids[] = DB::table('maintenance_case')->insertGetId(['batch_id' => $batch, 'group_key' => 'group-'.$i, 'judul_issue' => 'Issue '.$i, 'kategori' => 'defect', 'modul' => 'Autentikasi', 'fitur' => 'Login', 'ringkasan_masalah' => 'Gangguan login', 'severity' => 'high', 'priority_score' => 8, 'status_validasi' => 'Pending'], 'case_id');
        }
        DB::table('ulasan')->insert(['batch_id' => $batch, 'source_review_id' => 'evidence-201', 'teks_ulasan' => 'Tidak bisa login', 'rating' => 1, 'tanggal_ulasan' => now(), 'sumber' => 'csv', 'case_id' => $ids[200]]);

        $this->actingAs($user)->getJson('/api/v1/analysis-batches/'.$batch.'/cases?page=3&per_page=100')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.case_id', $ids[200])->assertJsonPath('meta.total', 201);
        $this->getJson('/api/v1/analysis-batches/'.$batch.'/reviews?case_id='.$ids[200])->assertOk()
            ->assertJsonPath('data.0.source_review_id', 'evidence-201')->assertJsonPath('meta.total', 1);
        $other = $this->batch($user);
        $this->getJson('/api/v1/analysis-batches/'.$other.'/reviews?case_id='.$ids[200])->assertNotFound();
    }

    public function test_paginated_routes_enforce_authentication_role_and_batch_ownership(): void
    {
        $owner = $this->user();
        $batch = $this->batch($owner);
        foreach (['status', 'reviews', 'cases'] as $resource) {
            $this->getJson('/api/v1/analysis-batches/'.$batch.'/'.$resource)->assertUnauthorized();
        }
        $this->actingAs($this->user('Developer'))->getJson('/api/v1/analysis-batches/'.$batch.'/reviews')->assertForbidden();
        $this->actingAs($this->user());
        foreach (['status', 'reviews', 'cases'] as $resource) {
            $this->getJson('/api/v1/analysis-batches/'.$batch.'/'.$resource)->assertNotFound();
        }
    }

    public static function invalidPagination(): array
    {
        return ['zero page' => ['page=0'], 'negative page' => ['page=-1'], 'zero size' => ['per_page=0'], 'oversize' => ['per_page=101'], 'noninteger' => ['per_page=abc']];
    }

    #[DataProvider('invalidPagination')]
    public function test_invalid_pagination_is_rejected(string $query): void
    {
        $user = $this->user();
        $batch = $this->batch($user);

        $this->actingAs($user)->getJson('/api/v1/analysis-batches/'.$batch.'/reviews?'.$query)->assertUnprocessable();
    }
}
