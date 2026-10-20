<?php
require_once __DIR__ . '/marketplace_lib.php';

$adminLogged = !empty($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
$shopId = !empty($_SESSION['mp_seller_id']) ? (int)$_SESSION['mp_seller_id'] : 0;
$sellerLogged = $shopId > 0;
$error = '';
$startMode = (($_GET['start'] ?? $_POST['start'] ?? '') === 'admin' || ($_POST['action'] ?? '') === 'start_admin') ? 'admin' : '';
$requestedShopId = filter_var($_GET['shop_id'] ?? $_POST['shop_id'] ?? null, FILTER_VALIDATE_INT);
if (!$requestedShopId || $requestedShopId < 1) {
    $requestedShopId = 0;
}

function mp_chat_cookie_name($conversationId)
{
    return 'mp_chat_' . (int)$conversationId;
}

function mp_chat_cookie_options($expires)
{
    $cookiePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $cookiePath = $cookiePath === '.' || $cookiePath === '/'
        ? '/'
        : rtrim($cookiePath, '/') . '/';

    return [
        'expires' => $expires,
        'path' => $cookiePath,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function mp_chat_load_conversation($conversationId, $adminLogged, $shopId, $sellerLogged, &$buyerToken = null)
{
    global $koneksi;

    $stmt = mysqli_prepare($koneksi, 'SELECT * FROM mp_conversations WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $conversationId);
    mysqli_stmt_execute($stmt);
    $conversation = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$conversation) {
        return null;
    }

    if ($adminLogged) {
        return $conversation['channel'] === 'seller_admin' ? $conversation : null;
    }
    if ($sellerLogged) {
        return (int)$conversation['shop_id'] === $shopId ? $conversation : null;
    }

    $cookie = $_COOKIE[mp_chat_cookie_name($conversationId)] ?? '';
    if (!is_string($cookie) || !preg_match('/^[a-f0-9]{64}$/', $cookie)) {
        return null;
    }
    $tokenHash = hash('sha256', $cookie);
    if (empty($conversation['access_token_hash']) || !hash_equals($conversation['access_token_hash'], $tokenHash)) {
        return null;
    }
    $buyerToken = $cookie;
    return $conversation;
}

function mp_chat_create_conversation($channel, $conversationShopId, $contactName, $contactPhone, $initialMessage, $senderType, $senderId, $accessTokenHash)
{
    global $koneksi;

    mysqli_begin_transaction($koneksi);
    try {
        $stmt = mysqli_prepare($koneksi, 'INSERT INTO mp_conversations (channel, shop_id, contact_name, contact_phone, access_token_hash, last_message_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)');
        mysqli_stmt_bind_param($stmt, 'sisss', $channel, $conversationShopId, $contactName, $contactPhone, $accessTokenHash);
        mysqli_stmt_execute($stmt);
        $conversationId = (int)mysqli_insert_id($koneksi);

        $messageStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_messages (conversation_id, sender_type, sender_id, message_body) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($messageStmt, 'isis', $conversationId, $senderType, $senderId, $initialMessage);
        mysqli_stmt_execute($messageStmt);
        mysqli_commit($koneksi);
        return $conversationId;
    } catch (Throwable $exception) {
        mysqli_rollback($koneksi);
        throw $exception;
    }
}

function mp_chat_issue_cookie($conversationId, $token)
{
    setcookie(mp_chat_cookie_name($conversationId), $token, mp_chat_cookie_options(time() + 60 * 60 * 24 * 365));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'start_shop' || $action === 'start_admin') {
            $contactName = trim((string)($_POST['contact_name'] ?? ''));
            $contactPhone = trim((string)($_POST['contact_phone'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));
            $channel = $action === 'start_shop' ? 'buyer_shop' : 'seller_admin';
            $senderType = $channel === 'buyer_shop' ? 'buyer' : 'prospective';
            $conversationShopId = 0;
            $senderId = 0;
            $token = '';

            if ($channel === 'buyer_shop') {
                $requestedShopId = filter_var($_POST['shop_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$requestedShopId || $requestedShopId < 1) {
                    $error = 'Toko yang dipilih tidak valid.';
                } else {
                    $shopStmt = mysqli_prepare($koneksi, "SELECT id FROM mp_shops WHERE id = ? AND status = 'approved' LIMIT 1");
                    mysqli_stmt_bind_param($shopStmt, 'i', $requestedShopId);
                    mysqli_stmt_execute($shopStmt);
                    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt))) {
                        $error = 'Toko tidak ditemukan atau belum tersedia di marketplace.';
                    } else {
                        $conversationShopId = (int)$requestedShopId;
                    }
                }
            } elseif ($sellerLogged) {
                $shopStmt = mysqli_prepare($koneksi, 'SELECT business_name, phone FROM mp_shops WHERE id = ? LIMIT 1');
                mysqli_stmt_bind_param($shopStmt, 'i', $shopId);
                mysqli_stmt_execute($shopStmt);
                $shop = mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt));
                if (!$shop) {
                    $error = 'Data toko tidak ditemukan. Silakan masuk kembali.';
                } else {
                    $contactName = $shop['business_name'];
                    $contactPhone = $shop['phone'];
                    $conversationShopId = $shopId;
                    $senderType = 'seller';
                    $senderId = $shopId;
                }
            }

            if ($error === '' && ($contactName === '' || mb_strlen($contactName, 'UTF-8') > 120 || $contactPhone === '' || mb_strlen($contactPhone, 'UTF-8') > 30)) {
                $error = 'Nama dan nomor WhatsApp wajib diisi sesuai batas yang tersedia.';
            } elseif ($error === '' && ($message === '' || mb_strlen($message, 'UTF-8') > 2000)) {
                $error = 'Pesan wajib diisi dan maksimal 2.000 karakter.';
            }

            if ($error === '') {
                try {
                    if ($channel === 'buyer_shop' || !$sellerLogged) {
                        $token = bin2hex(random_bytes(32));
                        $tokenHash = hash('sha256', $token);
                    } else {
                        $tokenHash = null;
                    }
                    $conversationId = mp_chat_create_conversation($channel, $conversationShopId ?: null, $contactName, $contactPhone, $message, $senderType, $senderId ?: null, $tokenHash);
                    if ($token !== '') {
                        mp_chat_issue_cookie($conversationId, $token);
                    }
                    mp_set_flash('success', 'Pesan berhasil dikirim. Balasan dapat dilihat di percakapan ini.');
                    mp_redirect('komunikasi.php?conversation=' . $conversationId);
                } catch (Throwable $exception) {
                    error_log('Marketplace conversation creation failed: ' . $exception->getMessage());
                    $error = 'Pesan belum dapat dikirim. Silakan coba kembali.';
                }
            }
        } elseif ($action === 'reply') {
            $conversationId = filter_var($_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT);
            $message = trim((string)($_POST['message'] ?? ''));
            $buyerToken = null;
            $conversation = $conversationId ? mp_chat_load_conversation((int)$conversationId, $adminLogged, $shopId, $sellerLogged, $buyerToken) : null;
            if (!$conversation) {
                $error = 'Percakapan tidak ditemukan atau Anda tidak memiliki akses.';
            } elseif ($message === '' || mb_strlen($message, 'UTF-8') > 2000) {
                $error = 'Pesan wajib diisi dan maksimal 2.000 karakter.';
            } else {
                $senderType = $adminLogged ? 'admin' : ($sellerLogged ? 'seller' : ($conversation['channel'] === 'buyer_shop' ? 'buyer' : 'prospective'));
                $senderId = $adminLogged ? (int)$_SESSION['admin_id'] : ($sellerLogged ? $shopId : null);
                $replyStmt = mysqli_prepare($koneksi, 'INSERT INTO mp_messages (conversation_id, sender_type, sender_id, message_body) VALUES (?, ?, ?, ?)');
                $replyShopId = $senderId;
                mysqli_stmt_bind_param($replyStmt, 'isis', $conversationId, $senderType, $replyShopId, $message);
                mysqli_stmt_execute($replyStmt);
                $updateStmt = mysqli_prepare($koneksi, 'UPDATE mp_conversations SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?');
                mysqli_stmt_bind_param($updateStmt, 'i', $conversationId);
                mysqli_stmt_execute($updateStmt);
                mp_set_flash('success', 'Balasan berhasil dikirim.');
                mp_redirect('komunikasi.php?conversation=' . (int)$conversationId);
            }
        } else {
            $error = 'Aksi pesan tidak dikenali.';
        }
    }
}

