// Fixture typed untuk pengembangan UI tanpa backend (NEXT_PUBLIC_SPRINT2_MOCK=1).
// Data contoh, bukan hasil analisis nyata. State disimpan di sessionStorage agar refresh konsisten.
import { ApiError } from "@/lib/api";
import type { Sprint2Api } from "./client";
import type {
  AuthUser, BacklogListQuery, BacklogStatus, CaseReview, MaintenanceBacklog, MaintenanceCase, MaintenanceCaseDetail, Paged,
  PullRequestDetail, PullRequestReview, PullRequestReviewResult, PullRequestReviewState,
  PullRequestReviewSubmission, PullRequestSummary,
} from "./types";

const STATE_KEY = "mg-sprint2-mock-state-v2";
export const MOCK_ROLE_KEY = "mg-mock-role";
const MOCK_BASE_SHA = "0000000000000000000000000000000000000000";
const MOCK_HEAD_SHA = "9f2c1ab4a1d3c5e7f90123456789abcdef012345";

const USERS: Record<string, AuthUser> = {
  maintenance: { user_id: 1, nama: "Budi Prasetyo", email: "maintenance@maintaingov.test", role: "Tim Maintenance" },
  developer: { user_id: 2, nama: "Raditya Fahmi", email: "developer@maintaingov.test", role: "Developer" },
};
const GITHUB_LOGIN: Record<string, string> = {
  maintenance: "budi-reviewer",
  developer: "raditya-dev",
};
const OTHER_DEV = { user_id: 3, nama: "Nadia Putri", github_login: "nadia-dev" };
const REPOSITORY = {
  full_name: "AgilePemdigi-2026/MaintainGov",
  owner: "AgilePemdigi-2026",
  name: "MaintainGov",
  html_url: "https://github.com/AgilePemdigi-2026/MaintainGov",
};

interface MockState {
  cases: MaintenanceCaseDetail[];
  backlogs: MaintenanceBacklog[];
  pullRequests: PullRequestDetail[];
  nextBacklogId: number;
  nextPrId: number;
  nextReviewId: number;
}

const review = (id: number, teks: string, rating: number, sumber: string, tanggal: string): CaseReview =>
  ({ review_id: id, source_review_id: `rv-${id}`, teks_ulasan: teks, rating, sumber, tanggal_ulasan: tanggal });

function seedCase(id: number, judul: string, kategori: string, modul: string, fitur: string, severity: string, score: number, ringkasan: string, rekomendasi: string, reviews: CaseReview[]): MaintenanceCaseDetail {
  return {
    case_id: id, judul_issue: judul, kategori, modul, fitur, severity, priority_score: score,
    ringkasan_masalah: ringkasan, recommendation: rekomendasi, status_validasi: "Pending", validated_at: null,
    validator: null, created_at: "2026-10-24T08:32:00+07:00", review_count: reviews.length, backlog_id: null, reviews,
  };
}

function seedBacklog(id: number, caseId: number, judul: string, kategori: string, modul: string, fitur: string, severity: string, score: number, status: BacklogStatus, extra: Partial<MaintenanceBacklog> = {}): MaintenanceBacklog {
  return {
    backlog_id: id, case_id: caseId, judul, kategori, modul, fitur, severity, priority_score: score,
    ringkasan_masalah: `Ringkasan teknis untuk ${judul.toLowerCase()}.`, recommendation: "Lihat rekomendasi pada case terkait.",
    status, assigned_to: null, assigned_at: null, source_branch: null, last_push: null, pull_request: null,
    created_at: "2026-10-24T08:42:00+07:00", updated_at: "2026-10-24T10:15:00+07:00", ...extra,
  };
}

