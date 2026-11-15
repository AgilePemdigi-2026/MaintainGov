"use client";
// UC06: detail Pull Request + keputusan Tim Maintenance (Approve / Request Changes).
// GitHub tetap otoritatif; tombol sinkron membaca ulang PR dan daftar review.
import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { useSession } from "@/components/workspace/session";
import {
  Alert, BacklogStatusPill, ErrorState, InfoRow, PageHeader, PullRequestStatusPill, ReviewDecisionPill, ReviewStatePill,
  SkeletonRows, Toast,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { backlogCode, formatDateTime } from "@/lib/sprint2/labels";
import type { PullRequestDetail, PullRequestReviewDecision } from "@/lib/sprint2/types";

function PullRequestDetailView() {
  const { user, handleError } = useSession();
  const params = useParams<{ id: string }>();
  const id = Number(params?.id);
  const validId = Number.isInteger(id) && id > 0;

  const [pullRequest, setPullRequest] = useState<PullRequestDetail | null>(null);
  const [loading, setLoading] = useState(validId);
  const [error, setError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(!validId);
  const [reloadKey, setReloadKey] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const [syncError, setSyncError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ title: string; text?: string } | null>(null);
  const [decision, setDecision] = useState<PullRequestReviewDecision>("APPROVE");
  const [notes, setNotes] = useState("");
  const [notesError, setNotesError] = useState<string | null>(null);
  const [reviewError, setReviewError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submitRef = useRef(false);
  const idempotencyRef = useRef("");

  const describeError = useCallback((err: unknown, fallback: string) => {
    const message = handleError(err, fallback);
    // 403 dari UC06 memuat alasan spesifik (self-review / identity mismatch).
    // Jangan diganti pesan generik agar reviewer tahu tindakan yang diperlukan.
    if (err instanceof ApiError && err.status === 403 && err.message) return err.message;
    return message;
  }, [handleError]);

  useEffect(() => {
    if (!validId) return;
    let active = true;
    setLoading(true);
    setError(null);
    sprint2Api.getPullRequest(id)
      .then(data => { if (active) setPullRequest(data); })
      .catch(err => {
        if (!active) return;
        if (err instanceof ApiError && err.status === 404) setNotFound(true);
        else setError(describeError(err, "Detail Pull Request tidak dapat dimuat."));
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, validId, reloadKey, describeError]);

  const retry = useCallback(() => setReloadKey(key => key + 1), []);
  const closeToast = useCallback(() => setToast(null), []);

  const sync = async () => {
    if (syncing || !validId) return;
    setSyncing(true);
    setSyncError(null);
    try {
      const data = await sprint2Api.syncPullRequest(id);
      setPullRequest(data);
      setToast({ title: "Pull Request berhasil disinkronkan dengan GitHub." });
    } catch (err) {
      setSyncError(describeError(err, "Pull Request tidak dapat disinkronkan."));
    } finally {
      setSyncing(false);
    }
  };

  const submitReview = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!pullRequest || submitRef.current) return;

    const trimmed = notes.trim();

    if (decision === "REQUEST_CHANGES" && trimmed === "") {
      setNotesError("Catatan perbaikan wajib diisi untuk Request Changes.");
      return;
    }

    submitRef.current = true;
    setSubmitting(true);
    setNotesError(null);
    setReviewError(null);

    if (!idempotencyRef.current) {
      idempotencyRef.current = `uc06-${id}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
    }

    try {
      const result = await sprint2Api.submitPullRequestReview(id, {
        decision,
        notes: trimmed || undefined,
        idempotency_key: idempotencyRef.current,
      });
      setPullRequest(result.pull_request);
      setDecision("APPROVE");
      setNotes("");
      idempotencyRef.current = "";
      setToast({
        title: result.message,
        text: result.idempotent_replay
          ? "Permintaan yang sama tidak dikirim ulang ke GitHub."
          : result.reconciled
            ? "Review direkonsiliasi dari daftar review GitHub."
            : result.sync_pending
              ? "Review tercatat; sinkronisasi status akan diulang saat halaman dimuat."
              : "Review tercatat di GitHub dan status issue telah diperbarui.",
      });
    } catch (err) {
      setReviewError(describeError(err, "Keputusan review tidak dapat dikirim. Coba lagi."));
    } finally {
      submitRef.current = false;
      setSubmitting(false);
    }
  };

  if (notFound) {
    return (
      <div className="ws-content">
        <EmptyPullRequest />
      </div>
    );
  }
  if (loading && !pullRequest) return <div className="ws-content"><SkeletonRows rows={5} /></div>;
  if (error && !pullRequest) return <div className="ws-content"><ErrorState message={error} onRetry={retry} /></div>;
  if (!pullRequest) return null;

  const isReviewer = user.role === "Tim Maintenance";
  const isOpen = pullRequest.lifecycle_state === "open";
  const issueHref = pullRequest.backlog_id
    ? user.role === "Developer"
      ? `/maintenance-issue/${pullRequest.backlog_id}`
      : `/maintenance-backlog/${pullRequest.backlog_id}`
    : null;

  return (
    <div className="ws-content">
      {toast && <Toast title={toast.title} text={toast.text} onClose={closeToast} />}
      <PageHeader
        crumbs={[
          { label: "Kolaborasi" },
          { label: "Pull Request", href: "/pull-requests" },
          { label: `#${pullRequest.github_pr_number}` },
        ]}
        title={pullRequest.title}
        meta={
          <>
            <span>PR #{pullRequest.github_pr_number}</span> ·{" "}
            <PullRequestStatusPill status={pullRequest.lifecycle_state} /> ·{" "}
            <ReviewStatePill state={pullRequest.review_state} /> ·{" "}
            <span><code>{pullRequest.source_branch}</code> → <code>{pullRequest.target_branch}</code></span>
          </>
        }
        actions={
          <>
            <button type="button" className="ws-btn ws-btn--outline" onClick={sync} disabled={syncing}>
              {syncing ? "Menyinkronkan..." : "Sinkronkan GitHub"}
            </button>
            <a className="ws-btn ws-btn--primary" href={pullRequest.github_pr_url} target="_blank" rel="noreferrer">
              Buka di GitHub
            </a>
          </>
        }
      />
      {syncError && <Alert tone="error" title={syncError} onClose={() => setSyncError(null)} />}
      {reviewError && <Alert tone="error" title={reviewError} onClose={() => setReviewError(null)} />}

      <div className="ws-grid">
        <div className="ws-stack">
          <section className="ws-card" aria-label="Deskripsi Pull Request">
            <div className="ws-card__head"><h2 className="ws-card__title">Deskripsi perubahan</h2></div>
            <div className="ws-card__body">
              <p>{pullRequest.description?.trim() || "Tidak ada deskripsi Pull Request."}</p>
            </div>
          </section>

          <section className="ws-card" aria-label="Riwayat review">
            <div className="ws-card__head">
              <div>
                <h2 className="ws-card__title">Review GitHub</h2>
                <p className="ws-card__hint">
                  {pullRequest.review_summary.approved} approved · {pullRequest.review_summary.changes_requested} changes requested
                  {" · "}{pullRequest.review_summary.dismissed} dismissed
                </p>
              </div>
            </div>
            <div className="ws-card__body">
              {pullRequest.reviews.length === 0 && <p>Belum ada review dari GitHub.</p>}
              {pullRequest.reviews.map(review => (
                <article className="ws-review" key={review.code_review_id}>
                  <div className="ws-review__meta">
                    <strong>{review.reviewer.nama ?? review.reviewer_login}</strong>
                    <span>
                      <ReviewDecisionPill decision={review.decision} /> · {formatDateTime(review.submitted_at)}
                    </span>
                  </div>
                  {review.body && <p className="ws-review__text">{review.body}</p>}
                  {review.html_url && (
                    <a href={review.html_url} target="_blank" rel="noreferrer">Lihat review di GitHub</a>
                  )}
                </article>
              ))}
            </div>
          </section>

          {isReviewer ? (
            <section className="ws-card" aria-label="Keputusan review">
              <div className="ws-card__head"><h2 className="ws-card__title">Keputusan review</h2></div>
              <div className="ws-card__body">
                {!isOpen ? (
                  <p>Review hanya dapat diberikan pada Pull Request berstatus Open. Status saat ini: {pullRequest.lifecycle_state}.</p>
                ) : (
                  <form onSubmit={submitReview} noValidate>
                    <fieldset className="ws-field" disabled={submitting}>
                      <legend className="ws-field__label">Keputusan</legend>
                      <div className="ws-actions">
                        <label>
                          <input
                            type="radio"
                            name="decision"
                            value="APPROVE"
                            checked={decision === "APPROVE"}
                            onChange={() => setDecision("APPROVE")}
                          />{" "}
                          Approve
                        </label>
                        <label>
                          <input
                            type="radio"
                            name="decision"
                            value="REQUEST_CHANGES"
                            checked={decision === "REQUEST_CHANGES"}
                            onChange={() => setDecision("REQUEST_CHANGES")}
                          />{" "}
                          Request changes
                        </label>
                      </div>
                    </fieldset>
                    <label className="ws-field">
                      <span className="ws-field__label">
                        Catatan review
                        <span className="ws-field__req">{decision === "REQUEST_CHANGES" ? "Wajib" : "Opsional"}</span>
                      </span>
                      <textarea
                        className="ws-textarea"
                        aria-label="Catatan review"
                        value={notes}
                        onChange={event => { setNotes(event.target.value); setNotesError(null); }}
                        aria-invalid={notesError ? "true" : undefined}
                        placeholder={decision === "REQUEST_CHANGES"
                          ? "Jelaskan perubahan yang diperlukan."
                          : "Catatan tambahan untuk approval (opsional)."}
                      />
                      {notesError && <p className="ws-field__error" role="alert">{notesError}</p>}
                    </label>
                    <div className="ws-form-actions">
                      <button type="submit" className="ws-btn ws-btn--primary" disabled={submitting}>
                        {submitting ? "Mengirim..." : "Kirim keputusan review"}
                      </button>
                    </div>
                  </form>
                )}
              </div>
            </section>
          ) : (
            <section className="ws-card" aria-label="Keputusan review">
              <div className="ws-card__head"><h2 className="ws-card__title">Keputusan review</h2></div>
              <div className="ws-card__body">
                <p>Hanya Tim Maintenance yang dapat memberikan keputusan review. Anda dapat memantau status review di halaman ini.</p>
              </div>
            </section>
          )}
        </div>

        <aside className="ws-stack">
          <section className="ws-card" aria-label="Detail Pull Request">
            <div className="ws-card__head"><h2 className="ws-card__title">Detail Pull Request</h2></div>
            <div className="ws-card__body">
              <dl className="ws-info">
                <InfoRow label="Repository">{pullRequest.repository.full_name || "-"}</InfoRow>
                <InfoRow label="Author">{pullRequest.author.nama ?? pullRequest.author.github_login ?? "-"}</InfoRow>
                <InfoRow label="Issue">
                  {issueHref
                    ? <Link href={issueHref}>{backlogCode(pullRequest.backlog_id)}</Link>
                    : "-"}
                </InfoRow>
                <InfoRow label="Source branch"><code>{pullRequest.source_branch}</code></InfoRow>
                <InfoRow label="Target branch"><code>{pullRequest.target_branch}</code></InfoRow>
                <InfoRow label="Head SHA"><code>{pullRequest.head_sha?.slice(0, 7) ?? "-"}</code></InfoRow>
                <InfoRow label="Draft">{pullRequest.is_draft ? "Ya" : "Tidak"}</InfoRow>
                <InfoRow label="Dibuat">{formatDateTime(pullRequest.github_created_at ?? pullRequest.created_at)}</InfoRow>
                <InfoRow label="Terakhir sinkron">{formatDateTime(pullRequest.last_synced_at)}</InfoRow>
                {pullRequest.merged_at && <InfoRow label="Merged">{formatDateTime(pullRequest.merged_at)}</InfoRow>}
              </dl>
            </div>
          </section>

          <section className="ws-card" aria-label="Status issue terkait">
            <div className="ws-card__head"><h2 className="ws-card__title">Status issue</h2></div>
            <div className="ws-card__body">
              {pullRequest.issue ? (
                <dl className="ws-info">
                  <InfoRow label="Issue">{backlogCode(pullRequest.issue.backlog_id)}</InfoRow>
                  <InfoRow label="Judul">{pullRequest.issue.title ?? "-"}</InfoRow>
                  <InfoRow label="Status"><BacklogStatusPill status={pullRequest.issue.status} /></InfoRow>
                </dl>
              ) : (
                <p>Issue terkait tidak ditemukan.</p>
              )}
            </div>
          </section>

          <section className="ws-card" aria-label="Catatan alur review">
            <div className="ws-card__head"><h2 className="ws-card__title">Catatan alur</h2></div>
            <div className="ws-card__body">
              <ul>
                <li>Approve mengubah status issue menjadi Ready for Merge.</li>
                <li>Request changes wajib disertai catatan dan mengembalikan issue ke Sedang Dikerjakan.</li>
                <li>Push baru ke branch PR mengembalikan issue ke Ready for Pull Request.</li>
                <li>Pull Request yang di-merge menandai issue Selesai; PR closed tanpa merge tidak.</li>
              </ul>
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}

function EmptyPullRequest() {
  return (
    <div className="ws-empty" role="alert">
      <p className="ws-empty__title">Pull Request tidak ditemukan</p>
      <p className="ws-empty__text">Pull Request ini tidak ada atau tidak dapat Anda akses.</p>
      <Link className="ws-btn ws-btn--outline" href="/pull-requests">Kembali ke daftar</Link>
    </div>
  );
}

export default function PullRequestDetailPage() {
  return <PullRequestDetailView />;
}
