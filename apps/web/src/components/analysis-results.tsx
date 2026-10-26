"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type { AnalysisCase, AnalysisReview, Page } from "@/lib/analysis-types";

function Pager({ page, loading, onPage }: { page: Page<unknown>["meta"]; loading: boolean; onPage: (value: number) => void }) {
  return <nav aria-label="Halaman hasil" style={{ display: "flex", gap: 12, alignItems: "center", margin: "12px 0" }}>
    <button disabled={loading || page.current_page <= 1} onClick={() => onPage(page.current_page - 1)}>Sebelumnya</button>
    <span>Halaman {page.current_page} dari {page.last_page} · {page.total} data</span>
    <button disabled={loading || page.current_page >= page.last_page} onClick={() => onPage(page.current_page + 1)}>Berikutnya</button>
  </nav>;
}

function usePage<T>(path: string) {
  const router = useRouter();
  const [result, setResult] = useState<Page<T> | null>(null);
  const [page, setPage] = useState(1);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    setLoading(true); setError("");
    apiFetch<Page<T>>(path + (path.includes("?") ? "&" : "?") + "page=" + page + "&per_page=25", { signal: controller.signal })
      .then(value => { if (active) setResult(value); })
      .catch(e => {
        if (!active) return;
        if (e instanceof ApiError && e.status === 401) router.replace("/login");
        setError(e instanceof Error ? e.message : "Tidak dapat memuat hasil.");
      }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; controller.abort(); };
  }, [path, page, refresh, router]);
  return { result, page, setPage, error, loading, retry: () => setRefresh(value => value + 1) };
}

function SourceReviews({ batchId, caseId }: { batchId: number; caseId: number }) {
  const source = usePage<AnalysisReview>("/analysis-batches/" + batchId + "/reviews?case_id=" + caseId);
  return <div aria-busy={source.loading}>
    {source.error && <p role="alert">{source.error} <button onClick={source.retry}>Muat ulang ulasan</button></p>}
    {source.loading ? <p>Memuat ulasan asal...</p> : !source.error && source.result && <>
      <ul>{source.result.data.map(review => <li key={review.review_id}>{review.source_review_id}: {review.teks_ulasan}</li>)}</ul>
      <Pager page={source.result.meta} loading={source.loading} onPage={source.setPage} />
    </>}
  </div>;
}

function CaseCard({ item, batchId }: { item: AnalysisCase; batchId: number }) {
  const [open, setOpen] = useState(false);
  return <article style={{ padding: 20, border: "1px solid #d4deea", borderRadius: 10, marginBottom: 12 }}>
    <h3>#{item.case_id} {item.judul_issue}</h3>
    <p>{item.status_validasi} · {item.kategori} · Severity: {item.severity} · Prioritas: {Number(item.priority_score).toFixed(2)}/10</p>
    <p>Modul: {item.modul} · Fitur: {item.fitur}</p>
    <p>{item.ringkasan_masalah}</p><p>Saran penanganan: {item.recommendation || "Perlu review tim maintenance."}</p>
    <button aria-expanded={open} onClick={() => setOpen(value => !value)}>{open ? "Tutup ulasan asal" : "Lihat ulasan pembentuk case"}</button>
    {open && <SourceReviews batchId={batchId} caseId={item.case_id} />}
  </article>;
}

export default function AnalysisResults({ batchId }: { batchId: number }) {
  const cases = usePage<AnalysisCase>("/analysis-batches/" + batchId + "/cases");
  const reviews = usePage<AnalysisReview>("/analysis-batches/" + batchId + "/reviews");
  return <>
    <p>Hasil tersimpan. Kandidat case berstatus Pending menunggu validasi tim maintenance. Sentimen memakai model dengan target proksi rating; aturan domain masih memerlukan persetujuan review.</p>
    <section aria-labelledby="case-heading" aria-busy={cases.loading}>
      <h2 id="case-heading">Kandidat Maintenance Case{cases.result ? " (" + cases.result.meta.total + ")" : ""}</h2>
      {cases.error && <p role="alert">{cases.error} <button onClick={cases.retry}>Muat ulang case</button></p>}
      {cases.loading ? <p>Memuat case...</p> : !cases.error && cases.result && <>
        {!cases.result.data.length && <p>Tidak ada kandidat case. Hasil setiap ulasan tetap tersedia.</p>}
        {cases.result.data.map(item => <CaseCard key={item.case_id} item={item} batchId={batchId} />)}
        <Pager page={cases.result.meta} loading={cases.loading} onPage={cases.setPage} />
      </>}
    </section>
    <section aria-labelledby="review-heading" aria-busy={reviews.loading}>
      <h2 id="review-heading">Hasil per ulasan</h2>
      {reviews.error && <p role="alert">{reviews.error} <button onClick={reviews.retry}>Muat ulang hasil</button></p>}
      {reviews.loading ? <p>Memuat hasil ulasan...</p> : !reviews.error && reviews.result && <>
        <div style={{ overflowX: "auto" }}><table style={{ width: "100%", textAlign: "left" }}>
          <caption>Hasil analisis halaman {reviews.result.meta.current_page}</caption>
          <thead><tr><th>ID asal</th><th>Ulasan</th><th>Sentimen</th><th>Kategori / severity</th><th>Issue / modul / fitur</th><th>Case</th></tr></thead>
          <tbody>{reviews.result.data.map(review => <tr key={review.review_id}>
            <td>{review.source_review_id}</td><td>{review.teks_ulasan}</td>
            <td>{review.analysis_result?.sentimen || "Belum tersedia"}{review.analysis_result?.needs_manual_review ? " (perlu review)" : ""}</td>
            <td>{review.analysis_result?.kategori || "—"} / {review.analysis_result?.severity || "Tidak ditentukan"}</td>
            <td>{review.analysis_result?.issue || "Belum ada issue teridentifikasi"} / {review.analysis_result?.modul || "—"} / {review.analysis_result?.fitur || "—"}</td>
            <td>{review.case_id ?? "—"}</td>
          </tr>)}</tbody>
        </table></div>
        <Pager page={reviews.result.meta} loading={reviews.loading} onPage={reviews.setPage} />
      </>}
    </section>
  </>;
}
