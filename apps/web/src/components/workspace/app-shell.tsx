"use client";
// Header (logo + menu pil) dan footer halaman dalam, gaya yang sama dengan landing dan login.
import { useState } from "react";
import type { ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
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

  const navItems = NAV.flatMap(section => section.items).filter(item => item.roles.includes(user.role));

  return (
    <div className="ws">
      <header className="ws-header">
        <div className="ws-header__left">
          <Link href="/" className="ws-header__logo">Maintain<span>Gov</span></Link>
          <button type="button" className="ws-header__menu" aria-label="Buka navigasi" aria-expanded={menuOpen} aria-controls="ws-nav" onClick={() => setMenuOpen(open => !open)}>☰</button>
          <nav id="ws-nav" aria-label="Navigasi utama" className={`ws-nav${menuOpen ? " ws-nav--open" : ""}`}>
            {navItems.map(item => {
              const active = pathname === item.href || pathname.startsWith(`${item.href}/`);
              return (
                <Link key={item.href} href={item.href} className={`ws-nav__item${active ? " ws-nav__item--active" : ""}`}
                  aria-current={active ? "page" : undefined} onClick={() => setMenuOpen(false)}>
                  {item.label}
                </Link>
              );
            })}
          </nav>
        </div>
        <div className="ws-header__right">
          <span className="ws-avatar" aria-hidden="true">{initials(user.nama)}</span>
          <span className="ws-header__user">
            <span className="ws-header__uname">{user.nama}</span>
            <span className="ws-header__role">{user.role}</span>
          </span>
          <button type="button" className="ws-btn ws-btn--ghost-light" disabled={busy} onClick={onLogout}>Keluar</button>
        </div>
      </header>
      {isMockMode && (
        <div className="ws-mock">
          <p><strong>Mode data contoh (mock).</strong> Bukan bukti integrasi live.</p>
          <div className="ws-mock__roles">
            <button type="button" className="ws-btn ws-btn--outline ws-btn--sm" onClick={() => switchMockRole("maintenance")}>Maintenance</button>
            <button type="button" className="ws-btn ws-btn--outline ws-btn--sm" onClick={() => switchMockRole("developer")}>Developer</button>
          </div>
        </div>
      )}
      <main className="ws-main">
        {error && <p role="alert" className="ws-inline-error">{error}</p>}
        {children}
      </main>
      <footer className="ws-footer">
        <span>MaintainGov · Kelompok 5 · PPL</span>
        <span>Studi kasus: Tangerang LIVE, Pemerintah Kota Tangerang</span>
      </footer>
    </div>
  );
}
