"use client";

import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";
import type { BatchStatus } from "@/lib/analysis-types";
import AnalysisResults from "@/components/analysis-results";

export default function AnalisisFeedbackPage() {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [batchId, setBatchId] = useState<number | null>(null);
  const [batch, setBatch] = useState<BatchStatus | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [pollError, setPollError] = useState("");
  const [pollAttempt, setPollAttempt] = useState(0);
  const [refresh, setRefresh] = useState(0);
  const requestKey = useRef("");
  const submitting = useRef(false);

  useEffect(() => {
    apiFetch<{ data: AuthUser }>("/auth/me").then(({ data }) => {
      if (data.role !== "Tim Maintenance") { router.replace("/maintenance-backlog"); return; }
      setUser(data);
      const id = Number(new URLSearchParams(window.location.search).get("batch"));
      if (Number.isSafeInteger(id) && id > 0) setBatchId(id);
    }).catch(() => router.replace("/login"));
  }, [router]);

  useEffect(() => {
    if (!user || !batchId) return;
    let active = true;
    let failures = 0;
    let timer: ReturnType<typeof setTimeout>;
    const controller = new AbortController();
    const poll = async () => {
      try {
        const { data } = await apiFetch<{ data: BatchStatus }>("/analysis-batches/" + batchId + "/status", { signal: controller.signal });
        if (!active) return;
        setBatch(data); setPollError(""); setPollAttempt(0); failures = 0;
        if (data.status === "Diproses") timer = setTimeout(poll, 2000);
      } catch (e) {
        if (!active) return;
        if (e instanceof ApiError && e.status === 401) { router.replace("/login"); return; }
        failures += 1;
        const retryable = !(e instanceof ApiError) || e.status >= 500 || e.status === 429;
        setPollAttempt(retryable ? failures : 5);
        setPollError(e instanceof Error ? e.message : "Tidak dapat mengambil status.");
        if (failures < 5 && retryable) {
          timer = setTimeout(poll, Math.min(1000 * 2 ** failures, 15000));
        }
      }
    };
    void poll();
    return () => { active = false; clearTimeout(timer); controller.abort(); };
  }, [batchId, user, router, refresh]);

  function showError(e: unknown, fallback: string) {
    if (e instanceof ApiError && e.status === 401) { router.replace("/login"); return; }
    setError(e instanceof Error ? e.message : fallback);
  }

  async function upload(e: FormEvent) {
    e.preventDefault();
    if (!file || submitting.current || batch?.status === "Diproses") return;
    submitting.current = true; setBusy(true); setError("");
    try {
      const form = new FormData(); form.append("file", file);
      if (!requestKey.current) requestKey.current = crypto.randomUUID();
      const { data } = await apiFetch<{ data: { batch_id: number } }>("/analysis-batches", {
        method: "POST", body: form, headers: { "Idempotency-Key": requestKey.current },
      });
      window.history.replaceState(null, "", "?batch=" + data.batch_id);
      setBatch(null); setBatchId(data.batch_id); setRefresh(value => value + 1);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) setFile(null);
      showError(e, "Unggahan gagal.");
    }
    finally { submitting.current = false; setBusy(false); }
  }

  async function retry() {
    if (submitting.current) return;
    submitting.current = true; setBusy(true); setError("");
    try {
      await apiFetch("/analysis-batches/" + batchId + "/retry", { method: "POST" });
      setBatch(value => value ? { ...value, status: "Diproses", error: null, attempt: value.attempt + 1 } : null);
      setRefresh(value => value + 1);
    } catch (e) { showError(e, "Pengulangan gagal."); }
    finally { submitting.current = false; setBusy(false); }
  }

  async function logout() {
    if (submitting.current) return;
    submitting.current = true; setBusy(true);
    try { await apiFetch("/auth/logout", { method: "POST" }); router.replace("/login"); }
    catch (e) { showError(e, "Logout gagal."); }
    finally { submitting.current = false; setBusy(false); }
  }

  if (!user) return <main style={{ padding: 32 }}>Memeriksa sesi...</main>;
  return <main style={{ maxWidth: 1100, margin: "auto", padding: 24, lineHeight: 1.6 }}>
    <header style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 16 }}>
      <div><p>MaintainGov · {user.nama}</p><h1>Analisis Feedback</h1></div>
      <button disabled={busy} onClick={logout}>Keluar</button>
    </header>
    <p>Unggah CSV UTF-8 berisi kolom <strong>reviewId, Review Teks, rating, date</strong>. Format tanggal: YYYY-MM-DD HH:MM:SS. Maksimum 10 MB (10.000.000 byte) dan 10.000 ulasan.</p>
    <form onSubmit={upload} style={{ padding: 24, background: "#eef4fa", borderRadius: 12 }}>
      <label htmlFor="feedback-file">Dataset feedback CSV </label>
      <input id="feedback-file" type="file" accept=".csv" disabled={busy || batch?.status === "Diproses"} onChange={e => {
        const selected = e.target.files?.[0] ?? null;
        requestKey.current = ""; setError(""); setFile(null);
        if (!selected) return;
        if (!selected.name.toLowerCase().endsWith(".csv")) { setError("Pilih file CSV."); return; }
        if (selected.size > 10000000) { setError("Maksimum ukuran CSV adalah 10 MB (10.000.000 byte)."); return; }
        setFile(selected);
      }} />
      <button type="submit" disabled={!file || busy || batch?.status === "Diproses"}>{busy ? "Mengirim..." : "Analisis feedback"}</button>
    </form>
    {error && <p role="alert" style={{ color: "#b42318" }}>{error}</p>}
    {pollError && <p role="alert">{pollError} {pollAttempt < 5 ? "Status akan dicoba kembali jika gangguan bersifat sementara." : "Pembaruan otomatis dijeda."} <button onClick={() => setRefresh(value => value + 1)}>Cek status sekarang</button></p>}
    {batch && <section aria-live="polite" style={{ marginTop: 24 }}>
      <h2>Batch #{batch.batch_id} · {batch.status}</h2>
      <p>{batch.review_count} ulasan · Percobaan {batch.attempt}</p>
      {batch.status === "Diproses" && <p>Analisis sedang diproses. Status diperbarui otomatis. Jika proses terhenti, hubungi tim untuk memeriksa worker.</p>}
      {batch.status === "Gagal" && <><p role="alert">{batch.error}</p><button disabled={busy} onClick={retry}>Coba ulang analisis</button></>}
      {batch.status === "Selesai" && <>
        <p role="status">Feedback berhasil diproses. {batch.case_count === 0 ? "Tidak ada kandidat case yang teridentifikasi." : batch.case_count + " kandidat case Pending terbentuk."}</p>
        <p>Versi analisis: {batch.analysis_version}</p>
        <AnalysisResults key={batch.batch_id} batchId={batch.batch_id} />
      </>}
    </section>}
  </main>;
}
