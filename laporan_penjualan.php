<?php
require_once __DIR__ . '/marketplace_lib.php';

$isAdmin = !empty($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$isSeller = !$isAdmin && !empty($_SESSION['mp_seller_id']);
if (!$isAdmin && !$isSeller) {
    mp_redirect('login.php');
}

$periodValue = $_GET['period'] ?? 'day';
$period = is_string($periodValue) ? $periodValue : '';
$dateValueInput = $_GET['date'] ?? null;
$dateValue = is_string($dateValueInput)
    ? trim($dateValueInput)
    : ($period === 'month' ? date('Y-m') : date('Y-m-d'));
$exportValue = $_GET['export'] ?? '';
$export = is_string($exportValue) ? $exportValue : '';
$periodPattern = $period === 'month' ? 'Y-m' : 'Y-m-d';
$datePattern = $period === 'month' ? '/^\d{4}-(0[1-9]|1[0-2])$/' : '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/';
$validPeriod = in_array($period, ['day', 'month'], true);
$parsedDate = $validPeriod && preg_match($datePattern, $dateValue)
    ? DateTimeImmutable::createFromFormat('!' . $periodPattern, $dateValue)
    : false;
$dateErrors = DateTimeImmutable::getLastErrors();
$validDate = $parsedDate !== false
    && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
    && $parsedDate->format($periodPattern) === $dateValue;

if (!$validPeriod || !$validDate || !in_array($export, ['', 'xlsx'], true)) {
    http_response_code(400);
    if ($export === 'xlsx') {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Parameter laporan tidak valid.';
        exit;
    }
}

if (!$validPeriod || !$validDate || !in_array($export, ['', 'xlsx'], true)) {
    $period = 'day';
    $dateValue = date('Y-m-d');
    $parsedDate = new DateTimeImmutable($dateValue);
    $error = 'Rentang laporan tidak valid. Pilih tanggal atau bulan yang benar lalu coba kembali.';
} else {
    $error = '';
}

$periodStart = $parsedDate;
$periodEnd = $period === 'month'
    ? $periodStart->modify('first day of next month')
    : $periodStart->modify('+1 day');
$periodStartValue = $periodStart->format('Y-m-d 00:00:00');
$periodEndValue = $periodEnd->format('Y-m-d 00:00:00');
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$periodLabel = $period === 'month'
    ? $monthNames[(int)$periodStart->format('n')] . ' ' . $periodStart->format('Y')
    : $periodStart->format('d-m-Y');

$sellerShop = null;
if ($isSeller) {
    $sellerShopId = (int)$_SESSION['mp_seller_id'];
    $shopStmt = mysqli_prepare($koneksi, 'SELECT id, business_name FROM mp_shops WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($shopStmt, 'i', $sellerShopId);
    mysqli_stmt_execute($shopStmt);
    $sellerShop = mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt));
    if (!$sellerShop) {
        unset($_SESSION['mp_seller_id']);
        mp_set_flash('danger', 'Akun toko tidak ditemukan. Silakan masuk kembali.');
        mp_redirect('login.php?role=seller');
    }
}

$salesSql = "SELECT s.id, s.business_name,
        COUNT(o.id) AS completed_orders,
        COALESCE(SUM(item_totals.item_count), 0) AS sold_items,
        COALESCE(SUM(o.total_amount), 0) AS total_sales
    FROM mp_shops s
    LEFT JOIN mp_orders o
        ON o.shop_id = s.id
        AND o.order_status = 'selesai'
        AND o.updated_at >= ?
        AND o.updated_at < ?
    LEFT JOIN (
        SELECT order_id, SUM(quantity) AS item_count
        FROM mp_order_items
        GROUP BY order_id
    ) AS item_totals ON item_totals.order_id = o.id";
if ($isSeller) {
    $salesSql .= ' WHERE s.id = ?';
}
$salesSql .= ' GROUP BY s.id, s.business_name ORDER BY s.business_name ASC';
$salesStmt = mysqli_prepare($koneksi, $salesSql);
if ($isSeller) {
    mysqli_stmt_bind_param($salesStmt, 'ssi', $periodStartValue, $periodEndValue, $sellerShopId);
} else {
    mysqli_stmt_bind_param($salesStmt, 'ss', $periodStartValue, $periodEndValue);
}
mysqli_stmt_execute($salesStmt);
$salesResult = mysqli_stmt_get_result($salesStmt);

$salesRows = [];
$totals = ['completed_orders' => 0, 'sold_items' => 0, 'total_sales' => 0];
while ($salesRow = mysqli_fetch_assoc($salesResult)) {
    $salesRow['completed_orders'] = (int)$salesRow['completed_orders'];
    $salesRow['sold_items'] = (int)$salesRow['sold_items'];
    $salesRow['total_sales'] = (int)$salesRow['total_sales'];
    $totals['completed_orders'] += $salesRow['completed_orders'];
    $totals['sold_items'] += $salesRow['sold_items'];
    $totals['total_sales'] += $salesRow['total_sales'];
    $salesRows[] = $salesRow;
}

