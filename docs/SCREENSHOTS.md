# Catatan screenshot

Gambar diambil langsung dari Chrome headless pada Windows saat aplikasi dijalankan dengan PHP 8.5.12 CLI NTS. Gambar tidak digambar ulang atau disusun sebagai mockup. Nama akun `qa_customer`/`qa_operator` dan pesanan adalah fixture sintetis; password, cookie, token, dan snapshot JSON tidak disertakan.

| File | Kondisi pengambilan |
| --- | --- |
| [`catalogue-desktop.png`](screenshots/catalogue-desktop.png) | Viewport 1440 × 1000 CSS px, capture halaman penuh; pelanggan mempunyai dua pesanan dan membuka invoice pertama. Katalog sudah dimuat ulang setelah pengujian refresh gagal, sedangkan receipt tetap tersimpan. |
| [`mobile-dark.png`](screenshots/mobile-dark.png) | Viewport 390 × 844 CSS px, bagian atas halaman dengan tema gelap; reflow katalog dan pesanan pada halaman penuh turut diuji. |
| [`admin-summary.png`](screenshots/admin-summary.png) | Capture elemen ringkasan setelah admin dari CLI login melalui form; angka berasal dari data fixture yang sama, bukan teks statis. |

Capture dilakukan sesudah pemeriksaan browser terhadap response checkout yang hilang, retry dengan key sama, kegagalan refresh, respons invoice terlambat, dan teks produk yang berisi markup. Data probe dipulihkan sebelum capture. Hash dan ukuran gambar tercatat dalam [`screenshot-provenance.json`](screenshot-provenance.json).

Gambar membuktikan keadaan yang ditampilkan pada pengujian tersebut. Pembayaran, pengiriman, server produksi, perangkat ponsel fisik, dan semua kombinasi teknologi bantu tidak diuji.
