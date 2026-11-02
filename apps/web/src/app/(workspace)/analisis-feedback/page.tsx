"use client";

import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type { BatchStatus } from "@/lib/analysis-types";
import AnalysisResults from "@/components/analysis-results";
import { PageHeader } from "@/components/workspace/ui";
import { useSession } from "@/components/workspace/session";

const MAX_CSV_BYTES = 10_000_000;

export default function AnalisisFeedbackPage() {
  const router = useRouter();
  const { user, handleError } = useSession();
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
    if (user.role !== "Tim Maintenance") router.replace("/maintenance-backlog");
  }, [user.role, router]);

  useEffect(() => {
    const id = Number(new URLSearchParams(window.location.search).get("batch"));
    if (Number.isSafeInteger(id) && id > 0) setBatchId(id);
  }, []);

  useEffect(() => {
    if (user.role !== "Tim Maintenance" || !batchId) return;
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
  }, [batchId, user.role, router, refresh]);

  function showError(e: unknown, fallback: string) {
    setError(handleError(e, fallback));
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

  if (user.role !== "Tim Maintenance") {
    return <div className="ws-content"><p>Mengalihkan ke halaman Maintenance Backlog...</p></div>;
  }

  const processing = batch?.status === "Diproses";
  const statusTone = batch?.status === "Selesai" ? "done" : batch?.status === "Diproses" ? "ready" : "muted";

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Tim Maintenance" }, { label: "Analisis Feedback" }]}
        title="Analisis Feedback"
        meta="Unggah dataset ulasan untuk mengidentifikasi masalah dan membantu tim menentukan prioritas maintenance."
      />

      <section className="ws-card" aria-label="Unggah dataset feedback">
        <div className="ws-card__head">
          <div>
            <h2 className="ws-card__title">Unggah Dataset</h2>
            <p className="ws-card__hint">Pilih file CSV untuk memulai analisis feedback.</p>
          </div>
        </div>
        <div className="ws-card__body">
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

            <button className="ws-btn ws-btn--primary" type="submit" disabled={!file || busy || processing}>
              {busy ? "Mengirim..." : "Analisis feedback"}
            </button>
          </form>
        </div>
      </section>

      {error && <p className="analysis-error" role="alert">{error}</p>}
      {pollError && (
        <p className="analysis-error" role="alert">
          {pollError}{" "}
          {pollAttempt < 5 ? "Status akan dicoba kembali jika gangguan bersifat sementara." : "Pembaruan otomatis dijeda."}{" "}
          <button type="button" className="ws-link-btn" onClick={() => setRefresh(value => value + 1)}>Cek status sekarang</button>
        </p>
      )}

      {batch && (
        <section className="ws-card" aria-live="polite">
          <div className="ws-card__head">
            <div>
              <h2 className="ws-card__title">Batch #{batch.batch_id} · {batch.status}</h2>
              <p className="ws-card__hint">{batch.review_count} ulasan · Percobaan {batch.attempt}</p>
            </div>
            <span className={`ws-pill ws-pill--${statusTone}`}>{batch.status}</span>
          </div>
          <div className="ws-card__body">
            {processing && (
              <p>Analisis sedang diproses. Status diperbarui otomatis. Jika proses terhenti, hubungi tim untuk memeriksa worker.</p>
            )}

            {batch.status === "Gagal" && (
              <div>
                <p className="analysis-error" role="alert">{batch.error}</p>
                <button className="ws-btn ws-btn--primary" disabled={busy} onClick={retry}>Coba ulang analisis</button>
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
          </div>
        </section>
      )}
    </div>
  );
}
