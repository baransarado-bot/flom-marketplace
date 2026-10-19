<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../koneksi.php';
require_once __DIR__ . '/../../mobile_push.php';

try {
    $sentCount = mp_mobile_push_send_pending();
    echo 'Sent ' . $sentCount . " FLOM push notification(s).\n";
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'FLOM push worker: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
