# Latest instruction: read-only legacy audit

Panduan terbaru: [LEGACY_COMPATIBILITY.md](LEGACY_COMPATIBILITY.md). .env.example sekarang menggunakan legacy_readonly: tidak menjalankan migrate/seed atau membuat tbl_config di production. Instruksi migration di bawah hanya arsip mode sebelumnya, bukan langkah audit yang aktif.

# Pemasangan pada database existing mkm_ssmdb

Gunakan `.env.example` terbaru untuk mode **legacy_config**. Contoh untuk pengembangan terpisah disimpan di `.env.local.example`. Jangan mengganti `.env` server/aplikasi lain; buat file ini hanya di folder aplikasi baru. `.env` lokal yang berisi credential saat ini tidak diubah.

## Langkah di server

1. Backup database dan aplikasi existing terlebih dahulu.
2. Salin kode ke folder aplikasi baru, jangan overwrite receiver/BAT yang masih aktif. Jangan membawa `.env`, storage/ledger, atau approval trial dari laptop.
3. Copy `.env.example` menjadi `.env` pada folder baru. Isi DB_USERNAME dan DB_PASSWORD langsung di server. Jika aplikasi dan MySQL sama-sama berada di server 172.17.210.228, DB_HOST=127.0.0.1 sudah benar. Jangan mengubah konfigurasi MySQL/Apache existing.
4. Pastikan nilai berikut tetap terpasang:

```ini
INTEGRATION_STORAGE=legacy_config
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mkm_ssmdb
DRY_RUN=true
ALLOW_PRODUCTION_WRITE=false
TALENTA_SCHEDULER_ENABLED=false
MACHINE_RECEIVER_ENABLED=false
```

5. Dari folder aplikasi baru, jalankan:

```bat
C:\xampp\php\php.exe bin\console.php migrate
C:\xampp\php\php.exe bin\console.php seed
```

PHP minimum 8.2 dengan pdo_mysql dan openssl. MySQL/MariaDB harus mendukung JSON. Tidak ada pembuatan database otomatis dalam mode ini.

## Apa yang dijalankan?

- `migrate`: memeriksa tabel `tbl_config`. Jika belum ada, hanya menjalankan CREATE TABLE untuk tabel itu. Tidak menjalankan migration 001–003, tidak membuat schema_version, dan tidak ALTER/UPDATE/DELETE tabel attendance.
- Jika tabel konfigurasi milik aplikasi sudah tersedia dan strukturnya cocok, migration selesai tanpa mengubah isi tabel. Jika ada tabel dengan nama sama yang bukan milik aplikasi atau berbeda struktur, migration berhenti. Jangan menghapus tabel itu untuk memaksa lanjut.
- `seed`: membuat profil Talenta kosong pada `tbl_config`, serta APP_KEY pada `.env` aplikasi baru jika belum ada. Profil/credential yang sudah ada tidak ditimpa. Tidak melakukan seed status/shift/attendance.
- Client ID, Client Secret, Import Token terenkripsi berada pada `tbl_config`. Riwayat perubahan konfigurasi juga berada di kolom audit_history tabel yang sama, tanpa nilai rahasia; tidak perlu tabel audit tambahan.
- `tbl_rawdata`, `tbl_absen`, `tbl_log` tetap utuh. Tidak ada penulisan FlFlag atau perubahan kode arah.

Backup database bersama `.env` dan APP_KEY. Jangan mengganti APP_KEY setelah credential disimpan. Aplikasi tidak dapat membuat Import Token Mekari.

## Pengaturan akun

Buka dari browser di server: `http://localhost/<folder-aplikasi-baru>/public/settings.php`. Isi credential lewat menu Pengaturan. Credential yang diisi di tabel menjadi sumber utama; kolom MEKARI_* di `.env` boleh kosong. Setelah seed profil kosong, nilai `.env` tidak otomatis diimpor ke tabel.

Empat menu: Ringkasan, Data Absensi, Cek di Talenta, Pengaturan. Pengelolaan hanya dari localhost; belum ada login untuk membuka dashboard ke jaringan. Jangan menghapus pembatas localhost.

## Batas pekerjaan ini

Mode ini menyiapkan **penyimpanan konfigurasi saja**. Adapter pembaca `tbl_rawdata` dan pengiriman scheduler dari database legacy belum selesai dihubungkan. Halaman data mesin/engine yang lama masih memerlukan schema integrasi penuh; jangan menjalankan migration penuh untuk mengatasi pesan tersebut pada mkm_ssmdb.

Selanjutnya perlu inspeksi read-only struktur asli, verifikasi arti FcDirection/FlFlag, pemetaan kartu ke Talenta, dan profil CSV. Gambar saja belum memastikan tipe kolom atau arti flag. Jangan mengaktifkan receiver, mengganti BAT existing, atau mengaktifkan pengiriman terjadwal sebelum adapter dan uji satu karyawan selesai. Jadwal yang dituju tetap 08.00, 12.00, 18.00 WIB.

Mode `integration` untuk database integrasi terpisah tetap tersedia melalui `.env.local.example`. Pada mode itu migration memang membuat banyak tabel. Karena itu pastikan INTEGRATION_STORAGE=legacy_config sebelum menjalankan perintah di database existing.