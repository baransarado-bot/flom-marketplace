<?php
require_once __DIR__ . '/marketplace_lib.php';
mp_require_seller();

$shopId = (int)$_SESSION['mp_seller_id'];
$shopStmt = mysqli_prepare($koneksi, 'SELECT * FROM mp_shops WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($shopStmt, 'i', $shopId);
mysqli_stmt_execute($shopStmt);
$shop = mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt));
if (!$shop) {
    unset($_SESSION['mp_seller_id']);
    mp_set_flash('danger', 'Akun toko tidak ditemukan. Silakan masuk kembali.');
    mp_redirect('masuk_umkm.php');
}

$sellerMenus = [
    'ringkasan' => 'Ringkasan toko',
    'profil' => 'Profil dan rekening',
    'keamanan' => 'Keamanan akun',
    'produk' => 'Tambah produk',
    'daftar-produk' => 'Produk toko',
    'pesanan' => 'Pesanan masuk',
];
$requestedMenu = $_GET['menu'] ?? ($_POST['menu'] ?? 'ringkasan');
$activeMenu = is_string($requestedMenu) && isset($sellerMenus[$requestedMenu]) ? $requestedMenu : 'ringkasan';
if (isset($_GET['edit'])) {
    $activeMenu = 'produk';
}

$error = '';
$postSizeError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH'])) {
    $postMaxSize = trim((string)ini_get('post_max_size'));
    $sizeUnit = strtolower(substr($postMaxSize, -1));
    $sizeMultiplier = ['g' => 1024 * 1024 * 1024, 'm' => 1024 * 1024, 'k' => 1024][$sizeUnit] ?? 1;
    $postLimit = (int)$postMaxSize * $sizeMultiplier;
    if ($postLimit > 0 && (int)$_SERVER['CONTENT_LENGTH'] > $postLimit) {
        $postSizeError = 'Total ukuran data foto melebihi batas server (' . $postMaxSize . '). Pilih foto yang lebih kecil.';
    }
}
$categories = ['Makanan', 'Minuman', 'Tenun', 'Kerajinan Tangan', 'Kuliner', 'Kriya / Kerajinan', 'Fashion / Tenun', 'Seni dan Kreativitas', 'Pertanian dan Olahan', 'Lainnya'];
$shopLogoDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads_toko';
$productUploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads_produk';
$deleteProductImages = static function ($imagePaths) use ($productUploadDirectory) {
    $uploadRoot = realpath($productUploadDirectory);
    if (!$uploadRoot) {
        return;
    }
    foreach ($imagePaths as $imagePath) {
        $prefix = 'uploads_produk/';
        if (substr((string)$imagePath, 0, strlen($prefix)) !== $prefix) {
            continue;
        }
        $fileName = substr((string)$imagePath, strlen($prefix));
        if ($fileName === '' || basename($fileName) !== $fileName) {
            continue;
        }
        $filePath = realpath($productUploadDirectory . DIRECTORY_SEPARATOR . $fileName);
        if ($filePath && strpos($filePath, $uploadRoot . DIRECTORY_SEPARATOR) === 0 && is_file($filePath) && !unlink($filePath)) {
            error_log('Marketplace product image file could not be removed: ' . $filePath);
        }
    }
};
$deleteShopLogo = static function ($imagePath) use ($shopLogoDirectory) {
    $prefix = 'uploads_toko/';
    if (substr((string)$imagePath, 0, strlen($prefix)) !== $prefix) {
        return;
    }
    $fileName = substr((string)$imagePath, strlen($prefix));
    if ($fileName === '' || basename($fileName) !== $fileName) {
        return;
    }
    $filePath = $shopLogoDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($filePath) && !unlink($filePath)) {
        error_log('Marketplace shop logo file could not be removed: ' . $filePath);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($postSizeError !== '') {
        $error = $postSizeError;
    } elseif (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_product') {
            if ($shop['status'] !== 'approved') {
                $error = 'Toko harus diverifikasi admin sebelum dapat mengelola produk.';
            } else {
                $productId = filter_var($_POST['product_id'] ?? 0, FILTER_VALIDATE_INT);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $category = trim($_POST['category'] ?? '');
                $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);
                $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
                $existingImage = null;
                $existingGalleryImages = [];
                $existingProductImages = array_fill(0, 5, null);
                if ($productId) {
                    $checkStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_products WHERE id = ? AND shop_id = ? LIMIT 1');
                    mysqli_stmt_bind_param($checkStmt, 'ii', $productId, $shopId);
                    mysqli_stmt_execute($checkStmt);
                    $existingProduct = mysqli_fetch_assoc(mysqli_stmt_get_result($checkStmt));
                    if (!$existingProduct) {
                        $error = 'Produk tidak ditemukan pada toko Anda.';
                    } else {
                        $existingImage = $existingProduct['image_path'];
                        $existingProductImages[0] = $existingImage;
                        $galleryStmt = mysqli_prepare($koneksi, 'SELECT image_path, sort_order FROM mp_product_images WHERE product_id = ? ORDER BY sort_order, id');
                        mysqli_stmt_bind_param($galleryStmt, 'i', $productId);
                        mysqli_stmt_execute($galleryStmt);
                        $galleryResult = mysqli_stmt_get_result($galleryStmt);
                        while ($galleryImage = mysqli_fetch_assoc($galleryResult)) {
                            $existingGalleryImages[] = $galleryImage['image_path'];
                            $gallerySlot = (int)$galleryImage['sort_order'];
                            if ($gallerySlot >= 1 && $gallerySlot < 5) {
                                $existingProductImages[$gallerySlot] = $galleryImage['image_path'];
                            }
                        }
                    }
                }
                if ($error === '' && (!$name || !$description || !in_array($category, $categories, true) || $price === false || $price < 1 || $stock === false || $stock < 0 || $stock > 2147483647 || mp_length($name) > 160 || mp_length($category) > 80 || mp_length($description) > 3000)) {
                    $error = 'Nama, deskripsi, kategori, harga, dan stok produk harus valid.';
                }

                $newImages = [];
                $hasNewImages = false;
                if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                    $uploadIndexes = [];
                    foreach ($_FILES['images']['error'] as $index => $uploadError) {
                        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                            $uploadIndexes[] = $index;
                        }
                    }
                    if (count($uploadIndexes) > 5) {
                        $error = 'Pilih maksimal 5 foto produk.';
                    } elseif ($uploadIndexes !== []) {
                        $hasNewImages = true;
                        if (!is_dir($productUploadDirectory) && !mkdir($productUploadDirectory, 0755, true) && !is_dir($productUploadDirectory)) {
                            $error = 'Folder foto produk tidak dapat dibuat. Periksa izin folder aplikasi.';
                        }
                        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        foreach ($uploadIndexes as $index) {
                            if ($error !== '') {
                                break;
                            }
                            $temporaryPath = $_FILES['images']['tmp_name'][$index];
                            $uploadError = (int)$_FILES['images']['error'][$index];
                            if ($uploadError !== UPLOAD_ERR_OK) {
                                $uploadErrors = [
                                    UPLOAD_ERR_INI_SIZE => 'ukuran file melebihi batas unggah server',
                                    UPLOAD_ERR_FORM_SIZE => 'ukuran file melebihi batas formulir',
                                    UPLOAD_ERR_PARTIAL => 'file hanya terunggah sebagian',
                                    UPLOAD_ERR_NO_TMP_DIR => 'folder sementara unggahan tidak tersedia',
                                    UPLOAD_ERR_CANT_WRITE => 'file gagal ditulis ke penyimpanan server',
                                    UPLOAD_ERR_EXTENSION => 'unggahan dihentikan oleh konfigurasi server',
                                ];
                                $error = 'Foto ' . ($index + 1) . ' gagal diunggah: ' . ($uploadErrors[$uploadError] ?? 'terjadi kesalahan unggah') . '.';
                                break;
                            }
                            if ($_FILES['images']['size'][$index] > 3 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
                                $error = 'Setiap foto harus berukuran maksimal 3 MB dan diunggah melalui formulir.';
                                break;
                            }
                            $mime = $finfo->file($temporaryPath);
                            $imageInfo = @getimagesize($temporaryPath);
                            if (!isset($extensions[$mime]) || $imageInfo === false || $imageInfo['mime'] !== $mime) {
                                $error = 'Format setiap foto harus berupa JPG, PNG, atau WebP yang valid.';
                                break;
                            }
                            $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                            $destination = $productUploadDirectory . DIRECTORY_SEPARATOR . $fileName;
                            if (!move_uploaded_file($temporaryPath, $destination)) {
                                $error = 'Foto tidak dapat disimpan. Periksa izin folder uploads_produk.';
                                break;
                            }
                            $newImages[$index] = 'uploads_produk/' . $fileName;
                        }
                    }
                }
                if ($error === '' && !$productId && !isset($newImages[0])) {
                    $error = 'Pilih satu foto sampul produk terlebih dahulu.';
                }

                if ($error === '' && $productId && !$existingProduct) {
                    $error = 'Produk tidak ditemukan pada toko Anda.';
                }

                if ($error === '') {
                    $imagePath = $newImages[0] ?? $existingImage;
                    $oldImages = [];
                    foreach ($newImages as $slot => $newImage) {
                        if ($existingProductImages[$slot]) {
                            $oldImages[] = $existingProductImages[$slot];
                        }
                    }
                    try {
                        mysqli_begin_transaction($koneksi);
                        if ($productId) {
                            $saveStmt = mysqli_prepare($koneksi, "UPDATE mp_products SET name = ?, description = ?, category = ?, price = ?, stock = ?, image_path = ?, status = 'pending' WHERE id = ? AND shop_id = ?");
                            mysqli_stmt_bind_param($saveStmt, 'sssiisii', $name, $description, $category, $price, $stock, $imagePath, $productId, $shopId);
                            mysqli_stmt_execute($saveStmt);
                        } else {
                            $saveStmt = mysqli_prepare($koneksi, "INSERT INTO mp_products (shop_id, name, description, category, price, stock, image_path, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                            mysqli_stmt_bind_param($saveStmt, 'isssiis', $shopId, $name, $description, $category, $price, $stock, $imagePath);
                            mysqli_stmt_execute($saveStmt);
                            $productId = (int)mysqli_insert_id($koneksi);
                        }
                        if ($hasNewImages) {
                            $deleteGalleryStmt = mysqli_prepare($koneksi, 'DELETE FROM mp_product_images WHERE product_id = ?');
                            mysqli_stmt_bind_param($deleteGalleryStmt, 'i', $productId);
                            mysqli_stmt_execute($deleteGalleryStmt);
                            for ($index = 1; $index < 5; $index++) {
                                $galleryPath = $newImages[$index] ?? $existingProductImages[$index];
                                if ($galleryPath === null) {
                                    continue;
                                }
                                $galleryOrder = $index;
                                $galleryInsert = mysqli_prepare($koneksi, 'INSERT INTO mp_product_images (product_id, image_path, sort_order) VALUES (?, ?, ?)');
                                mysqli_stmt_bind_param($galleryInsert, 'isi', $productId, $galleryPath, $galleryOrder);
                                mysqli_stmt_execute($galleryInsert);
                            }
                        }
                        mysqli_commit($koneksi);
                        if ($hasNewImages) {
                            $deleteProductImages($oldImages);
                        }
                        mp_set_flash('success', $productId ? 'Produk dan foto slider berhasil diperbarui; perubahan menunggu peninjauan admin.' : 'Produk ditambahkan dan menunggu persetujuan admin.');
                        mp_redirect('toko_saya.php?menu=daftar-produk');
                    } catch (mysqli_sql_exception $exception) {
                        mysqli_rollback($koneksi);
                        $deleteProductImages($newImages);
                        error_log('Marketplace product save failed: ' . $exception->getMessage());
                        $error = 'Produk gagal disimpan. Silakan coba kembali.';
                    }
                } elseif ($newImages !== []) {
                    $deleteProductImages($newImages);
                }
            }
        } elseif ($action === 'save_shop_logo') {
            $removeLogo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';
            if ($removeLogo) {
                if (!$shop['logo_path']) {
                    $error = 'Toko Anda belum memiliki logo.';
                } else {
                    $logoStmt = mysqli_prepare($koneksi, 'UPDATE mp_shops SET logo_path = NULL WHERE id = ?');
                    mysqli_stmt_bind_param($logoStmt, 'i', $shopId);
                    if (mysqli_stmt_execute($logoStmt)) {
                        $deleteShopLogo($shop['logo_path']);
                        mp_set_flash('success', 'Logo toko berhasil dihapus.');
                        mp_redirect('toko_saya.php?menu=profil');
                    }
                    error_log('Marketplace shop logo removal failed: ' . mysqli_error($koneksi));
                    $error = 'Logo toko gagal dihapus. Silakan coba kembali.';
                }
            } elseif (!isset($_FILES['logo_image']) || $_FILES['logo_image']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Pilih gambar logo toko terlebih dahulu.';
            } elseif ($_FILES['logo_image']['error'] !== UPLOAD_ERR_OK || $_FILES['logo_image']['size'] > 3 * 1024 * 1024) {
                $error = 'Berkas logo gagal diunggah atau ukurannya melebihi 3 MB.';
            } else {
                $imageInfo = @getimagesize($_FILES['logo_image']['tmp_name']);
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($_FILES['logo_image']['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if ($imageInfo === false || !isset($extensions[$mime]) || $imageInfo['mime'] !== $mime) {
                    $error = 'Format logo harus JPG, PNG, atau WebP yang valid.';
                } elseif ($imageInfo[0] < 64 || $imageInfo[1] < 64 || $imageInfo[0] > 3000 || $imageInfo[1] > 3000) {
                    $error = 'Resolusi logo harus minimal 64 × 64 dan maksimal 3000 × 3000 piksel.';
                } elseif (!is_dir($shopLogoDirectory) && !mkdir($shopLogoDirectory, 0755, true) && !is_dir($shopLogoDirectory)) {
                    $error = 'Folder logo toko tidak dapat dibuat. Periksa izin folder aplikasi.';
                } else {
                    $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $destination = $shopLogoDirectory . DIRECTORY_SEPARATOR . $fileName;
                    if (!move_uploaded_file($_FILES['logo_image']['tmp_name'], $destination)) {
                        $error = 'Logo toko tidak dapat disimpan. Periksa izin folder uploads_toko.';
                    } else {
                        $newLogoPath = 'uploads_toko/' . $fileName;
                        try {
                            $logoStmt = mysqli_prepare($koneksi, 'UPDATE mp_shops SET logo_path = ? WHERE id = ?');
                            mysqli_stmt_bind_param($logoStmt, 'si', $newLogoPath, $shopId);
                            mysqli_stmt_execute($logoStmt);
                            if ($shop['logo_path']) {
                                $deleteShopLogo($shop['logo_path']);
                            }
                            mp_set_flash('success', 'Logo toko berhasil diperbarui.');
                            mp_redirect('toko_saya.php?menu=profil');
                        } catch (mysqli_sql_exception $exception) {
                            $deleteShopLogo($newLogoPath);
                            error_log('Marketplace shop logo save failed: ' . $exception->getMessage());
                            $error = 'Logo toko gagal disimpan. Silakan coba kembali.';
                        }
                    }
                }
            }
        } elseif ($action === 'save_shop_profile') {
            $businessName = trim($_POST['business_name'] ?? '');
            $ownerName = trim($_POST['owner_name'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $facebookUrl = trim($_POST['facebook_url'] ?? '');
            $instagramUrl = trim($_POST['instagram_url'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $kecamatan = trim($_POST['kecamatan'] ?? '');
            $kelurahan = trim($_POST['kelurahan'] ?? '');
            $bankName = trim($_POST['bank_name'] ?? '');
            $accountName = trim($_POST['bank_account_name'] ?? '');
            $accountNumber = trim($_POST['bank_account_number'] ?? '');
            $supportedBanks = mp_supported_banks();
            $isSocialUrlValid = static function ($url, $allowedHosts) {
                if ($url === '') {
                    return true;
                }
                if (strlen($url) > 255 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    return false;
                }
                $parts = parse_url($url);
                if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                    return false;
                }
                return in_array(strtolower($parts['host'] ?? ''), $allowedHosts, true);
            };
            if (!$businessName || !$ownerName || !in_array($category, $categories, true) || !$phone || !$address || !$kecamatan || !$kelurahan || !$bankName || !$accountName || !$accountNumber) {
                $error = 'Semua data usaha, lokasi, kontak, dan rekening wajib diisi.';
            } elseif (!$isSocialUrlValid($facebookUrl, ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'fb.me']) || !$isSocialUrlValid($instagramUrl, ['instagram.com', 'www.instagram.com'])) {
                $error = 'Tautan media sosial harus berupa alamat HTTPS Facebook atau Instagram yang valid.';
            } elseif (!isset($supportedBanks[$bankName]) && $bankName !== $shop['bank_name']) {
                $error = 'Pilih salah satu bank yang tersedia.';
            } elseif (!mp_is_valid_flores_timur_location($kecamatan, $kelurahan)) {
                $error = 'Pilih kecamatan dan desa/kelurahan yang terdaftar di Kabupaten Flores Timur.';
            } elseif (mp_length($businessName) > 160 || mp_length($ownerName) > 120 || mp_length($phone) > 30 || mp_length($address) > 1000 || mp_length($kecamatan) > 100 || mp_length($kelurahan) > 120 || mp_length($bankName) > 80 || mp_length($accountName) > 120 || mp_length($accountNumber) > 50) {
                $error = 'Salah satu kolom melebihi batas panjang yang diperbolehkan.';
            } else {
                $facebookValue = $facebookUrl !== '' ? $facebookUrl : null;
                $instagramValue = $instagramUrl !== '' ? $instagramUrl : null;
                $profileStmt = mysqli_prepare($koneksi, "UPDATE mp_shops SET business_name = ?, owner_name = ?, category = ?, phone = ?, facebook_url = ?, instagram_url = ?, address = ?, kecamatan = ?, kelurahan = ?, bank_name = ?, bank_account_name = ?, bank_account_number = ?, status = 'pending' WHERE id = ?");
                mysqli_stmt_bind_param($profileStmt, 'ssssssssssssi', $businessName, $ownerName, $category, $phone, $facebookValue, $instagramValue, $address, $kecamatan, $kelurahan, $bankName, $accountName, $accountNumber, $shopId);
                if (mysqli_stmt_execute($profileStmt)) {
                    mp_set_flash('success', 'Profil toko diperbarui dan dikirim kembali untuk verifikasi admin. Pesanan lama tetap menggunakan rekening saat pesanan dibuat.');
                    mp_redirect('toko_saya.php?menu=profil');
                }
                error_log('Marketplace shop profile update failed: ' . mysqli_error($koneksi));
                $error = 'Profil toko gagal diperbarui. Silakan coba kembali.';
            }
        } elseif ($action === 'change_password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            if (!password_verify($currentPassword, $shop['password_hash'])) {
                $error = 'Kata sandi saat ini tidak sesuai.';
            } elseif (strlen($newPassword) < 10 || strlen($newPassword) > 72) {
                $error = 'Kata sandi baru harus antara 10 dan 72 byte.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Konfirmasi kata sandi baru tidak sama.';
            } else {
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $passwordStmt = mysqli_prepare($koneksi, 'UPDATE mp_shops SET password_hash = ? WHERE id = ?');
                mysqli_stmt_bind_param($passwordStmt, 'si', $passwordHash, $shopId);
                if (mysqli_stmt_execute($passwordStmt)) {
                    mp_set_flash('success', 'Kata sandi berhasil diubah.');
                    mp_redirect('toko_saya.php?menu=keamanan');
                }
                error_log('Marketplace password update failed: ' . mysqli_error($koneksi));
                $error = 'Kata sandi gagal diperbarui. Silakan coba kembali.';
            }
        } elseif ($action === 'delete_product') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId) {
                $error = 'Produk tidak valid.';
            } else {
                $imageStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_products WHERE id = ? AND shop_id = ? LIMIT 1');
                mysqli_stmt_bind_param($imageStmt, 'ii', $productId, $shopId);
                mysqli_stmt_execute($imageStmt);
                $productToDelete = mysqli_fetch_assoc(mysqli_stmt_get_result($imageStmt));
                if (!$productToDelete) {
                    $error = 'Produk tidak ditemukan pada toko Anda.';
                } else {
                    $imagesToDelete = $productToDelete['image_path'] ? [$productToDelete['image_path']] : [];
                    $galleryStmt = mysqli_prepare($koneksi, 'SELECT image_path FROM mp_product_images WHERE product_id = ?');
                    mysqli_stmt_bind_param($galleryStmt, 'i', $productId);
                    mysqli_stmt_execute($galleryStmt);
                    $galleryResult = mysqli_stmt_get_result($galleryStmt);
                    while ($galleryImage = mysqli_fetch_assoc($galleryResult)) {
                        $imagesToDelete[] = $galleryImage['image_path'];
                    }
                    $deleteStmt = mysqli_prepare($koneksi, 'DELETE FROM mp_products WHERE id = ? AND shop_id = ?');
                    mysqli_stmt_bind_param($deleteStmt, 'ii', $productId, $shopId);
                    mysqli_stmt_execute($deleteStmt);
                    $deleteProductImages($imagesToDelete);
                    mp_set_flash('success', 'Produk beserta foto-foto slider dihapus dari toko.');
                    mp_redirect('toko_saya.php?menu=daftar-produk');
                }
            }
        } elseif ($action === 'order') {
            $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
            $orderAction = $_POST['order_action'] ?? '';
            if (!$orderId) {
                $error = 'Pesanan tidak valid.';
            } else {
                try {
                    mysqli_begin_transaction($koneksi);
                    $orderStmt = mysqli_prepare($koneksi, 'SELECT id, order_status, payment_method FROM mp_orders WHERE id = ? AND shop_id = ? FOR UPDATE');
                    mysqli_stmt_bind_param($orderStmt, 'ii', $orderId, $shopId);
                    mysqli_stmt_execute($orderStmt);
                    $order = mysqli_fetch_assoc(mysqli_stmt_get_result($orderStmt));
                    if (!$order) {
                        throw new RuntimeException('Pesanan tidak ditemukan pada toko Anda.');
                    }
                    if ($orderAction === 'confirm' && $order['order_status'] === 'menunggu_pembayaran' && $order['payment_method'] === 'transfer') {
                        $updateStmt = mysqli_prepare($koneksi, "UPDATE mp_orders SET payment_status = 'paid', order_status = 'diproses' WHERE id = ? AND shop_id = ?");
                        mysqli_stmt_bind_param($updateStmt, 'ii', $orderId, $shopId);
                    } elseif ($orderAction === 'accept' && $order['order_status'] === 'pesanan_baru' && $order['payment_method'] === 'cod') {
                        $updateStmt = mysqli_prepare($koneksi, "UPDATE mp_orders SET order_status = 'diproses' WHERE id = ? AND shop_id = ?");
                        mysqli_stmt_bind_param($updateStmt, 'ii', $orderId, $shopId);
                    } elseif ($orderAction === 'ship' && $order['order_status'] === 'diproses') {
                        $updateStmt = mysqli_prepare($koneksi, "UPDATE mp_orders SET order_status = 'dikirim' WHERE id = ? AND shop_id = ?");
                        mysqli_stmt_bind_param($updateStmt, 'ii', $orderId, $shopId);
                    } elseif ($orderAction === 'complete' && $order['order_status'] === 'dikirim') {
                        $updateStmt = mysqli_prepare($koneksi, "UPDATE mp_orders SET order_status = 'selesai', payment_status = 'paid' WHERE id = ? AND shop_id = ?");
                        mysqli_stmt_bind_param($updateStmt, 'ii', $orderId, $shopId);
                    } elseif ($orderAction === 'cancel' && in_array($order['order_status'], ['pesanan_baru', 'menunggu_pembayaran'], true)) {
                        $itemsStmt = mysqli_prepare($koneksi, 'SELECT product_id, quantity FROM mp_order_items WHERE order_id = ?');
                        mysqli_stmt_bind_param($itemsStmt, 'i', $orderId);
                        mysqli_stmt_execute($itemsStmt);
                        $itemsResult = mysqli_stmt_get_result($itemsStmt);
                        while ($item = mysqli_fetch_assoc($itemsResult)) {
                            if ($item['product_id'] !== null) {
                                $stockStmt = mysqli_prepare($koneksi, 'UPDATE mp_products SET stock = stock + ? WHERE id = ?');
                                mysqli_stmt_bind_param($stockStmt, 'ii', $item['quantity'], $item['product_id']);
                                mysqli_stmt_execute($stockStmt);
                            }
                        }
                        $updateStmt = mysqli_prepare($koneksi, "UPDATE mp_orders SET order_status = 'dibatalkan' WHERE id = ? AND shop_id = ?");
                        mysqli_stmt_bind_param($updateStmt, 'ii', $orderId, $shopId);
                    } else {
                        throw new RuntimeException('Aksi tersebut tidak sesuai dengan status pesanan saat ini.');
                    }
                    mysqli_stmt_execute($updateStmt);
                    mysqli_commit($koneksi);
                    mp_set_flash('success', 'Status pesanan berhasil diperbarui.');
                    mp_redirect('toko_saya.php?menu=pesanan');
                } catch (RuntimeException $exception) {
                    mysqli_rollback($koneksi);
                    $error = $exception->getMessage();
                } catch (mysqli_sql_exception $exception) {
                    mysqli_rollback($koneksi);
                    error_log('Marketplace order update failed: ' . $exception->getMessage());
                    $error = 'Status pesanan gagal diperbarui. Silakan coba kembali.';
                }
            }
        }
    }
}

