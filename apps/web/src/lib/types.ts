// Tipe awal mengikuti docs/ARCHITECTURE.md (kontrak v0.1, USULAN). Sesuaikan setelah kontrak di contracts/ dibekukan.
export type Role = "Tim Maintenance" | "Developer";

export type BatchStatus = "Diproses" | "Selesai" | "Gagal";

export type CaseStatus = "Pending" | "Valid" | "Tidak Valid";

export interface AuthUser {
  user_id: number;
  nama: string;
  email: string;
  role: Role;
}
