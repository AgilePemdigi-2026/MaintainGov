export type AnalysisReview = {
  review_id: number; source_review_id: string; teks_ulasan: string; rating: number; case_id: number | null;
  analysis_result: { sentimen: string; kategori: string; severity: string | null; issue: string | null; modul: string | null; fitur: string | null; needs_manual_review: boolean } | null;
};
export type AnalysisCase = {
  case_id: number; judul_issue: string; kategori: string; modul: string; fitur: string; severity: string;
  priority_score: number; ringkasan_masalah: string; recommendation: string | null; status_validasi: string;
};
export type BatchStatus = {
  batch_id: number; status: "Diproses" | "Selesai" | "Gagal"; review_count: number; case_count: number;
  error: string | null; attempt: number; analysis_version: string | null;
};
export type Page<T> = { data: T[]; meta: { current_page: number; last_page: number; per_page: number; total: number } };