if ($export === 'xlsx' && $error === '') {
    $spreadsheetRows = [];
    foreach ($salesRows as $salesRow) {
        $spreadsheetRows[] = [
            $periodLabel,
            $salesRow['business_name'],
            $salesRow['completed_orders'],
            $salesRow['sold_items'],
            $salesRow['total_sales'],
        ];
    }
    $spreadsheetRows[] = [
        $periodLabel,
        $isAdmin ? 'TOTAL KESELURUHAN' : 'TOTAL TOKO',
        $totals['completed_orders'],
        $totals['sold_items'],
        $totals['total_sales'],
    ];
    $fileScope = $isAdmin ? 'semua-toko' : 'toko';
    $fileName = 'laporan-penjualan-' . $fileScope . '-' . $period . '-' . $dateValue . '.xlsx';
    mp_download_xlsx($fileName, [
        'Periode selesai',
        'Nama toko',
        'Jumlah pesanan selesai',
        'Jumlah barang terjual',
        'Total penjualan (Rp)',
    ], $spreadsheetRows);
}

$periodQuery = http_build_query(['period' => $period, 'date' => $dateValue]);
$exportUrl = 'laporan_penjualan.php?' . $periodQuery . '&export=xlsx';
$sidebarClass = $isAdmin ? 'admin-sidebar' : 'seller-sidebar';
$sidebarTitle = $isAdmin ? 'Menu administrator' : 'Menu toko';

