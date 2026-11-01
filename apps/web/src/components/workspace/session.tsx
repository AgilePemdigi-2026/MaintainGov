"use client";
// Sesi bersama untuk halaman Sprint 2. Backend tetap memverifikasi role pada setiap endpoint;
// gating di sini hanya navigasi dan pesan yang ramah pengguna.
import { createContext, useCallback, useContext, useEffect, useState } from "react";
import type { ReactNode } from "react";
import { useRouter } from "next/navigation";
import { ApiError } from "@/lib/api";
import { sprint2Api } from "@/lib/sprint2/client";
import type { AuthUser } from "@/lib/sprint2/types";

interface SessionValue {
  user: AuthUser;
  /** Tangani error API umum: 401/419 -> login ulang. Mengembalikan pesan untuk ditampilkan. */
  handleError: (error: unknown, fallback: string) => string;
  logout: () => Promise<void>;
}

const SessionContext = createContext<SessionValue | null>(null);

export function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    if (error.status === 403) return "Anda tidak memiliki akses untuk aksi ini.";
    if (error.status === 419) return "Sesi kedaluwarsa. Silakan masuk kembali.";
    if (error.status >= 500 && !error.message) return fallback;
    return error.message || fallback;
  }
  if (error instanceof TypeError) return "Tidak dapat terhubung ke server. Periksa koneksi lalu coba lagi.";
  return error instanceof Error && error.message ? error.message : fallback;
}

export function SessionProvider({ children }: { children: (user: AuthUser) => ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);

  useEffect(() => {
    let active = true;
    sprint2Api.me().then(data => { if (active) setUser(data); }).catch(() => router.replace("/login"));
    return () => { active = false; };
  }, [router]);

  const handleError = useCallback((error: unknown, fallback: string) => {
    if (error instanceof ApiError && (error.status === 401 || error.status === 419)) router.replace("/login");
    return errorMessage(error, fallback);
  }, [router]);

  const logout = useCallback(async () => {
    await sprint2Api.logout();
    router.replace("/login");
  }, [router]);

  if (!user) return <main className="ws-loading" aria-busy="true">Memeriksa sesi...</main>;
  return <SessionContext.Provider value={{ user, handleError, logout }}>{children(user)}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error("useSession harus dipakai di dalam SessionProvider");
  return value;
}

/** Tampilkan konten hanya untuk role tertentu; backend tetap mengembalikan 403 bila dipaksa. */
export function RoleGate({ role, children }: { role: AuthUser["role"]; children: ReactNode }) {
  const { user } = useSession();
  if (user.role !== role) {
    return (
      <div className="ws-card ws-empty" role="alert">
        <h2 className="ws-empty__title">Akses ditolak</h2>
        <p className="ws-empty__text">Halaman ini hanya untuk {role}. Anda masuk sebagai {user.role}.</p>
      </div>
    );
  }
  return <>{children}</>;
}
