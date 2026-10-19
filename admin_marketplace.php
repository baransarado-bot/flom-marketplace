<?php
require_once __DIR__ . '/marketplace_lib.php';

if (empty($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    mp_redirect('login_admin.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            $error = 'Data yang dipilih tidak valid.';
        } elseif ($action === 'shop_approve' || $action === 'shop_reject') {
            $status = $action === 'shop_approve' ? 'approved' : 'rejected';
            $stmt = mysqli_prepare($koneksi, 'UPDATE mp_shops SET status = ? WHERE id = ?');
            mysqli_stmt_bind_param($stmt, 'si', $status, $id);
            mysqli_stmt_execute($stmt);
            mp_set_flash('success', 'Status verifikasi toko diperbarui.');
            mp_redirect('admin_marketplace.php#toko');
        } elseif ($action === 'product_approve' || $action === 'product_reject') {
            $status = $action === 'product_approve' ? 'approved' : 'rejected';
            $stmt = mysqli_prepare($koneksi, "UPDATE mp_products p JOIN mp_shops s ON s.id = p.shop_id SET p.status = ? WHERE p.id = ? AND p.status = 'pending' AND s.status = 'approved'");
            mysqli_stmt_bind_param($stmt, 'si', $status, $id);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) === 0) {
                $error = 'Produk tidak ditemukan, sudah diproses, atau toko belum diverifikasi.';
            } else {
                mp_set_flash('success', 'Status peninjauan produk diperbarui.');
                mp_redirect('admin_marketplace.php#produk');
            }
        } else {
            $error = 'Aksi tidak dikenali.';
        }
    }
}

$stats = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT
    (SELECT COUNT(*) FROM mp_shops WHERE status = 'pending') AS pending_shops,
    (SELECT COUNT(*) FROM mp_shops WHERE status = 'approved') AS approved_shops,
    (SELECT COUNT(*) FROM mp_products WHERE status = 'pending') AS pending_products,
    (SELECT COUNT(*) FROM mp_orders) AS total_orders,
    (SELECT COALESCE(SUM(total_amount), 0) FROM mp_orders WHERE order_status = 'selesai') AS completed_sales"));
$shopsResult = mysqli_query($koneksi, "SELECT id, business_name, logo_path, owner_name, category, phone, address, kecamatan, kelurahan, bank_name, bank_account_name, bank_account_number, status, created_at FROM mp_shops ORDER BY FIELD(status, 'pending', 'approved', 'rejected'), created_at DESC LIMIT 100");
$productsResult = mysqli_query($koneksi, "SELECT p.id, p.name, p.category, p.price, p.stock, p.image_path, p.created_at, s.business_name FROM mp_products p JOIN mp_shops s ON s.id = p.shop_id WHERE p.status = 'pending' AND s.status = 'approved' ORDER BY p.created_at ASC LIMIT 100");
$ordersResult = mysqli_query($koneksi, 'SELECT o.order_code, o.customer_name, o.customer_phone, o.kecamatan, o.payment_method, o.payment_status, o.order_status, o.total_amount, o.created_at, s.business_name FROM mp_orders o JOIN mp_shops s ON s.id = o.shop_id ORDER BY o.created_at DESC LIMIT 50');
$orderStatuses = ['menunggu_pembayaran' => 'Menunggu pembayaran', 'pesanan_baru' => 'Pesanan baru', 'diproses' => 'Diproses', 'dikirim' => 'Dikirim', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];
mp_render_header('Admin Marketplace');
mp_render_flash();
?>
<style>
    .admin-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .admin-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .admin-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .admin-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .9rem; font-weight: 600; text-decoration: none; }
    .admin-sidebar .nav-link:hover, .admin-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .admin-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    @media (max-width: 767.98px) { .admin-layout { grid-template-columns: 1fr; gap: 1rem; } .admin-sidebar { position: static; } .admin-sidebar .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; } .admin-sidebar .nav-link { white-space: nowrap; } }
