<?php
require_once __DIR__ . '/marketplace_lib.php';

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    mp_redirect('login_admin.php');
}

$error = '';
$defaults = [
    'app_name' => 'FLOM Marketplace Flores Timur',
    'copyright_text' => 'Pemerintah Kabupaten Flores Timur',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $appName = trim($_POST['app_name'] ?? '');
        $copyrightText = trim($_POST['copyright_text'] ?? '');
        if ($appName === '' || $copyrightText === '') {
            $error = 'Nama aplikasi dan teks hak cipta wajib diisi.';
        } elseif (mp_length($appName) > 120 || mp_length($copyrightText) > 180) {
            $error = 'Nama aplikasi maksimal 120 karakter dan teks hak cipta maksimal 180 karakter.';
        } else {
            try {
                mysqli_begin_transaction($koneksi);
                $saveStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                foreach (['app_name' => $appName, 'copyright_text' => $copyrightText] as $settingKey => $settingValue) {
                    mysqli_stmt_bind_param($saveStmt, 'ss', $settingKey, $settingValue);
                    mysqli_stmt_execute($saveStmt);
                }
                mysqli_commit($koneksi);
                mp_set_flash('success', 'Nama aplikasi dan copyright footer berhasil diperbarui.');
                mp_redirect('admin_footer.php');
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($koneksi);
                error_log('Marketplace footer settings save failed: ' . $exception->getMessage());
                $error = 'Pengaturan footer gagal disimpan. Silakan coba kembali.';
            }
        }
    }
}

$settingsResult = mysqli_query($koneksi, "SELECT setting_key, setting_value FROM mp_site_settings WHERE setting_key IN ('app_name', 'copyright_text')");
$settings = $defaults;
while ($setting = mysqli_fetch_assoc($settingsResult)) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

mp_render_header('Kelola Footer Aplikasi');
mp_render_flash();
?>
<style>
    .admin-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .admin-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .admin-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .admin-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .admin-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    .footer-settings-card { border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .footer-preview { padding: 1.5rem; border: 1px solid #e0e9e2; border-radius: .8rem; background: #fff; }
    .footer-preview-logos { display: flex; align-items: center; justify-content: center; gap: 1rem; margin-bottom: .8rem; }
    .footer-preview-logos img { display: block; object-fit: contain; }
    .footer-preview-government { width: 46px; height: 46px; }
    .footer-preview-marketplace { width: 170px; height: 57px; }
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
            <a class="nav-link" href="admin_login_image.php"><i class="bi bi-person-badge"></i>Gambar halaman login</a>
            <a class="nav-link active" href="admin_footer.php"><i class="bi bi-layout-text-window-reverse"></i>Footer aplikasi</a>
            <a class="nav-link" href="admin_contact.php"><i class="bi bi-telephone"></i>Hubungi kami</a>
            <a class="nav-link" href="marketplace.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Lihat marketplace</a>
            <a class="nav-link text-danger" href="keluar_admin.php"><i class="bi bi-box-arrow-right"></i>Keluar admin</a>
        </nav>
    </aside>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div><span class="badge text-bg-success mb-2">Identitas aplikasi</span><h1 class="h3 fw-bold mb-1">Pengaturan footer</h1><p class="small-muted mb-0">Atur nama aplikasi dan copyright. Footer memakai logo yang sama dengan header.</p></div>
            <a class="btn btn-outline-secondary" href="admin_marketplace.php">Kembali ke ringkasan</a>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= mp_escape($error) ?></div><?php endif; ?>
        <section class="footer-settings-card p-4 mb-4">
            <h2 class="h5 fw-bold mb-3">Identitas footer</h2>
            <form method="post">
                <?php mp_render_csrf(); ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="app-name">Nama aplikasi</label>
                    <input class="form-control" id="app-name" name="app_name" maxlength="120" value="<?= mp_escape($settings['app_name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="copyright-text">Teks copyright / pemilik aplikasi</label>
                    <input class="form-control" id="copyright-text" name="copyright_text" maxlength="180" value="<?= mp_escape($settings['copyright_text']) ?>" required>
                    <div class="form-text">Tahun berjalan ditambahkan otomatis sebelum teks ini.</div>
                </div>
                <button class="btn btn-market fw-semibold" type="submit"><i class="bi bi-check2-circle me-1"></i>Simpan pengaturan footer</button>
            </form>
        </section>
        <?php
        $footerLogosResult = mysqli_query($koneksi, "SELECT slot, image_path FROM mp_site_logos WHERE slot IN ('government', 'marketplace')");
        $footerLogos = [];
        while ($footerLogo = mysqli_fetch_assoc($footerLogosResult)) {
            $footerLogos[$footerLogo['slot']] = $footerLogo['image_path'];
        }
        ?>
        <section class="footer-preview text-center">
            <h2 class="h6 fw-bold mb-3">Pratinjau footer</h2>
            <div class="footer-preview-logos">
                <?php if (isset($footerLogos['government'])): ?><img class="footer-preview-government" src="<?= mp_escape($footerLogos['government']) ?>" alt="Logo Pemerintah Kabupaten Flores Timur"><?php else: ?><span class="text-market fs-3"><i class="bi bi-bank"></i></span><?php endif; ?>
                <?php if (isset($footerLogos['marketplace'])): ?><img class="footer-preview-marketplace" src="<?= mp_escape($footerLogos['marketplace']) ?>" alt="Logo FLOM Marketplace"><?php else: ?><span class="text-market fs-3"><i class="bi bi-shop"></i></span><?php endif; ?>
            </div>
            <div class="fw-bold"><?= mp_escape($settings['app_name']) ?></div>
            <div class="small-muted small">&copy; <?= date('Y') ?> <?= mp_escape($settings['copyright_text']) ?></div>
        </section>
    </div>
</div>
<?php mp_render_footer(); ?>
