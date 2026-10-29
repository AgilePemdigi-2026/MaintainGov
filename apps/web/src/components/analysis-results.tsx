
"use client";

import { useEffect, useState } from "react";
import type { CSSProperties, ReactNode } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type {
  AnalysisCase,
  AnalysisReview,
  Page,
} from "@/lib/analysis-types";

const colors = {
  High: { background: "#FEE2E2", color: "#B91C1C" },
  Medium: { background: "#FFEDD5", color: "#C2410C" },
  Low: { background: "#DCFCE7", color: "#15803D" },
  Default: { background: "#F1F5F9", color: "#475569" },
};

type Severity = keyof typeof colors;

function getSeverity(value?: string | null): Severity {
  const normalized = value?.trim().toLowerCase();

  if (normalized === "high") return "High";
  if (normalized === "medium") return "Medium";
  if (normalized === "low") return "Low";

  return "Default";
}

function SeverityBadge({ value }: { value?: string | null }) {
  const severity = getSeverity(value);
  const style = colors[severity];

  return (
    <span
      style={{
        display: "inline-flex",
        alignItems: "center",
        padding: "5px 10px",
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 700,
        background: style.background,
        color: style.color,
        whiteSpace: "nowrap",
      }}
    >
      {severity === "Default" ? value || "Tidak ditentukan" : severity}
    </span>
  );
}

function SentimentBadge({ value }: { value?: string | null }) {
  const sentiment = String(value ?? "").trim();
  const normalized = sentiment.toLowerCase();

  const isPositive =
    normalized.includes("positive") || normalized.includes("positif");
  const isNegative =
    normalized.includes("negative") || normalized.includes("negatif");

  const style = isPositive
    ? { background: "#DCFCE7", color: "#15803D" }
    : isNegative
      ? { background: "#FEE2E2", color: "#B91C1C" }
      : { background: "#F1F5F9", color: "#475569" };

  return (
    <span
      style={{
        display: "inline-flex",
        alignItems: "center",
        padding: "5px 10px",
        borderRadius: 999,
        fontSize: 12,
        fontWeight: 700,
        background: style.background,
        color: style.color,
        whiteSpace: "nowrap",
      }}
    >
      {sentiment || "Belum tersedia"}
    </span>
  );
}

function PriorityBadge({ score }: { score: number | string }) {
  const value = Number(score);
  const severity: Exclude<Severity, "Default"> =
    value >= 7 ? "High" : value >= 4 ? "Medium" : "Low";
  const style = colors[severity];

  return (
    <span
      style={{
        display: "inline-flex",
        alignItems: "center",
        padding: "5px 10px",
        borderRadius: 999,
        background: style.background,
        color: style.color,
        fontSize: 12,
        fontWeight: 700,
        whiteSpace: "nowrap",
      }}
    >
      {Number.isFinite(value) ? `${value.toFixed(2)}/10` : "—"}
    </span>
  );
}

function SectionCard({ children }: { children: ReactNode }) {
  return (
    <section
      style={{
        background: "#FFFFFF",
        border: "1px solid #E2E8F0",
        borderRadius: 16,
        padding: 24,
        marginBottom: 24,
        boxShadow: "0 2px 8px rgba(15, 23, 42, 0.03)",
      }}
    >
      {children}
    </section>
  );
}

const buttonStyle: CSSProperties = {
  padding: "9px 14px",
  border: "1px solid #CBD5E1",
  borderRadius: 8,
  background: "#FFFFFF",
  color: "#334155",
  fontSize: 13,
  fontWeight: 600,
  cursor: "pointer",
};

