<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeFeedback;
use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract validation tests ensuring Laravel validation in AnalyzeFeedback
 * matches the shared JSON Schema and Pydantic definitions.
 */
class ContractValidationTest extends TestCase
{
    use RefreshDatabase;

    private function user(): Pengguna
    {
        return Pengguna::create(['nama' => 'Penguji', 'email' => uniqid().'@test.local',
            'password' => 'password123', 'role' => 'Tim Maintenance']);
    }

    private function getBatch(int $id): object
    {
        return DB::table('analysis_batch')->where('batch_id', $id)->first();
    }

    private function batch(int $attempt = 1): int
    {
        return DB::table('analysis_batch')->insertGetId([
            'user_id' => $this->user()->user_id, 'nama_file' => 'test.csv',
            'jumlah_data' => 1, 'status_analisis' => 'Diproses',
            'attempt' => $attempt, 'created_at' => now(),
        ], 'batch_id');
    }

    private function review(int $batchId): int
    {
        DB::table('ulasan')->insert([
            'batch_id' => $batchId, 'source_review_id' => 'R-' . uniqid(),
            'teks_ulasan' => 'Test ulasan', 'rating' => 3,
        ]);
        return DB::table('ulasan')->where('batch_id', $batchId)->orderByDesc('review_id')->first()->review_id;
    }

    private function validResponse(int $batchId, int $reviewId): array
    {
        return [
            'schema_version' => '1.0', 'analysis_version' => 'test-v1',
            'batch_id' => $batchId,
            'results' => [[
                'review_id' => $reviewId, 'kategori' => 'defect',
                'sentimen' => 'negative',
            ]],
            'cases' => [[
                'group_key' => 'test-case-' . uniqid(), 'member_review_ids' => [$reviewId],
                'judul' => 'Test Case', 'ringkasan' => 'Test summary',
                'category' => 'defect', 'severity' => 'high',
                'module' => 'auth', 'feature' => 'login',
                'recommendation' => 'Fix it', 'priority_score' => 7.5,
            ]],
        ];
    }

    private function runWithResponse(array $response): string
    {
        $batchId = $response['batch_id'];
        Http::fake(fn () => Http::response($response));
        (new AnalyzeFeedback($batchId))->handle();
        return $this->getBatch($batchId)->status_analisis;
    }

    // ─── Sentiment validation ───────────────────────────────────────

    public function test_positive_sentiment_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['results'][0]['sentimen'] = 'positive';
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_negative_sentiment_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['results'][0]['sentimen'] = 'negative';
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_neutral_sentiment_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['results'][0]['sentimen'] = 'neutral';
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_indonesian_sentiment_rejected(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['results'][0]['sentimen'] = 'positif';
        $this->assertSame('Gagal', $this->runWithResponse($response));
        $this->assertDatabaseCount('maintenance_case', 0);
    }

    // ─── Case severity validation ───────────────────────────────────

    public function test_low_severity_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['severity'] = 'low';
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_medium_severity_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['severity'] = 'medium';
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_high_severity_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['severity'] = 'high';
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_critical_case_severity_rejected(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['severity'] = 'critical';
        $this->assertSame('Gagal', $this->runWithResponse($response));
        $this->assertDatabaseCount('maintenance_case', 0);
    }

    // ─── Priority score validation ──────────────────────────────────

    public function test_priority_score_zero_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['priority_score'] = 0;
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_priority_score_ten_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['priority_score'] = 10;
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_priority_score_midrange_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['priority_score'] = 5.5;
        $this->assertSame('Selesai', $this->runWithResponse($response));
    }

    public function test_priority_score_negative_rejected(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['priority_score'] = -1;
        $this->assertSame('Gagal', $this->runWithResponse($response));
    }

    public function test_priority_score_above_ten_rejected(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['priority_score'] = 10.01;
        $this->assertSame('Gagal', $this->runWithResponse($response));
    }

    // ─── Required/nullable field tests ──────────────────────────────

    public function test_missing_required_case_field_rejected(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        unset($response['cases'][0]['category']);
        $this->assertSame('Gagal', $this->runWithResponse($response));
    }

    public function test_nullable_module_feature_recommendation_accepted(): void
    {
        $batchId = $this->batch();
        $reviewId = $this->review($batchId);
        $response = $this->validResponse($batchId, $reviewId);
        $response['cases'][0]['module'] = null;
        $response['cases'][0]['feature'] = null;
        $response['cases'][0]['recommendation'] = null;
        $this->assertSame('Selesai', $this->runWithResponse($response));

        $case = DB::table('maintenance_case')->where('batch_id', $batchId)->first();
        $this->assertSame('Unknown', $case->modul);
        $this->assertNull($case->recommendation);
    }

    // ─── Non-JSON / empty body ──────────────────────────────────────

    public function test_non_json_response_marks_failure(): void
    {
        $batchId = $this->batch();
        $this->review($batchId);
        Http::fake(fn () => Http::response('not json', 200, ['Content-Type' => 'text/plain']));
        (new AnalyzeFeedback($batchId))->handle();
        $this->assertSame('Gagal', $this->getBatch($batchId)->status_analisis);
    }

    public function test_empty_body_response_marks_failure(): void
    {
        $batchId = $this->batch();
        $this->review($batchId);
        Http::fake(fn () => Http::response('', 200));
        (new AnalyzeFeedback($batchId))->handle();
        $this->assertSame('Gagal', $this->getBatch($batchId)->status_analisis);
    }
}
