# Petunjuk Marketplace UMKM/EKRAF Flores Timur

## Pemasangan lokal

1. Siapkan database MySQL/MariaDB kosong untuk marketplace. Repository menyediakan `database_marketplace_hosting.sql` untuk membuat skema lengkap dengan kontak simulasi; pilih database tersebut di phpMyAdmin sebelum mengimpor skrip. Skrip tidak membuat database dan tidak mengubah nama database secara otomatis.
   Untuk database lama yang telah menggunakan skema marketplace, jalankan kembali migrasi terkait secara berurutan sesuai fitur yang diperlukan.
   Untuk database lama, jalankan juga `database_marketplace_toko_migration.sql` agar kolom logo dan desa/kelurahan toko ditambahkan.
   Setelah fitur aplikasi mobile ditambahkan, jalankan `database_mobile_push_migration.sql` pada database lama untuk membuat tabel antrean dan perangkat notifikasi.
   Untuk memasang ulasan pada database lama secara terpisah, jalankan `database_marketplace_product_reviews_migration.sql`.
   Untuk memasang galeri slider foto produk pada database lama, jalankan `database_marketplace_product_images_migration.sql`.
   Untuk menambahkan tautan media sosial pada profil toko di database lama, jalankan `database_marketplace_shop_social_migration.sql`.
3. Buka `http://localhost/dinkop/`.
4. Sebelum penggunaan pertama, buka `http://localhost/dinkop/setup_admin.php` untuk membuat akun admin dengan kata sandi sendiri (minimal 12 karakter). Formulir penyiapan akan terkunci setelah admin pertama dibuat.
5. Admin masuk melalui tautan **Login Admin** pada navbar. Tidak ada kata sandi bawaan.
6. Di sidebar admin, buka **Gambar slide beranda** untuk mengunggah gambar, mengatur judul/deskripsi, urutan, serta status aktif. Beranda menampilkan maksimal delapan slide aktif secara bergantian.
7. Di sidebar admin, buka **Logo header aplikasi** untuk mengunggah atau mengganti logo Pemerintah Kabupaten Flores Timur dan logo Pasar Ekraf. Kedua logo akan tampil berdampingan pada header halaman aplikasi.
8. Di sidebar admin, buka **Gambar halaman login** untuk mengunggah foto khusus di sebelah kiri form masuk. Gambar ini terpisah dari slider beranda; hapus gambar khusus untuk kembali menggunakan ilustrasi bawaan.
9. Di sidebar admin, buka **Footer aplikasi** untuk mengubah nama aplikasi dan teks copyright. Logo footer mengikuti logo Pemerintah dan FLOM yang diatur pada menu logo header.
10. Di sidebar admin, buka **Hubungi kami** untuk mengatur deskripsi, jadwal kerja, alamat, email, nomor telepon, serta URL sematan Google Maps yang tampil di bagian bawah marketplace.
11. UMKM dapat memakai menu samping di halaman toko untuk membuka profil/rekening, keamanan akun, formulir produk, daftar produk, pesanan, dan laporan penjualan. Pilih laporan harian atau bulanan, kemudian gunakan tombol **Print laporan** atau **Download .xlsx**.
12. Admin dapat membuka **Laporan penjualan** dari sidebar untuk melihat jumlah pesanan selesai, barang terjual, dan omzet setiap toko untuk satu tanggal atau bulan yang dipilih. Tombol laporan dapat mencetak atau mengunduh data dalam format Excel `.xlsx`.
13. Admin dapat membuka **Pesan calon penjual / toko** dari sidebar untuk membaca dan membalas pertanyaan pendaftaran, verifikasi, atau bantuan aplikasi.
14. Gunakan hanya akun, pesanan, rekening, kontak, dan gambar simulasi untuk lingkungan uji. File seed yang berisi data lokal atau kredensial akun uji tidak disertakan di repository publik; buat data demo baru melalui aplikasi sebelum mencoba alur marketplace.

Di hosting, tetapkan `DINKOP_DB_HOST`, `DINKOP_DB_USER`, `DINKOP_DB_PASSWORD`, dan `DINKOP_DB_NAME` pada konfigurasi server. Jangan gunakan akun database root atau kata sandi kosong di produksi.

## Hosting uji coba gratis

