"use client";
// UC03 (Feature 5b): detail + edit Maintenance Backlog. Edit hanya Tim Maintenance saat status Ready for Development.
import { useCallback, useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { useSession } from "@/components/workspace/session";
import {
  Alert, BacklogStatusPill, EmptyState, ErrorState, InfoRow, PageHeader, SeverityBadge, SkeletonRows, Toast,
} from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { MESSAGES, PRIORITY_SCALE, SEVERITIES, backlogCode, caseCode, formatDateTime, priorityLabel } from "@/lib/sprint2/labels";
import type { BacklogUpdate, MaintenanceBacklog } from "@/lib/sprint2/types";

const SUMMARY_MAX = 1000;

interface FormState {
  judul: string;
  modul: string;
  fitur: string;
  severity: string;
  priority_score: string;
  ringkasan_masalah: string;
  recommendation: string;
}
type FieldName = keyof FormState;
type FormErrors = Partial<Record<FieldName, string>>;

function toForm(item: MaintenanceBacklog): FormState {
  return {
    judul: item.judul ?? "",
    modul: item.modul ?? "",
    fitur: item.fitur ?? "",
    severity: item.severity ?? "",
    priority_score: String(item.priority_score ?? ""),
    ringkasan_masalah: item.ringkasan_masalah ?? "",
    recommendation: item.recommendation ?? "",
  };
}

function validate(form: FormState): FormErrors {
  const errors: FormErrors = {};
  if (!form.judul.trim()) errors.judul = "Judul wajib diisi.";
  if (!form.modul.trim()) errors.modul = "Modul wajib diisi.";
  if (!form.fitur.trim()) errors.fitur = "Fitur wajib diisi.";
  if (!form.severity.trim()) errors.severity = "Severity wajib dipilih.";
  if (!form.ringkasan_masalah.trim()) errors.ringkasan_masalah = "Ringkasan masalah teknis wajib diisi.";
  else if (form.ringkasan_masalah.length > SUMMARY_MAX) errors.ringkasan_masalah = `Maksimal ${SUMMARY_MAX} karakter.`;
  const raw = form.priority_score.trim();
  const score = Number(raw);
  if (!raw) errors.priority_score = "Skor prioritas wajib diisi.";
  else if (!Number.isFinite(score) || score < 0 || score > PRIORITY_SCALE) errors.priority_score = `Skor harus antara 0 dan ${PRIORITY_SCALE}.`;
  return errors;
}

function editBlockReason(item: MaintenanceBacklog, role: string): string | null {
  if (role !== "Tim Maintenance") return "Hanya Tim Maintenance yang dapat mengubah backlog.";
  if (item.status !== "Ready for Development") return `Backlog sudah dikerjakan (status ${item.status}) sehingga tidak dapat diubah.`;
  return null;
}

export default function MaintenanceBacklogDetailPage() {
  const params = useParams<{ id: string }>();
  const rawId = Array.isArray(params?.id) ? params.id[0] : params?.id;
  const id = Number(rawId);
  const validId = Number.isInteger(id) && id > 0;
  const { user, handleError } = useSession();

  const [backlog, setBacklog] = useState<MaintenanceBacklog | null>(null);
  const [loading, setLoading] = useState(validId);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(!validId);
  const [reloadKey, setReloadKey] = useState(0);

  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<FormState | null>(null);
  const [errors, setErrors] = useState<FormErrors>({});
  const [formAlert, setFormAlert] = useState<{ title: string; text: string; tone: "error" | "warning" } | null>(null);
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  const [conflict, setConflict] = useState<string | null>(null);
  const submitting = useRef(false);

  const load = useCallback(async (silent = false) => {
    if (!validId) return;
    if (!silent) setLoading(true);
    setLoadError(null);
    try {
      const data = await sprint2Api.getBacklog(id);
      setBacklog(data);
      setNotFound(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) setNotFound(true);
      else setLoadError(handleError(err, "Maintenance Backlog tidak dapat dimuat."));
    } finally {
      setLoading(false);
    }
  }, [id, validId, handleError]);

  useEffect(() => { void load(); }, [load, reloadKey]);

  const closeToast = useCallback(() => setToast(null), []);

  const blockReason = backlog ? editBlockReason(backlog, user.role) : null;
  const canEdit = backlog !== null && blockReason === null;
  const original = backlog ? toForm(backlog) : null;
  const dirty = editing && form !== null && original !== null && (Object.keys(form) as FieldName[]).some(key => form[key] !== original[key]);

  const startEdit = () => {
    if (!backlog) return;
    setForm(toForm(backlog));
    setErrors({});
    setFormAlert(null);
    setToast(null);
    setEditing(true);
  };

  const cancelEdit = () => {
    setEditing(false);
    setForm(null);
    setErrors({});
    setFormAlert(null);
  };

  const setField = (name: FieldName, value: string) => {
    setForm(prev => (prev ? { ...prev, [name]: value } : prev));
    setErrors(prev => (prev[name] ? { ...prev, [name]: undefined } : prev));
  };

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!backlog || !form || submitting.current) return;
    const found = validate(form);
    if (Object.values(found).some(Boolean)) {
      setErrors(found);
      setFormAlert({ title: MESSAGES.backlogIncomplete, text: "Periksa kolom yang ditandai merah sebelum menyimpan.", tone: "error" });
      return;
    }
    setErrors({});
    setFormAlert(null);
    submitting.current = true;
    setSaving(true);
    const payload: BacklogUpdate = {
      judul: form.judul.trim(),
      modul: form.modul.trim(),
      fitur: form.fitur.trim(),
      severity: form.severity,
      priority_score: Number(form.priority_score),
      ringkasan_masalah: form.ringkasan_masalah.trim(),
      recommendation: form.recommendation.trim() ? form.recommendation.trim() : null,
    };
    try {
      const updated = await sprint2Api.updateBacklog(backlog.backlog_id, payload);
      setBacklog(updated);
      setEditing(false);
      setForm(null);
      setToast(MESSAGES.backlogSaved);
    } catch (err) {
      const status = err instanceof ApiError ? err.status : 0;
      const message = handleError(err, "Perubahan tidak dapat disimpan.");
      if (status === 422) {
        setFormAlert({ title: MESSAGES.backlogIncomplete, text: message, tone: "error" });
      } else if (status === 409) {
        setFormAlert(null);
        setEditing(false);
        setForm(null);
        setLoadError(null);
        setToast(null);
        setConflict(message);
        void load(true);
      } else {
        setFormAlert({ title: "Perubahan gagal disimpan", text: message, tone: "error" });
      }
    } finally {
      submitting.current = false;
      setSaving(false);
    }
  };

  const crumbs = [
    { label: "Tim Maintenance" },
    { label: "Maintenance Backlog", href: "/maintenance-backlog" },
    { label: validId ? backlogCode(id) : "Tidak ditemukan" },
  ];

  if (loading && !backlog) {
    return <div className="ws-content"><PageHeader crumbs={crumbs} title="Memuat backlog" /><div className="ws-card"><SkeletonRows rows={5} /></div></div>;
  }
  if (notFound) {
    return (
      <div className="ws-content">
        <PageHeader crumbs={crumbs} title="Maintenance Backlog" />
        <div className="ws-card">
          <EmptyState
            title="Maintenance Backlog tidak ditemukan"
            text="Tiket yang Anda cari tidak ada atau sudah dihapus."
            action={<Link className="ws-btn ws-btn--outline" href="/maintenance-backlog">Kembali ke daftar</Link>}
          />
        </div>
      </div>
    );
  }
  if (!backlog) {
    return (
      <div className="ws-content">
        <PageHeader crumbs={crumbs} title="Maintenance Backlog" />
        <div className="ws-card"><ErrorState message={loadError ?? "Maintenance Backlog tidak dapat dimuat."} onRetry={() => setReloadKey(key => key + 1)} /></div>
      </div>
    );
  }

  const values = editing && form ? form : toForm(backlog);
  const readOnly = !editing;
  const score = Number(values.priority_score);
  const scoreHelp = values.priority_score.trim() !== "" && Number.isFinite(score) && score >= 0 && score <= PRIORITY_SCALE
    ? `Label usulan: ${priorityLabel(score)} (skor 0–${PRIORITY_SCALE})`
    : `Skor 0–${PRIORITY_SCALE}; label usulan: High ≥ 7, Medium ≥ 4, Low < 4`;

  const fieldProps = (name: FieldName) => ({
    id: `backlog-${name}`,
    value: values[name],
    disabled: readOnly || saving,
    "aria-invalid": errors[name] ? true : undefined,
    "aria-describedby": errors[name] ? `backlog-${name}-error` : undefined,
    onChange: (event: { target: { value: string } }) => setField(name, event.target.value),
  });
  const fieldError = (name: FieldName) => errors[name]
    ? <p className="ws-field__error" id={`backlog-${name}-error`}>{errors[name]}</p>
    : null;

  const actions = canEdit ? (
    editing ? (
      <>
        {dirty && <span className="ws-field__help">Perubahan belum disimpan</span>}
        <button type="button" className="ws-btn ws-btn--outline" onClick={cancelEdit} disabled={saving}>Batal</button>
        <button type="submit" form="backlog-form" className="ws-btn ws-btn--primary" disabled={saving}>
          {saving ? "Menyimpan..." : "Simpan perubahan"}
        </button>
      </>
    ) : (
      <button type="button" className="ws-btn ws-btn--primary" onClick={startEdit}>Edit</button>
    )
  ) : undefined;

  return (
    <div className="ws-content">
      {toast && <Toast title={toast} onClose={closeToast} />}
      <PageHeader
        crumbs={crumbs}
        title={backlog.judul}
        meta={
          <>
            {backlogCode(backlog.backlog_id)} <BacklogStatusPill status={backlog.status} /> · Diperbarui {formatDateTime(backlog.updated_at)}
          </>
        }
        actions={actions}
      />

      {blockReason && <Alert tone="info" title="Backlog tidak dapat diedit">{blockReason}</Alert>}
      {conflict && <Alert tone="warning" title="Backlog telah berubah" onClose={() => setConflict(null)}>{conflict}</Alert>}
      {formAlert && <Alert tone={formAlert.tone} title={formAlert.title}>{formAlert.text}</Alert>}

      <div className="ws-grid">
        <div className="ws-stack">
          <section className="ws-card" aria-label="Parameter dan spesifikasi">
            <div className="ws-card__head">
              <h2 className="ws-card__title">Parameter &amp; spesifikasi</h2>
              <p className="ws-card__hint">{editing ? "Ubah nilai lalu simpan perubahan." : "Nilai saat ini dari backlog."}</p>
            </div>
            <form id="backlog-form" className="ws-card__body" noValidate onSubmit={submit} aria-label="Form edit backlog">
              <div className="ws-form-grid">
                <div className="ws-field ws-field--full">
                  <label className="ws-field__label" htmlFor="backlog-judul">Judul <span className="ws-field__req">*</span></label>
                  <input className="ws-input" type="text" {...fieldProps("judul")} />
                  {fieldError("judul")}
                </div>
                <div className="ws-field">
                  <label className="ws-field__label" htmlFor="backlog-modul">Modul <span className="ws-field__req">*</span></label>
                  <input className="ws-input" type="text" {...fieldProps("modul")} />
                  {fieldError("modul")}
                </div>
                <div className="ws-field">
                  <label className="ws-field__label" htmlFor="backlog-fitur">Fitur <span className="ws-field__req">*</span></label>
                  <input className="ws-input" type="text" {...fieldProps("fitur")} />
                  {fieldError("fitur")}
                </div>
                <div className="ws-field">
                  <label className="ws-field__label" htmlFor="backlog-severity">Severity <span className="ws-field__req">*</span></label>
                  <select className="ws-select" {...fieldProps("severity")}>
                    {!SEVERITIES.some(item => item === values.severity) && <option value={values.severity}>{values.severity || "Pilih severity"}</option>}
                    {SEVERITIES.map(item => <option key={item} value={item}>{item}</option>)}
                  </select>
                  {fieldError("severity")}
                </div>
                <div className="ws-field">
                  <label className="ws-field__label" htmlFor="backlog-priority_score">Skor prioritas <span className="ws-field__req">*</span></label>
                  <input className="ws-input" type="number" min={0} max={PRIORITY_SCALE} step={0.1} {...fieldProps("priority_score")} />
                  {fieldError("priority_score") ?? <p className="ws-field__help">{scoreHelp}</p>}
                </div>
                <div className="ws-field ws-field--full">
                  <label className="ws-field__label" htmlFor="backlog-ringkasan_masalah">Ringkasan masalah teknis <span className="ws-field__req">*</span></label>
                  <textarea className="ws-textarea" rows={5} maxLength={SUMMARY_MAX} {...fieldProps("ringkasan_masalah")} />
                  {fieldError("ringkasan_masalah")}
                  {editing && <p className="ws-field__help">{values.ringkasan_masalah.length}/{SUMMARY_MAX}</p>}
                </div>
                <div className="ws-field ws-field--full">
                  <label className="ws-field__label" htmlFor="backlog-recommendation">Rekomendasi</label>
                  <textarea className="ws-textarea" rows={4} {...fieldProps("recommendation")} />
                </div>
              </div>
            </form>
          </section>

          <section className="ws-card" aria-label="Referensi terkait">
            <div className="ws-card__head"><h2 className="ws-card__title">Referensi terkait</h2></div>
            <dl className="ws-card__body ws-info">
              <InfoRow label="Case terkait">
                <Link href={`/maintenance-case/${backlog.case_id}`}>{caseCode(backlog.case_id)}</Link>
              </InfoRow>
              <InfoRow label="Severity"><SeverityBadge severity={backlog.severity} /></InfoRow>
            </dl>
          </section>
        </div>

        <aside className="ws-stack" aria-label="Informasi tiket">
          <section className="ws-card">
            <div className="ws-card__head"><h2 className="ws-card__title">Informasi tiket</h2></div>
            <dl className="ws-card__body ws-info">
              <InfoRow label="ID tiket">{backlogCode(backlog.backlog_id)}</InfoRow>
              <InfoRow label="Status"><BacklogStatusPill status={backlog.status} /></InfoRow>
              <InfoRow label="Assignee">{backlog.assigned_to?.nama ?? "Belum ditugaskan"}</InfoRow>
              <InfoRow label="Dibuat">{formatDateTime(backlog.created_at)}</InfoRow>
              <InfoRow label="Terakhir diubah">{formatDateTime(backlog.updated_at)}</InfoRow>
            </dl>
          </section>
        </aside>
      </div>
    </div>
  );
}