</style>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Navigasi administrator">
        <div class="admin-sidebar-title">Menu administrator</div>
        <nav class="nav flex-column">
            <a class="nav-link active" href="#ringkasan"><i class="bi bi-speedometer2"></i>Ringkasan</a>
            <a class="nav-link" href="#toko"><i class="bi bi-shop"></i>Verifikasi toko</a>
            <a class="nav-link" href="#produk"><i class="bi bi-box-seam"></i>Verifikasi produk</a>
            <a class="nav-link" href="#pesanan"><i class="bi bi-receipt"></i>Pemantauan pesanan</a>
            <a class="nav-link" href="komunikasi.php"><i class="bi bi-chat-dots"></i>Pesan calon penjual / toko</a>
            <a class="nav-link" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
            <a class="nav-link" href="admin_slide.php"><i class="bi bi-images"></i>Gambar slide beranda</a>
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
    <div><span class="badge text-bg-success mb-2">Panel Administrator FLOM Marketplace</span><h1 class="h3 fw-bold mb-1">Pengawasan Marketplace UMKM/EKRAF</h1><p class="small-muted mb-0">Verifikasi toko dan produk, serta pantau transaksi.</p></div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="marketplace.php">Lihat marketplace</a>
        <a class="btn btn-outline-danger" href="keluar_admin.php">Keluar admin</a>
    </div>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
<div class="row g-3 mb-4" id="ringkasan">
    <div class="col-6 col-lg"><div class="card p-3 h-100"><div class="small-muted">Toko menunggu</div><div class="h3 fw-bold mb-0"><?= (int)$stats['pending_shops'] ?></div></div></div>
    <div class="col-6 col-lg"><div class="card p-3 h-100"><div class="small-muted">Toko aktif</div><div class="h3 fw-bold mb-0"><?= (int)$stats['approved_shops'] ?></div></div></div>
    <div class="col-6 col-lg"><div class="card p-3 h-100"><div class="small-muted">Produk ditinjau</div><div class="h3 fw-bold mb-0"><?= (int)$stats['pending_products'] ?></div></div></div>
    <div class="col-6 col-lg"><div class="card p-3 h-100"><div class="small-muted">Total pesanan</div><div class="h3 fw-bold mb-0"><?= (int)$stats['total_orders'] ?></div></div></div>
    <div class="col-12 col-lg"><div class="card p-3 h-100"><div class="small-muted">Nilai pesanan selesai*</div><div class="h5 fw-bold text-market mb-0"><?= mp_format_rupiah($stats['completed_sales']) ?></div></div></div>
</div>
<p class="small-muted small">*Nilai transaksi selesai merupakan data pemantauan; dana transfer tetap diterima langsung oleh UMKM.</p>

<section id="produk" class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Produk menunggu peninjauan</h2><span class="small-muted small">100 produk terbaru</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Produk</th><th>Toko</th><th>Kategori</th><th>Harga / stok</th><th>Aksi</th></tr></thead><tbody>
    <?php if (mysqli_num_rows($productsResult) === 0): ?><tr><td colspan="5" class="text-center small-muted py-4">Tidak ada produk yang menunggu peninjauan.</td></tr><?php else: while ($product = mysqli_fetch_assoc($productsResult)): ?>
        <tr><td><?php if ($product['image_path']): ?><img src="<?= mp_escape($product['image_path']) ?>" alt="" width="56" height="48" class="rounded object-fit-cover me-2"><?php endif; ?><strong><?= mp_escape($product['name']) ?></strong></td><td><?= mp_escape($product['business_name']) ?></td><td><?= mp_escape($product['category']) ?></td><td><?= mp_format_rupiah($product['price']) ?><div class="small-muted small">Stok <?= (int)$product['stock'] ?></div></td><td class="text-nowrap"><form method="post" class="d-inline"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="product_approve"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><button class="btn btn-sm btn-success" type="submit">Setujui</button></form><form method="post" class="d-inline" onsubmit="return confirm('Tolak produk ini?')"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="product_reject"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Tolak</button></form></td></tr>
    <?php endwhile; endif; ?>
    </tbody></table></div>
</section>

