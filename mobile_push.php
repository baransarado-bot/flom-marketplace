<?php

function mp_mobile_push_queue($userType, $userId, $title, $body, $targetUrl)
{
    global $koneksi;

    if (!in_array($userType, ['admin', 'seller'], true) || (int)$userId < 1) {
        throw new InvalidArgumentException('Penerima notifikasi FLOM tidak valid.');
    }

    $stmt = mysqli_prepare($koneksi, 'INSERT INTO mp_mobile_push_queue (user_type, user_id, title, body, target_url) VALUES (?, ?, ?, ?, ?)');
    $userId = (int)$userId;
    mysqli_stmt_bind_param($stmt, 'sisss', $userType, $userId, $title, $body, $targetUrl);
    mysqli_stmt_execute($stmt);
}

function mp_mobile_push_remove_device($token)
{
    global $koneksi;

    if (!is_string($token) || $token === '') {
        return;
    }
    $tokenHash = hash('sha256', $token, true);
    $stmt = mysqli_prepare($koneksi, 'DELETE FROM mp_mobile_push_devices WHERE device_token_hash = ?');
    mysqli_stmt_bind_param($stmt, 's', $tokenHash);
    mysqli_stmt_execute($stmt);
}

function mp_mobile_push_http_post($url, array $headers, $body)
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
            'max_redirects' => 0,
        ],
    ]);
    $responseBody = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $matches)) {
            $status = (int)$matches[1];
        }
    }
    if ($responseBody === false || $status === 0) {
        throw new RuntimeException('Layanan Firebase tidak dapat dihubungi.');
    }
    return [$status, $responseBody];
}

function mp_mobile_push_service_account()
{
    static $credentials = null;
    if ($credentials !== null) {
        return $credentials;
    }

    $configuredPath = getenv('FLOM_FCM_SERVICE_ACCOUNT_FILE');
    if (!is_string($configuredPath) || trim($configuredPath) === '') {
        throw new RuntimeException('FLOM_FCM_SERVICE_ACCOUNT_FILE belum diatur.');
    }
    $path = realpath($configuredPath);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($path === false || !is_file($path)) {
        throw new RuntimeException('File kredensial Firebase tidak ditemukan atau berada di direktori publik.');
    }
    $documentPrefix = $documentRoot ? rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR : '';
    $pathIsPublic = $documentPrefix !== '' && (DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($path, $documentPrefix, strlen($documentPrefix)) === 0
        : strpos($path, $documentPrefix) === 0);
    if ($pathIsPublic) {
        throw new RuntimeException('File kredensial Firebase tidak ditemukan atau berada di direktori publik.');
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Kredensial Firebase tidak dapat dibaca.');
    }
    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('File kredensial Firebase bukan JSON yang valid.', 0, $exception);
    }
    foreach (['project_id', 'client_email', 'private_key'] as $requiredField) {
        if (empty($decoded[$requiredField]) || !is_string($decoded[$requiredField])) {
            throw new RuntimeException('Kredensial Firebase tidak memiliki kolom ' . $requiredField . '.');
        }
    }
    $credentials = $decoded;
    return $credentials;
}

function mp_mobile_push_access_token(array $credentials)
{
    static $accessToken = null;
    static $expiresAt = 0;
    if (is_string($accessToken) && $expiresAt > time() + 60) {
        return $accessToken;
    }

    $base64Url = static function ($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    };
    $issuedAt = time();
    $jwtHeader = $base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $jwtClaims = $base64Url(json_encode([
        'iss' => $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ], JSON_THROW_ON_ERROR));
    $unsignedJwt = $jwtHeader . '.' . $jwtClaims;
    if (!openssl_sign($unsignedJwt, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Tanda tangan kredensial Firebase gagal dibuat.');
    }
    $assertion = $unsignedJwt . '.' . $base64Url($signature);
    [$status, $responseBody] = mp_mobile_push_http_post(
        'https://oauth2.googleapis.com/token',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ])
    );
    if ($status !== 200) {
        throw new RuntimeException('Firebase OAuth menolak kredensial (HTTP ' . $status . ').');
    }
    $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    if (empty($response['access_token']) || empty($response['expires_in'])) {
        throw new RuntimeException('Firebase OAuth tidak mengembalikan access token.');
    }
    $accessToken = $response['access_token'];
    $expiresAt = $issuedAt + (int)$response['expires_in'];
    return $accessToken;
}

