"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";
import type { AuthUser } from "@/lib/types";
import type { FormEvent } from "react";
import {
  CheckIcon,
  EyeIcon,
  EyeOffIcon,
  LockIcon,
  MailIcon,
  ShieldCheckIcon,
} from "@/components/icons";

const FEATURES = [
  "Triase aduan warga menjadi tiket otomatis",
  "Backlog, issue, dan pull request dalam satu alur",
  "Review kode terintegrasi GitHub",
];

export default function LoginPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [remember, setRemember] = useState(true);

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const form = new FormData(e.currentTarget);
    setError("");
    setBusy(true);
    try {
      const result = await apiFetch<{ data: AuthUser }>("/auth/login", {
        method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ email: form.get("email"), password: form.get("password"), remember }),
      });
      router.replace(result.data.role === "Tim Maintenance" ? "/analisis-feedback" : "/maintenance-backlog");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Tidak dapat menghubungi server.");
    } finally { setBusy(false); }
  }

  return (
    <div className="login">
      <aside className="brand">
        <div className="brand__head">
          <span className="brand__logo">
            <ShieldCheckIcon size={20} />
          </span>
          <div>
            <div className="brand__name">MaintainGov</div>
            <div className="brand__tagline">Super App Pemda</div>
          </div>
        </div>

        <div className="brand__main">
          <h2 className="brand__title">Satu alur dari aduan warga sampai rilis.</h2>
          <p className="brand__lead">
            Sistem pemeliharaan internal untuk Tim Maintenance dan Tim Developer
            Pemerintah Kota.
          </p>
          <ul className="brand__list">
            {FEATURES.map((text) => (
              <li key={text}>
                <span className="brand__check">
                  <CheckIcon size={12} />
                </span>
                {text}
              </li>
            ))}
          </ul>
        </div>

        <div className="brand__foot">MaintainGov · Sprint 1</div>
      </aside>

      <main className="panel">
        <form className="form" onSubmit={handleSubmit} noValidate>
          <h1 className="form__title">Masuk</h1>
          <p className="form__lead">Gunakan akun dinas Anda untuk melanjutkan.</p>

          <div className="form__fields">
            <div className="field">
              <div className="field__row">
                <label className="field__label" htmlFor="email">
                  Email dinas
                </label>
              </div>
              <div className="input">
                <MailIcon />
                <input
                  id="email"
                  name="email"
                  type="email"
                  autoComplete="username"
                  placeholder="nama@pemkot.go.id"
                />
              </div>
            </div>

            <div className="field">
              <div className="field__row">
                <label className="field__label" htmlFor="password">
                  Kata sandi
                </label>
                <a className="link" href="#">
                  Lupa kata sandi?
                </a>
              </div>
              <div className="input">
                <LockIcon />
                <input
                  id="password"
                  name="password"
                  type={showPassword ? "text" : "password"}
                  autoComplete="current-password"
                  placeholder="••••••••"
                />
                <button
                  type="button"
                  className="input__toggle"
                  onClick={() => setShowPassword((v) => !v)}
                  aria-label={showPassword ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}
                >
                  {showPassword ? <EyeOffIcon /> : <EyeIcon />}
                </button>
              </div>
            </div>
          </div>

          <label className="remember">
            <input
              type="checkbox"
              checked={remember}
              onChange={(e) => setRemember(e.target.checked)}
            />
            <span className="remember__box">
              <CheckIcon size={12} />
            </span>
            Ingat perangkat ini selama 30 hari
          </label>

          {error && <p role="alert" style={{ color: "#b42318" }}>{error}</p>}
          <button type="submit" className="btn btn--primary" disabled={busy}>
            Masuk
          </button>

          <div className="divider">atau</div>

          {/* TODO: integrasi SSO Pemda belum ditetapkan; tombol hanya tampilan sesuai Figma. */}
          <button type="button" className="btn btn--outline" disabled title="SSO belum tersedia pada Sprint 1">
            <ShieldCheckIcon />
            Masuk dengan SSO Pemda
          </button>

          <p className="signup">
            Belum punya akun?<a href="#">Daftar</a>
          </p>

          <p className="meta">
            <a href="#">Bantuan</a>·<a href="#">Kebijakan privasi</a>·
            <a href="#">Status sistem</a>
          </p>
        </form>
      </main>
    </div>
  );
}
