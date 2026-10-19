<?php
require_once __DIR__ . '/marketplace_lib.php';
if (!empty($_SESSION['mp_mobile_push_token'])) {
    try {
        mp_mobile_push_remove_device($_SESSION['mp_mobile_push_token']);
    } catch (mysqli_sql_exception $exception) {
        error_log('Marketplace seller push-token cleanup failed: ' . $exception->getMessage());
    }
}
unset($_SESSION['mp_seller_id'], $_SESSION['mp_mobile_push_token']);
session_regenerate_id(true);
mp_redirect('marketplace.php');
