<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MaintenanceBacklogController extends Controller
{
    /** Writable fields: maintenance_case yang boleh diubah dari backlog (UC03). */
    private const WRITABLE = [
        'judul_issue', 'ringkasan_masalah', 'modul', 'fitur', 'severity',
        'priority_score', 'recommendation',
    ];

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
            ->leftJoin('pengguna_sistem as u', 'u.user_id', '=', 'mb.assigned_to')
            ->select('mb.backlog_id', 'mb.case_id', 'mb.status', 'mb.assigned_to', 'mb.assigned_at',
                'mb.source_branch', 'mb.created_at', 'mb.updated_at',
                'mc.judul_issue', 'mc.kategori', 'mc.modul', 'mc.fitur', 'mc.severity',
                'mc.priority_score', 'mc.ringkasan_masalah', 'mc.status_validasi',
                'u.nama as assignee_nama')
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
            ->leftJoin('pengguna_sistem as u', 'u.user_id', '=', 'mb.assigned_to')
            ->select('mb.*', 'mc.judul_issue', 'mc.kategori', 'mc.modul', 'mc.fitur', 'mc.severity',
                'mc.priority_score', 'mc.ringkasan_masalah', 'mc.recommendation', 'mc.status_validasi',
                'mc.user_id', 'mc.validated_at', 'mc.created_at', 'u.nama as assignee_nama')
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
            'judul' => 'sometimes|string|max:255',
            'judul_issue' => 'sometimes|string|max:255',
            'fitur' => 'sometimes|string|max:100',
            'recommendation' => 'sometimes|nullable|string',
            'priority' => 'sometimes|in:High,Medium,Low',
            'priority_score' => 'sometimes|numeric|min:0|max:10',
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

        $caseUpdate = [
            'ringkasan_masalah' => $request->string('ringkasan_masalah')->toString(),
        ];

        if ($request->filled('judul') || $request->filled('judul_issue')) {
            $caseUpdate['judul_issue'] = $request->string(
                $request->filled('judul') ? 'judul' : 'judul_issue',
            )->toString();
        }
        if ($request->filled('modul')) {
            $caseUpdate['modul'] = $request->string('modul')->toString();
        }
        if ($request->filled('fitur')) {
            $caseUpdate['fitur'] = $request->string('fitur')->toString();
        }
        if ($request->filled('severity')) {
            $caseUpdate['severity'] = $request->string('severity')->toString();
        }
        if ($request->has('recommendation')) {
            $recommendation = $request->input('recommendation');
            $caseUpdate['recommendation'] = is_string($recommendation) && trim($recommendation) !== ''
                ? trim($recommendation)
                : null;
        }
        if ($request->filled('priority_score')) {
            $caseUpdate['priority_score'] = (float) $request->input('priority_score');
        } elseif ($request->filled('priority')) {
            // Kompatibilitas kontrak UC03: label priority dimap ke bucket tetap
            // High=9.00 / Medium=5.50 / Low=2.00 (D-W12-02-01).
            $caseUpdate['priority_score'] = self::PRIORITY_SCORE[(string) $request->string('priority')];
        }

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
