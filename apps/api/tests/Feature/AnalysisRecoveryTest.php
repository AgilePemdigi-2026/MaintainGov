<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeFeedback;
use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function upload(): int
    {
        Queue::fake([AnalyzeFeedback::class]);
        $user = Pengguna::create(['nama' => 'Penguji', 'email' => fake()->unique()->safeEmail(), 'password' => 'password123', 'role' => 'Tim Maintenance']);
        $file = UploadedFile::fake()->createWithContent('reviews.csv', "reviewId,Review Teks,rating,date\nr1,Tidak bisa login,1,2026-10-24 08:00:00\n");
        $response = $this->actingAs($user)->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json']);
        $response->assertAccepted();
        $batch = $response->json('data.batch_id');
        Queue::assertPushed(AnalyzeFeedback::class, fn (AnalyzeFeedback $job) => $job->batchId === $batch && $job->attempt === 1);

        return $batch;
    }

    public function test_stale_started_attempt_is_recoverable_and_retry_is_versioned(): void
    {
        $this->freezeTime();
        $stale = $this->upload();
        $fresh = $this->upload();
        $queued = $this->upload();
        DB::table('analysis_batch')->where('batch_id', $stale)->update(['started_at' => now()->subMinutes(6)]);
        DB::table('analysis_batch')->where('batch_id', $fresh)->update(['started_at' => now()->subMinute()]);

        $this->artisan('analysis:recover-stale')->expectsOutput('Recovered 1 batch(es).')->assertSuccessful();
        $this->assertDatabaseHas('analysis_batch', ['batch_id' => $stale, 'status_analisis' => 'Gagal']);
        $this->assertDatabaseHas('analysis_batch', ['batch_id' => $fresh, 'status_analisis' => 'Diproses']);
        $this->assertDatabaseHas('analysis_batch', ['batch_id' => $queued, 'status_analisis' => 'Diproses', 'started_at' => null]);
        $owner = Pengguna::find(DB::table('analysis_batch')->where('batch_id', $stale)->value('user_id'));
        $this->actingAs($owner)->postJson('/api/v1/analysis-batches/'.$stale.'/retry')->assertAccepted();
        Queue::assertPushed(AnalyzeFeedback::class, fn (AnalyzeFeedback $job) => $job->batchId === $stale && $job->attempt === 2);
        $this->postJson('/api/v1/analysis-batches/'.$stale.'/retry')->assertConflict();
    }

    public function test_unsafe_recovery_threshold_is_rejected(): void
    {
        $this->artisan('analysis:recover-stale', ['--older-than' => 100])->assertFailed();
    }

    public function test_obsolete_job_cannot_claim_or_fail_a_new_attempt(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $batch = $this->upload();
        DB::table('analysis_batch')->where('batch_id', $batch)->update(['attempt' => 2]);

        $job = new AnalyzeFeedback($batch, 1);
        $job->handle();
        $job->failed(new \RuntimeException('Old worker failed'));

        Http::assertNothingSent();
        $this->assertDatabaseHas('analysis_batch', ['batch_id' => $batch, 'attempt' => 2, 'status_analisis' => 'Diproses', 'started_at' => null]);
        $this->assertDatabaseCount('maintenance_case', 0);
    }

    public function test_in_flight_old_response_cannot_persist_into_a_new_attempt(): void
    {
        Http::preventStrayRequests();
        $batch = $this->upload();
        Http::fake([rtrim(config('services.analysis.url'), '/').'/internal/v1/analyze' => function ($request) use ($batch) {
            DB::table('analysis_batch')->where('batch_id', $batch)->update(['attempt' => 2, 'started_at' => null]);

            return Http::response(['schema_version' => '1.0', 'analysis_version' => 'old-attempt', 'batch_id' => $batch,
                'results' => [['review_id' => $request['reviews'][0]['review_id'], 'kategori' => 'other', 'sentimen' => 'neutral']], 'cases' => []]);
        }]);

        (new AnalyzeFeedback($batch, 1))->handle();

        Http::assertSentCount(1);
        $this->assertNull(DB::table('ulasan')->where('batch_id', $batch)->value('analysis_result'));
        $this->assertDatabaseHas('analysis_batch', ['batch_id' => $batch, 'attempt' => 2, 'status_analisis' => 'Diproses']);
        $this->assertDatabaseCount('maintenance_case', 0);
    }

    public function test_legacy_serialized_job_binds_to_the_active_attempt_when_claimed(): void
    {
        Http::preventStrayRequests();
        $batch = $this->upload();
        DB::table('analysis_batch')->where('batch_id', $batch)->update(['attempt' => 2]);
        Http::fake([rtrim(config('services.analysis.url'), '/').'/internal/v1/analyze' => fn ($request) => Http::response([
            'schema_version' => '1.0', 'analysis_version' => 'legacy-queue', 'batch_id' => $batch,
            'results' => [['review_id' => $request['reviews'][0]['review_id'], 'kategori' => 'other', 'sentimen' => 'neutral']], 'cases' => []])]);
        $class = AnalyzeFeedback::class;
        $legacy = unserialize(sprintf('O:%d:"%s":1:{s:7:"batchId";i:%d;}', strlen($class), $class, $batch));

        $legacy->handle();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('analysis_batch', ['batch_id' => $batch, 'attempt' => 2, 'status_analisis' => 'Selesai']);
    }
}
