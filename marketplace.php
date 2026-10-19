<?php
require_once __DIR__ . '/marketplace_lib.php';

$search = trim($_GET['q'] ?? '');
$category = trim($_GET['kategori'] ?? '');
$categoriesResult = mysqli_query($koneksi, "SELECT p.category, COUNT(*) AS product_count FROM mp_products p JOIN mp_shops s ON s.id = p.shop_id WHERE p.status = 'approved' AND s.status = 'approved' AND p.stock > 0 GROUP BY p.category ORDER BY p.category");
$categories = [];
$availableProductCounts = [];
while ($categoryRow = mysqli_fetch_assoc($categoriesResult)) {
    $categories[] = $categoryRow['category'];
    $availableProductCounts[$categoryRow['category']] = (int)$categoryRow['product_count'];
}

$categoryCards = [
    ['label' => 'Makanan', 'icon' => 'bi-egg-fried', 'tone' => 'food', 'aliases' => ['Makanan', 'Kuliner']],
    ['label' => 'Minuman', 'icon' => 'bi-cup-straw', 'tone' => 'drink', 'aliases' => ['Minuman']],
    ['label' => 'Tenun', 'icon' => 'bi-grid-3x3-gap', 'tone' => 'weaving', 'aliases' => ['Tenun', 'Fashion / Tenun']],
    ['label' => 'Kerajinan Tangan', 'icon' => 'bi-palette', 'tone' => 'craft', 'aliases' => ['Kerajinan Tangan', 'Kriya / Kerajinan']],
];
$productCategoryTones = [
    'Makanan' => 'food',
    'Kuliner' => 'food',
    'Minuman' => 'drink',
    'Tenun' => 'weaving',
    'Fashion / Tenun' => 'weaving',
    'Kerajinan Tangan' => 'craft',
    'Kriya / Kerajinan' => 'craft',
];
$categoryCounts = [];
foreach ($categoryCards as $card) {
    $categoryCounts[$card['label']] = 0;
    foreach ($card['aliases'] as $alias) {
        $categoryCounts[$card['label']] += $availableProductCounts[$alias] ?? 0;
    }
}
$otherCategories = [];
foreach ($categories as $availableCategory) {
    $cardLabel = null;
    foreach ($categoryCards as $card) {
        if (in_array($availableCategory, $card['aliases'], true)) {
            $cardLabel = $card['label'];
            break;
        }
    }
    if ($cardLabel === null) {
        $otherCategories[] = $availableCategory;
    }
}

$sql = "SELECT p.id, p.shop_id, p.name, p.description, p.category, p.price, p.stock, p.image_path, s.business_name, s.logo_path, s.kecamatan, s.kelurahan,
               COALESCE(r.average_rating, 0) AS average_rating, COALESCE(r.review_count, 0) AS review_count
        FROM mp_products p JOIN mp_shops s ON s.id = p.shop_id
        LEFT JOIN (SELECT product_id, AVG(rating) AS average_rating, COUNT(*) AS review_count FROM mp_product_reviews GROUP BY product_id) r ON r.product_id = p.id
        WHERE p.status = 'approved' AND s.status = 'approved' AND p.stock > 0";
$types = '';
$params = [];
if ($search !== '') {
    $sql .= ' AND (p.name LIKE ? OR p.description LIKE ? OR s.business_name LIKE ?)';
    $term = '%' . $search . '%';
    $types .= 'sss';
    array_push($params, $term, $term, $term);
}
if ($category !== '' && in_array($category, array_merge(['Makanan', 'Minuman', 'Tenun', 'Kerajinan Tangan'], $otherCategories), true)) {
    $aliases = [$category];
    foreach ($categoryCards as $card) {
        if ($card['label'] === $category) {
            $aliases = $card['aliases'];
            break;
        }
    }
    $categoryPlaceholders = implode(', ', array_fill(0, count($aliases), '?'));
    $sql .= ' AND p.category IN (' . $categoryPlaceholders . ')';
    $types .= str_repeat('s', count($aliases));
    array_push($params, ...$aliases);
}
$sql .= ' ORDER BY p.created_at DESC LIMIT 60';
$stmt = mysqli_prepare($koneksi, $sql);
if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$productsResult = mysqli_stmt_get_result($stmt);
$cartCount = array_sum($_SESSION['mp_cart'] ?? []);
$slidesResult = mysqli_query($koneksi, 'SELECT id, title, caption, image_path FROM mp_slides WHERE is_active = 1 ORDER BY sort_order ASC, id DESC LIMIT 8');
$slides = [];
while ($slide = mysqli_fetch_assoc($slidesResult)) {
    $slides[] = $slide;
}

