<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeFeedback;
use App\Services\FeedbackCsv;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalysisController extends Controller
{
    public function store(Request $request, FeedbackCsv $csv): JsonResponse
    {
        $request->validate([
            'file' => [
                'bail',
                'required',
                'file',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value && $value->getSize() > 10_000_000) {
                        $fail('Ukuran file melebihi batas 10.000.000 byte.');
                    }
                },
            ],
        ]);
        $file = $request->file('file');
        abort_unless(strtolower($file->getClientOriginalExtension()) === 'csv', 422, 'File harus berekstensi CSV.');
        $rows = $csv->parse($file->getRealPath());
        $hash = hash_file('sha256', $file->getRealPath());
        $key = $request->header('Idempotency-Key', $hash);
        abort_if(! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $key), 422, 'Idempotency-Key tidak valid.');
        $userId = $request->user()->user_id;
        $existing = DB::table('analysis_batch')->where('user_id', $userId)->where('request_key', $key)->first();
        if ($existing) {
            abort_if($existing->source_sha256 !== $hash, 409, 'Request key sudah dipakai untuk file berbeda.');

            return response()->json(['data' => $this->snapshot($existing)], 202);
        }
        try {
            $id = DB::transaction(function () use ($userId, $file, $hash, $key, $rows) {
                $id = DB::table('analysis_batch')->insertGetId([
                    'user_id' => $userId, 'nama_file' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'jumlah_data' => count($rows), 'status_analisis' => 'Diproses',
                    'source_sha256' => $hash, 'request_key' => $key, 'created_at' => now(),
                ], 'batch_id');
                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table('ulasan')->insert(array_map(fn ($row) => ['batch_id' => $id] + $row, $chunk));
                }
                AnalyzeFeedback::dispatch($id, 1);

                return $id;
            });
        } catch (QueryException $e) {
            $existing = DB::table('analysis_batch')->where('user_id', $userId)->where('request_key', $key)->first();
            if (! $existing) {
                throw $e;
            }
            abort_if($existing->source_sha256 !== $hash, 409, 'Request key sudah dipakai untuk file berbeda.');

            return response()->json(['data' => $this->snapshot($existing)], 202);
        }

        return response()->json(['data' => $this->snapshot(DB::table('analysis_batch')->where('batch_id', $id)->first())], 202);
    }

    private function owned(Request $request, int $id): object
    {
        return DB::table('analysis_batch')->where('batch_id', $id)
            ->where('user_id', $request->user()->user_id)->firstOrFail();
    }

    private function snapshot(object $batch): array
    {
        return ['batch_id' => $batch->batch_id, 'status' => $batch->status_analisis,
            'review_count' => $batch->jumlah_data, 'attempt' => $batch->attempt,
            'started_at' => $batch->started_at, 'completed_at' => $batch->completed_at,
            'error' => $batch->error_message, 'analysis_version' => $batch->analysis_version];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $batch = $this->owned($request, $id);
        $reviews = DB::table('ulasan')->where('batch_id', $id)->orderBy('review_id')->get()
            ->map(function ($r) {
                $r->analysis_result = json_decode($r->analysis_result ?? 'null', true);

                return $r;
            });
        $cases = DB::table('maintenance_case')->where('batch_id', $id)->orderByDesc('priority_score')->get();

        return response()->json(['data' => $this->snapshot($batch) + ['reviews' => $reviews, 'cases' => $cases]]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $batch = $this->owned($request, $id);

        return response()->json(['data' => $this->snapshot($batch) + [
            'case_count' => DB::table('maintenance_case')->where('batch_id', $id)->count(),
        ]]);
    }

    public function reviews(Request $request, int $id): JsonResponse
    {
        $this->owned($request, $id);
        $request->validate(['case_id' => 'sometimes|integer|min:1']);
        $query = DB::table('ulasan')->where('batch_id', $id)->orderBy('review_id');
        if ($request->has('case_id')) {
            $caseId = $request->integer('case_id');
            abort_unless(DB::table('maintenance_case')->where('batch_id', $id)->where('case_id', $caseId)->exists(), 404);
            $query->where('case_id', $caseId);
        }

        return $this->page($query, $request, true);
    }

    public function cases(Request $request, int $id): JsonResponse
    {
        $this->owned($request, $id);

        return $this->page(DB::table('maintenance_case')->where('batch_id', $id)
            ->orderByDesc('priority_score')->orderBy('case_id'), $request);
    }

    private function page(Builder $query, Request $request, bool $decodeReviews = false): JsonResponse
    {
        $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $page = $query->paginate($request->integer('per_page', 25));
        if ($decodeReviews) {
            $page->setCollection($page->getCollection()->map(function (object $review): object {
                $review->analysis_result = json_decode($review->analysis_result ?? 'null', true);

                return $review;
            }));
        }

        return response()->json(['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]]);
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        $this->owned($request, $id);
        DB::transaction(function () use ($id) {
            $batch = DB::table('analysis_batch')->where('batch_id', $id)->lockForUpdate()->first();
            abort_unless($batch->status_analisis === 'Gagal', 409, 'Hanya batch gagal yang dapat diulang.');
            abort_if($batch->attempt >= 10, 409, 'Batas pengulangan tercapai.');
            $newAttempt = $batch->attempt + 1;
            DB::table('analysis_batch')->where('batch_id', $id)->update([
                'status_analisis' => 'Diproses', 'started_at' => null, 'completed_at' => null,
                'error_message' => null, 'attempt' => $newAttempt,
            ]);
            AnalyzeFeedback::dispatch($id, $newAttempt);
        });

        return response()->json(['data' => $this->snapshot(DB::table('analysis_batch')->where('batch_id', $id)->first())], 202);
    }
}
