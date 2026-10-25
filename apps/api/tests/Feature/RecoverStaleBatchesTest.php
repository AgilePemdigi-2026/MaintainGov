<?php

namespace Tests\Feature;

use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the analysis:recover-stale Artisan command.
 */
class RecoverStaleBatchesTest extends TestCase
{
    use RefreshDatabase;

    private function userId(): int
    {
        return Pengguna::create(['nama' => 'Rec', 'email' => uniqid().'@test.local',
            'password' => 'password123', 'role' => 'Tim Maintenance'])->user_id;
    }

    private function getBatch(int $id): object
    {
        return DB::table('analysis_batch')->where('batch_id', $id)->first();
    }

    private function insertBatch(array $override = []): int
    {
        return DB::table('analysis_batch')->insertGetId(array_merge([
            'user_id' => $this->userId(), 'nama_file' => 'r.csv',
            'jumlah_data' => 1, 'status_analisis' => 'Diproses',
            'attempt' => 1, 'created_at' => now(),
        ], $override), 'batch_id');
    }

    public function test_expired_processing_batch_is_recovered(): void
    {
        $id = $this->insertBatch(['started_at' => now()->subMinutes(10)]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        $b = $this->getBatch($id);
        $this->assertSame('Gagal', $b->status_analisis);
        $this->assertNotNull($b->completed_at);
        $this->assertStringContainsString('300', $b->error_message);
    }

    public function test_queued_but_unstarted_batch_is_not_touched(): void
    {
        $id = $this->insertBatch(['started_at' => null]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        $this->assertSame('Diproses', $this->getBatch($id)->status_analisis);
    }

    public function test_recently_started_batch_is_not_touched(): void
    {
        $id = $this->insertBatch(['started_at' => now()->subSeconds(60)]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        $this->assertSame('Diproses', $this->getBatch($id)->status_analisis);
    }

    public function test_completed_batch_is_not_touched(): void
    {
        $id = $this->insertBatch([
            'status_analisis' => 'Selesai',
            'started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(25),
        ]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        $this->assertSame('Selesai', $this->getBatch($id)->status_analisis);
    }

    public function test_already_failed_batch_is_not_touched(): void
    {
        $id = $this->insertBatch([
            'status_analisis' => 'Gagal',
            'started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(25),
            'error_message' => 'Previous failure',
        ]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        $b = $this->getBatch($id);
        $this->assertSame('Gagal', $b->status_analisis);
        $this->assertSame('Previous failure', $b->error_message);
    }

    public function test_repeated_recovery_is_idempotent(): void
    {
        $id = $this->insertBatch(['started_at' => now()->subMinutes(10)]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])->assertExitCode(0);
        $this->assertSame('Gagal', $this->getBatch($id)->status_analisis);

        // Second run: already Gagal, should not be touched again
        $this->artisan('analysis:recover-stale', ['--timeout' => 300])->assertExitCode(0);
        $this->assertSame('Gagal', $this->getBatch($id)->status_analisis);
    }

    public function test_recovery_does_not_overwrite_newer_attempt(): void
    {
        $id = $this->insertBatch([
            'started_at' => now()->subMinutes(10),
            'attempt' => 1,
        ]);

        // Simulate a retry occurring between candidate selection and recovery execution:
        // Bump attempt to 2, reset started_at.
        DB::table('analysis_batch')->where('batch_id', $id)->update([
            'attempt' => 2, 'started_at' => null,
        ]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        // Should remain Diproses because started_at is now null (not selected as candidate)
        $b = $this->getBatch($id);
        $this->assertSame('Diproses', $b->status_analisis);
        $this->assertSame(2, (int) $b->attempt);
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        $id = $this->insertBatch(['started_at' => now()->subMinutes(10)]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame('Diproses', $this->getBatch($id)->status_analisis);
    }

    public function test_mixed_state_batches_correct_recovery(): void
    {
        $expired = $this->insertBatch(['started_at' => now()->subMinutes(10)]);
        $queued = $this->insertBatch(['started_at' => null]);
        $recent = $this->insertBatch(['started_at' => now()->subSeconds(60)]);
        $done = $this->insertBatch([
            'status_analisis' => 'Selesai',
            'started_at' => now()->subMinutes(30),
        ]);
        $failed = $this->insertBatch([
            'status_analisis' => 'Gagal',
            'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('analysis:recover-stale', ['--timeout' => 300])
            ->assertExitCode(0);

        $this->assertSame('Gagal', $this->getBatch($expired)->status_analisis);
        $this->assertSame('Diproses', $this->getBatch($queued)->status_analisis);
        $this->assertSame('Diproses', $this->getBatch($recent)->status_analisis);
        $this->assertSame('Selesai', $this->getBatch($done)->status_analisis);
        $this->assertSame('Gagal', $this->getBatch($failed)->status_analisis);
    }
}
