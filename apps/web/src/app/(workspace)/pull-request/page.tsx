"use client";
// UC06 (FR-UC06-01): daftar Pull Request untuk review. Role: Developer (D-W14-01-a).
// Filter status diteruskan ke backend (?status=), paginasi mengikuti meta Laravel.
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { RoleGate, useSession } from "@/components/workspace/session";
import { DecisionPill, ReviewStatusPill } from "@/components/workspace/review";
import { EmptyState, ErrorState, PageHeader, Pagination, SkeletonRows } from "@/components/workspace/ui";
import { sprint3Api } from "@/lib/sprint3/client";
import { REVIEW_STATUSES, prCode } from "@/lib/sprint3/labels";
import { backlogCode, formatDateTime } from "@/lib/sprint2/labels";
import type { Paged } from "@/lib/sprint2/types";
import type { PullRequestSummary, ReviewStatus } from "@/lib/sprint3/types";

function PullRequestList() {
  const { user, handleError } = useSession();
  const [status, setStatus] = useState<ReviewStatus | "">("Open");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Paged<PullRequestSummary> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    sprint3Api.listPullRequests({ status, page })
      .then(data => { if (active) setResult(data); })
      .catch(err => { if (active) setError(handleError(err, "Daftar Pull Request tidak dapat dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, reloadKey, handleError]);

  const reload = useCallback(() => setReloadKey(key => key + 1), []);
  const rows = result?.data ?? [];

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Tim Developer" }, { label: "Pull Request" }]}
        title="Pull Request"
        meta={result ? `${result.meta.total} pull request sesuai filter` : "Memuat pull request"}
        actions={<button type="button" className="ws-btn ws-btn--outline" onClick={reload} disabled={loading}>{loading ? "Memuat..." : "Refresh"}</button>}
      />
      <section className="ws-card" aria-label="Daftar Pull Request">
        <div className="ws-toolbar">
          <select
            className="ws-select"
            aria-label="Filter status review"
            value={status}
            onChange={event => { setStatus(event.target.value as ReviewStatus | ""); setPage(1); }}
          >
            {REVIEW_STATUSES.map(item => <option key={item} value={item}>Status: {item}</option>)}
            <option value="">Status: Semua</option>
          </select>
          <p className="ws-toolbar__note">Review hanya dapat diberikan pada PR berstatus Open oleh Developer selain penulis PR.</p>
        </div>

        {loading && !result && <SkeletonRows rows={5} />}
        {error && <ErrorState message={error} onRetry={reload} />}
        {!error && result && rows.length === 0 && (
          <EmptyState
            title="Belum ada Pull Request"
            text={status ? `Tidak ada pull request berstatus ${status}.` : "Pull request muncul setelah Developer mengajukannya dari Maintenance Issue."}
            action={status ? <button type="button" className="ws-btn ws-btn--outline" onClick={() => { setStatus(""); setPage(1); }}>Tampilkan semua status</button> : undefined}
          />
        )}
        {!error && result && rows.length > 0 && (
          <>
            <div className="ws-table-wrap" aria-busy={loading}>
              <table className="ws-table">
                <thead>
                  <tr>
                    <th scope="col">PR</th>
                    <th scope="col">Judul &amp; branch</th>
                    <th scope="col">Issue</th>
                    <th scope="col">Author</th>
                    <th scope="col">Status</th>
                    <th scope="col">Review terakhir</th>
                    <th scope="col">Diajukan</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(pr => (
                    <tr key={pr.pull_request_id}>
                      <td className="ws-table__id">{prCode(pr.pull_request_number, pr.pull_request_id)}</td>
                      <td>
                        <Link className="ws-table__title" href={`/pull-request/${pr.pull_request_id}`}>{pr.title}</Link>
                        <span className="ws-table__sub"><code>{pr.source_branch}</code> → <code>{pr.target_branch}</code></span>
                      </td>
                      <td className="ws-table__id">{backlogCode(pr.backlog_id)}</td>
                      <td>
                        {pr.developer?.nama ?? "-"}
                        {pr.developer?.user_id === user.user_id && <span className="ws-table__sub">PR Anda</span>}
                      </td>
                      <td><ReviewStatusPill pr={pr} /></td>
                      <td>
                        {pr.last_review
                          ? <><DecisionPill decision={pr.last_review.decision} /><span className="ws-table__sub">{pr.last_review.reviewer?.nama ?? "-"} · {pr.review_count} review</span></>
                          : <span className="ws-table__sub">Belum ada review</span>}
                      </td>
                      <td className="ws-table__id">{formatDateTime(pr.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination
              page={result.meta.current_page}
              lastPage={result.meta.last_page}
              total={result.meta.total}
              perPage={result.meta.per_page}
              unit="pull request"
              onPage={setPage}
            />
          </>
        )}
      </section>
    </div>
  );
}

export default function PullRequestListPage() {
  return <RoleGate role="Developer"><PullRequestList /></RoleGate>;
}
