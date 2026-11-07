import Link from "next/link";
import { REVIEW_COLUMNS } from "@/lib/review-samples";

const TICKER = ["Unggah CSV", "Analisis sentimen", "Severity", "Skor prioritas", "Maintenance Case", "Backlog", "Maintenance Issue", "Pull request"];

const STEPS = [
  { no: "01", title: "Unggah CSV", text: "Tim Maintenance mengunggah file CSV berisi ulasan Tangerang LIVE: ID ulasan, teks, rating, dan tanggal. Formatnya dicek dulu." },
  { no: "02", title: "Jalankan analisis", text: "Sistem membaca sentimen tiap ulasan, mengelompokkan yang masalahnya mirip, lalu memberi severity dan skor prioritas." },
  { no: "03", title: "Tinjau case", text: "Hasilnya berupa kandidat Maintenance Case berstatus Pending beserta ulasan asalnya. Tim memutuskan: terima atau tolak." },
  { no: "04", title: "Kerjakan & ajukan PR", text: "Case yang diterima masuk backlog dan menjadi Maintenance Issue. Developer mengerjakannya, lalu mengajukan pull request ke repository lewat MaintainGov." },
];

const FEATURES = [
  "Severity dan skor prioritas untuk tiap case",
  "Ulasan yang membahas masalah sama digabung jadi satu case",
  "Tidak ada yang masuk backlog tanpa diperiksa tim",
];

// Contoh tampilan hasil; angka bukan data nyata.
const SAMPLE_CASES = [
  { id: "MC-018", title: "Login gagal setelah pembaruan", sev: "High", prio: "8.4", pct: 84, tone: "high" },
  { id: "MC-021", title: "OTP tidak terkirim", sev: "Medium", prio: "6.2", pct: 62, tone: "medium" },
  { id: "MC-019", title: "Permintaan fitur cetak dokumen", sev: "Low", prio: "3.1", pct: 31, tone: "low" },
];

function ArrowIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M5 12h14" />
      <path d="m13 6 6 6-6 6" />
    </svg>
  );
}