function seedReview(
  reviewId: number,
  githubState: "APPROVED" | "CHANGES_REQUESTED",
  body: string | null,
  commitId: string,
  submittedAt: string,
): PullRequestReview {
  return {
    code_review_id: reviewId,
    github_review_id: 7000 + reviewId,
    reviewer_id: USERS.maintenance.user_id,
    reviewer: {
      user_id: USERS.maintenance.user_id,
      nama: USERS.maintenance.nama,
      github_login: GITHUB_LOGIN.maintenance,
    },
    reviewer_login: GITHUB_LOGIN.maintenance,
    github_state: githubState,
    decision: githubState === "APPROVED" ? "approved" : "changes_requested",
    body,
    commit_id: commitId,
    html_url: null,
    source: "github_sync",
    submitted_at: submittedAt,
    synced_at: submittedAt,
  };
}

function summarizeReviews(reviews: PullRequestReview[]): PullRequestDetail["review_summary"] {
  return {
    approved: reviews.filter(review => review.github_state === "APPROVED").length,
    changes_requested: reviews.filter(review => review.github_state === "CHANGES_REQUESTED").length,
    dismissed: reviews.filter(review => review.github_state === "DISMISSED").length,
  };
}

function buildPullRequest(options: {
  id: number;
  backlog: MaintenanceBacklog;
  developer: { user_id: number; nama: string; github_login: string };
  headSha: string;
  lifecycle?: "open" | "merged";
  reviewState?: PullRequestReviewState;
  reviews?: PullRequestReview[];
  createdAt?: string;
  mergedAt?: string | null;
  title?: string;
  description?: string;
}): PullRequestDetail {
  const {
    id, backlog, developer, headSha, lifecycle = "open", reviewState = "pending",
    reviews = [], createdAt = "2026-10-29T09:00:00+07:00", mergedAt = null,
  } = options;
  const title = options.title ?? backlog.judul;
  const sourceBranch = backlog.source_branch ?? `maintenance/issue-${backlog.backlog_id}`;

  return {
    pull_request_id: id,
    issue_id: backlog.backlog_id,
    backlog_id: backlog.backlog_id,
    developer_id: developer.user_id,
    developer: { ...developer },
    author: { ...developer },
    repository: { ...REPOSITORY },
    source_branch: sourceBranch,
    target_branch: "main",
    title,
    description: options.description ?? `Perubahan untuk ${title.toLowerCase()}.`,
    github_pr_number: id,
    github_pr_url: `${REPOSITORY.html_url}/pull/${id}`,
    lifecycle_state: lifecycle,
    is_draft: false,
    review_state: lifecycle === "merged" ? "approved" : reviewState,
    merged_at: mergedAt,
    closed_at: lifecycle === "open" ? null : mergedAt ?? createdAt,
    github_created_at: createdAt,
    github_updated_at: mergedAt ?? createdAt,
    last_synced_at: mergedAt ?? createdAt,
    head_sha: headSha,
    base_sha: MOCK_BASE_SHA,
    created_at: createdAt,
    issue: {
      issue_id: backlog.backlog_id,
      backlog_id: backlog.backlog_id,
      title: backlog.judul,
      status: backlog.status,
      source_branch: sourceBranch,
    },
    reviews,
    review_summary: summarizeReviews(reviews),
  };
}

function toPullRequestInfo(pr: PullRequestDetail): NonNullable<MaintenanceBacklog["pull_request"]> {
  return {
    pr_id: pr.pull_request_id,
    github_pr_number: pr.github_pr_number,
    url: pr.github_pr_url,
    judul: pr.title,
    source_branch: pr.source_branch,
    target_branch: pr.target_branch,
    status: pr.lifecycle_state === "merged" ? "Merged" : pr.lifecycle_state === "closed" ? "Closed" : "Open",
    created_at: pr.created_at ?? new Date().toISOString(),
  };
}

