<?php
require_once __DIR__ . '/marketplace_lib.php';

$productId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    http_response_code(404);
    $product = null;
} else {
    $productStmt = mysqli_prepare($koneksi, "SELECT p.id, p.shop_id, p.name, p.description, p.category, p.price, p.stock, p.image_path, s.business_name, s.logo_path, s.phone, s.facebook_url, s.instagram_url, s.kecamatan, s.kelurahan
        FROM mp_products p JOIN mp_shops s ON s.id = p.shop_id
        WHERE p.id = ? AND p.status = 'approved' AND s.status = 'approved' LIMIT 1");
    mysqli_stmt_bind_param($productStmt, 'i', $productId);
    mysqli_stmt_execute($productStmt);
    $product = mysqli_fetch_assoc(mysqli_stmt_get_result($productStmt));
    if (!$product) {
        http_response_code(404);
    }
}

$averageRating = 0.0;
$reviewCount = 0;
$reviews = [];
$productImages = [];
$sameShopProducts = [];
$recommendedProducts = [];
$productCategoryTones = [
    'Makanan' => 'food',
    'Kuliner' => 'food',
    'Minuman' => 'drink',
    'Tenun' => 'weaving',
    'Fashion / Tenun' => 'weaving',
    'Kerajinan Tangan' => 'craft',
    'Kriya / Kerajinan' => 'craft',
];
if ($product) {
    if ($product['image_path']) {
        $productImages[] = $product['image_path'];
    }
    $imagesStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_product_images WHERE product_id = ? ORDER BY sort_order, id');
    mysqli_stmt_bind_param($imagesStmt, 'i', $productId);
    mysqli_stmt_execute($imagesStmt);
    $imagesResult = mysqli_stmt_get_result($imagesStmt);
    while ($productImage = mysqli_fetch_assoc($imagesResult)) {
        $productImages[] = $productImage['image_path'];
    }

    $ratingStmt = mysqli_prepare($koneksi, 'SELECT COALESCE(AVG(rating), 0) AS average_rating, COUNT(*) AS review_count FROM mp_product_reviews WHERE product_id = ?');
    mysqli_stmt_bind_param($ratingStmt, 'i', $productId);
    mysqli_stmt_execute($ratingStmt);
    $ratingSummary = mysqli_fetch_assoc(mysqli_stmt_get_result($ratingStmt));
    $averageRating = (float)$ratingSummary['average_rating'];
    $reviewCount = (int)$ratingSummary['review_count'];

    $reviewStmt = mysqli_prepare($koneksi, 'SELECT rating, review_text, created_at FROM mp_product_reviews WHERE product_id = ? ORDER BY created_at DESC, id DESC LIMIT 30');
    mysqli_stmt_bind_param($reviewStmt, 'i', $productId);
    mysqli_stmt_execute($reviewStmt);
    $reviewResult = mysqli_stmt_get_result($reviewStmt);
    while ($review = mysqli_fetch_assoc($reviewResult)) {
        $reviews[] = $review;
    }

    $recommendationQuery = "SELECT p.id, p.shop_id, p.name, p.description, p.category, p.price, p.stock, p.image_path, s.business_name, s.logo_path, s.kecamatan, s.kelurahan,
            COALESCE(r.average_rating, 0) AS average_rating, COALESCE(r.review_count, 0) AS review_count
        FROM mp_products p JOIN mp_shops s ON s.id = p.shop_id
        LEFT JOIN (SELECT product_id, AVG(rating) AS average_rating, COUNT(*) AS review_count FROM mp_product_reviews GROUP BY product_id) r ON r.product_id = p.id
        WHERE p.status = 'approved' AND s.status = 'approved' AND p.id <> ?";
    $sameShopStmt = mysqli_prepare($koneksi, $recommendationQuery . ' AND p.shop_id = ? ORDER BY p.created_at DESC LIMIT 8');
    mysqli_stmt_bind_param($sameShopStmt, 'ii', $productId, $product['shop_id']);
    mysqli_stmt_execute($sameShopStmt);
    $sameShopResult = mysqli_stmt_get_result($sameShopStmt);
    while ($recommendedProduct = mysqli_fetch_assoc($sameShopResult)) {
        $sameShopProducts[] = $recommendedProduct;
    }

    $recommendedStmt = mysqli_prepare($koneksi, $recommendationQuery . ' AND p.stock > 0 ORDER BY p.created_at DESC LIMIT 60');
    mysqli_stmt_bind_param($recommendedStmt, 'i', $productId);
    mysqli_stmt_execute($recommendedStmt);
    $recommendedResult = mysqli_stmt_get_result($recommendedStmt);
    while ($recommendedProduct = mysqli_fetch_assoc($recommendedResult)) {
        $recommendedProducts[] = $recommendedProduct;
    }
}

mp_render_header($product ? $product['name'] . ' · Detail Produk' : 'Produk tidak ditemukan');
?>
<style>
    .product-detail-card, .product-reviews-card { border: 1px solid #e2e9e3; border-radius: 1.2rem; background: #fff; box-shadow: 0 12px 30px rgba(25,65,47,.08); }
    .product-gallery { display: flex; flex-direction: column; gap: .75rem; padding: .75rem; background: linear-gradient(145deg,#f3f8f4,#fff); }
    .product-detail-image { width: 100%; height: clamp(280px, 38vw, 470px); border-radius: .8rem; object-fit: contain; background: #fff; }
    .product-gallery-thumbnails { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: .65rem; }
    .product-gallery-thumbnail { overflow: hidden; padding: 3px; border: 2px solid transparent; border-radius: .65rem; background: #fff; transition: border-color .15s ease, box-shadow .15s ease; }
    .product-gallery-thumbnail img { display: block; width: 100%; height: 76px; border-radius: .4rem; object-fit: cover; }
    .product-gallery-thumbnail:hover, .product-gallery-thumbnail.is-active, .product-gallery-thumbnail:focus-visible { border-color: #167354; box-shadow: 0 0 0 2px rgba(22,115,84,.14); }
    .product-detail-copy { color: #55695e; line-height: 1.75; white-space: pre-line; }
    .product-shop-info { display: flex; flex-direction: column; align-items: flex-start; gap: .65rem; margin-bottom: 1.5rem; }
    .product-shop-main { display: flex; width: 100%; flex-wrap: wrap; align-items: center; gap: 1rem; }
    .product-shop-logo-frame { display: grid; width: min(100%, 220px); height: 120px; padding: .5rem; place-items: center; overflow: hidden; border: 1px solid #e0e9e2; border-radius: .8rem; background: #fff; }
    .product-shop-logo { display: block; width: 100%; height: 100%; object-fit: contain; }
    .product-social-links { display: grid; gap: .55rem; }
    .product-social-title { margin-bottom: .1rem; color: #28483b; font-size: .92rem; font-weight: 800; }
    .product-social-link { display: inline-flex; align-items: center; gap: .45rem; width: fit-content; max-width: 100%; color: #1765a0; font-weight: 700; text-decoration: none; overflow-wrap: anywhere; }
    .product-social-link:hover, .product-social-link:focus-visible { color: #104c79; text-decoration: underline; }
    .product-social-link.instagram { color: #a83275; }
    .product-social-link.instagram:hover, .product-social-link.instagram:focus-visible { color: #7c2456; }
    .product-shop-name { font-weight: 700; overflow-wrap: anywhere; }
    .product-shop-location { color: #526d60; font-size: .9rem; line-height: 1.5; overflow-wrap: anywhere; }
    .product-detail-price { color: #126248; font-size: clamp(1.5rem,3vw,2rem); font-weight: 800; }
    .product-rating-summary { display: flex; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .product-rating-score { color: #253b30; font-size: 1.6rem; font-weight: 800; }
    .product-rating-stars { color: #f2a900; letter-spacing: .08em; }
    .review-entry { padding: 1rem 0; border-top: 1px solid #edf1ed; }
    .review-entry:first-of-type { border-top: 0; }
    .review-verified { color: #167354; font-size: .78rem; font-weight: 700; }
    .review-reviewer { font-weight: 700; }
    .review-text { margin: .65rem 0 0; color: #4b5e52; white-space: pre-line; overflow-wrap: anywhere; }
    .product-recommendations { margin-bottom: 1.5rem; }
    .recommendation-product-card { --recommend-accent: #167354; --recommend-dark: #10553f; --recommend-shadow: #0b392a; --recommend-soft: #eaf5ef; --recommend-edge: #d5e7db; position: relative; display: flex; height: 100%; flex-direction: column; overflow: hidden; border: 1px solid var(--recommend-edge); border-radius: 1rem; background: linear-gradient(150deg,#fff 0%,#fff 72%,var(--recommend-soft) 100%); box-shadow: 0 5px 0 var(--recommend-dark),0 14px 28px rgba(17,53,43,.11); transition: transform .2s ease,box-shadow .2s ease; }
    .recommendation-product-card::before { position: absolute; z-index: 0; top: 150px; right: -42px; width: 122px; height: 105px; border: 1px solid rgba(255,255,255,.82); border-radius: 28px; background: linear-gradient(145deg,rgba(255,255,255,.94),var(--recommend-soft)); box-shadow: 8px 10px 0 rgba(17,53,43,.045),inset -6px -7px 12px rgba(17,53,43,.035); content: ""; opacity: .64; transform: rotate(-25deg) skewY(8deg); pointer-events: none; }
    .recommendation-product-card:hover { transform: translateY(-5px); box-shadow: 0 8px 0 var(--recommend-dark),0 21px 36px rgba(17,53,43,.17); }
    .recommendation-product-card > img, .recommendation-product-card > .recommendation-product-placeholder, .recommendation-product-card > .recommendation-product-body { position: relative; z-index: 1; }
    .recommendation-product-image { width: 100%; height: 180px; object-fit: cover; background: linear-gradient(145deg,#e5f2e9,#f8f0dc); }
    .recommendation-product-placeholder { display: grid; height: 180px; place-items: center; color: #378365; background: linear-gradient(145deg,#e5f2e9,#f8f0dc); }
    .recommendation-product-body { display: flex; flex: 1; flex-direction: column; padding: 1rem; }
    .recommendation-category { display: inline-block; align-self: flex-start; margin-bottom: .55rem; padding: .35rem .6rem; border: 1px solid var(--recommend-edge); border-radius: 99px; background: linear-gradient(145deg,#fff,var(--recommend-soft)); color: var(--recommend-dark); font-size: .72rem; font-weight: 700; }
    .recommendation-product-title { min-height: 2.7rem; margin: 0 0 .45rem; font-size: 1rem; font-weight: 800; line-height: 1.35; }
    .recommendation-shop { display: flex; align-items: center; gap: .55rem; min-height: 42px; margin: .55rem 0; color: #28483b; font-size: .9rem; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
    .recommendation-shop-logo { display: block; flex: 0 0 42px; width: 42px; height: 42px; padding: 3px; border: 1px solid #d8e5dc; border-radius: .55rem; background: #fff; object-fit: contain; }
    .recommendation-rating { display: flex; align-items: center; gap: .35rem; min-height: 1.25rem; color: #53685c; font-size: .8rem; }
    .recommendation-rating-stars { color: #f2a900; }
    .recommendation-price { margin-top: auto; padding-top: .5rem; color: var(--recommend-dark); font-size: 1.1rem; font-weight: 800; }
    .recommendation-product-card .btn-market { border-color: var(--recommend-dark); background: linear-gradient(145deg,var(--recommend-accent),var(--recommend-dark)); box-shadow: 0 3px 0 var(--recommend-shadow),0 5px 10px rgba(17,53,43,.12); }
    .recommendation-product-food { --recommend-accent: #d87516; --recommend-dark: #99500f; --recommend-shadow: #66320a; --recommend-soft: #fff1dd; --recommend-edge: #f0d1a8; }
    .recommendation-product-drink { --recommend-accent: #1382a8; --recommend-dark: #105a79; --recommend-shadow: #0a3a50; --recommend-soft: #e2f4fb; --recommend-edge: #bfdfea; }
    .recommendation-product-weaving { --recommend-accent: #805bb1; --recommend-dark: #56377e; --recommend-shadow: #382354; --recommend-soft: #f1eafb; --recommend-edge: #ddd0ee; }
    .recommendation-product-craft { --recommend-accent: #bd5477; --recommend-dark: #833652; --recommend-shadow: #572337; --recommend-soft: #fceaf0; --recommend-edge: #efd0dc; }
    .recommendation-product-default { --recommend-accent: #2c6e9b; --recommend-dark: #214e70; --recommend-shadow: #15344d; --recommend-soft: #e7f1fa; --recommend-edge: #ccdfed; }
    @media (max-width: 575.98px) { .recommendation-product-image, .recommendation-product-placeholder { height: 150px; } .recommendation-product-body { padding: .8rem; } }
    @media (max-width: 767.98px) { .product-detail-image { height: clamp(240px, 72vw, 360px); } .product-gallery-thumbnail img { height: 62px; } }
</style>
<?php if (!$product): ?>
    <section class="card p-4 p-md-5 text-center"><i class="bi bi-bag-x display-5 text-market"></i><h1 class="h4 fw-bold mt-3">Produk tidak ditemukan</h1><p class="small-muted">Produk ini mungkin sudah tidak tersedia atau belum disetujui untuk ditampilkan.</p><a class="btn btn-market mx-auto" href="marketplace.php">Kembali ke marketplace</a></section>
<?php else: ?>
    <nav aria-label="Breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="marketplace.php">Marketplace</a></li><li class="breadcrumb-item active" aria-current="page"><?= mp_escape($product['name']) ?></li></ol></nav>
    <section class="product-detail-card mb-4">
        <div class="row g-0">
            <div class="col-lg-6">
                <?php if ($productImages): ?>
                    <div class="product-gallery" aria-label="Foto sampul dan detail produk">
                        <img id="product-cover-image" src="<?= mp_escape($productImages[0]) ?>" class="product-detail-image" alt="<?= mp_escape($product['name']) ?> · Foto sampul">
                        <?php if (count($productImages) > 1): ?>
                            <div class="product-gallery-thumbnails" aria-label="Pilih foto detail produk">
                                <?php foreach (array_slice($productImages, 1, 4) as $imageIndex => $productImage): $detailNumber = $imageIndex + 1; ?>
                                    <button type="button" class="product-gallery-thumbnail" data-gallery-image="<?= mp_escape($productImage) ?>" data-gallery-alt="<?= mp_escape($product['name'] . ' · Foto detail ' . $detailNumber) ?>" aria-label="Tampilkan foto detail <?= $detailNumber ?>" aria-pressed="false">
                                        <img src="<?= mp_escape($productImage) ?>" alt="">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="product-detail-image d-flex align-items-center justify-content-center text-market"><i class="bi bi-bag-heart display-1"></i></div>
                <?php endif; ?>
            </div>
            <div class="col-lg-6"><div class="p-4 p-md-5">
                <span class="badge rounded-pill text-bg-light border mb-3"><?= mp_escape($product['category']) ?></span>
                <h1 class="h2 fw-bold"><?= mp_escape($product['name']) ?></h1>
                <div class="product-rating-summary mb-3">
                    <span class="product-rating-stars" aria-label="<?= number_format($averageRating, 1, ',', '.') ?> dari 5"><?= str_repeat('&#9733;', (int)round($averageRating)) . str_repeat('&#9734;', 5 - (int)round($averageRating)) ?></span>
                    <?php if ($reviewCount > 0): ?>
                        <strong><?= number_format($averageRating, 1, ',', '.') ?>/5</strong><span class="small-muted">· <?= $reviewCount ?> ulasan pembeli</span>
                    <?php else: ?>
                        <span class="small-muted">Belum ada ulasan</span>
                    <?php endif; ?>
                </div>
                <div class="product-detail-price mb-3"><?= mp_format_rupiah($product['price']) ?></div>
                <p class="product-detail-copy mb-4"><?= mp_escape($product['description']) ?></p>
                <div class="product-shop-info">
                    <div class="product-shop-main">
                        <?php if ($product['logo_path']): ?><div class="product-shop-logo-frame"><img class="product-shop-logo" src="<?= mp_escape($product['logo_path']) ?>" alt="Logo <?= mp_escape($product['business_name']) ?>"></div><?php else: ?><i class="bi bi-shop text-market fs-3"></i><?php endif; ?>
                        <div class="product-social-links">
                            <div class="product-social-title">Ikuti Media Sosial Kami :</div>
                            <a class="product-social-link" href="<?= mp_escape($product['facebook_url'] ?: 'https://facebook.com') ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-facebook" aria-hidden="true"></i>Facebook</a>
                            <a class="product-social-link instagram" href="<?= mp_escape($product['instagram_url'] ?: 'https://instagram.com') ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-instagram" aria-hidden="true"></i>Instagram</a>
                        </div>
                    </div>
                    <div class="product-shop-name"><?= mp_escape($product['business_name']) ?></div>
                    <div class="product-shop-location"><i class="bi bi-geo-alt me-1"></i><?= mp_escape(trim(($product['kelurahan'] ? $product['kelurahan'] . ', ' : '') . $product['kecamatan'], ', ')) ?></div>
                </div>
                <p class="small-muted"><i class="bi bi-box-seam me-1"></i><?= (int)$product['stock'] > 0 ? 'Stok tersedia: ' . (int)$product['stock'] : 'Stok sedang habis' ?></p>
                <?php if ((int)$product['stock'] > 0): ?>
                    <form method="post" action="keranjang.php" class="d-flex flex-wrap gap-2 mb-2">
                        <?php mp_render_csrf(); ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                        <label class="visually-hidden" for="product-quantity">Jumlah</label>
                        <input class="form-control" id="product-quantity" type="number" name="quantity" value="1" min="1" max="<?= (int)$product['stock'] ?>" aria-label="Jumlah produk" style="max-width:100px">
                        <button class="btn btn-market fw-semibold flex-grow-1" type="submit"><i class="bi bi-bag-plus me-1"></i>Tambah ke Keranjang</button>
                    </form>
                <?php else: ?>
                    <button class="btn btn-secondary w-100 mb-2" type="button" disabled>Stok habis</button>
                <?php endif; ?>
                <a class="btn btn-outline-success w-100" href="komunikasi.php?shop_id=<?= (int)$product['shop_id'] ?>"><i class="bi bi-chat-dots me-1"></i>Chat dengan toko</a>
            </div></div>
        </div>
    </section>

    <section class="product-reviews-card p-4 p-md-5 mb-4" id="ulasan">
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div><div class="section-eyebrow mb-1">Pengalaman pembeli</div><h2 class="h4 fw-bold mb-0">Ulasan produk</h2></div>
            <?php if ($reviewCount > 0): ?><div class="product-rating-summary"><span class="product-rating-score"><?= number_format($averageRating, 1, ',', '.') ?></span><div><div class="product-rating-stars"><?= str_repeat('&#9733;', (int)round($averageRating)) . str_repeat('&#9734;', 5 - (int)round($averageRating)) ?></div><div class="small-muted small">dari <?= $reviewCount ?> ulasan terverifikasi</div></div></div><?php endif; ?>
        </div>
        <p class="small-muted">Ulasan hanya dapat ditulis oleh pembeli yang pesanan produknya sudah ditandai selesai.</p>
        <?php if ($reviews): ?>
            <?php foreach ($reviews as $review): ?>
                <article class="review-entry">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div><span class="review-reviewer">Pembeli terverifikasi</span><div class="review-verified"><i class="bi bi-patch-check-fill me-1"></i>Produk dibeli di FLOM Marketplace</div></div>
                        <time class="small-muted small" datetime="<?= mp_escape(date('c', strtotime($review['created_at']))) ?>"><?= mp_escape(date('d/m/Y', strtotime($review['created_at']))) ?></time>
                    </div>
                    <div class="product-rating-stars mt-2" aria-label="Rating <?= (int)$review['rating'] ?> dari 5"><?= str_repeat('&#9733;', (int)$review['rating']) ?><?= str_repeat('&#9734;', 5 - (int)$review['rating']) ?></div>
                    <p class="review-text"><?= mp_escape($review['review_text']) ?></p>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="text-center py-4"><i class="bi bi-chat-square-heart fs-2 text-market"></i><p class="fw-semibold mt-2 mb-1">Jadilah pembeli pertama yang memberi ulasan</p><p class="small-muted mb-0">Setelah pesanan diterima dan ditandai selesai, ulasan dapat dikirim melalui halaman lacak pesanan.</p></div>
        <?php endif; ?>
        <a class="btn btn-outline-success btn-sm mt-3" href="lacak_pesanan.php">Lacak pesanan untuk menulis ulasan</a>
    </section>

    <?php
    $renderRecommendationCards = static function ($products) use ($productCategoryTones) {
        foreach ($products as $recommendedProduct):
            $tone = $productCategoryTones[$recommendedProduct['category']] ?? 'default';
            ?>
            <div class="col-6 col-lg-4 col-xl-3">
                <article class="recommendation-product-card recommendation-product-<?= mp_escape($tone) ?>">
                    <?php if ($recommendedProduct['image_path']): ?>
                        <img class="recommendation-product-image" src="<?= mp_escape($recommendedProduct['image_path']) ?>" alt="<?= mp_escape($recommendedProduct['name']) ?>" loading="lazy">
                    <?php else: ?>
                        <div class="recommendation-product-placeholder"><i class="bi bi-bag-heart fs-1"></i></div>
                    <?php endif; ?>
                    <div class="recommendation-product-body">
                        <span class="recommendation-category"><?= mp_escape($recommendedProduct['category']) ?></span>
                        <h3 class="recommendation-product-title"><?= mp_escape($recommendedProduct['name']) ?></h3>
                        <div class="recommendation-shop">
                            <?php if ($recommendedProduct['logo_path']): ?>
                                <img class="recommendation-shop-logo" src="<?= mp_escape($recommendedProduct['logo_path']) ?>" alt="Logo <?= mp_escape($recommendedProduct['business_name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="bi bi-shop fs-5" aria-hidden="true"></i>
                            <?php endif; ?>
                            <span><?= mp_escape($recommendedProduct['business_name']) ?></span>
                        </div>
                        <div class="recommendation-rating">
                            <span class="recommendation-rating-stars" aria-hidden="true">&#9733;</span>
                            <?php if ((int)$recommendedProduct['review_count'] > 0): ?>
                                <strong><?= number_format((float)$recommendedProduct['average_rating'], 1, ',', '.') ?></strong>
                                <span>(<?= (int)$recommendedProduct['review_count'] ?> ulasan)</span>
                            <?php else: ?>
                                <span>Belum ada ulasan</span>
                            <?php endif; ?>
                        </div>
                        <?php if ((int)$recommendedProduct['stock'] > 0): ?>
                            <div class="small-muted small mt-1">Stok <?= (int)$recommendedProduct['stock'] ?></div>
                        <?php else: ?>
                            <div class="text-danger small fw-semibold mt-1">Stok habis</div>
                        <?php endif; ?>
                        <div class="recommendation-price"><?= mp_format_rupiah($recommendedProduct['price']) ?></div>
                        <a class="btn btn-market btn-sm fw-semibold mt-2" href="produk.php?id=<?= (int)$recommendedProduct['id'] ?>">Lihat produk</a>
                    </div>
                </article>
            </div>
            <?php
        endforeach;
    };
    ?>
    <section class="product-recommendations" aria-labelledby="same-shop-title">
        <div class="mb-3"><div class="section-eyebrow mb-1">Jelajahi toko</div><h2 class="h4 fw-bold mb-0" id="same-shop-title">Dari Toko yang Sama</h2><p class="small-muted mb-0">Produk lainnya dari <?= mp_escape($product['business_name']) ?></p></div>
        <?php if ($sameShopProducts): ?>
            <div class="row g-4">
                <?php $renderRecommendationCards($sameShopProducts); ?>
            </div>
        <?php else: ?>
            <div class="card p-4 small-muted">Belum ada produk lain dari toko ini yang tersedia.</div>
        <?php endif; ?>
    </section>
    <?php if ($recommendedProducts): ?>
        <section class="product-recommendations" aria-labelledby="more-products-title">
            <div class="mb-3"><div class="section-eyebrow mb-1">Pilihan UMKM Flores Timur</div><h2 class="h4 fw-bold mb-0" id="more-products-title">Kamu mungkin suka juga</h2><p class="small-muted mb-0">Pilihan produk dari berbagai toko lokal</p></div>
            <div class="row g-4">
                <?php $renderRecommendationCards($recommendedProducts); ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php if ($product && count($productImages) > 1): ?>
<script>
    (function () {
        var cover = document.getElementById('product-cover-image');
        var thumbnails = document.querySelectorAll('[data-gallery-image]');
        thumbnails.forEach(function (thumbnail) {
            thumbnail.addEventListener('click', function () {
                cover.src = thumbnail.dataset.galleryImage;
                cover.alt = thumbnail.dataset.galleryAlt;
                thumbnails.forEach(function (item) {
                    item.classList.remove('is-active');
                    item.setAttribute('aria-pressed', 'false');
                });
                thumbnail.classList.add('is-active');
                thumbnail.setAttribute('aria-pressed', 'true');
            });
        });
    })();
</script>
<?php endif; ?>
<?php mp_render_footer(); ?>
