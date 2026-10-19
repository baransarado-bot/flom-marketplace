<?php
require_once __DIR__ . '/marketplace_lib.php';

$role = ($_POST['role'] ?? $_GET['role'] ?? 'seller') === 'admin' ? 'admin' : 'seller';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($role === 'admin') {
            $stmt = mysqli_prepare($koneksi, 'SELECT id, username, password_hash FROM mp_admins WHERE username = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if ($account && password_verify($password, $account['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_logged'] = true;
                $_SESSION['admin_id'] = (int)$account['id'];
                $_SESSION['username'] = $account['username'];
                mp_redirect('admin_marketplace.php');
            }
            $error = 'Username atau kata sandi admin tidak sesuai.';
        } else {
            $stmt = mysqli_prepare($koneksi, 'SELECT id, password_hash FROM mp_shops WHERE username = ? LIMIT 1');
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

            if ($account && password_verify($password, $account['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['mp_seller_id'] = (int)$account['id'];
                mp_redirect('toko_saya.php');
            }
            $error = 'Username atau kata sandi penjual tidak sesuai.';
        }
    }
}

$loginImageResult = mysqli_query($koneksi, "SELECT image_path FROM mp_site_logos WHERE slot = 'login_visual' LIMIT 1");
$loginImage = mysqli_fetch_assoc($loginImageResult);
$heroImage = $loginImage ? $loginImage['image_path'] : 'assets/login-flores.svg';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#123d33">
    <?php mp_render_loading_screen_assets(); ?>
    <title>Masuk - Pasar Ekraf Flores Timur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --forest: #123d33; --forest-deep: #0b2b25; --forest-bright: #1c7454; --gold: #f2c66d; --ink: #1d302b; --muted: #78877f; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 2rem; background: #eef2ef; color: var(--ink); font-family: "DM Sans", system-ui, sans-serif; }
        .login-frame { display: grid; width: min(1180px, 100%); min-height: min(720px, calc(100vh - 4rem)); grid-template-columns: 1.12fr .88fr; overflow: hidden; border: 1px solid rgba(18,61,51,.08); border-radius: 1.5rem; background: #fff; box-shadow: 0 28px 90px rgba(21,48,38,.16); }
        .login-visual { position: relative; display: flex; min-height: 640px; align-items: flex-end; overflow: hidden; padding: clamp(2rem, 5vw, 4.4rem); background: var(--forest-deep); color: #fff; }
        .login-visual::before { position: absolute; inset: 4.5rem 0 0; z-index: 0; background: linear-gradient(180deg, rgba(7,28,23,.08) 10%, rgba(6,28,23,.32) 46%, rgba(5,25,21,.93) 100%), linear-gradient(90deg, rgba(7,28,23,.2), transparent 72%); content: ""; }
        .login-visual-image { position: absolute; inset: 4.5rem 0 0; width: 100%; height: calc(100% - 4.5rem); object-fit: contain; object-position: center; background: var(--forest-deep); }
        .visual-back { position: absolute; z-index: 2; top: 1.4rem; right: 2rem; display: inline-flex; align-items: center; gap: .5rem; border: 1px solid rgba(255,255,255,.35); border-radius: .7rem; padding: .65rem .9rem; background: rgba(17,38,31,.3); color: #fff; font-size: .84rem; font-weight: 600; text-decoration: none; backdrop-filter: blur(10px); transition: background .2s ease; }
        .visual-back:hover { background: rgba(17,38,31,.64); color: #fff; }
        .visual-copy { position: relative; z-index: 1; max-width: 620px; }
        .visual-copy-frame { border: 1px solid #ff9f43; background: rgba(255,159,67,.08); color: #ff9f43; text-shadow: 0 1px 2px rgba(0,0,0,.72); }
        .visual-kicker { position: absolute; z-index: 2; top: 1.4rem; left: clamp(2rem, 5vw, 4.4rem); display: inline-flex; align-items: center; gap: .55rem; margin: 0; border-radius: .55rem; padding: .45rem .7rem; font-size: .76rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
        .visual-title { width: fit-content; max-width: 610px; margin: 0 0 1rem; border-radius: .8rem; padding: .6rem .8rem; font-size: clamp(2.5rem, 4.7vw, 4.45rem); font-weight: 800; line-height: 1.02; letter-spacing: -.055em; }
        .visual-description { width: fit-content; max-width: 540px; margin: 0; border-radius: .7rem; padding: .55rem .75rem; font-size: 1rem; line-height: 1.8; }
        .login-panel { display: flex; align-items: center; justify-content: center; padding: clamp(1.5rem, 4vw, 3.5rem); background: #fafcfb; }
        .login-card { width: min(410px, 100%); padding: clamp(1.5rem, 3vw, 2.25rem); border: 1px solid #e7ece8; border-radius: 1.25rem; background: #fff; box-shadow: 0 14px 45px rgba(25,57,44,.07); }
        .brand-mark { display: inline-grid; width: 44px; height: 44px; place-items: center; border-radius: 14px; background: #e7f3eb; color: var(--forest-bright); font-size: 1.3rem; }
        .brand-title { color: #183b30; font-size: .95rem; font-weight: 800; letter-spacing: -.015em; }
        .brand-subtitle { margin-top: .1rem; color: var(--muted); font-size: .79rem; }
        .panel-kicker { margin-top: 2rem; color: #638272; font-size: .7rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; }
        .panel-title { margin: .45rem 0 .55rem; color: #1c302a; font-size: clamp(1.75rem, 3vw, 2.25rem); font-weight: 800; letter-spacing: -.05em; }
        .panel-description { margin-bottom: 1.35rem; color: #849189; font-size: .89rem; line-height: 1.6; }
        .role-switch { display: grid; grid-template-columns: 1fr 1fr; gap: .3rem; margin-bottom: 1.35rem; padding: .28rem; border: 1px solid #e5ebe7; border-radius: .8rem; background: #f2f6f3; }
        .role-button { min-height: 43px; border: 0; border-radius: .58rem; background: transparent; color: #718078; font: inherit; font-size: .88rem; font-weight: 700; transition: background .18s ease, color .18s ease, box-shadow .18s ease; }
        .role-button:hover { color: var(--forest); }
        .role-button.active { background: #fff; color: var(--forest); box-shadow: 0 2px 8px rgba(25,57,44,.1); }
        .form-label { margin-bottom: .45rem; color: #47574f; font-size: .83rem; font-weight: 700; }
        .form-control { min-height: 49px; border-color: #dfe7e2; border-radius: .7rem; padding: .75rem .9rem; color: #283a32; font-size: .9rem; }
        .form-control::placeholder { color: #abb4af; }
        .form-control:focus { border-color: #5e9a7a; box-shadow: 0 0 0 .22rem rgba(28,116,84,.12); }
        .login-submit { min-height: 50px; border: 0; border-radius: .7rem; background: var(--forest); color: #fff; font-size: .9rem; font-weight: 700; box-shadow: 0 8px 18px rgba(18,61,51,.15); transition: background .18s ease, transform .18s ease; }
        .login-submit:hover { transform: translateY(-1px); background: #0e332a; color: #fff; }
        .login-submit:focus-visible, .role-button:focus-visible { outline: 3px solid rgba(28,116,84,.32); outline-offset: 2px; }
        .login-help { min-height: 1.3rem; margin-top: 1rem; color: #708078; font-size: .81rem; text-align: center; }
        .login-help a, .setup-link { color: #176b4c; font-weight: 700; text-decoration: none; }
        .login-help a:hover, .setup-link:hover { text-decoration: underline; }
        .home-link { display: inline-flex; align-items: center; gap: .4rem; margin-top: 1.05rem; color: #849189; font-size: .81rem; text-decoration: none; }
        .home-link:hover { color: var(--forest-bright); }
        .secure-note { display: flex; align-items: center; justify-content: center; gap: .4rem; margin-top: 1.2rem; color: #9aa69f; font-size: .72rem; }
        .secure-note i { color: #4b9471; }
        @media (max-width: 820px) { body { padding: 1rem; } .login-frame { width: min(560px, 100%); min-height: 0; grid-template-columns: 1fr; border-radius: 1.2rem; } .login-visual { min-height: 250px; padding: 2rem; } .login-visual::before, .login-visual-image { top: 5.5rem; height: calc(100% - 5.5rem); } .visual-kicker { top: 1rem; left: 2rem; } .visual-back { top: 3.2rem; right: auto; left: 2rem; padding: .5rem .7rem; } .visual-copy { padding-top: 2.5rem; } .visual-title { max-width: 500px; font-size: clamp(2rem, 7vw, 3rem); } .visual-description { font-size: .89rem; } .login-panel { padding: 1.25rem; } .login-card { padding: 1.5rem; } .panel-kicker { margin-top: 1.5rem; } }
        @media (max-width: 420px) { body { padding: .55rem; } .login-frame { border-radius: .9rem; } .login-visual { min-height: 220px; padding: 1.3rem; } .login-visual::before, .login-visual-image { top: 5.5rem; height: calc(100% - 5.5rem); } .visual-kicker { top: .8rem; left: 1.3rem; } .visual-back { top: 3.05rem; right: auto; left: 1.3rem; } .visual-title { font-size: 1.9rem; } .login-panel { padding: .75rem; } .login-card { padding: 1.25rem; border-radius: .9rem; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
    <?php mp_render_loading_screen(); ?>
    <main class="login-frame">
        <section class="login-visual" aria-label="Pasar Ekraf Flores Timur">
            <img class="login-visual-image" src="<?= mp_escape($heroImage) ?>" alt="Ilustrasi marketplace UMKM Flores Timur">
            <a class="visual-back" href="marketplace.php"><i class="bi bi-arrow-left"></i>Beranda</a>
            <div class="visual-kicker visual-copy-frame"><i class="bi bi-geo-alt-fill"></i>Kabupaten Flores Timur</div>
            <div class="visual-copy">
                <h1 class="visual-title visual-copy-frame">Satu ruang untuk produk lokal Flores Timur.</h1>
                <p class="visual-description visual-copy-frame">Temukan cita rasa, tenun, dan kerajinan khas. Mari dukung UMKM dan ekonomi kreatif tumbuh bersama.</p>
            </div>
        </section>
        <section class="login-panel">
            <div class="login-card">
                <div class="d-flex align-items-center gap-3">
                    <span class="brand-mark"><i class="bi bi-shop-window"></i></span>
                    <div><div class="brand-title">Pasar Ekraf Flores Timur</div><div class="brand-subtitle">Marketplace UMKM &amp; Ekonomi Kreatif</div></div>
                </div>
                <div class="panel-kicker">Portal marketplace daerah</div>
                <h2 class="panel-title">Masuk ke akun</h2>
                <p class="panel-description">Pilih jenis akun, kemudian masukkan username dan kata sandi Anda.</p>

                <?php if ($error !== ''): ?><div class="alert alert-danger py-2 small" role="alert"><?= mp_escape($error) ?></div><?php endif; ?>

                <div class="role-switch" role="group" aria-label="Pilih jenis akun">
                    <button class="role-button <?= $role === 'seller' ? 'active' : '' ?>" type="button" data-role="seller" aria-pressed="<?= $role === 'seller' ? 'true' : 'false' ?>"><i class="bi bi-shop me-1"></i>Penjual</button>
                    <button class="role-button <?= $role === 'admin' ? 'active' : '' ?>" type="button" data-role="admin" aria-pressed="<?= $role === 'admin' ? 'true' : 'false' ?>"><i class="bi bi-shield-lock me-1"></i>Admin</button>
                </div>

                <form method="post" action="login.php" id="login-form">
                    <?php mp_render_csrf(); ?>
                    <input type="hidden" name="role" id="account-role" value="<?= mp_escape($role) ?>">
                    <div class="mb-3"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" autocomplete="username" placeholder="Masukkan username" required autofocus></div>
                    <div class="mb-4"><label class="form-label" for="password">Kata sandi</label><input class="form-control" id="password" type="password" name="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required></div>
                    <button class="btn login-submit w-100" id="submit-label" type="submit"><?= $role === 'admin' ? 'Masuk sebagai Admin' : 'Masuk sebagai Penjual' ?><i class="bi bi-arrow-right ms-2"></i></button>
                </form>

                <div class="login-help" id="login-help">
                    <?php if ($role === 'admin'): ?>
                        Penyiapan pertama? <a href="setup_admin.php">Buat akun admin</a>
                    <?php else: ?>
                        Belum punya toko? <a href="daftar_umkm.php">Daftar sebagai UMKM/EKRAF</a>
                    <?php endif; ?>
                </div>
                <div class="text-center"><a class="home-link" href="marketplace.php"><i class="bi bi-arrow-left"></i>Kembali ke marketplace</a></div>
                <div class="secure-note"><i class="bi bi-shield-check"></i>Login aman dengan akun terdaftar</div>
            </div>
        </section>
    </main>
    <script>
        (() => {
            const buttons = document.querySelectorAll('.role-button');
            const roleInput = document.getElementById('account-role');
            const submitLabel = document.getElementById('submit-label');
            const help = document.getElementById('login-help');
            const username = document.getElementById('username');

            buttons.forEach((button) => {
                button.addEventListener('click', () => {
                    const role = button.dataset.role;
                    roleInput.value = role;
                    buttons.forEach((item) => {
                        const active = item === button;
                        item.classList.toggle('active', active);
                        item.setAttribute('aria-pressed', active ? 'true' : 'false');
                    });
                    submitLabel.innerHTML = role === 'admin'
                        ? 'Masuk sebagai Admin<i class="bi bi-arrow-right ms-2"></i>'
                        : 'Masuk sebagai Penjual<i class="bi bi-arrow-right ms-2"></i>';
                    help.innerHTML = role === 'admin'
                        ? 'Penyiapan pertama? <a href="setup_admin.php">Buat akun admin</a>'
                        : 'Belum punya toko? <a href="daftar_umkm.php">Daftar sebagai UMKM/EKRAF</a>';
                    const nextUrl = new URL(window.location.href);
                    nextUrl.searchParams.set('role', role);
                    window.history.replaceState({}, '', nextUrl);
                    username.focus();
                });
            });
        })();
    </script>
</body>
</html>
