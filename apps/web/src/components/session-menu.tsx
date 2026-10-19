"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";

export default function SessionMenu() {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    apiFetch<{ data: AuthUser }>("/auth/me").then(({ data }) => {
      setUser(data);
      if (data.role === "Tim Maintenance") router.replace("/analisis-feedback");
    }).catch(() => router.replace("/login"));
  }, [router]);
  async function logout() {
    try { await apiFetch("/auth/logout", { method: "POST" }); router.replace("/login"); }
    catch (e) { setError(e instanceof Error ? e.message : "Logout gagal."); }
  }
  return <div><p>{user ? `${user.nama} · ${user.role}` : "Memeriksa sesi..."}</p>
    <button onClick={logout}>Keluar</button>{error && <p role="alert">{error}</p>}</div>;
}
