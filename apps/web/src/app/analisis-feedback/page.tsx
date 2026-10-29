
"use client";

import { useEffect, useRef, useState } from "react";
import type { FormEvent, CSSProperties } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";
import { ShieldCheckIcon } from "@/components/icons";

type Review = {
  review_id: number;
  source_review_id: string;
  teks_ulasan: string;
  rating: number;
  case_id: number | null;
  analysis_result: {
    sentimen: string;
    kategori: string;
    needs_manual_review: boolean;
  } | null;
};

type Case = {
  case_id: number;
  judul_issue: string;
  severity: string;
  priority_score: number;
  ringkasan_masalah: string;
  recommendation: string | null;
  status_validasi: string;
};

type Batch = {
  batch_id: number;
  status: string;
  review_count: number;
  error: string | null;
  attempt: number;
  reviews: Review[];
  cases: Case[];
};

type SeverityLevel = "high" | "medium" | "low" | "unknown";

const menuItems = [
  { label: "Analisis Feedback", href: "/analisis-feedback", icon: "▤" },
  { label: "Maintenance Case", href: "#", icon: "▣" },
  { label: "Maintenance Backlog", href: "/maintenance-backlog", icon: "☷" },
  { label: "Maintenance Issue", href: "#", icon: "⚑" },
  { label: "Pull Request", href: "#", icon: "⑂" },
];

const severityStyles: Record<
  SeverityLevel,
  { label: string; color: string; background: string; border: string }
> = {
  high: {
    label: "HIGH",
    color: "#B42318",
    background: "#FEF3F2",
    border: "#FECDCA",
  },
  medium: {
    label: "MEDIUM",
    color: "#B54708",
    background: "#FFFAEB",
    border: "#FEDF89",
  },
  low: {
    label: "LOW",
    color: "#027A48",
    background: "#ECFDF3",
    border: "#ABEFC6",
  },
  unknown: {
    label: "BELUM DIKETAHUI",
    color: "#475467",
    background: "#F2F4F7",
    border: "#D0D5DD",
  },
};

function getSeverityLevel(severity: string): SeverityLevel {
  const value = severity.trim().toLowerCase();

  if (["high", "tinggi", "critical", "kritis"].includes(value)) {
    return "high";
  }

  if (["medium", "sedang", "moderate"].includes(value)) {
    return "medium";
  }

  if (["low", "rendah", "minor"].includes(value)) {
    return "low";
  }

  return "unknown";
}

function getSentimentStyle(sentiment: string): CSSProperties {
  const value = sentiment.trim().toLowerCase();

  if (["negative", "negatif"].includes(value)) {
    return {
      color: "#991B1B",
      background: "#FECACA",
      borderColor: "#F87171",
    };
  }

  if (["positive", "positif"].includes(value)) {
    return {
      color: "#166534",
      background: "#BBF7D0",
      borderColor: "#4ADE80",
    };
  }

  if (["neutral", "netral"].includes(value)) {
    return {
      color: "#475467",
      background: "#F2F4F7",
      borderColor: "#D0D5DD",
    };
  }

  return {
    color: "#6941C6",
    background: "#F4F3FF",
    borderColor: "#D9D6FE",
  };
}

function getStatusStyle(status: string): CSSProperties {
  const value = status.trim().toLowerCase();

  if (["selesai", "approved", "valid"].includes(value)) {
    return {
      color: "#027A48",
      background: "#ECFDF3",
      borderColor: "#ABEFC6",
    };
  }

  if (["gagal", "rejected", "ditolak"].includes(value)) {
    return {
      color: "#B42318",
      background: "#FEF3F2",
      borderColor: "#FECDCA",
    };
  }

  return {
    color: "#475467",
    background: "#F2F4F7",
    borderColor: "#EAECF0",
  };
}

const severityCardStyle: Record<SeverityLevel, CSSProperties> = {
  high: {
    background: "#FEF3F2",
    borderColor: "#FECDCA",
    color: "#B42318",
  },
  medium: {
    background: "#FFFAEB",
    borderColor: "#FEDF89",
    color: "#B54708",
  },
  low: {
    background: "#ECFDF3",
    borderColor: "#ABEFC6",
    color: "#027A48",
  },
  unknown: {
    background: "#F2F4F7",
    borderColor: "#D0D5DD",
    color: "#475467",
  },
};

