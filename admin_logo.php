<?php
require_once __DIR__ . '/marketplace_lib.php';

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    mp_redirect('login_admin.php');
}

$error = '';
$logoDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads_logo';
$logoSlots = [
    'government' => 'Logo Pemerintah Kabupaten Flores Timur',
    'marketplace' => 'Logo Pasar Ekraf',
];

$deleteLogoImage = static function ($imagePath) use ($logoDirectory) {
    $prefix = 'uploads_logo/';
    if (substr((string)$imagePath, 0, strlen($prefix)) !== $prefix) {
        return;
    }
    $fileName = substr((string)$imagePath, strlen($prefix));
    if ($fileName === '' || basename($fileName) !== $fileName) {
        return;
    }
    $filePath = $logoDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath) && !unlink($filePath)) {
        error_log('Marketplace logo file could not be removed: ' . $filePath);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';
        $slot = $_POST['slot'] ?? '';
        if (!is_string($slot) || !isset($logoSlots[$slot])) {
            $error = 'Jenis logo yang dipilih tidak valid.';
        } elseif ($action === 'save_logo') {
            $upload = $_FILES['logo_image'] ?? null;
            if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Pilih berkas logo terlebih dahulu.';
            } elseif ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024) {
                $error = 'Berkas logo gagal diunggah atau ukurannya melebihi 5 MB.';
            } else {
                $imageInfo = @getimagesize($upload['tmp_name']);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($upload['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if ($imageInfo === false || !isset($extensions[$mime]) || $imageInfo['mime'] !== $mime) {
                    $error = 'Format logo harus JPG, PNG, atau WebP yang valid.';
                } elseif ($imageInfo[0] < 64 || $imageInfo[1] < 64 || $imageInfo[0] > 3000 || $imageInfo[1] > 3000) {
                    $error = 'Resolusi logo harus minimal 64 × 64 dan maksimal 3000 × 3000 piksel.';
                } elseif (!is_dir($logoDirectory) && !mkdir($logoDirectory, 0755, true) && !is_dir($logoDirectory)) {
                    $error = 'Folder logo tidak dapat dibuat. Periksa izin folder aplikasi.';
                } else {
                    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $destination = $logoDirectory . DIRECTORY_SEPARATOR . $fileName;
                    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                        $error = 'Logo tidak dapat disimpan. Periksa izin folder uploads_logo.';
                    } else {
                        $newImagePath = 'uploads_logo/' . $fileName;
                        try {
                            $findStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_site_logos WHERE slot = ? LIMIT 1');
                            mysqli_stmt_bind_param($findStmt, 's', $slot);
                            mysqli_stmt_execute($findStmt);
                            $existingLogo = mysqli_fetch_assoc(mysqli_stmt_get_result($findStmt));

                            $saveStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_site_logos (slot, image_path) VALUES (?, ?) ON DUPLICATE KEY UPDATE image_path = VALUES(image_path)');
                            mysqli_stmt_bind_param($saveStmt, 'ss', $slot, $newImagePath);
                            mysqli_stmt_execute($saveStmt);
                            if ($existingLogo) {
                                $deleteLogoImage($existingLogo['image_path']);
                            }
                            mp_set_flash('success', $logoSlots[$slot] . ' berhasil diperbarui.');
                            mp_redirect('admin_logo.php');
                        } catch (mysqli_sql_exception $exception) {
                            $deleteLogoImage($newImagePath);
                            error_log('Marketplace logo save failed: ' . $exception->getMessage());
                            $error = 'Logo gagal disimpan. Silakan coba kembali.';
                        }
                    }
                }
            }
        } elseif ($action === 'delete_logo') {
            try {
                $findStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_site_logos WHERE slot = ? LIMIT 1');
                mysqli_stmt_bind_param($findStmt, 's', $slot);
                mysqli_stmt_execute($findStmt);
                $existingLogo = mysqli_fetch_assoc(mysqli_stmt_get_result($findStmt));
                if (!$existingLogo) {
                    $error = 'Logo tersebut belum diunggah.';
                } else {
                    $deleteStmt = mysqli_prepare($koneksi, 'DELETE FROM mp_site_logos WHERE slot = ?');
                    mysqli_stmt_bind_param($deleteStmt, 's', $slot);
                    mysqli_stmt_execute($deleteStmt);
                    $deleteLogoImage($existingLogo['image_path']);
                    mp_set_flash('success', $logoSlots[$slot] . ' berhasil dihapus.');
                    mp_redirect('admin_logo.php');
                }
            } catch (mysqli_sql_exception $exception) {
                error_log('Marketplace logo delete failed: ' . $exception->getMessage());
                $error = 'Logo gagal dihapus. Silakan coba kembali.';
            }
        } else {
            $error = 'Aksi tidak dikenali.';
        }
    }
}

