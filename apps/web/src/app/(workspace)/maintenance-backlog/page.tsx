"use client";
// UC03 (Feature 5a): daftar Maintenance Backlog. Dapat dilihat Tim Maintenance dan Developer.
import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useSession } from "@/components/workspace/session";
import {
  BacklogStatusPill, EmptyState, ErrorState, PageHeader, Pagination, SeverityBadge, SkeletonRows,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { BACKLOG_STATUSES, BACKLOG_STATUS_LABEL, SEVERITIES, backlogCode, caseCode, priorityLabel } from "@/lib/sprint2/labels";
import type { BacklogStatus, MaintenanceBacklog, Paged } from "@/lib/sprint2/types";

const SR_ONLY = { position: "absolute", width: 1, height: 1, overflow: "hidden", clip: "rect(0 0 0 0)" } as const;

export default function MaintenanceBacklogPage() {
  const { handleError } = useSession();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [modul, setModul] = useState("");
  const [severity, setSeverity] = useState("");
  const [status, setStatus] = useState<BacklogStatus | "">("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Paged<MaintenanceBacklog> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [moduls, setModuls] = useState<string[]>([]);

  useEffect(() => {
    const timer = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    sprint2Api.listBacklogs({ page, status, search, modul, severity })
      .then(data => {
        if (!active) return;
        setResult(data);
        setModuls(prev => Array.from(new Set([...prev, ...data.data.map(item => item.modul).filter(Boolean)])).sort());
      })
      .catch(err => { if (active) setError(handleError(err, "Daftar Maintenance Backlog tidak dapat dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, search, modul, severity, reloadKey, handleError]);

  const retry = useCallback(() => setReloadKey(key => key + 1), []);
  const reset = () => {
    setSearchInput(""); setSearch(""); setModul(""); setSeverity(""); setStatus(""); setPage(1);
  };

  const rows = useMemo(() => result?.data ?? [], [result]);
  const total = result?.meta.total ?? 0;
  const counts = useMemo(() => BACKLOG_STATUSES.map(item => ({
    status: item, count: rows.filter(row => row.status === item).length,
  })), [rows]);
  const rowOffset = result ? (result.meta.current_page - 1) * result.meta.per_page : 0;

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Tim Maintenance" }, { label: "Maintenance Backlog" }]}
        title="Maintenance Backlog"
        meta={result ? `${total} tiket tervalidasi` : "Memuat tiket tervalidasi"}
      />

      {result && rows.length > 0 && (
        <section className="ws-card" aria-label="Ringkasan backlog">
          <div className="ws-card__head">
            <p className="ws-card__title">Ringkasan di halaman ini</p>
            <p className="ws-card__hint">{rows.length} dari {total} tiket</p>
          </div>
          <div className="ws-card__body">
            <ul style={{ display: "flex", flexWrap: "wrap", gap: 12, listStyle: "none", margin: 0, padding: 0 }}>
              {counts.map(item => (
                <li key={item.status}>{BACKLOG_STATUS_LABEL[item.status]}: <strong>{item.count}</strong></li>
              ))}
            </ul>
          </div>
        </section>
      )}

      <section className="ws-card" aria-label="Daftar Maintenance Backlog">
        <div className="ws-toolbar">
          <label className="ws-search">
            <input
              type="search"
              value={searchInput}
              aria-label="Cari Maintenance Backlog"
              onChange={event => setSearchInput(event.target.value)}
              placeholder="Cari judul, ID, atau referensi"
            />
          </label>
          <select className="ws-select" aria-label="Modul" value={modul} onChange={event => { setModul(event.target.value); setPage(1); }}>
            <option value="">Modul: Semua</option>
            {moduls.map(item => <option key={item} value={item}>{item}</option>)}
          </select>
          <select className="ws-select" aria-label="Severity" value={severity} onChange={event => { setSeverity(event.target.value); setPage(1); }}>
            <option value="">Severity: Semua</option>
            {SEVERITIES.map(item => <option key={item} value={item}>{item}</option>)}
          </select>
          <select className="ws-select" aria-label="Status" value={status} onChange={event => { setStatus(event.target.value as BacklogStatus | ""); setPage(1); }}>
            <option value="">Status: Semua</option>
            {BACKLOG_STATUSES.map(item => <option key={item} value={item}>{BACKLOG_STATUS_LABEL[item]}</option>)}
          </select>
          <button type="button" className="ws-link-btn" onClick={reset}>Reset</button>
        </div>

        {loading && !result && !error && <SkeletonRows rows={5} />}
        {error && <ErrorState message={error} onRetry={retry} />}
        {!error && result && rows.length === 0 && (
          <EmptyState
            title="Belum ada Maintenance Backlog"
            text="Backlog muncul setelah Maintenance Case divalidasi, atau tidak ada tiket yang sesuai dengan filter."
          />
        )}
        {!error && result && rows.length > 0 && (
          <>
            <div className="ws-table-wrap" aria-busy={loading}>
              <table className="ws-table">
                <thead>
                  <tr>
                    <th scope="col">No</th>
                    <th scope="col">Judul backlog</th>
                    <th scope="col">Kategori</th>
                    <th scope="col">Modul / fitur</th>
                    <th scope="col">Severity</th>
                    <th scope="col" title="Label usulan dari skor 0–10">Priority</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span style={SR_ONLY}>Buka</span></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((item, index) => (
                    <tr key={item.backlog_id}>
                      <td>{rowOffset + index + 1}</td>
                      <td>
                        <Link className="ws-table__title" href={`/maintenance-backlog/${item.backlog_id}`}>{item.judul}</Link>
                        <div className="ws-table__sub">{backlogCode(item.backlog_id)} · ref {caseCode(item.case_id)}</div>
                      </td>
                      <td>{item.kategori}</td>
                      <td>
                        <div>{item.modul}</div>
                        <div className="ws-table__sub">{item.fitur}</div>
                      </td>
                      <td><SeverityBadge severity={item.severity} /></td>
                      <td>
                        {priorityLabel(Number(item.priority_score))}{" "}
                        <span className="ws-table__sub">({Number(item.priority_score).toFixed(1)})</span>
                      </td>
                      <td><BacklogStatusPill status={item.status} /></td>
                      <td><Link href={`/maintenance-backlog/${item.backlog_id}`} aria-label={`Buka ${backlogCode(item.backlog_id)}`}>›</Link></td>
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
              unit="item"
              onPage={setPage}
            />
          </>
        )}
      </section>
    </div>
  );
}
