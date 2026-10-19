<?php
require_once __DIR__ . '/marketplace_lib.php';

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    mp_redirect('login_admin.php');
}

$error = '';
$defaults = [
    'contact_intro' => 'Kami menyediakan berbagai media yang dapat Anda hubungi untuk informasi layanan marketplace dan ekonomi kreatif Flores Timur.',
    'contact_work_hours' => 'Senin-Jumat, 08.00-16.00 WITA',
    'contact_address' => 'Jl. A. Yani, Puken Tobi Wangibao, Larantuka',
    'contact_email' => 'faslafpflomur@gmail.com',
    'contact_phone' => '082129839734',
    'contact_map_url' => 'https://www.google.com/maps?q=Jl.+A.+Yani,+Puken+Tobi+Wangibao,+Larantuka&output=embed',
];
$fieldLimits = [
    'contact_intro' => 500,
    'contact_work_hours' => 120,
    'contact_address' => 300,
    'contact_email' => 254,
    'contact_phone' => 30,
    'contact_map_url' => 1800,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $contact = [];
        foreach ($fieldLimits as $key => $limit) {
            $contact[$key] = trim($_POST[$key] ?? '');
            if ($contact[$key] === '' || mp_length($contact[$key]) > $limit) {
                $error = 'Semua kolom wajib diisi dan tidak boleh melebihi batas karakter.';
                break;
            }
        }
        if ($error === '' && !filter_var($contact['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Alamat email kontak tidak valid.';
        }
        if ($error === '' && !preg_match('/^[0-9+(). -]{7,30}$/', $contact['contact_phone'])) {
            $error = 'Nomor telepon hanya boleh berisi angka, spasi, dan tanda + ( ) - dengan panjang 7 hingga 30 karakter.';
        }
        if ($error === '') {
            $mapParts = parse_url($contact['contact_map_url']);
            $allowedHosts = ['google.com', 'www.google.com', 'maps.google.com'];
            $mapQuery = [];
            if (is_array($mapParts) && isset($mapParts['query'])) {
                parse_str($mapParts['query'], $mapQuery);
            }
            $validMap = is_array($mapParts)
                && ($mapParts['scheme'] ?? '') === 'https'
                && in_array(strtolower($mapParts['host'] ?? ''), $allowedHosts, true)
                && !isset($mapParts['user'])
                && !isset($mapParts['pass'])
                && substr($mapParts['path'] ?? '', 0, 5) === '/maps'
                && (substr($mapParts['path'] ?? '', 0, 11) === '/maps/embed' || ($mapQuery['output'] ?? '') === 'embed');
            if (!$validMap) {
                $error = 'Gunakan URL embed Google Maps yang aman, misalnya https://www.google.com/maps?q=Larantuka&output=embed.';
            }
        }

        if ($error === '') {
            try {
                mysqli_begin_transaction($koneksi);
                $saveStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
                foreach ($contact as $settingKey => $settingValue) {
                    mysqli_stmt_bind_param($saveStmt, 'ss', $settingKey, $settingValue);
                    mysqli_stmt_execute($saveStmt);
                }
                mysqli_commit($koneksi);
                mp_set_flash('success', 'Informasi kontak dan peta berhasil diperbarui.');
                mp_redirect('admin_contact.php');
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($koneksi);
                error_log('Marketplace contact settings save failed: ' . $exception->getMessage());
                $error = 'Informasi kontak gagal disimpan. Silakan coba kembali.';
            }
        }
    }
}

$settingsResult = mysqli_query($koneksi, "SELECT setting_key, setting_value FROM mp_site_settings WHERE setting_key LIKE 'contact\\_%'");
$contactSettings = $defaults;
while ($setting = mysqli_fetch_assoc($settingsResult)) {
    if (array_key_exists($setting['setting_key'], $defaults)) {
        $contactSettings[$setting['setting_key']] = $setting['setting_value'];
    }
}

mp_render_header('Kelola Kontak Marketplace');
mp_render_flash();
?>
<style>
    .admin-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .admin-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .admin-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .admin-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .admin-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    .contact-settings-card { border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .contact-admin-map { width: 100%; height: 300px; border: 0; border-radius: .8rem; background: #e9f0f5; }
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
            <a class="nav-link" href="admin_footer.php"><i class="bi bi-layout-text-window-reverse"></i>Footer aplikasi</a>
            <a class="nav-link active" href="admin_contact.php"><i class="bi bi-telephone"></i>Hubungi kami</a>
            <a class="nav-link" href="marketplace.php#hubungi-kami" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Lihat kontak publik</a>
            <a class="nav-link text-danger" href="keluar_admin.php"><i class="bi bi-box-arrow-right"></i>Keluar admin</a>
        </nav>
    </aside>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div><span class="badge text-bg-success mb-2">Kontak publik</span><h1 class="h3 fw-bold mb-1">Pengaturan Hubungi Kami</h1><p class="small-muted mb-0">Kontak dan peta akan tampil pada bagian bawah halaman marketplace.</p></div>
            <a class="btn btn-outline-secondary" href="admin_marketplace.php">Kembali ke ringkasan</a>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= mp_escape($error) ?></div><?php endif; ?>
        <section class="contact-settings-card p-4 mb-4">
            <form method="post" class="row g-3">
                <?php mp_render_csrf(); ?>
                <div class="col-12"><label class="form-label fw-semibold" for="contact_intro">Deskripsi kontak</label><textarea class="form-control" id="contact_intro" name="contact_intro" rows="2" maxlength="500" required><?= mp_escape($contactSettings['contact_intro']) ?></textarea></div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="contact_work_hours">Jadwal kerja</label><input class="form-control" id="contact_work_hours" name="contact_work_hours" maxlength="120" value="<?= mp_escape($contactSettings['contact_work_hours']) ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="contact_address">Alamat</label><input class="form-control" id="contact_address" name="contact_address" maxlength="300" value="<?= mp_escape($contactSettings['contact_address']) ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="contact_email">Email</label><input class="form-control" id="contact_email" type="email" name="contact_email" maxlength="254" value="<?= mp_escape($contactSettings['contact_email']) ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="contact_phone">Telepon</label><input class="form-control" id="contact_phone" type="tel" name="contact_phone" maxlength="30" value="<?= mp_escape($contactSettings['contact_phone']) ?>" required></div>
                <div class="col-12"><label class="form-label fw-semibold" for="contact_map_url">URL embed Google Maps</label><input class="form-control" id="contact_map_url" type="url" name="contact_map_url" maxlength="1800" value="<?= mp_escape($contactSettings['contact_map_url']) ?>" required><div class="form-text">Tempel URL embed dari Google Maps. Untuk membuatnya: Google Maps → Bagikan → Sematkan peta → salin URL pada atribut src.</div></div>
                <div class="col-12"><h2 class="h6 fw-bold mb-0">Pratinjau peta</h2><iframe class="contact-admin-map" src="<?= mp_escape($contactSettings['contact_map_url']) ?>" title="Pratinjau peta kontak" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>
                <div class="col-12"><button class="btn btn-market fw-semibold" type="submit"><i class="bi bi-check2-circle me-1"></i>Simpan kontak dan peta</button></div>
            </form>
        </section>
    </div>
</div>
<?php mp_render_footer(); ?>