mp_render_header('Laporan Penjualan', $isSeller);
mp_render_flash();
?>
<style>
    .sales-report-layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .sales-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .sales-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .sales-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .88rem; font-weight: 600; text-decoration: none; }
    .sales-sidebar .nav-link:hover, .sales-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .sales-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    .report-card { border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .report-actions { display: flex; flex-wrap: wrap; gap: .55rem; }
    .report-action { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; min-height: 40px; border: 1px solid #cbd9d0; border-radius: .65rem; padding: .5rem .8rem; background: #fff; color: #315548; font: inherit; font-size: .86rem; font-weight: 600; text-decoration: none; cursor: pointer; }
    .report-action:hover { border-color: #167354; background: #eff7f1; color: #126248; }
    @media (max-width: 767.98px) { .sales-report-layout { grid-template-columns: 1fr; gap: 1rem; } .sales-sidebar { position: static; } .sales-sidebar .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; } .sales-sidebar .nav-link { white-space: nowrap; } }
    @media print { body { background: #fff !important; } .navbar-market, footer, .sales-sidebar, .report-filters, .report-actions, .alert { display: none !important; } main.container { max-width: none !important; padding: 0 !important; } .sales-report-layout { display: block; } .report-card { border: 0; box-shadow: none; } }
</style>
<div class="sales-report-layout">
    <aside class="sales-sidebar <?= mp_escape($sidebarClass) ?>" aria-label="Navigasi laporan">
        <div class="sales-sidebar-title"><?= mp_escape($sidebarTitle) ?></div>
        <nav class="nav flex-column">
            <?php if ($isAdmin): ?>
                <a class="nav-link" href="admin_marketplace.php"><i class="bi bi-speedometer2"></i>Ringkasan &amp; pengawasan</a>
                <a class="nav-link active" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
                <a class="nav-link" href="admin_slide.php"><i class="bi bi-images"></i>Gambar slide beranda</a>
                <a class="nav-link" href="admin_logo.php"><i class="bi bi-badge-tm"></i>Logo header aplikasi</a>
                <a class="nav-link" href="admin_login_image.php"><i class="bi bi-person-badge"></i>Gambar halaman login</a>
                <a class="nav-link" href="admin_footer.php"><i class="bi bi-layout-text-window-reverse"></i>Footer aplikasi</a>
                <a class="nav-link" href="admin_contact.php"><i class="bi bi-telephone"></i>Hubungi kami</a>
                <a class="nav-link" href="marketplace.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>Lihat marketplace</a>
                <a class="nav-link text-danger" href="keluar_admin.php"><i class="bi bi-box-arrow-right"></i>Keluar admin</a>
            <?php else: ?>
                <a class="nav-link" href="toko_saya.php#ringkasan"><i class="bi bi-speedometer2"></i>Ringkasan toko</a>
                <a class="nav-link" href="toko_saya.php#profil"><i class="bi bi-shop"></i>Profil dan rekening</a>
                <a class="nav-link" href="toko_saya.php#keamanan"><i class="bi bi-shield-lock"></i>Keamanan akun</a>
                <a class="nav-link" href="toko_saya.php#produk"><i class="bi bi-plus-square"></i>Tambah produk</a>
                <a class="nav-link" href="toko_saya.php#daftar-produk"><i class="bi bi-box-seam"></i>Produk toko</a>
                <a class="nav-link" href="toko_saya.php#pesanan"><i class="bi bi-receipt"></i>Pesanan masuk</a>
                <a class="nav-link active" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
                <a class="nav-link text-danger" href="keluar_umkm.php"><i class="bi bi-box-arrow-right"></i>Keluar toko</a>
            <?php endif; ?>
        </nav>
    </aside>
    <div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <span class="badge text-bg-success mb-2"><?= $isAdmin ? 'Panel pemerintah daerah' : 'Laporan toko' ?></span>
                <h1 class="h3 fw-bold mb-1">Laporan penjualan <?= $isAdmin ? 'setiap toko' : 'toko' ?></h1>
                <p class="small-muted mb-0"><?= $isAdmin ? 'Ringkasan transaksi selesai per toko.' : mp_escape($sellerShop['business_name']) ?> · Periode: <?= mp_escape($periodLabel) ?></p>
            </div>
            <div class="report-actions">
                <button class="report-action" type="button" onclick="window.print()"><i class="bi bi-printer"></i>Print laporan</button>
                <a class="report-action" href="<?= mp_escape($exportUrl) ?>"><i class="bi bi-file-earmark-spreadsheet"></i>Download .xlsx</a>
            </div>
        </div>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= mp_escape($error) ?></div><?php endif; ?>
        <section class="report-card p-4 mb-4 report-filters">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-sm-4 col-lg-3">
                    <label class="form-label" for="report-period">Jenis periode</label>
                    <select class="form-select" name="period" id="report-period">
                        <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Per hari</option>
                        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Per bulan</option>
                    </select>
                </div>
                <div class="col-sm-5 col-lg-4">
                    <label class="form-label" for="report-date"><?= $period === 'month' ? 'Pilih bulan' : 'Pilih tanggal' ?></label>
                    <input class="form-control" type="<?= $period === 'month' ? 'month' : 'date' ?>" name="date" id="report-date" value="<?= mp_escape($dateValue) ?>" required>
                </div>
                <div class="col-sm-3 col-lg-2"><button class="report-action w-100" type="submit"><i class="bi bi-funnel"></i>Tampilkan</button></div>
            </form>
        </section>
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-4"><div class="card p-3 h-100"><div class="small-muted">Pesanan selesai</div><div class="h4 fw-bold mb-0"><?= number_format($totals['completed_orders'], 0, ',', '.') ?></div></div></div>
            <div class="col-12 col-md-4"><div class="card p-3 h-100"><div class="small-muted">Barang terjual</div><div class="h4 fw-bold mb-0"><?= number_format($totals['sold_items'], 0, ',', '.') ?></div></div></div>
            <div class="col-12 col-md-4"><div class="card p-3 h-100"><div class="small-muted">Total penjualan</div><div class="h4 fw-bold text-market mb-0"><?= mp_format_rupiah($totals['total_sales']) ?></div></div></div>
        </div>
        <section class="report-card p-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 fw-bold mb-0">Rincian penjualan</h2>
                <span class="small-muted small"><?= $isAdmin ? count($salesRows) . ' toko' : 'Toko: ' . mp_escape($sellerShop['business_name']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><?php if ($isAdmin): ?><th>Nama toko</th><?php endif; ?><th>Pesanan selesai</th><th>Barang terjual</th><th>Total penjualan</th></tr></thead>
                    <tbody>
                        <?php if ($salesRows === []): ?>
                            <tr><td colspan="<?= $isAdmin ? 4 : 3 ?>" class="text-center small-muted py-4">Belum ada data toko untuk ditampilkan.</td></tr>
                        <?php else: foreach ($salesRows as $salesRow): ?>
                            <tr>
                                <?php if ($isAdmin): ?><td><strong><?= mp_escape($salesRow['business_name']) ?></strong></td><?php endif; ?>
                                <td><?= number_format($salesRow['completed_orders'], 0, ',', '.') ?></td>
                                <td><?= number_format($salesRow['sold_items'], 0, ',', '.') ?></td>
                                <td class="fw-semibold"><?= mp_format_rupiah($salesRow['total_sales']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light fw-bold">
                            <?php if ($isAdmin): ?><td>Total keseluruhan</td><?php endif; ?>
                            <td><?= number_format($totals['completed_orders'], 0, ',', '.') ?></td>
                            <td><?= number_format($totals['sold_items'], 0, ',', '.') ?></td>
                            <td><?= mp_format_rupiah($totals['total_sales']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="small-muted small mt-3 mb-0">Hanya pesanan berstatus selesai yang dihitung. Tanggal laporan mengikuti waktu pesanan diselesaikan.</p>
        </section>
    </div>
</div>
<script>
    document.getElementById('report-period').addEventListener('change', function () {
        const dateInput = document.getElementById('report-date');
        if (this.value === 'month' && dateInput.type === 'date') {
            dateInput.value = dateInput.value.slice(0, 7);
            dateInput.type = 'month';
        } else if (this.value === 'day' && dateInput.type === 'month') {
            dateInput.value = dateInput.value ? `${dateInput.value}-01` : '';
            dateInput.type = 'date';
        }
    });
</script>
<?php mp_render_footer(); ?>
