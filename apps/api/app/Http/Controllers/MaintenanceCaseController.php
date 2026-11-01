<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MaintenanceCaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:Pending,Valid,Tidak Valid',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => 'Parameter tidak valid.',
                'data' => null, 'errors' => $validator->errors(),
            ], 422);
        }

        // AC1 / FR-UC02-01: default daftar case Pending; ?status= opsional
        $query = DB::table('maintenance_case')->orderBy('case_id')
            ->where('status_validasi', $request->string('status', 'Pending'));
        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true, 'message' => 'Daftar maintenance case.',
            'data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $case = DB::table('maintenance_case')->where('case_id', $id)->first();
        if ($case === null) {
            return response()->json([
                'success' => false, 'message' => 'Case tidak ditemukan.', 'data' => null,
            ], 404);
        }
        $reviews = DB::table('ulasan')->where('case_id', $id)->orderBy('review_id')->get()
            ->map(function ($r) {
                $r->analysis_result = json_decode($r->analysis_result ?? 'null', true);

                return $r;
            });

        return response()->json([
            'success' => true, 'message' => 'Detail maintenance case.',
            'data' => ['case' => $case, 'reviews' => $reviews],
        ]);
    }

    public function validate(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['keputusan' => 'required|in:Ya,Tidak']);
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => 'Keputusan harus Ya atau Tidak.',
                'data' => null, 'errors' => $validator->errors(),
            ], 422);
        }

        $validatorId = $request->user()->user_id;
        $decision = $request->string('keputusan')->toString();

        try {
            // Payload dirakit di dalam transaksi dari nilai yang baru ditulis,
            // tidak membaca ulang tabel setelah commit. 404/409 tidak menulis
            // apa pun, sehingga dikembalikan sebagai sentinel setelah transaksi.
            [$outcome, $payload] = DB::transaction(function () use ($id, $decision, $validatorId) {
                $case = DB::table('maintenance_case')->where('case_id', $id)
                    ->lockForUpdate()->first();
                if ($case === null) {
                    return ['not_found', null];
                }
                if ($case->status_validasi !== 'Pending') {
                    return ['conflict', $case->status_validasi];
                }

                $status = $decision === 'Ya' ? 'Valid' : 'Tidak Valid';
                $validatedNow = now();
                DB::table('maintenance_case')->where('case_id', $id)->update([
                    'user_id' => $validatorId, 'status_validasi' => $status,
                    'validated_at' => $validatedNow,
                ]);

                if ($decision === 'Tidak') {
                    return ['done', [
                        'case_id' => $id, 'status_validasi' => $status,
                        'validator_id' => $validatorId, 'validated_at' => $validatedNow->format('Y-m-d H:i:s'),
                        'keputusan' => $decision, 'backlog_id' => null,
                    ]];
                }

                // BR-10: UNIQUE(case_id) memastikan tepat satu backlog per case
                $backlogId = DB::table('maintenance_backlog')->insertGetId([
                    'case_id' => $id, 'status' => 'Ready for Development',
                    'created_at' => $validatedNow, 'updated_at' => $validatedNow,
                ], 'backlog_id');

                return ['done', [
                    'case_id' => $id, 'status_validasi' => $status,
                    'validator_id' => $validatorId, 'validated_at' => $validatedNow->format('Y-m-d H:i:s'),
                    'keputusan' => $decision, 'backlog_id' => $backlogId,
                ]];
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return response()->json([
                'success' => false, 'message' => 'Backlog untuk case ini sudah ada.', 'data' => null,
            ], 409);
        }

        if ($outcome === 'not_found') {
            return response()->json([
                'success' => false, 'message' => 'Case tidak ditemukan.', 'data' => null,
            ], 404);
        }
        if ($outcome === 'conflict') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya case Pending yang dapat divalidasi. Status saat ini: '.$payload.'.',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => $decision === 'Ya'
                ? 'Case valid. Backlog dibentuk.'
                : 'Case ditandai tidak valid.',
            'data' => $payload,
        ]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'unique constraint')
            || str_contains($e->getMessage(), 'Duplicate entry')
            || $sqlState === '23000';
    }
}
