// Kontrak frontend Sprint 2 (UC02–UC05), draf v0.1 mengikuti docs/ARCHITECTURE.md §4–6.
// Nama field mengikuti PDM (maintenance_case, maintenance_backlog, pull_request). Sesuaikan
// bila kontrak final Axel (UC02/UC03) dan Hanif (UC04/UC05) di contracts/ berbeda.
import type { AuthUser, CaseStatus } from "@/lib/types";

export type { AuthUser, CaseStatus };

export type Severity = "Critical" | "High" | "Medium" | "Low";

/** "Maintenance Issue" adalah backlog dalam konteks pengerjaan (glossary SRS), bukan tabel baru. */
export type BacklogStatus =
  | "Ready for Development"
  | "Sedang Dikerjakan"
  | "Ready for Pull Request"
  | "Ready for Merge"
  | "Selesai";

export interface Paged<T> {
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
}

export interface CaseReview {
  review_id: number;
  source_review_id: string;
  teks_ulasan: string;
  rating: number;
  sumber?: string | null;
  tanggal_ulasan?: string | null;
}

export interface MaintenanceCase {
  case_id: number;
  judul_issue: string;
  kategori: string;
  modul: string;
  fitur: string;
  severity: Severity | string;
  /** Skor 0–10 dari UC01 (services/analysis). */
  priority_score: number;
  ringkasan_masalah: string;
  recommendation: string | null;
  status_validasi: CaseStatus;
  validated_at: string | null;
  validator?: Pick<AuthUser, "user_id" | "nama"> | null;
  created_at: string | null;
  review_count?: number;
  backlog_id?: number | null;
}

export interface MaintenanceCaseDetail extends MaintenanceCase {
  reviews: CaseReview[];
}

export interface CaseListQuery {
  page?: number;
  status?: CaseStatus | "";
  search?: string;
  kategori?: string;
  severity?: string;
}

export type CaseDecision = "Valid" | "Tidak Valid";

/** Respons POST /maintenance-cases/{id}/validate (MaintenanceCaseController, PR #31). */
export interface CaseValidationResult {
  case_id: number;
  status_validasi: CaseStatus;
  validator_id: number;
  validated_at: string;
  keputusan: "Ya" | "Tidak";
  backlog_id: number | null;
}

export interface PullRequestInfo {
  pr_id: number;
  github_pr_number: number;
  url: string;
  judul: string;
  source_branch: string;
  target_branch: string;
  /** Status GitHub lifecycle; UC05 hanya membuat PR berstatus Open. */
  status: "Open" | "Closed" | "Merged";
  created_at: string;
}

export interface MaintenanceBacklog {
  backlog_id: number;
  case_id: number;
  judul: string;
  kategori: string;
  modul: string;
  fitur: string;
  severity: Severity | string;
  priority_score: number;
  ringkasan_masalah: string;
  recommendation: string | null;
  status: BacklogStatus;
  assigned_to: Pick<AuthUser, "user_id" | "nama"> | null;
  assigned_at: string | null;
  source_branch: string | null;
  /** Diisi backend setelah push GitHub terverifikasi (UC04); null bila belum. */
  last_push: { sha: string; branch: string; pushed_at: string } | null;
  pull_request: PullRequestInfo | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface BacklogListQuery {
  page?: number;
  status?: BacklogStatus | "";
  search?: string;
  modul?: string;
  severity?: string;
}

/** Field yang boleh diubah Tim Maintenance pada UC03 selama status Ready for Development. */
export interface BacklogUpdate {
  judul: string;
  modul: string;
  fitur: string;
  severity: Severity | string;
  priority_score: number;
  ringkasan_masalah: string;
  recommendation: string | null;
}

export interface PullRequestOptions {
  source_branch: string;
  target_branches: string[];
  default_target: string | null;
  repository: string;
}

export interface PullRequestCreate {
  target_branch: string;
  judul: string;
  deskripsi: string;
}
