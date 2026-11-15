// Kontrak frontend Sprint 3 (UC06 Review Pull Request + Status/Riwayat).
// Sumber kebenaran: contracts/uc06-api.md §4–§5. Nama field mengikuti respons Laravel.

export type ReviewStatus = "Open" | "Approved" | "Changes Requested" | "Merged" | "Closed";
export type ReviewState = "pending" | "approved" | "changes_requested";
export type ReviewDecision = "Approved" | "Changes Requested";
export type SyncStatus = "pending" | "synced" | "gagal";

export interface PersonRef {
  user_id: number;
  nama: string;
  github_login?: string | null;
}

export interface CodeReview {
  review_id: number;
  pull_request_id?: number;
  reviewer?: PersonRef | null;
  decision: ReviewDecision;
  comment: string | null;
  reviewed_at: string | null;
  sync_status: SyncStatus;
  synced_at: string | null;
  last_sync_error_code: string | null;
  github_review_id?: number | null;
  github_review_url?: string | null;
}

export interface PullRequestSummary {
  pull_request_id: number;
  issue_id: number;
  backlog_id: number;
  title: string;
  description?: string | null;
  source_branch: string;
  target_branch: string;
  /** Status mentah UC05 (lifecycle_state bila ada, selain itu submission_state). */
  status: string;
  review_status: ReviewStatus | string;
  review_state: ReviewState;
  ready_for_merge: boolean;
  status_label: string;
  submission_state: string;
  lifecycle_state: string | null;
  developer: PersonRef | null;
  repository?: { full_name: string; owner: string; name: string; html_url: string } | null;
  pull_request_number: number | null;
  pull_request_url: string | null;
  head_sha: string | null;
  base_sha: string | null;
  review_count: number;
  last_review: CodeReview | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface PullRequestIssue {
  backlog_id: number;
  title: string;
  status: string;
  assigned_to: PersonRef | null;
  case: {
    case_id: number;
    judul_issue: string;
    kategori: string;
    severity: string;
    status_validasi: string;
    ringkasan_masalah: string;
  } | null;
}

export interface PullRequestDetail extends PullRequestSummary {
  issue: PullRequestIssue | null;
  reviews: CodeReview[];
}

export interface PullRequestListQuery {
  status?: ReviewStatus | "";
  page?: number;
}

export interface ReviewInput {
  decision: "approve" | "request_changes";
  comment?: string;
}

export interface ReviewSyncMeta {
  status: SyncStatus;
  code?: string | null;
  retry_after_seconds?: number | null;
}

export interface ReviewResult {
  message: string;
  review: CodeReview;
  pull_request: Pick<PullRequestSummary, "pull_request_id" | "review_status" | "review_state" | "ready_for_merge" | "status_label"> & Partial<PullRequestSummary>;
  backlog: { backlog_id: number; status: string } | null;
  sync: ReviewSyncMeta;
}

export type TimelineType =
  | "case_created"
  | "case_validated"
  | "backlog_created"
  | "issue_claimed"
  | "push_detected"
  | "pull_request_submitted"
  | "review_submitted";

export interface TimelineEvent {
  type: TimelineType | string;
  occurred_at: string | null;
  actor: { user_id: number; nama: string; role: string } | null;
  detail: Record<string, unknown>;
}

export interface CaseHistory {
  case: {
    case_id: number;
    judul_issue: string;
    status_validasi: string;
    severity: string;
    kategori: string;
    modul?: string;
    fitur?: string;
    ringkasan_masalah?: string;
  };
  timeline: TimelineEvent[];
  pull_requests: {
    pull_request_id: number;
    pull_request_number: number | null;
    title: string;
    review_status: string;
    ready_for_merge: boolean;
    lifecycle_state: string | null;
    submission_state?: string;
  }[];
}

/** Berkas berubah dari GitHub REST publik (`GET /repos/{repo}/pulls/{n}/files`). */
export interface ChangedFile {
  filename: string;
  status: string;
  additions: number;
  deletions: number;
  patch?: string;
}