export default function LandingPage() {
  return (
    <div className="landing">
      <header className="landing__nav">
        <div className="landing__wrap landing__nav-row">
          <span className="landing__logo">Maintain<span className="landing__accent">Gov</span></span>
          <nav aria-label="Navigasi halaman" className="landing__links">
            <a href="#cara-kerja">Cara kerja</a>
            <a href="#fitur">Fitur</a>
            <a href="#untuk-siapa">Untuk siapa</a>
            <Link href="/login" className="landing__btn landing__btn--sm">Masuk</Link>
          </nav>
        </div>
      </header>

      <section className="landing__hero">
        <div className="landing__wrap landing__hero-row">
          <div className="landing__hero-copy">
            <h1 className="landing__title">
              <span className="landing__rise" style={{ animationDelay: ".15s" }}>Pantau keluhan,</span>{" "}
              <span className="landing__rise landing__accent" style={{ animationDelay: ".35s" }}>prioritaskan perbaikan.</span>
            </h1>
            <p className="landing__lead landing__rise" style={{ animationDelay: ".55s" }}>
              MaintainGov mengubah ulasan warga tentang Tangerang LIVE menjadi daftar perbaikan berprioritas untuk tim pengembang.
            </p>
            <div className="landing__cta landing__rise" style={{ animationDelay: ".75s" }}>
              <Link href="/login" className="landing__btn">Masuk ke aplikasi <ArrowIcon /></Link>
              <a href="#cara-kerja" className="landing__btn landing__btn--ghost">Lihat cara kerja</a>
            </div>
          </div>

          <div className="landing__stream" aria-hidden="true">
            {REVIEW_COLUMNS.slice(0, 2).map((col, i) => (
              <div key={i} className="landing__stream-col">
                <div className="landing__stream-track" style={{ animationDuration: col.speed }}>
                  {[...col.items, ...col.items].map((item, j) => (
                    <div key={j} className="landing__review">
                      <span className={`login__dot login__dot--${item.tone}`} />
                      {item.text}
                    </div>
                  ))}
                </div>
              </div>
            ))}
            <div className="landing__scan" />
            <div className="landing__float">
              <strong>MC-018 · Login gagal</strong>
              <small>high · prioritas 8.4 · Pending</small>
            </div>
          </div>
        </div>
        <svg className="landing__wave landing__wave--bottom" aria-hidden="true" viewBox="0 0 1440 120" preserveAspectRatio="none">
          <path d="M0 120 V70 C 240 10, 480 110, 720 64 S 1200 0, 1440 56 V120 Z" />
        </svg>
      </section>

      <div className="landing__ticker" aria-hidden="true">
        <div className="landing__ticker-track">
          {[...TICKER, ...TICKER].map((t, i) => (
            <span key={i}>{t}<i /></span>
          ))}
        </div>
      </div>

      <section id="cara-kerja" className="landing__wrap landing__section">
        <div className="landing__section-head landing__reveal">
          <h2 className="landing__h2">Cara kerja</h2>
          <p>Empat langkah dari file CSV sampai pull request.</p>
        </div>
        <div className="landing__steps">
          {STEPS.map((s) => (
            <article key={s.no} className="landing__card landing__reveal">
              <span className="landing__step-no">{s.no}</span>
              <h3>{s.title}</h3>
              <p>{s.text}</p>
            </article>
          ))}
        </div>
      </section>

      <section id="fitur" className="landing__wrap landing__section landing__feature">
        <div className="landing__feature-copy landing__reveal">
          <h2 className="landing__h2">Tahu keluhan mana yang <span className="landing__accent-dark">perlu dikerjakan duluan.</span></h2>
          <p>Setiap kandidat case punya kategori, severity, dan skor prioritas. Ulasan asal yang membentuknya tetap bisa dibuka, jadi tim bisa mengecek dulu sebelum menerima atau menolak.</p>
          <ul className="landing__checklist">
            {FEATURES.map((f, i) => (
              <li key={f}><span aria-hidden="true">{String(i + 1).padStart(2, "0")}</span>{f}</li>
            ))}
          </ul>
        </div>
        <div className="landing__preview landing__reveal">
          <div className="landing__preview-head"><span>batch #0042 · reviews.csv</span><span className="landing__done">● selesai</span></div>
          {SAMPLE_CASES.map((c) => (
            <div key={c.id} className="landing__case-row">
              <span className="landing__case-id">{c.id}</span>
              <span className="landing__case-title">{c.title}</span>
              <span className="landing__case-meter">
                <span className="landing__case-meta"><span>{c.sev}</span><span>{c.prio}</span></span>
                <span className="landing__track"><span className={`landing__bar landing__bar--${c.tone}`} style={{ width: `${c.pct}%` }} /></span>
              </span>
            </div>
          ))}
        </div>
      </section>

      <section id="untuk-siapa" className="landing__wrap landing__section">
        <h2 className="landing__h2 landing__reveal">Untuk siapa</h2>
        <div className="landing__roles">
          <article className="landing__card landing__card--dark landing__reveal">
            <p className="landing__role">Tim Maintenance</p>
            <h3>Memilah keluhan warga.</h3>
            <p>Unggah ulasan, jalankan analisis, periksa kandidat case beserta ulasan asalnya, lalu putuskan mana yang masuk backlog.</p>
          </article>
          <article className="landing__card landing__card--accent landing__reveal">
            <p className="landing__role">Tim Developer</p>
            <h3>Mengerjakan backlog yang sudah diurutkan.</h3>
            <p>Ambil Maintenance Issue sesuai prioritasnya, kerjakan, lalu ajukan pull request ke repository.</p>
          </article>
        </div>
      </section>

      <section className="landing__closing">
        <svg className="landing__wave landing__wave--top" aria-hidden="true" viewBox="0 0 1440 120" preserveAspectRatio="none">
          <path d="M0 0 V50 C 260 120, 520 10, 760 60 S 1220 120, 1440 40 V0 Z" />
        </svg>
        <div className="landing__wrap landing__closing-body landing__reveal">
          <h2 className="landing__closing-title">Siap memilah ulasan <span className="landing__accent">Tangerang LIVE?</span></h2>
          <Link href="/login" className="landing__btn landing__btn--lg">Masuk ke MaintainGov <ArrowIcon /></Link>
        </div>
        <footer className="landing__footer">
          <div className="landing__wrap landing__footer-row">
            <span>MaintainGov · Kelompok 5 · PPL</span>
            <span>Studi kasus: Tangerang LIVE, Pemerintah Kota Tangerang</span>
          </div>
        </footer>
      </section>
    </div>
  );
}