<section id="toko" class="card p-4 mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Pendaftaran dan verifikasi toko</h2><span class="small-muted small">100 toko terbaru</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Usaha / pemilik</th><th>Kontak dan lokasi</th><th>Rekening transfer</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php if (mysqli_num_rows($shopsResult) === 0): ?><tr><td colspan="5" class="text-center small-muted py-4">Belum ada pendaftaran toko.</td></tr><?php else: while ($shop = mysqli_fetch_assoc($shopsResult)): ?>
        <tr><td><?php if ($shop['logo_path']): ?><img src="<?= mp_escape($shop['logo_path']) ?>" alt="Logo <?= mp_escape($shop['business_name']) ?>" width="38" height="38" class="rounded border me-2" style="object-fit:contain"><?php endif; ?><strong><?= mp_escape($shop['business_name']) ?></strong><div class="small-muted small"><?= mp_escape($shop['owner_name']) ?> · <?= mp_escape($shop['category']) ?></div></td><td><?= mp_escape($shop['phone']) ?><div class="small-muted small"><?= mp_escape($shop['address']) ?>, <?= mp_escape(trim(($shop['kelurahan'] ? $shop['kelurahan'] . ', ' : '') . $shop['kecamatan'], ', ')) ?></div></td><td><?= mp_escape($shop['bank_name']) ?><div class="small-muted small"><?= mp_escape($shop['bank_account_name']) ?> · <?= mp_escape($shop['bank_account_number']) ?></div></td><td><span class="badge text-bg-<?= $shop['status'] === 'approved' ? 'success' : ($shop['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= $shop['status'] === 'approved' ? 'Disetujui' : ($shop['status'] === 'rejected' ? 'Ditolak' : 'Menunggu') ?></span></td><td class="text-nowrap"><?php if ($shop['status'] !== 'approved'): ?><form method="post" class="d-inline"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="shop_approve"><input type="hidden" name="id" value="<?= (int)$shop['id'] ?>"><button class="btn btn-sm btn-success" type="submit">Setujui</button></form><?php endif; ?><?php if ($shop['status'] !== 'rejected'): ?><form method="post" class="d-inline" onsubmit="return confirm('Tolak pendaftaran toko ini?')"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="shop_reject"><input type="hidden" name="id" value="<?= (int)$shop['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Tolak</button></form><?php endif; ?></td></tr>
    <?php endwhile; endif; ?>
    </tbody></table></div>
</section>

<section class="card p-4" id="pesanan">
    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Pemantauan pesanan</h2><span class="small-muted small">50 pesanan terbaru · tidak ada dana yang ditampung platform</span></div>
    <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Pesanan</th><th>Pembeli</th><th>Toko</th><th>Pembayaran</th><th>Status pesanan</th><th>Total</th><th>Waktu</th></tr></thead><tbody>
    <?php if (mysqli_num_rows($ordersResult) === 0): ?><tr><td colspan="7" class="text-center small-muted py-4">Belum ada transaksi.</td></tr><?php else: while ($order = mysqli_fetch_assoc($ordersResult)): ?>
        <tr><td><?= mp_escape($order['order_code']) ?></td><td><?= mp_escape($order['customer_name']) ?><div class="small-muted small"><?= mp_escape($order['customer_phone']) ?> · <?= mp_escape($order['kecamatan']) ?></div></td><td><?= mp_escape($order['business_name']) ?></td><td><?= $order['payment_method'] === 'transfer' ? 'Transfer' : 'COD' ?><div class="small-muted small"><?= $order['payment_status'] === 'paid' ? 'Dibayar' : 'Belum dibayar' ?></div></td><td><?= mp_escape($orderStatuses[$order['order_status']] ?? $order['order_status']) ?></td><td><?= mp_format_rupiah($order['total_amount']) ?></td><td><?= mp_escape(date('d-m-Y H:i', strtotime($order['created_at']))) ?></td></tr>
    <?php endwhile; endif; ?>
    </tbody></table></div>
</section>
    </div>
</div>
<?php mp_render_footer(); ?>
