
"use client";

import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
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

const menuItems = [
  { label: "Analisis Feedback", href: "/analisis-feedback", icon: "▤" },
  { label: "Maintenance Case", href: "#", icon: "▣" },
  { label: "Maintenance Backlog", href: "/maintenance-backlog", icon: "☷" },
  { label: "Maintenance Issue", href: "#", icon: "⚑" },
  { label: "Pull Request", href: "#", icon: "⑂" },
];

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

        if (Number.isSafeInteger(id) && id > 0) setBatchId(id);
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

      const { data } = await apiFetch<{ data: { batch_id: number } }>(
        "/analysis-batches",
        {
          method: "POST",
          body: form,
          headers: { "Idempotency-Key": requestKey.current },
        }
      );

      window.history.replaceState(null, "", `?batch=${data.batch_id}`);

      if (data.batch_id === batchId) {
        const current = await apiFetch<{ data: Batch }>(
          `/analysis-batches/${data.batch_id}`
        );

        setBatch(current.data);
      } else {
        setBatch(null);
        setBatchId(data.batch_id);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unggahan gagal.");
    } finally {
      setBusy(false);
    }
  }

  async function retry() {
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
              <p className="analysis-eyebrow">MAINTENANCE MANAGEMENT</p>
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
                    Hasil tersimpan. Kandidat case tetap menunggu validasi tim
                    maintenance. Model sentimen menggunakan proksi rating;
                    kategori dan severity masih dalam review.
                  </p>

                  <h3 className="analysis-section-title">
                    Kandidat Maintenance Case ({batch.cases.length})
                  </h3>

                  {!batch.cases.length && (
                    <p>
                      Belum ada kandidat case. Hasil per ulasan tersedia di
                      bagian bawah.
                    </p>
                  )}

                  <div className="analysis-case-list">
                    {batch.cases.slice(0, 200).map((c) => (
                      <article className="analysis-case" key={c.case_id}>
                        <h4>
                          #{c.case_id} {c.judul_issue}
                        </h4>
                        <p>
                          {c.status_validasi} · Severity: {c.severity} ·
                          Prioritas: {Number(c.priority_score).toFixed(2)}/10
                        </p>
                        <p>{c.ringkasan_masalah}</p>
                        <p>
                          Saran penanganan:{" "}
                          {c.recommendation || "Belum tersedia"}
                        </p>

                        <details>
                          <summary>Ulasan pembentuk case</summary>
                          <ul>
                            {batch.reviews
                              .filter((r) => r.case_id === c.case_id)
                              .map((r) => (
                                <li key={r.review_id}>
                                  {r.source_review_id}: {r.teks_ulasan}
                                </li>
                              ))}
                          </ul>
                        </details>
                      </article>
                    ))}
                  </div>

                  {batch.cases.length > 200 && (
                    <p>Menampilkan 200 case pertama.</p>
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
                        {batch.reviews.slice(0, 200).map((r) => (
                          <tr key={r.review_id}>
                            <td>{r.source_review_id}</td>
                            <td>{r.teks_ulasan}</td>
                            <td>{r.analysis_result?.sentimen || "—"}</td>
                            <td>
                              {r.analysis_result?.kategori || "—"}
                              {r.analysis_result?.needs_manual_review
                                ? " (perlu review)"
                                : ""}
                            </td>
                            <td>{r.case_id ?? "—"}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {batch.reviews.length > 200 && (
                    <p>
                      Menampilkan 200 ulasan pertama dari{" "}
                      {batch.reviews.length}.
                    </p>
                  )}
                </>
              )}
            </section>
          )}
        </section>
      </div>
    </main>
  );
}
