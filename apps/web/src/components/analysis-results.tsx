
"use client";

import { useEffect, useState } from "react";
import type { ReactNode } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type {
  AnalysisCase,
  AnalysisReview,
  Page,
} from "@/lib/analysis-types";

// Gaya ada di globals.css (kelas ar-*), sama dengan halaman dalam lainnya.
type Severity = "High" | "Medium" | "Low" | "Default";

function getSeverity(value?: string | null): Severity {
  const normalized = value?.trim().toLowerCase();

  if (normalized === "high") return "High";
  if (normalized === "medium") return "Medium";
  if (normalized === "low") return "Low";

  return "Default";
}

function SeverityBadge({ value }: { value?: string | null }) {
  const severity = getSeverity(value);

  return (
    <span className={`ar-badge ar-badge--${severity.toLowerCase()}`}>
      {severity === "Default" ? value || "Tidak ditentukan" : severity}
    </span>
  );
}

function SentimentBadge({ value }: { value?: string | null }) {
  const sentiment = String(value ?? "").trim();
  const normalized = sentiment.toLowerCase();

  const tone =
    normalized.includes("positive") || normalized.includes("positif")
      ? "positive"
      : normalized.includes("negative") || normalized.includes("negatif")
        ? "negative"
        : "default";

  return (
    <span className={`ar-badge ar-badge--${tone}`}>
      {sentiment || "Belum tersedia"}
    </span>
  );
}

function SectionCard({ children, labelledBy, busy }: { children: ReactNode; labelledBy: string; busy: boolean }) {
  return (
    <section aria-labelledby={labelledBy} aria-busy={busy} className="ar-section">
      {children}
    </section>
  );
}

function Pager({
  page,
  loading,
  onPage,
}: {
  page: Page<unknown>["meta"];
  loading: boolean;
  onPage: (value: number) => void;
}) {
  return (
    <nav aria-label="Halaman hasil" className="ar-pager">
      <span>
        Halaman {page.current_page} dari {page.last_page} · {page.total} data
      </span>

      <div className="ar-pager__nav">
        <button
          type="button"
          className="ar-btn"
          disabled={loading || page.current_page <= 1}
          onClick={() => onPage(page.current_page - 1)}
        >
          Sebelumnya
        </button>

        <button
          type="button"
          className="ar-btn"
          disabled={loading || page.current_page >= page.last_page}
          onClick={() => onPage(page.current_page + 1)}
        >
          Berikutnya
        </button>
      </div>
    </nav>
  );
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

    setLoading(true);
    setError("");

    apiFetch<Page<T>>(
      path +
        (path.includes("?") ? "&" : "?") +
        "page=" +
        page +
        "&per_page=25",
      { signal: controller.signal }
    )
      .then((value) => {
        if (active) setResult(value);
      })
      .catch((e: unknown) => {
        if (!active) return;

        if (e instanceof ApiError && e.status === 401) {
          router.replace("/login");
        }

        setError(
          e instanceof Error ? e.message : "Tidak dapat memuat hasil."
        );
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
      controller.abort();
    };
  }, [path, page, refresh, router]);

  return {
    result,
    page,
    setPage,
    error,
    loading,
    retry: () => setRefresh((value) => value + 1),
  };
}

function SourceReviews({
  batchId,
  caseId,
}: {
  batchId: number;
  caseId: number;
}) {
  const source = usePage<AnalysisReview>(
    "/analysis-batches/" + batchId + "/reviews?case_id=" + caseId
  );

  return (
    <div aria-busy={source.loading} className="ar-source">
      <h4 className="ar-source__title">Ulasan pembentuk case</h4>

      {source.error && (
        <p role="alert">
          {source.error}{" "}
          <button type="button" onClick={source.retry} className="ar-btn">
            Muat ulang ulasan
          </button>
        </p>
      )}

      {source.loading ? (
        <p>Memuat ulasan asal...</p>
      ) : (
        !source.error &&
        source.result && (
          <>
            {source.result.data.length === 0 ? (
              <p>Tidak ada ulasan pada halaman ini.</p>
            ) : (
              <ul className="ar-source__list">
                {source.result.data.map((review) => (
                  <li key={review.review_id}>
                    <strong>{review.source_review_id}:</strong>{" "}
                    {review.teks_ulasan}
                  </li>
                ))}
              </ul>
            )}

            <Pager
              page={source.result.meta}
              loading={source.loading}
              onPage={source.setPage}
            />
          </>
        )
      )}
    </div>
  );
}

