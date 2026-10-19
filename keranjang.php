<?php
require_once __DIR__ . '/marketplace_lib.php';

if (!isset($_SESSION['mp_cart']) || !is_array($_SESSION['mp_cart'])) {
    $_SESSION['mp_cart'] = [];
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || !$quantity || $quantity < 1) {
                $error = 'Produk atau jumlah yang dimasukkan tidak valid.';
            } else {
                $stmt = mysqli_prepare($koneksi, "SELECT p.id, p.shop_id, p.stock FROM mp_products p JOIN mp_shops s ON s.id = p.shop_id WHERE p.id = ? AND p.status = 'approved' AND s.status = 'approved' LIMIT 1");
                mysqli_stmt_bind_param($stmt, 'i', $productId);
                mysqli_stmt_execute($stmt);
                $product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                if (!$product || $quantity > (int)$product['stock']) {
                    $error = 'Produk tidak tersedia atau jumlah melebihi stok.';
                } elseif (!empty($_SESSION['mp_cart']) && (int)($_SESSION['mp_cart_shop_id'] ?? 0) !== (int)$product['shop_id']) {
                    $error = 'Keranjang hanya dapat berisi produk dari satu toko. Selesaikan pesanan toko saat ini atau kosongkan keranjang terlebih dahulu.';
                } else {
                    $_SESSION['mp_cart_shop_id'] = (int)$product['shop_id'];
                    $_SESSION['mp_cart'][$productId] = min((int)$product['stock'], (int)($_SESSION['mp_cart'][$productId] ?? 0) + $quantity);
                    mp_set_flash('success', 'Produk ditambahkan ke keranjang.');
                    mp_redirect('keranjang.php');
                }
            }
        } elseif ($action === 'remove') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if ($productId && isset($_SESSION['mp_cart'][$productId])) {
                unset($_SESSION['mp_cart'][$productId]);
                if (empty($_SESSION['mp_cart'])) {
                    unset($_SESSION['mp_cart_shop_id']);
                }
                mp_redirect('keranjang.php');
            }
            $error = 'Produk tidak ditemukan di keranjang.';
        } elseif ($action === 'checkout') {
            $customerName = trim($_POST['customer_name'] ?? '');
            $customerPhone = trim($_POST['customer_phone'] ?? '');
            $shippingAddress = trim($_POST['shipping_address'] ?? '');
            $kecamatan = trim($_POST['kecamatan'] ?? '');
            $customerNote = trim($_POST['customer_note'] ?? '');
            $paymentMethod = $_POST['payment_method'] ?? '';
            if (!$customerName || !$customerPhone || !$shippingAddress || !$kecamatan || !in_array($paymentMethod, ['transfer', 'cod'], true)) {
                $error = 'Nama, nomor WhatsApp, alamat, kecamatan, dan metode pembayaran wajib diisi.';
            } elseif (mp_length($customerName) > 120 || mp_length($customerPhone) > 30 || mp_length($shippingAddress) > 1200 || mp_length($kecamatan) > 100 || mp_length($customerNote) > 1000) {
                $error = 'Salah satu data pesanan melebihi batas panjang yang diperbolehkan.';
            } elseif (empty($_SESSION['mp_cart']) || empty($_SESSION['mp_cart_shop_id'])) {
                $error = 'Keranjang Anda kosong.';
            } else {
                $shopId = (int)$_SESSION['mp_cart_shop_id'];
                $orderItems = [];
                $totalAmount = 0;
                $bankName = null;
                $accountName = null;
                $accountNumber = null;

                try {
                    mysqli_begin_transaction($koneksi);
                    $shopStmt = mysqli_prepare($koneksi, "SELECT bank_name, bank_account_name, bank_account_number FROM mp_shops WHERE id = ? AND status = 'approved' FOR UPDATE");
                    mysqli_stmt_bind_param($shopStmt, 'i', $shopId);
                    mysqli_stmt_execute($shopStmt);
                    $shop = mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt));
                    if (!$shop) {
                        throw new RuntimeException('Toko tidak tersedia untuk menerima pesanan.');
                    }
                    $bankName = $paymentMethod === 'transfer' ? $shop['bank_name'] : null;
                    $accountName = $paymentMethod === 'transfer' ? $shop['bank_account_name'] : null;
                    $accountNumber = $paymentMethod === 'transfer' ? $shop['bank_account_number'] : null;

                    foreach ($_SESSION['mp_cart'] as $productId => $quantity) {
                        $productId = (int)$productId;
                        $quantity = (int)$quantity;
                        $productStmt = mysqli_prepare($koneksi, "SELECT id, name, price, stock FROM mp_products WHERE id = ? AND shop_id = ? AND status = 'approved' FOR UPDATE");
                        mysqli_stmt_bind_param($productStmt, 'ii', $productId, $shopId);
                        mysqli_stmt_execute($productStmt);
                        $product = mysqli_fetch_assoc(mysqli_stmt_get_result($productStmt));
                        if (!$product || $quantity < 1 || $quantity > (int)$product['stock']) {
                            throw new RuntimeException('Stok atau ketersediaan produk berubah. Periksa kembali keranjang Anda.');
                        }
                        if ($quantity > intdiv(PHP_INT_MAX, (int)$product['price'])) {
                            throw new RuntimeException('Jumlah pesanan terlalu besar untuk dihitung. Kurangi jumlah produk.');
                        }
                        $subtotal = (int)$product['price'] * $quantity;
                        if ($totalAmount > PHP_INT_MAX - $subtotal) {
                            throw new RuntimeException('Total pesanan melebihi batas yang dapat diproses. Kurangi jumlah produk.');
                        }
                        $totalAmount += $subtotal;
                        $orderItems[] = ['id' => $productId, 'name' => $product['name'], 'price' => (int)$product['price'], 'quantity' => $quantity, 'subtotal' => $subtotal];
                    }

                    $orderCode = 'FT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(12)));
                    $paymentStatus = 'unpaid';
                    $orderStatus = $paymentMethod === 'transfer' ? 'menunggu_pembayaran' : 'pesanan_baru';
                    $noteValue = $customerNote !== '' ? $customerNote : null;
                    $orderStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_orders (order_code, shop_id, customer_name, customer_phone, shipping_address, kecamatan, customer_note, payment_method, payment_status, order_status, bank_name, bank_account_name, bank_account_number, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    mysqli_stmt_bind_param($orderStmt, 'sisssssssssssi', $orderCode, $shopId, $customerName, $customerPhone, $shippingAddress, $kecamatan, $noteValue, $paymentMethod, $paymentStatus, $orderStatus, $bankName, $accountName, $accountNumber, $totalAmount);
                    mysqli_stmt_execute($orderStmt);
                    $orderId = mysqli_insert_id($koneksi);

                    foreach ($orderItems as $item) {
                        $itemStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_order_items (order_id, product_id, product_name, unit_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?, ?)');
                        mysqli_stmt_bind_param($itemStmt, 'iisiii', $orderId, $item['id'], $item['name'], $item['price'], $item['quantity'], $item['subtotal']);
                        mysqli_stmt_execute($itemStmt);

                        $stockStmt = mysqli_prepare($koneksi, 'UPDATE mp_products SET stock = stock - ? WHERE id = ? AND stock >= ?');
                        mysqli_stmt_bind_param($stockStmt, 'iii', $item['quantity'], $item['id'], $item['quantity']);
                        mysqli_stmt_execute($stockStmt);
                        if (mysqli_stmt_affected_rows($stockStmt) !== 1) {
                            throw new RuntimeException('Stok produk tidak mencukupi. Periksa kembali keranjang Anda.');
                        }
                    }
                    mysqli_commit($koneksi);
                    try {
                        mp_mobile_push_queue('seller', $shopId, 'Pesanan baru', 'Ada pesanan baru di toko Anda.', 'toko_saya.php?menu=pesanan');
                    } catch (mysqli_sql_exception $notificationException) {
                        error_log('Marketplace order push queue failed for order ' . $orderId . ': ' . $notificationException->getMessage());
                    }
                    $_SESSION['mp_cart'] = [];
                    unset($_SESSION['mp_cart_shop_id']);
                    $_SESSION['mp_last_order_code'] = $orderCode;
                    mp_redirect('marketplace.php');
                } catch (RuntimeException $exception) {
                    mysqli_rollback($koneksi);
                    $error = $exception->getMessage();
                } catch (mysqli_sql_exception $exception) {
                    mysqli_rollback($koneksi);
                    error_log('Marketplace checkout failed: ' . $exception->getMessage());
                    $error = 'Pesanan belum dapat disimpan. Silakan coba kembali.';
                }
            }
        } else {
            $error = 'Aksi tidak dikenali.';
        }
    }
}

