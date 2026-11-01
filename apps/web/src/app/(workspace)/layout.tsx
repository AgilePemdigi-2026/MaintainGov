"use client";
// Layout bersama halaman Sprint 2. Gaya dipisah di workspace.css agar tidak bentrok
// dengan perubahan globals.css/layout.tsx pada PR Sprint 1 (#20).
import type { ReactNode } from "react";
import AppShell from "@/components/workspace/app-shell";
import { SessionProvider } from "@/components/workspace/session";
import "./workspace.css";

export default function WorkspaceLayout({ children }: { children: ReactNode }) {
  return <SessionProvider>{() => <AppShell>{children}</AppShell>}</SessionProvider>;
}
