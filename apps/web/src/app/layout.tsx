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

export const metadata: Metadata = {
  title: "MaintainGov",
  description: "Sistem pemeliharaan internal Super App Pemda",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="id" className={font.variable}>
      <body>{children}</body>
    </html>
  );
}
