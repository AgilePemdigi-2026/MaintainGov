"use client";
// Komponen tampilan bersama halaman Sprint 2 (mengikuti frame Hi-Fi Figma "MaintainGov").
import { useEffect } from "react";
import type { ReactNode } from "react";
import Link from "next/link";
import { BACKLOG_STATUS_LABEL, BACKLOG_STATUS_TONE, CASE_STATUS_TONE, PRIORITY_SCALE, severityTone } from "@/lib/sprint2/labels";
import type { BacklogStatus, CaseStatus } from "@/lib/sprint2/types";

export function PageHeader({ crumbs, title, meta, actions }: {
  crumbs: { label: string; href?: string }[];
  title: string;
  meta?: ReactNode;
  actions?: ReactNode;
}) {
  return (
    <header className="ws-page-head">
      <nav aria-label="Breadcrumb" className="ws-crumbs">
        {crumbs.map((crumb, index) => (
          <span key={crumb.label}>
            {index > 0 && <span className="ws-crumbs__sep" aria-hidden="true">/</span>}
            {crumb.href ? <Link href={crumb.href}>{crumb.label}</Link> : <span>{crumb.label}</span>}
          </span>
        ))}
      </nav>
      <div className="ws-page-head__row">
        <div>
          <h1 className="ws-title">{title}</h1>
          {meta && <div className="ws-subtitle">{meta}</div>}
        </div>
        {actions && <div className="ws-actions">{actions}</div>}
      </div>
    </header>
  );
}

export function SeverityBadge({ severity }: { severity: string }) {
  return (
    <span className={`ws-sev ws-sev--${severityTone(severity)}`}>
      <span className="ws-sev__bars" aria-hidden="true"><i /><i /><i /><i /></span>
      {severity}
    </span>
  );
}

export function CaseStatusPill({ status }: { status: CaseStatus }) {
  return <span className={`ws-pill ws-pill--${CASE_STATUS_TONE[status] ?? "muted"}`}>{status}</span>;
}

export function BacklogStatusPill({ status }: { status: BacklogStatus }) {
  return <span className={`ws-pill ws-pill--${BACKLOG_STATUS_TONE[status] ?? "muted"}`}>{BACKLOG_STATUS_LABEL[status] ?? status}</span>;
}

export function PriorityBar({ score }: { score: number }) {
  const value = Number(score);
  const pct = Math.max(0, Math.min(100, (value / PRIORITY_SCALE) * 100));
  const tone = value >= 9 ? "critical" : value >= 7 ? "high" : value >= 4 ? "medium" : "low";
  return (
    <span className="ws-prio">
      <span className="ws-prio__num">{value.toFixed(1)}/{PRIORITY_SCALE}</span>
      <span className="ws-prio__track" aria-hidden="true"><span className={`ws-prio__fill ws-prio__fill--${tone}`} style={{ width: `${pct}%` }} /></span>
    </span>
  );
}

export function Alert({ tone = "error", title, children, onClose }: {
  tone?: "error" | "success" | "info" | "warning";
  title: string;
  children?: ReactNode;
  onClose?: () => void;
}) {
  return (
    <div className={`ws-alert ws-alert--${tone}`} role={tone === "error" ? "alert" : "status"}>
      <div>
        <p className="ws-alert__title">{title}</p>
        {children && <div className="ws-alert__text">{children}</div>}
      </div>
      {onClose && <button type="button" className="ws-icon-btn" aria-label="Tutup pesan" onClick={onClose}>×</button>}
    </div>
  );
}

/** Toast kanan atas, hilang otomatis 6 detik, tetap bisa ditutup manual (frame States & Feedback). */
export function Toast({ tone = "success", title, text, onClose }: {
  tone?: "success" | "error";
  title: string;
  text?: string;
  onClose: () => void;
}) {
  useEffect(() => {
    const timer = setTimeout(onClose, 6000);
    return () => clearTimeout(timer);
  }, [onClose]);
  return (
    <div className={`ws-toast ws-toast--${tone}`} role={tone === "error" ? "alert" : "status"}>
      <div>
        <p className="ws-toast__title">{title}</p>
        {text && <p className="ws-toast__text">{text}</p>}
      </div>
      <button type="button" className="ws-icon-btn" aria-label="Tutup notifikasi" onClick={onClose}>×</button>
    </div>
  );
}

export function EmptyState({ title, text, action }: { title: string; text?: string; action?: ReactNode }) {
  return (
    <div className="ws-empty">
      <p className="ws-empty__title">{title}</p>
      {text && <p className="ws-empty__text">{text}</p>}
      {action}
    </div>
  );
}

export function SkeletonRows({ rows = 4 }: { rows?: number }) {
  return (
    <div className="ws-skeleton" aria-busy="true" aria-label="Memuat data">
      {Array.from({ length: rows }, (_, index) => <div key={index} className="ws-skeleton__row"><i /><i /><i /><i /></div>)}
    </div>
  );
}

export function ErrorState({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="ws-empty" role="alert">
      <p className="ws-empty__title">Data gagal dimuat</p>
      <p className="ws-empty__text">{message}</p>
      <button type="button" className="ws-btn ws-btn--outline" onClick={onRetry}>Coba lagi</button>
    </div>
  );
}

export function Pagination({ page, lastPage, total, perPage, unit, onPage }: {
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  unit: string;
  onPage: (page: number) => void;
}) {
  const from = total === 0 ? 0 : (page - 1) * perPage + 1;
  const to = Math.min(total, page * perPage);
  return (
    <div className="ws-pager">
      <span>{from}–{to} dari {total} {unit}</span>
      <nav aria-label="Navigasi halaman" className="ws-pager__nav">
        <button type="button" className="ws-pager__btn" aria-label="Halaman sebelumnya" disabled={page <= 1} onClick={() => onPage(page - 1)}>‹</button>
        {Array.from({ length: lastPage }, (_, index) => index + 1).map(number => (
          <button key={number} type="button" className="ws-pager__btn" aria-current={number === page ? "page" : undefined} onClick={() => onPage(number)}>{number}</button>
        ))}
        <button type="button" className="ws-pager__btn" aria-label="Halaman berikutnya" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>›</button>
      </nav>
    </div>
  );
}

export function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="ws-info-row">
      <dt>{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}
