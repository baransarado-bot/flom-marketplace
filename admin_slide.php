<?php
require_once __DIR__ . '/marketplace_lib.php';

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    mp_redirect('login_admin.php');
}

$error = '';
$slideDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads_slide';
$maxSlides = 8;

$deleteSlideImage = static function ($imagePath) use ($slideDirectory) {
    $fileName = basename((string)$imagePath);
    if ($fileName === '' || $fileName !== (string)$imagePath) {
        return;
    }
    $filePath = $slideDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath)) {
        unlink($filePath);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_slide') {
            $slideId = filter_var($_POST['slide_id'] ?? 0, FILTER_VALIDATE_INT);
            $title = trim($_POST['title'] ?? '');
            $caption = trim($_POST['caption'] ?? '');
            $sortOrder = filter_var($_POST['sort_order'] ?? 0, FILTER_VALIDATE_INT);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $existingSlide = null;

            if ($slideId) {
                $findStmt = mysqli_prepare($koneksi, 'SELECT id, image_path FROM mp_slides WHERE id = ? LIMIT 1');
                mysqli_stmt_bind_param($findStmt, 'i', $slideId);
                mysqli_stmt_execute($findStmt);
                $existingSlide = mysqli_fetch_assoc(mysqli_stmt_get_result($findStmt));
                if (!$existingSlide) {
                    $error = 'Slide yang akan diubah tidak ditemukan.';
                }
            }

            if ($error === '' && (mp_length($title) > 180 || mp_length($caption) > 300 || $sortOrder === false || $sortOrder < 0 || $sortOrder > 9999)) {
                $error = 'Judul, deskripsi, atau urutan slide tidak valid.';
            }

            $activeCount = (int)mysqli_fetch_assoc(mysqli_query($koneksi, 'SELECT COUNT(*) AS total FROM mp_slides WHERE is_active = 1'))['total'];
            $willBeActiveCount = $activeCount;
            if ($existingSlide && $isActive === 0) {
                $currentActiveStmt = mysqli_prepare($koneksi, 'SELECT is_active FROM mp_slides WHERE id = ?');
                mysqli_stmt_bind_param($currentActiveStmt, 'i', $slideId);
                mysqli_stmt_execute($currentActiveStmt);
                $currentActive = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($currentActiveStmt))['is_active'];
                $willBeActiveCount -= $currentActive;
            } elseif (!$existingSlide && $isActive === 1) {
                $willBeActiveCount++;
            } elseif ($existingSlide && $isActive === 1) {
                $currentActiveStmt = mysqli_prepare($koneksi, 'SELECT is_active FROM mp_slides WHERE id = ?');
                mysqli_stmt_bind_param($currentActiveStmt, 'i', $slideId);
                mysqli_stmt_execute($currentActiveStmt);
                $currentActive = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($currentActiveStmt))['is_active'];
                $willBeActiveCount += $currentActive === 0 ? 1 : 0;
            }
            if ($error === '' && $willBeActiveCount > $maxSlides) {
                $error = 'Beranda menampilkan maksimal 8 slide aktif. Nonaktifkan slide lain terlebih dahulu.';
            }

            $newImagePath = null;
            if ($error === '' && isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK || $_FILES['image']['size'] > 5 * 1024 * 1024) {
                    $error = 'Gambar gagal diunggah atau ukurannya melebihi 5 MB.';
                } else {
                    $imageInfo = @getimagesize($_FILES['image']['tmp_name']);
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($_FILES['image']['tmp_name']);
                    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if ($imageInfo === false || !isset($extensions[$mime]) || $imageInfo['mime'] !== $mime) {
                        $error = 'Format gambar harus JPG, PNG, atau WebP yang valid.';
                    } elseif ($imageInfo[0] < 800 || $imageInfo[1] < 300) {
                        $error = 'Resolusi gambar minimal 800 × 300 piksel agar tampil baik pada slider.';
                    } elseif (!is_dir($slideDirectory) && !mkdir($slideDirectory, 0755, true) && !is_dir($slideDirectory)) {
                        $error = 'Folder gambar slider tidak dapat dibuat. Periksa izin folder aplikasi.';
                    } else {
                        $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                        $destination = $slideDirectory . DIRECTORY_SEPARATOR . $fileName;
                        if (!move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                            $error = 'Gambar tidak dapat disimpan. Periksa izin folder uploads_slide.';
                        } else {
                            $newImagePath = 'uploads_slide/' . $fileName;
                        }
                    }
                }
            }

            if ($error === '' && !$existingSlide && $newImagePath === null) {
                $error = 'Pilih gambar untuk slide baru.';
            }

            if ($error === '') {
                $imagePath = $newImagePath ?: $existingSlide['image_path'];
                try {
                    if ($existingSlide) {
                        $saveStmt = mysqli_prepare($koneksi, 'UPDATE mp_slides SET title = ?, caption = ?, image_path = ?, sort_order = ?, is_active = ? WHERE id = ?');
                        mysqli_stmt_bind_param($saveStmt, 'sssiii', $title, $caption, $imagePath, $sortOrder, $isActive, $slideId);
                    } else {
                        $saveStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_slides (title, caption, image_path, sort_order, is_active) VALUES (?, ?, ?, ?, ?)');
                        mysqli_stmt_bind_param($saveStmt, 'sssii', $title, $caption, $imagePath, $sortOrder, $isActive);
                    }
                    mysqli_stmt_execute($saveStmt);
                    if ($newImagePath && $existingSlide) {
                        $deleteSlideImage($existingSlide['image_path']);
                    }
                    mp_set_flash('success', $existingSlide ? 'Slide berhasil diperbarui.' : 'Slide berhasil ditambahkan.');
                    mp_redirect('admin_slide.php');
                } catch (mysqli_sql_exception $exception) {
                    if ($newImagePath) {
                        $deleteSlideImage($newImagePath);
                    }
                    error_log('Marketplace slide save failed: ' . $exception->getMessage());
                    $error = 'Perubahan slide gagal disimpan. Silakan coba kembali.';
                }
            } elseif ($newImagePath) {
                $deleteSlideImage($newImagePath);
            }
        } elseif ($action === 'toggle_slide') {
            $slideId = filter_var($_POST['slide_id'] ?? null, FILTER_VALIDATE_INT);
            $isActive = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
            if (!$slideId || !in_array($isActive, [0, 1], true)) {
                $error = 'Data slide tidak valid.';
            } else {
                $activeCount = (int)mysqli_fetch_assoc(mysqli_query($koneksi, 'SELECT COUNT(*) AS total FROM mp_slides WHERE is_active = 1'))['total'];
                if ($isActive === 1 && $activeCount >= $maxSlides) {
                    $error = 'Beranda menampilkan maksimal 8 slide aktif. Nonaktifkan slide lain terlebih dahulu.';
                } else {
                    $toggleStmt = mysqli_prepare($koneksi, 'UPDATE mp_slides SET is_active = ? WHERE id = ?');
                    mysqli_stmt_bind_param($toggleStmt, 'ii', $isActive, $slideId);
                    mysqli_stmt_execute($toggleStmt);
                    mp_set_flash('success', $isActive ? 'Slide diaktifkan.' : 'Slide dinonaktifkan.');
                    mp_redirect('admin_slide.php');
                }
            }
        } elseif ($action === 'delete_slide') {
            $slideId = filter_var($_POST['slide_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$slideId) {
                $error = 'Slide yang akan dihapus tidak valid.';
            } else {
                $findStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_slides WHERE id = ? LIMIT 1');
                mysqli_stmt_bind_param($findStmt, 'i', $slideId);
                mysqli_stmt_execute($findStmt);
                $slideToDelete = mysqli_fetch_assoc(mysqli_stmt_get_result($findStmt));
                if (!$slideToDelete) {
                    $error = 'Slide tidak ditemukan.';
                } else {
                    try {
                        $deleteStmt = mysqli_prepare($koneksi, 'DELETE FROM mp_slides WHERE id = ?');
                        mysqli_stmt_bind_param($deleteStmt, 'i', $slideId);
                        mysqli_stmt_execute($deleteStmt);
                        $deleteSlideImage($slideToDelete['image_path']);
                        mp_set_flash('success', 'Slide dan gambarnya berhasil dihapus.');
                        mp_redirect('admin_slide.php');
                    } catch (mysqli_sql_exception $exception) {
                        error_log('Marketplace slide delete failed: ' . $exception->getMessage());
                        $error = 'Slide gagal dihapus. Silakan coba kembali.';
                    }
                }
            }
        } else {
            $error = 'Aksi tidak dikenali.';
        }
    }
}

