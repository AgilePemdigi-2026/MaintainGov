"use client";
// UC02 (Feature 4a): detail Maintenance Case dan keputusan validasi (Ya / Tidak).
import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { RoleGate, useSession } from "@/components/workspace/session";
import {
  Alert, CaseStatusPill, EmptyState, ErrorState, InfoRow, PageHeader, PriorityBar, SeverityBadge, SkeletonRows,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { MESSAGES, backlogCode, caseCode, formatDateTime, priorityLabel } from "@/lib/sprint2/labels";
import type { CaseDecision, MaintenanceCaseDetail } from "@/lib/sprint2/types";

const NOT_FOUND = "Maintenance Case tidak ditemukan";

function NotFound() {
  return (
    <div className="ws-content">
      <PageHeader crumbs={[{ label: "Tim Maintenance" }, { label: "Maintenance Case", href: "/maintenance-case" }]} title="Maintenance Case" />
      <div className="ws-card">
        <EmptyState
          title={NOT_FOUND}
          text="Kasus mungkin sudah dihapus atau tautan tidak valid."
          action={<Link className="ws-btn ws-btn--outline" href="/maintenance-case">Kembali ke daftar</Link>}
        />
      </div>
    </div>
  );
}

function CaseDetail({ id }: { id: number }) {
  const { handleError } = useSession();
  const [data, setData] = useState<MaintenanceCaseDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(false);
  const [confirming, setConfirming] = useState<CaseDecision | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState<string | null>(null);
  const [actionError, setActionError] = useState<{ message: string; retry: CaseDecision | null } | null>(null);
  const submittingRef = useRef(false);

  const load = useCallback(async (silent = false) => {
    if (!silent) setLoading(true);
    setLoadError(null);
    try {
      const detail = await sprint2Api.getCase(id);
      setData(detail);
      setNotFound(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setNotFound(true);
      else if (!silent) setLoadError(handleError(err, "Maintenance Case tidak dapat dimuat."));
    } finally {
      setLoading(false);
    }
  }, [id, handleError]);

  useEffect(() => { void load(); }, [load]);

  const submit = async (decision: CaseDecision) => {
    if (submittingRef.current) return;
    submittingRef.current = true;
    setSubmitting(true);
    setActionError(null);
    setSuccess(null);
    try {
      const result = await sprint2Api.validateCase(id, decision);
      setData(prev => (prev ? {
        ...prev, status_validasi: result.status_validasi, validated_at: result.validated_at, backlog_id: result.backlog_id,
      } : prev));
      setSuccess(decision === "Valid" ? MESSAGES.caseValid : MESSAGES.caseInvalid);
      setConfirming(null);
    } catch (err) {
      const message = handleError(err, "Keputusan tidak dapat disimpan. Coba lagi.");
      setConfirming(null);
      if (err instanceof ApiError && err.status === 409) {
        setActionError({ message, retry: null });
        await load(true);
      } else {
        setActionError({ message, retry: err instanceof ApiError && err.status === 403 ? null : decision });
      }
    } finally {
      submittingRef.current = false;
      setSubmitting(false);
    }
  };

  if (loading && !data) return <div className="ws-content"><SkeletonRows rows={6} /></div>;
  if (notFound) return <NotFound />;
  if (loadError || !data) {
    return <div className="ws-content"><ErrorState message={loadError ?? "Maintenance Case tidak dapat dimuat."} onRetry={() => void load()} /></div>;
  }

  const isPending = data.status_validasi === "Pending";
  const backlogId = data.backlog_id ?? null;
  const reviews = data.reviews ?? [];

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={[
          { label: "Tim Maintenance" },
          { label: "Maintenance Case", href: "/maintenance-case" },
          { label: caseCode(data.case_id) },
        ]}
        title={data.judul_issue}
        meta={
          <>
            <span>{caseCode(data.case_id)}</span>{" "}
            <CaseStatusPill status={data.status_validasi} />{" "}
            <span>Dibuat {formatDateTime(data.created_at)}</span>
          </>
        }
      />

      {success && <Alert tone="success" title={success} onClose={() => setSuccess(null)} />}
      {actionError && (
        <Alert tone="error" title={actionError.message} onClose={() => setActionError(null)}>
          {actionError.retry && (
            <button
              type="button"
              className="ws-btn ws-btn--outline ws-btn--sm"
              disabled={submitting}
              onClick={() => void submit(actionError.retry as CaseDecision)}
            >
              Coba lagi
            </button>
          )}
        </Alert>
      )}

      <div className="ws-grid">
        <div className="ws-stack">
          <section className="ws-card">
            <div className="ws-card__head"><h2 className="ws-card__title">Ringkasan masalah</h2></div>
            <div className="ws-card__body"><p>{data.ringkasan_masalah}</p></div>
          </section>
          <section className="ws-card">
            <div className="ws-card__head"><h2 className="ws-card__title">Rekomendasi teknis</h2></div>
            <div className="ws-card__body"><p>{data.recommendation || "Belum ada rekomendasi"}</p></div>
          </section>
          <section className="ws-card">
            <div className="ws-card__head">
              <h2 className="ws-card__title">Ulasan terkait</h2>
              <span className="ws-card__hint">{reviews.length} ulasan</span>
            </div>
            <div className="ws-card__body">
              {reviews.length === 0 && <p>Belum ada ulasan terkait.</p>}
              {reviews.map(review => (
                <article key={review.review_id} className="ws-review">
                  <div className="ws-review__meta">
                    <span>{review.sumber || review.source_review_id} · Rating {review.rating}/5 {"★".repeat(Math.max(0, Math.min(5, review.rating)))}</span>
                    <span>{formatDateTime(review.tanggal_ulasan)}</span>
                  </div>
                  <p className="ws-review__text">&ldquo;{review.teks_ulasan}&rdquo;</p>
                </article>
              ))}
            </div>
          </section>
        </div>

        <aside className="ws-stack">
          <section className="ws-card">
            <div className="ws-card__head"><h2 className="ws-card__title">Detail kasus</h2></div>
            <div className="ws-card__body">
              <dl className="ws-info">
                <InfoRow label="Kategori">{data.kategori}</InfoRow>
                <InfoRow label="Modul">{data.modul}</InfoRow>
                <InfoRow label="Fitur">{data.fitur}</InfoRow>
                <InfoRow label="Severity"><SeverityBadge severity={data.severity} /></InfoRow>
                <InfoRow label="Skor prioritas">
                  <PriorityBar score={data.priority_score} />
                  <span className="ws-card__hint">{priorityLabel(Number(data.priority_score))} (usulan)</span>
                </InfoRow>
                <InfoRow label="Status"><CaseStatusPill status={data.status_validasi} /></InfoRow>
              </dl>
            </div>
          </section>
          {data.status_validasi === "Valid" && backlogId !== null && (
            <section className="ws-card">
              <div className="ws-card__head"><h2 className="ws-card__title">Maintenance Backlog</h2></div>
              <div className="ws-card__body">
                <dl className="ws-info">
                  <InfoRow label="ID">{backlogCode(backlogId)}</InfoRow>
                </dl>
                <p><Link className="ws-btn ws-btn--outline" href={`/maintenance-backlog/${backlogId}`}>Lihat Maintenance Backlog</Link></p>
              </div>
            </section>
          )}
        </aside>
      </div>

      <section className="ws-card" aria-label="Keputusan tim maintenance">
        <div className="ws-card__head"><h2 className="ws-card__title">Keputusan tim maintenance</h2></div>
        {isPending ? (
          confirming ? (
            <div className="ws-decision" role="group" aria-label="Konfirmasi keputusan">
              <p>{confirming === "Valid" ? "Konfirmasi: teruskan sebagai Valid?" : "Konfirmasi: tandai sebagai Tidak Valid?"}</p>
              <div className="ws-actions">
                <button type="button" className="ws-btn ws-btn--outline" disabled={submitting} onClick={() => setConfirming(null)}>Batal</button>
                <button
                  type="button"
                  className={`ws-btn ${confirming === "Valid" ? "ws-btn--primary" : "ws-btn--danger"}`}
                  disabled={submitting}
                  onClick={() => void submit(confirming)}
                >
                  Konfirmasi
                </button>
              </div>
            </div>
          ) : (
            <div className="ws-decision">
              <p>Apakah Maintenance Case ini valid untuk diteruskan menjadi Maintenance Backlog?</p>
              <div className="ws-actions">
                <button type="button" className="ws-btn ws-btn--primary" disabled={submitting} onClick={() => { setActionError(null); setConfirming("Valid"); }}>Ya</button>
                <button type="button" className="ws-btn ws-btn--danger" disabled={submitting} onClick={() => { setActionError(null); setConfirming("Tidak Valid"); }}>Tidak</button>
              </div>
            </div>
          )
        ) : (
          <div className="ws-decision">
            <p>
              Diputuskan <strong>{data.status_validasi}</strong>
              {data.validator?.nama ? ` oleh ${data.validator.nama}` : ""}
              {data.validated_at ? ` pada ${formatDateTime(data.validated_at)}` : ""}.
            </p>
          </div>
        )}
      </section>
    </div>
  );
}

export default function MaintenanceCaseDetailPage() {
  const params = useParams<{ id: string }>();
  const raw = Array.isArray(params?.id) ? params.id[0] : params?.id;
  const id = Number(raw);
  return (
    <RoleGate role="Tim Maintenance">
      {Number.isInteger(id) && id > 0 ? <CaseDetail id={id} /> : <NotFound />}
    </RoleGate>
  );
}