$cartRows = [];
$cartTotal = 0;
$cartShop = null;
if (!empty($_SESSION['mp_cart'])) {
    $shopId = (int)($_SESSION['mp_cart_shop_id'] ?? 0);
    $shopStmt = mysqli_prepare($koneksi, "SELECT business_name, phone FROM mp_shops WHERE id = ? AND status = 'approved'");
    mysqli_stmt_bind_param($shopStmt, 'i', $shopId);
    mysqli_stmt_execute($shopStmt);
    $cartShop = mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt));

    foreach ($_SESSION['mp_cart'] as $productId => $quantity) {
        $productId = (int)$productId;
        $stmt = mysqli_prepare($koneksi, "SELECT id, name, price, stock FROM mp_products WHERE id = ? AND shop_id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($stmt, 'ii', $productId, $shopId);
        mysqli_stmt_execute($stmt);
        $product = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($product) {
            $product['quantity'] = (int)$quantity;
            $product['subtotal'] = (int)$product['price'] * (int)$quantity;
            $cartTotal += $product['subtotal'];
            $cartRows[] = $product;
        } else {
            unset($_SESSION['mp_cart'][$productId]);
        }
    }
    if (empty($_SESSION['mp_cart'])) {
        unset($_SESSION['mp_cart_shop_id']);
    }
}

