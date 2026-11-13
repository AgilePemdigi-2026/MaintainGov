// Fixture typed untuk pengembangan UI tanpa backend (NEXT_PUBLIC_SPRINT2_MOCK=1).
// Data contoh, bukan hasil analisis nyata. State disimpan di sessionStorage agar refresh konsisten.
import { ApiError } from "@/lib/api";
import type { Sprint2Api } from "./client";
import type {
  AuthUser, BacklogListQuery, BacklogStatus, CaseReview, MaintenanceBacklog, MaintenanceCase, MaintenanceCaseDetail, Paged,
} from "./types";

const STATE_KEY = "mg-sprint2-mock-state";
export const MOCK_ROLE_KEY = "mg-mock-role";
const MOCK_BASE_SHA = "0000000000000000000000000000000000000000";

const USERS: Record<string, AuthUser> = {
  maintenance: { user_id: 1, nama: "Budi Prasetyo", email: "maintenance@maintaingov.test", role: "Tim Maintenance" },
  developer: { user_id: 2, nama: "Raditya Fahmi", email: "developer@maintaingov.test", role: "Developer" },
};
const OTHER_DEV = { user_id: 3, nama: "Nadia Putri" };

interface MockState {
  cases: MaintenanceCaseDetail[];
  backlogs: MaintenanceBacklog[];
  nextBacklogId: number;
  nextPrId: number;
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

function seed(): MockState {
  return {
    nextBacklogId: 106, nextPrId: 42,
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
    backlogs: [
      seedBacklog(89, 180, "Optimasi connection pool SSO gateway", "Bug", "Autentikasi", "SSO KTP", "Critical", 9.6, "Ready for Development"),
      seedBacklog(91, 181, "Perbaikan timeout integrasi QRIS payment", "Bug", "Pembayaran", "QRIS Dynamic", "High", 8.5, "Sedang Dikerjakan",
        { assigned_to: OTHER_DEV, assigned_at: "2026-10-25T09:00:00+07:00", source_branch: "fix/qris-timeout-91" }),
      seedBacklog(94, 182, "Sinkronisasi socket antrean faskes puskesmas", "Performance", "Kesehatan", "Antrean Online", "Medium", 6.4, "Ready for Pull Request",
        { assigned_to: { user_id: 2, nama: "Raditya Fahmi" }, assigned_at: "2026-10-25T10:00:00+07:00", source_branch: "fix/antrean-socket-94",
          last_push: { sha: "9f2c1ab", branch: "fix/antrean-socket-94", pushed_at: "2026-10-26T14:20:00+07:00" } }),
      seedBacklog(98, 183, "Perbaikan queue background worker notifikasi IMB", "Notification", "Perizinan", "FCM Push", "Low", 3.1, "Selesai"),
      seedBacklog(103, 184, "Caching GeoJSON peta zonasi bansos", "Performance", "Sosial", "Web GIS", "High", 8.0, "Ready for Development"),
      seedBacklog(105, 185, "Validasi format NIK pada formulir bansos", "Bug", "Sosial", "Formulir Bansos", "Medium", 5.0, "Ready for Development",
        { assigned_to: null }),
    ],
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

function currentUser(): AuthUser {
  let key = "maintenance";
  try { key = localStorage.getItem(MOCK_ROLE_KEY) === "developer" ? "developer" : "maintenance"; } catch { /* default */ }
  return USERS[key];
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
    backlog.pull_request = {
      pr_id: number, github_pr_number: number, url: `https://github.com/AgilePemdigi-2026/MaintainGov/pull/${number}`,
      judul: input.judul, source_branch: backlog.last_push.branch, target_branch: input.target_branch, status: "Open",
      created_at: new Date().toISOString(),
    };
    save(state);
    return delay(backlog.pull_request);
  },
};
