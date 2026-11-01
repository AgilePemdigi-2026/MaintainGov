"use client";
// UC02 (Feature 4a): daftar Maintenance Case untuk divalidasi Tim Maintenance.
import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { RoleGate, useSession } from "@/components/workspace/session";
import {
  CaseStatusPill, EmptyState, ErrorState, PageHeader, Pagination, PriorityBar, SeverityBadge, SkeletonRows,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { SEVERITIES, caseCode } from "@/lib/sprint2/labels";
import type { CaseStatus, MaintenanceCase, Paged } from "@/lib/sprint2/types";

const DEFAULT_CATEGORIES = ["Bug", "Performance", "Notification"];
const STATUS_OPTIONS: { value: CaseStatus | ""; label: string }[] = [
  { value: "Pending", label: "Pending" },
  { value: "Valid", label: "Valid" },
  { value: "Tidak Valid", label: "Tidak Valid" },
  { value: "", label: "Semua" },
];

function CaseList() {
  const { handleError } = useSession();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [kategori, setKategori] = useState("");
  const [severity, setSeverity] = useState("");
  const [status, setStatus] = useState<CaseStatus | "">("Pending");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [result, setResult] = useState<Paged<MaintenanceCase> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [categories, setCategories] = useState<string[]>(DEFAULT_CATEGORIES);

  useEffect(() => {
    const timer = setTimeout(() => { setSearch(searchInput.trim()); setPage(1); }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);
    sprint2Api.listCases({ page, status, search, kategori, severity })
      .then(data => {
        if (!active) return;
        setResult(data);
        setCategories(prev => Array.from(new Set([...prev, ...data.data.map(item => item.kategori).filter(Boolean)])));
      })
      .catch(err => { if (active) setError(handleError(err, "Daftar Maintenance Case tidak dapat dimuat.")); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [page, status, search, kategori, severity, reloadKey, handleError]);

  const retry = useCallback(() => setReloadKey(key => key + 1), []);
  const reset = () => {
    setSearchInput(""); setSearch(""); setKategori(""); setSeverity(""); setStatus("Pending"); setPage(1);
  };

  const total = result?.meta.total ?? 0;
  const rows = result?.data ?? [];
  const pendingText = status === "Pending" ? `${total} kasus pending hasil ekstraksi feedback` : `${total} kasus hasil ekstraksi feedback`;

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[{ label: "Tim Maintenance" }, { label: "Maintenance Case" }]}
        title="Maintenance Case"
        meta={result ? pendingText : "Memuat kasus hasil ekstraksi feedback"}
      />
      <section className="ws-card" aria-label="Daftar Maintenance Case">
        <div className="ws-toolbar">
          <label className="ws-search">
            <input
              type="search"
              aria-label="Cari Maintenance Case"
              value={searchInput}
              onChange={event => setSearchInput(event.target.value)}
              placeholder="Cari judul, modul, atau ID kasus"
            />
          </label>
          <select className="ws-select" aria-label="Kategori" value={kategori} onChange={event => { setKategori(event.target.value); setPage(1); }}>
            <option value="">Kategori: Semua</option>
            {categories.map(item => <option key={item} value={item}>{item}</option>)}
          </select>
          <select className="ws-select" aria-label="Severity" value={severity} onChange={event => { setSeverity(event.target.value); setPage(1); }}>
            <option value="">Severity: Semua</option>
            {SEVERITIES.map(item => <option key={item} value={item}>{item}</option>)}
          </select>
          <select className="ws-select" aria-label="Status" value={status} onChange={event => { setStatus(event.target.value as CaseStatus | ""); setPage(1); }}>
            {STATUS_OPTIONS.map(item => <option key={item.label} value={item.value}>Status: {item.label}</option>)}
          </select>
          <button type="button" className="ws-link-btn" onClick={reset}>Reset</button>
        </div>

        {loading && !result && <SkeletonRows rows={5} />}
        {error && <ErrorState message={error} onRetry={retry} />}
        {!error && result && rows.length === 0 && (
          <EmptyState title="Tidak ada Maintenance Case" text="Tidak ada kasus yang sesuai dengan filter saat ini." />
        )}
        {!error && result && rows.length > 0 && (
          <>
            <div className="ws-table-wrap" aria-busy={loading}>
              <table className="ws-table">
                <thead>
                  <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Judul issue</th>
                    <th scope="col">Kategori</th>
                    <th scope="col">Modul</th>
                    <th scope="col">Severity</th>
                    <th scope="col">Skor prioritas</th>
                    <th scope="col">Status</th>
                    <th scope="col" aria-label="Buka"></th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map(item => (
                    <tr key={item.case_id}>
                      <td className="ws-table__id">{caseCode(item.case_id)}</td>
                      <td><Link className="ws-table__title" href={`/maintenance-case/${item.case_id}`}>{item.judul_issue}</Link></td>
                      <td>{item.kategori}</td>
                      <td>{item.modul}</td>
                      <td><SeverityBadge severity={item.severity} /></td>
                      <td><PriorityBar score={item.priority_score} /></td>
                      <td><CaseStatusPill status={item.status_validasi} /></td>
                      <td><Link href={`/maintenance-case/${item.case_id}`} aria-label={`Buka ${caseCode(item.case_id)}`}>›</Link></td>
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
              unit="kasus"
              onPage={setPage}
            />
          </>
        )}
      </section>
    </div>
  );
}

export default function MaintenanceCasePage() {
  return <RoleGate role="Tim Maintenance"><CaseList /></RoleGate>;
}
