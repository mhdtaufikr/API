# Trial manual kartu 2120

1. Buka Data Absensi → Tes dengan simulator.
2. Kartu dikunci ke 2120. Ubah tanggal/jam WIB, detik, terminal, atau kode arah. Raw Card Message mengikuti otomatis dan tidak diedit manual.
3. Lihat hasil parsing hanya memeriksa format. Simpan simulasi menyimpan tap lokal, lalu mencoba validasi/pengolahan dan menyiapkan antrean. Tidak ada request Talenta dari endpoint simulator.
4. Buka Data Absensi → Antrean kirim ke Talenta. Tap kartu 2120 yang belum diklaim batch tampil sebagai Menunggu validasi. Data yang sudah lolos memiliki tombol Review & kirim manual.
5. Pada halaman review, periksa identitas dan CSV, ketik konfirmasi SEND NIK yang diminta, centang persetujuan, lalu Kirim sekarang ke Talenta. Ini tidak menunggu atau mengaktifkan Windows Task Scheduler.
6. Cek hasil melalui Cek di Talenta. HTTP diterima belum berarti import selesai. Data yang pernah dicoba tidak dikirim ulang otomatis.

## Batas keamanan

- Nomor kartu 2120 diperiksa ulang di server sebelum penyimpanan. Mengubah readonly field/JavaScript tidak mengizinkan kartu lain.
- Endpoint simulator terpisah memakai POST, CSRF, pembatas localhost, izin simulator/receiver, serta allowlist receiver. Protokol receiver mesin tetap GET dan tidak dibatasi hanya kartu 2120.
- Jangan menganggap kartu = NIK = user ID = badge. Mapping harus eksplisit dan terverifikasi. Hanya NIK trial yang dikonfigurasi dapat masuk antrean review.
- Kode arah mesin tidak ditebak sebagai masuk/pulang. Shift, kebijakan, kelengkapan tap, dan proteksi absensi harus lolos. Antrean manual saat ini memakai validasi shift penuh; satu tap yang belum lengkap bisa tetap menunggu validasi.
- Simulator tidak mengaktifkan DRY_RUN=false, ALLOW_PRODUCTION_WRITE, atau konfirmasi profil CSV. Tombol kirim aktif hanya jika seluruh syarat live yang sudah ada terpenuhi.
- Pada database production `mkm_ssmdb`, insert trial memerlukan `LEGACY_LOCAL_WRITE=true` dan `LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED=true`. Pengecualian ini hanya berlaku untuk request localhost simulator kartu 2120 dengan FcDirection satu digit; receiver mesin fisik dan pengiriman Talenta tetap terkunci.
- Bila mapping untuk NIK trial juga mencakup kartu selain 2120, simulator menahan pengolahan otomatis. Admin perlu meninjau identitas tersebut.
- Mode legacy_config hanya memasang tbl_config. Simulasi penyimpanan legacy tetap nonaktif sampai adapter attendance legacy tersedia; tidak membuat tabel tambahan otomatis.

Implementasi lokal diuji dengan replay tap yang sudah ada (duplicate), penolakan kartu lain, penolakan CSRF salah, dan perubahan tanggal/jam di browser. Tidak ada pengiriman live dalam pemeriksaan ini.
