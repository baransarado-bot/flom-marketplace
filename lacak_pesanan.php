<?php
require_once __DIR__ . '/marketplace_lib.php';

$order = null;
$itemsResult = null;
$error = '';
$reviewSuccess = '';
$orderCode = '';
$phone = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? 'lookup';
        $orderCode = strtoupper(trim($_POST['order_code'] ?? ''));
        $phone = trim($_POST['customer_phone'] ?? '');
        if ($orderCode === '' || $phone === '') {
            $error = 'Nomor pesanan dan nomor WhatsApp/HP wajib diisi.';
        } elseif ($action === 'review') {
            $orderItemId = filter_var($_POST['order_item_id'] ?? null, FILTER_VALIDATE_INT);
            $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
            $reviewText = trim($_POST['review_text'] ?? '');
            if (!$orderItemId || !$rating || $rating < 1 || $rating > 5 || $reviewText === '' || mp_length($reviewText) > 1500) {
                $error = 'Rating dan ulasan wajib diisi. Ulasan maksimal 1.500 karakter.';
            } else {
                $eligibleStmt = mysqli_prepare($koneksi, "SELECT o.id AS order_id, oi.id AS order_item_id, oi.product_id
                    FROM mp_orders o JOIN mp_order_items oi ON oi.order_id = o.id
                    WHERE o.order_code = ? AND o.customer_phone = ? AND o.order_status = 'selesai'
                    AND oi.id = ? AND oi.product_id IS NOT NULL LIMIT 1");
                mysqli_stmt_bind_param($eligibleStmt, 'ssi', $orderCode, $phone, $orderItemId);
                mysqli_stmt_execute($eligibleStmt);
                $eligibleItem = mysqli_fetch_assoc(mysqli_stmt_get_result($eligibleStmt));
                if (!$eligibleItem) {
                    $error = 'Ulasan hanya dapat diberikan untuk produk pada pesanan selesai milik Anda.';
                } else {
                    try {
                        $insertReview = mysqli_prepare($koneksi, 'INSERT INTO mp_product_reviews (product_id, order_item_id, rating, review_text) VALUES (?, ?, ?, ?)');
                        mysqli_stmt_bind_param($insertReview, 'iiis', $eligibleItem['product_id'], $orderItemId, $rating, $reviewText);
                        mysqli_stmt_execute($insertReview);
                        $reviewSuccess = 'Terima kasih! Ulasan Anda berhasil dikirim dan ditandai sebagai pembelian terverifikasi.';
                    } catch (mysqli_sql_exception $exception) {
                        if ((int)$exception->getCode() === 1062) {
                            $error = 'Anda sudah memberikan ulasan untuk produk ini pada pesanan tersebut.';
                        } else {
                            error_log('Marketplace product review failed for order item ' . $orderItemId . ': ' . $exception->getMessage());
                            $error = 'Ulasan belum dapat disimpan. Silakan coba kembali.';
                        }
                    }
                }
            }
        } elseif ($action !== 'lookup') {
            $error = 'Aksi tidak dikenali.';
        }

        if (in_array($action, ['lookup', 'review'], true)) {
            $stmt = mysqli_prepare($koneksi, 'SELECT o.*, s.business_name, s.phone AS shop_phone FROM mp_orders o JOIN mp_shops s ON s.id = o.shop_id WHERE o.order_code = ? AND o.customer_phone = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'ss', $orderCode, $phone);
            mysqli_stmt_execute($stmt);
            $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$order && $error === '' && $action === 'lookup') {
                $error = 'Pesanan tidak ditemukan. Pastikan nomor pesanan dan nomor HP sama dengan data saat checkout.';
            }
        }
        if ($order) {
            $itemsStmt = mysqli_prepare($koneksi, 'SELECT oi.id AS order_item_id, oi.product_id, oi.product_name, oi.unit_price, oi.quantity, oi.subtotal, r.rating AS review_rating, r.review_text, r.created_at AS review_created_at
                FROM mp_order_items oi LEFT JOIN mp_product_reviews r ON r.order_item_id = oi.id WHERE oi.order_id = ? ORDER BY oi.id');
            mysqli_stmt_bind_param($itemsStmt, 'i', $order['id']);
            mysqli_stmt_execute($itemsStmt);
            $itemsResult = mysqli_stmt_get_result($itemsStmt);
        }
    }
}

