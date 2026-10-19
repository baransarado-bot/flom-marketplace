# FLOM Marketplace UMKM/EKRAF Flores Timur

Marketplace web untuk UMKM dan ekonomi kreatif Flores Timur. Pemerintah Kabupaten mengelola admin pusat; penjual mengelola toko dan produknya sendiri.

## Teknologi

- PHP 8.1+ dan MySQL/MariaDB
- Antarmuka web responsif
- Aplikasi Android/iOS pendamping berbasis Capacitor

## Mulai mencoba

1. Siapkan PHP dengan ekstensi MySQLi dan database MySQL/MariaDB.
2. Buat database kosong, lalu impor `database_marketplace_hosting.sql` ke database tersebut. Skrip ini menggunakan kontak simulasi; atur kontak sebenarnya hanya pada lingkungan yang sesuai.
3. Atur koneksi database melalui variabel lingkungan `DINKOP_DB_HOST`, `DINKOP_DB_USER`, `DINKOP_DB_PASSWORD`, dan `DINKOP_DB_NAME`, atau file konfigurasi privat di luar document root. Jangan commit kredensial.
4. Arahkan document root web server ke folder aplikasi dan pastikan folder `uploads_*` dapat ditulisi untuk fitur unggah.
5. Buka `/setup_admin.php` untuk membuat akun admin pertama.

Panduan lengkap instalasi dan alur marketplace tersedia di [PETUNJUK_MARKETPLACE.md](./PETUNJUK_MARKETPLACE.md).

## Keamanan repository

File database lokal, akun uji, gambar unggahan, konfigurasi rahasia, berkas Firebase, arsip, dan dependensi hasil instalasi tidak disertakan. Gunakan data simulasi untuk lingkungan demo; jangan commit data pelanggan atau rekening.
