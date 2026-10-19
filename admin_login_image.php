<?php
require_once __DIR__ . '/marketplace_lib.php';

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    mp_redirect('login_admin.php');
}

$error = '';
$imageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads_login';
$imageSlot = 'login_visual';
$fallbackImage = 'assets/login-flores.svg';

$deleteLoginImage = static function ($imagePath) use ($imageDirectory) {
    $prefix = 'uploads_login/';
    if (substr((string)$imagePath, 0, strlen($prefix)) !== $prefix) {
        return;
    }
    $fileName = substr((string)$imagePath, strlen($prefix));
    if ($fileName === '' || basename($fileName) !== $fileName) {
        return;
    }
    $filePath = $imageDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath) && !unlink($filePath)) {
        error_log('Marketplace login image could not be removed: ' . $filePath);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_login_image') {
            $upload = $_FILES['login_image'] ?? null;
            if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Pilih gambar untuk halaman login terlebih dahulu.';
            } elseif ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024) {
                $error = 'Gambar gagal diunggah atau ukurannya melebihi 5 MB.';
            } else {
                $imageInfo = @getimagesize($upload['tmp_name']);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($upload['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if ($imageInfo === false || !isset($extensions[$mime]) || $imageInfo['mime'] !== $mime) {
                    $error = 'Format gambar harus JPG, PNG, atau WebP yang valid.';
                } elseif ($imageInfo[0] < 800 || $imageInfo[1] < 600 || $imageInfo[0] > 5000 || $imageInfo[1] > 5000) {
                    $error = 'Resolusi gambar harus minimal 800 × 600 dan maksimal 5000 × 5000 piksel.';
                } elseif (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true) && !is_dir($imageDirectory)) {
                    $error = 'Folder gambar login tidak dapat dibuat. Periksa izin folder aplikasi.';
                } else {
                    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $destination = $imageDirectory . DIRECTORY_SEPARATOR . $fileName;
                    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                        $error = 'Gambar tidak dapat disimpan. Periksa izin folder uploads_login.';
                    } else {
                        $newImagePath = 'uploads_login/' . $fileName;
                        try {
                            $findStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_site_logos WHERE slot = ? LIMIT 1');
                            mysqli_stmt_bind_param($findStmt, 's', $imageSlot);
                            mysqli_stmt_execute($findStmt);
                            $existingImage = mysqli_fetch_assoc(mysqli_stmt_get_result($findStmt));

                            $saveStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_site_logos (slot, image_path) VALUES (?, ?) ON DUPLICATE KEY UPDATE image_path = VALUES(image_path)');
                            mysqli_stmt_bind_param($saveStmt, 'ss', $imageSlot, $newImagePath);
                            mysqli_stmt_execute($saveStmt);
                            if ($existingImage) {
                                $deleteLoginImage($existingImage['image_path']);
                            }
                            mp_set_flash('success', 'Gambar halaman login berhasil diperbarui.');
                            mp_redirect('admin_login_image.php');
                        } catch (mysqli_sql_exception $exception) {
                            $deleteLoginImage($newImagePath);
                            error_log('Marketplace login image save failed: ' . $exception->getMessage());
                            $error = 'Gambar halaman login gagal disimpan. Silakan coba kembali.';
                        }
                    }
                }
            }
        } elseif ($action === 'delete_login_image') {
            try {
                $findStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_site_logos WHERE slot = ? LIMIT 1');
                mysqli_stmt_bind_param($findStmt, 's', $imageSlot);
                mysqli_stmt_execute($findStmt);
                $existingImage = mysqli_fetch_assoc(mysqli_stmt_get_result($findStmt));
                if (!$existingImage) {
                    $error = 'Belum ada gambar khusus yang diunggah. Ilustrasi bawaan sedang digunakan.';
                } else {
                    $deleteStmt = mysqli_prepare($koneksi, 'DELETE FROM mp_site_logos WHERE slot = ?');
                    mysqli_stmt_bind_param($deleteStmt, 's', $imageSlot);
                    mysqli_stmt_execute($deleteStmt);
                    $deleteLoginImage($existingImage['image_path']);
                    mp_set_flash('success', 'Gambar khusus dihapus. Ilustrasi bawaan kembali digunakan.');
                    mp_redirect('admin_login_image.php');
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Marketplace login image delete failed: ' . $exception->getMessage());
                $error = 'Gambar halaman login gagal dihapus. Silakan coba kembali.';
            }
        } else {
            $error = 'Aksi tidak dikenali.';
        }
    }
}

$imageStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_site_logos WHERE slot = ? LIMIT 1');
mysqli_stmt_bind_param($imageStmt, 's', $imageSlot);
mysqli_stmt_execute($imageStmt);
$currentImage = mysqli_fetch_assoc(mysqli_stmt_get_result($imageStmt));
$previewImage = $currentImage ? $currentImage['image_path'] : $fallbackImage;

mp_render_header('Kelola Gambar Halaman Login');
mp_render_flash();
?>
<style>
    .admin-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .admin-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .admin-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .admin-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .admin-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    .image-card { border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .login-image-preview { position: relative; width: 100%; max-width: 720px; aspect-ratio: 4 / 3; overflow: hidden; margin-bottom: 1.25rem; border: 1px solid #d8e2dc; border-radius: .9rem; background: #e9f0eb; }
    .login-image-preview img { width: 100%; height: 100%; object-fit: cover; }
    @media (max-width: 767.98px) { .admin-layout { grid-template-columns: 1fr; gap: 1rem; } .admin-sidebar { position: static; } .admin-sidebar .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; } .admin-sidebar .nav-link { white-space: nowrap; } }
</style>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Navigasi administrator">
        <div class="admin-sidebar-title">Menu administrator</div>
        <nav class="nav flex-column">
            <a class="nav-link" href="admin_marketplace.php"><i class="bi bi-speedometer2"></i>Ringkasan &amp; pengawasan</a>
            <a class="nav-link" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
            <a class="nav-link" href="admin_slide.php"><i class="bi bi-images"></i>Gambar slide beranda</a>
            <a class="nav-link" href="admin_logo.php"><i class="bi bi-badge-tm"></i>Logo header aplikasi</a>
            <a class="nav-link active" href="admin_login_image.php"><i class="bi bi-person-badge"></i>Gambar halaman login</a>
            <a class="nav-link" href="admin_footer.php"><i class="bi bi-layout-text-window-reverse"></i>Footer aplikasi</a>
            <a class="nav-link" href="admin_contact.php"><i class="bi bi-telephone"></i>Hubungi kami</a>
            <a class="nav-link" href="marketplace.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Lihat marketplace</a>
            <a class="nav-link text-danger" href="keluar_admin.php"><i class="bi bi-box-arrow-right"></i>Keluar admin</a>
        </nav>
    </aside>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div><span class="badge text-bg-success mb-2">Tampilan masuk</span><h1 class="h3 fw-bold mb-1">Gambar halaman login</h1><p class="small-muted mb-0">Gambar ini khusus untuk sisi kiri halaman masuk dan terpisah dari slider beranda.</p></div>
            <a class="btn btn-outline-secondary" href="admin_marketplace.php">Kembali ke ringkasan</a>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= mp_escape($error) ?></div><?php endif; ?>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Unggah JPG, PNG, atau WebP maksimal 5 MB. Resolusi 800 × 600 hingga 5000 × 5000 piksel. Gambar akan menyesuaikan bingkai login; sebagian tepi dapat terpotong.</div>
        <section class="image-card p-4">
            <h2 class="h5 fw-bold mb-3"><?= $currentImage ? 'Gambar login saat ini' : 'Ilustrasi bawaan' ?></h2>
            <div class="login-image-preview"><img src="<?= mp_escape($previewImage) ?>" alt="Pratinjau gambar halaman login"></div>
            <form method="post" enctype="multipart/form-data">
                <?php mp_render_csrf(); ?>
                <input type="hidden" name="action" value="save_login_image">
                <label class="form-label" for="login-image">Pilih gambar khusus untuk halaman login</label>
                <input class="form-control mb-3" id="login-image" type="file" name="login_image" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn btn-market fw-semibold" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i><?= $currentImage ? 'Ganti gambar' : 'Unggah gambar' ?></button>
            </form>
            <?php if ($currentImage): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Hapus gambar khusus dan gunakan ilustrasi bawaan?')">
                    <?php mp_render_csrf(); ?>
                    <input type="hidden" name="action" value="delete_login_image">
                    <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Gunakan ilustrasi bawaan</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php mp_render_footer(); ?>
