"use client";
// UC06 (FR-UC06-02..15): detail Pull Request + keputusan review.
// Status selalu dibaca ulang dari backend setelah review; klien tidak menebak transisi.
// Sinkron GitHub gagal = peringatan + "Kirim ulang", bukan sukses (D-W14-01-e).
import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { RoleGate, useSession } from "@/components/workspace/session";
import { DecisionPill, ReviewStatusPill, SyncPill } from "@/components/workspace/review";
import { Alert, EmptyState, ErrorState, InfoRow, PageHeader, SeverityBadge, SkeletonRows } from "@/components/workspace/ui";
import { fetchChangedFiles, sprint3Api } from "@/lib/sprint3/client";
import { COMMENT_MAX, REVIEW_MESSAGES, prCode, shortSha, syncErrorText } from "@/lib/sprint3/labels";
import { backlogCode, caseCode, formatDateTime } from "@/lib/sprint2/labels";
import type { ChangedFile, PullRequestDetail, ReviewInput, ReviewResult } from "@/lib/sprint3/types";

type Notice = { tone: "success" | "warning" | "error" | "info"; title: string; text?: string };

function ChangedFiles({ pr }: { pr: PullRequestDetail }) {
  const [files, setFiles] = useState<ChangedFile[] | null | undefined>(undefined);
  const repo = pr.repository?.full_name ?? "";
  const number = pr.pull_request_number ?? 0;

  useEffect(() => {
    let active = true;
    setFiles(undefined);
    fetchChangedFiles(repo, number).then(rows => { if (active) setFiles(rows); });
    return () => { active = false; };
  }, [repo, number]);

  const filesUrl = pr.pull_request_url ? `${pr.pull_request_url}/files` : null;
  const totals = files?.reduce((sum, file) => ({ add: sum.add + file.additions, del: sum.del + file.deletions }), { add: 0, del: 0 });

  return (
    <section className="ws-card" aria-label="Perubahan kode">
      <div className="ws-card__head">
        <h2 className="ws-card__title">Perubahan kode</h2>
        {filesUrl && <a className="ws-card__link" href={filesUrl} target="_blank" rel="noreferrer">Files changed di GitHub</a>}
      </div>
      <div className="ws-card__body">
        <dl className="ws-info">
          <InfoRow label="Branch"><code>{pr.source_branch}</code> → <code>{pr.target_branch}</code></InfoRow>
          <InfoRow label="Head commit"><code title={pr.head_sha ?? undefined}>{shortSha(pr.head_sha)}</code></InfoRow>
          <InfoRow label="Base commit"><code title={pr.base_sha ?? undefined}>{shortSha(pr.base_sha)}</code></InfoRow>
        </dl>
        {files === undefined && <p className="ws-muted" aria-busy="true">Memuat daftar berkas dari GitHub...</p>}
        {files === null && (
          <p className="ws-muted">
            Daftar berkas tidak dapat dibaca dari GitHub (repository privat, PR tidak ditemukan, atau batas permintaan tercapai).
            {filesUrl && <> Buka <a href={filesUrl} target="_blank" rel="noreferrer">Files changed</a> untuk melihat diff.</>}
          </p>
        )}
        {files && files.length === 0 && <p className="ws-muted">Tidak ada berkas berubah.</p>}
        {files && files.length > 0 && (
          <>
            <p className="ws-muted">{files.length} berkas · <span className="ws-diff__add">+{totals?.add}</span> <span className="ws-diff__del">−{totals?.del}</span></p>
            <ul className="ws-files">
              {files.map(file => (
                <li key={file.filename}>
                  <details>
                    <summary>
                      <code className="ws-files__name">{file.filename}</code>
                      <span className="ws-files__stat"><span className="ws-diff__add">+{file.additions}</span> <span className="ws-diff__del">−{file.deletions}</span></span>
                    </summary>
                    {file.patch
                      ? <pre className="ws-patch">{file.patch.split("\n").map((line, index) => (
                        <span key={index} className={line.startsWith("+") ? "ws-patch__add" : line.startsWith("-") ? "ws-patch__del" : line.startsWith("@@") ? "ws-patch__hunk" : undefined}>{line}{"\n"}</span>
                      ))}</pre>
                      : <p className="ws-muted">Diff tidak tersedia (berkas biner atau terlalu besar).</p>}
                  </details>
                </li>
              ))}
            </ul>
          </>
        )}
      </div>
    </section>
  );
}

