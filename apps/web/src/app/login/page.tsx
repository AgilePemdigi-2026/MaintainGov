"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";
import type { FormEvent } from "react";
import { CheckIcon, EyeIcon, EyeOffIcon } from "@/components/icons";
import { REVIEW_COLUMNS } from "@/lib/review-samples";

// Durasi animasi "whoosh" setelah login berhasil sebelum pindah halaman.
const ENTER_DELAY_MS = 1700;

function prefersReducedMotion() {
  return typeof window !== "undefined" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

export default function LoginPage() {
  const router = useRouter();
  const submitting = useRef(false);
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(true);
  const [entered, setEntered] = useState<AuthUser | null>(null);

  useEffect(() => () => clearTimeout(timer.current), []);

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submitting.current) return;
    submitting.current = true;
    const form = new FormData(e.currentTarget);
    setError("");
    setBusy(true);
    try {
      const result = await apiFetch<{ data: AuthUser }>("/auth/login", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: form.get("email"), password: form.get("password"), remember }),
      });
      const target = result.data.role === "Tim Maintenance" ? "/analisis-feedback" : "/maintenance-backlog";
      setEntered(result.data);
      timer.current = setTimeout(() => router.replace(target), prefersReducedMotion() ? 0 : ENTER_DELAY_MS);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Tidak dapat menghubungi server.");
      submitting.current = false;
      setBusy(false);
    }
  }

  return (
    <div className="login">
      <section className="login__hero" aria-label="MaintainGov">
        <div className="login__stream" aria-hidden="true">
          {REVIEW_COLUMNS.map((col, i) => (
            <div key={i} className="login__stream-col">
              <div className="login__stream-track" style={{ animationDuration: col.speed }}>
                {[...col.items, ...col.items].map((item, j) => (
                  <div key={j} className="login__review">
                    <span className={`login__dot login__dot--${item.tone}`} />
                    {item.text}
                  </div>
                ))}
              </div>
            </div>
          ))}
        </div>
        <div className="login__scan" aria-hidden="true" />
        <div className="login__cases" aria-hidden="true">
          <div className="login__case login__case--a">
            MC-018 · Login gagal<small>high · 14 ulasan</small>
          </div>
          <div className="login__case login__case--b">
            MC-021 · OTP tidak terkirim<small>medium · 6 ulasan</small>
          </div>
        </div>
        <div className="login__brand">
          <h2 className="login__wordmark">
            <span className="login__rise" style={{ animationDelay: "1.5s" }}>Maintain</span>
            <span className="login__rise login__accent" style={{ animationDelay: "1.65s" }}>Gov</span>
          </h2>
          <p className="login__slogan login__rise" style={{ animationDelay: "1.85s" }}>
            Pantau keluhan, prioritaskan perbaikan.
          </p>
        </div>
      </section>

      <main className="login__panel">
        <svg className="login__curve" aria-hidden="true" viewBox="0 0 140 800" preserveAspectRatio="none">
          <path d="M140 0 H96 C 20 110, 132 250, 70 400 S 4 650, 86 800 H140 Z" />
        </svg>
        <form className="login__form" onSubmit={handleSubmit}>
          <div>
            <h1 className="login__title">Masuk</h1>
            <p className="login__lead">Gunakan akun dinas Anda untuk melanjutkan.</p>
          </div>

          <div className="login__field">
            <label htmlFor="email">Email dinas</label>
            <input
              id="email"
              name="email"
              className="login__input"
              required
              type="email"
              autoComplete="username"
              placeholder="nama@instansi.go.id"
            />
          </div>

          <div className="login__field">
            <label htmlFor="password">Kata sandi</label>
            <div className="login__password">
              <input
                id="password"
                name="password"
                className="login__input"
                required
                type={showPassword ? "text" : "password"}
                autoComplete="current-password"
              />
              <button
                type="button"
                className="login__toggle"
                onClick={() => setShowPassword((v) => !v)}
                aria-label={showPassword ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}
              >
                {showPassword ? <EyeOffIcon /> : <EyeIcon />}
              </button>
            </div>
          </div>

          <label className="login__remember">
            <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />
            Ingat perangkat ini
          </label>

          {error && <p role="alert" className="login__error">{error}</p>}
          <button type="submit" className="login__submit" disabled={busy}>
            {busy && !entered ? "Memeriksa…" : "Masuk"}
            <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
              <path d="M5 12h14" />
              <path d="m13 6 6 6-6 6" />
            </svg>
          </button>

          <p className="login__signup">
            Belum punya akun? <a href="#">Daftar</a>
          </p>
        </form>
      </main>

      {/* Intro saat halaman dibuka: layar MaintainGov disapu keluar. */}
      <div className="login__intro login__intro--flash" aria-hidden="true" />
      <div className="login__intro" aria-hidden="true">
        <span className="login__intro-mark">
          <span className="login__rise" style={{ animationDelay: ".1s" }}>Maintain</span>
          <span className="login__rise login__accent" style={{ animationDelay: ".25s" }}>Gov</span>
        </span>
        <span className="login__bar"><span className="login__bar-fill" /></span>
      </div>

      {entered && (
        <>
          <div className="login__enter login__enter--flash" aria-hidden="true" />
          <div className="login__enter">
            <p className="login__welcome login__rise" style={{ animationDelay: ".6s" }}>
              Selamat datang<span className="login__accent">.</span>
            </p>
            <span className="login__bar login__rise" style={{ animationDelay: ".75s" }}>
              <span className="login__bar-fill" style={{ animationDelay: ".85s", animationDuration: "1s" }} />
            </span>
          </div>
          <div role="status" className="login__toast">
            <span className="login__toast-icon" aria-hidden="true"><CheckIcon size={16} /></span>
            <span>
              <strong>Berhasil masuk</strong>
              <small>{entered.nama} · {entered.role}</small>
            </span>
          </div>
        </>
      )}
    </div>
  );
}
