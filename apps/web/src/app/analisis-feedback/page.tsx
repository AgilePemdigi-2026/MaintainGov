"use client";

import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";

type Review = { review_id: number; source_review_id: string; teks_ulasan: string; rating: number; case_id: number | null; analysis_result: { sentimen: string; kategori: string; needs_manual_review: boolean } | null };
type Case = { case_id: number; judul_issue: string; severity: string; priority_score: number; ringkasan_masalah: string; recommendation: string | null; status_validasi: string };
type Batch = { batch_id: number; status: string; review_count: number; error: string | null; attempt: number; reviews: Review[]; cases: Case[] };

export default function AnalisisFeedbackPage() {
  const router = useRouter();
  const [user, setUser] = useState<AuthUser | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [batchId, setBatchId] = useState<number | null>(null);
  const [batch, setBatch] = useState<Batch | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const requestKey = useRef("");

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
    let timer: ReturnType<typeof setTimeout>;
    const poll = async () => {
      try {
        const { data } = await apiFetch<{ data: Batch }>(`/analysis-batches/${batchId}`);
        if (!active) return;
        setBatch(data);
        setError("");
        if (data.status === "Diproses") timer = setTimeout(poll, 2000);
      } catch (e) {
        if (!active) return;
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        setError(e instanceof Error ? e.message : "Tidak dapat mengambil hasil.");
      }
    };
    void poll();
    return () => { active = false; clearTimeout(timer); };
  }, [batchId, user, router]);

  async function upload(e: FormEvent) {
    e.preventDefault();
    if (!file || busy || batch?.status === "Diproses") return;
    setBusy(true); setError("");
    try {
      const form = new FormData(); form.append("file", file);
      if (!requestKey.current) requestKey.current = crypto.randomUUID();
      const { data } = await apiFetch<{ data: { batch_id: number } }>("/analysis-batches", {
        method: "POST", body: form, headers: { "Idempotency-Key": requestKey.current },
      });
      window.history.replaceState(null, "", `?batch=${data.batch_id}`);
      if (data.batch_id === batchId) {
        const current = await apiFetch<{ data: Batch }>(`/analysis-batches/${data.batch_id}`);
        setBatch(current.data);
      } else {
        setBatch(null); setBatchId(data.batch_id);
      }
    } catch (e) { setError(e instanceof Error ? e.message : "Unggahan gagal."); }
    finally { setBusy(false); }
  }

  async function retry() {
    setBusy(true); setError("");
    try {
      await apiFetch(`/analysis-batches/${batchId}/retry`, { method: "POST" });
      window.location.reload();
    } catch (e) { setError(e instanceof Error ? e.message : "Pengulangan gagal."); }
    finally { setBusy(false); }
  }

  async function logout() {
    try { await apiFetch("/auth/logout", { method: "POST" }); router.replace("/login"); }
    catch (e) { setError(e instanceof Error ? e.message : "Logout gagal."); }
  }

  if (!user) return <main style={{ padding: 32 }}>Memeriksa sesi...</main>;
  return <main style={{ maxWidth: 1100, margin: "auto", padding: 32, lineHeight: 1.6 }}>
    <header style={{ display: "flex", justifyContent: "space-between", alignItems: "center" }}>
      <div><p>MaintainGov · {user.nama}</p><h1>Analisis Feedback</h1></div>
      <button onClick={logout}>Keluar</button>
    </header>
    <p>Unggah CSV berisi kolom <strong>reviewId, Review Teks, rating, date</strong>. Format tanggal: YYYY-MM-DD HH:MM:SS. Maksimum 8 MiB dan 10.000 ulasan.</p>
    <form onSubmit={upload} style={{ padding: 24, background: "#eef4fa", borderRadius: 12 }}>
      <label htmlFor="feedback-file">Dataset feedback CSV </label>
      <input id="feedback-file" type="file" accept=".csv" disabled={busy || batch?.status === "Diproses"} onChange={e => {
        setFile(e.target.files?.[0] ?? null); requestKey.current = ""; setError("");
      }} />
      <button type="submit" disabled={!file || busy || batch?.status === "Diproses"}>{busy ? "Mengirim..." : "Analisis feedback"}</button>
    </form>
    {error && <p role="alert" style={{ color: "#b42318" }}>{error}</p>}
    {batch && <section aria-live="polite" style={{ marginTop: 24 }}>
      <h2>Batch #{batch.batch_id} · {batch.status}</h2>
      <p>{batch.review_count} ulasan · Percobaan {batch.attempt}</p>
      {batch.status === "Diproses" && <p>Analisis sedang diproses. Status diperbarui otomatis.</p>}
      {batch.status === "Gagal" && <><p role="alert">{batch.error}</p><button disabled={busy} onClick={retry}>Coba ulang analisis</button></>}
      {batch.status === "Selesai" && <>
        <p>Hasil sudah tersimpan. Kandidat case menunggu validasi tim maintenance. Model sentimen masih menggunakan proksi rating; aturan kategori dan severity masih dalam review.</p>
        <h2>Kandidat Maintenance Case ({batch.cases.length})</h2>
        {!batch.cases.length && <p>Tidak ada kandidat case. Hasil per ulasan tetap tersimpan di bawah.</p>}
        {batch.cases.slice(0, 200).map(c => <article key={c.case_id} style={{ padding: 20, border: "1px solid #d4deea", borderRadius: 10, marginBottom: 12 }}>
          <h3>#{c.case_id} {c.judul_issue}</h3>
          <p>{c.status_validasi} · Severity: {c.severity} · Prioritas: {Number(c.priority_score).toFixed(2)}/10</p>
          <p>{c.ringkasan_masalah}</p><p>Saran penanganan: {c.recommendation}</p>
          <details><summary>Ulasan pembentuk case</summary><ul>{batch.reviews.filter(r => r.case_id === c.case_id).map(r => <li key={r.review_id}>{r.source_review_id}: {r.teks_ulasan}</li>)}</ul></details>
        </article>)}
        {batch.cases.length > 200 && <p>Menampilkan 200 case pertama.</p>}
        <h2>Hasil per ulasan</h2>
        <div style={{ overflowX: "auto" }}><table style={{ width: "100%", textAlign: "left" }}><thead><tr><th>ID asal</th><th>Ulasan</th><th>Sentimen</th><th>Kategori</th><th>Case</th></tr></thead>
        <tbody>{batch.reviews.slice(0, 200).map(r => <tr key={r.review_id}><td>{r.source_review_id}</td><td>{r.teks_ulasan}</td><td>{r.analysis_result?.sentimen}</td><td>{r.analysis_result?.kategori}{r.analysis_result?.needs_manual_review ? " (perlu review)" : ""}</td><td>{r.case_id ?? "—"}</td></tr>)}</tbody></table></div>
        {batch.reviews.length > 200 && <p>Menampilkan 200 ulasan pertama dari {batch.reviews.length}.</p>}
      </>}
    </section>}
  </main>;
}
