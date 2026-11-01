<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MaintenanceBacklogController extends Controller
{
    /** Writable fields: maintenance_case yang boleh diubah dari backlog (UC03). */
    private const WRITABLE = ['ringkasan_masalah', 'modul', 'severity', 'priority_score'];

    /** Bucket priority label -> priority_score; menjaga urutan High > Medium > Low pada rentang skor model 0-10. */
    private const PRIORITY_SCORE = ['High' => 9.00, 'Medium' => 5.50, 'Low' => 2.00];

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:Ready for Development,Sedang Dikerjakan,Ready for Pull Request,Selesai',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => 'Parameter tidak valid.',
                'data' => null, 'errors' => $validator->errors(),
            ], 422);
        }

        $query = DB::table('maintenance_backlog as mb')
            ->join('maintenance_case as mc', 'mc.case_id', '=', 'mb.case_id')
            ->select('mb.backlog_id', 'mb.case_id', 'mb.status', 'mb.assigned_to', 'mb.assigned_at',
                'mb.source_branch', 'mb.created_at', 'mb.updated_at',
                'mc.judul_issue', 'mc.kategori', 'mc.modul', 'mc.fitur', 'mc.severity',
                'mc.priority_score', 'mc.ringkasan_masalah', 'mc.status_validasi')
            // Prioritas label High > Medium > Low, lalu skor, lalu backlog_id (deterministik)
            ->orderByRaw("CASE LOWER(mc.severity) WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END, mc.priority_score DESC, mb.backlog_id ASC");
        if ($request->filled('status')) {
            $query->where('mb.status', $request->string('status'));
        }
        $page = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'success' => true, 'message' => 'Daftar maintenance backlog.',
            'data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $backlog = DB::table('maintenance_backlog as mb')
            ->join('maintenance_case as mc', 'mc.case_id', '=', 'mb.case_id')
            ->select('mb.*', 'mc.judul_issue', 'mc.kategori', 'mc.modul', 'mc.fitur', 'mc.severity',
                'mc.priority_score', 'mc.ringkasan_masalah', 'mc.recommendation', 'mc.status_validasi',
                'mc.user_id', 'mc.validated_at', 'mc.created_at')
            ->where('mb.backlog_id', $id)->first();
        if ($backlog === null) {
            return response()->json([
                'success' => false, 'message' => 'Backlog tidak ditemukan.', 'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true, 'message' => 'Detail maintenance backlog.',
            'data' => $backlog,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'priority' => 'sometimes|in:High,Medium,Low',
            'severity' => 'sometimes|string|max:20',
            'modul' => 'sometimes|string|max:100',
            'ringkasan_masalah' => 'required|string',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false, 'message' => 'Perubahan tidak valid.',
                'data' => null, 'errors' => $validator->errors(),
            ], 422);
        }

        $caseUpdate = array_filter([
            'ringkasan_masalah' => $request->string('ringkasan_masalah')->toString(),
            'modul' => $request->filled('modul') ? $request->string('modul')->toString() : null,
            'severity' => $request->filled('severity') ? $request->string('severity')->toString() : null,
            'priority_score' => $request->filled('priority')
                // TODO(decision D-W12-02-01): label priority dimap ke bucket tetap
                // High=9.00 / Medium=5.50 / Low=2.00 dan MENGGANTIKAN skor model ML
                // (intentional, tanpa kolom baru). Konfirmasi PO + perubahan skema
                // tersendiri hanya bila keputusan berubah — lihat
                // docs/artifacts/shared/pdm-delta-sprint2.md dan contracts/uc03-api.md.
                ? self::PRIORITY_SCORE[(string) $request->string('priority')]
                : null,
        ], fn ($value) => $value !== null);

        $payload = DB::transaction(function () use ($id, $caseUpdate) {
            $backlog = DB::table('maintenance_backlog')->where('backlog_id', $id)
                ->lockForUpdate()->first();
            if ($backlog === null) {
                return ['not_found', null];
            }
            // BR-12: hanya backlog Ready for Development yang dapat diedit
            if ($backlog->status !== 'Ready for Development') {
                return ['conflict', $backlog->status];
            }

            $caseId = $backlog->case_id;
            $touchedAt = now();

            if ($caseUpdate !== []) {
                DB::table('maintenance_case')->where('case_id', $caseId)->update($caseUpdate);
            }
            // updated_at via timestamps pada maintenance_backlog
            DB::table('maintenance_backlog')->where('backlog_id', $id)->update(['updated_at' => $touchedAt]);

            $case = DB::table('maintenance_case')->where('case_id', $caseId)->first();

            return ['done', $this->payload($backlog, $case, $touchedAt->format('Y-m-d H:i:s'))];
        });

        if ($payload[0] === 'not_found') {
            return response()->json([
                'success' => false, 'message' => 'Backlog tidak ditemukan.', 'data' => null,
            ], 404);
        }
        if ($payload[0] === 'conflict') {
            return response()->json([
                'success' => false,
                'message' => 'Perubahan hanya diizinkan saat backlog masih Ready for Development (BR-12). Status saat ini: '.$payload[1].'.',
                'data' => null,
            ], 409);
        }

        return response()->json([
            'success' => true, 'message' => 'Backlog diperbarui.', 'data' => $payload[1],
        ]);
    }

    private function payload(object $backlog, object $case, string $updatedAt): array
    {
        return [
            'backlog_id' => $backlog->backlog_id, 'case_id' => $backlog->case_id,
            'status' => $backlog->status,
            'case' => collect((array) $case)->only(self::WRITABLE + [
                'case_id', 'judul_issue', 'kategori', 'fitur', 'status_validasi', 'created_at',
            ])->all(),
            'updated_at' => $updatedAt,
        ];
    }
}