function mp_mobile_push_deliver(array $credentials, $deviceToken, $title, $body, $targetUrl)
{
    global $koneksi;

    $payload = json_encode([
        'message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => ['url' => $targetUrl],
            'android' => [
                'priority' => 'HIGH',
                'notification' => ['channel_id' => 'flom-marketplace'],
            ],
            'apns' => [
                'payload' => ['aps' => ['sound' => 'default']],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    [$status, $responseBody] = mp_mobile_push_http_post(
        'https://fcm.googleapis.com/v1/projects/' . rawurlencode($credentials['project_id']) . '/messages:send',
        [
            'Authorization' => 'Bearer ' . mp_mobile_push_access_token($credentials),
            'Content-Type' => 'application/json',
        ],
        $payload
    );
    if ($status >= 200 && $status < 300) {
        return;
    }

    $response = json_decode($responseBody, true);
    $errorStatus = $response['error']['status'] ?? '';
    if ($errorStatus === 'UNREGISTERED') {
        mp_mobile_push_remove_device($deviceToken);
        return;
    }
    throw new RuntimeException('Firebase menolak push notification (HTTP ' . $status . ', ' . ($errorStatus ?: 'UNKNOWN') . ').');
}

function mp_mobile_push_send_pending($limit = 25)
{
    global $koneksi;

    $credentials = mp_mobile_push_service_account();
    $limit = max(1, min(100, (int)$limit));
    $queueResult = mysqli_query(
        $koneksi,
        'SELECT id, user_type, user_id, title, body, target_url, attempts
        FROM mp_mobile_push_queue
        WHERE sent_at IS NULL AND attempts < 5
          AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        ORDER BY id ASC LIMIT ' . $limit
    );
    $sentCount = 0;
    while ($notification = mysqli_fetch_assoc($queueResult)) {
        $notificationId = (int)$notification['id'];
        $attemptCount = (int)$notification['attempts'];
        $claimStmt = mysqli_prepare($koneksi, 'UPDATE mp_mobile_push_queue SET attempts = attempts + 1, last_attempt_at = CURRENT_TIMESTAMP WHERE id = ? AND sent_at IS NULL AND attempts = ? AND (last_attempt_at IS NULL OR last_attempt_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE))');
        mysqli_stmt_bind_param($claimStmt, 'ii', $notificationId, $attemptCount);
        mysqli_stmt_execute($claimStmt);
        if (mysqli_stmt_affected_rows($claimStmt) !== 1) {
            continue;
        }

        $userType = $notification['user_type'];
        $userId = (int)$notification['user_id'];
        $deviceStmt = mysqli_prepare($koneksi, 'SELECT device_token FROM mp_mobile_push_devices WHERE user_type = ? AND user_id = ?');
        mysqli_stmt_bind_param($deviceStmt, 'si', $userType, $userId);
        mysqli_stmt_execute($deviceStmt);
        $deviceResult = mysqli_stmt_get_result($deviceStmt);
        $deliveryError = '';
        while ($device = mysqli_fetch_assoc($deviceResult)) {
            try {
                mp_mobile_push_deliver($credentials, $device['device_token'], $notification['title'], $notification['body'], $notification['target_url']);
            } catch (RuntimeException | JsonException $exception) {
                $deliveryError = $exception->getMessage();
                error_log('FLOM mobile push delivery failed for queue item ' . $notificationId . ': ' . $deliveryError);
                break;
            }
        }

        if ($deliveryError === '') {
            $sentStmt = mysqli_prepare($koneksi, 'UPDATE mp_mobile_push_queue SET sent_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = ?');
            mysqli_stmt_bind_param($sentStmt, 'i', $notificationId);
            mysqli_stmt_execute($sentStmt);
            $sentCount++;
        } else {
            $errorStmt = mysqli_prepare($koneksi, 'UPDATE mp_mobile_push_queue SET last_error = ? WHERE id = ?');
            mysqli_stmt_bind_param($errorStmt, 'si', $deliveryError, $notificationId);
            mysqli_stmt_execute($errorStmt);
        }
    }
    return $sentCount;
}