Paket uji coba dapat dijalankan pada hosting PHP/MySQL gratis seperti InfinityFree dengan subdomain gratis. Hosting dan situs yang dibuat akan bersifat publik; gunakan hanya katalog/data simulasi. Jangan mengimpor database lokal, foto unggahan lokal, rekening, nomor HP, pesanan, ataupun kredensial nyata.

1. Buat akun hosting atas nama Anda, lalu buat subdomain, database MySQL, dan akun FTP melalui panel hosting. Simpan alamat host database, nama database, username, dan password secara pribadi. Jangan mengirim password tersebut melalui chat.
2. Impor `database_marketplace_hosting.sql` ke database yang sudah dibuat lewat phpMyAdmin. File ini tidak membuat atau memilih database bernama `db_dinkop`; pilih database hosting yang benar terlebih dahulu.
3. Buat file `flom_db_config.php` di direktori induk folder `htdocs` (di luar direktori publik) dengan isi berikut, lalu ganti nilai contoh menggunakan kredensial dari panel hosting:
   ```php
   <?php
   return [
       'host' => 'HOST_DATABASE_DARI_PANEL',
       'user' => 'USERNAME_DATABASE',
       'password' => 'PASSWORD_DATABASE',
       'database' => 'NAMA_DATABASE',
   ];
   ```
   `koneksi.php` memuat file tersebut dari luar document root. Jika panel menempatkan document root di lokasi lain, atur `DINKOP_DB_CONFIG_FILE` ke path privat yang sesuai. Jangan mengunggah file berisi kredensial ke dalam `htdocs`.
4. Unggah isi `flom-marketplace-hosting.zip` ke folder `htdocs` menggunakan FTP/File Manager. Unggah berkas aplikasi beserta folder `assets`, `data`, dan folder `uploads_*`; jangan mengunggah database lokal, folder `mobile`, `node_modules`, atau foto di folder upload lokal. Pastikan hosting memakai PHP 8.1 atau lebih baru dan folder `uploads_*` dapat ditulisi PHP bila ingin menguji unggah.
5. Buka alamat subdomain, lalu kunjungi `/setup_admin.php` satu kali untuk membuat akun admin dengan kata sandi kuat. Akun admin demo tidak disediakan. Setelah berhasil, masuk melalui `/login_admin.php`; penyiapan admin akan terkunci setelah akun pertama dibuat.
6. Daftarkan toko dan produk simulasi sendiri. Jangan menerima pembayaran, transfer bank, COD, atau mengumpulkan data pribadi nyata saat uji coba gratis. Server push notification dan pembuatan aplikasi native memerlukan konfigurasi hosting/kredensial terpisah dan tidak disertakan pada paket uji coba web ini.

## Aplikasi Android/iOS dengan Capacitor

Marketplace web tetap berjalan seperti biasa melalui browser PC/laptop. Aplikasi Android/iOS memakai backend PHP yang sama, sehingga memerlukan domain publik HTTPS yang dapat dijangkau perangkat; alamat `localhost` hanya tersedia di komputer server. Belum ada APK/AAB atau arsip iOS yang siap diunggah selama domain dan konfigurasi Firebase belum tersedia.

1. Instal Node.js 20 atau lebih baru. Dari PowerShell, masuk ke `D:\xampp\htdocs\dinkop\mobile`, jalankan `npm.cmd install`, lalu `npm.cmd run build:bridge`. Perintah kedua membangun `assets\mobile-bridge.js`, yang dipakai halaman web saat dibuka di aplikasi native maupun diabaikan oleh browser biasa.
2. Tetapkan URL publik aplikasi dan sinkronkan perubahan sebelum build rilis:
   ```powershell
   Set-Location 'D:\xampp\htdocs\dinkop\mobile'
   $env:FLOM_PUBLIC_URL = 'https://domain-marketplace-anda.go.id'
   npm.cmd run sync
   ```
   `FLOM_PUBLIC_URL` wajib HTTPS dan bukan localhost. Isi dengan alamat depan marketplace yang sebenarnya; jangan memakai URL yang masih memiliki halaman login atau parameter rahasia.
