"use client";
// UC04 (Feature 6a): detail Maintenance Issue + klaim "Kerjakan issue".
// Status selalu berasal dari respons API; klien tidak menebak status berikutnya.
import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { RoleGate, useSession } from "@/components/workspace/session";
import {
  Alert, BacklogStatusPill, EmptyState, ErrorState, InfoRow, PageHeader, SeverityBadge, SkeletonRows, Toast,
} from "@/components/workspace/ui";
import { githubSprint2, isMockMode, sprint2Api } from "@/lib/sprint2/client";
import { MESSAGES, backlogCode, caseCode, formatDateTime, priorityLabel } from "@/lib/sprint2/labels";
import type { BranchPreparation, MaintenanceBacklog } from "@/lib/sprint2/types";

function IssueDetail() {
  const { user, handleError } = useSession();
  const params = useParams<{ id: string }>();
  const id = Number(params?.id);
  const validId = Number.isInteger(id) && id > 0;

  const [issue, setIssue] = useState<MaintenanceBacklog | null>(null);
  const [loading, setLoading] = useState(validId);
  const [error, setError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(!validId);
  const [reloadKey, setReloadKey] = useState(0);
  const [claiming, setClaiming] = useState(false);
  const claimRef = useRef(false);
  const [taken, setTaken] = useState(false);
  const [claimError, setClaimError] = useState<string | null>(null);
  const [toast, setToast] = useState(false);
  const [branchNotice, setBranchNotice] = useState<BranchPreparation | null>(null);
  const [branchError, setBranchError] = useState<string | null>(null);
  const [preparingBranch, setPreparingBranch] = useState(false);
  const prepareRef = useRef(false);
  const [verifying, setVerifying] = useState(false);
  const verifyRef = useRef(false);
  const [verifyError, setVerifyError] = useState<string | null>(null);
  const [verifySuccess, setVerifySuccess] = useState(false);

  useEffect(() => {
    if (!validId) return;
    let active = true;
    setLoading(true);
    setError(null);
    sprint2Api.getIssue(id)
      .then(data => { if (active) setIssue(data); })
      .catch(err => {
        if (!active) return;
        if (err instanceof ApiError && err.status === 404) setNotFound(true);
        else setError(handleError(err, "Maintenance Issue tidak dapat dimuat."));
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, validId, reloadKey, handleError]);

  const retry = useCallback(() => setReloadKey(key => key + 1), []);
  const closeToast = useCallback(() => setToast(false), []);

  const claim = async () => {
    if (claimRef.current) return;
    claimRef.current = true;
    setClaiming(true);
    setClaimError(null);
    try {
      const data = await sprint2Api.claimBacklog(id);
      setIssue(data);
      setBranchNotice(data.branch_preparation ?? null);
      setToast(true);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setTaken(true);
        setClaimError(MESSAGES.issueTaken);
        try { setIssue(await sprint2Api.getIssue(id)); } catch { /* data lama tetap tampil; tombol tetap terkunci */ }
      } else {
        setClaimError(handleError(err, "Issue tidak dapat diambil. Coba lagi."));
      }
    } finally {
      claimRef.current = false;
      setClaiming(false);
    }
  };

  // Recovery path when branch creation failed during claim or the page was
  // reloaded before GitHub was reachable.
  const prepareBranch = async () => {
    if (prepareRef.current || !validId) return;
    prepareRef.current = true;
    setPreparingBranch(true);
    setBranchError(null);
    try {
      const result = await sprint2Api.prepareBranch(id);
      setBranchNotice(result);
      setIssue(current => current ? { ...current, source_branch: current.source_branch ?? result.branch } : current);
    } catch (err) {
      setBranchError(handleError(err, "Branch GitHub belum dapat disiapkan. Coba lagi."));
    } finally {
      prepareRef.current = false;
      setPreparingBranch(false);
    }
  };

  // Recovery path when the GitHub webhook cannot reach localhost.
  const verifyLatestPush = async () => {
    if (verifyRef.current || !validId) return;
    verifyRef.current = true;
    setVerifying(true);
    setVerifyError(null);
    setVerifySuccess(false);
    try {
      await githubSprint2.verifyPush(id);
      setIssue(await sprint2Api.getIssue(id));
      setBranchNotice(null);
      setVerifySuccess(true);
    } catch (err) {
      setVerifyError(handleError(err, 'Push belum dapat diverifikasi. Periksa branch dan koneksi GitHub.'));
    } finally {
      verifyRef.current = false;
      setVerifying(false);
    }
  };

  if (notFound) {
    return (
      <div className="ws-content">
        <EmptyState
          title="Maintenance Issue tidak ditemukan"
          action={<Link className="ws-btn ws-btn--outline" href="/maintenance-issue">Kembali ke daftar</Link>}
        />
      </div>
    );
  }
  if (loading && !issue) return <div className="ws-content"><SkeletonRows rows={5} /></div>;
  if (error && !issue) return <div className="ws-content"><ErrorState message={error} onRetry={retry} /></div>;
  if (!issue) return null;

  const isAssignee = issue.assigned_to?.user_id === user.user_id;
  const canClaim = issue.status === "Ready for Development" && !issue.assigned_to && !taken;
  const canPr = issue.status === "Ready for Pull Request" && !!issue.last_push && !issue.pull_request && isAssignee;
  const code = backlogCode(issue.backlog_id);

  return (
    <div className="ws-content">
      {toast && <Toast title="Issue berhasil diambil." text="Status: Sedang dikerjakan." onClose={closeToast} />}
      <PageHeader
        crumbs={[{ label: "Tim Developer" }, { label: "Maintenance Issue", href: "/maintenance-issue" }, { label: code }]}
        title={issue.judul}
        meta={<><span>{code}</span> · <BacklogStatusPill status={issue.status} /> · <span>Dari case {caseCode(issue.case_id)}</span></>}
        actions={
          <>
            {canPr
              ? <Link className="ws-btn ws-btn--outline" href={`/maintenance-issue/${issue.backlog_id}/pull-request`}>Ajukan pull request</Link>
              : <button type="button" className="ws-btn ws-btn--outline" disabled>Ajukan pull request</button>}
            <button type="button" className="ws-btn ws-btn--primary" disabled={!canClaim || claiming} onClick={claim}>
              {claiming ? "Memproses..." : "Kerjakan issue"}
            </button>
          </>
        }
      />
      {claimError && <Alert tone="error" title={claimError} onClose={() => setClaimError(null)} />}
      {branchNotice?.status === "failed" && (
        <Alert tone="warning" title={branchNotice.message}>
          Branch <code>{branchNotice.branch}</code> belum dibuat di GitHub. Klaim issue tetap tersimpan; gunakan tombol “Siapkan branch GitHub” di bawah untuk mencoba lagi.
        </Alert>
      )}
      {branchNotice && branchNotice.status !== "failed" && (
        <Alert tone={branchNotice.status === "created" ? "success" : "info"} title={branchNotice.message}>
          Branch <code>{branchNotice.branch}</code> {branchNotice.status === "created" ? "dibuat otomatis" : "sudah tersedia"} di GitHub. Commit dan push ke branch ini sebelum verifikasi.
        </Alert>
      )}
      {branchError && <Alert tone="error" title={branchError} onClose={() => setBranchError(null)} />}
      {verifyError && <Alert tone="error" title={verifyError} onClose={() => setVerifyError(null)} />}
      {verifySuccess && <Alert tone="success" title="Push telah diverifikasi dari GitHub." />}
      {isAssignee && !isMockMode && (
        <section className="ws-card" aria-label="Verifikasi push GitHub">
          <div className="ws-card__head"><h2 className="ws-card__title">Integrasi GitHub</h2></div>
          <div className="ws-card__body">
            <p>Branch kerja: <code>{issue.source_branch ?? '-'}</code>. Branch dibuat otomatis saat issue diambil. Lakukan commit dan push ke branch ini, lalu verifikasi — aplikasi membaca HEAD branch dari GitHub, bukan mempercayai input browser.</p>
            <div className="ws-actions">
              {(issue.status === 'Sedang Dikerjakan' || issue.status === 'Ready for Pull Request') && !issue.pull_request &&
                <button className="ws-btn ws-btn--outline" type="button" onClick={prepareBranch} disabled={preparingBranch}>
                  {preparingBranch ? 'Menyiapkan...' : 'Siapkan branch GitHub'}
                </button>}
              {(issue.status === 'Sedang Dikerjakan' || issue.status === 'Ready for Pull Request') && !issue.pull_request &&
                <button className="ws-btn ws-btn--primary" type="button" onClick={verifyLatestPush} disabled={verifying}>
                  {verifying ? 'Memverifikasi...' : 'Verifikasi push GitHub'}
                </button>}
              <button className="ws-btn ws-btn--outline" type="button" onClick={retry} disabled={verifying}>
                Refresh status issue
              </button>
            </div>
          </div>
        </section>
      )}
      <div className="ws-grid">
        <div className="ws-stack">
          <section className="ws-card" aria-label="Spesifikasi issue">
            <div className="ws-card__head"><h2 className="ws-card__title">Spesifikasi issue</h2></div>
            <div className="ws-card__body">
              <dl className="ws-info">
                <InfoRow label="Modul">{issue.modul}</InfoRow>
                <InfoRow label="Fitur">{issue.fitur}</InfoRow>
                <InfoRow label="Severity"><SeverityBadge severity={issue.severity} /></InfoRow>
                <InfoRow label="Priority">{priorityLabel(issue.priority_score)} ({Number(issue.priority_score).toFixed(1)})</InfoRow>
                <InfoRow label="Ringkasan">{issue.ringkasan_masalah}</InfoRow>
              </dl>
            </div>
          </section>
          <section className="ws-card" aria-label="Status pengerjaan">
            <div className="ws-card__head"><h2 className="ws-card__title">Status pengerjaan</h2></div>
            <div className="ws-card__body">
              <dl className="ws-info">
                <InfoRow label="Source branch">{issue.source_branch ?? "-"}</InfoRow>
                <InfoRow label="Last push">
                  {issue.last_push
                    ? `${issue.last_push.branch} · ${issue.last_push.sha.slice(0, 7)} · ${formatDateTime(issue.last_push.pushed_at)}`
                    : "Belum ada push terverifikasi dari GitHub."}
                </InfoRow>
                {issue.pull_request && (
                  <InfoRow label="Pull request">
                    PR #{issue.pull_request.github_pr_number} · {issue.pull_request.status}{" "}
                    <a href={issue.pull_request.url} target="_blank" rel="noreferrer">Buka di GitHub</a>
                  </InfoRow>
                )}
              </dl>
            </div>
          </section>
        </div>
        <aside className="ws-stack">
          <section className="ws-card" aria-label="Penugasan">
            <div className="ws-card__head"><h2 className="ws-card__title">Penugasan</h2></div>
            <div className="ws-card__body">
              <dl className="ws-info">
                <InfoRow label="Assignee">{issue.assigned_to?.nama ?? "Belum ditugaskan"}</InfoRow>
                <InfoRow label="Ditugaskan pada">{formatDateTime(issue.assigned_at)}</InfoRow>
              </dl>
            </div>
          </section>
          <section className="ws-card" aria-label="Catatan alur">
            <div className="ws-card__head"><h2 className="ws-card__title">Catatan alur</h2></div>
            <div className="ws-card__body">
              <ul>
                <li>Kerjakan issue mengubah status menjadi Sedang Dikerjakan dan menugaskan Anda sebagai assignee.</li>
                <li>Ajukan pull request hanya aktif saat status Ready for pull request setelah push terverifikasi.</li>
                <li>Jika Developer lain mengambil lebih dulu, tombol terkunci dan PIC ditampilkan.</li>
              </ul>
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}

export default function MaintenanceIssueDetailPage() {
  return <RoleGate role="Developer"><IssueDetail /></RoleGate>;
}
