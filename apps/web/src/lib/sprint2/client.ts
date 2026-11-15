// Typed API client Sprint 2. Komponen hanya memanggil `sprint2Api`, sehingga pergantian
// mock -> Laravel live cukup lewat env NEXT_PUBLIC_SPRINT2_MOCK, tanpa mengubah komponen.
// Mode mock hanya untuk pengembangan UI; bukan bukti integrasi live.
//
// Kontrak live memakai dua resource backend:
// - UC03 `GET/PATCH /maintenance-backlog` untuk halaman Tim Maintenance/backlog.
// - UC04 `GET /developer/maintenance-issues` untuk halaman Developer/maintenance issue.
// Adapter di bawah memetakan bentuk respons backend ke tipe UI Sprint 2.
import { apiFetch } from "@/lib/api";
import { mockApi } from "./mock";
import type {
  AuthUser, BacklogListQuery, BacklogStatus, BacklogUpdate, BranchPreparation, CaseDecision, CaseListQuery,
  CaseValidationResult, MaintenanceBacklog, MaintenanceCase, MaintenanceCaseDetail, Paged, PullRequestCreate,
  PullRequestDetail, PullRequestInfo, PullRequestListQuery, PullRequestOptions, PullRequestRecord, PullRequestReview,
  PullRequestReviewResult, PullRequestReviewState, PullRequestReviewSubmission, PullRequestSummary,
} from "./types";

export interface Sprint2Api {
  me(): Promise<AuthUser>;
  logout(): Promise<void>;
  listCases(query?: CaseListQuery): Promise<Paged<MaintenanceCase>>;
  getCase(id: number): Promise<MaintenanceCaseDetail>;
  validateCase(id: number, keputusan: CaseDecision): Promise<CaseValidationResult>;
  listBacklogs(query?: BacklogListQuery): Promise<Paged<MaintenanceBacklog>>;
  getBacklog(id: number): Promise<MaintenanceBacklog>;
  updateBacklog(id: number, input: BacklogUpdate): Promise<MaintenanceBacklog>;
  listIssues(query?: BacklogListQuery): Promise<Paged<MaintenanceBacklog>>;
  getIssue(id: number): Promise<MaintenanceBacklog>;
  claimBacklog(id: number): Promise<MaintenanceBacklog>;
  getPullRequestOptions(id: number): Promise<PullRequestOptions>;
  createPullRequest(id: number, input: PullRequestCreate): Promise<PullRequestInfo>;
  listPullRequests(query?: PullRequestListQuery): Promise<Paged<PullRequestSummary>>;
  getPullRequest(id: number): Promise<PullRequestDetail>;
  syncPullRequest(id: number): Promise<PullRequestDetail>;
  submitPullRequestReview(id: number, input: PullRequestReviewSubmission): Promise<PullRequestReviewResult>;
}

interface BackendPage<T> {
  data: T[];
  meta: Paged<T>["meta"];
}

interface BackendPullRequestRow {
  pull_request_id?: number;
  pr_id?: number;
  github_pr_number?: number | null;
  pull_request_number?: number | null;
  github_pr_url?: string | null;
  pull_request_url?: string | null;
  url?: string | null;
  title?: string;
  judul?: string;
  source_branch?: string;
  target_branch?: string;
  status?: string | null;
  lifecycle_state?: string | null;
  submission_state?: string | null;
  created_at?: string | null;
}

interface BackendPushRow {
  sha?: string | null;
  commit?: string | null;
  branch?: string | null;
  pushed_at?: string | null;
}

interface BackendBranchPreparationRow {
  status?: string | null;
  branch?: string | null;
  commit_sha?: string | null;
  message?: string | null;
  error_code?: string | null;
}

interface BackendBacklogRow {
  backlog_id?: number | string;
  case_id?: number | string;
  judul?: string;
  judul_issue?: string;
  kategori?: string;
  modul?: string;
  fitur?: string;
  severity?: string;
  priority_score?: number | string;
  ringkasan_masalah?: string;
  recommendation?: string | null;
  status?: string;
  assigned_to?: number | string | AuthUser | null;
  assignee_nama?: string | null;
  assigned_at?: string | null;
  source_branch?: string | null;
  last_push?: BackendPushRow | null;
  last_push_sha?: string | null;
  last_push_at?: string | null;
  pull_request?: BackendPullRequestRow | null;
  branch_preparation?: BackendBranchPreparationRow | null;
  created_at?: string | null;
  updated_at?: string | null;
}