3. Buat proyek Firebase dan daftarkan aplikasi Android dengan ID `id.go.florestimur.flommarketplace` serta aplikasi iOS dengan ID yang sama. Aktifkan Firebase Cloud Messaging. Simpan `google-services.json` di `mobile\android\app\` dan `GoogleService-Info.plist` di `mobile\ios\App\App\`; file tersebut tidak berisi kunci service account server, tetapi tetap jangan menimpa konfigurasi aplikasi lain. Untuk iOS, unggah APNs authentication key ke Firebase dan aktifkan Push Notifications pada Apple Developer.
4. Letakkan file service-account Firebase khusus server di luar document root web (contohnya `D:\xampp\private\flom-firebase-service-account.json`) dan berikan hanya izin yang diperlukan untuk mengirim Firebase Cloud Messaging. Atur variabel lingkungan server `FLOM_FCM_SERVICE_ACCOUNT_FILE` ke lokasi tersebut. Jangan unggah, commit, atau salin file rahasia ini ke folder web, Android, atau iOS.
5. Antrean notifikasi dibuat setelah pesanan berhasil dan setelah pendaftaran toko diterima. Jalankan worker PHP CLI setiap menit melalui Task Scheduler Windows atau cron Linux. Contoh uji manual di Windows:
   ```powershell
   $env:FLOM_FCM_SERVICE_ACCOUNT_FILE = 'D:\xampp\private\flom-firebase-service-account.json'
   & 'D:\xampp\php\php.exe' 'D:\xampp\htdocs\dinkop\mobile\tools\send_mobile_push.php'
   ```
   Worker juga memerlukan variabel konfigurasi database yang dipakai PHP hosting. Perangkat baru didaftarkan setelah admin/pemilik toko masuk pada aplikasi dan menyetujui izin notifikasi; token perangkat dihapus saat sesi keluar.
6. Sinkronkan platform di komputer pengembang, buka Android Studio dengan `npm.cmd run open:android`, lalu buat **signed Android App Bundle (AAB)** memakai Android SDK/JDK. Pembuatan aplikasi iOS harus dilakukan pada Mac dengan Xcode menggunakan `npm run open:ios`. Kedua proyek native perlu kredensial Firebase di atas sebelum push notification dapat diuji.
7. Bridge aplikasi menambahkan pemilih kamera/galeri untuk unggahan foto, tautan masuk `flom://open/...`, serta aksi membuka halaman terkait dari notifikasi. Uji login, upload, tautan, transaksi, token dan notifikasi pada perangkat sungguhan serta HTTPS sebelum distribusi.

**Catatan penerbitan:** konfigurasi Capacitor saat ini membuka halaman PHP jarak jauh melalui `FLOM_PUBLIC_URL`. Backend yang sama tetap dapat diakses dari browser, tetapi App Store dapat menolak aplikasi yang dinilai hanya sebagai pembungkus situs; fitur native tidak menjamin persetujuan. Tinjau kebijakan Google Play/Apple, privasi, penggunaan data, dan persyaratan akun pengembang sebelum mengirim rilis. Jika App Store meminta aplikasi yang tidak bergantung pada antarmuka jarak jauh, diperlukan pengembangan UI native/bundled dan API backend yang sesuai.

## Alur penggunaan

1. UMKM mendaftar di `daftar_umkm.php` dengan profil dan rekening penerima transfer. Toko berstatus menunggu verifikasi.
   Pilih bank penerima dari BRI, BNI, Mandiri, BCA, BNI Syariah, atau BPD NTT; pratinjau wordmark/logo lokal ikut berubah saat bank dipilih. BNI Syariah adalah nama lama yang telah berintegrasi ke BSI, jadi pastikan nama bank sesuai rekening aktif.
2. Admin pemerintah membuka panel admin marketplace, lalu menyetujui toko.
3. UMKM masuk melalui `masuk_umkm.php`, mengelola profil, logo toko, tautan Facebook/Instagram, rekening, stok, dan produk. Tautan media sosial default `https://facebook.com` dan `https://instagram.com` ditampilkan di sebelah logo toko; ganti dengan tautan profil usaha agar pelanggan menuju akun toko yang benar. Tautan tersimpan harus HTTPS dan berasal dari domain Facebook atau Instagram yang valid. Setiap produk wajib memiliki satu foto sampul dan dapat memakai hingga empat foto detail JPG, PNG, atau WebP (maksimal 3 MB per foto). Pilih setiap foto pada slot unggahnya masing-masing; slot foto detail boleh dikosongkan. Foto sampul ditampilkan besar dan foto detail dapat dipilih sebagai gambar utama di halaman detail produk. Saat mengubah produk, slot yang tidak diganti tetap menggunakan foto lama. Kecamatan dan desa/kelurahan usaha dipilih dari daftar wilayah Flores Timur. Produk baru atau produk yang diubah menunggu tinjauan admin.
   Setiap produk dapat dikategorikan sebagai **Makanan**, **Minuman**, **Tenun**, atau **Kerajinan Tangan**. Kartu kategori di halaman awal menampilkan jumlah produk aktif dan membuka daftar produk sesuai kategori.
