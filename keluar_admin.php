<?php
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/mobile_push.php';
if (!empty($_SESSION['mp_mobile_push_token'])) {
    try {
        mp_mobile_push_remove_device($_SESSION['mp_mobile_push_token']);
    } catch (mysqli_sql_exception $exception) {
        error_log('Marketplace admin push-token cleanup failed: ' . $exception->getMessage());
    }
}
unset($_SESSION['admin_logged'], $_SESSION['admin_id'], $_SESSION['username'], $_SESSION['mp_mobile_push_token']);
session_regenerate_id(true);
header('Location: login_admin.php');
exit;
