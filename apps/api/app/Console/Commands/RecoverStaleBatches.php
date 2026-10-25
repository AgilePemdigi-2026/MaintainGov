<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover analysis batches that are stuck in "Diproses" state.
 *
 * A batch is considered stale when:
 *   - status_analisis = 'Diproses'
 *   - started_at is NOT null (a worker has actually started processing)
 *   - started_at is older than the configured timeout + grace period
 *
 * Batches still waiting in the queue (started_at IS NULL) are NOT touched —
 * a worker has not picked them up yet, and the queue framework will deliver
 * them when a worker becomes available.
 *
 * Safe to run concurrently with workers: uses SELECT ... FOR UPDATE and
 * re-verifies the batch state inside the transaction.
 *
 * Safe to run repeatedly: already-recovered batches are marked 'Gagal' and
 * will be skipped on the next invocation.
 */
class RecoverStaleBatches extends Command
{
    protected $signature = 'analysis:recover-stale
        {--timeout=300 : Seconds after started_at before a batch is considered stale}
        {--dry-run : Preview which batches would be recovered without changing them}';

    protected $description = 'Mark processing batches as failed when the worker has exceeded the timeout.';

    public function handle(): int
    {
        $timeoutSeconds = (int) $this->option('timeout');
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subSeconds($timeoutSeconds);

        // Find candidates: processing, actually started, and past the timeout.
        $candidates = DB::table('analysis_batch')
            ->where('status_analisis', 'Diproses')
            ->whereNotNull('started_at')
            ->where('started_at', '<', $cutoff)
            ->orderBy('batch_id')
            ->get(['batch_id', 'attempt', 'started_at']);

        if ($candidates->isEmpty()) {
            $this->info('No stale batches found.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d candidate(s) (timeout=%ds, cutoff=%s):',
            $candidates->count(), $timeoutSeconds, $cutoff->toDateTimeString()));

        $recovered = 0;

        foreach ($candidates as $candidate) {
            $this->line(sprintf(
                '  batch_id=%d  attempt=%d  started_at=%s',
                $candidate->batch_id, $candidate->attempt, $candidate->started_at,
            ));

            if ($dryRun) {
                continue;
            }

            // Atomic recovery with attempt guard.
            $affected = DB::transaction(function () use ($candidate) {
                $b = DB::table('analysis_batch')
                    ->where('batch_id', $candidate->batch_id)
                    ->lockForUpdate()
                    ->first();

                // Re-verify: skip if another worker completed or a newer attempt
                // has been started since we read the candidate list.
                if (!$b
                    || $b->status_analisis !== 'Diproses'
                    || (int) $b->attempt !== (int) $candidate->attempt
                ) {
                    return 0;
                }

                return DB::table('analysis_batch')
                    ->where('batch_id', $candidate->batch_id)
                    ->where('attempt', $candidate->attempt)
                    ->update([
                        'status_analisis' => 'Gagal',
                        'completed_at'    => now(),
                        'error_message'   => sprintf(
                            'Analisis dihentikan: worker tidak menyelesaikan pemrosesan dalam %d detik (attempt %d).',
                            (int) $this->option('timeout'),
                            $candidate->attempt,
                        ),
                    ]);
            });

            $recovered += $affected;
        }

        $label = $dryRun ? 'Would recover' : 'Recovered';
        $count = $dryRun ? $candidates->count() : $recovered;
        $this->info("$label $count batch(es).");

        return self::SUCCESS;
    }
}