$logoResult = mysqli_query($koneksi, 'SELECT slot, image_path FROM mp_site_logos');
$logos = [];
while ($logo = mysqli_fetch_assoc($logoResult)) {
    $logos[$logo['slot']] = $logo['image_path'];
}

mp_render_header('Kelola Logo Header');
mp_render_flash();
?>
<style>
    .admin-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .admin-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .admin-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .admin-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .admin-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    .logo-card { height: 100%; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .logo-preview { display: grid; min-height: 150px; place-items: center; margin-bottom: 1.25rem; border: 1px dashed #d8e2dc; border-radius: .8rem; background: #f7faf8; }
    .logo-preview img { width: 130px; height: 130px; object-fit: contain; }
    @media (max-width: 767.98px) { .admin-layout { grid-template-columns: 1fr; gap: 1rem; } .admin-sidebar { position: static; } .admin-sidebar .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; } .admin-sidebar .nav-link { white-space: nowrap; } }
</style>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Navigasi administrator">
        <div class="admin-sidebar-title">Menu administrator</div>
        <nav class="nav flex-column">
            <a class="nav-link" href="admin_marketplace.php"><i class="bi bi-speedometer2"></i>Ringkasan &amp; pengawasan</a>
            <a class="nav-link" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
            <a class="nav-link" href="admin_slide.php"><i class="bi bi-images"></i>Gambar slide beranda</a>
            <a class="nav-link active" href="admin_logo.php"><i class="bi bi-badge-tm"></i>Logo header aplikasi</a>
            <a class="nav-link" href="admin_login_image.php"><i class="bi bi-person-badge"></i>Gambar halaman login</a>
            <a class="nav-link" href="admin_footer.php"><i class="bi bi-layout-text-window-reverse"></i>Footer aplikasi</a>
            <a class="nav-link" href="admin_contact.php"><i class="bi bi-telephone"></i>Hubungi kami</a>
            <a class="nav-link" href="marketplace.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Lihat marketplace</a>
            <a class="nav-link text-danger" href="keluar_admin.php"><i class="bi bi-box-arrow-right"></i>Keluar admin</a>
        </nav>
    </aside>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div><span class="badge text-bg-success mb-2">Identitas aplikasi</span><h1 class="h3 fw-bold mb-1">Logo header aplikasi</h1><p class="small-muted mb-0">Unggah dua logo yang tampil berdampingan pada header halaman marketplace.</p></div>
            <a class="btn btn-outline-secondary" href="admin_marketplace.php">Kembali ke ringkasan</a>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= mp_escape($error) ?></div><?php endif; ?>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Gunakan JPG, PNG, atau WebP maksimal 5 MB. Resolusi minimum 64 × 64 piksel; PNG transparan disarankan untuk logo.</div>
        <div class="row g-3">
            <?php foreach ($logoSlots as $slot => $label): ?>
                <div class="col-12 col-lg-6">
                    <section class="logo-card p-4">
                        <h2 class="h5 fw-bold mb-3"><?= mp_escape($label) ?></h2>
                        <div class="logo-preview">
                            <?php if (isset($logos[$slot])): ?>
                                <img src="<?= mp_escape($logos[$slot]) ?>" alt="<?= mp_escape($label) ?>">
                            <?php else: ?>
                                <span class="text-secondary"><i class="bi bi-image fs-1 d-block text-center"></i>Belum ada logo</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" enctype="multipart/form-data">
                            <?php mp_render_csrf(); ?>
                            <input type="hidden" name="action" value="save_logo">
                            <input type="hidden" name="slot" value="<?= mp_escape($slot) ?>">
                            <label class="form-label" for="logo-<?= mp_escape($slot) ?>">Pilih gambar logo</label>
                            <input class="form-control mb-3" id="logo-<?= mp_escape($slot) ?>" type="file" name="logo_image" accept="image/jpeg,image/png,image/webp" required>
                            <button class="btn btn-market fw-semibold" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i><?= isset($logos[$slot]) ? 'Ganti logo' : 'Unggah logo' ?></button>
                        </form>
                        <?php if (isset($logos[$slot])): ?>
                            <form method="post" class="mt-2" onsubmit="return confirm('Hapus logo ini dari header aplikasi?')">
                                <?php mp_render_csrf(); ?>
                                <input type="hidden" name="action" value="delete_logo">
                                <input type="hidden" name="slot" value="<?= mp_escape($slot) ?>">
                                <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Hapus logo</button>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php mp_render_footer(); ?>