function CaseCard({
  item,
  batchId,
}: {
  item: AnalysisCase;
  batchId: number;
}) {
  const [open, setOpen] = useState(false);
  const score = Number(item.priority_score);
  const scoreTone = score >= 7 ? "high" : score >= 4 ? "medium" : "low";
  const scorePct = Number.isFinite(score) ? Math.max(0, Math.min(100, score * 10)) : 0;

  return (
    <article className="ar-case">
      <div className="ar-case__top">
        <p className="ar-case__id">MAINTENANCE CASE #{item.case_id}</p>
        <SeverityBadge value={item.severity} />
      </div>

      <h3 className="ar-case__title">{item.judul_issue}</h3>

      <div className="ar-case__tags">
        <span className="ar-badge ar-badge--pending">{item.status_validasi}</span>
        <span className="ar-badge ar-badge--default">{item.kategori}</span>
      </div>

      <div className={`ar-score ar-score--${scoreTone}`}>
        <span className="ar-score__label">Skor prioritas</span>
        <span className="ar-score__value">
          {Number.isFinite(score) ? score.toFixed(2) : "—"}<small>/10</small>
        </span>
        <span className="ar-score__track" aria-hidden="true">
          <span className="ar-score__fill" style={{ width: `${scorePct}%` }} />
        </span>
      </div>

      <p className="ar-case__meta">
        <strong>Modul:</strong> {item.modul}
        {" · "}
        <strong>Fitur:</strong> {item.fitur}
      </p>

      <div>
        <p className="ar-case__label">Ringkasan masalah</p>
        <p className="ar-case__text">{item.ringkasan_masalah}</p>
      </div>

      <div>
        <p className="ar-case__label">Saran penanganan</p>
        <p className="ar-case__text">
          {item.recommendation || "Perlu review tim maintenance."}
        </p>
      </div>

      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="ar-case__toggle"
      >
        {open ? "Tutup ulasan asal" : "Lihat ulasan pembentuk case"}
        <span aria-hidden="true">{open ? " ↑" : " ↓"}</span>
      </button>

      {open && <SourceReviews batchId={batchId} caseId={item.case_id} />}
    </article>
  );
}

