"use client";
// Header + sidebar halaman Sprint 2 (komponen Figma "Header" 1280x56 dan "Aside" 260px).
import { useState } from "react";
import type { ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ShieldCheckIcon } from "@/components/icons";
import { isMockMode } from "@/lib/sprint2/client";
import { MOCK_ROLE_KEY } from "@/lib/sprint2/mock";
import { useSession } from "./session";

const NAV: { group: string; items: { href: string; label: string; roles: string[] }[] }[] = [
  {
    group: "Tim Maintenance",
    items: [
      { href: "/analisis-feedback", label: "Analisis Feedback", roles: ["Tim Maintenance"] },
      { href: "/maintenance-case", label: "Maintenance Case", roles: ["Tim Maintenance"] },
      { href: "/maintenance-backlog", label: "Maintenance Backlog", roles: ["Tim Maintenance", "Developer"] },
    ],
  },
  {
    group: "Tim Developer",
    items: [{ href: "/maintenance-issue", label: "Maintenance Issue", roles: ["Developer"] }],
  },
];

function initials(name: string) {
  return name.split(/\s+/).slice(0, 2).map(part => part[0]?.toUpperCase() ?? "").join("");
}

export default function AppShell({ children }: { children: ReactNode }) {
  const { user, logout, handleError } = useSession();
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function onLogout() {
    if (busy) return;
    setBusy(true);
    try { await logout(); } catch (e) { setError(handleError(e, "Logout gagal.")); setBusy(false); }
  }

  function switchMockRole(role: "maintenance" | "developer") {
    try { localStorage.setItem(MOCK_ROLE_KEY, role); } catch { /* abaikan */ }
    window.location.reload();
  }

  return (
    <div className="ws">
      <header className="ws-header">
        <div className="ws-header__brand">
          <span className="ws-header__logo"><ShieldCheckIcon size={18} /></span>
          <span>
            <span className="ws-header__name">MaintainGov</span>
            <span className="ws-header__tag">Sistem Pemeliharaan Internal · Super App Pemda</span>
          </span>
        </div>
        <div className="ws-header__right">
          <button type="button" className="ws-header__menu" aria-label="Buka navigasi" aria-expanded={menuOpen} onClick={() => setMenuOpen(open => !open)}>☰</button>
          <span className="ws-avatar" aria-hidden="true">{initials(user.nama)}</span>
          <span className="ws-header__user">
            <span className="ws-header__uname">{user.nama}</span>
            <span className="ws-header__role">{user.role}</span>
          </span>
          <button type="button" className="ws-btn ws-btn--ghost-light" disabled={busy} onClick={onLogout}>Keluar</button>
        </div>
      </header>
      <div className="ws-body">
        <aside className={`ws-aside${menuOpen ? " ws-aside--open" : ""}`} aria-label="Navigasi utama">
          {NAV.map(section => {
            const items = section.items.filter(item => item.roles.includes(user.role));
            if (!items.length) return null;
            return (
              <div key={section.group} className="ws-nav">
                <p className="ws-nav__group">{section.group}</p>
                {items.map(item => {
                  const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
                  return (
                    <Link key={item.href} href={item.href} className={`ws-nav__item${active ? " ws-nav__item--active" : ""}`}
                      aria-current={active ? "page" : undefined} onClick={() => setMenuOpen(false)}>
                      {item.label}
                    </Link>
                  );
                })}
              </div>
            );
          })}
          <div className="ws-aside__foot">
            {isMockMode && (
              <div className="ws-mock">
                <p className="ws-mock__title">Mode data contoh (mock)</p>
                <p>Bukan bukti integrasi live.</p>
                <div className="ws-mock__roles">
                  <button type="button" className="ws-btn ws-btn--outline ws-btn--sm" onClick={() => switchMockRole("maintenance")}>Maintenance</button>
                  <button type="button" className="ws-btn ws-btn--outline ws-btn--sm" onClick={() => switchMockRole("developer")}>Developer</button>
                </div>
              </div>
            )}
            <p>MaintainGov · Sprint 2</p>
          </div>
        </aside>
        <main className="ws-main">
          {error && <p role="alert" className="ws-inline-error">{error}</p>}
          {children}
        </main>
      </div>
    </div>
  );
}
