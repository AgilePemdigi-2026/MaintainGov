"use client";
// Status Maintenance: ringkasan status setiap backlog + akses ke riwayat case (BR-23).
// Kedua role membaca; datanya dari UC03 GET /maintenance-backlog (read-only).
import { useCallback, useEffect, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useSession } from "@/components/workspace/session";
import { BacklogStatusPill, EmptyState, ErrorState, PageHeader, Pagination, SeverityBadge, SkeletonRows } from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { BACKLOG_STATUSES, backlogCode, caseCode, formatDateTime } from "@/lib/sprint2/labels";
import type { BacklogStatus, MaintenanceBacklog, Paged } from "@/lib/sprint2/types";

export default function StatusRiwayatPage() {
  const { handleError } = useSession();
  const router = useRouter();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<BacklogStatus | "">("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Paged<MaintenanceBacklog> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [caseInput, setCaseInput] = useState("");
  const [caseError, setCaseError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    sprint2Api.listBacklogs({ status, search, page })
      .then(data => { if (active) setResult(data); })
      .catch(err => { if (active) setError(handleError(err, "Status maintenance tidak dapat dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, search, reloadKey, handleError]);

  const reload = useCallback(() => setReloadKey(key => key + 1), []);

  const openCase = (event: FormEvent) => {
    event.preventDefault();
    const digits = caseInput.trim().replace(/^case-0*/i, "");
    const caseId = Number(digits);
    if (!Number.isInteger(caseId) || caseId < 1) { setCaseError("Masukkan ID case, misalnya 12 atau CASE-0012."); return; }
    router.push(`/riwayat/${caseId}`);
  };

  const rows = result?.data ?? [];

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Status & Riwayat" }]}
        title="Status & Riwayat Maintenance"
        meta="Telusuri alur Feedback → Case → Backlog → Issue → Pull Request → Review."
        actions={<button type="button" className="ws-btn ws-btn--outline" onClick={reload} disabled={loading}>{loading ? "Memuat..." : "Refresh"}</button>}
      />

      <section className="ws-card" aria-label="Buka riwayat case">
        <form className="ws-toolbar" onSubmit={openCase} noValidate>
          <label className="ws-search">
            <input
              aria-label="ID Maintenance Case"
              value={caseInput}
              onChange={event => { setCaseInput(event.target.value); setCaseError(null); }}
              placeholder="Buka riwayat langsung dengan ID case (contoh: CASE-0012)"
            />
          </label>
          <button type="submit" className="ws-btn ws-btn--primary">Buka riwayat</button>
          {caseError && <p className="ws-field__error ws-toolbar__full" role="alert">{caseError}</p>}
        </form>
      </section>

      <section className="ws-card" aria-label="Status maintenance per backlog">
        <div className="ws-toolbar">
          <label className="ws-search">
            <input type="search" aria-label="Cari backlog" value={searchInput} onChange={event => setSearchInput(event.target.value)} placeholder="Cari judul, modul, atau ID" />
          </label>
          <select className="ws-select" aria-label="Status backlog" value={status} onChange={event => { setStatus(event.target.value as BacklogStatus | ""); setPage(1); }}>
            <option value="">Status: Semua</option>
            {BACKLOG_STATUSES.map(item => <option key={item} value={item}>Status: {item}</option>)}
          </select>
        </div>
        {loading && !result && <SkeletonRows rows={5} />}
        {error && <ErrorState message={error} onRetry={reload} />}
        {!error && result && rows.length === 0 && (
          <EmptyState title="Belum ada backlog" text="Backlog terbentuk setelah Tim Maintenance memvalidasi case sebagai Valid. Riwayat case Pending tetap bisa dibuka lewat ID case." />
        )}
        {!error && result && rows.length > 0 && (
          <>
            <div className="ws-table-wrap" aria-busy={loading}>
              <table className="ws-table">
                <thead>
                  <tr>
                    <th scope="col">Backlog</th>
                    <th scope="col">Judul</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Status</th>
                    <th scope="col">Assignee</th>
                    <th scope="col">Diperbarui</th>
                    <th scope="col"><span className="ws-sr-only">Aksi</span></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(item => (
                    <tr key={item.backlog_id}>
                      <td className="ws-table__id">{backlogCode(item.backlog_id)}<span className="ws-table__sub">{caseCode(item.case_id)}</span></td>
                      <td><span className="ws-table__title">{item.judul}</span><span className="ws-table__sub">{item.modul}</span></td>
                      <td><SeverityBadge severity={item.severity} /></td>
                      <td><BacklogStatusPill status={item.status} /></td>
                      <td>{item.assigned_to?.nama ?? "-"}</td>
                      <td className="ws-table__id">{formatDateTime(item.updated_at)}</td>
                      <td><Link className="ws-btn ws-btn--outline ws-btn--sm" href={`/riwayat/${item.case_id}`}>Riwayat</Link></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <Pagination page={result.meta.current_page} lastPage={result.meta.last_page} total={result.meta.total} perPage={result.meta.per_page} unit="backlog" onPage={setPage} />
          </>
        )}
      </section>
    </div>
  );
}