$productsStmt = mysqli_prepare($koneksi, 'SELECT id, name, category, price, stock, image_path, status FROM mp_products WHERE shop_id = ? ORDER BY created_at DESC');
mysqli_stmt_bind_param($productsStmt, 'i', $shopId);
mysqli_stmt_execute($productsStmt);
$productsResult = mysqli_stmt_get_result($productsStmt);

$ordersStmt = mysqli_prepare($koneksi, 'SELECT * FROM mp_orders WHERE shop_id = ? ORDER BY created_at DESC LIMIT 100');
mysqli_stmt_bind_param($ordersStmt, 'i', $shopId);
mysqli_stmt_execute($ordersStmt);
$ordersResult = mysqli_stmt_get_result($ordersStmt);

$editingProduct = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId) {
        $editStmt = mysqli_prepare($koneksi, 'SELECT id, name, description, category, price, stock, image_path FROM mp_products WHERE id = ? AND shop_id = ? LIMIT 1');
        mysqli_stmt_bind_param($editStmt, 'ii', $editId, $shopId);
        mysqli_stmt_execute($editStmt);
        $editingProduct = mysqli_fetch_assoc(mysqli_stmt_get_result($editStmt));
    }
}
$editingProductImages = [];
if ($editingProduct) {
    if ($editingProduct['image_path']) {
        $editingProductImages[0] = $editingProduct['image_path'];
    }
    $editImagesStmt = mysqli_prepare($koneksi, 'SELECT image_path, sort_order FROM mp_product_images WHERE product_id = ? ORDER BY sort_order, id');
    mysqli_stmt_bind_param($editImagesStmt, 'i', $editingProduct['id']);
    mysqli_stmt_execute($editImagesStmt);
    $editImagesResult = mysqli_stmt_get_result($editImagesStmt);
    while ($editImage = mysqli_fetch_assoc($editImagesResult)) {
        $editingProductImages[(int)$editImage['sort_order']] = $editImage['image_path'];
    }
}