$statusLabels = ['menunggu_pembayaran' => 'Menunggu pembayaran', 'pesanan_baru' => 'Pesanan baru', 'diproses' => 'Sedang diproses', 'dikirim' => 'Dalam pengiriman', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];
mp_render_header('Lacak Pesanan');
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <section class="card p-4 p-md-5">
            <h1 class="h3 fw-bold">Lacak pesanan</h1>
            <p class="small-muted">Masukkan kode pesanan dan nomor WhatsApp/HP yang digunakan saat checkout.</p>
            <?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
            <?php if ($reviewSuccess !== ''): ?><div class="alert alert-success"><?= mp_escape($reviewSuccess) ?></div><?php endif; ?>
            <form method="post" class="row g-3 mb-4">
                <?php mp_render_csrf(); ?>
                <input type="hidden" name="action" value="lookup">
                <div class="col-md-6"><label class="form-label">Kode pesanan</label><input class="form-control text-uppercase" name="order_code" maxlength="40" value="<?= mp_escape($orderCode) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Nomor WhatsApp/HP</label><input class="form-control" name="customer_phone" maxlength="30" value="<?= mp_escape($phone) ?>" required></div>
                <div class="col-12"><button class="btn btn-market" type="submit">Cari pesanan</button></div>
            </form>
            <?php if ($order): ?>
                <hr>
                <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><h2 class="h5 fw-bold mb-0"><?= mp_escape($order['order_code']) ?></h2><span class="badge text-bg-success"><?= mp_escape($statusLabels[$order['order_status']] ?? $order['order_status']) ?></span></div>
                <p class="mb-1"><strong>Toko:</strong> <?= mp_escape($order['business_name']) ?> · <a href="https://wa.me/<?= mp_escape(preg_replace('/\D+/', '', $order['shop_phone'])) ?>" target="_blank" rel="noopener">Hubungi penjual via WhatsApp</a></p>
                <p class="mb-1"><strong>Penerima:</strong> <?= mp_escape($order['customer_name']) ?></p>
                <p class="mb-1"><strong>Alamat:</strong> <?= mp_escape($order['shipping_address']) ?>, <?= mp_escape($order['kecamatan']) ?></p>
                <p class="mb-3"><strong>Pembayaran:</strong> <?= $order['payment_method'] === 'transfer' ? 'Transfer langsung · ' . ($order['payment_status'] === 'paid' ? 'dikonfirmasi penjual' : 'menunggu konfirmasi penjual') : 'COD · ' . ($order['payment_status'] === 'paid' ? 'diterima' : 'dibayar saat barang diterima') ?></p>
                <?php if ($order['payment_method'] === 'transfer' && $order['payment_status'] === 'unpaid' && $order['order_status'] !== 'dibatalkan'): ?>
                    <div class="alert alert-info">Silakan transfer <strong><?= mp_format_rupiah($order['total_amount']) ?></strong> ke <?= mp_escape($order['bank_name']) ?> · <strong><?= mp_escape($order['bank_account_number']) ?></strong> atas nama <strong><?= mp_escape($order['bank_account_name']) ?></strong>. Dana diterima langsung oleh UMKM. Hubungi penjual untuk konfirmasi.</div>
                <?php endif; ?>
                <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Produk</th><th>Harga</th><th>Jumlah</th><th>Subtotal</th><?php if ($order['order_status'] === 'selesai'): ?><th>Ulasan</th><?php endif; ?></tr></thead><tbody>
                    <?php while ($item = mysqli_fetch_assoc($itemsResult)): ?>
                        <tr>
                            <td><?= mp_escape($item['product_name']) ?></td><td><?= mp_format_rupiah($item['unit_price']) ?></td><td><?= (int)$item['quantity'] ?></td><td><?= mp_format_rupiah($item['subtotal']) ?></td>
                            <?php if ($order['order_status'] === 'selesai'): ?>
                                <td>
                                    <?php if ($item['review_rating'] !== null): ?>
                                        <span class="text-warning" aria-label="Rating <?= (int)$item['review_rating'] ?> dari 5"><?= str_repeat('&#9733;', (int)$item['review_rating']) ?></span><div class="small"><?= mp_escape($item['review_text']) ?></div><span class="small-muted small">Ulasan terkirim</span>
                                    <?php elseif ($item['product_id'] !== null): ?>
                                        <form method="post" class="review-form">
                                            <?php mp_render_csrf(); ?>
                                            <input type="hidden" name="action" value="review"><input type="hidden" name="order_code" value="<?= mp_escape($order['order_code']) ?>"><input type="hidden" name="customer_phone" value="<?= mp_escape($phone) ?>"><input type="hidden" name="order_item_id" value="<?= (int)$item['order_item_id'] ?>">
                                            <label class="visually-hidden" for="rating-<?= (int)$item['order_item_id'] ?>">Rating</label>
                                            <select class="form-select form-select-sm mb-2" name="rating" id="rating-<?= (int)$item['order_item_id'] ?>" required><option value="">Pilih bintang</option><option value="5">★★★★★ · Sangat puas</option><option value="4">★★★★☆ · Puas</option><option value="3">★★★☆☆ · Cukup</option><option value="2">★★☆☆☆ · Kurang</option><option value="1">★☆☆☆☆ · Tidak puas</option></select>
                                            <label class="visually-hidden" for="review-<?= (int)$item['order_item_id'] ?>">Tulis ulasan</label>
                                            <textarea class="form-control form-control-sm mb-2" name="review_text" id="review-<?= (int)$item['order_item_id'] ?>" rows="3" maxlength="1500" placeholder="Bagikan pengalaman Anda" required></textarea>
                                            <button class="btn btn-market btn-sm" type="submit">Kirim ulasan</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="small-muted">Produk tidak lagi tersedia untuk ulasan.</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody><tfoot><tr><th colspan="<?= $order['order_status'] === 'selesai' ? '4' : '3' ?>" class="text-end">Total produk (ongkir belum termasuk)</th><th><?= mp_format_rupiah($order['total_amount']) ?></th><?php if ($order['order_status'] === 'selesai'): ?><th></th><?php endif; ?></tr></tfoot></table></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php mp_render_footer(); ?>
