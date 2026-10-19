<?php
require_once __DIR__ . '/marketplace_lib.php';

$error = '';
$categories = ['Kuliner', 'Kriya / Kerajinan', 'Fashion / Tenun', 'Seni dan Kreativitas', 'Pertanian dan Olahan', 'Lainnya'];
$supportedBanks = mp_supported_banks();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $businessName = trim($_POST['business_name'] ?? '');
        $ownerName = trim($_POST['owner_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $kecamatan = trim($_POST['kecamatan'] ?? '');
        $kelurahan = trim($_POST['kelurahan'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $bankAccountName = trim($_POST['bank_account_name'] ?? '');
        $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_.-]{4,60}$/', $username)) {
            $error = 'Username harus 4-60 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
        } elseif (strlen($password) < 10 || strlen($password) > 72) {
            $error = 'Kata sandi harus antara 10 dan 72 byte.';
        } elseif ($businessName === '' || $ownerName === '' || !in_array($category, $categories, true) || $phone === '' || $address === '' || $kecamatan === '' || $kelurahan === '' || !isset($supportedBanks[$bankName]) || $bankAccountName === '' || $bankAccountNumber === '') {
            $error = 'Semua data usaha, lokasi, kontak, dan rekening wajib diisi.';
        } elseif (!mp_is_valid_flores_timur_location($kecamatan, $kelurahan)) {
            $error = 'Pilih kecamatan dan desa/kelurahan yang terdaftar di Kabupaten Flores Timur.';
        } elseif (mp_length($businessName) > 160 || mp_length($ownerName) > 120 || mp_length($category) > 80 || mp_length($phone) > 30 || mp_length($address) > 1000 || mp_length($kecamatan) > 100 || mp_length($kelurahan) > 120 || mp_length($bankName) > 80 || mp_length($bankAccountName) > 120 || mp_length($bankAccountNumber) > 50) {
            $error = 'Salah satu kolom melebihi batas panjang yang diperbolehkan.';
        } else {
            $stmt = mysqli_prepare($koneksi, 'INSERT INTO mp_shops (username, password_hash, business_name, owner_name, category, phone, address, kecamatan, kelurahan, bank_name, bank_account_name, bank_account_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            mysqli_stmt_bind_param($stmt, 'ssssssssssss', $username, $passwordHash, $businessName, $ownerName, $category, $phone, $address, $kecamatan, $kelurahan, $bankName, $bankAccountName, $bankAccountNumber);

            try {
                mysqli_stmt_execute($stmt);
                try {
                    $adminResult = mysqli_query($koneksi, 'SELECT id FROM mp_admins');
                    while ($admin = mysqli_fetch_assoc($adminResult)) {
                        mp_mobile_push_queue('admin', (int)$admin['id'], 'Pendaftaran UMKM baru', 'Ada pendaftaran toko yang menunggu verifikasi.', 'admin_marketplace.php#toko');
                    }
                } catch (mysqli_sql_exception $notificationException) {
                    error_log('Marketplace registration push queue failed: ' . $notificationException->getMessage());
                }
                mp_set_flash('success', 'Pendaftaran toko berhasil dikirim. Anda dapat masuk setelah menunggu verifikasi admin Pemerintah Kabupaten Flores Timur.');
                mp_redirect('masuk_umkm.php');
            } catch (mysqli_sql_exception $exception) {
                if ((int)$exception->getCode() === 1062) {
                    $error = 'Username tersebut sudah digunakan. Silakan pilih username lain.';
                } else {
                    error_log('Marketplace registration failed: ' . $exception->getMessage());
                    $error = 'Pendaftaran gagal disimpan. Silakan coba kembali atau hubungi admin.';
                }
            }
        }
    }
}

mp_render_header('Pendaftaran UMKM dan EKRAF');
mp_render_flash();
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card p-4 p-md-5">
            <div class="mb-4">
                <span class="badge text-bg-success mb-2">Pendaftaran Penjual</span>
                <h1 class="h3 fw-bold">Daftarkan toko UMKM/EKRAF</h1>
                <p class="small-muted mb-0">Toko akan ditinjau admin Pemerintah Kabupaten Flores Timur sebelum dapat berjualan.</p>
            </div>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
            <form method="post">
                <?php mp_render_csrf(); ?>
                <h2 class="h6 fw-bold text-market">Akun masuk</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><label class="form-label">Username</label><input class="form-control" name="username" minlength="4" maxlength="60" autocomplete="username" required></div>
                    <div class="col-md-6"><label class="form-label">Kata sandi</label><input class="form-control" type="password" name="password" minlength="10" autocomplete="new-password" required><div class="form-text">Minimal 10 karakter.</div></div>
                </div>
                <h2 class="h6 fw-bold text-market">Profil usaha</h2>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><label class="form-label">Nama usaha/toko</label><input class="form-control" name="business_name" maxlength="160" required></div>
                    <div class="col-md-6"><label class="form-label">Nama pemilik</label><input class="form-control" name="owner_name" maxlength="120" required></div>
                    <div class="col-md-6"><label class="form-label">Kategori usaha</label><select class="form-select" name="category" required><option value="">Pilih kategori</option><?php foreach ($categories as $categoryOption): ?><option value="<?= mp_escape($categoryOption) ?>"><?= mp_escape($categoryOption) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Nomor HP/WhatsApp</label><input class="form-control" name="phone" maxlength="30" autocomplete="tel" required></div>
                    <div class="col-12"><label class="form-label">Alamat usaha</label><input class="form-control" name="address" maxlength="1000" required></div>
                    <?php mp_render_flores_timur_location_fields($_POST['kecamatan'] ?? '', $_POST['kelurahan'] ?? ''); ?>
                </div>
                <h2 class="h6 fw-bold text-market">Rekening penerima transfer</h2>
                <p class="small-muted small">Pembayaran transfer pembeli dikirim langsung ke rekening toko, bukan ditampung oleh platform.</p>
                <div class="row g-3 mb-4">
                    <div class="col-md-4"><label class="form-label" for="bank-name">Nama bank</label><?php mp_render_bank_selector($_POST['bank_name'] ?? ''); ?></div>
                    <div class="col-md-4"><label class="form-label">Nama pemilik rekening</label><input class="form-control" name="bank_account_name" maxlength="120" required></div>
                    <div class="col-md-4"><label class="form-label">Nomor rekening</label><input class="form-control" name="bank_account_number" maxlength="50" required></div>
                </div>
                <button class="btn btn-market fw-semibold px-4" type="submit">Kirim pendaftaran</button>
                <a class="btn btn-outline-secondary ms-2" href="marketplace.php">Kembali ke marketplace</a>
            </form>
        </div>
    </div>
</div>
<?php mp_render_footer(); ?>
