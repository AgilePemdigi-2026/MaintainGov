"use client";
// UC06: daftar Pull Request. Tim Maintenance melihat semua PR; Developer hanya
// melihat PR miliknya. Filter status dan pencarian diteruskan ke backend.
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { useSession } from "@/components/workspace/session";
import {
  BacklogStatusPill, EmptyState, ErrorState, PageHeader, Pagination, PullRequestStatusPill, ReviewStatePill, SkeletonRows,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { backlogCode, formatDateTime } from "@/lib/sprint2/labels";
import type { Paged, PullRequestLifecycle, PullRequestSummary } from "@/lib/sprint2/types";

const STATUS_OPTIONS: { value: PullRequestLifecycle | "all"; label: string }[] = [
  { value: "open", label: "Open" },
  { value: "merged", label: "Merged" },
  { value: "closed", label: "Closed" },
  { value: "all", label: "Semua" },
];

function PullRequestList() {
  const { user, handleError } = useSession();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<PullRequestLifecycle | "all">("open");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Paged<PullRequestSummary> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const timer = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    sprint2Api.listPullRequests({ status, search, page })
      .then(data => { if (active) setResult(data); })
      .catch(err => { if (active) setError(handleError(err, "Daftar Pull Request tidak dapat dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, search, reloadKey, handleError]);

  const retry = useCallback(() => setReloadKey(key => key + 1), []);
  const rows = result?.data ?? [];
  const issueHref = (issueId: number) =>
    user.role === "Developer" ? `/maintenance-issue/${issueId}` : `/maintenance-backlog/${issueId}`;

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Kolaborasi" }, { label: "Pull Request" }]}
        title="Pull Request"
        meta={result ? `${result.meta.total} pull request sesuai filter` : "Memuat Pull Request"}
      />
      <section className="ws-card" aria-label="Daftar Pull Request">
        <div className="ws-toolbar">
          <label className="ws-search">
            <input
              type="search"
              aria-label="Cari Pull Request"
              value={searchInput}
              onChange={event => setSearchInput(event.target.value)}
              placeholder="Cari judul, nomor PR, atau branch"
            />
          </label>
          <select
            className="ws-select"
            aria-label="Status Pull Request"
            value={status}
            onChange={event => { setStatus(event.target.value as PullRequestLifecycle | "all"); setPage(1); }}
          >
            {STATUS_OPTIONS.map(item => <option key={item.value} value={item.value}>Status: {item.label}</option>)}
          </select>
        </div>

        {loading && !result && <SkeletonRows rows={5} />}
        {error && <ErrorState message={error} onRetry={retry} />}
        {!error && result && rows.length === 0 && (
          <EmptyState title="Tidak ada Pull Request" text="Tidak ada Pull Request yang sesuai dengan filter saat ini." />
        )}
        {!error && result && rows.length > 0 && (
          <>
            <div className="ws-table-wrap" aria-busy={loading}>
              <table className="ws-table">
                <thead>
                  <tr>
                    <th scope="col">PR</th>
                    <th scope="col">Judul</th>
                    <th scope="col">Issue</th>
                    <th scope="col">Author</th>
                    <th scope="col">Branch</th>
                    <th scope="col">Review</th>
                    <th scope="col">Status</th>
                    <th scope="col">Diperbarui</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(item => (
                    <tr key={item.pull_request_id}>
                      <td className="ws-table__id">
                        <Link href={`/pull-requests/${item.pull_request_id}`}>#{item.github_pr_number}</Link>
                      </td>
                      <td>
                        <Link className="ws-table__title" href={`/pull-requests/${item.pull_request_id}`}>{item.title}</Link>
                      </td>
                      <td>
                        <Link href={issueHref(item.backlog_id)}>{backlogCode(item.backlog_id)}</Link>
                        {item.issue && <> <BacklogStatusPill status={item.issue.status} /></>}
                      </td>
                      <td>{item.author.nama ?? item.author.github_login ?? "-"}</td>
                      <td><code>{item.source_branch}</code> → <code>{item.target_branch}</code></td>
                      <td>
                        <ReviewStatePill state={item.review_state} />
                        <span className="ws-card__hint"> · {item.reviews_count} review</span>
                      </td>
                      <td><PullRequestStatusPill status={item.lifecycle_state} /></td>
                      <td>{formatDateTime(item.github_updated_at ?? item.created_at)}</td>
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

export default function PullRequestsPage() {
  return <PullRequestList />;
}
