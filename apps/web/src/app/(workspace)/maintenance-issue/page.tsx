"use client";
// UC04 (Feature 6a): daftar Maintenance Issue untuk Developer.
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { RoleGate, useSession } from "@/components/workspace/session";
import {
  BacklogStatusPill, EmptyState, ErrorState, PageHeader, Pagination, PriorityBar, SeverityBadge, SkeletonRows,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { backlogCode } from "@/lib/sprint2/labels";
import type { BacklogStatus, MaintenanceBacklog, Paged } from "@/lib/sprint2/types";

const STATUS_OPTIONS: { value: BacklogStatus | ""; label: string }[] = [
  { value: "Ready for Development", label: "Ready for Development" },
  { value: "Sedang Dikerjakan", label: "Sedang Dikerjakan" },
  { value: "Ready for Pull Request", label: "Ready for Pull Request" },
  { value: "", label: "Semua" },
];

function IssueList() {
  const { handleError } = useSession();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<BacklogStatus | "">("Ready for Development");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Paged<MaintenanceBacklog> | null>(null);
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
    sprint2Api.listIssues({ status, search, page })
      .then(data => { if (active) setResult(data); })
      .catch(err => { if (active) setError(handleError(err, "Daftar Maintenance Issue tidak dapat dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, search, reloadKey, handleError]);

  const retry = useCallback(() => setReloadKey(key => key + 1), []);
  const rows = result?.data ?? [];

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Tim Developer" }, { label: "Maintenance Issue" }]}
        title="Maintenance Issue"
        meta={result ? `${result.meta.total} issue sesuai filter` : "Memuat issue pengerjaan"}
      />
      <section className="ws-card" aria-label="Daftar Maintenance Issue">
        <div className="ws-toolbar">
          <label className="ws-search">
            <input
              type="search"
              aria-label="Cari Maintenance Issue"
              value={searchInput}
              onChange={event => setSearchInput(event.target.value)}
              placeholder="Cari judul, modul, atau ID issue"
            />
          </label>
          <select className="ws-select" aria-label="Status" value={status} onChange={event => { setStatus(event.target.value as BacklogStatus | ""); setPage(1); }}>
            {STATUS_OPTIONS.map(item => <option key={item.label} value={item.value}>Status: {item.label}</option>)}
          </select>
        </div>

        {loading && !result && <SkeletonRows rows={5} />}
        {error && <ErrorState message={error} onRetry={retry} />}
        {!error && result && rows.length === 0 && (
          <EmptyState title="Tidak ada Maintenance Issue" text="Tidak ada issue yang sesuai dengan filter saat ini." />
        )}
        {!error && result && rows.length > 0 && (
          <>
            <div className="ws-table-wrap" aria-busy={loading}>
              <table className="ws-table">
                <thead>
                  <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Judul</th>
                    <th scope="col">Modul</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col">Assignee</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(item => (
                    <tr key={item.backlog_id}>
                      <td className="ws-table__id">{backlogCode(item.backlog_id)}</td>
                      <td><Link className="ws-table__title" href={`/maintenance-issue/${item.backlog_id}`}>{item.judul}</Link></td>
                      <td>{item.modul}</td>
                      <td><SeverityBadge severity={item.severity} /></td>
                      <td><PriorityBar score={item.priority_score} /></td>
                      <td><BacklogStatusPill status={item.status} /></td>
                      <td>{item.assigned_to?.nama ?? "-"}</td>
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
              unit="issue"
              onPage={setPage}
            />
          </>
        )}
      </section>
    </div>
  );
}

export default function MaintenanceIssuePage() {
  return <RoleGate role="Developer"><IssueList /></RoleGate>;
}
