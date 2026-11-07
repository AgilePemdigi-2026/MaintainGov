import type { Metadata } from "next";
import localFont from "next/font/local";
import type { ReactNode } from "react";
import "./globals.css";

const font = localFont({
  src: "./fonts/plus-jakarta-sans-latin-wght-normal.woff2",
  weight: "200 800",
  style: "normal",
  display: "swap",
  variable: "--font-sans",
});

// Font utama seluruh aplikasi (lihat fonts/README.md).
const displayFont = localFont({
  src: "./fonts/bricolage-grotesque-latin-wght-normal.woff2",
  weight: "200 800",
  style: "normal",
  display: "swap",
  variable: "--font-display",
});

export const metadata: Metadata = {
  title: "MaintainGov",
  description: "Sistem pemeliharaan internal Super App Pemda",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="id" className={`${font.variable} ${displayFont.variable}`}>
      <body>{children}</body>
    </html>
  );
}
