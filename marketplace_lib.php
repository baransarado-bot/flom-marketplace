<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/mobile_push.php';

if (!mysqli_set_charset($koneksi, 'utf8mb4')) {
    throw new RuntimeException('Koneksi marketplace gagal menggunakan karakter UTF-8 penuh.');
}

function mp_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mp_length($value)
{
    return mb_strlen((string)$value, 'UTF-8');
}

function mp_csrf_token()
{
    if (empty($_SESSION['mp_csrf_token'])) {
        $_SESSION['mp_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['mp_csrf_token'];
}

function mp_verify_csrf()
{
    $token = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    return isset($_SESSION['mp_csrf_token']) && hash_equals($_SESSION['mp_csrf_token'], $token);
}

function mp_render_csrf()
{
    echo '<input type="hidden" name="csrf_token" value="' . mp_escape(mp_csrf_token()) . '">';
}

function mp_render_mobile_bridge()
{
    $role = !empty($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true
        ? 'admin'
        : (!empty($_SESSION['mp_seller_id']) ? 'seller' : '');
    if ($role === '') {
        return;
    }

    echo '<meta name="mp-csrf-token" content="' . mp_escape(mp_csrf_token()) . '">';
    echo '<meta name="mp-user-role" content="' . mp_escape($role) . '">';
    echo '<script defer src="assets/mobile-bridge.js"></script>';
}

function mp_render_loading_screen_assets()
{
    echo '<style>
        .mp-loading-screen { position: fixed; inset: 0; z-index: 2000; display: grid; align-content: center; justify-items: center; gap: 1.5rem; padding: 2rem; background: radial-gradient(ellipse at 50% 43%, rgba(244, 255, 248, .98), rgba(248, 251, 249, .99) 48%, #f1f7f3 100%); opacity: 1; transition: opacity .45s ease, visibility 0s linear .45s; }
        .mp-loading-screen.is-hidden { visibility: hidden; opacity: 0; pointer-events: none; }
        .mp-loading-logo { display: block; width: min(760px, 88vw); height: auto; filter: drop-shadow(0 10px 24px rgba(25, 78, 54, .12)); animation: mp-loading-logo-in .8s ease both; }
        .mp-loading-status { display: grid; justify-items: center; gap: .8rem; color: #315b45; font: 700 .9rem/1.4 system-ui, sans-serif; letter-spacing: .025em; }
        .mp-loading-progress { width: min(210px, 56vw); height: 4px; overflow: hidden; border-radius: 99px; background: rgba(30, 108, 70, .13); }
        .mp-loading-progress::after { display: block; width: 42%; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #f58220, #ffb344 54%, #23804c); content: ""; animation: mp-loading-progress 1.25s ease-in-out infinite; }
        @keyframes mp-loading-logo-in { from { opacity: 0; transform: translateY(8px) scale(.98); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @keyframes mp-loading-progress { from { transform: translateX(-110%); } to { transform: translateX(250%); } }
        @media (prefers-reduced-motion: reduce) { .mp-loading-logo, .mp-loading-progress::after { animation: none; } .mp-loading-screen { transition: none; } }
    </style>';
    echo '<script>(function(){var hide=function(){var screen=document.getElementById("mp-loading-screen");if(screen){screen.classList.add("is-hidden");}};window.addEventListener("load",function(){window.setTimeout(hide,180);},{once:true});window.setTimeout(hide,12000);})();</script>';
}

function mp_render_loading_screen()
{
    echo '<div id="mp-loading-screen" class="mp-loading-screen" role="status" aria-live="polite" aria-label="Memuat FLOM Marketplace"><img class="mp-loading-logo" src="assets/flom-loading-logo.png" alt="FLOM Marketplace — Belanja Mudah, Jualan Berkah"><div class="mp-loading-status"><span>Memuat marketplace...</span><span class="mp-loading-progress" aria-hidden="true"></span></div></div>';
}

function mp_flores_timur_regions()
{
    static $regions = null;
    if ($regions !== null) {
        return $regions;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'wilayah-flores-timur.json';
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Data kecamatan dan desa/kelurahan Flores Timur tidak dapat dibaca.');
    }

    $regions = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($regions) || count($regions) !== 19) {
        throw new RuntimeException('Data wilayah Flores Timur tidak lengkap.');
    }
    foreach ($regions as $district => $villages) {
        if (!is_string($district) || !is_array($villages) || $villages === []) {
            throw new RuntimeException('Format data wilayah Flores Timur tidak valid.');
        }
    }

    return $regions;
}

function mp_flores_timur_label($name)
{
    return mb_convert_case(mb_strtolower((string)$name, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function mp_is_valid_flores_timur_location($district, $village)
{
    $regions = mp_flores_timur_regions();
    return isset($regions[$district]) && in_array($village, $regions[$district], true);
}

function mp_render_flores_timur_location_fields($district, $village)
{
    $regions = mp_flores_timur_regions();
    $selectedDistrict = strtoupper(trim((string)$district));
    if (!isset($regions[$selectedDistrict])) {
        $selectedDistrict = '';
    }
    $selectedVillage = strtoupper(trim((string)$village));
    if ($selectedDistrict === '' || !in_array($selectedVillage, $regions[$selectedDistrict], true)) {
        $selectedVillage = '';
    }
    $regionJson = json_encode($regions, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

    echo '<div class="col-12" data-flores-timur-location data-regions="' . mp_escape($regionJson) . '"><div class="row g-3">';
    echo '<div class="col-md-6"><label class="form-label" for="shop-kecamatan">Kecamatan</label><select class="form-select" id="shop-kecamatan" name="kecamatan" data-location-district required><option value="">Pilih kecamatan</option>';
    foreach ($regions as $districtName => $villages) {
        $selected = $selectedDistrict === $districtName ? ' selected' : '';
        echo '<option value="' . mp_escape($districtName) . '"' . $selected . '>' . mp_escape(mp_flores_timur_label($districtName)) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="col-md-6"><label class="form-label" for="shop-kelurahan">Desa/Kelurahan</label><select class="form-select" id="shop-kelurahan" name="kelurahan" data-location-village required' . ($selectedDistrict === '' ? ' disabled' : '') . '><option value="">Pilih desa/kelurahan</option>';
    if ($selectedDistrict !== '') {
        foreach ($regions[$selectedDistrict] as $villageName) {
            $selected = $selectedVillage === $villageName ? ' selected' : '';
            echo '<option value="' . mp_escape($villageName) . '"' . $selected . '>' . mp_escape(mp_flores_timur_label($villageName)) . '</option>';
        }
    }
    echo '</select></div></div></div>';
    echo '<script src="assets/flores-timur-location.js"></script>';
}

function mp_supported_banks()
{
    return [
        'BRI' => 'assets/banks/bri.webp',
        'BNI' => 'assets/banks/bni.png',
        'MANDIRI' => 'assets/banks/mandiri.webp',
        'BCA' => 'assets/banks/bca.png',
        'BNI SYARIAH' => 'assets/banks/bni-syariah.jpg',
        'BPD NTT' => 'assets/banks/bpd-ntt.png',
    ];
}

function mp_render_bank_selector($selectedBank, $legacyBank = '')
{
    $banks = mp_supported_banks();
    $selectedBank = (string)$selectedBank;
    $selectedLogo = $banks[$selectedBank] ?? '';
    echo '<div class="bank-selector">';
    echo '<div class="bank-preview" data-bank-preview aria-live="polite">';
    if ($selectedLogo !== '') {
        echo '<img src="' . mp_escape($selectedLogo) . '" alt="Logo ' . mp_escape($selectedBank) . '" data-bank-logo>';
    } else {
        echo '<span class="bank-preview-placeholder" data-bank-placeholder aria-hidden="true"><i class="bi bi-bank"></i></span>';
    }
    echo '<span class="bank-preview-label" data-bank-label>' . mp_escape($selectedBank !== '' ? $selectedBank : 'Pilih bank') . '</span></div>';
    echo '<select class="form-select" name="bank_name" id="bank-name" data-bank-select required><option value="">Pilih nama bank</option>';
    if ($legacyBank !== '' && !isset($banks[$legacyBank])) {
        echo '<option value="' . mp_escape($legacyBank) . '" data-logo=""' . ($selectedBank === $legacyBank ? ' selected' : '') . '>' . mp_escape($legacyBank) . ' (nama rekening tersimpan)</option>';
    }
    foreach ($banks as $bankName => $logoPath) {
        echo '<option value="' . mp_escape($bankName) . '" data-logo="' . mp_escape($logoPath) . '"' . ($selectedBank === $bankName ? ' selected' : '') . '>' . mp_escape($bankName) . '</option>';
    }
    echo '</select></div>';
    echo '<style>.bank-selector{display:grid;gap:.65rem}.bank-preview{display:flex;min-height:76px;align-items:center;gap:.8rem;padding:.55rem .75rem;border:1px solid #e1e9e3;border-radius:.75rem;background:#fff}.bank-preview img,.bank-preview-placeholder{display:grid;width:120px;height:56px;flex:0 0 120px;place-items:center;object-fit:contain}.bank-preview-placeholder{border-radius:.5rem;background:#f0f4f1;color:#638272;font-size:1.3rem}.bank-preview-label{font-size:.9rem;font-weight:700;color:#315548}</style>';
    echo '<script>(function(){var select=document.querySelector("[data-bank-select]");if(!select)return;var preview=select.closest(".bank-selector").querySelector("[data-bank-preview]");var label=preview.querySelector("[data-bank-label]");function update(){var option=select.options[select.selectedIndex];var logo=option?option.dataset.logo:"";var image=preview.querySelector("[data-bank-logo]");var placeholder=preview.querySelector("[data-bank-placeholder]");label.textContent=select.value||"Pilih bank";if(logo){if(!image){image=document.createElement("img");image.dataset.bankLogo="";preview.insertBefore(image,label)}image.src=logo;image.alt="Logo "+select.value;if(placeholder)placeholder.remove()}else{if(image)image.remove();if(!placeholder){placeholder=document.createElement("span");placeholder.className="bank-preview-placeholder";placeholder.dataset.bankPlaceholder="";placeholder.setAttribute("aria-hidden","true");placeholder.innerHTML=\'<i class="bi bi-bank"></i>\';preview.insertBefore(placeholder,label)}}}select.addEventListener("change",update)})();</script>';
    echo '<div class="form-text">Logo ditampilkan sebagai pratinjau. BNI Syariah merupakan nama lama yang telah berintegrasi ke BSI; pastikan nama bank sesuai rekening penerima.</div>';
}

function mp_format_rupiah($amount)
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function mp_xlsx_column_name($columnNumber)
{
    $columnName = '';
    while ($columnNumber > 0) {
        $remainder = ($columnNumber - 1) % 26;
        $columnName = chr(65 + $remainder) . $columnName;
        $columnNumber = intdiv($columnNumber - 1, 26);
    }
    return $columnName;
}

function mp_xlsx_xml_escape($value)
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mp_download_xlsx($filename, array $headers, array $rows)
{
    if (headers_sent($outputFile, $outputLine)) {
        throw new RuntimeException('Ekspor laporan tidak dapat dimulai karena halaman sudah mengirim keluaran.');
    }

    $worksheetRows = array_merge([$headers], $rows);
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($worksheetRows as $rowIndex => $row) {
        $excelRow = $rowIndex + 1;
        $sheetXml .= '<row r="' . $excelRow . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $cellReference = mp_xlsx_column_name($columnIndex + 1) . $excelRow;
            if (is_int($value) || is_float($value)) {
                $sheetXml .= '<c r="' . $cellReference . '"><v>' . $value . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $cellReference . '" t="inlineStr"><is><t xml:space="preserve">' . mp_xlsx_xml_escape($value) . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Laporan Penjualan" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>',
        'xl/worksheets/sheet1.xml' => $sheetXml,
    ];

    $archive = '';
    $centralDirectory = '';
    $fileCount = 0;
    foreach ($files as $path => $contents) {
        $pathLength = strlen($path);
        $contentLength = strlen($contents);
        $checksum = crc32($contents);
        $fileOffset = strlen($archive);
        $archive .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $checksum, $contentLength, $contentLength, $pathLength, 0)
            . $path . $contents;
        $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $checksum, $contentLength, $contentLength, $pathLength, 0, 0, 0, 0, 0, $fileOffset)
            . $path;
        $fileCount++;
    }

    $archive .= $centralDirectory . pack('VvvvvVVv', 0x06054b50, 0, 0, $fileCount, $fileCount, strlen($centralDirectory), strlen($archive), 0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . strlen($archive));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $archive;
    exit;
}

function mp_redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function mp_set_flash($type, $message)
{
    $_SESSION['mp_flash'] = ['type' => $type, 'message' => $message];
}

function mp_get_flash()
{
    if (empty($_SESSION['mp_flash'])) {
        return null;
    }
    $flash = $_SESSION['mp_flash'];
    unset($_SESSION['mp_flash']);
    return $flash;
}

function mp_require_seller()
{
    if (empty($_SESSION['mp_seller_id'])) {
        mp_set_flash('warning', 'Silakan masuk sebagai UMKM terlebih dahulu.');
        mp_redirect('masuk_umkm.php');
    }
}

function mp_render_header($title, $showSellerLinks = false, $fullBleedHero = false)
{
    global $koneksi;

    $title = mp_escape($title);
    $bodyClass = $fullBleedHero ? ' class="marketplace-home"' : '';
    $navbarVariant = $fullBleedHero ? ' navbar-market-overlay' : '';
    $cartCount = 0;
    if (isset($_SESSION['mp_cart']) && is_array($_SESSION['mp_cart'])) {
        foreach ($_SESSION['mp_cart'] as $quantity) {
            if (is_scalar($quantity) && is_numeric($quantity)) {
                $cartCount += max(0, (int)$quantity);
            }
        }
    }
    $logosResult = mysqli_query($koneksi, 'SELECT slot, image_path FROM mp_site_logos');
    $logos = [];
    while ($logo = mysqli_fetch_assoc($logosResult)) {
        $logos[$logo['slot']] = $logo['image_path'];
    }
    $governmentLogo = isset($logos['government'])
        ? '<img class="navbar-logo navbar-logo-government" src="' . mp_escape($logos['government']) . '" alt="Logo Pemerintah Kabupaten Flores Timur">'
        : '<span class="brand-mark"><i class="bi bi-bank"></i></span>';
    $marketplaceLogo = isset($logos['marketplace'])
        ? '<img class="navbar-logo navbar-logo-marketplace" src="' . mp_escape($logos['marketplace']) . '" alt="Logo FLOM Marketplace dan tagline">'
        : '<span class="brand-mark"><i class="bi bi-shop"></i></span>';
    $sellerLink = $showSellerLinks
        ? '<a class="nav-link" href="toko_saya.php">Kelola Toko</a><a class="nav-link" href="komunikasi.php">Pesan</a><a class="nav-link" href="keluar_umkm.php">Keluar</a>'
        : '';
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    mp_render_mobile_bridge();
    mp_render_loading_screen_assets();
    echo '<title>' . $title . ' - Pasar Ekraf Flores Timur</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">';
    echo '<style>body{background:#f4f7f6;color:#18352d}.navbar-market{position:sticky;top:0;z-index:1030;min-height:144px;padding:.8rem 0;background:#123d33}.navbar-market>.container{min-height:120px}.navbar-market .navbar-brand{padding:0}.brand-mark{color:#f2c66d}.navbar-brand-logos{display:inline-flex;align-items:center;gap:.8rem}.navbar-logo{display:block;object-fit:contain;background:transparent;border:0;border-radius:0;padding:0}.navbar-logo-government{width:80px;height:80px}.navbar-logo-marketplace{width:340px;height:114px}.navbar-brand-logos .brand-mark{display:grid;width:80px;height:80px;place-items:center;font-size:2rem}.navbar-market .navbar-nav .header-nav-link{display:inline-flex;align-items:center;gap:.45rem;margin-left:.5rem;border:1px solid rgba(255,255,255,.55);border-radius:.65rem;padding:.6rem .9rem;color:#fff;font-size:.9rem;font-weight:600;line-height:1.25;transition:background-color .18s ease,border-color .18s ease}.navbar-market .navbar-nav .header-nav-link:hover,.navbar-market .navbar-nav .header-nav-link:focus-visible{border-color:#fff;background:rgba(255,255,255,.13);color:#fff}.navbar-market .navbar-nav .header-nav-link:focus-visible{outline:2px solid #f2c66d;outline-offset:2px}.hero-market{background:linear-gradient(130deg,#123d33,#24735d);color:#fff;border-radius:1.25rem}.product-image{height:190px;object-fit:cover}.card{border:0;box-shadow:0 8px 24px rgba(17,53,43,.07)}.btn-market{background:#167354;color:#fff}.btn-market:hover{background:#105c43;color:#fff}.text-market{color:#167354}.small-muted{color:#687c75}@media(max-width:575.98px){.navbar-market{min-height:128px;padding:.55rem 0}.navbar-market>.container{min-height:116px;align-items:flex-start;flex-direction:column;gap:.25rem}.navbar-market .navbar-brand{font-size:.98rem;gap:.45rem!important}.navbar-market .navbar-nav{flex-direction:row;flex-wrap:wrap;gap:.35rem;margin-left:0!important}.navbar-market .navbar-nav .header-nav-link{margin-left:0;padding:.45rem .65rem;font-size:.78rem}.navbar-market .nav-link:not(.header-nav-link){padding:.25rem 0;font-size:.82rem}.navbar-logo-government,.navbar-brand-logos .brand-mark{width:54px;height:54px}.navbar-logo-marketplace{width:230px;height:77px}.navbar-brand-logos{gap:.5rem}}</style>';
    echo '<style>.navbar-market .navbar-nav .header-nav-link{border-color:#f58220;background:#f58220;color:#fff;font-weight:800}.navbar-market .navbar-nav .header-nav-link:hover,.navbar-market .navbar-nav .header-nav-link:focus-visible{border-color:#ff9a3d;background:#ff8b2b;color:#fff}</style>';
    echo '<style>
        .header-cart-wrapper { position: relative; flex: 0 0 auto; }
        .navbar-market .navbar-nav .header-cart-wrapper { margin-left: .35rem; }
        .header-cart-link { position: relative; display: grid; width: 48px; height: 46px; place-items: center; border: 1px solid #f58220; border-radius: .65rem; padding: 0; background: #f58220; color: #fff; font-size: 1.35rem; text-decoration: none; }
        .header-cart-link:hover, .header-cart-link:focus-visible { border-color: #ff9a3d; background: #ff8b2b; color: #fff; }
        .header-cart-link:focus-visible { outline: 2px solid #f2c66d; outline-offset: 2px; }
        .product-cart-link { display: inline-flex; width: auto; min-height: 46px; align-items: center; justify-content: center; gap: .45rem; padding: .6rem .9rem; font-size: .9rem; font-weight: 800; white-space: nowrap; }
        .header-cart-count { position: absolute; top: -7px; right: -7px; display: grid; min-width: 19px; height: 19px; place-items: center; border: 2px solid #123d33; border-radius: 99px; padding: 0 .25rem; background: #fff; color: #db6410; font-size: .65rem; font-weight: 800; line-height: 1; }
        .header-cart-preview { position: absolute; z-index: 1040; top: calc(100% + 13px); right: 0; width: min(360px, calc(100vw - 2rem)); padding: 1.25rem 1.4rem 1.35rem; border: 1px solid #e7ece8; border-radius: 1rem; background: #fff; box-shadow: 0 20px 48px rgba(18, 45, 33, .22); color: #18352d; text-align: center; opacity: 0; visibility: hidden; transform: translateY(7px); transition: opacity .18s ease, transform .18s ease, visibility .18s ease; }
        .header-cart-wrapper:hover .header-cart-preview, .header-cart-wrapper:focus-within .header-cart-preview { opacity: 1; visibility: visible; transform: translateY(0); }
        .header-cart-preview::before { position: absolute; top: -8px; right: 18px; width: 15px; height: 15px; border-top: 1px solid #e7ece8; border-left: 1px solid #e7ece8; background: #fff; content: ""; transform: rotate(45deg); }
        .header-cart-preview-art { display: block; width: 144px; height: 108px; max-width: none; margin: 0 auto .5rem; object-fit: contain; }
        .header-cart-preview-title { margin: 0 0 .35rem; color: #161b19; font-size: 1.15rem; font-weight: 800; line-height: 1.35; }
        .header-cart-preview-copy { margin: 0 0 1rem; color: #727b76; font-size: .9rem; }
        .header-cart-preview-action { display: inline-flex; min-width: 150px; min-height: 40px; align-items: center; justify-content: center; border: 1px solid #f58220; border-radius: .55rem; padding: .45rem .85rem; background: #f58220; color: #fff; font-size: .9rem; font-weight: 800; text-decoration: none; }
        .header-cart-preview-action:hover, .header-cart-preview-action:focus-visible { border-color: #ff9a3d; background: #ff8b2b; color: #fff; }
        @media(max-width:991.98px) { .navbar-market .navbar-nav .header-cart-wrapper { margin-left: 0; } .navbar-market .navbar-nav .header-cart-link { width: 42px; height: 42px; } }
        @media(max-width:575.98px) { .navbar-market .navbar-nav .header-cart-link { width: 38px; height: 38px; } .header-cart-preview { top: calc(100% + 10px); padding: 1rem; } }
        @media(prefers-reduced-motion:reduce) { .header-cart-preview { transition: none; } }
    </style>';
    echo '<style>
        html { -webkit-text-size-adjust: 100%; }
        body { min-width: 320px; overflow-x: hidden; }
        img, svg, video, iframe { max-width: 100%; }
        input, select, textarea, button { max-width: 100%; }
        .marketplace-main { min-width: 0; }
        .marketplace-main .row > * { min-width: 0; }
        .table-responsive { overscroll-behavior-x: contain; }
        .table-responsive > table { min-width: 600px; }
        .contact-value, .small-muted, .form-text { overflow-wrap: anywhere; }
        @media (max-width: 991.98px) {
            .navbar-market { min-height: 0; padding: .65rem 0; }
            .navbar-market > .container { min-height: 0; flex-wrap: wrap; gap: .5rem 1rem; }
            .navbar-logo-government, .navbar-brand-logos .brand-mark { width: 62px; height: 62px; }
            .navbar-logo-marketplace { width: min(260px, 34vw); height: 88px; }
            .navbar-market .navbar-nav { flex-direction: row; flex-wrap: wrap; justify-content: flex-end; gap: .35rem; margin-left: auto !important; }
            .navbar-market .navbar-nav .header-nav-link { margin-left: 0; }
        }
        @media (max-width: 767.98px) {
            .container, .container-fluid { --bs-gutter-x: 1.25rem; }
            .table-responsive > table { min-width: 560px; }
            .card-body, .card.p-4 { overflow-wrap: anywhere; }
            .form-control, .form-select { min-width: 0; }
            .btn { white-space: normal; }
        }
        @media (max-width: 575.98px) {
            .navbar-market { padding: .55rem 0; }
            .navbar-market > .container { align-items: flex-start; flex-direction: column; gap: .25rem; }
            .navbar-brand-logos { max-width: 100%; gap: .45rem; }
            .navbar-logo-government, .navbar-brand-logos .brand-mark { width: 48px; height: 48px; }
            .navbar-logo-marketplace { width: min(210px, 56vw); height: 70px; }
            .navbar-market .navbar-nav { width: 100%; justify-content: flex-start; gap: .35rem; margin-left: 0 !important; }
            .navbar-market .navbar-nav .header-nav-link { padding: .45rem .6rem; font-size: .76rem; }
            .market-search-panel { padding: .8rem; }
            .report-actions, .hero-actions { align-items: stretch; }
            .report-actions > *, .hero-actions > * { max-width: 100%; }
            .contact-section { padding: 1rem; }
            .contact-map-frame { height: 250px; }
            .login-frame { max-width: 100%; }
        }
    </style></head><body' . $bodyClass . '>';
    mp_render_loading_screen();
    $cartPreview = $cartCount === 0
        ? '<img class="header-cart-preview-art" src="assets/cart-empty.svg" alt=""><h2 class="header-cart-preview-title">Wah, keranjang belanjamu kosong</h2><p class="header-cart-preview-copy">Yuk, isi dengan barang-barang impianmu!</p><a class="header-cart-preview-action" href="marketplace.php#kategori-produk">Mulai Belanja</a>'
        : '<img class="header-cart-preview-art" src="assets/cart-empty.svg" alt=""><h2 class="header-cart-preview-title">Keranjang belanjamu</h2><p class="header-cart-preview-copy">' . $cartCount . ' barang siap dipesan.</p><a class="header-cart-preview-action" href="keranjang.php">Lihat Keranjang</a>';
    echo '<nav class="navbar navbar-expand-lg navbar-dark navbar-market' . $navbarVariant . '"><div class="container"><a class="navbar-brand d-inline-flex align-items-center" href="marketplace.php" aria-label="Beranda Pasar Ekraf Flores Timur"><span class="navbar-brand-logos">' . $governmentLogo . $marketplaceLogo . '</span></a><div class="navbar-nav ms-auto"><a class="nav-link header-nav-link" href="lacak_pesanan.php"><i class="bi bi-truck"></i>Lacak Pesanan</a><a class="nav-link header-nav-link header-nav-register" href="daftar_umkm.php"><i class="bi bi-person-plus"></i>Daftar</a><a class="nav-link header-nav-link" href="login.php"><i class="bi bi-person-circle"></i>Masuk Penjual / Admin</a><div class="header-cart-wrapper"><a class="header-cart-link" href="keranjang.php" aria-label="Keranjang belanja, ' . $cartCount . ' barang" title="Keranjang belanja"><i class="bi bi-cart3" aria-hidden="true"></i>' . ($cartCount > 0 ? '<span class="header-cart-count">' . $cartCount . '</span>' : '') . '</a><div class="header-cart-preview" role="region" aria-label="Pratinjau keranjang">' . $cartPreview . '</div></div>' . $sellerLink . '</div></div></nav><main class="container py-4 marketplace-main">';
}

function mp_render_flash()
{
    $flash = mp_get_flash();
    if ($flash) {
        echo '<div class="alert alert-' . mp_escape($flash['type']) . ' alert-dismissible fade show" role="alert">';
        echo mp_escape($flash['message']) . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button></div>';
    }
}

function mp_render_contact_section()
{
    global $koneksi;

    $defaults = [
        'contact_intro' => 'Kami menyediakan berbagai media yang dapat Anda hubungi untuk informasi layanan marketplace dan ekonomi kreatif Flores Timur.',
        'contact_work_hours' => 'Senin-Jumat, 08.00-16.00 WITA',
        'contact_address' => 'Jl. A. Yani, Puken Tobi Wangibao, Larantuka',
        'contact_email' => 'faslafpflomur@gmail.com',
        'contact_phone' => '082129839734',
        'contact_map_url' => 'https://www.google.com/maps?q=Jl.+A.+Yani,+Puken+Tobi+Wangibao,+Larantuka&output=embed',
    ];
    $settingsResult = mysqli_query($koneksi, "SELECT setting_key, setting_value FROM mp_site_settings WHERE setting_key LIKE 'contact\\_%'");
    $contact = $defaults;
    while ($setting = mysqli_fetch_assoc($settingsResult)) {
        if (array_key_exists($setting['setting_key'], $defaults)) {
            $contact[$setting['setting_key']] = $setting['setting_value'];
        }
    }
    $phoneHref = preg_replace('/[^0-9+]/', '', $contact['contact_phone']);

    echo '<section class="contact-section mb-4" id="hubungi-kami"><div class="row g-4 align-items-center">';
    echo '<div class="col-12 col-lg-6"><div class="contact-map-frame"><iframe src="' . mp_escape($contact['contact_map_url']) . '" title="Peta lokasi kontak FLOM Marketplace" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div></div>';
    echo '<div class="col-12 col-lg-6"><div class="contact-content"><div class="contact-eyebrow">Hubungi Kami</div><h2 class="contact-title">Kontak Kami</h2><p class="contact-intro">' . nl2br(mp_escape($contact['contact_intro'])) . '</p>';
    echo '<div class="contact-detail"><span class="contact-icon"><i class="bi bi-clock"></i></span><div><div class="contact-label">Jadwal Kerja</div><div class="contact-value">' . nl2br(mp_escape($contact['contact_work_hours'])) . '</div></div></div>';
    echo '<div class="contact-detail"><span class="contact-icon"><i class="bi bi-geo-alt"></i></span><div><div class="contact-label">Alamat</div><div class="contact-value">' . nl2br(mp_escape($contact['contact_address'])) . '</div></div></div>';
    echo '<div class="contact-detail"><span class="contact-icon"><i class="bi bi-envelope"></i></span><div><div class="contact-label">Email</div><div class="contact-value"><a href="mailto:' . mp_escape($contact['contact_email']) . '">' . mp_escape($contact['contact_email']) . '</a></div></div></div>';
    echo '<div class="contact-detail"><span class="contact-icon"><i class="bi bi-telephone"></i></span><div><div class="contact-label">Telepon</div><div class="contact-value"><a href="tel:' . mp_escape($phoneHref) . '">' . mp_escape($contact['contact_phone']) . '</a></div></div></div>';
    echo '</div></div></div></section>';
    echo '<style>.contact-section{overflow:hidden;padding:clamp(1.25rem,3.5vw,3.25rem);border:1px solid #dbe5ef;border-radius:1.5rem;background:linear-gradient(135deg,#f2f6fb,#eaf1f8);box-shadow:0 14px 36px rgba(34,57,84,.08)}.contact-map-frame{height:360px;overflow:hidden;border:1px solid #d1dce8;border-radius:1rem;background:#e5ebf2;box-shadow:0 12px 26px rgba(34,57,84,.12)}.contact-map-frame iframe{display:block;width:100%;height:100%;border:0}.contact-content{padding:.25rem clamp(0rem,2vw,1.25rem)}.contact-eyebrow{color:#607890;font-size:.75rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.contact-title{margin:.35rem 0 .65rem;color:#263b55;font-size:clamp(2rem,4vw,2.7rem);font-weight:800;letter-spacing:-.04em}.contact-intro{margin-bottom:1.35rem;color:#718297;line-height:1.75}.contact-detail{display:flex;align-items:center;gap:.9rem;margin-top:1rem}.contact-icon{display:grid;width:48px;height:48px;flex:0 0 48px;place-items:center;border:1px solid rgba(255,255,255,.6);border-radius:.85rem;background:#6f88a4;color:#fff;font-size:1.15rem;box-shadow:0 5px 12px rgba(45,69,96,.14)}.contact-label{margin-bottom:.12rem;color:#718297;font-size:.7rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.contact-value{color:#2b405a;font-size:.95rem;font-weight:700;line-height:1.55;overflow-wrap:anywhere}.contact-value a{color:inherit;text-decoration:none}.contact-value a:hover{text-decoration:underline}@media(max-width:991.98px){.contact-map-frame{height:320px}}@media(max-width:575.98px){.contact-section{border-radius:1rem}.contact-map-frame{height:280px}.contact-detail{gap:.7rem}.contact-icon{width:42px;height:42px;flex-basis:42px}}</style>';
}

function mp_render_footer()
{
    global $koneksi;

    $settingsResult = mysqli_query($koneksi, "SELECT setting_key, setting_value FROM mp_site_settings WHERE setting_key IN ('app_name', 'copyright_text')");
    $settings = [];
    while ($setting = mysqli_fetch_assoc($settingsResult)) {
        $settings[$setting['setting_key']] = $setting['setting_value'];
    }
    $appName = trim($settings['app_name'] ?? '') ?: 'FLOM Marketplace Flores Timur';
    $copyrightText = trim($settings['copyright_text'] ?? '') ?: 'Pemerintah Kabupaten Flores Timur';

    $logosResult = mysqli_query($koneksi, "SELECT slot, image_path FROM mp_site_logos WHERE slot IN ('government', 'marketplace')");
    $logos = [];
    while ($logo = mysqli_fetch_assoc($logosResult)) {
        $logos[$logo['slot']] = $logo['image_path'];
    }
    $governmentLogo = isset($logos['government'])
        ? '<img class="footer-logo footer-logo-government" src="' . mp_escape($logos['government']) . '" alt="Logo Pemerintah Kabupaten Flores Timur">'
        : '<span class="footer-logo-placeholder" aria-hidden="true"><i class="bi bi-bank"></i></span>';
    $marketplaceLogo = isset($logos['marketplace'])
        ? '<img class="footer-logo footer-logo-marketplace" src="' . mp_escape($logos['marketplace']) . '" alt="Logo FLOM Marketplace">'
        : '<span class="footer-logo-placeholder" aria-hidden="true"><i class="bi bi-shop"></i></span>';

    echo '</main><footer class="site-footer"><div class="container text-center"><div class="footer-logos">' . $governmentLogo . $marketplaceLogo . '</div>';
    echo '<div class="footer-app-name">' . mp_escape($appName) . '</div>';
    echo '<div class="footer-copyright">&copy; ' . date('Y') . ' ' . mp_escape($copyrightText) . '</div></div></footer>';
    echo '<style>.site-footer{padding:1.5rem 0 1.75rem;border-top:1px solid #e2eae4;background:#fff;color:#687c75}.footer-logos{display:flex;align-items:center;justify-content:center;gap:1rem;margin-bottom:.8rem}.footer-logo{display:block;object-fit:contain;background:transparent}.footer-logo-government{width:46px;height:46px}.footer-logo-marketplace{width:170px;height:57px}.footer-logo-placeholder{display:grid;width:46px;height:46px;place-items:center;border:1px solid #dfe8e1;border-radius:.65rem;color:#167354;font-size:1.4rem}.footer-app-name{color:#18352d;font-size:.95rem;font-weight:700}.footer-copyright{margin-top:.2rem;font-size:.8rem}@media(max-width:575.98px){.footer-logo-government,.footer-logo-placeholder{width:38px;height:38px}.footer-logo-marketplace{width:138px;height:46px}.footer-logos{gap:.75rem}}</style>';
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html>';
}
