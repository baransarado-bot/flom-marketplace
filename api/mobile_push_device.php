<?php
require_once __DIR__ . '/../marketplace_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Metode permintaan tidak diizinkan.']);
    exit;
}

$adminLogged = !empty($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$adminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
$shopId = !empty($_SESSION['mp_seller_id']) ? (int)$_SESSION['mp_seller_id'] : 0;
if (($adminLogged && $adminId < 1) || (!$adminLogged && $shopId < 1)) {
    http_response_code(401);
    echo json_encode(['error' => 'Masuk sebagai admin atau pemilik toko untuk mendaftarkan notifikasi.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrfToken) || empty($_SESSION['mp_csrf_token']) || !hash_equals($_SESSION['mp_csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesi keamanan tidak valid. Muat ulang halaman.']);
    exit;
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    http_response_code(415);
    echo json_encode(['error' => 'Format permintaan harus JSON.']);
    exit;
}

try {
    $requestBody = file_get_contents('php://input');
    if ($requestBody === false || strlen($requestBody) > 8192) {
        http_response_code(413);
        echo json_encode(['error' => 'Ukuran permintaan terlalu besar.']);
        exit;
    }
    $payload = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'Isi permintaan tidak valid.']);
    exit;
}
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Isi permintaan harus berupa objek JSON.']);
    exit;
}

$action = $payload['action'] ?? '';
if ($action === 'register') {
    $token = $payload['token'] ?? '';
    $platform = $payload['platform'] ?? '';
    if (!is_string($token) || strlen($token) < 20 || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7F]/', $token) || !in_array($platform, ['android', 'ios'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token perangkat atau platform tidak valid.']);
        exit;
    }

    $userType = $adminLogged ? 'admin' : 'seller';
    $userId = $adminLogged ? $adminId : $shopId;
    $tokenHash = hash('sha256', $token, true);
    $stmt = mysqli_prepare($koneksi, 'INSERT INTO mp_mobile_push_devices (user_type, user_id, platform, device_token, device_token_hash) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_type = VALUES(user_type), user_id = VALUES(user_id), platform = VALUES(platform), device_token = VALUES(device_token), updated_at = CURRENT_TIMESTAMP');
    mysqli_stmt_bind_param($stmt, 'sisss', $userType, $userId, $platform, $token, $tokenHash);
    mysqli_stmt_execute($stmt);
    $previousToken = $_SESSION['mp_mobile_push_token'] ?? '';
    if (is_string($previousToken) && $previousToken !== '' && !hash_equals($previousToken, $token)) {
        require_once __DIR__ . '/../mobile_push.php';
        mp_mobile_push_remove_device($previousToken);
    }
    $_SESSION['mp_mobile_push_token'] = $token;
    echo json_encode(['registered' => true]);
    exit;
}

if ($action === 'unregister') {
    $token = $_SESSION['mp_mobile_push_token'] ?? '';
    if (is_string($token) && $token !== '') {
        require_once __DIR__ . '/../mobile_push.php';
        mp_mobile_push_remove_device($token);
        unset($_SESSION['mp_mobile_push_token']);
    }
    echo json_encode(['registered' => false]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Aksi notifikasi tidak dikenal.']);
