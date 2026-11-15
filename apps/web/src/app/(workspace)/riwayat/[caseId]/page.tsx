"use client";
// Riwayat Maintenance (BR-23): Feedback → Case → Backlog → Issue → PR → Review.
// Timeline dari GET /maintenance-cases/{case}/history; ulasan asal (Feedback) dari UC02 detail
// case, yang hanya boleh dibaca Tim Maintenance.
import { useCallback, useEffect, useState } from "react";
import type { ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { useSession } from "@/components/workspace/session";
import { DecisionPill, ReviewStatusPill, SyncPill } from "@/components/workspace/review";
import { CaseStatusPill, EmptyState, ErrorState, PageHeader, SeverityBadge, SkeletonRows } from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { backlogCode, caseCode, formatDateTime } from "@/lib/sprint2/labels";
import type { CaseReview, CaseStatus } from "@/lib/sprint2/types";
import { sprint3Api } from "@/lib/sprint3/client";
import { FLOW_STAGES, TIMELINE_LABEL, prCode, shortSha } from "@/lib/sprint3/labels";
import type { CaseHistory, TimelineEvent, TimelineType } from "@/lib/sprint3/types";

const text = (value: unknown) => (value === null || value === undefined || value === "" ? null : String(value));

function EventDetail({ event, prNumbers }: { event: TimelineEvent; prNumbers: Map<number, number | null> }) {
  const d = event.detail ?? {};
  const prId = Number(d.pull_request_id ?? 0);
  const prLabel = prId ? prCode(prNumbers.get(prId) ?? (d.pull_request_number as number | null) ?? null, prId) : null;
  let body: ReactNode = null;
  switch (event.type) {
    case "case_created":
      body = <>Kandidat case terbentuk dari analisis feedback{text(d.kategori) && <> · kategori {text(d.kategori)}</>}.</>;
      break;
    case "case_validated":
      body = <>Keputusan validasi: <strong>{text(d.keputusan) ?? "-"}</strong>.</>;
      break;
    case "backlog_created":
      body = d.backlog_id ? <>Backlog <strong>{backlogCode(Number(d.backlog_id))}</strong> dibentuk dari case Valid.</> : null;
      break;
    case "issue_claimed":
      body = <>Branch kerja <code>{text(d.source_branch) ?? "-"}</code>.</>;
      break;
    case "push_detected":
      body = <>Commit <code title={text(d.commit) ?? undefined}>{shortSha(text(d.commit))}</code> pada <code>{text(d.branch) ?? "-"}</code> terverifikasi dari GitHub.</>;
      break;
    case "pull_request_submitted":
      body = (
        <>
          {prId ? <Link href={`/pull-request/${prId}`}>{prLabel}</Link> : prLabel} {text(d.title)}
          {" · "}<code>{text(d.source_branch)}</code> → <code>{text(d.target_branch)}</code>
          {text(d.pull_request_url) && <> · <a href={String(d.pull_request_url)} target="_blank" rel="noreferrer">GitHub</a></>}
        </>
      );
      break;
    case "review_submitted":
      body = (
        <span className="ws-timeline__pills">
          {prLabel && <span>{prLabel}</span>}
          {text(d.decision) && <DecisionPill decision={String(d.decision)} />}
          {text(d.sync_status) && <SyncPill status={String(d.sync_status)} />}
        </span>
      );
      break;
    default:
      body = null;
  }
  return body ? <div className="ws-timeline__detail">{body}</div> : null;
}

function CaseHistoryView() {
  const { user, handleError } = useSession();
  const params = useParams<{ caseId: string }>();
  const caseId = Number(params?.caseId);
  const validId = Number.isInteger(caseId) && caseId > 0;
  const isMaintenance = user.role === "Tim Maintenance";

  const [history, setHistory] = useState<CaseHistory | null>(null);
  const [feedback, setFeedback] = useState<CaseReview[] | null>(null);
  const [loading, setLoading] = useState(validId);
  const [error, setError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(!validId);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    if (!validId) return;
    let active = true;
    setLoading(true);
    setError(null);
    sprint3Api.getCaseHistory(caseId)
      .then(data => { if (active) setHistory(data); })
      .catch(err => {
        if (!active) return;
        if (err instanceof ApiError && err.status === 404) setNotFound(true);
        else setError(handleError(err, "Riwayat case tidak dapat dimuat."));
      })
      .finally(() => { if (active) setLoading(false); });
    if (isMaintenance) {
      sprint2Api.getCase(caseId)
        .then(data => { if (active) setFeedback(data.reviews ?? []); })
        .catch(() => { if (active) setFeedback(null); });
    }
    return () => { active = false; };
  }, [caseId, validId, isMaintenance, reloadKey, handleError]);

  const reload = useCallback(() => setReloadKey(key => key + 1), []);

  if (notFound) {
    return (
      <div className="ws-content">
        <EmptyState title="Maintenance Case tidak ditemukan" action={<Link className="ws-btn ws-btn--outline" href="/riwayat">Kembali ke status</Link>} />
      </div>
    );
  }
  if (loading && !history) return <div className="ws-content"><SkeletonRows rows={6} /></div>;
  if (error && !history) return <div className="ws-content"><ErrorState message={error} onRetry={reload} /></div>;
  if (!history) return null;

  const types = new Set(history.timeline.map(event => event.type));
  const doneStages = FLOW_STAGES.map(stage => stage.events.some(type => types.has(type)));
  const currentIndex = doneStages.lastIndexOf(true);
  const prNumbers = new Map(history.pull_requests.map(pr => [pr.pull_request_id, pr.pull_request_number]));
  const rejected = history.case.status_validasi === "Tidak Valid";

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Status & Riwayat", href: "/riwayat" }, { label: caseCode(history.case.case_id) }]}
        title={history.case.judul_issue}
        meta={<><span>{caseCode(history.case.case_id)}</span> · <CaseStatusPill status={history.case.status_validasi as CaseStatus} /> · <SeverityBadge severity={history.case.severity} /> · <span>{history.case.kategori}</span></>}
        actions={
          <>
            <button type="button" className="ws-btn ws-btn--outline" onClick={reload} disabled={loading}>{loading ? "Memuat..." : "Refresh"}</button>
            {isMaintenance && <Link className="ws-btn ws-btn--outline" href={`/maintenance-case/${history.case.case_id}`}>Detail case</Link>}
          </>
        }
      />

      <section className="ws-card" aria-label="Alur maintenance">
        <ol className="ws-flow">
          {FLOW_STAGES.map((stage, index) => {
            const done = doneStages[index];
            const state = done ? (index === currentIndex ? "current" : "done") : rejected && index > 1 ? "stopped" : "todo";
            return (
              <li key={stage.key} className={`ws-flow__step ws-flow__step--${state}`} aria-current={state === "current" ? "step" : undefined}>
                <span className="ws-flow__dot" aria-hidden="true">{done ? "✓" : index + 1}</span>
                <span className="ws-flow__label">{stage.label}</span>
                <span className="ws-sr-only">{done ? "selesai" : state === "stopped" ? "tidak dilanjutkan" : "belum"}</span>
              </li>
            );
          })}
        </ol>
        {rejected && <p className="ws-flow__note">Case dinyatakan Tidak Valid sehingga tidak diteruskan menjadi backlog.</p>}
      </section>

      <div className="ws-grid">
        <section className="ws-card" aria-label="Timeline">
          <div className="ws-card__head"><h2 className="ws-card__title">Timeline</h2></div>
          <div className="ws-card__body">
            {history.timeline.length === 0
              ? <p className="ws-muted">Belum ada aktivitas tercatat.</p>
              : (
                <ol className="ws-timeline">
                  {history.timeline.map((event, index) => (
                    <li key={`${event.type}-${index}`} className={`ws-timeline__item ws-timeline__item--${event.type}`}>
                      <div className="ws-timeline__head">
                        <strong>{TIMELINE_LABEL[event.type as TimelineType] ?? event.type}</strong>
                        <time dateTime={event.occurred_at ?? undefined}>{formatDateTime(event.occurred_at)}</time>
                      </div>
                      <p className="ws-timeline__actor">{event.actor ? `${event.actor.nama} · ${event.actor.role}` : "Sistem (analisis otomatis)"}</p>
                      <EventDetail event={event} prNumbers={prNumbers} />
                    </li>
                  ))}
                </ol>
              )}
          </div>
        </section>

        <aside className="ws-stack">
          <section className="ws-card" aria-label="Pull Request">
            <div className="ws-card__head"><h2 className="ws-card__title">Pull Request ({history.pull_requests.length})</h2></div>
            <div className="ws-card__body">
              {history.pull_requests.length === 0 && <p className="ws-muted">Belum ada pull request untuk case ini.</p>}
              {history.pull_requests.map(pr => (
                <div key={pr.pull_request_id} className="ws-review">
                  <div className="ws-review__meta">
                    {user.role === "Developer"
                      ? <Link href={`/pull-request/${pr.pull_request_id}`}><strong>{prCode(pr.pull_request_number, pr.pull_request_id)}</strong></Link>
                      : <strong>{prCode(pr.pull_request_number, pr.pull_request_id)}</strong>}
                    <ReviewStatusPill pr={{ review_status: pr.review_status, ready_for_merge: pr.ready_for_merge, status_label: pr.review_status }} />
                  </div>
                  <p className="ws-review__text">{pr.title}</p>
                </div>
              ))}
              <p className="ws-field__help">Backlog berstatus Selesai hanya setelah GitHub melaporkan PR Merged; Approve saja belum menyelesaikan backlog.</p>
            </div>
          </section>

          <section className="ws-card" aria-label="Feedback asal">
            <div className="ws-card__head"><h2 className="ws-card__title">Feedback asal</h2></div>
            <div className="ws-card__body">
              {!isMaintenance && <p className="ws-muted">Ulasan pengguna asal hanya dapat dibuka Tim Maintenance.</p>}
              {isMaintenance && feedback === null && <p className="ws-muted">Ulasan asal tidak dapat dimuat.</p>}
              {isMaintenance && feedback && feedback.length === 0 && <p className="ws-muted">Tidak ada ulasan terhubung.</p>}
              {isMaintenance && feedback && feedback.slice(0, 5).map(review => (
                <div key={review.review_id} className="ws-review">
                  <div className="ws-review__meta"><span>{review.sumber ?? "-"} · rating {review.rating}</span><span>{formatDateTime(review.tanggal_ulasan)}</span></div>
                  <p className="ws-review__text">{review.teks_ulasan}</p>
                </div>
              ))}
              {isMaintenance && feedback && feedback.length > 5 && <p className="ws-field__help">+{feedback.length - 5} ulasan lain pada detail case.</p>}
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}

export default function CaseHistoryPage() {
  return <CaseHistoryView />;
}