const panelStyle: CSSProperties = {
  border: "1px solid #EAECF0",
  borderRadius: 16,
  padding: 20,
  background: "#FFFFFF",
  minWidth: 0,
};

const badgeBaseStyle: CSSProperties = {
  display: "inline-flex",
  alignItems: "center",
  justifyContent: "center",
  padding: "5px 10px",
  borderRadius: 999,
  border: "1px solid",
  fontSize: 11,
  fontWeight: 700,
  letterSpacing: "0.04em",
  whiteSpace: "nowrap",
};

export default function AnalisisFeedbackPage() {
  const router = useRouter();

  const [user, setUser] = useState<AuthUser | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [batchId, setBatchId] = useState<number | null>(null);
  const [batch, setBatch] = useState<Batch | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [severityFilter, setSeverityFilter] = useState("all");
  const [visibleReviewCount, setVisibleReviewCount] = useState(50);

  const requestKey = useRef("");

  useEffect(() => {
    apiFetch<{ data: AuthUser }>("/auth/me")
      .then(({ data }) => {
        if (data.role !== "Tim Maintenance") {
          router.replace("/maintenance-backlog");
          return;
        }

        setUser(data);

        const id = Number(
          new URLSearchParams(window.location.search).get("batch")
        );

        if (Number.isSafeInteger(id) && id > 0) {
          setBatchId(id);
        }
      })
      .catch(() => router.replace("/login"));
  }, [router]);

  useEffect(() => {
    if (!user || !batchId) return;

    let active = true;
    let timer: ReturnType<typeof setTimeout>;

    const poll = async () => {
      try {
        const { data } = await apiFetch<{ data: Batch }>(
          `/analysis-batches/${batchId}`
        );

        if (!active) return;

        setBatch(data);
        setError("");

        if (data.status === "Diproses") {
          timer = setTimeout(poll, 2000);
        }
      } catch (e) {
        if (!active) return;

        if (e instanceof ApiError && e.status === 401) {
          router.replace("/login");
        }

        setError(
          e instanceof Error ? e.message : "Tidak dapat mengambil hasil."
        );
      }
    };

    void poll();

    return () => {
      active = false;
      clearTimeout(timer);
    };
  }, [batchId, user, router]);

  async function upload(e: FormEvent) {
    e.preventDefault();

    if (!file || busy || batch?.status === "Diproses") return;

    setBusy(true);
    setError("");

    try {
      const form = new FormData();
      form.append("file", file);

      if (!requestKey.current) {
        requestKey.current = crypto.randomUUID();
      }

      const { data } = await apiFetch<{
        data: { batch_id: number };
      }>("/analysis-batches", {
        method: "POST",
        body: form,
        headers: {
          "Idempotency-Key": requestKey.current,
        },
      });

      window.history.replaceState(
        null,
        "",
        `?batch=${data.batch_id}`
      );

      if (data.batch_id === batchId) {
        const current = await apiFetch<{ data: Batch }>(
          `/analysis-batches/${data.batch_id}`
        );

        setBatch(current.data);
      } else {
        setBatch(null);
        setBatchId(data.batch_id);
        setVisibleReviewCount(50);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unggahan gagal.");
    } finally {
      setBusy(false);
    }
  }

  async function retry() {
    if (!batchId) return;

    setBusy(true);
    setError("");

    try {
      await apiFetch(`/analysis-batches/${batchId}/retry`, {
        method: "POST",
      });

      window.location.reload();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Pengulangan gagal.");
    } finally {
      setBusy(false);
    }
  }

  async function logout() {
    try {
      await apiFetch("/auth/logout", { method: "POST" });
      router.replace("/login");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Logout gagal.");
    }
  }

  if (!user) {
    return <main style={{ padding: 32 }}>Memeriksa sesi...</main>;
  }

  const cases = batch?.cases ?? [];

  const severityCounts = {
    high: cases.filter((c) => getSeverityLevel(c.severity) === "high").length,
    medium: cases.filter((c) => getSeverityLevel(c.severity) === "medium").length,
    low: cases.filter((c) => getSeverityLevel(c.severity) === "low").length,
    unknown: cases.filter((c) => getSeverityLevel(c.severity) === "unknown").length,
  };

  const filteredCases = cases.filter((c) => {
    if (severityFilter === "all") return true;
    return getSeverityLevel(c.severity) === severityFilter;
  });

  return (
    <main className="analysis-page">
      <header className="analysis-header">
        <div className="analysis-brand">
          <span className="analysis-brand__logo">
            <ShieldCheckIcon size={20} />
          </span>
          <span>MaintainGov</span>
        </div>

        <div className="analysis-header__right">
          <span className="analysis-user">{user.nama}</span>
          <button className="analysis-logout" onClick={logout}>
            Keluar ↗
          </button>
        </div>
      </header>

      <div className="analysis-layout">
        <aside className="analysis-sidebar">
          <p className="analysis-sidebar__label">MENU UTAMA</p>

          {menuItems.map((item) => (
            <a
              key={item.label}
              href={item.href}
              className={
                item.label === "Analisis Feedback" ? "active" : undefined
              }
              onClick={
                item.href === "#"
                  ? (e) => e.preventDefault()
                  : undefined
              }
            >
              <span className="analysis-menu-icon">{item.icon}</span>
              {item.label}
            </a>
          ))}

          <div className="analysis-sidebar__footer">
            <span className="analysis-avatar">
              {user.nama?.charAt(0)?.toUpperCase() || "A"}
            </span>
            <div>
              <strong>{user.nama}</strong>
              <small>{user.role}</small>
            </div>
          </div>
        </aside>

        <section className="analysis-content">
          <div className="analysis-heading-row">
            <div>
              <p className="analysis-eyebrow">
                MAINTENANCE MANAGEMENT
              </p>
              <h1 className="analysis-title">Analisis Feedback</h1>
              <p className="analysis-description">
                Unggah dataset ulasan untuk mengidentifikasi masalah dan
                membantu tim menentukan prioritas maintenance.
              </p>
            </div>
          </div>

          <section className="analysis-card">
            <div className="analysis-card__heading">
              <div className="analysis-card__icon">↑</div>
              <div>
                <h2>Unggah Dataset</h2>
                <p>Pilih file CSV untuk memulai analisis feedback.</p>
              </div>
            </div>

            <form onSubmit={upload}>
              <label className="analysis-dropzone" htmlFor="feedback-file">
                <span className="analysis-file-icon">⇧</span>
                <strong>
                  {file ? file.name : "Pilih file dataset CSV"}
                </strong>
                <span>
                  {file
                    ? `${(file.size / 1024).toFixed(1)} KB`
                    : "Klik untuk memilih file dari perangkat kamu"}
                </span>
                <small>Format CSV · Maksimal 8 MiB · 10.000 ulasan</small>

                <input
                  id="feedback-file"
                  type="file"
                  accept=".csv,text/csv"
                  disabled={busy || batch?.status === "Diproses"}
                  onChange={(e) => {
                    setFile(e.target.files?.[0] ?? null);
                    requestKey.current = "";
                    setError("");
                  }}
                />
              </label>

              <p className="analysis-format-note">
                Kolom wajib:{" "}
                <strong>reviewId, Review Teks, rating, date</strong>.
                Format tanggal: YYYY-MM-DD HH:MM:SS.
              </p>

              <button
                className="analysis-primary-button"
                type="submit"
                disabled={!file || busy || batch?.status === "Diproses"}
              >
                {busy
                  ? "Memproses..."
                  : batch?.status === "Diproses"
                    ? "Analisis sedang berjalan..."
                    : "Analisis Dataset →"}
              </button>
            </form>
          </section>

          {error && (
            <p className="analysis-error" role="alert">
              {error}
            </p>
          )}

          {batch && (
            <section className="analysis-card" aria-live="polite">
              <div className="analysis-card__heading">
                <div className="analysis-card__icon">▤</div>
                <div>
                  <h2>Hasil Analisis</h2>
                  <p>
                    Batch #{batch.batch_id} · {batch.review_count} ulasan ·
                    Percobaan {batch.attempt}
                  </p>
                </div>
                <span className="analysis-status">{batch.status}</span>
              </div>

              {batch.status === "Diproses" && (
                <p>
                  Analisis sedang diproses. Status diperbarui otomatis.
                </p>
              )}

              {batch.status === "Gagal" && (
                <div>
                  <p className="analysis-error">{batch.error}</p>
                  <button
                    className="analysis-primary-button"
                    disabled={busy}
                    onClick={retry}
                  >
                    Coba Ulang Analisis
                  </button>
                </div>
              )}

              {batch.status === "Selesai" && (
                <>
                  <p className="analysis-description">
                    Hasil tersimpan. Kandidat case tetap menunggu validasi
                    tim maintenance. Model sentimen menggunakan proksi
                    rating; kategori dan severity masih dalam review.
                  </p>

                  <div
                    style={{
                      display: "grid",
                      gridTemplateColumns: "repeat(auto-fit, minmax(145px, 1fr))",
                      gap: 12,
                      margin: "24px 0",
                    }}
                  >
                    {(
                      [
                        ["high", "High", severityCounts.high],
                        ["medium", "Medium", severityCounts.medium],
                        ["low", "Low", severityCounts.low],
                        ["unknown", "Belum diketahui", severityCounts.unknown],
                      ] as const
                    ).map(([level, label, count]) => (
                      <div
                        key={level}
                        style={{
                          ...panelStyle,
                          ...severityCardStyle[level],
                          padding: 16,
                          borderWidth: 1,
                          borderStyle: "solid",
                        }}
                      >
                        <div
                          style={{
                            fontSize: 12,
                            fontWeight: 700,
                            marginBottom: 10,
                          }}
                        >
                          {label.toUpperCase()}
                        </div>
                        <div
                          style={{
                            fontSize: 30,
                            fontWeight: 750,
                            lineHeight: 1.2,
                          }}
                        >
                          {count}
                        </div>
                        <div style={{ fontSize: 12, marginTop: 5 }}>
                          maintenance case
                        </div>
                      </div>
                    ))}
                  </div>

                  <div
                    style={{
                      display: "flex",
                      flexWrap: "wrap",
                      alignItems: "center",
                      justifyContent: "space-between",
                      gap: 12,
                      marginBottom: 16,
                    }}
                  >
                    <div>
                      <h3 className="analysis-section-title" style={{ margin: 0 }}>
                        Kandidat Maintenance Case ({cases.length})
                      </h3>
                      <p
                        style={{
                          color: "#667085",
                          fontSize: 13,
                          margin: "6px 0 0",
                        }}
                      >
                        Pilih severity untuk menyaring masalah berdasarkan
                        tingkat urgensi.
                      </p>
                    </div>

                    <label
                      style={{
                        display: "flex",
                        alignItems: "center",
                        gap: 8,
                        fontSize: 13,
                        fontWeight: 600,
                      }}
                    >
                      Severity
                      <select
                        value={severityFilter}
                        onChange={(e) => setSeverityFilter(e.target.value)}
                        style={{
                          padding: "9px 12px",
                          borderRadius: 9,
                          border: "1px solid #D0D5DD",
                          background: "#FFFFFF",
                          color: "#344054",
                        }}
                      >
                        <option value="all">Semua</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                        <option value="unknown">Belum diketahui</option>
                      </select>
                    </label>
                  </div>

                  {!cases.length && (
                    <p>
                      Belum ada kandidat case. Hasil per ulasan tersedia di
                      bagian bawah.
                    </p>
                  )}

                  {cases.length > 0 && filteredCases.length === 0 && (
                    <p>Tidak ada case dengan severity tersebut.</p>
                  )}

                  <div
                    style={{
                      display: "grid",
                      gridTemplateColumns: "repeat(auto-fit, minmax(min(100%, 320px), 1fr))",
                      gap: 16,
                      marginBottom: 28,
                    }}
                  >
                    {filteredCases.slice(0, 200).map((c) => {
                      const level = getSeverityLevel(c.severity);
                      const severity = severityStyles[level];
                      const matchedReviews = batch.reviews.filter(
                        (r) => r.case_id === c.case_id
                      );

                      return (
                        <article
                          key={c.case_id}
                          style={{
                            ...panelStyle,
                            borderTop: `4px solid ${severity.color}`,
                            display: "flex",
                            flexDirection: "column",
                            gap: 12,
                          }}
                        >
                          <div
                            style={{
                              display: "flex",
                              alignItems: "flex-start",
                              justifyContent: "space-between",
                              gap: 10,
                            }}
                          >
                            <span
                              style={{
                                fontSize: 12,
                                color: "#667085",
                                fontWeight: 600,
                              }}
                            >
                              CASE #{c.case_id}
                            </span>
                            <span
                              style={{
                                ...badgeBaseStyle,
                                color: severity.color,
                                background: severity.background,
                                borderColor: severity.border,
                              }}
                            >
                              {severity.label}
                            </span>
                          </div>

                          <h4
                            style={{
                              fontSize: 17,
                              lineHeight: 1.45,
                              margin: 0,
                              color: "#101828",
                              overflowWrap: "anywhere",
                            }}
                          >
                            {c.judul_issue}
                          </h4>

                          <div
                            style={{
                              display: "flex",
                              alignItems: "center",
                              flexWrap: "wrap",
                              gap: 8,
                            }}
                          >
                            <span
                              style={{
                                ...badgeBaseStyle,
                                ...getStatusStyle(c.status_validasi),
                                letterSpacing: 0,
                              }}
                            >
                              {c.status_validasi}
                            </span>
                            <span
                              style={{
                                fontSize: 12,
                                color: "#667085",
                              }}
                            >
                              {matchedReviews.length} ulasan terkait
                            </span>
                          </div>

                          <div
                            style={{
                              padding: 14,
                              background: "#F9FAFB",
                              borderRadius: 10,
                              border: "1px solid #EAECF0",
                            }}
                          >
                            <div
                              style={{
                                fontSize: 12,
                                color: "#667085",
                                marginBottom: 5,
                              }}
                            >
                              Skor prioritas
                            </div>
                            <div
                              style={{
                                display: "flex",
                                alignItems: "baseline",
                                gap: 5,
                              }}
                            >
                              <strong
                                style={{
                                  fontSize: 25,
                                  color: severity.color,
                                }}
                              >
                                {Number(c.priority_score).toFixed(2)}
                              </strong>
                              <span style={{ color: "#667085", fontSize: 13 }}>
                                / 10
                              </span>
                            </div>
                            <div
                              style={{
                                height: 6,
                                background: "#EAECF0",
                                borderRadius: 99,
                                marginTop: 9,
                                overflow: "hidden",
                              }}
                            >
                              <div
                                style={{
                                  height: "100%",
                                  width: `${Math.min(
                                    100,
                                    Math.max(0, Number(c.priority_score) * 10)
                                  )}%`,
                                  background: severity.color,
                                  borderRadius: 99,
                                }}
                              />
                            </div>
                          </div>

                          <div>
                            <h5
                              style={{
                                margin: "0 0 6px",
                                fontSize: 13,
                                color: "#344054",
                              }}
                            >
                              Ringkasan masalah
                            </h5>
                            <p
                              style={{
                                margin: 0,
                                fontSize: 14,
                                lineHeight: 1.65,
                                color: "#475467",
                                overflowWrap: "anywhere",
                              }}
                            >
                              {c.ringkasan_masalah || "Belum ada ringkasan."}
                            </p>
                          </div>

                          <div>
                            <h5
                              style={{
                                margin: "0 0 6px",
                                fontSize: 13,
                                color: "#344054",
                              }}
                            >
                              Saran penanganan
                            </h5>
                            <p
                              style={{
                                margin: 0,
                                fontSize: 14,
                                lineHeight: 1.65,
                                color: "#475467",
                                overflowWrap: "anywhere",
                              }}
                            >
                              {c.recommendation || "Belum tersedia"}
                            </p>
                          </div>

                          <details
                            style={{
                              borderTop: "1px solid #EAECF0",
                              paddingTop: 12,
                              marginTop: "auto",
                            }}
                          >
                            <summary
                              style={{
                                cursor: "pointer",
                                color: "#344054",
                                fontSize: 13,
                                fontWeight: 650,
                              }}
                            >
                              Lihat ulasan pembentuk case ({matchedReviews.length})
                            </summary>

                            {matchedReviews.length ? (
                              <ul
                                style={{
                                  paddingLeft: 20,
                                  margin: "12px 0 0",
                                }}
                              >
                                {matchedReviews.map((r) => (
                                  <li
                                    key={r.review_id}
                                    style={{
                                      marginBottom: 10,
                                      fontSize: 13,
                                      lineHeight: 1.6,
                                      color: "#475467",
                                      overflowWrap: "anywhere",
                                    }}
                                  >
                                    <div
                                      style={{
                                        fontSize: 11,
                                        color: "#98A2B3",
                                        marginBottom: 3,
                                      }}
                                    >
                                      {r.source_review_id}
                                    </div>
                                    {r.teks_ulasan}
                                  </li>
                                ))}
                              </ul>
                            ) : (
                              <p
                                style={{
                                  color: "#667085",
                                  fontSize: 13,
                                }}
                              >
                                Tidak ada ulasan terkait yang ditemukan pada
                                batch ini.
                              </p>
                            )}
                          </details>
                        </article>
                      );
                    })}
                  </div>

                  {filteredCases.length > 200 && (
                    <p>Menampilkan 200 case pertama dari hasil filter.</p>
                  )}

                  <h3 className="analysis-section-title">
                    Hasil per Ulasan
                  </h3>

                  <div className="analysis-table-wrap">
                    <table className="analysis-table">
                      <thead>
                        <tr>
                          <th>ID Asal</th>
                          <th>Ulasan</th>
                          <th>Sentimen</th>
                          <th>Kategori</th>
                          <th>Case</th>
                        </tr>
                      </thead>
                      <tbody>
                        {batch.reviews.slice(0, visibleReviewCount).map((r) => {
                          const sentiment = r.analysis_result?.sentimen;

                          return (
                            <tr key={r.review_id}>
                              <td>{r.source_review_id}</td>
                              <td>{r.teks_ulasan}</td>
                              <td>
                                {sentiment ? (
                                  <span
                                    style={{
                                      ...badgeBaseStyle,
                                      ...getSentimentStyle(sentiment),
                                      textTransform: "capitalize",
                                      letterSpacing: 0,
                                    }}
                                  >
                                    {sentiment}
                                  </span>
                                ) : (
                                  "—"
                                )}
                              </td>
                              <td>
                                {r.analysis_result?.kategori || "—"}
                                {r.analysis_result?.needs_manual_review
                                  ? " (perlu review)"
                                  : ""}
                              </td>
                              <td>{r.case_id ?? "—"}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>

                  <div
                    style={{
                      display: "flex",
                      flexDirection: "column",
                      alignItems: "flex-start",
                      gap: 12,
                      marginTop: 16,
                    }}
                  >
                    <p
                      style={{
                        margin: 0,
                        fontSize: 13,
                        color: "#667085",
                      }}
                    >
                      Menampilkan{" "}
                      {Math.min(visibleReviewCount, batch.reviews.length)} dari{" "}
                      {batch.reviews.length} ulasan
                    </p>

                    {visibleReviewCount < batch.reviews.length && (
                      <button
                        type="button"
                        onClick={() =>
                          setVisibleReviewCount((current) => current + 50)
                        }
                        style={{
                          padding: "10px 18px",
                          borderRadius: 8,
                          border: "1px solid #D0D5DD",
                          background: "#FFFFFF",
                          color: "#344054",
                          fontSize: 13,
                          fontWeight: 600,
                          cursor: "pointer",
                        }}
                      >
                        Load More ↓
                      </button>
                    )}
                  </div>
                </>
              )}
            </section>
          )}
        </section>
      </div>
    </main>
  );
}