mp_render_header('Keranjang dan Pemesanan');
mp_render_flash();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><h1 class="h3 fw-bold mb-1">Keranjang belanja</h1><p class="small-muted mb-0">Pemesanan dibuat per toko agar pembayaran langsung diterima UMKM.</p></div>
    <a class="btn btn-outline-success" href="marketplace.php">Lanjut belanja</a>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
<?php if (!$cartRows || !$cartShop): ?>
    <div class="card p-5 text-center"><i class="bi bi-basket fs-1 text-market"></i><h2 class="h5 fw-bold mt-3">Keranjang masih kosong</h2><p class="small-muted">Pilih produk dari katalog untuk mulai memesan.</p><a class="btn btn-market mx-auto" href="marketplace.php">Lihat produk</a></div>
<?php else: ?>
    <div class="card mb-4"><div class="card-body p-4">
        <h2 class="h5 fw-bold mb-3"><i class="bi bi-shop me-2 text-market"></i><?= mp_escape($cartShop['business_name']) ?></h2>
        <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Produk</th><th>Harga</th><th>Jumlah</th><th>Subtotal</th><th></th></tr></thead><tbody>
        <?php foreach ($cartRows as $row): ?>
            <tr><td><?= mp_escape($row['name']) ?><?php if ($row['quantity'] > $row['stock']): ?><div class="text-danger small">Stok tersisa <?= (int)$row['stock'] ?></div><?php endif; ?></td><td><?= mp_format_rupiah($row['price']) ?></td><td><?= (int)$row['quantity'] ?></td><td><?= mp_format_rupiah($row['subtotal']) ?></td><td><form method="post"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button></form></td></tr>
        <?php endforeach; ?>
        </tbody><tfoot><tr><th colspan="3" class="text-end">Total produk (ongkir belum termasuk)</th><th><?= mp_format_rupiah($cartTotal) ?></th><th></th></tr></tfoot></table></div>
    </div></div>
    <div class="row g-4">
        <div class="col-lg-7"><div class="card p-4">
            <h2 class="h5 fw-bold mb-3">Data penerima dan pengiriman</h2>
            <form method="post">
                <?php mp_render_csrf(); ?><input type="hidden" name="action" value="checkout">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Nama penerima</label><input class="form-control" name="customer_name" maxlength="120" required></div>
                    <div class="col-md-6"><label class="form-label">Nomor WhatsApp/HP</label><input class="form-control" name="customer_phone" maxlength="30" required></div>
                    <div class="col-12"><label class="form-label">Alamat lengkap</label><textarea class="form-control" name="shipping_address" rows="2" maxlength="1200" required></textarea></div>
                    <div class="col-md-6"><label class="form-label">Kecamatan</label><input class="form-control" name="kecamatan" maxlength="100" required></div>
                    <div class="col-md-6"><label class="form-label">Catatan (opsional)</label><input class="form-control" name="customer_note" maxlength="1000"></div>
                    <div class="col-12"><label class="form-label">Metode pembayaran</label><select class="form-select" name="payment_method" required><option value="">Pilih metode</option><option value="transfer">Transfer bank langsung ke UMKM</option><option value="cod">Bayar tunai saat barang diterima (COD)</option></select><div class="form-text">Ongkos kirim dan ketersediaan COD dikonfirmasi langsung dengan penjual. COD bergantung pada layanan toko/kurir.</div></div>
                    <div class="col-12"><button class="btn btn-market fw-semibold" type="submit">Buat pesanan · <?= mp_format_rupiah($cartTotal) ?></button></div>
                </div>
            </form>
        </div></div>
        <div class="col-lg-5"><div class="card p-4">
            <h2 class="h6 fw-bold">Informasi penting</h2>
            <ul class="small-muted ps-3 mb-0"><li>Setelah pesanan dibuat, penjual menerima detail kontak dan alamat Anda.</li><li>Pembayaran transfer masuk langsung ke rekening UMKM. Pastikan nama toko dan jumlah transfer sebelum membayar.</li><li>Pengiriman, ongkos kirim, dan konfirmasi transaksi dikoordinasikan pembeli dan penjual.</li></ul>
        </div></div>
    </div>
<?php endif; ?>
<?php mp_render_footer(); ?>