4. Admin meninjau produk di `admin_marketplace.php`. Produk yang disetujui tampil di katalog publik.
5. Pembeli memilih produk dari satu toko per pesanan, mengisi alamat, kecamatan, dan nomor WhatsApp, lalu memilih transfer atau COD.
6. Untuk transfer, pembeli membayar langsung ke rekening UMKM yang ditampilkan setelah checkout. UMKM mengonfirmasi penerimaan transfer dari dashboard tokonya.
7. Untuk COD, UMKM menerima pesanan lalu memperbarui status pemrosesan dan pengiriman. Pembeli dapat memeriksa status di `lacak_pesanan.php` menggunakan kode pesanan dan nomor HP.
   Kartu katalog membuka halaman detail produk dengan harga, stok, toko, rating, serta ulasan pembeli. Pembeli hanya dapat mengirim satu ulasan dan rating per produk pesanan setelah status pesanan menjadi **Selesai**; ulasan dikirim pada halaman lacak pesanan menggunakan kode pesanan dan nomor HP checkout.
8. Pembeli dapat menekan **Chat dengan toko** pada produk untuk bertanya langsung kepada UMKM. Toko membaca dan membalas pesan melalui **Pesan pembeli dan admin** di menu tokonya.
9. Calon penjual dapat memilih **Tanya admin** pada halaman marketplace, mengisi nama dan nomor HP/WhatsApp, lalu mengirim pertanyaan. Admin membaca dan membalasnya dari menu **Pesan calon penjual / toko**. Penjual yang sudah masuk dapat memulai percakapan dengan admin dari halaman pesan.
10. Tautan percakapan pembeli dan calon penjual disimpan sebagai cookie aman pada browser yang memulainya. Gunakan browser/perangkat yang sama untuk membuka kembali percakapan; jangan membagikan atau menghapus cookie tersebut sebelum percakapan selesai.

## Batasan versi awal

- Ongkos kirim dan ketersediaan layanan COD dikonfirmasi langsung oleh pembeli dan UMKM; belum ada integrasi kurir atau kalkulasi ongkir otomatis.
- Ulasan publik hanya menerima rating dan komentar dari item pesanan yang sudah berstatus selesai. Verifikasi dilakukan dengan kode pesanan dan nomor HP checkout; identitas pembeli tidak ditampilkan pada ulasan.
- Bukti transfer diverifikasi manual oleh UMKM. Platform tidak menampung dana dan tidak terhubung ke payment gateway.
- Laporan penjualan menghitung pesanan berstatus selesai, termasuk jumlah pesanan, barang, dan omzet; tanggal penjualan menggunakan waktu terakhir pesanan diperbarui saat diselesaikan.
- Push notifikasi pesanan dan pendaftaran toko bekerja setelah domain HTTPS, Firebase, file service account, tabel notifikasi, izin perangkat, dan worker terjadwal dikonfigurasi. Pesan inbox belum mengirim notifikasi push atau diperbarui langsung.
- Percakapan tersimpan di inbox dan diperbarui saat halaman pesan dibuka atau dimuat ulang; belum ada notifikasi push atau pembaruan pesan langsung.
- Gambar slider menerima JPG, PNG, atau WebP dengan ukuran maksimal 5 MB dan resolusi minimum 800 × 300 piksel.
- Gambar khusus halaman login menerima JPG, PNG, atau WebP dengan ukuran maksimal 5 MB dan resolusi 800 × 600 hingga 5000 × 5000 piksel.
- Logo header menerima JPG, PNG, atau WebP dengan ukuran maksimal 5 MB dan resolusi minimal 64 × 64 piksel.
- Logo toko menerima JPG, PNG, atau WebP dengan ukuran maksimal 3 MB dan resolusi minimal 64 × 64 piksel.
- Daftar wilayah kecamatan/desa/kelurahan bersumber dari dataset API wilayah Indonesia untuk Kabupaten Flores Timur (`5309`), berisi 19 kecamatan dan 246 desa/kelurahan.
- Terapkan HTTPS, kebijakan privasi/retensi data, prosedur pemulihan cadangan, dan pengelolaan akun admin yang kuat sebelum aplikasi dipublikasikan.