$selectedId = filter_var($_GET['conversation'] ?? $_POST['conversation_id'] ?? null, FILTER_VALIDATE_INT);
if (!$selectedId || $selectedId < 1) {
    $selectedId = 0;
}
$buyerToken = null;
$selectedConversation = $selectedId ? mp_chat_load_conversation($selectedId, $adminLogged, $shopId, $sellerLogged, $buyerToken) : null;
if ($selectedId && !$selectedConversation && $error === '') {
    $error = 'Percakapan tidak ditemukan atau Anda tidak memiliki akses.';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll_messages') {
    $afterMessageId = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT);
    if ($afterMessageId === false || $afterMessageId < 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'Posisi pesan tidak valid.'], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if (!$selectedConversation) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => 'Percakapan tidak ditemukan atau Anda tidak memiliki akses.'], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $newMessagesStmt = mysqli_prepare($koneksi, 'SELECT id, sender_type, message_body, created_at FROM mp_messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT 100');
    mysqli_stmt_bind_param($newMessagesStmt, 'ii', $selectedId, $afterMessageId);
    mysqli_stmt_execute($newMessagesStmt);
    $newMessagesResult = mysqli_stmt_get_result($newMessagesStmt);
    $newMessages = [];
    while ($messageRow = mysqli_fetch_assoc($newMessagesResult)) {
        $messageRow['id'] = (int)$messageRow['id'];
        $messageRow['time_label'] = date('d/m/Y H:i', strtotime($messageRow['created_at'])) . ' WITA';
        $newMessages[] = $messageRow;
    }
    echo json_encode(['messages' => $newMessages], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$guestConversations = [];
if (!$adminLogged && !$sellerLogged) {
    $cookieIds = [];
    foreach (array_keys($_COOKIE) as $cookieName) {
        if (preg_match('/^mp_chat_(\d+)$/', $cookieName, $matches)) {
            $cookieIds[] = (int)$matches[1];
        }
    }
    $cookieIds = array_slice(array_unique($cookieIds), 0, 40);
    foreach ($cookieIds as $cookieId) {
        $conversationToken = null;
        $conversation = mp_chat_load_conversation($cookieId, false, 0, false, $conversationToken);
        if ($conversation) {
            $conversation['display_name'] = $conversation['channel'] === 'buyer_shop'
                ? 'Toko'
                : 'Admin FLOM Marketplace';
            if ($conversation['channel'] === 'buyer_shop') {
                $shopNameStmt = mysqli_prepare($koneksi, 'SELECT business_name FROM mp_shops WHERE id = ? LIMIT 1');
                $conversationShop = (int)$conversation['shop_id'];
                mysqli_stmt_bind_param($shopNameStmt, 'i', $conversationShop);
                mysqli_stmt_execute($shopNameStmt);
                $shopName = mysqli_fetch_assoc(mysqli_stmt_get_result($shopNameStmt));
                $conversation['display_name'] = $shopName['business_name'] ?? 'Toko';
            }
            $previewStmt = mysqli_prepare($koneksi, 'SELECT message_body FROM mp_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
            mysqli_stmt_bind_param($previewStmt, 'i', $cookieId);
            mysqli_stmt_execute($previewStmt);
            $preview = mysqli_fetch_assoc(mysqli_stmt_get_result($previewStmt));
            $conversation['last_message_preview'] = $preview['message_body'] ?? '';
            $guestConversations[] = $conversation;
        }
    }
}

$conversationList = [];
if ($adminLogged) {
    $listResult = mysqli_query($koneksi, "SELECT c.id, c.channel, c.contact_name, c.contact_phone, c.last_message_at, s.business_name, (SELECT m.message_body FROM mp_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_preview FROM mp_conversations c LEFT JOIN mp_shops s ON s.id = c.shop_id WHERE c.channel = 'seller_admin' ORDER BY c.last_message_at DESC LIMIT 100");
    while ($item = mysqli_fetch_assoc($listResult)) {
        $item['display_name'] = $item['business_name'] ?: 'Calon penjual';
        $conversationList[] = $item;
    }
} elseif ($sellerLogged) {
    $listStmt = mysqli_prepare($koneksi, "SELECT c.id, c.channel, c.contact_name, c.contact_phone, c.last_message_at, s.business_name, (SELECT m.message_body FROM mp_messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_preview FROM mp_conversations c LEFT JOIN mp_shops s ON s.id = c.shop_id WHERE c.shop_id = ? ORDER BY c.last_message_at DESC LIMIT 100");
    mysqli_stmt_bind_param($listStmt, 'i', $shopId);
    mysqli_stmt_execute($listStmt);
    $listResult = mysqli_stmt_get_result($listStmt);
    while ($item = mysqli_fetch_assoc($listResult)) {
        $item['display_name'] = $item['channel'] === 'buyer_shop' ? $item['contact_name'] : 'Admin FLOM Marketplace';
        $conversationList[] = $item;
    }
} else {
    $conversationList = $guestConversations;
}

$messages = [];
if ($selectedConversation) {
    $messagesStmt = mysqli_prepare($koneksi, 'SELECT id, sender_type, message_body, created_at FROM mp_messages WHERE conversation_id = ? ORDER BY created_at ASC, id ASC');
    mysqli_stmt_bind_param($messagesStmt, 'i', $selectedId);
    mysqli_stmt_execute($messagesStmt);
    $messagesResult = mysqli_stmt_get_result($messagesStmt);
    while ($messageRow = mysqli_fetch_assoc($messagesResult)) {
        $messages[] = $messageRow;
    }
}

$publicShop = null;
if (!$selectedConversation && !$adminLogged && !$sellerLogged && $requestedShopId > 0) {
    $shopStmt = mysqli_prepare($koneksi, "SELECT id, business_name FROM mp_shops WHERE id = ? AND status = 'approved' LIMIT 1");
    mysqli_stmt_bind_param($shopStmt, 'i', $requestedShopId);
    mysqli_stmt_execute($shopStmt);
    $publicShop = mysqli_fetch_assoc(mysqli_stmt_get_result($shopStmt));
    if (!$publicShop && $error === '') {
        $error = 'Toko tidak ditemukan atau belum tersedia di marketplace.';
    }
}

$isStartAdmin = !$selectedConversation && !$publicShop && $startMode === 'admin' && !$adminLogged;
$canStartAdmin = !$adminLogged && ($sellerLogged || $isStartAdmin);
mp_render_header('Pesan dan Komunikasi', $sellerLogged);
mp_render_flash();
?>
<style>
    .chat-layout { display:grid; grid-template-columns:minmax(260px,.72fr) minmax(0,1.55fr); gap:1rem; align-items:stretch; }
    .chat-panel { min-width:0; border:1px solid #dce8df; border-radius:1rem; background:#fff; box-shadow:0 8px 24px rgba(17,53,43,.06); overflow:hidden; }
    .chat-panel-heading { padding:1rem 1.1rem; border-bottom:1px solid #edf1ee; }
    .chat-conversation-link { display:block; padding:.85rem 1rem; border-bottom:1px solid #edf1ee; color:inherit; text-decoration:none; transition:background-color .15s ease; }
    .chat-conversation-link:hover,.chat-conversation-link.active { background:#eaf5ef; }
    .chat-conversation-name { display:block; overflow:hidden; font-size:.94rem; text-overflow:ellipsis; white-space:nowrap; }
    .chat-conversation-preview { display:block; margin-top:.3rem; overflow:hidden; color:#718078; font-size:.8rem; text-overflow:ellipsis; white-space:nowrap; }
    .chat-thread { display:flex; min-height:530px; height:min(72vh,760px); flex-direction:column; }
    .chat-thread-heading { display:flex; min-height:72px; align-items:center; gap:.75rem; padding:.75rem 1rem; border-bottom:1px solid #e4ebe6; background:#f8fbf9; }
    .chat-avatar { display:grid; width:44px; height:44px; flex:0 0 44px; place-items:center; border-radius:50%; background:#167354; color:#fff; font-size:1.1rem; }
    .chat-thread-title { min-width:0; margin:0; overflow:hidden; font-size:1rem; font-weight:800; text-overflow:ellipsis; white-space:nowrap; }
    .chat-thread-subtitle { margin-top:.15rem; color:#718078; font-size:.78rem; }
    .chat-messages { display:flex; min-height:0; flex:1; flex-direction:column; gap:.6rem; overflow-y:auto; overscroll-behavior:contain; padding:1rem clamp(.75rem,3vw,1.4rem); background-color:#edf3ef; background-image:radial-gradient(rgba(22,115,84,.055) .75px,transparent .75px); background-size:18px 18px; }
    .chat-bubble { max-width:min(82%,620px); padding:.62rem .78rem .48rem; border:1px solid #e6ebe7; border-radius:1rem 1rem 1rem .3rem; background:#fff; box-shadow:0 1px 1px rgba(17,53,43,.05); overflow-wrap:anywhere; }
    .chat-bubble.mine { align-self:flex-end; border-color:#cce6d4; border-radius:1rem 1rem .3rem 1rem; background:#dff4e5; }
    .chat-meta { display:flex; flex-wrap:wrap; justify-content:space-between; gap:.3rem .8rem; margin-bottom:.3rem; color:#718078; font-size:.69rem; }
    .chat-message-text { color:#20372d; font-size:.92rem; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; }
    .chat-compose { display:flex; align-items:flex-end; gap:.65rem; padding:.75rem; border-top:1px solid #e3ebe5; background:#f8fbf9; }
    .chat-compose textarea { min-height:44px; max-height:120px; flex:1; resize:vertical; border-radius:1.2rem; padding:.65rem 1rem; }
    .chat-send-button { display:grid; width:46px; height:46px; flex:0 0 46px; place-items:center; border:0; border-radius:50%; background:#167354; color:#fff; font-size:1.1rem; }
    .chat-send-button:hover,.chat-send-button:focus-visible { background:#105c43; }
    .chat-connection-status { min-height:1.2rem; padding:.1rem .9rem .45rem; background:#f8fbf9; color:#718078; font-size:.72rem; }
    .chat-mobile-back { display:none; color:#385a4c; font-size:1.2rem; text-decoration:none; }
    .chat-start-form { max-width:740px; margin:0 auto; border:1px solid #dce8df; border-radius:1rem; }
    @media(max-width:767.98px){.chat-layout{grid-template-columns:minmax(0,1fr);gap:.7rem}.chat-thread{min-height:calc(100dvh - 245px);height:calc(100dvh - 245px);max-height:680px}.chat-layout-open .chat-list-panel{display:none}.chat-layout-open.show-chat-list .chat-list-panel{display:block}.chat-layout-open.show-chat-list .chat-thread-panel{display:none}.chat-mobile-back{display:inline-flex}.chat-bubble{max-width:90%}.chat-messages{padding:.8rem}.chat-compose{gap:.45rem;padding:.6rem}.chat-compose textarea{padding:.6rem .85rem}.chat-send-button{width:44px;height:44px;flex-basis:44px}}
    @media(prefers-reduced-motion:reduce){.chat-conversation-link{transition:none}}
</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div><span class="badge text-bg-success mb-2">Komunikasi FLOM Marketplace</span><h1 class="h3 fw-bold mb-1">Pesan dan komunikasi</h1><p class="small-muted mb-0">Tanyakan produk kepada toko atau hubungi admin untuk bantuan dan pendaftaran usaha.</p></div>
    <a class="btn btn-outline-secondary" href="marketplace.php"><i class="bi bi-shop me-1"></i>Kembali ke marketplace</a>
</div>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
<?php if ($selectedConversation): ?>
    <?php
    if ($adminLogged) {
        $conversationTitle = $selectedConversation['contact_name'];
        if (!empty($selectedConversation['shop_id'])) {
            $shopTitleStmt = mysqli_prepare($koneksi, 'SELECT business_name FROM mp_shops WHERE id = ? LIMIT 1');
            $conversationShopId = (int)$selectedConversation['shop_id'];
            mysqli_stmt_bind_param($shopTitleStmt, 'i', $conversationShopId);
            mysqli_stmt_execute($shopTitleStmt);
            $shopTitle = mysqli_fetch_assoc(mysqli_stmt_get_result($shopTitleStmt));
            $conversationTitle = $shopTitle['business_name'] ?? $conversationTitle;
        }
    } elseif ($sellerLogged) {
        $conversationTitle = $selectedConversation['channel'] === 'buyer_shop' ? $selectedConversation['contact_name'] : 'Admin FLOM Marketplace';
    } elseif ($selectedConversation['channel'] === 'buyer_shop') {
        $shopTitleStmt = mysqli_prepare($koneksi, 'SELECT business_name FROM mp_shops WHERE id = ? LIMIT 1');
        $conversationShopId = (int)$selectedConversation['shop_id'];
        mysqli_stmt_bind_param($shopTitleStmt, 'i', $conversationShopId);
        mysqli_stmt_execute($shopTitleStmt);
        $shopTitle = mysqli_fetch_assoc(mysqli_stmt_get_result($shopTitleStmt));
        $conversationTitle = $shopTitle['business_name'] ?? 'Toko';
    } else {
        $conversationTitle = 'Admin FLOM Marketplace';
    }
    ?>
    <div class="chat-layout chat-layout-open">
        <aside class="chat-panel chat-list-panel" aria-label="Daftar percakapan">
            <div class="chat-panel-heading"><h2 class="h6 fw-bold mb-0"><?= $adminLogged ? 'Pesan calon penjual / toko' : ($sellerLogged ? 'Percakapan toko' : 'Percakapan Anda') ?></h2></div>
            <?php foreach ($conversationList as $item): ?>
                <a class="chat-conversation-link <?= (int)$item['id'] === $selectedId ? 'active' : '' ?>" href="komunikasi.php?conversation=<?= (int)$item['id'] ?>">
                    <div class="d-flex justify-content-between gap-2"><strong class="chat-conversation-name"><?= mp_escape($item['display_name']) ?></strong><span class="small-muted small"><?= mp_escape(date('d/m H:i', strtotime($item['last_message_at']))) ?></span></div>
                    <span class="chat-conversation-preview"><?= mp_escape($item['last_message_preview'] ?? '') ?></span>
                </a>
            <?php endforeach; ?>
        </aside>
        <section class="chat-panel chat-thread-panel">
            <div class="chat-thread">
                <div class="chat-thread-heading">
                    <a class="chat-mobile-back" href="komunikasi.php" aria-label="Kembali ke daftar percakapan"><i class="bi bi-arrow-left"></i></a>
                    <div class="chat-avatar" aria-hidden="true"><i class="bi <?= $selectedConversation['channel'] === 'buyer_shop' ? 'bi-shop' : 'bi-shield-check' ?>"></i></div>
                    <div class="min-w-0">
                        <h2 class="chat-thread-title"><?= mp_escape($conversationTitle) ?></h2>
                        <div class="chat-thread-subtitle"><?= $selectedConversation['channel'] === 'buyer_shop' ? 'Chat pembeli dan toko · FLOM Marketplace' : 'Chat toko dan admin · FLOM Marketplace' ?></div>
                    </div>
                </div>
                <div class="chat-messages" id="chat-messages" aria-live="polite" aria-relevant="additions text">
            <?php foreach ($messages as $messageRow):
                $mine = $adminLogged ? $messageRow['sender_type'] === 'admin' : ($sellerLogged ? $messageRow['sender_type'] === 'seller' : in_array($messageRow['sender_type'], ['buyer', 'prospective'], true));
                $senderLabels = ['buyer' => 'Pembeli', 'seller' => 'Toko', 'prospective' => 'Calon penjual', 'admin' => 'Admin FLOM Marketplace'];
            ?>
                <article class="chat-bubble <?= $mine ? 'mine' : '' ?>" data-message-id="<?= (int)$messageRow['id'] ?>">
                    <div class="chat-meta"><span><?= mp_escape($senderLabels[$messageRow['sender_type']] ?? 'Pengguna') ?></span><time><?= mp_escape(date('d/m/Y H:i', strtotime($messageRow['created_at']))) ?> WITA</time></div>
                    <div class="chat-message-text"><?= mp_escape($messageRow['message_body']) ?></div>
                </article>
            <?php endforeach; ?>
                </div>
                <div class="chat-connection-status" id="chat-connection-status" role="status">Chat tersimpan di FLOM Marketplace. Balasan akan muncul otomatis.</div>
                <form method="post" class="chat-compose" id="chat-compose">
                    <?php mp_render_csrf(); ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="conversation_id" value="<?= (int)$selectedId ?>">
                    <label class="visually-hidden" for="reply-message">Tulis pesan</label>
                    <textarea class="form-control" id="reply-message" name="message" rows="1" maxlength="2000" placeholder="Tulis pesan..." required></textarea>
                    <button class="chat-send-button" type="submit" aria-label="Kirim pesan"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
                </form>
            </div>
        </section>
    </div>
    <script>
        (() => {
            const messageList = document.getElementById('chat-messages');
            const status = document.getElementById('chat-connection-status');
            const composeForm = document.getElementById('chat-compose');
            const composeInput = document.getElementById('reply-message');
            if (!messageList || !status || !composeForm || !composeInput) return;

            const conversationId = <?= (int)$selectedId ?>;
            const mineTypes = <?= json_encode($adminLogged ? ['admin'] : ($sellerLogged ? ['seller'] : ['buyer', 'prospective']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const senderLabels = {buyer: 'Pembeli', seller: 'Toko', prospective: 'Calon penjual', admin: 'Admin FLOM Marketplace'};
            let lastMessageId = Math.max(0, ...Array.from(messageList.querySelectorAll('[data-message-id]'), node => Number(node.dataset.messageId) || 0));
            let pollTimer;
            messageList.scrollTop = messageList.scrollHeight;

            const appendMessage = message => {
                const shouldFollow = messageList.scrollHeight - messageList.scrollTop - messageList.clientHeight < 120;
                const bubble = document.createElement('article');
                bubble.className = `chat-bubble${mineTypes.includes(message.sender_type) ? ' mine' : ''}`;
                bubble.dataset.messageId = String(message.id);

                const meta = document.createElement('div');
                meta.className = 'chat-meta';
                const sender = document.createElement('span');
                sender.textContent = senderLabels[message.sender_type] || 'Pengguna';
                const time = document.createElement('time');
                time.textContent = message.time_label;
                meta.append(sender, time);

                const body = document.createElement('div');
                body.className = 'chat-message-text';
                body.textContent = message.message_body;
                bubble.append(meta, body);
                messageList.append(bubble);
                lastMessageId = Math.max(lastMessageId, Number(message.id) || 0);
                if (shouldFollow) messageList.scrollTop = messageList.scrollHeight;
            };

            const poll = async () => {
                if (document.hidden) return schedulePoll();
                try {
                    const query = new URLSearchParams({action: 'poll_messages', conversation: String(conversationId), after: String(lastMessageId)});
                    const response = await fetch(`komunikasi.php?${query}`, {credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const result = await response.json();
                    if (!Array.isArray(result.messages)) throw new Error('Respons pesan tidak valid.');
                    result.messages.forEach(appendMessage);
                    status.textContent = 'Terhubung · balasan tampil otomatis';
                } catch (error) {
                    status.textContent = 'Koneksi chat terputus. Mencoba menyambung kembali...';
                } finally {
                    schedulePoll();
                }
            };

            function schedulePoll() {
                window.clearTimeout(pollTimer);
                pollTimer = window.setTimeout(poll, 3000);
            }

            composeInput.addEventListener('keydown', event => {
                if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                    event.preventDefault();
                    composeForm.requestSubmit();
                }
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) poll();
            });
            schedulePoll();
        })();
    </script>
<?php elseif ($publicShop || $isStartAdmin): ?>
    <section class="card chat-start-form p-4 p-md-5 mb-4">
        <h2 class="h4 fw-bold"><?= $publicShop ? 'Hubungi ' . mp_escape($publicShop['business_name']) : ($sellerLogged ? 'Hubungi admin FLOM Marketplace' : 'Kirim pesan kepada admin') ?></h2>
        <p class="small-muted"><?= $publicShop ? 'Tanyakan produk, stok, cara pemesanan, atau hal lain langsung kepada toko. Nomor kontak digunakan agar toko dapat menanggapi pesan Anda.' : 'Hubungi admin terkait pendaftaran UMKM/EKRAF, verifikasi toko, atau bantuan penggunaan marketplace.' ?></p>
        <form method="post" class="row g-3">
            <?php mp_render_csrf(); ?>
            <input type="hidden" name="action" value="<?= $publicShop ? 'start_shop' : 'start_admin' ?>">
            <?php if ($publicShop): ?><input type="hidden" name="shop_id" value="<?= (int)$publicShop['id'] ?>"><?php endif; ?>
            <?php if (!$sellerLogged): ?>
                <div class="col-md-6"><label class="form-label fw-semibold" for="contact-name">Nama</label><input class="form-control" id="contact-name" name="contact_name" maxlength="120" value="<?= mp_escape($_POST['contact_name'] ?? '') ?>" autocomplete="name" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold" for="contact-phone">Nomor HP/WhatsApp</label><input class="form-control" id="contact-phone" name="contact_phone" maxlength="30" value="<?= mp_escape($_POST['contact_phone'] ?? '') ?>" autocomplete="tel" required></div>
            <?php endif; ?>
            <div class="col-12"><label class="form-label fw-semibold" for="first-message">Pesan</label><textarea class="form-control" id="first-message" name="message" rows="5" maxlength="2000" placeholder="Tulis pesan Anda..." required><?= mp_escape($_POST['message'] ?? '') ?></textarea><div class="form-text">Maksimal 2.000 karakter. Balasan akan tersimpan di FLOM Marketplace.</div></div>
            <div class="col-12"><button class="btn btn-market fw-semibold" type="submit"><i class="bi bi-send me-1"></i>Kirim pesan</button><a class="btn btn-outline-secondary ms-1" href="komunikasi.php">Batal</a></div>
        </form>
    </section>
<?php else: ?>
    <div class="chat-layout">
        <section class="chat-panel">
            <div class="chat-panel-heading"><h2 class="h6 fw-bold mb-0"><?= $adminLogged ? 'Pesan calon penjual / toko' : ($sellerLogged ? 'Percakapan toko' : 'Percakapan Anda') ?></h2></div>
            <?php if ($conversationList === []): ?>
                <div class="p-3 small-muted"><?= $adminLogged ? 'Belum ada pesan masuk.' : ($sellerLogged ? 'Belum ada pesan dari pembeli atau percakapan dengan admin.' : 'Belum ada percakapan di perangkat ini.') ?></div>
            <?php else: foreach ($conversationList as $item): ?>
                <a class="chat-conversation-link <?= (int)$item['id'] === $selectedId ? 'active' : '' ?>" href="komunikasi.php?conversation=<?= (int)$item['id'] ?>">
                    <div class="d-flex justify-content-between gap-2"><strong><?= mp_escape($item['display_name']) ?></strong><span class="small-muted small"><?= mp_escape(date('d/m H:i', strtotime($item['last_message_at']))) ?></span></div>
                    <span class="chat-conversation-preview"><?= mp_escape($item['last_message_preview'] ?? '') ?></span>
                </a>
            <?php endforeach; endif; ?>
        </section>
        <section class="chat-panel p-4">
            <div class="text-center py-4">
                <i class="bi bi-chat-dots display-5 text-market"></i>
                <h2 class="h5 fw-bold mt-3"><?= $adminLogged ? 'Pilih pesan untuk dibalas' : ($sellerLogged ? 'Balas pembeli atau hubungi admin' : 'Mulai komunikasi') ?></h2>
                <p class="small-muted"><?= $adminLogged ? 'Pilih percakapan dari daftar untuk membaca dan membalas pesan.' : ($sellerLogged ? 'Pilih pesan pembeli di daftar atau kirim pertanyaan langsung kepada admin.' : 'Buka produk di marketplace untuk menghubungi toko, atau kirim pertanyaan kepada admin.') ?></p>
                <?php if ($canStartAdmin): ?><a class="btn btn-market fw-semibold" href="komunikasi.php?start=admin"><i class="bi bi-shield-check me-1"></i>Hubungi admin</a><?php elseif (!$adminLogged && !$sellerLogged): ?><a class="btn btn-market fw-semibold" href="komunikasi.php?start=admin"><i class="bi bi-chat-left-text me-1"></i>Pesan admin tentang pendaftaran</a><?php endif; ?>
            </div>
        </section>
    </div>
<?php endif; ?>
<?php mp_render_footer(); ?>