mp_render_header('Marketplace UMKM dan EKRAF', false, true);
?>
<style>
    .market-shell { max-width: 1320px; }
    .market-topline { color: #d9e9df; font-size: .82rem; letter-spacing: .04em; }
    .market-hero { position: relative; overflow: hidden; min-height: 290px; border-radius: 1.5rem; background: radial-gradient(ellipse at 92% 12%, rgba(244, 207, 131, .28), transparent 28%), radial-gradient(ellipse at 88% 105%, rgba(79, 153, 112, .42), transparent 38%), linear-gradient(115deg, #082a23 0%, #105240 58%, #247055 100%); box-shadow: 0 24px 60px rgba(12, 53, 42, .19); }
    .market-hero-slide { position: relative; min-height: 350px; overflow: hidden; border-radius: 1.5rem; background: radial-gradient(ellipse at 92% 12%, rgba(244, 207, 131, .28), transparent 28%), radial-gradient(ellipse at 88% 105%, rgba(79, 153, 112, .42), transparent 38%), linear-gradient(115deg, #082a23 0%, #105240 58%, #247055 100%); box-shadow: 0 24px 60px rgba(12, 53, 42, .19); }
    .market-hero-slide::before { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(5, 34, 28, .88) 0%, rgba(7, 46, 37, .72) 48%, rgba(8, 44, 34, .22) 100%); content: ""; }
    .market-hero-slide.has-image::before { background: linear-gradient(90deg, rgba(5, 34, 28, .88) 0%, rgba(7, 46, 37, .68) 48%, rgba(8, 44, 34, .25) 100%); }
    .market-hero-image { position: absolute; inset: 0; display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; }
    .market-hero-slide.has-image::after { position: absolute; z-index: 2; inset: 10px; border: 2px solid rgba(239, 255, 245, .7); border-radius: 1.1rem; box-shadow: 0 0 18px rgba(244, 255, 248, .38), 0 0 38px rgba(67, 154, 99, .3), inset 0 0 24px rgba(244, 255, 248, .24), inset 0 0 64px rgba(67, 154, 99, .3); content: ""; pointer-events: none; }
    .market-hero-content { position: relative; z-index: 3; max-width: 720px; min-height: 350px; padding: 2.5rem 3rem 3.5rem; display: flex; flex-direction: column; justify-content: center; }
    .hero-text-frame { border: 1px solid rgba(242,198,109,.82); background: rgba(5,34,28,.56); box-shadow: inset 0 0 0 1px rgba(255,255,255,.06); }
    .market-topline.hero-text-frame { width: fit-content; max-width: 100%; border-radius: .55rem; padding: .42rem .65rem; color: #fff0bf; }
    .market-hero-carousel .carousel-indicators { z-index: 2; margin-bottom: 1rem; }
    .market-hero-carousel .carousel-indicators [data-bs-target] { width: 9px; height: 9px; margin: 0 5px; border: 0; border-radius: 50%; opacity: .65; }
    .market-hero-carousel .carousel-indicators .active { width: 26px; border-radius: 99px; background: #f2c66d; opacity: 1; }
    .market-hero-carousel .carousel-control-prev, .market-hero-carousel .carousel-control-next { z-index: 2; width: 7%; }
    .market-hero-carousel .carousel-control-prev-icon, .market-hero-carousel .carousel-control-next-icon { width: 2.4rem; height: 2.4rem; border-radius: 50%; background-color: rgba(7, 34, 27, .58); background-size: 48%; }
    .hero-kicker { display: inline-flex; align-items: center; gap: .55rem; padding: .48rem .8rem; border: 1px solid rgba(255, 231, 174, .38); border-radius: 99px; color: #ffe3a0; background: rgba(255,255,255,.08); font-size: .78rem; font-weight: 700; letter-spacing: .04em; }
    .hero-kicker i { font-size: .9rem; }
    .market-hero .display-6 { max-width: 650px; font-size: clamp(2rem, 4.3vw, 3.45rem); line-height: 1.12; letter-spacing: -.04em; }
    .market-hero-title.hero-text-frame { width: fit-content; padding: .55rem .8rem; border-radius: .8rem; color: #fff; }
    .market-hero-copy { max-width: 550px; color: rgba(255,255,255,.8); font-size: 1rem; line-height: 1.75; }
    .market-hero-copy.hero-text-frame { width: fit-content; padding: .55rem .8rem; border-radius: .7rem; color: rgba(255,255,255,.92); }
    .hero-actions .btn { border-radius: .7rem; padding: .78rem 1.05rem; }
    .hero-actions .btn.hero-text-frame { border: 1px solid #f2c66d; }
    .hero-seller-button { display: inline-flex; align-items: center; gap: .4rem; min-height: 46px; border: 1px solid rgba(255,255,255,.72); border-radius: .7rem; padding: .65rem 1rem; background: rgba(255,255,255,.08); color: #fff; font-size: .9rem; text-decoration: none; transition: background-color .18s ease, border-color .18s ease, transform .18s ease; }
    .hero-seller-button.hero-text-frame { border-color: #f2c66d; }
    .hero-actions .hero-cta-orange { min-height: 54px; border: 1px solid #f58220; border-radius: .75rem; padding: .85rem 1.2rem; background: #f58220; color: #fff; font-size: 1.08rem; font-weight: 800; line-height: 1.35; text-decoration: none; }
    .hero-actions .hero-cta-orange strong { font-weight: 800; }
    .hero-seller-button:hover, .hero-seller-button:focus-visible { transform: translateY(-1px); border-color: #fff; background: rgba(255,255,255,.16); color: #fff; }
    .hero-seller-button:focus-visible { outline: 2px solid #f2c66d; outline-offset: 2px; }
    .market-stat { display: inline-flex; align-items: center; gap: .55rem; padding: .45rem .7rem; margin-top: .85rem; border-radius: .65rem; color: rgba(255,255,255,.88); font-size: .85rem; }
    .market-stat i { color: #f2c66d; }
    .market-hero-slide .hero-seller-button { transition: none; }
    .market-hero-slide .hero-text-frame:hover { transform: none; border-color: rgba(242,198,109,.82); background: rgba(5,34,28,.56); box-shadow: inset 0 0 0 1px rgba(255,255,255,.06); }
    .market-hero-slide .hero-seller-button:hover { color: #fff; }
    .market-hero-slide .hero-actions .btn.hero-text-frame:hover { border-color: #f2c66d; background: rgba(5,34,28,.56); color: #212529; }
    .market-hero-slide .hero-actions .hero-cta-orange:hover, .market-hero-slide .hero-actions .hero-cta-orange:focus-visible { border-color: #ff9a3d; background: #ff8b2b; color: #fff; box-shadow: 0 0 14px rgba(255, 139, 43, .38); }
    .section-eyebrow { color: #52816d; font-size: .72rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
    .category-card { --category-ink: #a84f21; --category-surface: #fff8ef; --category-edge: #f0d3b5; --category-depth: #e5b989; --category-icon-top: #fff0df; --category-icon-bottom: #ffd7a9; position: relative; isolation: isolate; display: flex; min-height: 176px; height: 100%; flex-direction: column; justify-content: space-between; overflow: hidden; padding: 1.25rem; border: 1px solid var(--category-edge); border-radius: 1rem; background: linear-gradient(145deg, var(--category-surface) 0%, #fff 74%); box-shadow: 0 5px 0 var(--category-depth), 0 13px 25px rgba(17,53,43,.09); color: #18352d; text-decoration: none; transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
    .category-card::before { position: absolute; z-index: 0; top: -35px; right: -24px; width: 125px; height: 112px; border: 1px solid rgba(255,255,255,.75); border-radius: 28px; background: linear-gradient(145deg, rgba(255,255,255,.94), var(--category-icon-top) 52%, var(--category-icon-bottom)); box-shadow: 8px 10px 0 rgba(0,0,0,.045), inset -7px -8px 14px rgba(0,0,0,.035), inset 5px 5px 12px rgba(255,255,255,.85); content: ""; opacity: .8; transform: rotate(-25deg) skewY(8deg); transition: transform .2s ease, opacity .2s ease; }
    .category-card > * { position: relative; z-index: 1; }
    .category-card:hover { transform: translateY(-6px); border-color: var(--category-depth); box-shadow: 0 8px 0 var(--category-depth), 0 20px 34px rgba(17,53,43,.15); color: #18352d; }
    .category-card:hover::before { opacity: 1; transform: translate(-3px, -2px) rotate(-20deg) skewY(8deg); }
    .category-card.is-active { border-color: var(--category-ink); box-shadow: 0 0 0 2px var(--category-edge), 0 5px 0 var(--category-depth), 0 14px 28px rgba(17,53,43,.12); }
    .category-icon { display: grid; width: 58px; height: 58px; place-items: center; border: 1px solid rgba(255,255,255,.85); border-radius: 17px; background: linear-gradient(145deg, var(--category-icon-top), var(--category-icon-bottom)); box-shadow: 0 5px 0 var(--category-depth), 0 9px 16px rgba(17,53,43,.1), inset 2px 2px 5px rgba(255,255,255,.9), inset -3px -4px 7px rgba(0,0,0,.06); color: var(--category-ink); font-size: 1.6rem; }
    .category-icon i { filter: drop-shadow(0 2px 1px rgba(0,0,0,.14)); }
    .category-card-food { --category-ink: #a84f21; --category-surface: #fff8ef; --category-edge: #f0d3b5; --category-depth: #e5b989; --category-icon-top: #fff0df; --category-icon-bottom: #ffd7a9; }
    .category-card-drink { --category-ink: #1670a5; --category-surface: #f0faff; --category-edge: #c5e2ef; --category-depth: #92c4db; --category-icon-top: #e2f3fb; --category-icon-bottom: #b7e4f5; }
    .category-card-weaving { --category-ink: #7650a1; --category-surface: #f8f4ff; --category-edge: #ded1ef; --category-depth: #b9a1d4; --category-icon-top: #f0e9fb; --category-icon-bottom: #d6c2ef; }
    .category-card-craft { --category-ink: #a34b68; --category-surface: #fff4f7; --category-edge: #efd0dc; --category-depth: #d9a3b7; --category-icon-top: #fbe8ee; --category-icon-bottom: #f2c5d5; }
    .category-label { margin-top: 1.05rem; font-size: 1.03rem; font-weight: 800; }
    .category-meta { display: flex; align-items: center; justify-content: space-between; color: #72847c; font-size: .78rem; }
    .category-arrow { color: var(--category-ink); font-size: 1.05rem; }
    .category-count { position: absolute; z-index: 1; top: 1.2rem; right: 1.2rem; min-width: 28px; padding: .2rem .48rem; border: 1px solid rgba(255,255,255,.9); border-radius: 99px; color: var(--category-ink); background: linear-gradient(145deg,#fff,var(--category-icon-top)); box-shadow: 0 2px 5px rgba(17,53,43,.08), inset 1px 1px 2px rgba(255,255,255,.95); font-size: .72rem; font-weight: 700; text-align: center; }
    .market-search-panel { padding: 1rem; border: 1px solid #e4ebe5; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.05); }
    .search-input-wrap { position: relative; }
    .search-input-wrap i { position: absolute; top: 50%; left: 1rem; color: #7c9188; transform: translateY(-50%); }
    .search-input-wrap .form-control { min-height: 48px; padding-left: 2.75rem; border-color: #e2e9e4; border-radius: .7rem; }
    .market-search-panel .form-select { min-height: 48px; border-color: #e2e9e4; border-radius: .7rem; }
    .market-search-panel .btn { min-height: 48px; border-radius: .7rem; }
    .active-filter { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .7rem; border-radius: 99px; color: #126248; background: #e8f5ee; font-size: .8rem; font-weight: 700; text-decoration: none; }
    .product-card { --product-accent: #167354; --product-accent-dark: #10553f; --product-accent-shadow: #0b392a; --product-soft: #eaf5ef; --product-edge: #d5e7db; position: relative; isolation: isolate; overflow: hidden; border: 1px solid var(--product-edge); border-radius: 1rem; background: linear-gradient(150deg,#fff 0%,#fff 72%,var(--product-soft) 100%); box-shadow: 0 5px 0 var(--product-accent-dark), 0 14px 28px rgba(17,53,43,.11); transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .product-card::before { position: absolute; z-index: 0; top: 166px; right: -36px; width: 145px; height: 122px; border: 1px solid rgba(255,255,255,.82); border-radius: 32px; background: linear-gradient(145deg,rgba(255,255,255,.94),var(--product-soft)); box-shadow: 9px 12px 0 rgba(17,53,43,.045),inset -7px -8px 14px rgba(17,53,43,.035),inset 5px 5px 12px rgba(255,255,255,.86); content: ""; opacity: .64; transform: rotate(-25deg) skewY(8deg); pointer-events: none; }
    .product-card:hover { transform: translateY(-5px); border-color: var(--product-edge); box-shadow: 0 8px 0 var(--product-accent-dark), 0 21px 36px rgba(17,53,43,.17); }
    .product-card > .product-image, .product-card > .card-body { position: relative; z-index: 1; }
    .product-image { height: 205px; object-fit: cover; background: linear-gradient(145deg,#e5f2e9,#f8f0dc); box-shadow: inset 0 -4px 0 rgba(255,255,255,.62); }
    .product-placeholder { color: #378365; }
    .product-category { display: inline-block; border: 1px solid var(--product-edge); border-radius: 99px; padding: .38rem .62rem; background: linear-gradient(145deg,#fff,var(--product-soft)); box-shadow: 0 2px 0 var(--product-edge),0 4px 7px rgba(17,53,43,.06),inset 1px 1px 2px rgba(255,255,255,.95); color: var(--product-accent-dark); font-size: .72rem; font-weight: 700; }
    .product-card .form-control { border-color: var(--product-edge); border-radius: .55rem; background: linear-gradient(145deg,#fff,var(--product-soft)); box-shadow: inset 0 2px 3px rgba(17,53,43,.06),0 2px 0 var(--product-edge); }
    .product-card .btn-market { border: 1px solid var(--product-accent-dark); border-radius: .55rem; background: linear-gradient(145deg,var(--product-accent),var(--product-accent-dark)); box-shadow: 0 3px 0 var(--product-accent-shadow),0 5px 10px rgba(17,53,43,.12); }
    .product-card .btn-market:hover, .product-card .btn-market:focus-visible { border-color: var(--product-accent-dark); background: linear-gradient(145deg,var(--product-accent),var(--product-accent-dark)); box-shadow: 0 2px 0 var(--product-accent-shadow),0 3px 7px rgba(17,53,43,.14); }
    .product-card .btn-outline-success { border-color: var(--product-edge); border-radius: .55rem; background: linear-gradient(145deg,#fff,var(--product-soft)); box-shadow: 0 3px 0 var(--product-edge),0 5px 9px rgba(17,53,43,.07); color: var(--product-accent-dark); }
    .product-card .btn-outline-success:hover, .product-card .btn-outline-success:focus-visible { border-color: var(--product-edge); background: var(--product-soft); color: var(--product-accent-dark); }
    .product-price { color: var(--product-accent-dark); font-size: 1.12rem; letter-spacing: -.02em; text-shadow: 0 1px 0 rgba(255,255,255,.8); }
    .product-rating-link { display: flex; align-items: center; gap: .4rem; min-height: 42px; padding: .45rem .65rem; border: 1px solid var(--product-accent-dark); border-radius: .6rem; background: linear-gradient(145deg,var(--product-accent),var(--product-accent-dark)); box-shadow: 0 3px 0 var(--product-accent-shadow),0 5px 10px rgba(17,53,43,.12); color: #fff !important; font-size: 1rem; font-weight: 800; line-height: 1.25; text-decoration: none; transition: transform .2s ease, box-shadow .2s ease, filter .2s ease; }
    .product-rating-link:hover, .product-rating-link:focus-visible { transform: translateY(-2px); box-shadow: 0 5px 0 var(--product-accent-shadow),0 8px 14px rgba(17,53,43,.16); color: #fff !important; filter: brightness(1.08); }
    .product-rating-link .small-muted { color: rgba(255,255,255,.94) !important; font-weight: 700; }
    .product-rating-link .product-rating-stars { font-size: 1.15rem; }
    .product-rating-stars { color: #f2a900; font-size: 1rem; line-height: 1; }
    .products-heading { display: flex; flex-wrap: wrap; align-items: center; gap: .9rem 1rem; }
    .products-heading-main { display: flex; flex-wrap: wrap; align-items: center; gap: .9rem 1rem; }
    .store-line { display: flex; align-items: center; gap: .65rem; min-height: 52px; margin-bottom: .5rem !important; color: #28483b; font-size: 1rem; font-weight: 800; line-height: 1.35; overflow-wrap: anywhere; }
    .product-card-food { --product-accent: #d87516; --product-accent-dark: #99500f; --product-accent-shadow: #66320a; --product-soft: #fff1dd; --product-edge: #f0d1a8; }
    .product-card-drink { --product-accent: #1382a8; --product-accent-dark: #105a79; --product-accent-shadow: #0a3a50; --product-soft: #e2f4fb; --product-edge: #bfdfea; }
    .product-card-weaving { --product-accent: #805bb1; --product-accent-dark: #56377e; --product-accent-shadow: #382354; --product-soft: #f1eafb; --product-edge: #ddd0ee; }
    .product-card-craft { --product-accent: #bd5477; --product-accent-dark: #833652; --product-accent-shadow: #572337; --product-soft: #fceaf0; --product-edge: #efd0dc; }
    .shop-logo-mini { display: block; flex: 0 0 52px; width: 52px; height: 52px; padding: 3px; border: 1px solid #d8e5dc; border-radius: .65rem; background: #fff; object-fit: contain; }
    .market-empty { border: 1px dashed #cddbd1; border-radius: 1rem; background: rgba(255,255,255,.78); }
    @media (max-width: 575.98px) { .market-hero-slide, .market-hero-content { min-height: 360px; } .market-hero-slide { border-radius: 1rem; } .market-hero-content { max-width: 100%; padding: 2rem 1.35rem 3.5rem; } .market-hero .display-6 { font-size: 2rem; } .market-hero-copy { font-size: .9rem; } .market-hero-carousel .carousel-control-prev, .market-hero-carousel .carousel-control-next { width: 10%; } .category-card { min-height: 152px; padding: 1rem; } .category-icon { width: 48px; height: 48px; border-radius: 14px; font-size: 1.35rem; } .category-label { font-size: .93rem; } .category-count { top: .9rem; right: .85rem; } .product-image { height: 170px; } }
    .marketplace-home main.marketplace-main { width: 100%; max-width: none; margin: 0; padding: 0 !important; }
    .marketplace-home .navbar-market-overlay { position: fixed; z-index: 1030; top: 1.25rem; left: 50%; width: min(calc(100% - 3rem), 1440px); min-height: 92px; padding: .35rem 1rem; transform: translateX(-50%); border: 1px solid rgba(255,255,255,.2); border-radius: 1.1rem; background: rgba(25,43,61,.86); box-shadow: 0 16px 40px rgba(8,20,34,.24); backdrop-filter: blur(14px); }
    .marketplace-home .navbar-market-overlay > .container { width: 100%; max-width: none; min-height: 78px; }
    .marketplace-home .navbar-market-overlay .navbar-logo-government, .marketplace-home .navbar-market-overlay .navbar-brand-logos .brand-mark { width: 62px; height: 62px; }
    .marketplace-home .navbar-market-overlay .navbar-logo-marketplace { width: 250px; height: 84px; }
    .marketplace-home .navbar-market-overlay .navbar-brand-logos { gap: .5rem; }
    .marketplace-home .hero-market { min-height: 100vh; min-height: 100svh; margin: 0 !important; padding: 0 !important; overflow: hidden; border-radius: 0; background: #102c38; box-shadow: none; }
    .marketplace-home .market-hero-carousel, .marketplace-home .market-hero-carousel .carousel-inner, .marketplace-home .market-hero-carousel .carousel-item { height: 100vh; height: 100svh; min-height: 100vh; min-height: 100svh; }
    .marketplace-home .market-hero-slide { height: 100%; min-height: 100vh; min-height: 100svh; border-radius: 0; box-shadow: none; }
    .marketplace-home .market-hero-content { width: min(780px, 66vw); max-width: 780px; min-height: 100vh; min-height: 100svh; justify-content: center; padding: 10rem clamp(2rem, 8vw, 8rem) 5rem; }
    .marketplace-home .market-hero-slide::before, .marketplace-home .market-hero-slide.has-image::before { background: linear-gradient(90deg, rgba(10,24,34,.82) 0%, rgba(10,24,34,.62) 47%, rgba(10,24,34,.12) 100%), linear-gradient(0deg, rgba(10,24,34,.32), transparent 42%); }
    .marketplace-home .market-hero-carousel .carousel-indicators { margin-bottom: 1.6rem; }
    .market-content { max-width: 1320px; }
    @media (max-width: 820px) {
        .marketplace-home .navbar-market-overlay { top: .75rem; width: calc(100% - 1.5rem); min-height: 0; padding: .35rem .65rem; }
        .marketplace-home .navbar-market-overlay > .container { min-height: 0; flex-direction: row; align-items: center; justify-content: space-between; gap: .5rem; }
        .marketplace-home .navbar-market-overlay .navbar-logo-government, .marketplace-home .navbar-market-overlay .navbar-brand-logos .brand-mark { width: 48px; height: 48px; }
        .marketplace-home .navbar-market-overlay .navbar-logo-marketplace { width: clamp(132px, 28vw, 210px); height: 70px; }
        .marketplace-home .navbar-market-overlay .navbar-nav { flex-direction: row; flex-wrap: wrap; justify-content: flex-end; gap: .35rem; margin-left: 0 !important; }
        .marketplace-home .navbar-market-overlay .navbar-nav .header-nav-link { margin-left: 0; padding: .48rem .65rem; font-size: .78rem; }
        .marketplace-home .market-hero-carousel, .marketplace-home .market-hero-carousel .carousel-inner, .marketplace-home .market-hero-carousel .carousel-item { height: auto; min-height: 100vh; min-height: 100svh; }
        .marketplace-home .market-hero-slide, .marketplace-home .market-hero-content { height: auto; min-height: 100vh; min-height: 100svh; }
        .marketplace-home .market-hero-content { width: 100%; max-width: 650px; padding: 11rem 2rem 5rem; }
    }
    @media (max-width: 575.98px) {
        .marketplace-home .navbar-market-overlay > .container { align-items: flex-start; flex-direction: column; gap: .1rem; }
        .marketplace-home .navbar-market-overlay .navbar-brand-logos { gap: .35rem; }
        .marketplace-home .navbar-market-overlay .navbar-logo-government, .marketplace-home .navbar-market-overlay .navbar-brand-logos .brand-mark { width: 38px; height: 38px; }
        .marketplace-home .navbar-market-overlay .navbar-logo-marketplace { width: 190px; height: 64px; }
        .marketplace-home .navbar-market-overlay .navbar-nav { width: 100%; justify-content: flex-start; }
        .marketplace-home .navbar-market-overlay .navbar-nav .header-nav-link { padding: .4rem .55rem; font-size: .74rem; }
        .marketplace-home .market-hero-content { max-width: 100%; padding: 13rem 1.35rem 5rem; }
        .marketplace-home .market-hero .display-6 { font-size: 2rem; }
        .marketplace-home .market-hero-carousel .carousel-control-prev, .marketplace-home .market-hero-carousel .carousel-control-next { width: 9%; }
    }
</style>
<section class="hero-market p-4 p-md-5 mb-4">
    <div id="marketHeroCarousel" class="carousel slide market-hero-carousel" data-bs-ride="carousel" data-bs-interval="5000">
        <?php if (count($slides) > 1): ?>
            <div class="carousel-indicators">
                <?php foreach ($slides as $index => $slide): ?>
                    <button type="button" data-bs-target="#marketHeroCarousel" data-bs-slide-to="<?= (int)$index ?>" class="<?= $index === 0 ? 'active' : '' ?>" <?= $index === 0 ? 'aria-current="true"' : '' ?> aria-label="Slide <?= (int)$index + 1 ?>"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="carousel-inner">
            <?php if (count($slides) === 0): ?>
                <div class="carousel-item active">
                    <div class="market-hero-slide">
                        <div class="market-hero-content">
                            <div class="market-topline hero-text-frame mb-3"><i class="bi bi-geo-alt-fill me-1"></i>KABUPATEN FLORES TIMUR</div>
                            <span class="hero-kicker hero-text-frame mb-3"><i class="bi bi-stars"></i>Bangga Produk Lokal</span>
                            <h1 class="display-6 fw-bold mt-3 mb-3 market-hero-title hero-text-frame">Jelajahi cita rasa &amp; karya dari Flores Timur.</h1>
                            <p class="market-hero-copy hero-text-frame mb-4">Temukan makanan, minuman, tenun, dan kerajinan tangan istimewa. Belanja langsung dari toko UMKM lokal.</p>
                            <div class="d-flex flex-wrap align-items-center gap-3 hero-actions">
                                <a class="btn fw-bold hero-text-frame hero-cta-orange" href="#kategori-produk"><i class="bi bi-grid me-2"></i>Jelajahi Kategori</a>
                                <a class="hero-seller-button hero-text-frame hero-cta-orange" href="masuk_umkm.php">Punya usaha? <strong>Mulai berjualan</strong><i class="bi bi-arrow-right"></i></a>
                                <a class="hero-seller-button hero-text-frame hero-cta-orange" href="komunikasi.php?start=admin"><i class="bi bi-chat-left-text"></i><strong>Tanya admin</strong></a>
                            </div>
                            <div class="market-stat hero-text-frame"><i class="bi bi-patch-check-fill"></i>Produk pilihan UMKM lokal terverifikasi</div>
                        </div>
                    </div>
                </div>
            <?php else: foreach ($slides as $index => $slide): ?>
                <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                    <div class="market-hero-slide has-image">
                        <img class="market-hero-image" src="<?= mp_escape($slide['image_path']) ?>" alt="<?= mp_escape($slide['title'] ?: 'Gambar promosi marketplace UMKM Flores Timur') ?>" <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                        <div class="market-hero-content">
                            <div class="market-topline hero-text-frame mb-3"><i class="bi bi-geo-alt-fill me-1"></i>KABUPATEN FLORES TIMUR</div>
                            <span class="hero-kicker hero-text-frame mb-3"><i class="bi bi-stars"></i>Bangga Produk Lokal</span>
                            <h1 class="display-6 fw-bold mt-3 mb-3 market-hero-title hero-text-frame"><?= $slide['title'] !== '' ? mp_escape($slide['title']) : 'Jelajahi cita rasa &amp; karya dari Flores Timur.' ?></h1>
                            <p class="market-hero-copy hero-text-frame mb-4"><?= $slide['caption'] !== '' ? mp_escape($slide['caption']) : 'Temukan makanan, minuman, tenun, dan kerajinan tangan istimewa. Belanja langsung dari toko UMKM lokal.' ?></p>
                            <div class="d-flex flex-wrap align-items-center gap-3 hero-actions">
                                <a class="btn fw-bold hero-text-frame hero-cta-orange" href="#kategori-produk"><i class="bi bi-grid me-2"></i>Jelajahi Kategori</a>
                                <a class="hero-seller-button hero-text-frame hero-cta-orange" href="masuk_umkm.php">Punya usaha? <strong>Mulai berjualan</strong><i class="bi bi-arrow-right"></i></a>
                                <a class="hero-seller-button hero-text-frame hero-cta-orange" href="komunikasi.php?start=admin"><i class="bi bi-chat-left-text"></i><strong>Tanya admin</strong></a>
                            </div>
                            <div class="market-stat hero-text-frame"><i class="bi bi-patch-check-fill"></i>Produk pilihan UMKM lokal terverifikasi</div>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php if (count($slides) > 1): ?>
            <button class="carousel-control-prev" type="button" data-bs-target="#marketHeroCarousel" data-bs-slide="prev" aria-label="Slide sebelumnya"><span class="carousel-control-prev-icon" aria-hidden="true"></span></button>
            <button class="carousel-control-next" type="button" data-bs-target="#marketHeroCarousel" data-bs-slide="next" aria-label="Slide berikutnya"><span class="carousel-control-next-icon" aria-hidden="true"></span></button>
        <?php endif; ?>
    </div>
</section>

<div class="market-content container py-4">
<?php mp_render_flash(); ?>
<?php if (!empty($_SESSION['mp_last_order_code'])):
    $lastOrderCode = (string)$_SESSION['mp_last_order_code'];
    $lastStmt = mysqli_prepare($koneksi, 'SELECT o.order_code, o.customer_name, o.payment_method, o.payment_status, o.order_status, o.bank_name, o.bank_account_name, o.bank_account_number, o.total_amount, s.business_name, s.phone FROM mp_orders o JOIN mp_shops s ON s.id = o.shop_id WHERE o.order_code = ? LIMIT 1');
    mysqli_stmt_bind_param($lastStmt, 's', $lastOrderCode);
    mysqli_stmt_execute($lastStmt);
    $lastOrder = mysqli_fetch_assoc(mysqli_stmt_get_result($lastStmt));
    unset($_SESSION['mp_last_order_code']);
    if ($lastOrder): ?>
    <div class="card border-success mb-4"><div class="card-body p-4">
        <h2 class="h5 fw-bold text-success"><i class="bi bi-check-circle-fill me-2"></i>Pesanan berhasil dibuat</h2>
        <p class="mb-1">Nomor pesanan <strong><?= mp_escape($lastOrder['order_code']) ?></strong> · <?= mp_escape($lastOrder['customer_name']) ?></p>
        <p class="mb-1">Toko: <strong><?= mp_escape($lastOrder['business_name']) ?></strong> · <a href="https://wa.me/<?= mp_escape(preg_replace('/\D+/', '', $lastOrder['phone'])) ?>" target="_blank" rel="noopener">Hubungi penjual</a></p>
        <p class="mb-1">Total: <strong><?= mp_format_rupiah($lastOrder['total_amount']) ?></strong></p>
        <?php if ($lastOrder['payment_method'] === 'transfer'): ?>
            <div class="alert alert-info mt-3 mb-0">Transfer langsung ke rekening toko <strong><?= mp_escape($lastOrder['bank_name']) ?></strong>, nomor <strong><?= mp_escape($lastOrder['bank_account_number']) ?></strong> atas nama <strong><?= mp_escape($lastOrder['bank_account_name']) ?></strong>. Konfirmasikan pembayaran kepada penjual melalui WhatsApp.</div>
        <?php else: ?>
            <div class="alert alert-warning mt-3 mb-0">Pembayaran dilakukan tunai kepada penjual/kurir saat barang diterima (COD).</div>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-success mt-3" href="lacak_pesanan.php">Lacak pesanan ini</a>
    </div></div>
<?php endif; endif; ?>

<section id="kategori-produk" class="mb-5">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div><div class="section-eyebrow mb-1">Temukan produk pilihan</div><h2 class="h3 fw-bold mb-0">Belanja berdasarkan kategori</h2></div>
        <span class="small-muted small">Karya terbaik dari pelaku usaha lokal</span>
    </div>
    <div class="row g-3">
        <?php foreach ($categoryCards as $card): ?>
            <div class="col-6 col-lg-3">
                <a class="category-card category-card-<?= mp_escape($card['tone']) ?> <?= $category === $card['label'] ? 'is-active' : '' ?>" href="marketplace.php?<?= http_build_query(array_filter(['kategori' => $card['label'], 'q' => $search], static function ($value) { return $value !== ''; })) ?>">
                    <span class="category-count"><?= (int)$categoryCounts[$card['label']] ?></span>
                    <span class="category-icon"><i class="bi <?= mp_escape($card['icon']) ?>"></i></span>
                    <span class="category-label"><?= mp_escape($card['label']) ?></span>
                    <span class="category-meta"><span>Lihat produk</span><i class="bi bi-arrow-up-right category-arrow"></i></span>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="row g-3 align-items-end mb-4">
    <div class="col-12">
        <form method="get" class="market-search-panel row g-2 align-items-end">
            <div class="col-md-7"><label class="form-label small fw-semibold" for="q">Cari produk atau toko</label><div class="search-input-wrap"><i class="bi bi-search"></i><input id="q" class="form-control" name="q" value="<?= mp_escape($search) ?>" placeholder="Contoh: jagung titi, kopi, tenun ikat"></div></div>
            <div class="col-md-3"><label class="form-label small fw-semibold" for="kategori">Kategori</label><select id="kategori" class="form-select" name="kategori"><option value="">Semua kategori</option><?php foreach ($categoryCards as $card): ?><option value="<?= mp_escape($card['label']) ?>" <?= $category === $card['label'] ? 'selected' : '' ?>><?= mp_escape($card['label']) ?></option><?php endforeach; ?><?php foreach ($otherCategories as $categoryOption): ?><option value="<?= mp_escape($categoryOption) ?>" <?= $category === $categoryOption ? 'selected' : '' ?>><?= mp_escape($categoryOption) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2 d-grid"><button class="btn btn-market fw-semibold" type="submit">Cari</button></div>
        </form>
    </div>
</div>

<div class="products-heading justify-content-between mb-3">
    <div class="products-heading-main"><div><div class="section-eyebrow mb-1"><?= $category !== '' ? 'Kategori pilihan' : 'Dari UMKM Flores Timur' ?></div><h2 class="h4 fw-bold mb-0"><?= $category !== '' ? 'Produk ' . mp_escape($category) : 'Produk pilihan hari ini' ?></h2></div>
        <div class="header-cart-wrapper"><a class="header-cart-link product-cart-link" href="keranjang.php" aria-label="Keranjang belanja, <?= (int)$cartCount ?> barang"><i class="bi bi-cart3" aria-hidden="true"></i>Keranjang (<?= (int)$cartCount ?>)</a>
            <div class="header-cart-preview" role="region" aria-label="Pratinjau keranjang">
                <?php if ($cartCount === 0): ?>
                    <img class="header-cart-preview-art" src="assets/cart-empty.svg" alt=""><h3 class="header-cart-preview-title">Wah, keranjang belanjamu kosong</h3><p class="header-cart-preview-copy">Yuk, isi dengan barang-barang impianmu!</p><a class="header-cart-preview-action" href="marketplace.php#kategori-produk">Mulai Belanja</a>
                <?php else: ?>
                    <img class="header-cart-preview-art" src="assets/cart-empty.svg" alt=""><h3 class="header-cart-preview-title">Keranjang belanjamu</h3><p class="header-cart-preview-copy"><?= (int)$cartCount ?> barang siap dipesan.</p><a class="header-cart-preview-action" href="keranjang.php">Lihat Keranjang</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($category !== ''): ?><a class="active-filter" href="marketplace.php<?= $search !== '' ? '?' . http_build_query(['q' => $search]) : '' ?>"><i class="bi bi-x-circle"></i><?= mp_escape($category) ?> · Hapus filter</a><?php endif; ?>
        <span class="small-muted small">Menampilkan hingga 60 produk</span>
    </div>
</div>
<div class="row g-4">
    <?php if (mysqli_num_rows($productsResult) === 0): ?>
        <div class="col-12"><div class="market-empty p-4 p-md-5 text-center"><i class="bi bi-basket3 display-5 text-market"></i><h3 class="h5 fw-bold mt-3"><?= $category !== '' ? 'Belum ada produk di kategori ini' : 'Produk lokal segera hadir' ?></h3><p class="small-muted mb-0"><?= $category !== '' ? 'Coba kategori lainnya, atau kembali lagi saat UMKM menambahkan produk baru.' : 'Produk akan tampil di sini setelah toko dan produk diverifikasi oleh admin.' ?></p></div></div>
    <?php else: while ($product = mysqli_fetch_assoc($productsResult)): ?>
        <div class="col-sm-6 col-lg-4 col-xl-3">
            <article class="card product-card product-card-<?= mp_escape($productCategoryTones[$product['category']] ?? 'default') ?> h-100">
                <?php if ($product['image_path']): ?><img src="<?= mp_escape($product['image_path']) ?>" class="product-image card-img-top" alt="<?= mp_escape($product['name']) ?>"><?php else: ?><div class="product-image product-placeholder d-flex align-items-center justify-content-center"><i class="bi bi-bag-heart fs-1"></i></div><?php endif; ?>
                <div class="card-body d-flex flex-column">
                    <div class="mb-2"><span class="product-category"><?= mp_escape($product['category']) ?></span></div>
                    <h3 class="h6 fw-bold mt-1 mb-2"><?= mp_escape($product['name']) ?></h3>
                    <p class="small-muted small mb-2"><?= mp_escape(mb_strimwidth($product['description'], 0, 110, '…', 'UTF-8')) ?></p>
                    <div class="store-line"><?php if ($product['logo_path']): ?><img class="shop-logo-mini" src="<?= mp_escape($product['logo_path']) ?>" alt="Logo <?= mp_escape($product['business_name']) ?>"><?php else: ?><i class="bi bi-shop fs-4" aria-hidden="true"></i><?php endif; ?><span><?= mp_escape($product['business_name']) ?></span></div>
                    <p class="small-muted small mb-3"><i class="bi bi-geo-alt me-1"></i><?= mp_escape(trim(($product['kelurahan'] ? $product['kelurahan'] . ', ' : '') . $product['kecamatan'], ', ')) ?> · Stok <?= (int)$product['stock'] ?></p>
                    <a class="product-rating-link mb-3" href="produk.php?id=<?= (int)$product['id'] ?>" aria-label="Detail dan ulasan <?= mp_escape($product['name']) ?>">
                        <span class="product-rating-stars" aria-hidden="true">&#9733;</span>
                        <?php if ((int)$product['review_count'] > 0): ?>
                            <strong><?= number_format((float)$product['average_rating'], 1, ',', '.') ?></strong><span class="small-muted">(<?= (int)$product['review_count'] ?> ulasan)</span>
                        <?php else: ?>
                            <span class="small-muted">Belum ada ulasan</span>
                        <?php endif; ?>
                        <span class="ms-auto">Detail produk <i class="bi bi-arrow-right"></i></span>
                    </a>
                    <div class="mt-auto">
                        <div class="fw-bold product-price mb-3"><?= mp_format_rupiah($product['price']) ?></div>
                        <form method="post" action="keranjang.php" class="d-flex gap-2">
                            <?php mp_render_csrf(); ?>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
                            <input class="form-control form-control-sm" type="number" name="quantity" value="1" min="1" max="<?= (int)$product['stock'] ?>" aria-label="Jumlah" style="max-width:82px">
                            <button class="btn btn-market btn-sm flex-grow-1 fw-semibold" type="submit"><i class="bi bi-bag-plus me-1"></i>Tambah ke Keranjang</button>
                        </form>
                        <a class="btn btn-outline-success btn-sm w-100 mt-2" href="komunikasi.php?shop_id=<?= (int)$product['shop_id'] ?>"><i class="bi bi-chat-dots me-1"></i>Chat dengan toko</a>
                    </div>
                </div>
            </article>
        </div>
    <?php endwhile; endif; ?>
</div>
<?php echo '</div><div class="container pb-4">'; mp_render_contact_section(); echo '</div>'; ?>
<?php mp_render_footer(); ?>
