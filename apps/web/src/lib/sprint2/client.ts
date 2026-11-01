// Typed API client Sprint 2. Komponen hanya memanggil `sprint2Api`, sehingga pergantian
// mock -> Laravel live cukup lewat env NEXT_PUBLIC_SPRINT2_MOCK, tanpa mengubah komponen.
// Mode mock hanya untuk pengembangan UI; bukan bukti integrasi live.
import { apiFetch } from "@/lib/api";
import { mockApi } from "./mock";
import type {
  AuthUser, BacklogListQuery, BacklogUpdate, CaseDecision, CaseListQuery, CaseValidationResult,
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
  claimBacklog(id: number): Promise<MaintenanceBacklog>;
  getPullRequestOptions(id: number): Promise<PullRequestOptions>;
  createPullRequest(id: number, input: PullRequestCreate): Promise<PullRequestInfo>;
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
  listBacklogs: query => apiFetch<Paged<MaintenanceBacklog>>(`/maintenance-backlogs${qs(query)}`),
  getBacklog: async id => (await apiFetch<{ data: MaintenanceBacklog }>(`/maintenance-backlogs/${id}`)).data,
  updateBacklog: async (id, input) =>
    (await apiFetch<{ data: MaintenanceBacklog }>(`/maintenance-backlogs/${id}`, { method: "PATCH", ...json(input) })).data,
  claimBacklog: async id =>
    (await apiFetch<{ data: MaintenanceBacklog }>(`/maintenance-backlogs/${id}/claim`, { method: "POST" })).data,
  getPullRequestOptions: async id =>
    (await apiFetch<{ data: PullRequestOptions }>(`/maintenance-backlogs/${id}/pull-request-options`)).data,
  createPullRequest: async (id, input) =>
    (await apiFetch<{ data: PullRequestInfo }>(`/maintenance-backlogs/${id}/pull-requests`, { method: "POST", ...json(input) })).data,
};

export const isMockMode = process.env.NEXT_PUBLIC_SPRINT2_MOCK === "1";

export const sprint2Api: Sprint2Api = isMockMode ? mockApi : liveApi;