function seed(): MockState {
  const backlogs: MaintenanceBacklog[] = [
    seedBacklog(89, 180, "Optimasi connection pool SSO gateway", "Bug", "Autentikasi", "SSO KTP", "Critical", 9.6, "Ready for Development"),
    seedBacklog(91, 181, "Perbaikan timeout integrasi QRIS payment", "Bug", "Pembayaran", "QRIS Dynamic", "High", 8.5, "Sedang Dikerjakan",
      { assigned_to: OTHER_DEV, assigned_at: "2026-10-25T09:00:00+07:00", source_branch: "fix/qris-timeout-91" }),
    seedBacklog(94, 182, "Sinkronisasi socket antrean faskes puskesmas", "Performance", "Kesehatan", "Antrean Online", "Medium", 6.4, "Ready for Pull Request",
      { assigned_to: { user_id: 2, nama: "Raditya Fahmi" }, assigned_at: "2026-10-25T10:00:00+07:00", source_branch: "fix/antrean-socket-94",
        last_push: { sha: MOCK_HEAD_SHA, branch: "fix/antrean-socket-94", pushed_at: "2026-10-26T14:20:00+07:00" } }),
    seedBacklog(98, 183, "Perbaikan queue background worker notifikasi IMB", "Notification", "Perizinan", "FCM Push", "Low", 3.1, "Selesai",
      { assigned_to: OTHER_DEV, assigned_at: "2026-10-23T09:00:00+07:00", source_branch: "fix/notif-imb-98",
        last_push: { sha: "1a2b3c4d5e6f708192a3b4c5d6e7f80912345678", branch: "fix/notif-imb-98", pushed_at: "2026-10-27T11:00:00+07:00" } }),
    seedBacklog(103, 184, "Caching GeoJSON peta zonasi bansos", "Performance", "Sosial", "Web GIS", "High", 8.0, "Ready for Development"),
    seedBacklog(105, 185, "Validasi format NIK pada formulir bansos", "Bug", "Sosial", "Formulir Bansos", "Medium", 5.0, "Ready for Development",
      { assigned_to: null }),
  ];

  const prPending = buildPullRequest({
    id: 42,
    backlog: backlogs[2],
    developer: { user_id: USERS.developer.user_id, nama: USERS.developer.nama, github_login: GITHUB_LOGIN.developer },
    headSha: MOCK_HEAD_SHA,
    createdAt: "2026-10-28T10:05:00+07:00",
    description: "Memperbaiki sinkronisasi socket antrean agar nomor di aplikasi sama dengan faskes.",
  });
  const approvedReview = seedReview(
    9001, "APPROVED", "Perubahan sudah sesuai dan siap digabungkan.", MOCK_HEAD_SHA, "2026-10-28T13:30:00+07:00",
  );
  const prMerged = buildPullRequest({
    id: 41,
    backlog: backlogs[3],
    developer: OTHER_DEV,
    headSha: "1a2b3c4d5e6f708192a3b4c5d6e7f80912345678",
    lifecycle: "merged",
    reviews: [approvedReview],
    createdAt: "2026-10-27T11:40:00+07:00",
    mergedAt: "2026-10-28T15:10:00+07:00",
    description: "Menstabilkan worker notifikasi IMB dan menambah retry FCM.",
  });

  for (const pr of [prPending, prMerged]) {
    const backlog = backlogs.find(item => item.backlog_id === pr.backlog_id);
    if (backlog) backlog.pull_request = toPullRequestInfo(pr);
  }

  return {
    nextBacklogId: 106, nextPrId: 43, nextReviewId: 9002,
    cases: [
      seedCase(192, "Gagal login SSO KTP Digital saat jam sibuk", "Bug", "Autentikasi & Akun", "Single Sign-On (SSO)", "Critical", 9.4,
        "Pengguna melaporkan pesan error \"Koneksi gateway Dukcapil terputus\" saat login via KTP Digital pada pukul 08.00–10.00 WIB.",
        "Periksa timeout connection pool pada auth-gateway dan tambahkan circuit breaker fallback.",
        [review(5011, "Mau urus KTP pagi-pagi aplikasi malah muter terus keluar error gateway.", 1, "Play Store", "2026-10-24 08:44:00"),
          review(5012, "Login pakai KTP digital mental terus dari kemarin subuh.", 1, "Kanal Aduan Pemda", "2026-10-24 09:12:00"),
          review(5013, "Sering log out sendiri pas jam kerja dan tidak bisa login lagi.", 2, "App Store", "2026-10-23 17:30:00")]),
      seedCase(193, "Pembayaran retribusi QRIS sering timeout", "Bug", "Modul Pembayaran", "QRIS Dinamis", "High", 8.8,
        "Transaksi QRIS retribusi gagal dikonfirmasi dalam 30 detik sehingga warga membayar dua kali.",
        "Tambahkan idempotency key dan perpanjang timeout callback bank.",
        [review(5021, "Bayar retribusi pasar lewat QRIS saldo kepotong tapi status gagal.", 1, "Play Store", "2026-10-24 10:02:00")]),
      seedCase(195, "Notifikasi status perizinan IMB terlambat kirim", "Notification", "Perizinan Terpadu", "Notifikasi Push", "Low", 5.8,
        "Notifikasi perubahan status izin terkirim lebih dari 6 jam setelah status berubah.",
        "Periksa antrean worker notifikasi dan retry FCM.",
        [review(5031, "Izin sudah disetujui tapi notifikasi baru masuk besoknya.", 3, "App Store", "2026-10-22 13:10:00")]),
      seedCase(196, "Peta zonasi bansos lambat memuat data GeoJSON", "Performance", "Sosial & Bantuan", "Web GIS", "High", 8.1,
        "Peta zonasi membutuhkan lebih dari 12 detik untuk tampil pada jaringan 4G.",
        "Gunakan tile vektor dan cache GeoJSON per kecamatan.",
        [review(5041, "Petanya loading lama banget, keburu nutup aplikasi.", 2, "Play Store", "2026-10-21 19:45:00")]),
      seedCase(194, "Layanan antrean puskesmas tidak sinkron faskes", "Performance", "Kesehatan Warga", "Antrean Online", "Medium", 7.2,
        "Nomor antrean di aplikasi berbeda dengan nomor yang dipanggil di puskesmas.",
        "Sinkronkan socket antrean dengan sistem faskes tiap 15 detik.",
        [review(5051, "Nomor antrean di HP beda sama yang dipanggil.", 2, "Kanal Aduan Pemda", "2026-10-20 08:15:00")]),
    ],
    backlogs,
    pullRequests: [prPending, prMerged],
  };
}

