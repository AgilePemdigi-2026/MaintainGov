<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeFeedback;
use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for attempt guard and stale-result protection.
 */
class AttemptGuardTest extends TestCase
{
    use RefreshDatabase;

    private function user(): Pengguna
    {
        return Pengguna::create(['nama' => 'Guard', 'email' => uniqid().'@test.local',
            'password' => 'password123', 'role' => 'Tim Maintenance']);
    }

    private function getBatch(int $id): object
    {
        return DB::table('analysis_batch')->where('batch_id', $id)->first();
    }

    private function makeBatch(int $attempt = 1, ?string $startedAt = null): int
    {
        return DB::table('analysis_batch')->insertGetId([
            'user_id' => $this->user()->user_id, 'nama_file' => 'g.csv',
            'jumlah_data' => 1, 'status_analisis' => 'Diproses',
            'attempt' => $attempt, 'started_at' => $startedAt, 'created_at' => now(),
        ], 'batch_id');
    }

    private function addReview(int $batchId): int
    {
        DB::table('ulasan')->insert([
            'batch_id' => $batchId, 'source_review_id' => 'R-' . uniqid(),
            'teks_ulasan' => 'tidak bisa login', 'rating' => 1,
        ]);
        return DB::table('ulasan')->where('batch_id', $batchId)->first()->review_id;
    }

    private function fakeSuccess(int $batchId, int $reviewId): void
    {
        Http::fake(fn () => Http::response([
            'schema_version' => '1.0', 'analysis_version' => 'test',
            'batch_id' => $batchId,
            'results' => [['review_id' => $reviewId, 'kategori' => 'defect', 'sentimen' => 'negative']],
            'cases' => [['group_key' => 'k', 'member_review_ids' => [$reviewId],
                'judul' => 'J', 'ringkasan' => 'R', 'category' => 'defect',
                'severity' => 'high', 'module' => 'a', 'feature' => 'b',
                'recommendation' => 'Fix', 'priority_score' => 7]],
        ]));
    }

    public function test_stale_worker_from_attempt_1_cannot_overwrite_attempt_2(): void
    {
        $batchId = $this->makeBatch(attempt: 2);
        $reviewId = $this->addReview($batchId);

        // Simulate a stale worker carrying attempt=1
        $this->fakeSuccess($batchId, $reviewId);
        $staleJob = new AnalyzeFeedback($batchId, attempt: 1);
        $staleJob->handle();

        // Batch should remain in Diproses (claim rejected because attempt doesn't match)
        $batch = $this->getBatch($batchId);
        $this->assertSame('Diproses', $batch->status_analisis);
        $this->assertNull($batch->started_at);
        $this->assertDatabaseCount('maintenance_case', 0);
    }

    public function test_correct_attempt_worker_succeeds(): void
    {
        $batchId = $this->makeBatch(attempt: 2);
        $reviewId = $this->addReview($batchId);

        $this->fakeSuccess($batchId, $reviewId);
        $job = new AnalyzeFeedback($batchId, attempt: 2);
        $job->handle();

        $batch = $this->getBatch($batchId);
        $this->assertSame('Selesai', $batch->status_analisis);
        $this->assertDatabaseCount('maintenance_case', 1);
    }

    public function test_stale_worker_failed_callback_does_not_overwrite_newer_attempt(): void
    {
        $batchId = $this->makeBatch(attempt: 2);
        $this->addReview($batchId);

        // Stale worker from attempt 1 calls failed()
        $staleJob = new AnalyzeFeedback($batchId, attempt: 1);
        $staleJob->failed(new \RuntimeException('old failure'));

        // Should NOT have marked as Gagal because attempt doesn't match
        $batch = $this->getBatch($batchId);
        $this->assertSame('Diproses', $batch->status_analisis);
    }

    public function test_backward_compatible_job_defaults_to_attempt_1(): void
    {
        $batchId = $this->makeBatch(attempt: 1);
        $reviewId = $this->addReview($batchId);

        $this->fakeSuccess($batchId, $reviewId);
        // Old-style dispatch without explicit attempt — should default to 1
        $job = new AnalyzeFeedback($batchId);
        $job->handle();

        $this->assertSame('Selesai', $this->getBatch($batchId)->status_analisis);
    }

    public function test_double_claim_only_one_worker_succeeds(): void
    {
        $batchId = $this->makeBatch(attempt: 1);
        $reviewId = $this->addReview($batchId);

        $this->fakeSuccess($batchId, $reviewId);

        // First worker claims
        $job1 = new AnalyzeFeedback($batchId, attempt: 1);
        $job1->handle();
        $this->assertSame('Selesai', $this->getBatch($batchId)->status_analisis);

        // Second worker tries to claim — should be rejected (started_at already set / status changed)
        Http::fake(fn () => Http::response([], 200));
        $job2 = new AnalyzeFeedback($batchId, attempt: 1);
        $job2->handle();

        // Still exactly 1 case
        $this->assertDatabaseCount('maintenance_case', 1);
    }

    public function test_retry_endpoint_passes_correct_attempt_number(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(fn () => Http::response([], 503));

        $user = $this->user();
        $batchId = DB::table('analysis_batch')->insertGetId([
            'user_id' => $user->user_id, 'nama_file' => 'r.csv',
            'jumlah_data' => 1, 'status_analisis' => 'Gagal',
            'attempt' => 1, 'completed_at' => now(),
            'error_message' => 'fail', 'created_at' => now(),
        ], 'batch_id');
        DB::table('ulasan')->insert([
            'batch_id' => $batchId, 'source_review_id' => 'R-retry',
            'teks_ulasan' => 'test', 'rating' => 3,
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/analysis-batches/$batchId/retry")
            ->assertStatus(202);

        $batch = $this->getBatch($batchId);
        // After retry: attempt should be 2, status Gagal (sync queue, 503 response)
        $this->assertSame(2, (int) $batch->attempt);
    }
}