export default function AnalysisResults({
  batchId,
}: {
  batchId: number;
}) {
  const cases = usePage<AnalysisCase>(
    "/analysis-batches/" + batchId + "/cases"
  );

  const reviews = usePage<AnalysisReview>(
    "/analysis-batches/" + batchId + "/reviews"
  );

  const [searchCase, setSearchCase] = useState("");
  const [severityFilter, setSeverityFilter] = useState("Semua");
  const [statusFilter, setStatusFilter] = useState("Semua");

  const filteredCases = (cases.result?.data ?? []).filter((item) => {
    const keyword = searchCase.trim().toLowerCase();

    const matchesSearch = [
      item.judul_issue,
      item.ringkasan_masalah,
      item.modul,
      item.fitur,
      item.kategori,
    ].some((value) =>
      String(value ?? "").toLowerCase().includes(keyword)
    );

    const matchesSeverity =
      severityFilter === "Semua" ||
      getSeverity(item.severity) === severityFilter;

    const matchesStatus =
      statusFilter === "Semua" ||
      String(item.status_validasi ?? "").toLowerCase() ===
        statusFilter.toLowerCase();

    return matchesSearch && matchesSeverity && matchesStatus;
  });

  return (
    <div className="ar">
      <div className="ar-notice">
        <strong>Analisis berhasil disimpan.</strong>
        <p>
          Kandidat case berstatus Pending menunggu validasi tim maintenance.
          Sentimen menggunakan model dengan target proksi rating, sedangkan
          aturan domain masih memerlukan persetujuan review.
        </p>
      </div>

      <SectionCard labelledBy="case-heading" busy={cases.loading}>
        <div className="ar-section__head">
          <h2 id="case-heading" className="ar-section__title">
            Kandidat Maintenance Case
            {cases.result ? ` (${cases.result.meta.total})` : ""}
          </h2>

          <p className="ar-section__hint">
            Daftar masalah yang teridentifikasi dari ulasan pengguna.
          </p>
        </div>

        <div className="ar-filters">
          <input
            type="text"
            aria-label="Cari kandidat maintenance case"
            placeholder="Cari judul, modul, atau masalah..."
            value={searchCase}
            onChange={(event) => setSearchCase(event.target.value)}
            className="ar-input"
          />

          <select
            aria-label="Filter severity"
            value={severityFilter}
            onChange={(event) => setSeverityFilter(event.target.value)}
            className="ar-select"
          >
            <option value="Semua">Semua Severity</option>
            <option value="High">High</option>
            <option value="Medium">Medium</option>
            <option value="Low">Low</option>
          </select>

          <select
            aria-label="Filter status validasi"
            value={statusFilter}
            onChange={(event) => setStatusFilter(event.target.value)}
            className="ar-select"
          >
            <option value="Semua">Semua Status</option>
            <option value="Pending">Pending</option>
            <option value="Valid">Valid</option>
            <option value="Tidak Valid">Tidak Valid</option>
          </select>

          <button
            type="button"
            onClick={() => {
              setSearchCase("");
              setSeverityFilter("Semua");
              setStatusFilter("Semua");
            }}
            className="ar-link"
          >
            Reset Filter
          </button>
        </div>

        <p aria-live="polite" className="ar-muted">
          Menampilkan {filteredCases.length} kandidat pada halaman ini.
        </p>

        {cases.error && (
          <p role="alert" className="ar-error">
            {cases.error}{" "}
            <button type="button" onClick={cases.retry} className="ar-btn">
              Muat ulang case
            </button>
          </p>
        )}

        {cases.loading ? (
          <p className="ar-muted">Memuat case...</p>
        ) : (
          !cases.error &&
          cases.result && (
            <>
              {cases.result.data.length === 0 ? (
                <p className="ar-muted">
                  Tidak ada kandidat case. Hasil setiap ulasan tetap tersedia.
                </p>
              ) : filteredCases.length === 0 ? (
                <p className="ar-muted">
                  Tidak ada kandidat case yang sesuai dengan filter. Coba ubah
                  filter atau klik Reset Filter.
                </p>
              ) : (
                <div className="ar-case-grid">
                  {filteredCases.map((item) => (
                    <CaseCard
                      key={item.case_id}
                      item={item}
                      batchId={batchId}
                    />
                  ))}
                </div>
              )}

              <Pager
                page={cases.result.meta}
                loading={cases.loading}
                onPage={cases.setPage}
              />
            </>
          )
        )}
      </SectionCard>

      <SectionCard labelledBy="review-heading" busy={reviews.loading}>
        <div className="ar-section__head">
          <h2 id="review-heading" className="ar-section__title">
            Hasil per Ulasan
          </h2>

          <p className="ar-section__hint">
            Rincian sentimen, kategori, severity, dan case untuk setiap ulasan.
          </p>
        </div>

        {reviews.error && (
          <p role="alert" className="ar-error">
            {reviews.error}{" "}
            <button type="button" onClick={reviews.retry} className="ar-btn">
              Muat ulang hasil
            </button>
          </p>
        )}

        {reviews.loading ? (
          <p className="ar-muted">Memuat hasil ulasan...</p>
        ) : (
          !reviews.error &&
          reviews.result && (
            <>
              <div className="ar-table-box">
                <div className="ar-table-scroll">
                  <table className="ar-table">
                    <caption>
                      Halaman {reviews.result.meta.current_page}
                    </caption>

                    <thead>
                      <tr>
                        {[
                          "ID asal",
                          "Ulasan",
                          "Sentimen",
                          "Kategori / Severity",
                          "Issue / Modul / Fitur",
                          "Case",
                        ].map((heading) => (
                          <th key={heading} scope="col">
                            {heading}
                          </th>
                        ))}
                      </tr>
                    </thead>

                    <tbody>
                      {reviews.result.data.map((review) => (
                        <tr key={review.review_id}>
                          <td className="ar-table__id">
                            {review.source_review_id}
                          </td>

                          <td className="ar-table__text">
                            {review.teks_ulasan}
                          </td>

                          <td>
                            <div className="ar-stack">
                              <SentimentBadge
                                value={review.analysis_result?.sentimen}
                              />

                              {review.analysis_result?.needs_manual_review && (
                                <span className="ar-flag">Perlu review</span>
                              )}
                            </div>
                          </td>

                          <td>
                            <div className="ar-stack">
                              <span>
                                {review.analysis_result?.kategori || "—"}
                              </span>

                              <SeverityBadge
                                value={review.analysis_result?.severity}
                              />
                            </div>
                          </td>

                          <td className="ar-table__issue">
                            {review.analysis_result?.issue ||
                              "Belum ada issue teridentifikasi"}
                            {" / "}
                            {review.analysis_result?.modul || "—"}
                            {" / "}
                            {review.analysis_result?.fitur || "—"}
                          </td>

                          <td className="ar-table__case">{review.case_id ?? "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <Pager
                  page={reviews.result.meta}
                  loading={reviews.loading}
                  onPage={reviews.setPage}
                />
              </div>
            </>
          )
        )}
      </SectionCard>
    </div>
  );
}