interface BackendIssueRow extends BackendBacklogRow {
  issue_id?: number | string;
  title?: string;
  summary?: string;
  module?: string;
}

interface BackendBranches {
  source_branch?: string | null;
  suggested_target_branch?: string | null;
  default_branch?: string | null;
  branches?: { name: string }[];
  repository?: { full_name?: string | null } | null;
}

interface BackendPullRequestIssueRef {
  issue_id?: number | string;
  backlog_id?: number | string;
  title?: string | null;
  status?: string | null;
  source_branch?: string | null;
}

interface BackendPullRequestReviewRow {
  code_review_id?: number | string;
  github_review_id?: number | string | null;
  reviewer_id?: number | string | null;
  reviewer?: { user_id?: number | string | null; nama?: string | null; github_login?: string | null } | null;
  reviewer_login?: string | null;
  github_state?: string | null;
  decision?: string | null;
  body?: string | null;
  commit_id?: string | null;
  html_url?: string | null;
  source?: string | null;
  submitted_at?: string | null;
  synced_at?: string | null;
}

interface BackendPullRequestRecordRow {
  pull_request_id?: number | string;
  issue_id?: number | string;
  backlog_id?: number | string;
  developer_id?: number | string;
  developer?: { user_id?: number | string; nama?: string | null; github_login?: string | null } | null;
  author?: { user_id?: number | string | null; nama?: string | null; github_login?: string | null } | null;
  repository?: { full_name?: string | null; owner?: string | null; name?: string | null; html_url?: string | null } | null;
  source_branch?: string | null;
  target_branch?: string | null;
  title?: string | null;
  description?: string | null;
  github_pr_number?: number | string | null;
  pull_request_number?: number | string | null;
  github_pr_url?: string | null;
  pull_request_url?: string | null;
  lifecycle_state?: string | null;
  status?: string | null;
  submission_state?: string | null;
  is_draft?: boolean;
  review_state?: string | null;
  merged_at?: string | null;
  closed_at?: string | null;
  github_created_at?: string | null;
  github_updated_at?: string | null;
  last_synced_at?: string | null;
  head_sha?: string | null;
  base_sha?: string | null;
  created_at?: string | null;
  issue?: BackendPullRequestIssueRef | null;
  reviews_count?: number | string | null;
  reviews?: BackendPullRequestReviewRow[] | null;
  review_summary?: { approved?: number | string; changes_requested?: number | string; dismissed?: number | string } | null;
}

interface BackendPullRequestReviewResponse {
  data: BackendPullRequestRecordRow;
  message?: string;
  meta: {
    review?: BackendPullRequestReviewRow | null;
    created?: boolean;
    reconciled?: boolean;
    idempotent_replay?: boolean;
    sync_pending?: boolean;
  };
}

function qs(query: object = {}): string {
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== "") params.set(key, String(value));
  }
  const text = params.toString();
  return text ? `?${text}` : "";
}

const json = (body: unknown): RequestInit => ({
  body: JSON.stringify(body),
  headers: { "Content-Type": "application/json" },
});

const includesText = (value: string, search?: string) => !search || value.toLowerCase().includes(search.toLowerCase());

const asNumber = (value: unknown, fallback = 0): number => {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : fallback;
};