function PullRequestReview() {
  const { user, handleError } = useSession();
  const params = useParams<{ id: string }>();
  const id = Number(params?.id);
  const validId = Number.isInteger(id) && id > 0;

  const [pr, setPr] = useState<PullRequestDetail | null>(null);
  const [loading, setLoading] = useState(validId);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(!validId);
  const [reloadKey, setReloadKey] = useState(0);

  const [mode, setMode] = useState<"idle" | "request_changes">("idle");
  const [comment, setComment] = useState("");
  const [commentError, setCommentError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submitRef = useRef(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [failedInput, setFailedInput] = useState<ReviewInput | null>(null);
  const commentRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    if (!validId) return;
    let active = true;
    setLoading(true);
    setLoadError(null);
    sprint3Api.getPullRequest(id)
      .then(data => { if (active) setPr(data); })
      .catch(err => {
        if (!active) return;
        if (err instanceof ApiError && err.status === 404) setNotFound(true);
        else setLoadError(handleError(err, "Detail Pull Request tidak dapat dimuat."));
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, validId, reloadKey, handleError]);

  const reload = useCallback(() => setReloadKey(key => key + 1), []);

  useEffect(() => {
    if (mode === "request_changes") commentRef.current?.focus();
  }, [mode]);

  // Hasil review tampil di atas halaman; arahkan pandangan ke sana agar tidak terlewat.
  const noticeRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (notice) noticeRef.current?.scrollIntoView({ behavior: "smooth", block: "center" });
  }, [notice]);

  const send = async (input: ReviewInput) => {
    if (submitRef.current) return;
    submitRef.current = true;
    setSubmitting(true);
    setNotice(null);
    try {
      const result: ReviewResult = await sprint3Api.submitReview(id, input);
      if (result.sync.status === "synced") {
        setFailedInput(null);
        setMode("idle");
        setComment("");
        setNotice(input.decision === "approve"
          ? { tone: "success", title: result.message || REVIEW_MESSAGES.approved, text: "Status: Ready for Merge. Merge tetap dilakukan di GitHub (MaintainGov tidak melakukan merge)." }
          : { tone: "warning", title: result.message || REVIEW_MESSAGES.changesRequested, text: `Backlog kembali ke status ${result.backlog?.status ?? "Sedang Dikerjakan"}. Developer perlu memperbaiki dan mengajukan PR baru.` });
      } else {
        setFailedInput(input);
        setNotice({
          tone: "warning",
          title: result.message || REVIEW_MESSAGES.syncFailed,
          text: `${syncErrorText(result.sync.code)} Keputusan belum berlaku; status PR tetap Open.`.trim(),
        });
      }
      setPr(await sprint3Api.getPullRequest(id));
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        if (input.decision === "request_changes") setCommentError(err.message);
        setNotice({ tone: "error", title: "Review belum valid", text: err.message });
      } else if (err instanceof ApiError && err.status === 409) {
        setFailedInput(null);
        setNotice({ tone: "error", title: "Pull Request tidak lagi berstatus Open", text: err.message });
        reload();
      } else if (err instanceof ApiError && err.status === 403) {
        setNotice({ tone: "error", title: "Anda tidak dapat mereview Pull Request ini", text: err.message });
      } else {
        setNotice({ tone: "error", title: "Review gagal dikirim", text: handleError(err, "Review tidak dapat dikirim. Coba lagi.") });
      }
    } finally {
      submitRef.current = false;
      setSubmitting(false);
    }
  };

  const approve = () => send({ decision: "approve" });

  const submitChanges = (event: FormEvent) => {
    event.preventDefault();
    const text = comment.trim();
    if (!text) { setCommentError(REVIEW_MESSAGES.commentRequired); commentRef.current?.focus(); return; }
    if (text.length > COMMENT_MAX) { setCommentError(`Komentar maksimal ${COMMENT_MAX} karakter.`); return; }
    setCommentError(null);
    send({ decision: "request_changes", comment: text });
  };

  if (notFound) {
    return (
      <div className="ws-content">
        <EmptyState title="Pull Request tidak ditemukan" action={<Link className="ws-btn ws-btn--outline" href="/pull-request">Kembali ke daftar</Link>} />
      </div>
    );
  }
  if (loading && !pr) return <div className="ws-content"><SkeletonRows rows={6} /></div>;
  if (loadError && !pr) return <div className="ws-content"><ErrorState message={loadError} onRetry={reload} /></div>;
  if (!pr) return null;

  const code = prCode(pr.pull_request_number, pr.pull_request_id);
  const isAuthor = pr.developer?.user_id === user.user_id;
  const isOpen = pr.review_state === "pending" && pr.review_status === "Open";
  const canReview = user.role === "Developer" && !isAuthor && isOpen;
  const myReview = pr.reviews.find(review => review.reviewer?.user_id === user.user_id) ?? null;
  const blockedReason = isAuthor
    ? "Anda adalah penulis Pull Request ini. Review harus diberikan Developer lain."
    : !isOpen
      ? `Pull Request berstatus ${pr.ready_for_merge ? "Ready for Merge" : pr.review_status}; review baru tidak diterima.`
      : null;

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Tim Developer" }, { label: "Pull Request", href: "/pull-request" }, { label: code }]}
        title={pr.title}
        meta={<><span>{code}</span> · <ReviewStatusPill pr={pr} /> · <span>oleh {pr.developer?.nama ?? "-"}</span> · <span>{formatDateTime(pr.created_at)}</span></>}
        actions={
          <>
            <button type="button" className="ws-btn ws-btn--outline" onClick={reload} disabled={loading || submitting}>{loading ? "Memuat..." : "Refresh"}</button>
            {pr.pull_request_url && <a className="ws-btn ws-btn--outline" href={pr.pull_request_url} target="_blank" rel="noreferrer">Buka di GitHub</a>}
          </>
        }
      />
      {notice && (
        <div ref={noticeRef}>
        <Alert tone={notice.tone} title={notice.title} onClose={() => setNotice(null)}>
          {notice.text}
          {failedInput && (
            <div className="ws-alert__actions">
              <button type="button" className="ws-btn ws-btn--primary ws-btn--sm" disabled={submitting} onClick={() => send(failedInput)}>
                {submitting ? "Mengirim..." : "Kirim ulang"}
              </button>
            </div>
          )}
        </Alert>
        </div>
      )}

      <div className="ws-grid">
        <div className="ws-stack">
          <section className="ws-card" aria-label="Informasi Pull Request">
            <div className="ws-card__head"><h2 className="ws-card__title">Informasi Pull Request</h2></div>
            <div className="ws-card__body">
              <dl className="ws-info">
                <InfoRow label="Author">{pr.developer?.nama ?? "-"}{pr.developer?.github_login ? ` (@${pr.developer.github_login})` : ""}</InfoRow>
                <InfoRow label="Repository">{pr.repository?.full_name ?? "-"}</InfoRow>
                <InfoRow label="Branch"><code>{pr.source_branch}</code> → <code>{pr.target_branch}</code></InfoRow>
                <InfoRow label="Status"><ReviewStatusPill pr={pr} /></InfoRow>
                {pr.description && <InfoRow label="Deskripsi"><span className="ws-prewrap">{pr.description}</span></InfoRow>}
              </dl>
            </div>
          </section>

          <section className="ws-card" aria-label="Issue terkait">
            <div className="ws-card__head">
              <h2 className="ws-card__title">Issue terkait</h2>
              {pr.issue?.case && <Link className="ws-card__link" href={`/riwayat/${pr.issue.case.case_id}`}>Lihat riwayat</Link>}
            </div>
            <div className="ws-card__body">
              {pr.issue ? (
                <dl className="ws-info">
                  <InfoRow label="Issue"><Link href={`/maintenance-issue/${pr.issue.backlog_id}`}>{backlogCode(pr.issue.backlog_id)}</Link> · {pr.issue.title}</InfoRow>
                  <InfoRow label="Status issue">{pr.issue.status}</InfoRow>
                  <InfoRow label="Assignee">{pr.issue.assigned_to?.nama ?? "-"}</InfoRow>
                  {pr.issue.case && (
                    <>
                      <InfoRow label="Case">{caseCode(pr.issue.case.case_id)} · {pr.issue.case.judul_issue}</InfoRow>
                      <InfoRow label="Kategori">{pr.issue.case.kategori}</InfoRow>
                      <InfoRow label="Severity"><SeverityBadge severity={pr.issue.case.severity} /></InfoRow>
                      <InfoRow label="Ringkasan">{pr.issue.case.ringkasan_masalah}</InfoRow>
                    </>
                  )}
                </dl>
              ) : <p className="ws-muted">Data issue tidak tersedia.</p>}
            </div>
          </section>

          <ChangedFiles pr={pr} />
        </div>

        <aside className="ws-stack">
          <section className="ws-card" aria-label="Keputusan review">
            <div className="ws-card__head"><h2 className="ws-card__title">Keputusan review</h2></div>
            <div className="ws-card__body">
              {canReview ? (
                mode === "idle" ? (
                  <>
                    <p>Periksa perubahan kode, lalu pilih keputusan. Keputusan dikirim ke GitHub sebelum status berubah.</p>
                    <div className="ws-review-actions">
                      <button type="button" className="ws-btn ws-btn--primary" disabled={submitting} onClick={approve}>
                        {submitting ? "Mengirim..." : "Approve"}
                      </button>
                      <button type="button" className="ws-btn ws-btn--outline" disabled={submitting} onClick={() => { setMode("request_changes"); setNotice(null); }}>
                        Request Changes
                      </button>
                    </div>
                  </>
                ) : (
                  <form className="ws-stack" onSubmit={submitChanges} noValidate>
                    <div className="ws-field">
                      <label className="ws-field__label" htmlFor="review-comment">Komentar perbaikan <span className="ws-field__req">wajib</span></label>
                      <textarea
                        id="review-comment"
                        ref={commentRef}
                        className="ws-textarea"
                        rows={6}
                        maxLength={COMMENT_MAX}
                        value={comment}
                        aria-invalid={!!commentError}
                        aria-describedby="review-comment-help"
                        onChange={event => { setComment(event.target.value); if (commentError) setCommentError(null); }}
                        placeholder="Jelaskan bagian yang perlu diperbaiki Developer."
                      />
                      <p id="review-comment-help" className="ws-field__help">{comment.length}/{COMMENT_MAX} karakter</p>
                      {commentError && <p className="ws-field__error" role="alert">{commentError}</p>}
                    </div>
                    <div className="ws-review-actions">
                      <button type="button" className="ws-btn ws-btn--outline" disabled={submitting} onClick={() => { setMode("idle"); setCommentError(null); }}>Batal</button>
                      <button type="submit" className="ws-btn ws-btn--primary" disabled={submitting}>{submitting ? "Mengirim..." : "Kirim Request Changes"}</button>
                    </div>
                  </form>
                )
              ) : (
                <p className="ws-muted" data-testid="review-blocked">{blockedReason ?? "Review tidak tersedia untuk akun ini."}</p>
              )}
              {myReview && myReview.sync_status !== "synced" && !failedInput && (
                <p className="ws-field__help">Review Anda sebelumnya ({myReview.decision}) belum tersinkron. Kirim keputusan lagi untuk mengulang sinkronisasi.</p>
              )}
            </div>
          </section>

          <section className="ws-card" aria-label="Riwayat review">
            <div className="ws-card__head"><h2 className="ws-card__title">Riwayat review ({pr.reviews.length})</h2></div>
            <div className="ws-card__body">
              {pr.reviews.length === 0 && <p className="ws-muted">Belum ada review.</p>}
              {pr.reviews.map(review => (
                <article key={review.review_id} className="ws-review">
                  <div className="ws-review__meta">
                    <strong>{review.reviewer?.nama ?? "-"}</strong>
                    <span>{formatDateTime(review.reviewed_at)}</span>
                  </div>
                  <div className="ws-review__pills"><DecisionPill decision={review.decision} /> <SyncPill status={review.sync_status} /></div>
                  {review.comment && <p className="ws-review__text ws-prewrap">{review.comment}</p>}
                  {review.sync_status === "gagal" && <p className="ws-field__error">{syncErrorText(review.last_sync_error_code) || "Sinkronisasi gagal."}</p>}
                  {review.github_review_url && <a href={review.github_review_url} target="_blank" rel="noreferrer">Lihat review di GitHub</a>}
                </article>
              ))}
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}

export default function PullRequestDetailPage() {
  return <RoleGate role="Developer"><PullRequestReview /></RoleGate>;
}
