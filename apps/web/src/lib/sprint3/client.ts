// Typed API client Sprint 3 (UC06 + Status/Riwayat). Selalu memanggil Laravel nyata;
// tidak ada mock UC06 (kontrak uc06-api.md §6: mock GitHub tidak menyediakan stub review).
import { apiFetch } from "@/lib/api";
import type { Paged } from "@/lib/sprint2/types";
import type {
  CaseHistory, ChangedFile, CodeReview, PullRequestDetail, PullRequestListQuery, PullRequestSummary,
  ReviewInput, ReviewResult, ReviewSyncMeta,
} from "./types";

function qs(query: object = {}): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
  }
  const text = params.toString();
  return text ? `?${text}` : "";
}

export const sprint3Api = {
  listPullRequests: (query: PullRequestListQuery = {}) =>
    apiFetch<Paged<PullRequestSummary>>(`/pull-requests${qs(query)}`),

  getPullRequest: async (id: number): Promise<PullRequestDetail> => {
    const { data } = await apiFetch<{ data: PullRequestDetail }>(`/pull-requests/${id}`);
    return { ...data, reviews: data.reviews ?? [], issue: data.issue ?? null };
  },

  /**
   * Kirim keputusan review. Respons 200 dengan `meta.sync.status = gagal` BUKAN sukses:
   * review tersimpan tetapi keputusan belum berlaku (D-W14-01-e).
   */
  submitReview: async (id: number, input: ReviewInput): Promise<ReviewResult> => {
    const body = await apiFetch<{
      message: string;
      data: { review: CodeReview; pull_request: ReviewResult["pull_request"]; backlog: ReviewResult["backlog"] };
      meta?: { sync?: ReviewSyncMeta };
    }>(`/pull-requests/${id}/reviews`, {
      method: "POST",
      body: JSON.stringify({ decision: input.decision, ...(input.comment ? { comment: input.comment } : {}) }),
      headers: { "Content-Type": "application/json" },
    });
    return {
      message: body.message,
      review: body.data.review,
      pull_request: body.data.pull_request,
      backlog: body.data.backlog ?? null,
      sync: body.meta?.sync ?? { status: body.data.review.sync_status },
    };
  },

  getCaseHistory: async (caseId: number): Promise<CaseHistory> =>
    (await apiFetch<{ data: CaseHistory }>(`/maintenance-cases/${caseId}/history`)).data,
};

/**
 * Perubahan kode dibaca langsung dari GitHub REST publik (kontrak UC06 §1: endpoint backend
 * sengaja tidak memanggil GitHub untuk diff). Tanpa token; repository privat atau rate limit
 * menghasilkan null sehingga UI menampilkan tautan "Files changed" di GitHub.
 */
export async function fetchChangedFiles(repoFullName: string, number: number): Promise<ChangedFile[] | null> {
  if (!/^[\w.-]+\/[\w.-]+$/.test(repoFullName) || number < 1) return null;
  try {
    const response = await fetch(`https://api.github.com/repos/${repoFullName}/pulls/${number}/files?per_page=100`, {
      headers: { Accept: "application/vnd.github+json" },
      cache: "no-store",
    });
    if (!response.ok) return null;
    const rows = (await response.json()) as ChangedFile[];
    return Array.isArray(rows) ? rows : null;
  } catch {
    return null;
  }
}