function mapPullRequest(row: BackendPullRequestRow): PullRequestInfo {
  // Never silently classify pending/failed submissions as a successful PR.
  const number = asNumber(row.github_pr_number ?? row.pull_request_number);
  const url = String(row.github_pr_url ?? row.pull_request_url ?? row.url ?? '');
  if (number <= 0 || !/^https:\/\/github\.com\//.test(url))
    throw new Error('GitHub belum mengonfirmasi nomor dan URL Pull Request.');
  const rawStatus = String(row.lifecycle_state ?? row.status ?? row.submission_state ?? "open").toLowerCase();
  const status = rawStatus === "merged" ? "Merged" : rawStatus === "closed" ? "Closed" : "Open";

  return {
    pr_id: asNumber(row.pull_request_id ?? row.pr_id),
    github_pr_number: number,
    url,
    judul: String(row.title ?? row.judul ?? ""),
    source_branch: String(row.source_branch ?? ""),
    target_branch: String(row.target_branch ?? ""),
    status,
    created_at: String(row.created_at ?? ""),
  };
}

function mapLastPush(row: BackendBacklogRow): MaintenanceBacklog["last_push"] {
  const push = row.last_push
    ?? (row.last_push_sha
      ? { commit: row.last_push_sha, branch: row.source_branch, pushed_at: row.last_push_at }
      : null);

  if (!push) return null;
  const sha = push.sha ?? push.commit ?? "";
  if (!sha) return null;

  return {
    sha,
    branch: String(push.branch ?? row.source_branch ?? ""),
    pushed_at: String(push.pushed_at ?? ""),
  };
}

function mapAssignedTo(value: BackendBacklogRow["assigned_to"], fallbackName?: string | null) {
  if (value === null || value === undefined) return null;
  if (typeof value === "object") {
    return { user_id: asNumber(value.user_id), nama: String(value.nama ?? "") };
  }

  return {
    user_id: asNumber(value),
    nama: fallbackName || `Pengguna #${value}`,
  };
}

function mapBranchPreparation(value: BackendBranchPreparationRow | null | undefined): BranchPreparation | null {
  if (!value) return null;
  const status = value.status;
  if (status !== "created" && status !== "exists" && status !== "failed") return null;

  return {
    status,
    branch: String(value.branch ?? ""),
    commit_sha: value.commit_sha ?? null,
    message: String(value.message ?? ""),
    error_code: value.error_code ?? null,
  };
}

function mapBacklog(row: BackendBacklogRow): MaintenanceBacklog {
  return {
    backlog_id: asNumber(row.backlog_id),
    case_id: asNumber(row.case_id),
    judul: String(row.judul_issue ?? row.judul ?? ""),
    kategori: String(row.kategori ?? ""),
    modul: String(row.modul ?? ""),
    fitur: String(row.fitur ?? ""),
    severity: String(row.severity ?? ""),
    priority_score: asNumber(row.priority_score),
    ringkasan_masalah: String(row.ringkasan_masalah ?? ""),
    recommendation: row.recommendation ?? null,
    status: String(row.status ?? "Ready for Development") as BacklogStatus,
    assigned_to: mapAssignedTo(row.assigned_to, row.assignee_nama),
    assigned_at: row.assigned_at ?? null,
    source_branch: row.source_branch ?? null,
    last_push: mapLastPush(row),
    pull_request: row.pull_request ? mapPullRequest(row.pull_request) : null,
    branch_preparation: mapBranchPreparation(row.branch_preparation),
    created_at: row.created_at ?? null,
    updated_at: row.updated_at ?? null,
  };
}

function mapIssue(row: BackendIssueRow): MaintenanceBacklog {
  return {
    backlog_id: asNumber(row.backlog_id ?? row.issue_id),
    case_id: asNumber(row.case_id),
    judul: String(row.title ?? row.judul ?? ""),
    kategori: String(row.kategori ?? ""),
    modul: String(row.module ?? row.modul ?? ""),
    fitur: String(row.fitur ?? ""),
    severity: String(row.severity ?? ""),
    priority_score: asNumber(row.priority_score),
    ringkasan_masalah: String(row.summary ?? row.ringkasan_masalah ?? ""),
    recommendation: row.recommendation ?? null,
    status: String(row.status ?? "Ready for Development") as BacklogStatus,
    assigned_to: mapAssignedTo(row.assigned_to, row.assignee_nama),
    assigned_at: row.assigned_at ?? null,
    source_branch: row.source_branch ?? null,
    last_push: mapLastPush(row),
    pull_request: row.pull_request ? mapPullRequest(row.pull_request) : null,
    branch_preparation: mapBranchPreparation(row.branch_preparation),
    created_at: row.created_at ?? null,
    updated_at: row.updated_at ?? null,
  };
}

function mapBranchOptions(data: BackendBranches): PullRequestOptions {
  return {
    source_branch: String(data.source_branch ?? ""),
    target_branches: (data.branches ?? []).map(branch => branch.name)
      .filter(name => name !== data.source_branch),
    default_target: data.suggested_target_branch ?? data.default_branch ?? null,
    repository: String(data.repository?.full_name ?? ""),
  };
}

function normalizeLifecycle(value: unknown): PullRequestRecord["lifecycle_state"] {
  const state = String(value ?? "open").toLowerCase();
  if (state === "merged") return "merged";
  if (state === "closed") return "closed";
  return "open";
}

function normalizeReviewState(value: unknown): PullRequestReviewState {
  const state = String(value ?? "pending").toLowerCase();
  return state === "approved" || state === "changes_requested" ? state : "pending";
}

function mapPullRequestReviewer(
  row: { user_id?: number | string | null; nama?: string | null; github_login?: string | null } | null | undefined,
): PullRequestReview["reviewer"] {
  return {
    user_id: row?.user_id === null || row?.user_id === undefined ? null : asNumber(row.user_id),
    nama: row?.nama ?? null,
    github_login: row?.github_login ?? null,
  };
}

function mapPullRequestReview(row: BackendPullRequestReviewRow): PullRequestReview {
  const githubState = String(row.github_state ?? "PENDING").toUpperCase();
  const decision = row.decision === "approved" || row.decision === "changes_requested"
    || row.decision === "dismissed" || row.decision === "commented"
    ? row.decision
    : githubState === "APPROVED" ? "approved"
      : githubState === "CHANGES_REQUESTED" ? "changes_requested"
        : githubState === "DISMISSED" ? "dismissed" : "commented";

  return {
    code_review_id: asNumber(row.code_review_id),
    github_review_id: row.github_review_id === null || row.github_review_id === undefined ? null : asNumber(row.github_review_id),
    reviewer_id: row.reviewer_id === null || row.reviewer_id === undefined ? null : asNumber(row.reviewer_id),
    reviewer: mapPullRequestReviewer(row.reviewer),
    reviewer_login: String(row.reviewer_login ?? row.reviewer?.github_login ?? ""),
    github_state: githubState,
    decision,
    body: row.body ?? null,
    commit_id: row.commit_id ?? null,
    html_url: row.html_url ?? null,
    source: String(row.source ?? ""),
    submitted_at: row.submitted_at ?? null,
    synced_at: row.synced_at ?? null,
  };
}

function mapPullRequestRecord(row: BackendPullRequestRecordRow): PullRequestRecord {
  return {
    pull_request_id: asNumber(row.pull_request_id),
    issue_id: asNumber(row.issue_id ?? row.backlog_id),
    backlog_id: asNumber(row.backlog_id ?? row.issue_id),
    developer_id: asNumber(row.developer_id),
    developer: row.developer
      ? {
        user_id: asNumber(row.developer.user_id),
        nama: row.developer.nama ?? null,
        github_login: row.developer.github_login ?? null,
      }
      : null,
    author: row.author
      ? mapPullRequestReviewer(row.author)
      : mapPullRequestReviewer(row.developer),
    repository: {
      full_name: String(row.repository?.full_name ?? ""),
      owner: row.repository?.owner ?? null,
      name: row.repository?.name ?? null,
      html_url: row.repository?.html_url ?? null,
    },
    source_branch: String(row.source_branch ?? ""),
    target_branch: String(row.target_branch ?? ""),
    title: String(row.title ?? ""),
    description: row.description ?? null,
    github_pr_number: asNumber(row.github_pr_number ?? row.pull_request_number),
    github_pr_url: String(row.github_pr_url ?? row.pull_request_url ?? ""),
    lifecycle_state: normalizeLifecycle(row.lifecycle_state ?? row.status),
    is_draft: Boolean(row.is_draft),
    review_state: normalizeReviewState(row.review_state),
    merged_at: row.merged_at ?? null,
    closed_at: row.closed_at ?? null,
    github_created_at: row.github_created_at ?? null,
    github_updated_at: row.github_updated_at ?? null,
    last_synced_at: row.last_synced_at ?? null,
    head_sha: row.head_sha ?? null,
    base_sha: row.base_sha ?? null,
    created_at: row.created_at ?? null,
    issue: row.issue
      ? {
        issue_id: asNumber(row.issue.issue_id ?? row.issue.backlog_id),
        backlog_id: asNumber(row.issue.backlog_id ?? row.issue.issue_id),
        title: row.issue.title ?? null,
        status: String(row.issue.status ?? "Ready for Pull Request") as BacklogStatus,
        source_branch: row.issue.source_branch ?? null,
      }
      : null,
  };
}

function mapPullRequestSummary(row: BackendPullRequestRecordRow): PullRequestSummary {
  return { ...mapPullRequestRecord(row), reviews_count: asNumber(row.reviews_count) };
}

function mapPullRequestDetail(row: BackendPullRequestRecordRow): PullRequestDetail {
  const reviews = (row.reviews ?? []).map(mapPullRequestReview);
  const summary = row.review_summary;

  return {
    ...mapPullRequestRecord(row),
    reviews,
    review_summary: {
      approved: asNumber(summary?.approved ?? reviews.filter(review => review.github_state === "APPROVED").length),
      changes_requested: asNumber(
        summary?.changes_requested ?? reviews.filter(review => review.github_state === "CHANGES_REQUESTED").length,
      ),
      dismissed: asNumber(summary?.dismissed ?? reviews.filter(review => review.github_state === "DISMISSED").length),
    },
  };
}

async function fetchBacklog(id: number): Promise<MaintenanceBacklog> {
  const { data } = await apiFetch<{ data: BackendBacklogRow }>(`/maintenance-backlog/${id}`);
  return mapBacklog(data);
}

async function fetchIssue(id: number): Promise<MaintenanceBacklog> {
  const { data } = await apiFetch<{ data: BackendIssueRow }>(`/developer/maintenance-issues/${id}`);
  const issue = mapIssue(data);

  // UC04 detail tidak menyertakan metadata PR; ambil sekali agar halaman PR tetap
  // menampilkan PR yang sudah ada setelah reload.
  if (issue.status === "Ready for Pull Request" && !issue.pull_request) {
    try {
      const { data: pullRequests } = await apiFetch<{ data: BackendPullRequestRow[] }>(
        `/maintenance-backlogs/${id}/pull-requests`,
      );
      // A failed/pending submission is NOT a real GitHub PR. Do not lock the UI
      // behind a fake 'Open' PR with number 0 on reload.
      const existing = pullRequests.find(row =>
        row.submission_state === 'created' &&
        Number(row.github_pr_number ?? row.pull_request_number ?? 0) > 0 &&
        !!(row.github_pr_url ?? row.pull_request_url ?? row.url),
      );
      if (existing) issue.pull_request = mapPullRequest(existing);
    } catch {
      // Metadata PR bersifat pelengkap; kegagalan baca tidak boleh menggagalkan detail issue.
    }
  }

  return issue;
}

export const liveApi: Sprint2Api = {
  me: async () => (await apiFetch<{ data: AuthUser }>("/auth/me")).data,
  logout: async () => { await apiFetch("/auth/logout", { method: "POST" }); },
  // Backend UC02 hanya memfilter status + paginasi; search/kategori/severity disaring di halaman yang dimuat.
  listCases: async ({ search, kategori, severity, ...query } = {}) => {
    const page = await apiFetch<Paged<MaintenanceCase>>(`/maintenance-cases${qs(query)}`);
    const data = page.data
      .filter(item => includesText(`${item.judul_issue} ${item.modul} ${item.case_id}`, search))
      .filter(item => !kategori || item.kategori.toLowerCase() === kategori.toLowerCase())
      .filter(item => !severity || String(item.severity).toLowerCase() === severity.toLowerCase());
    return { ...page, data };
  },
  getCase: async id => {
    const { data } = await apiFetch<{ data: { case: MaintenanceCase; reviews: MaintenanceCaseDetail["reviews"] } }>(`/maintenance-cases/${id}`);
    return { ...data.case, reviews: data.reviews };
  },
  validateCase: async (id, decision) =>
    (await apiFetch<{ data: CaseValidationResult }>(`/maintenance-cases/${id}/validate`, {
      method: "POST", ...json({ keputusan: decision === "Valid" ? "Ya" : "Tidak" }),
    })).data,
  // UC03: path backend singular. Filter search/modul/severity belum didukung backend,
  // jadi disaring pada halaman yang dimuat tanpa mengubah query status/paginasi.
  listBacklogs: async ({ search, modul, severity, ...query } = {}) => {
    const page = await apiFetch<BackendPage<BackendBacklogRow>>(`/maintenance-backlog${qs(query)}`);
    const data = page.data
      .map(mapBacklog)
      .filter(item => includesText(`${item.judul} ${item.modul} ${item.backlog_id} ${item.case_id}`, search))
      .filter(item => !modul || item.modul.toLowerCase() === modul.toLowerCase())
      .filter(item => !severity || String(item.severity).toLowerCase() === severity.toLowerCase());
    return { ...page, data };
  },
  getBacklog: fetchBacklog,
  updateBacklog: async (id, input) => {
    await apiFetch(`/maintenance-backlog/${id}`, {
      method: "PATCH",
      ...json({
        judul: input.judul,
        modul: input.modul,
        fitur: input.fitur,
        severity: input.severity,
        priority_score: input.priority_score,
        ringkasan_masalah: input.ringkasan_masalah,
        recommendation: input.recommendation,
      }),
    });
    return fetchBacklog(id);
  },
  // UC04: daftar/detail issue Developer memakai namespace khusus Developer.
  listIssues: async ({ search, modul, severity, ...query } = {}) => {
    const page = await apiFetch<BackendPage<BackendIssueRow>>(`/developer/maintenance-issues${qs(query)}`);
    const data = page.data
      .map(mapIssue)
      .filter(item => includesText(`${item.judul} ${item.modul} ${item.backlog_id} ${item.case_id}`, search))
      .filter(item => !modul || item.modul.toLowerCase() === modul.toLowerCase())
      .filter(item => !severity || String(item.severity).toLowerCase() === severity.toLowerCase());
    return { ...page, data };
  },
  getIssue: fetchIssue,
  claimBacklog: async id => {
    const { data } = await apiFetch<{ data: BackendIssueRow }>(`/maintenance-backlogs/${id}/claim`, { method: "POST" });
    return mapIssue(data);
  },
  getPullRequestOptions: async id => {
    const { data } = await apiFetch<{ data: BackendBranches }>(`/maintenance-backlogs/${id}/branches`);
    return mapBranchOptions(data);
  },
  createPullRequest: async (id, input) => {
    // Kontrak backend membutuhkan source_branch dari issue yang sudah diverifikasi.
    const issue = await fetchIssue(id);
    const { data } = await apiFetch<{ data: BackendPullRequestRow }>(`/maintenance-backlogs/${id}/pull-requests`, {
      method: "POST",
      ...json({
        source_branch: issue.source_branch ?? "",
        target_branch: input.target_branch,
        title: input.judul,
        description: input.deskripsi,
      }),
    });
    return mapPullRequest(data);
  },
  // UC06: list/detail/sync/review. Backend tetap otoritatif atas akses dan
  // identitas reviewer; klien hanya menampilkan hasilnya.
  listPullRequests: async (query = {}) => {
    const page = await apiFetch<BackendPage<BackendPullRequestRecordRow>>(`/pull-requests${qs(query)}`);
    return { ...page, data: page.data.map(mapPullRequestSummary) };
  },
  getPullRequest: async id => {
    const { data } = await apiFetch<{ data: BackendPullRequestRecordRow }>(`/pull-requests/${id}`);
    return mapPullRequestDetail(data);
  },
  syncPullRequest: async id => {
    const { data } = await apiFetch<{ data: BackendPullRequestRecordRow }>(`/pull-requests/${id}/sync`, {
      method: "POST",
      ...json({}),
    });
    return mapPullRequestDetail(data);
  },
  submitPullRequestReview: async (id, input) => {
    const headers: Record<string, string> = { "Content-Type": "application/json" };

    if (input.idempotency_key) {
      headers["Idempotency-Key"] = input.idempotency_key;
    }

    const response = await apiFetch<BackendPullRequestReviewResponse>(`/pull-requests/${id}/reviews`, {
      method: "POST",
      body: JSON.stringify({ decision: input.decision, notes: input.notes ?? "" }),
      headers,
    });

    if (!response.meta?.review) {
      throw new Error("GitHub tidak mengembalikan data review.");
    }

    return {
      pull_request: mapPullRequestDetail(response.data),
      review: mapPullRequestReview(response.meta.review),
      created: Boolean(response.meta.created),
      reconciled: Boolean(response.meta.reconciled),
      idempotent_replay: Boolean(response.meta.idempotent_replay),
      sync_pending: Boolean(response.meta.sync_pending),
      message: response.message ?? "Keputusan review tersimpan.",
    };
  },
};

/** Real GitHub operations. Always called via Laravel; token stays on the server. */
export const githubSprint2 = {
  async checkConnection(): Promise<void> {
    const { data } = await apiFetch<{data: { status?: string }}>("/github/connection");
    if (data.status !== 'connected') throw new Error('GitHub belum terhubung. Periksa token pada Laravel.');
  },
  async verifyPush(id: number): Promise<void> {
    await apiFetch(`/maintenance-backlogs/${id}/push-verifications`, {
      method: 'POST', ...json({}),
    });
  },
  async getPushStatus(id: number): Promise<{verified: boolean; issue_status: string}> {
    const { data } = await apiFetch<{data: {verified: boolean; issue_status: string}}>(
      `/maintenance-backlogs/${id}/push-status`);
    return data;
  },
  async reconcile(id: number): Promise<void> {
    await apiFetch(`/maintenance-backlogs/${id}/pull-requests/reconcile`, {
      method: 'POST', ...json({}),
    });
  },
};

export const isMockMode = process.env.NEXT_PUBLIC_SPRINT2_MOCK === "1";

export const sprint2Api: Sprint2Api = isMockMode ? mockApi : liveApi;
