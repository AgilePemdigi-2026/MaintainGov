// Satu tempat untuk label UI, pesan wajib dari Use Case Week 6, dan mapping prioritas.
import type { BacklogStatus, CaseStatus } from "./types";

/** Pesan persis dari UC02–UC05 / US02–US05. Jangan ubah tanpa memperbarui artefak. */
export const MESSAGES = {
  caseValid: "Maintenance Case berhasil divalidasi",
  caseInvalid: "Maintenance Case tidak diteruskan",
  backlogSaved: "Perubahan berhasil disimpan",
  backlogIncomplete: "Data wajib belum lengkap",
  issueTaken: "Issue telah diambil Developer lain",
  prIncomplete: "Lengkapi informasi Pull Request",
  prCreated: "Pull request berhasil dibuat",
  githubDown: "Koneksi GitHub tidak tersedia",
} as const;

export const CASE_STATUS_TONE: Record<CaseStatus, string> = {
  Pending: "pending",
  Valid: "done",
  "Tidak Valid": "muted",
};

export const BACKLOG_STATUS_LABEL: Record<BacklogStatus, string> = {
  "Ready for Development": "Ready for development",
  "Sedang Dikerjakan": "Sedang dikerjakan",
  "Ready for Pull Request": "Ready for pull request",
  "Ready for Merge": "Ready for merge",
  Selesai: "Selesai",
};

export const BACKLOG_STATUS_TONE: Record<BacklogStatus, string> = {
  "Ready for Development": "ready",
  "Sedang Dikerjakan": "progress",
  "Ready for Pull Request": "review",
  "Ready for Merge": "review",
  Selesai: "done",
};

export const BACKLOG_STATUSES = Object.keys(BACKLOG_STATUS_LABEL) as BacklogStatus[];

export const SEVERITIES = ["Critical", "High", "Medium", "Low"] as const;

export const PRIORITY_SCALE = 10;

/**
 * KEPUTUSAN TERBUKA (Week 6): UC01 memakai skor 0–10, US03 mencontohkan label "High".
 * Ambang di bawah adalah USULAN tampilan saja; backend tetap menyimpan priority_score.
 */
export function priorityLabel(score: number): "High" | "Medium" | "Low" {
  if (score >= 7) return "High";
  if (score >= 4) return "Medium";
  return "Low";
}

export function severityTone(severity: string): string {
  // Pipeline UC01 menyimpan severity huruf kecil ("medium"); label UI tetap mengikuti nilai dari API.
  return ({ critical: "critical", high: "high", medium: "medium", low: "low" } as Record<string, string>)[String(severity).toLowerCase()] ?? "muted";
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString("id-ID", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export const caseCode = (id: number) => `CASE-${String(id).padStart(4, "0")}`;
export const backlogCode = (id: number) => `BKLG-${String(id).padStart(4, "0")}`;
