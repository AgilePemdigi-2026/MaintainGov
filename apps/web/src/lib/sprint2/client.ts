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
  AuthUser, BacklogListQuery, BacklogStatus, BacklogUpdate, CaseDecision, CaseListQuery, CaseValidationResult,
  MaintenanceBacklog, MaintenanceCase, MaintenanceCaseDetail, Paged, PullRequestCreate, PullRequestInfo,
  PullRequestOptions,
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
  const rawStatus = String(row.lifecycle_state ?? row.status ?? row.submission_state ?? "open").toLowerCase();
  const status = rawStatus === "merged" ? "Merged" : rawStatus === "closed" ? "Closed" : "Open";

  return {
    pr_id: asNumber(row.pull_request_id ?? row.pr_id),
    github_pr_number: asNumber(row.github_pr_number ?? row.pull_request_number),
    url: String(row.github_pr_url ?? row.pull_request_url ?? row.url ?? ""),
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
    created_at: row.created_at ?? null,
    updated_at: row.updated_at ?? null,
  };
}

function mapBranchOptions(data: BackendBranches): PullRequestOptions {
  return {
    source_branch: String(data.source_branch ?? ""),
    target_branches: (data.branches ?? []).map(branch => branch.name),
    default_target: data.suggested_target_branch ?? data.default_branch ?? null,
    repository: String(data.repository?.full_name ?? ""),
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
      if (pullRequests.length > 0) issue.pull_request = mapPullRequest(pullRequests[0]);
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
};

export const isMockMode = process.env.NEXT_PUBLIC_SPRINT2_MOCK === "1";

export const sprint2Api: Sprint2Api = isMockMode ? mockApi : liveApi;