function load(): MockState {
  try {
    const raw = sessionStorage.getItem(STATE_KEY);
    if (raw) return JSON.parse(raw) as MockState;
  } catch { /* storage tidak tersedia: pakai seed */ }
  return seed();
}

function save(state: MockState) {
  try { sessionStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch { /* abaikan */ }
}

function currentUserKey(): "maintenance" | "developer" {
  try { return localStorage.getItem(MOCK_ROLE_KEY) === "developer" ? "developer" : "maintenance"; } catch { return "maintenance"; }
}

function currentUser(): AuthUser {
  return USERS[currentUserKey()];
}

function currentGithubLogin(): string {
  return GITHUB_LOGIN[currentUserKey()];
}

const delay = <T,>(value: T) => new Promise<T>(resolve => setTimeout(() => resolve(structuredClone(value)), 250));

function requireRole(role: AuthUser["role"]) {
  if (currentUser().role !== role) throw new ApiError(403, "Anda tidak memiliki akses untuk aksi ini.");
}

function paginate<T>(items: T[], page = 1, perPage = 10): Paged<T> {
  const lastPage = Math.max(1, Math.ceil(items.length / perPage));
  const current = Math.min(Math.max(1, page), lastPage);
  return {
    data: items.slice((current - 1) * perPage, current * perPage),
    meta: { current_page: current, last_page: lastPage, per_page: perPage, total: items.length },
  };
}

const includes = (text: string, search?: string) => !search || text.toLowerCase().includes(search.toLowerCase());

function findBacklog(state: MockState, id: number) {
  const backlog = state.backlogs.find(item => item.backlog_id === id);
  if (!backlog) throw new ApiError(404, "Backlog tidak ditemukan.");
  return backlog;
}

function findPullRequest(state: MockState, id: number) {
  const pullRequest = state.pullRequests.find(item => item.pull_request_id === id);
  if (!pullRequest) throw new ApiError(404, "Pull Request tidak ditemukan.");
  return pullRequest;
}

function hydratePullRequest(state: MockState, pullRequest: PullRequestDetail): PullRequestDetail {
  const backlog = state.backlogs.find(item => item.backlog_id === pullRequest.backlog_id);

  if (backlog) {
    pullRequest.issue = {
      issue_id: backlog.backlog_id,
      backlog_id: backlog.backlog_id,
      title: backlog.judul,
      status: backlog.status,
      source_branch: backlog.source_branch,
    };
  }

  pullRequest.review_summary = summarizeReviews(pullRequest.reviews);
  return pullRequest;
}

function assertPullRequestVisible(pullRequest: PullRequestDetail, user: AuthUser) {
  if (user.role === "Tim Maintenance") return;
  if (user.role === "Developer" && pullRequest.developer_id === user.user_id) return;
  throw new ApiError(403, "Pull Request ini tidak dapat diakses oleh pengguna.");
}

function filterBacklogs(state: MockState, query: BacklogListQuery = {}) {
  return state.backlogs
    .filter(item => !query.status || item.status === query.status)
    .filter(item => includes(`${item.judul} ${item.backlog_id} ${item.case_id}`, query.search))
    .filter(item => !query.modul || item.modul === query.modul)
    .filter(item => !query.severity || item.severity === query.severity);
}

export const mockApi: Sprint2Api = {
  async me() { return delay(currentUser()); },
  async logout() { try { localStorage.removeItem(MOCK_ROLE_KEY); } catch { /* abaikan */ } },

  async listCases(query = {}) {
    requireRole("Tim Maintenance");
    const items = load().cases
      .filter(item => !query.status || item.status_validasi === query.status)
      .filter(item => includes(`${item.judul_issue} ${item.modul} ${item.case_id}`, query.search))
      .filter(item => !query.kategori || item.kategori === query.kategori)
      .filter(item => !query.severity || item.severity === query.severity)
      .sort((a, b) => b.priority_score - a.priority_score)
      .map(({ reviews: _reviews, ...rest }) => rest as MaintenanceCase);
    return delay(paginate(items, query.page));
  },

  async getCase(id) {
    requireRole("Tim Maintenance");
    const found = load().cases.find(item => item.case_id === id);
    if (!found) throw new ApiError(404, "Maintenance Case tidak ditemukan.");
    return delay(found);
  },

  async validateCase(id, keputusan) {
    requireRole("Tim Maintenance");
    const state = load();
    const found = state.cases.find(item => item.case_id === id);
    if (!found) throw new ApiError(404, "Maintenance Case tidak ditemukan.");
    if (found.status_validasi !== "Pending") throw new ApiError(409, "Maintenance Case sudah divalidasi sebelumnya.");
    const user = currentUser();
    found.status_validasi = keputusan;
    found.validated_at = new Date().toISOString();
    found.validator = { user_id: user.user_id, nama: user.nama };
    let backlog: MaintenanceBacklog | null = null;
    if (keputusan === "Valid") {
      backlog = seedBacklog(state.nextBacklogId++, found.case_id, found.judul_issue, found.kategori, found.modul, found.fitur,
        found.severity, found.priority_score, "Ready for Development",
        { ringkasan_masalah: found.ringkasan_masalah, recommendation: found.recommendation, created_at: found.validated_at, updated_at: found.validated_at });
      state.backlogs.unshift(backlog);
      found.backlog_id = backlog.backlog_id;
    }
    save(state);
    return delay({
      case_id: found.case_id, status_validasi: keputusan, validator_id: user.user_id, validated_at: found.validated_at,
      keputusan: keputusan === "Valid" ? "Ya" as const : "Tidak" as const, backlog_id: backlog?.backlog_id ?? null,
    });
  },

  async listBacklogs(query = {}) {
    return delay(paginate(filterBacklogs(load(), query), query.page));
  },

  async getBacklog(id) { return delay(findBacklog(load(), id)); },

  async listIssues(query = {}) {
    requireRole("Developer");
    return delay(paginate(filterBacklogs(load(), query), query.page));
  },

  async getIssue(id) {
    requireRole("Developer");
    return delay(findBacklog(load(), id));
  },

  async updateBacklog(id, input) {
    requireRole("Tim Maintenance");
    const state = load();
    const backlog = findBacklog(state, id);
    if (backlog.status !== "Ready for Development") throw new ApiError(409, "Backlog sudah dikerjakan dan tidak dapat diubah.");
    const missing = (["judul", "modul", "fitur", "severity", "ringkasan_masalah"] as const).filter(key => !String(input[key] ?? "").trim());
    if (missing.length) throw new ApiError(422, "Data wajib belum lengkap");
    Object.assign(backlog, input, { updated_at: new Date().toISOString() });
    save(state);
    return delay(backlog);
  },

  async claimBacklog(id) {
    requireRole("Developer");
    const state = load();
    const backlog = findBacklog(state, id);
    if (backlog.assigned_to || backlog.status !== "Ready for Development") throw new ApiError(409, "Issue telah diambil Developer lain");
    const user = currentUser();
    const branch = `maintenance/issue-${backlog.backlog_id}`;
    Object.assign(backlog, {
      status: "Sedang Dikerjakan", assigned_to: { user_id: user.user_id, nama: user.nama },
      assigned_at: new Date().toISOString(), source_branch: branch,
      branch_preparation: {
        status: "created", branch, commit_sha: MOCK_BASE_SHA,
        message: "Branch berhasil dibuat di GitHub.", error_code: null,
      },
    });
    save(state);
    return delay(backlog);
  },

  async getPullRequestOptions(id) {
    requireRole("Developer");
    const backlog = findBacklog(load(), id);
    if (backlog.status !== "Ready for Pull Request" || !backlog.last_push) throw new ApiError(409, "Issue belum siap diajukan sebagai pull request.");
    return delay({ source_branch: backlog.last_push.branch, target_branches: ["main", "staging"], default_target: "main", repository: "AgilePemdigi-2026/MaintainGov" });
  },

  async createPullRequest(id, input) {
    requireRole("Developer");
    const state = load();
    const backlog = findBacklog(state, id);
    if (!input.judul.trim() || !input.deskripsi.trim() || !input.target_branch) throw new ApiError(422, "Lengkapi informasi Pull Request");
    if (backlog.pull_request) throw new ApiError(409, "Pull request untuk issue ini sudah dibuat.");
    if (!backlog.last_push) throw new ApiError(409, "Issue belum siap diajukan sebagai pull request.");
    const number = state.nextPrId++;
    const user = currentUser();
    const createdAt = new Date().toISOString();
    const detail = buildPullRequest({
      id: number,
      backlog,
      developer: { user_id: user.user_id, nama: user.nama, github_login: currentGithubLogin() },
      headSha: backlog.last_push.sha,
      createdAt,
      title: input.judul,
      description: input.deskripsi,
    });
    detail.source_branch = backlog.last_push.branch;
    detail.target_branch = input.target_branch;
    state.pullRequests.push(detail);
    backlog.pull_request = toPullRequestInfo(detail);
    save(state);
    return delay(backlog.pull_request);
  },

  async listPullRequests(query = {}) {
    const user = currentUser();
    const state = load();
    const status = query.status ?? "open";
    const items: PullRequestSummary[] = state.pullRequests
      .filter(item => user.role === "Tim Maintenance" || item.developer_id === user.user_id)
      .filter(item => status === "all" || status === "" || item.lifecycle_state === status)
      .filter(item => includes(`${item.title} ${item.github_pr_number} ${item.source_branch} ${item.author.github_login ?? ""}`, query.search))
      .sort((left, right) => String(right.github_updated_at ?? "").localeCompare(String(left.github_updated_at ?? "")))
      .map(item => {
        const hydrated = hydratePullRequest(state, structuredClone(item));
        return { ...hydrated, reviews_count: hydrated.reviews.length };
      });

    return delay(paginate(items, query.page, query.per_page ?? 25));
  },

  async getPullRequest(id) {
    const user = currentUser();
    const state = load();
    const pullRequest = findPullRequest(state, id);
    assertPullRequestVisible(pullRequest, user);
    return delay(hydratePullRequest(state, structuredClone(pullRequest)));
  },

  async syncPullRequest(id) {
    const user = currentUser();
    const state = load();
    const pullRequest = findPullRequest(state, id);
    assertPullRequestVisible(pullRequest, user);
    pullRequest.last_synced_at = new Date().toISOString();
    hydratePullRequest(state, pullRequest);
    save(state);
    return delay(pullRequest);
  },

  async submitPullRequestReview(id, input: PullRequestReviewSubmission): Promise<PullRequestReviewResult> {
    requireRole("Tim Maintenance");
    const state = load();
    const pullRequest = findPullRequest(state, id);
    const notes = String(input.notes ?? "").trim();

    if (input.decision === "REQUEST_CHANGES" && notes === "") {
      throw new ApiError(422, "Catatan perbaikan wajib diisi untuk Request Changes.");
    }

    if (pullRequest.lifecycle_state !== "open") {
      throw new ApiError(409, "Review hanya dapat diberikan pada Pull Request berstatus Open.");
    }

    const user = currentUser();
    const login = currentGithubLogin();

    if (pullRequest.developer_id === user.user_id
      || (pullRequest.author.github_login ?? "").toLowerCase() === login.toLowerCase()) {
      throw new ApiError(403, "Reviewer tidak boleh memberikan review pada Pull Request miliknya sendiri.");
    }

    const githubState = input.decision === "APPROVE" ? "APPROVED" : "CHANGES_REQUESTED";
    const message = input.decision === "REQUEST_CHANGES"
      ? "Perubahan memerlukan perbaikan"
      : "Pull Request disetujui. Issue siap untuk merge.";
    const existing = pullRequest.reviews.find(review =>
      review.reviewer_login === login
      && review.github_state === githubState
      && (review.body ?? "") === notes);

    if (existing) {
      hydratePullRequest(state, pullRequest);
      return delay({
        pull_request: pullRequest,
        review: existing,
        created: false,
        reconciled: false,
        idempotent_replay: true,
        sync_pending: false,
        message,
      });
    }

    const submittedAt = new Date().toISOString();
    const reviewId = state.nextReviewId++;
    const reviewRecord: PullRequestReview = {
      code_review_id: reviewId,
      github_review_id: 8000 + reviewId,
      reviewer_id: user.user_id,
      reviewer: { user_id: user.user_id, nama: user.nama, github_login: login },
      reviewer_login: login,
      github_state: githubState,
      decision: githubState === "APPROVED" ? "approved" : "changes_requested",
      body: notes || null,
      commit_id: pullRequest.head_sha,
      html_url: `${pullRequest.github_pr_url}#pullrequestreview-${8000 + reviewId}`,
      source: "maintaingov",
      submitted_at: submittedAt,
      synced_at: submittedAt,
    };

    pullRequest.reviews.push(reviewRecord);
    pullRequest.review_state = githubState === "APPROVED" ? "approved" : "changes_requested";
    pullRequest.github_updated_at = submittedAt;
    pullRequest.last_synced_at = submittedAt;

    const backlog = state.backlogs.find(item => item.backlog_id === pullRequest.backlog_id);
    if (backlog) {
      backlog.status = githubState === "APPROVED" ? "Ready for Merge" : "Sedang Dikerjakan";
      backlog.updated_at = submittedAt;
    }

    hydratePullRequest(state, pullRequest);
    save(state);

    return delay({
      pull_request: pullRequest,
      review: reviewRecord,
      created: true,
      reconciled: false,
      idempotent_replay: false,
      sync_pending: false,
      message,
    });
  },
};
