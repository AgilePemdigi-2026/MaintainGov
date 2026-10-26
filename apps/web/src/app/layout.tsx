
import type { Metadata } from "next";
import { Plus_Jakarta_Sans } from "next/font/google";
import type { ReactNode } from "react";
import "./globals.css";

const font = Plus_Jakarta_Sans({
  subsets: ["latin"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-sans",
});

export const metadata: Metadata = {
  title: "MaintainGov",
  description: "Sistem pemeliharaan internal Super App Pemda",
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="id" className={font.variable}>
      <body className={font.className}>{children}</body>
    </html>
  );
}
