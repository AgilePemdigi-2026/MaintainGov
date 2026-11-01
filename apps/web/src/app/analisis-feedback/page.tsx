"use client";

import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";
import type { BatchStatus } from "@/lib/analysis-types";
import AnalysisResults from "@/components/analysis-results";
import { ShieldCheckIcon } from "@/components/icons";

// Hanya menu yang halamannya sudah ada; menu Sprint 2 ditambahkan saat halamannya tersedia.
const menuItems = [
  { label: "Analisis Feedback", href: "/analisis-feedback", icon: "▤" },
  { label: "Maintenance Backlog", href: "/maintenance-backlog", icon: "☷" },
];

const MAX_CSV_BYTES = 10_000_000;

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

  function selectFile(selected: File | null) {
    requestKey.current = ""; setError(""); setFile(null);
    if (!selected) return;
    if (!selected.name.toLowerCase().endsWith(".csv")) { setError("Pilih file CSV."); return; }
    if (selected.size > MAX_CSV_BYTES) { setError("Maksimum ukuran CSV adalah 10 MB (10.000.000 byte)."); return; }
    setFile(selected);
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

  const processing = batch?.status === "Diproses";

  return (
    <main className="analysis-page">
      <header className="analysis-header">
        <div className="analysis-brand">
          <span className="analysis-brand__logo"><ShieldCheckIcon size={20} /></span>
          <span>MaintainGov</span>
        </div>
        <div className="analysis-header__right">
          <span className="analysis-user">{user.nama}</span>
          <button className="analysis-logout" disabled={busy} onClick={logout}>
            Keluar<span aria-hidden="true"> ↗</span>
          </button>
        </div>
      </header>

      <div className="analysis-layout">
        <aside className="analysis-sidebar">
          <p className="analysis-sidebar__label">MENU UTAMA</p>
          {menuItems.map(item => (
            <a key={item.label} href={item.href} className={item.label === "Analisis Feedback" ? "active" : undefined}
              aria-current={item.label === "Analisis Feedback" ? "page" : undefined}>
              <span className="analysis-menu-icon" aria-hidden="true">{item.icon}</span>
              {item.label}
            </a>
          ))}
          <div className="analysis-sidebar__footer">
            <span className="analysis-avatar" aria-hidden="true">{user.nama?.charAt(0)?.toUpperCase() || "A"}</span>
            <div>
              <strong>{user.nama}</strong>
              <small>{user.role}</small>
            </div>
          </div>
        </aside>

        <section className="analysis-content">
          <div className="analysis-heading-row">
            <div>
              <p className="analysis-eyebrow">MAINTENANCE MANAGEMENT</p>
              <h1 className="analysis-title">Analisis Feedback</h1>
              <p className="analysis-description">
                Unggah dataset ulasan untuk mengidentifikasi masalah dan membantu tim menentukan prioritas maintenance.
              </p>
            </div>
          </div>

          <section className="analysis-card">
            <div className="analysis-card__heading">
              <div className="analysis-card__icon" aria-hidden="true">↑</div>
              <div>
                <h2>Unggah Dataset</h2>
                <p>Pilih file CSV untuk memulai analisis feedback.</p>
              </div>
            </div>

            <form onSubmit={upload}>
              <div className="analysis-dropzone">
                <span className="analysis-file-icon" aria-hidden="true">⇧</span>
                <label htmlFor="feedback-file"><strong>Dataset feedback CSV</strong></label>
                <span>{file ? `${file.name} · ${(file.size / 1024).toFixed(1)} KB` : "Pilih file dari perangkat kamu"}</span>
                <small>Format CSV UTF-8 · Maksimum 10 MB (10.000.000 byte) · 10.000 ulasan</small>
                <input id="feedback-file" type="file" accept=".csv" disabled={busy || processing}
                  onChange={e => selectFile(e.target.files?.[0] ?? null)} />
              </div>

              <p className="analysis-format-note">
                Kolom wajib: <strong>reviewId, Review Teks, rating, date</strong>. Format tanggal: YYYY-MM-DD HH:MM:SS.
              </p>

              <button className="analysis-primary-button" type="submit" disabled={!file || busy || processing}>
                {busy ? "Mengirim..." : "Analisis feedback"}
              </button>
            </form>
          </section>

          {error && <p className="analysis-error" role="alert">{error}</p>}
          {pollError && (
            <p className="analysis-error" role="alert">
              {pollError}{" "}
              {pollAttempt < 5 ? "Status akan dicoba kembali jika gangguan bersifat sementara." : "Pembaruan otomatis dijeda."}{" "}
              <button type="button" className="analysis-logout" onClick={() => setRefresh(value => value + 1)}>Cek status sekarang</button>
            </p>
          )}

          {batch && (
            <section className="analysis-card" aria-live="polite">
              <div className="analysis-card__heading">
                <div className="analysis-card__icon" aria-hidden="true">▤</div>
                <div>
                  <h2>Batch #{batch.batch_id} · {batch.status}</h2>
                  <p>{batch.review_count} ulasan · Percobaan {batch.attempt}</p>
                </div>
                <span className="analysis-status">{batch.status}</span>
              </div>

              {processing && (
                <p>Analisis sedang diproses. Status diperbarui otomatis. Jika proses terhenti, hubungi tim untuk memeriksa worker.</p>
              )}

              {batch.status === "Gagal" && (
                <div>
                  <p className="analysis-error" role="alert">{batch.error}</p>
                  <button className="analysis-primary-button" disabled={busy} onClick={retry}>Coba ulang analisis</button>
                </div>
              )}

              {batch.status === "Selesai" && (
                <>
                  <p role="status" className="analysis-description">
                    Feedback berhasil diproses.{" "}
                    {batch.case_count === 0
                      ? "Tidak ada kandidat case yang teridentifikasi."
                      : batch.case_count + " kandidat case Pending terbentuk."}
                  </p>
                  <p className="analysis-format-note">Versi analisis: {batch.analysis_version}</p>
                  <AnalysisResults key={batch.batch_id} batchId={batch.batch_id} />
                </>
              )}
            </section>
          )}
        </section>
      </div>
    </main>
  );
}