const cellStyle: CSSProperties = {
  padding: 12,
  verticalAlign: "top",
  borderBottom: "1px solid #E2E8F0",
  color: "#475569",
  lineHeight: 1.6,
};

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
    <nav
      aria-label="Halaman hasil"
      style={{
        display: "flex",
        flexWrap: "wrap",
        gap: 12,
        alignItems: "center",
        justifyContent: "space-between",
        marginTop: 20,
        color: "#64748B",
        fontSize: 13,
      }}
    >
      <span>
        Halaman {page.current_page} dari {page.last_page} · {page.total} data
      </span>

      <div style={{ display: "flex", gap: 8 }}>
        <button
          type="button"
          disabled={loading || page.current_page <= 1}
          onClick={() => onPage(page.current_page - 1)}
          style={buttonStyle}
        >
          Sebelumnya
        </button>

        <button
          type="button"
          disabled={loading || page.current_page >= page.last_page}
          onClick={() => onPage(page.current_page + 1)}
          style={buttonStyle}
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
    <div
      aria-busy={source.loading}
      style={{
        marginTop: 16,
        padding: 16,
        background: "#F8FAFC",
        borderRadius: 10,
      }}
    >
      <h4 style={{ marginTop: 0, color: "#334155" }}>
        Ulasan pembentuk case
      </h4>

      {source.error && (
        <p role="alert">
          {source.error}{" "}
          <button type="button" onClick={source.retry} style={buttonStyle}>
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
              <ul style={{ paddingLeft: 20, lineHeight: 1.7 }}>
                {source.result.data.map((review) => (
                  <li key={review.review_id} style={{ marginBottom: 8 }}>
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

  return (
    <article
      style={{
        border: "1px solid #E2E8F0",
        borderRadius: 14,
        padding: 20,
        marginBottom: 14,
        background: "#FFFFFF",
      }}
    >
      <div
        style={{
          display: "flex",
          flexWrap: "wrap",
          justifyContent: "space-between",
          alignItems: "flex-start",
          gap: 12,
        }}
      >
        <div style={{ flex: 1, minWidth: 180 }}>
          <p
            style={{
              margin: "0 0 8px",
              fontSize: 12,
              fontWeight: 700,
              color: "#64748B",
            }}
          >
            MAINTENANCE CASE #{item.case_id}
          </p>

          <h3
            style={{
              margin: "0 0 12px",
              fontSize: 17,
              lineHeight: 1.5,
              color: "#0F172A",
            }}
          >
            {item.judul_issue}
          </h3>
        </div>

        <span
          style={{
            padding: "5px 10px",
            borderRadius: 999,
            background: "#F1F5F9",
            color: "#475569",
            fontSize: 12,
            fontWeight: 700,
          }}
        >
          {item.status_validasi}
        </span>
      </div>

      <div
        style={{
          display: "flex",
          flexWrap: "wrap",
          alignItems: "center",
          gap: 8,
          marginBottom: 16,
        }}
      >
        <SeverityBadge value={item.severity} />
        <PriorityBadge score={item.priority_score} />

        <span
          style={{
            padding: "5px 10px",
            borderRadius: 999,
            background: "#EFF6FF",
            color: "#1D4ED8",
            fontSize: 12,
            fontWeight: 600,
          }}
        >
          {item.kategori}
        </span>
      </div>

      <div style={{ color: "#475569", fontSize: 14, lineHeight: 1.7 }}>
        <p style={{ margin: "0 0 8px" }}>
          <strong style={{ color: "#334155" }}>Modul:</strong> {item.modul}
          {" · "}
          <strong style={{ color: "#334155" }}>Fitur:</strong> {item.fitur}
        </p>

        <p style={{ margin: "0 0 12px" }}>{item.ringkasan_masalah}</p>

        <div
          style={{
            padding: 14,
            background: "#F8FAFC",
            borderRadius: 10,
            borderLeft: "3px solid #64748B",
          }}
        >
          <strong style={{ color: "#334155" }}>Saran penanganan</strong>
          <p style={{ margin: "5px 0 0" }}>
            {item.recommendation || "Perlu review tim maintenance."}
          </p>
        </div>
      </div>

      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        style={{ ...buttonStyle, marginTop: 16 }}
      >
        {open ? "Tutup ulasan asal ↑" : "Lihat ulasan pembentuk case ↓"}
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
    <div style={{ marginTop: 28 }}>
      <div
        style={{
          padding: 18,
          marginBottom: 24,
          borderRadius: 12,
          background: "#EFF6FF",
          border: "1px solid #DBEAFE",
          color: "#1E40AF",
          fontSize: 14,
          lineHeight: 1.7,
        }}
      >
        <strong>Analisis berhasil disimpan.</strong>
        <p style={{ margin: "4px 0 0" }}>
          Kandidat case berstatus Pending menunggu validasi tim maintenance.
          Sentimen menggunakan model dengan target proksi rating, sedangkan
          aturan domain masih memerlukan persetujuan review.
        </p>
      </div>

      <SectionCard>
        <div style={{ marginBottom: 20 }}>
          <h2
            id="case-heading"
            style={{ margin: "0 0 6px", fontSize: 20, color: "#0F172A" }}
          >
            Kandidat Maintenance Case
            {cases.result ? ` (${cases.result.meta.total})` : ""}
          </h2>

          <p style={{ margin: 0, color: "#64748B", fontSize: 13 }}>
            Daftar masalah yang teridentifikasi dari ulasan pengguna.
          </p>
        </div>

        <div
          style={{
            display: "flex",
            flexWrap: "wrap",
            gap: 12,
            marginBottom: 20,
            alignItems: "center",
          }}
        >
          <input
            type="text"
            aria-label="Cari kandidat maintenance case"
            placeholder="Cari judul, modul, atau masalah..."
            value={searchCase}
            onChange={(event) => setSearchCase(event.target.value)}
            style={{
              ...buttonStyle,
              flex: "1 1 220px",
              minWidth: 180,
              boxSizing: "border-box",
            }}
          />

          <select
            aria-label="Filter severity"
            value={severityFilter}
            onChange={(event) => setSeverityFilter(event.target.value)}
            style={buttonStyle}
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
            style={buttonStyle}
          >
            <option value="Semua">Semua Status</option>
            <option value="Pending">Pending</option>
            <option value="Approved">Approved</option>
            <option value="Rejected">Rejected</option>
          </select>

          <button
            type="button"
            onClick={() => {
              setSearchCase("");
              setSeverityFilter("Semua");
              setStatusFilter("Semua");
            }}
            style={buttonStyle}
          >
            Reset Filter
          </button>
        </div>

        <p
          aria-live="polite"
          style={{
            color: "#64748B",
            fontSize: 13,
            margin: "0 0 16px",
          }}
        >
          Menampilkan {filteredCases.length} kandidat pada halaman ini.
        </p>

        {cases.error && (
          <p role="alert" style={{ color: "#B91C1C" }}>
            {cases.error}{" "}
            <button type="button" onClick={cases.retry} style={buttonStyle}>
              Muat ulang case
            </button>
          </p>
        )}

        {cases.loading ? (
          <p style={{ color: "#64748B" }}>Memuat case...</p>
        ) : (
          !cases.error &&
          cases.result && (
            <>
              {cases.result.data.length === 0 ? (
                <p style={{ color: "#64748B" }}>
                  Tidak ada kandidat case. Hasil setiap ulasan tetap tersedia.
                </p>
              ) : filteredCases.length === 0 ? (
                <p style={{ color: "#64748B" }}>
                  Tidak ada kandidat case yang sesuai dengan filter. Coba ubah
                  filter atau klik Reset Filter.
                </p>
              ) : (
                filteredCases.map((item) => (
                  <CaseCard
                    key={item.case_id}
                    item={item}
                    batchId={batchId}
                  />
                ))
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

      <SectionCard>
        <div style={{ marginBottom: 20 }}>
          <h2
            id="review-heading"
            style={{ margin: "0 0 6px", fontSize: 20, color: "#0F172A" }}
          >
            Hasil per Ulasan
          </h2>

          <p style={{ margin: 0, color: "#64748B", fontSize: 13 }}>
            Rincian sentimen, kategori, severity, dan case untuk setiap ulasan.
          </p>
        </div>

        {reviews.error && (
          <p role="alert" style={{ color: "#B91C1C" }}>
            {reviews.error}{" "}
            <button type="button" onClick={reviews.retry} style={buttonStyle}>
              Muat ulang hasil
            </button>
          </p>
        )}

        {reviews.loading ? (
          <p style={{ color: "#64748B" }}>Memuat hasil ulasan...</p>
        ) : (
          !reviews.error &&
          reviews.result && (
            <>
              <div style={{ overflowX: "auto" }}>
                <table
                  style={{
                    width: "100%",
                    minWidth: 850,
                    borderCollapse: "collapse",
                    textAlign: "left",
                    fontSize: 13,
                  }}
                >
                  <caption
                    style={{
                      textAlign: "left",
                      padding: "0 0 12px",
                      color: "#64748B",
                    }}
                  >
                    Halaman {reviews.result.meta.current_page}
                  </caption>

                  <thead>
                    <tr style={{ background: "#F8FAFC" }}>
                      {[
                        "ID asal",
                        "Ulasan",
                        "Sentimen",
                        "Kategori / Severity",
                        "Issue / Modul / Fitur",
                        "Case",
                      ].map((heading) => (
                        <th
                          key={heading}
                          scope="col"
                          style={{
                            padding: 12,
                            borderBottom: "1px solid #E2E8F0",
                            color: "#475569",
                            fontWeight: 700,
                            whiteSpace: "nowrap",
                          }}
                        >
                          {heading}
                        </th>
                      ))}
                    </tr>
                  </thead>

                  <tbody>
                    {reviews.result.data.map((review) => (
                      <tr key={review.review_id}>
                        <td style={cellStyle}>
                          {review.source_review_id}
                        </td>

                        <td style={{ ...cellStyle, minWidth: 220 }}>
                          {review.teks_ulasan}
                        </td>

                        <td style={cellStyle}>
                          <div
                            style={{
                              display: "flex",
                              flexDirection: "column",
                              alignItems: "flex-start",
                              gap: 6,
                            }}
                          >
                            <SentimentBadge
                              value={review.analysis_result?.sentimen}
                            />

                            {review.analysis_result?.needs_manual_review && (
                              <span
                                style={{
                                  color: "#C2410C",
                                  fontSize: 12,
                                  fontWeight: 600,
                                }}
                              >
                                Perlu review
                              </span>
                            )}
                          </div>
                        </td>

                        <td style={cellStyle}>
                          <div
                            style={{
                              display: "flex",
                              flexDirection: "column",
                              alignItems: "flex-start",
                              gap: 8,
                            }}
                          >
                            <span>
                              {review.analysis_result?.kategori || "—"}
                            </span>

                            <SeverityBadge
                              value={review.analysis_result?.severity}
                            />
                          </div>
                        </td>

                        <td style={{ ...cellStyle, minWidth: 200 }}>
                          {review.analysis_result?.issue ||
                            "Belum ada issue teridentifikasi"}
                          {" / "}
                          {review.analysis_result?.modul || "—"}
                          {" / "}
                          {review.analysis_result?.fitur || "—"}
                        </td>

                        <td style={cellStyle}>{review.case_id ?? "—"}</td>
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
            </>
          )
        )}
      </SectionCard>
    </div>
  );
}