$statusLabels = ['pending' => 'Menunggu verifikasi', 'approved' => 'Disetujui', 'rejected' => 'Perlu perbaikan', 'menunggu_pembayaran' => 'Menunggu pembayaran', 'pesanan_baru' => 'Pesanan baru', 'diproses' => 'Diproses', 'dikirim' => 'Dikirim', 'selesai' => 'Selesai', 'dibatalkan' => 'Dibatalkan'];
mp_render_header('Kelola Toko', true);
mp_render_flash();
?>
<style>
    .seller-layout { display: grid; grid-template-columns: 240px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
    .seller-sidebar { position: sticky; top: 1rem; padding: 1rem; border: 1px solid #e0e9e2; border-radius: 1rem; background: #fff; box-shadow: 0 8px 24px rgba(17,53,43,.06); }
    .seller-sidebar-title { padding: .4rem .65rem .8rem; color: #71857b; font-size: .72rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
    .seller-sidebar .nav-link { display: flex; align-items: center; gap: .7rem; margin-bottom: .25rem; padding: .7rem .75rem; border-radius: .65rem; color: #456258; font-size: .88rem; font-weight: 600; text-decoration: none; }
    .seller-sidebar .nav-link:hover, .seller-sidebar .nav-link.active { color: #126248; background: #e9f5ee; }
    .seller-sidebar .nav-link i { width: 1.15rem; text-align: center; }
    @media (max-width: 767.98px) { .seller-layout { grid-template-columns: 1fr; gap: 1rem; } .seller-sidebar { position: static; } .seller-sidebar .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; } .seller-sidebar .nav-link { white-space: nowrap; } }
</style>
<div class="seller-layout">
    <aside class="seller-sidebar" aria-label="Menu toko">
        <div class="seller-sidebar-title">Menu toko</div>
        <nav class="nav flex-column">
            <a class="nav-link <?= $activeMenu === 'ringkasan' ? 'active' : '' ?>" href="toko_saya.php?menu=ringkasan"><i class="bi bi-speedometer2"></i>Ringkasan toko</a>
            <a class="nav-link <?= $activeMenu === 'profil' ? 'active' : '' ?>" href="toko_saya.php?menu=profil"><i class="bi bi-shop"></i>Profil dan rekening</a>
            <a class="nav-link <?= $activeMenu === 'keamanan' ? 'active' : '' ?>" href="toko_saya.php?menu=keamanan"><i class="bi bi-shield-lock"></i>Keamanan akun</a>
            <a class="nav-link <?= $activeMenu === 'produk' ? 'active' : '' ?>" href="toko_saya.php?menu=produk"><i class="bi bi-plus-square"></i>Tambah produk</a>
            <a class="nav-link <?= $activeMenu === 'daftar-produk' ? 'active' : '' ?>" href="toko_saya.php?menu=daftar-produk"><i class="bi bi-box-seam"></i>Produk toko</a>
            <a class="nav-link <?= $activeMenu === 'pesanan' ? 'active' : '' ?>" href="toko_saya.php?menu=pesanan"><i class="bi bi-receipt"></i>Pesanan masuk</a>
            <a class="nav-link" href="komunikasi.php"><i class="bi bi-chat-dots"></i>Pesan pembeli dan admin</a>
            <a class="nav-link" href="laporan_penjualan.php"><i class="bi bi-bar-chart"></i>Laporan penjualan</a>
            <a class="nav-link text-danger" href="keluar_umkm.php"><i class="bi bi-box-arrow-right"></i>Keluar toko</a>
        </nav>
    </aside>
    <div class="seller-main">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 fw-bold mb-1"><?= mp_escape($sellerMenus[$activeMenu]) ?></h1><p class="small-muted mb-0">Toko <?= mp_escape($shop['business_name']) ?> · <?= mp_escape($shop['category']) ?></p></div>
    <span class="badge text-bg-<?= $shop['status'] === 'approved' ? 'success' : ($shop['status'] === 'rejected' ? 'danger' : 'warning') ?> fs-6"><?= mp_escape($statusLabels[$shop['status']]) ?></span>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
<?php if ($shop['status'] !== 'approved' && $activeMenu === 'ringkasan'): ?>
    <div class="alert alert-info"><strong>Verifikasi toko:</strong> <?= $shop['status'] === 'pending' ? 'Pendaftaran Anda sedang ditinjau admin Pemerintah Kabupaten Flores Timur. Produk dan pesanan akan tersedia setelah toko disetujui.' : 'Pendaftaran belum disetujui. Hubungi admin Pemerintah Kabupaten Flores Timur untuk informasi lebih lanjut.' ?></div>
<?php endif; ?>

<?php if ($activeMenu === 'ringkasan'): ?>
    <?php
    $summaryStmt = mysqli_prepare($koneksi, "SELECT
        (SELECT COUNT(*) FROM mp_products WHERE shop_id = ?) AS product_count,
        (SELECT COUNT(*) FROM mp_orders WHERE shop_id = ? AND order_status NOT IN ('selesai', 'dibatalkan')) AS open_orders,
        (SELECT COALESCE(SUM(total_amount), 0) FROM mp_orders WHERE shop_id = ? AND order_status = 'selesai') AS completed_sales");
    mysqli_stmt_bind_param($summaryStmt, 'iii', $shopId, $shopId, $shopId);
    mysqli_stmt_execute($summaryStmt);
    $shopSummary = mysqli_fetch_assoc(mysqli_stmt_get_result($summaryStmt));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4"><div class="card p-3 h-100"><div class="small-muted">Produk terdaftar</div><div class="h4 fw-bold mb-0"><?= (int)$shopSummary['product_count'] ?></div></div></div>
        <div class="col-12 col-md-4"><div class="card p-3 h-100"><div class="small-muted">Pesanan aktif</div><div class="h4 fw-bold mb-0"><?= (int)$shopSummary['open_orders'] ?></div></div></div>
        <div class="col-12 col-md-4"><div class="card p-3 h-100"><div class="small-muted">Penjualan selesai</div><div class="h4 fw-bold text-market mb-0"><?= mp_format_rupiah($shopSummary['completed_sales']) ?></div></div></div>
    </div>
    <section class="card p-4">
        <h2 class="h5 fw-bold mb-2">Kelola toko Anda</h2>
        <p class="small-muted mb-3">Pilih menu di samping untuk mengatur profil, produk, pesanan, atau melihat laporan penjualan.</p>
        <a class="btn btn-outline-success align-self-start" href="laporan_penjualan.php"><i class="bi bi-bar-chart me-2"></i>Lihat laporan penjualan</a>
    </section>
<?php elseif ($activeMenu === 'profil'): ?>
<section class="card p-4 mb-4" id="profil">
    <h2 class="h5 fw-bold mb-3">Profil dan rekening toko</h2>
    <div class="row g-3 align-items-center mb-4">
        <div class="col-auto">
            <?php if ($shop['logo_path']): ?>
                <img src="<?= mp_escape($shop['logo_path']) ?>" alt="Logo <?= mp_escape($shop['business_name']) ?>" style="width:96px;height:96px;object-fit:contain;border:1px solid #e0e9e2;border-radius:.8rem;background:#f8faf9">
            <?php else: ?>
                <div class="d-grid text-market" style="width:96px;height:96px;place-items:center;border:1px dashed #cddbd1;border-radius:.8rem;background:#f8faf9"><i class="bi bi-shop fs-2"></i></div>
            <?php endif; ?>
        </div>
        <div class="col">
            <h3 class="h6 fw-bold mb-1">Logo toko</h3>
            <p class="small-muted small mb-2">JPG, PNG, atau WebP · maksimal 3 MB · resolusi 64 × 64 hingga 3000 × 3000 piksel.</p>
            <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap align-items-center gap-2">
                <?php mp_render_csrf(); ?><input type="hidden" name="action" value="save_shop_logo">
                <label class="visually-hidden" for="shop-logo">Pilih logo toko</label>
                <input class="form-control form-control-sm" id="shop-logo" type="file" name="logo_image" accept="image/jpeg,image/png,image/webp" required style="max-width:320px">
                <button class="btn btn-sm btn-outline-success" type="submit"><?= $shop['logo_path'] ? 'Ganti logo' : 'Unggah logo' ?></button>
            </form>
            <?php if ($shop['logo_path']): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Hapus logo toko?')">
                    <?php mp_render_csrf(); ?><input type="hidden" name="action" value="save_shop_logo"><input type="hidden" name="remove_logo" value="1">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Hapus logo</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <hr>
    <form method="post" class="row g-3">
        <?php mp_render_csrf(); ?><input type="hidden" name="action" value="save_shop_profile">
        <div class="col-md-6"><label class="form-label">Nama usaha/toko</label><input class="form-control" name="business_name" maxlength="160" value="<?= mp_escape($shop['business_name']) ?>" required></div>
        <div class="col-md-6"><label class="form-label">Nama pemilik</label><input class="form-control" name="owner_name" maxlength="120" value="<?= mp_escape($shop['owner_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Kategori usaha</label><select class="form-select" name="category" required><?php foreach ($categories as $option): ?><option value="<?= mp_escape($option) ?>" <?= $shop['category'] === $option ? 'selected' : '' ?>><?= mp_escape($option) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Nomor WhatsApp/HP</label><input class="form-control" name="phone" maxlength="30" value="<?= mp_escape($shop['phone']) ?>" required></div>
        <?php
        $profileFacebookUrl = ($_POST['action'] ?? '') === 'save_shop_profile' ? ($_POST['facebook_url'] ?? '') : (($shop['facebook_url'] ?? '') ?: 'https://facebook.com');
        $profileInstagramUrl = ($_POST['action'] ?? '') === 'save_shop_profile' ? ($_POST['instagram_url'] ?? '') : (($shop['instagram_url'] ?? '') ?: 'https://instagram.com');
        ?>
        <div class="col-md-6"><label class="form-label" for="shop-facebook">Facebook toko</label><input class="form-control" id="shop-facebook" type="url" name="facebook_url" maxlength="255" value="<?= mp_escape($profileFacebookUrl) ?>" placeholder="https://facebook.com"><div class="form-text">Ganti dengan tautan halaman Facebook toko agar pelanggan menuju profil yang benar.</div></div>
        <div class="col-md-6"><label class="form-label" for="shop-instagram">Instagram toko</label><input class="form-control" id="shop-instagram" type="url" name="instagram_url" maxlength="255" value="<?= mp_escape($profileInstagramUrl) ?>" placeholder="https://instagram.com"><div class="form-text">Ganti dengan tautan profil Instagram toko agar pelanggan menuju profil yang benar.</div></div>
        <div class="col-12"><label class="form-label">Alamat usaha</label><input class="form-control" name="address" maxlength="1000" value="<?= mp_escape($shop['address']) ?>" required></div>
        <?php
        $profileDistrict = ($_POST['action'] ?? '') === 'save_shop_profile' ? ($_POST['kecamatan'] ?? '') : $shop['kecamatan'];
        $profileVillage = ($_POST['action'] ?? '') === 'save_shop_profile' ? ($_POST['kelurahan'] ?? '') : $shop['kelurahan'];
        mp_render_flores_timur_location_fields($profileDistrict, $profileVillage);
        ?>
        <div class="col-md-4"><label class="form-label" for="bank-name">Nama bank</label><?php mp_render_bank_selector($shop['bank_name'], $shop['bank_name']); ?></div>
        <div class="col-md-2"><label class="form-label">Nama rekening</label><input class="form-control" name="bank_account_name" maxlength="120" value="<?= mp_escape($shop['bank_account_name']) ?>" required></div>
        <div class="col-md-2"><label class="form-label">Nomor rekening</label><input class="form-control" name="bank_account_number" maxlength="50" value="<?= mp_escape($shop['bank_account_number']) ?>" required></div>
        <div class="col-12"><button class="btn btn-outline-success" type="submit">Simpan profil toko</button></div>
    </form>
</section>
<?php elseif ($activeMenu === 'keamanan'): ?>
<section class="card p-4 mb-4" id="keamanan">
    <h2 class="h5 fw-bold mb-3">Keamanan akun</h2>
    <form method="post" class="row g-3">
        <?php mp_render_csrf(); ?><input type="hidden" name="action" value="change_password">
        <div class="col-md-4"><label class="form-label">Kata sandi saat ini</label><input class="form-control" type="password" name="current_password" autocomplete="current-password" required></div>
        <div class="col-md-4"><label class="form-label">Kata sandi baru</label><input class="form-control" type="password" name="new_password" minlength="10" autocomplete="new-password" required></div>
        <div class="col-md-4"><label class="form-label">Ulangi kata sandi baru</label><input class="form-control" type="password" name="confirm_password" minlength="10" autocomplete="new-password" required></div>
        <div class="col-12"><button class="btn btn-outline-secondary" type="submit">Ubah kata sandi</button></div>
    </form>
</section>
<?php elseif ($activeMenu === 'produk'): ?>
<div class="row g-4">
    <div class="col-12">
        <section class="card p-4" id="produk">
            <h2 class="h5 fw-bold mb-3"><?= $editingProduct ? 'Ubah produk' : 'Tambah produk' ?></h2>
            <?php if ($shop['status'] === 'approved'): ?>
            <form method="post" enctype="multipart/form-data">
                <?php mp_render_csrf(); ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="product_id" value="<?= (int)($editingProduct['id'] ?? 0) ?>">
                <div class="mb-3"><label class="form-label">Nama produk</label><input class="form-control" name="name" maxlength="160" value="<?= mp_escape($editingProduct['name'] ?? '') ?>" required></div>
                <div class="mb-3"><label class="form-label">Deskripsi produk</label><textarea class="form-control" name="description" rows="3" maxlength="3000" required><?= mp_escape($editingProduct['description'] ?? '') ?></textarea></div>
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Kategori</label><select class="form-select" name="category" required><option value="">Pilih kategori</option><?php foreach ($categories as $option): ?><option value="<?= mp_escape($option) ?>" <?= ($editingProduct['category'] ?? '') === $option ? 'selected' : '' ?>><?= mp_escape($option) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label">Harga (Rp)</label><input class="form-control" type="number" name="price" min="1" step="1" value="<?= mp_escape($editingProduct['price'] ?? '') ?>" required></div>
                    <div class="col-6"><label class="form-label">Stok</label><input class="form-control" type="number" name="stock" min="0" step="1" value="<?= mp_escape($editingProduct['stock'] ?? '0') ?>" required></div>
                    <div class="col-12">
                        <span class="form-label d-block">Foto produk (1 foto sampul + hingga 4 foto detail · JPG, PNG, WebP · maks. 3 MB per foto)</span>
                        <div class="row g-3">
                            <?php for ($imageSlot = 0; $imageSlot < 5; $imageSlot++): ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-label small fw-semibold" for="product-image-<?= $imageSlot ?>"><?= $imageSlot === 0 ? 'Foto sampul' : 'Foto detail ' . $imageSlot ?></label>
                                    <input class="form-control" id="product-image-<?= $imageSlot ?>" type="file" name="images[]" accept="image/jpeg,image/png,image/webp" <?= !$editingProduct && $imageSlot === 0 ? 'required' : '' ?> data-product-images>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <div class="form-text mt-2"><?= $editingProductImages ? 'Pilih file pada slot yang ingin diganti. Foto di slot kosong akan tetap digunakan; foto sampul lama tetap dipakai jika slot sampul dibiarkan kosong.' : 'Foto sampul wajib dipilih. Empat foto detail bersifat opsional dan dipilih melalui slot masing-masing.' ?></div>
                        <?php if ($editingProductImages): ?>
                            <div class="d-flex flex-wrap gap-2 mt-3" aria-label="Foto produk saat ini">
                                <?php foreach ($editingProductImages as $imageIndex => $productImage): ?>
                                    <div class="text-center"><img src="<?= mp_escape($productImage) ?>" alt="<?= $imageIndex === 0 ? 'Foto sampul produk' : 'Foto detail produk ' . $imageIndex ?>" style="width:96px;height:72px;object-fit:cover;border:1px solid #dce6df;border-radius:.5rem"><div class="small-muted small"><?= $imageIndex === 0 ? 'Sampul' : 'Detail ' . $imageIndex ?></div></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button class="btn btn-market mt-3" type="submit"><?= $editingProduct ? 'Simpan perubahan' : 'Tambah produk' ?></button>
                <?php if ($editingProduct): ?><a class="btn btn-outline-secondary mt-3" href="toko_saya.php?menu=produk">Batal</a><?php endif; ?>
            </form>
            <?php else: ?><p class="small-muted mb-0">Form produk aktif setelah toko disetujui oleh admin.</p><?php endif; ?>
        </section>
    </div>
</div>
<?php if ($activeMenu === 'produk'): ?>
<script>
    document.querySelectorAll('[data-product-images]').forEach(function (input) {
        input.addEventListener('change', function () {
            input.setCustomValidity(input.files[0] && input.files[0].size > 3 * 1024 * 1024 ? 'Ukuran foto maksimal 3 MB.' : '');
        });
    });
</script>
<?php endif; ?>
<?php elseif ($activeMenu === 'daftar-produk'): ?>
<section class="card p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 fw-bold mb-0">Produk toko</h2>
        <a class="btn btn-market" href="toko_saya.php?menu=produk"><i class="bi bi-plus-lg me-1"></i>Tambah produk</a>
    </div>
    <div class="table-responsive"><table class="table align-middle table-sm"><thead><tr><th>Produk</th><th>Harga</th><th>Stok</th><th>Status</th><th></th></tr></thead><tbody>
    <?php if (mysqli_num_rows($productsResult) === 0): ?><tr><td colspan="5" class="text-center small-muted py-4">Belum ada produk.</td></tr><?php else: while ($product = mysqli_fetch_assoc($productsResult)): ?>
        <tr><td><?= mp_escape($product['name']) ?><div class="small-muted small"><?= mp_escape($product['category']) ?></div></td><td><?= mp_format_rupiah($product['price']) ?></td><td><?= (int)$product['stock'] ?></td><td><span class="badge text-bg-<?= $product['status'] === 'approved' ? 'success' : ($product['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= mp_escape($statusLabels[$product['status']]) ?></span></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="toko_saya.php?menu=produk&amp;edit=<?= (int)$product['id'] ?>">Ubah</a><form method="post" class="d-inline" onsubmit="return confirm('Hapus produk ini?')"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="delete_product"><button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button><input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>"></form></td></tr>
    <?php endwhile; endif; ?>
    </tbody></table></div>
</section>
<?php elseif ($activeMenu === 'pesanan'): ?>
<section class="card p-4" id="pesanan">
    <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 fw-bold mb-0">Pesanan masuk</h2><span class="small-muted small">Maksimal 100 pesanan terbaru</span></div>
    <?php if (mysqli_num_rows($ordersResult) === 0): ?><p class="small-muted mb-0">Belum ada pesanan untuk toko Anda.</p><?php else: ?>
        <div class="accordion" id="orderAccordion">
        <?php $orderIndex = 0; while ($order = mysqli_fetch_assoc($ordersResult)): $orderIndex++; $collapseId = 'order-' . (int)$order['id']; ?>
            <article class="accordion-item">
                <h3 class="accordion-header"><button class="accordion-button <?= $orderIndex > 1 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= mp_escape($collapseId) ?>"><span class="fw-bold me-3"><?= mp_escape($order['order_code']) ?></span><span class="small"><?= mp_escape($order['customer_name']) ?> · <?= mp_escape($statusLabels[$order['order_status']]) ?> · <?= mp_format_rupiah($order['total_amount']) ?></span></button></h3>
                <div id="<?= mp_escape($collapseId) ?>" class="accordion-collapse collapse <?= $orderIndex === 1 ? 'show' : '' ?>" data-bs-parent="#orderAccordion"><div class="accordion-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><strong>Penerima:</strong> <?= mp_escape($order['customer_name']) ?> · <a href="https://wa.me/<?= mp_escape(preg_replace('/\D+/', '', $order['customer_phone'])) ?>" target="_blank" rel="noopener"><?= mp_escape($order['customer_phone']) ?></a><br><strong>Alamat:</strong> <?= mp_escape($order['shipping_address']) ?>, <?= mp_escape($order['kecamatan']) ?></div>
                        <div class="col-md-6"><strong>Pembayaran:</strong> <?= $order['payment_method'] === 'transfer' ? 'Transfer langsung · ' . ($order['payment_status'] === 'paid' ? 'terkonfirmasi' : 'belum dikonfirmasi') : 'COD · ' . ($order['payment_status'] === 'paid' ? 'dibayar' : 'belum dibayar') ?><br><strong>Catatan:</strong> <?= mp_escape($order['customer_note'] ?: '-') ?></div>
                    </div>
                    <?php $itemsStmt = mysqli_prepare($koneksi, 'SELECT product_name, unit_price, quantity, subtotal FROM mp_order_items WHERE order_id = ?'); mysqli_stmt_bind_param($itemsStmt, 'i', $order['id']); mysqli_stmt_execute($itemsStmt); $itemsResult = mysqli_stmt_get_result($itemsStmt); ?>
                    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Produk</th><th>Harga</th><th>Jumlah</th><th>Subtotal</th></tr></thead><tbody><?php while ($item = mysqli_fetch_assoc($itemsResult)): ?><tr><td><?= mp_escape($item['product_name']) ?></td><td><?= mp_format_rupiah($item['unit_price']) ?></td><td><?= (int)$item['quantity'] ?></td><td><?= mp_format_rupiah($item['subtotal']) ?></td></tr><?php endwhile; ?></tbody></table></div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($order['order_status'] === 'menunggu_pembayaran'): ?><form method="post"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="order"><input type="hidden" name="order_action" value="confirm"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-success btn-sm" type="submit">Konfirmasi transfer diterima</button></form><?php endif; ?>
                        <?php if ($order['order_status'] === 'pesanan_baru'): ?><form method="post"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="order"><input type="hidden" name="order_action" value="accept"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-success btn-sm" type="submit">Terima pesanan COD</button></form><?php endif; ?>
                        <?php if ($order['order_status'] === 'diproses'): ?><form method="post"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="order"><input type="hidden" name="order_action" value="ship"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-primary btn-sm" type="submit">Tandai dikirim</button></form><?php endif; ?>
                        <?php if ($order['order_status'] === 'dikirim'): ?><form method="post" onsubmit="return confirm('Tandai barang diterima dan pesanan selesai? Untuk COD, pembayaran akan dicatat diterima.')"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="order"><input type="hidden" name="order_action" value="complete"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-success btn-sm" type="submit">Tandai selesai</button></form><?php endif; ?>
                        <?php if (in_array($order['order_status'], ['pesanan_baru', 'menunggu_pembayaran'], true)): ?><form method="post" onsubmit="return confirm('Batalkan pesanan ini dan kembalikan stok produk?')"><?php mp_render_csrf(); ?><input type="hidden" name="action" value="order"><input type="hidden" name="order_action" value="cancel"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Batalkan pesanan</button></form><?php endif; ?>
                    </div>
                </div></div>
            </article>
        <?php endwhile; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
    </div>
</div>
<script>
    const legacySellerMenus = {
        '#ringkasan': 'ringkasan',
        '#profil': 'profil',
        '#keamanan': 'keamanan',
        '#produk': 'produk',
        '#daftar-produk': 'daftar-produk',
        '#pesanan': 'pesanan'
    };
    const legacyMenu = legacySellerMenus[window.location.hash];
    if (legacyMenu) {
        const target = new URL(window.location.href);
        target.hash = '';
        target.searchParams.set('menu', legacyMenu);
        window.location.replace(target);
    }
</script>
<?php mp_render_footer(); ?>
