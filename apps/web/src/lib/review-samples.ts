// Contoh ulasan untuk ilustrasi di halaman login dan landing; bukan data dari API.
export type ReviewSample = { text: string; tone: "neg" | "pos" | "neu" };

export const REVIEW_COLUMNS: { speed: string; items: ReviewSample[] }[] = [
  {
    speed: "26s",
    items: [
      { text: "Gak bisa login sejak pembaruan.", tone: "neg" },
      { text: "Sekarang lebih cepat, mantap.", tone: "pos" },
      { text: "OTP tidak pernah masuk ke HP saya.", tone: "neg" },
      { text: "Mohon tambah fitur cetak dokumen.", tone: "neu" },
      { text: "Foto KTP gagal diunggah terus.", tone: "neg" },
    ],
  },
  {
    speed: "34s",
    items: [
      { text: "Menu pajak lama sekali dibuka.", tone: "neg" },
      { text: "Tampilan baru lebih rapi.", tone: "pos" },
      { text: "Aplikasi keluar sendiri saat bayar.", tone: "neg" },
      { text: "Tolong ada mode gelap.", tone: "neu" },
      { text: "Lupa password tapi email tidak terkirim.", tone: "neg" },
    ],
  },
  {
    speed: "30s",
    items: [
      { text: "Antrean online sangat membantu.", tone: "pos" },
      { text: "Login pakai NIK error terus.", tone: "neg" },
      { text: "Notifikasi telat sehari.", tone: "neg" },
      { text: "Bisa tambah bahasa daerah?", tone: "neu" },
      { text: "Pelayanan cepat, terima kasih.", tone: "pos" },
    ],
  },
];
