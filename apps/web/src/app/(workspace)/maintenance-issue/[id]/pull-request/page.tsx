"use client";
// UC05 (Feature 7a): Form Pull Request. Token GitHub tidak pernah ada di browser;
// backend yang membuat PR lalu mengembalikan nomor dan URL.
import { useEffect, useRef, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ApiError } from "@/lib/api";
import { RoleGate, useSession } from "@/components/workspace/session";
import { Alert, EmptyState, ErrorState, InfoRow, PageHeader, SkeletonRows } from "@/components/workspace/ui";
import { sprint2Api } from "@/lib/sprint2/client";
import { MESSAGES, backlogCode } from "@/lib/sprint2/labels";
import type { MaintenanceBacklog, PullRequestInfo, PullRequestOptions } from "@/lib/sprint2/types";

type FieldErrors = Partial<Record<"target" | "judul" | "deskripsi", string>>;

function PullRequestForm() {
  const { handleError } = useSession();
  const params = useParams<{ id: string }>();
  const id = Number(params?.id);
  const validId = Number.isInteger(id) && id > 0;
  const code = validId ? backlogCode(id) : "";
  const back = `/maintenance-issue/${validId ? id : ""}`;

  const [issue, setIssue] = useState<MaintenanceBacklog | null>(null);
  const [options, setOptions] = useState<PullRequestOptions | null>(null);
  const [loading, setLoading] = useState(validId);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [notFound, setNotFound] = useState(!validId);
  const [notReady, setNotReady] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const [target, setTarget] = useState("");
  const [judul, setJudul] = useState("");
  const [deskripsi, setDeskripsi] = useState(`Terkait issue ${code}\n\nRingkasan perubahan:\n`);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [formAlert, setFormAlert] = useState<{ title: string; text?: string } | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const submitRef = useRef(false);
  const [created, setCreated] = useState<PullRequestInfo | null>(null);

  useEffect(() => {
    if (!validId) return;
    let active = true;
    setLoading(true);
    setLoadError(null);
    setNotReady(false);
    (async () => {
      const data = await sprint2Api.getIssue(id);
      if (!active) return;
      setIssue(data);
      const ready = data.status === "Ready for Pull Request" && !!data.last_push;
      if (!ready || data.pull_request) { setOptions(null); return; }
      try {
        const opts = await sprint2Api.getPullRequestOptions(id);
        if (!active) return;
        setOptions(opts);
        setTarget(current => current || opts.default_target || "");
      } catch (err) {
        if (err instanceof ApiError && err.status === 409) { if (active) setNotReady(true); return; }
        throw err;
      }
    })()
      .catch(err => {
        if (!active) return;
        if (err instanceof ApiError && err.status === 404) setNotFound(true);
        else setLoadError(handleError(err, "Data pull request tidak dapat dimuat."));
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [id, validId, reloadKey, handleError]);

  const reload = () => setReloadKey(key => key + 1);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (submitRef.current || !options) return;
    const errors: FieldErrors = {};
    if (!target) errors.target = "Pilih branch tujuan.";
    if (!judul.trim()) errors.judul = "Judul pull request wajib diisi.";
    if (!deskripsi.trim()) errors.deskripsi = "Deskripsi perubahan wajib diisi.";
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      setFormAlert({ title: MESSAGES.prIncomplete, text: "Branch tujuan, judul, dan deskripsi wajib diisi." });
      return;
    }
    submitRef.current = true;
    setSubmitting(true);
    setFormAlert(null);
    try {
      setCreated(await sprint2Api.createPullRequest(id, { target_branch: target, judul: judul.trim(), deskripsi: deskripsi.trim() }));
    } catch (err) {
      if (err instanceof ApiError && (err.status === 502 || err.status === 503)) {
        setFormAlert({ title: MESSAGES.githubDown, text: "Issue tetap berstatus Ready for Pull Request. Isian Anda dipertahankan, coba lagi beberapa saat lagi." });
      } else if (err instanceof ApiError && err.status === 422) {
        setFormAlert({ title: MESSAGES.prIncomplete, text: err.message });
      } else if (err instanceof ApiError && err.status === 409) {
        setFormAlert({ title: "Status issue berubah", text: err.message });
        reload();
      } else {
        setFormAlert({ title: "Pull request gagal dibuat", text: handleError(err, "Pull request tidak dapat dibuat. Coba lagi.") });
      }
    } finally {
      submitRef.current = false;
      setSubmitting(false);
    }
  };

  const crumbs = [
    { label: "Tim Developer" },
    { label: "Maintenance Issue", href: "/maintenance-issue" },
    ...(validId ? [{ label: code, href: back }] : []),
    { label: "Buat pull request" },
  ];

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
  if (loading && !issue) return <div className="ws-content"><SkeletonRows rows={4} /></div>;
  if (loadError && !issue) return <div className="ws-content"><ErrorState message={loadError} onRetry={reload} /></div>;

  const existing = created ?? issue?.pull_request ?? null;
  const ready = !!issue && issue.status === "Ready for Pull Request" && !!issue.last_push;

  return (
    <div className="ws-content">
      <PageHeader
        crumbs={crumbs}
        title="Buat Pull Request"
        meta={`${options?.repository ?? "repository"} · terkait ${code}`}
      />
      {created && <Alert tone="success" title={MESSAGES.prCreated} />}
      {existing ? (
        <section className="ws-card" aria-label="Pull request">
          <div className="ws-card__head"><h2 className="ws-card__title">Pull request</h2></div>
          <div className="ws-card__body">
            <dl className="ws-info">
              <InfoRow label="Status">{existing.status}</InfoRow>
              <InfoRow label="Nomor">#{existing.github_pr_number}</InfoRow>
              <InfoRow label="Judul">{existing.judul}</InfoRow>
              <InfoRow label="Branch">{existing.source_branch} → {existing.target_branch}</InfoRow>
              <InfoRow label="GitHub"><a href={existing.url} target="_blank" rel="noreferrer">{existing.url}</a></InfoRow>
            </dl>
            <div className="ws-form-actions">
              <Link className="ws-btn ws-btn--outline" href={back}>Kembali ke issue</Link>
            </div>
          </div>
        </section>
      ) : !ready || notReady || !options ? (
        <>
          <Alert tone="warning" title="Issue belum siap diajukan sebagai pull request.">
            Pull request hanya dapat dibuat saat status Ready for pull request setelah push terverifikasi dari GitHub.
          </Alert>
          <div><Link className="ws-btn ws-btn--outline" href={back}>Kembali ke issue</Link></div>
        </>
      ) : (
        <form className="ws-stack" onSubmit={submit} noValidate>
          {formAlert && <Alert tone="error" title={formAlert.title} onClose={() => setFormAlert(null)}>{formAlert.text}</Alert>}
          <section className="ws-card" aria-label="Branch">
            <div className="ws-card__head"><h2 className="ws-card__title">Branch</h2></div>
            <div className="ws-card__body">
              <div className="ws-form-grid">
                <div className="ws-field">
                  <label className="ws-field__label" htmlFor="pr-source">Branch sumber (HEAD)</label>
                  <input id="pr-source" className="ws-input" value={options.source_branch} readOnly />
                  <p className="ws-field__help">Terkunci: branch berasal dari push terverifikasi.</p>
                </div>
                <div className="ws-field">
                  <label className="ws-field__label" htmlFor="pr-target">Branch tujuan (BASE) <span className="ws-field__req">wajib</span></label>
                  <select
                    id="pr-target"
                    className="ws-select"
                    value={target}
                    required
                    aria-invalid={!!fieldErrors.target}
                    onChange={event => setTarget(event.target.value)}
                  >
                    <option value="">Pilih branch tujuan</option>
                    {options.target_branches.map(branch => <option key={branch} value={branch}>{branch}</option>)}
                  </select>
                  {fieldErrors.target && <p className="ws-field__error">{fieldErrors.target}</p>}
                </div>
              </div>
            </div>
          </section>
          <section className="ws-card" aria-label="Detail pull request">
            <div className="ws-card__head"><h2 className="ws-card__title">Detail pull request</h2></div>
            <div className="ws-card__body">
              <div className="ws-form-grid">
                <div className="ws-field ws-field--full">
                  <label className="ws-field__label" htmlFor="pr-judul">Judul pull request <span className="ws-field__req">wajib</span></label>
                  <input
                    id="pr-judul"
                    className="ws-input"
                    value={judul}
                    required
                    aria-invalid={!!fieldErrors.judul}
                    onChange={event => setJudul(event.target.value)}
                  />
                  <p className="ws-field__help">Format: &lt;type&gt;(&lt;scope&gt;): &lt;deskripsi singkat&gt;</p>
                  {fieldErrors.judul && <p className="ws-field__error">{fieldErrors.judul}</p>}
                </div>
                <div className="ws-field ws-field--full">
                  <label className="ws-field__label" htmlFor="pr-deskripsi">Deskripsi perubahan <span className="ws-field__req">wajib</span></label>
                  <textarea
                    id="pr-deskripsi"
                    className="ws-textarea"
                    rows={8}
                    value={deskripsi}
                    required
                    aria-invalid={!!fieldErrors.deskripsi}
                    onChange={event => setDeskripsi(event.target.value)}
                  />
                  <p className="ws-field__help">Markdown didukung</p>
                  {fieldErrors.deskripsi && <p className="ws-field__error">{fieldErrors.deskripsi}</p>}
                </div>
              </div>
            </div>
          </section>
          <div className="ws-form-actions">
            <Link className="ws-btn ws-btn--outline" href={back}>Batal</Link>
            <button type="submit" className="ws-btn ws-btn--primary" disabled={submitting}>
              {submitting ? "Memproses..." : "Buat pull request"}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}

export default function PullRequestPage() {
  return <RoleGate role="Developer"><PullRequestForm /></RoleGate>;
}