$editingSlide = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId) {
        $editStmt = mysqli_prepare($koneksi, 'SELECT id, title, caption, image_path, sort_order, is_active FROM mp_slides WHERE id = ? LIMIT 1');
        mysqli_stmt_bind_param($editStmt, 'i', $editId);
        mysqli_stmt_execute($editStmt);
        $editingSlide = mysqli_fetch_assoc(mysqli_stmt_get_result($editStmt));
    }
}

$slidesResult = mysqli_query($koneksi, 'SELECT id, title, caption, image_path, sort_order, is_active, created_at FROM mp_slides ORDER BY sort_order ASC, id DESC');
$slideCount = mysqli_num_rows($slidesResult);
$activeCount = (int)mysqli_fetch_assoc(mysqli_query($koneksi, 'SELECT COUNT(*) AS total FROM mp_slides WHERE is_active = 1'))['total'];

mp_render_header('Kelola Gambar Slider');
mp_render_flash();
?>
<style>
    .admin-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .admin-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .admin-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .admin-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .admin-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    .slide-preview { width: 180px; height: 86px; border-radius: .65rem; background: #e7efe9; object-fit: cover; }
    .slide-form-card { border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    @media (max-width: 767.98px) { .admin-layout { grid-template-columns: 1fr; gap: 1rem; } .admin-sidebar { position: static; } .admin-sidebar .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; } .admin-sidebar .nav-link { white-space: nowrap; } .slide-preview { width: 100%; max-width: 260px; } }
</style>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Navigasi administrator">
        <div class="admin-sidebar-title">Menu administrator</div>
        <nav class="nav flex-column">
            <a class="nav-link" href="admin_marketplace.php"><i class="bi bi-speedometer2"></i>Ringkasan &amp; pengawasan</a>
            <a class="nav-link" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
            <a class="nav-link active" href="admin_slide.php"><i class="bi bi-images"></i>Gambar slide beranda</a>
            <a class="nav-link" href="admin_logo.php"><i class="bi bi-badge-tm"></i>Logo header aplikasi</a>
            <a class="nav-link" href="admin_login_image.php"><i class="bi bi-person-badge"></i>Gambar halaman login</a>
            <a class="nav-link" href="admin_footer.php"><i class="bi bi-layout-text-window-reverse"></i>Footer aplikasi</a>
            <a class="nav-link" href="admin_contact.php"><i class="bi bi-telephone"></i>Hubungi kami</a>
            <a class="nav-link" href="marketplace.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Lihat marketplace</a>
            <a class="nav-link text-danger" href="keluar_admin.php"><i class="bi bi-box-arrow-right"></i>Keluar admin</a>
        </nav>
    </aside>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div><span class="badge text-bg-success mb-2">Pengaturan beranda</span><h1 class="h3 fw-bold mb-1">Gambar slider beranda</h1><p class="small-muted mb-0">Atur gambar, judul, deskripsi, dan urutan pada bingkai utama marketplace.</p></div>
            <a class="btn btn-outline-secondary" href="admin_marketplace.php">Kembali ke ringkasan</a>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>Gambar JPG, PNG, atau WebP; maksimal 5 MB dan resolusi minimum 800 × 300 piksel. Gambar otomatis memenuhi bingkai slide di desktop dan ponsel; sebagian tepi dapat terpotong agar bingkai terisi penuh. Maksimal 8 slide aktif. Urutan lebih kecil tampil lebih dahulu.</div>

        <section class="slide-form-card p-4 mb-4">
            <h2 class="h5 fw-bold mb-3"><?= $editingSlide ? 'Ubah slide' : 'Unggah slide baru' ?></h2>
            <form method="post" enctype="multipart/form-data" class="row g-3">
                <?php mp_render_csrf(); ?>
                <input type="hidden" name="action" value="save_slide">
                <input type="hidden" name="slide_id" value="<?= (int)($editingSlide['id'] ?? 0) ?>">
                <div class="col-md-6"><label class="form-label" for="slide-title">Judul (opsional)</label><input id="slide-title" class="form-control" name="title" maxlength="180" value="<?= mp_escape($editingSlide['title'] ?? '') ?>" placeholder="Contoh: Pesona Tenun Lamaholot"></div>
                <div class="col-md-6"><label class="form-label" for="slide-order">Urutan tampil</label><input id="slide-order" class="form-control" type="number" name="sort_order" min="0" max="9999" value="<?= (int)($editingSlide['sort_order'] ?? $slideCount) ?>" required></div>
                <div class="col-12"><label class="form-label" for="slide-caption">Deskripsi (opsional)</label><textarea id="slide-caption" class="form-control" name="caption" rows="2" maxlength="300" placeholder="Pesan singkat yang akan tampil bersama gambar"><?= mp_escape($editingSlide['caption'] ?? '') ?></textarea></div>
                <div class="col-md-8"><label class="form-label" for="slide-image">Gambar <?= $editingSlide ? '(pilih hanya jika ingin mengganti gambar)' : '' ?></label><input id="slide-image" class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp" <?= $editingSlide ? '' : 'required' ?>></div>
                <div class="col-md-4"><label class="form-label d-block">Tampilan</label><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="slide-active" <?= !isset($editingSlide['is_active']) || (int)$editingSlide['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="slide-active">Aktif di beranda</label></div></div>
                <?php if ($editingSlide): ?><div class="col-12"><img class="slide-preview" src="<?= mp_escape($editingSlide['image_path']) ?>" alt="Gambar slide saat ini"></div><?php endif; ?>
                <div class="col-12"><button class="btn btn-market fw-semibold" type="submit"><i class="bi bi-cloud-arrow-up me-1"></i><?= $editingSlide ? 'Simpan perubahan' : 'Unggah slide' ?></button><?php if ($editingSlide): ?><a class="btn btn-outline-secondary ms-2" href="admin_slide.php">Batal</a><?php endif; ?></div>
            </form>
        </section>

        <section class="slide-form-card p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 fw-bold mb-0">Slide terdaftar</h2><span class="small-muted small"><?= (int)$activeCount ?> aktif · <?= (int)$slideCount ?> total</span></div>
            <?php if ($slideCount === 0): ?>
                <div class="text-center small-muted py-5"><i class="bi bi-images fs-1 d-block mb-2"></i>Belum ada gambar slider. Tambahkan gambar pertama untuk menampilkannya di beranda.</div>
            <?php else: ?>
                <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Gambar</th><th>Judul &amp; deskripsi</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                <?php while ($slide = mysqli_fetch_assoc($slidesResult)): ?>
                    <tr>
                        <td><img class="slide-preview" src="<?= mp_escape($slide['image_path']) ?>" alt="<?= mp_escape($slide['title'] ?: 'Gambar slide') ?>"></td>
                        <td><strong><?= mp_escape($slide['title'] ?: 'Tanpa judul') ?></strong><div class="small-muted small"><?= mp_escape($slide['caption'] ?: 'Deskripsi bawaan marketplace') ?></div></td>
                        <td><?= (int)$slide['sort_order'] ?></td>
                        <td><span class="badge text-bg-<?= (int)$slide['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int)$slide['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="text-nowrap">
                            <a class="btn btn-sm btn-outline-primary" href="admin_slide.php?edit=<?= (int)$slide['id'] ?>"><i class="bi bi-pencil me-1"></i>Ubah</a>
                            <form method="post" class="d-inline"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="toggle_slide"><input type="hidden" name="slide_id" value="<?= (int)$slide['id'] ?>"><input type="hidden" name="is_active" value="<?= (int)$slide['is_active'] === 1 ? '0' : '1' ?>"><button class="btn btn-sm btn-outline-success" type="submit"><?= (int)$slide['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Hapus slide dan berkas gambarnya?')"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="delete_slide"><input type="hidden" name="slide_id" value="<?= (int)$slide['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button></form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php mp_render_footer(); ?>
