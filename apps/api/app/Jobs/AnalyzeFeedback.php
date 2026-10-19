<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class AnalyzeFeedback implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public int $batchId) {}

    public function handle(): void
    {
        $claimed = DB::transaction(function () {
            $b = DB::table('analysis_batch')->where('batch_id', $this->batchId)->lockForUpdate()->first();
            if (!$b || $b->status_analisis !== 'Diproses' || $b->started_at) {
                return false;
            }
            DB::table('analysis_batch')->where('batch_id', $this->batchId)->update(['started_at' => now()]);
            return true;
        });
        if (!$claimed) {
            return;
        }
        try {
            $reviews = DB::table('ulasan')->where('batch_id', $this->batchId)->orderBy('review_id')->get();
            $response = Http::connectTimeout(5)->timeout(config('services.analysis.timeout'))
                ->post(rtrim(config('services.analysis.url'), '/').'/internal/v1/analyze', [
                    'schema_version' => '1.0', 'batch_id' => $this->batchId,
                    'reviews' => $reviews->map(fn ($r) => ['review_id' => $r->review_id,
                        'text' => $r->teks_ulasan, 'rating' => $r->rating])->all(),
                ])->throw()->json();
            Validator::make($response, [
                'schema_version' => 'required|in:1.0', 'batch_id' => 'required|integer|in:'.$this->batchId,
                'analysis_version' => 'required|string|max:50', 'results' => 'present|array', 'cases' => 'present|array',
                'results.*.review_id' => 'required|integer|distinct',
                'results.*.kategori' => 'required|string|max:50', 'results.*.sentimen' => 'required|in:positive,negative,neutral',
                'cases.*.group_key' => 'required|string|max:100|distinct', 'cases.*.member_review_ids' => 'required|array|min:1',
                'cases.*.member_review_ids.*' => 'required|integer', 'cases.*.judul' => 'required|string|max:200',
                'cases.*.ringkasan' => 'required|string', 'cases.*.category' => 'required|string|max:50',
                'cases.*.severity' => 'required|in:low,medium,high',
                'cases.*.module' => 'nullable|string|max:100', 'cases.*.feature' => 'nullable|string|max:100',
                'cases.*.recommendation' => 'nullable|string', 'cases.*.priority_score' => 'required|numeric|between:0,10',
            ])->validate();
            $ids = $reviews->pluck('review_id')->sort()->values()->all();
            $actual = array_column($response['results'], 'review_id');
            sort($actual);
            if ($ids !== $actual) {
                throw new \RuntimeException('Review IDs in response differ from request.');
            }
            $members = [];
            foreach ($response['cases'] as $c) {
                foreach ($c['member_review_ids'] as $id) {
                    if (!in_array($id, $ids, true) || isset($members[$id])) {
                        throw new \RuntimeException('Invalid or repeated case member.');
                    }
                    $members[$id] = true;
                }
            }
            DB::transaction(function () use ($response) {
                $b = DB::table('analysis_batch')->where('batch_id', $this->batchId)->lockForUpdate()->first();
                if ($b->status_analisis !== 'Diproses') {
                    return;
                }
                foreach ($response['results'] as $r) {
                    DB::table('ulasan')->where('batch_id', $this->batchId)->where('review_id', $r['review_id'])
                        ->update(['analysis_result' => json_encode($r, JSON_THROW_ON_ERROR)]);
                }
                foreach ($response['cases'] as $c) {
                    $caseId = DB::table('maintenance_case')->insertGetId([
                        'batch_id' => $this->batchId, 'group_key' => $c['group_key'], 'judul_issue' => $c['judul'],
                        'kategori' => $c['category'], 'modul' => $c['module'] ?? 'Unknown', 'fitur' => $c['feature'] ?? 'Unknown',
                        'severity' => $c['severity'], 'priority_score' => $c['priority_score'],
                        'ringkasan_masalah' => $c['ringkasan'], 'recommendation' => $c['recommendation'] ?? null,
                        'status_validasi' => 'Pending', 'created_at' => now(),
                    ], 'case_id');
                    DB::table('ulasan')->where('batch_id', $this->batchId)->whereIn('review_id', $c['member_review_ids'])
                        ->update(['case_id' => $caseId]);
                }
                DB::table('analysis_batch')->where('batch_id', $this->batchId)->update([
                    'status_analisis' => 'Selesai', 'tanggal_analisis' => now(), 'completed_at' => now(),
                    'error_message' => null, 'analysis_version' => $response['analysis_version'],
                ]);
            });
        } catch (\Throwable $e) {
            $this->failed($e);
            report($e);
        }
    }

    public function failed(?\Throwable $e): void
    {
        DB::table('analysis_batch')->where('batch_id', $this->batchId)->where('status_analisis', 'Diproses')
            ->update(['status_analisis' => 'Gagal', 'completed_at' => now(),
                'error_message' => 'Analisis gagal. Periksa layanan analisis, lalu coba ulang.']);
    }
}
