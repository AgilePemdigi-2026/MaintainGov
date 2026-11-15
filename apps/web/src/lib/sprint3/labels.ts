// Label dan pesan UC06 + Status/Riwayat (contracts/uc06-api.md §3, §5).
import type { PullRequestSummary, ReviewStatus, SyncStatus, TimelineType } from "./types";

export const REVIEW_MESSAGES = {
  /** FR-UC06-15: tampilkan persis bila backend tidak mengirim pesan lain. */
  changesRequested: "Perubahan memerlukan perbaikan.",
  approved: "Review berhasil disinkronkan. Pull Request disetujui; perubahan siap dilanjutkan ke proses merge.",
  syncFailed: "Review tersimpan tetapi belum tersinkron ke GitHub. Silakan coba kirim ulang.",
  commentRequired: "Komentar wajib diisi untuk Request Changes.",
} as const;

export const REVIEW_STATUSES: ReviewStatus[] = ["Open", "Approved", "Changes Requested", "Merged", "Closed"];

export const COMMENT_MAX = 5000;

/** Label badge: Ready for Merge menggantikan Approved saat ready_for_merge (FR-UC06-12). */
export function reviewBadge(pr: Pick<PullRequestSummary, "review_status" | "ready_for_merge" | "status_label">): { label: string; tone: string } {
  if (pr.ready_for_merge) return { label: "Ready for Merge", tone: "done" };
  const status = String(pr.review_status);
  const tone = ({
    Open: "ready",
    Approved: "done",
    "Changes Requested": "pending",
    Merged: "merged",
    Closed: "muted",
  } as Record<string, string>)[status] ?? "muted";
  return { label: pr.status_label || status, tone };
}

export const SYNC_LABEL: Record<SyncStatus, { label: string; tone: string }> = {
  synced: { label: "Tersinkron ke GitHub", tone: "done" },
  pending: { label: "Menunggu sinkron", tone: "pending" },
  gagal: { label: "Gagal sinkron", tone: "error" },
};

export const DECISION_TONE: Record<string, string> = {
  Approved: "done",
  "Changes Requested": "pending",
};

export const TIMELINE_LABEL: Record<TimelineType, string> = {
  case_created: "Case dibuat",
  case_validated: "Case divalidasi",
  backlog_created: "Backlog dibentuk",
  issue_claimed: "Issue diambil",
  push_detected: "Push terdeteksi",
  pull_request_submitted: "Pull Request diajukan",
  review_submitted: "Review diberikan",
};

/** Tahap alur maintenance untuk ringkasan Feedback → Case → Backlog → Issue → PR → Review. */
export const FLOW_STAGES: { key: string; label: string; events: string[] }[] = [
  { key: "feedback", label: "Feedback", events: ["case_created"] },
  { key: "case", label: "Case", events: ["case_validated"] },
  { key: "backlog", label: "Backlog", events: ["backlog_created"] },
  { key: "issue", label: "Issue", events: ["issue_claimed", "push_detected"] },
  { key: "pr", label: "Pull Request", events: ["pull_request_submitted"] },
  { key: "review", label: "Review", events: ["review_submitted"] },
];

export const prCode = (number: number | null | undefined, id: number) => (number ? `#${number}` : `PR-${id}`);

export const shortSha = (sha: string | null | undefined) => (sha ? sha.slice(0, 7) : "-");

/** Pesan error kode GitHub → kalimat Indonesia untuk peringatan sinkron. */
export function syncErrorText(code: string | null | undefined): string {
  switch (code) {
    case "GITHUB_UNAVAILABLE": return "GitHub sedang tidak dapat dihubungi.";
    case "GITHUB_RATE_LIMITED": return "Batas permintaan GitHub tercapai; tunggu sebentar.";
    case "GITHUB_NOT_CONFIGURED": return "Integrasi GitHub belum dikonfigurasi di server.";
    case "GITHUB_RESOURCE_NOT_FOUND": return "Pull Request tidak ditemukan lagi di GitHub.";
    case "GITHUB_VALIDATION_FAILED": return "GitHub menolak review ini.";
    default: return code ? `Kode: ${code}.` : "";
  }
}
