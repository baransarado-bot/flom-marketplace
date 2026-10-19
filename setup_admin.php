<?php
require_once __DIR__ . '/marketplace_lib.php';

$countResult = mysqli_query($koneksi, 'SELECT COUNT(*) AS total FROM mp_admins');
$adminCount = (int)mysqli_fetch_assoc($countResult)['total'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $adminCount === 0) {
    if (!mp_verify_csrf()) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.-]{4,60}$/', $username)) {
            $error = 'Username harus 4-60 karakter dan hanya berisi huruf, angka, titik, garis bawah, atau tanda hubung.';
        } elseif (strlen($password) < 12 || strlen($password) > 72) {
            $error = 'Kata sandi admin harus antara 12 dan 72 byte.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = mysqli_prepare($koneksi, 'INSERT INTO mp_admins (username, password_hash) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM mp_admins)');
                mysqli_stmt_bind_param($stmt, 'ss', $username, $hash);
                mysqli_stmt_execute($stmt);
                if (mysqli_stmt_affected_rows($stmt) === 1) {
                    mp_set_flash('success', 'Akun admin pertama berhasil dibuat. Silakan masuk.');
                    mp_redirect('login_admin.php');
                }
                $adminCount = (int)mysqli_fetch_assoc(mysqli_query($koneksi, 'SELECT COUNT(*) AS total FROM mp_admins'))['total'];
                $error = 'Akun admin sudah dibuat pada sesi lain. Silakan masuk.';
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $adminCount = (int)mysqli_fetch_assoc(mysqli_query($koneksi, 'SELECT COUNT(*) AS total FROM mp_admins'))['total'];
                    $error = $adminCount > 0 ? 'Akun admin sudah dibuat. Silakan masuk.' : 'Username admin tersebut sudah digunakan.';
                } else {
                    error_log('Dinkop admin setup failed: ' . $exception->getMessage());
                    $error = 'Akun admin gagal dibuat. Silakan coba kembali.';
                }
            }
        }
    }
}

mp_render_header('Penyiapan Admin');
mp_render_flash();
?>
<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <section class="card p-4 p-md-5">
            <h1 class="h3 fw-bold">Penyiapan admin pertama</h1>
            <?php if ($adminCount > 0): ?>
                <div class="alert alert-info">Akun admin sudah tersedia. Penyiapan pertama telah dikunci.</div>
                <a class="btn btn-market" href="login_admin.php">Ke halaman masuk</a>
            <?php else: ?>
                <p class="small-muted">Buat kredensial admin dengan kata sandi kuat. Formulir ini otomatis terkunci setelah akun pertama dibuat.</p>
                <?php if ($error !== ''): ?><div class="alert alert-danger"><?= mp_escape($error) ?></div><?php endif; ?>
                <form method="post">
                    <?php mp_render_csrf(); ?>
                    <div class="mb-3"><label class="form-label">Username admin</label><input class="form-control" name="username" minlength="4" maxlength="60" autocomplete="username" required></div>
                    <div class="mb-4"><label class="form-label">Kata sandi admin</label><input class="form-control" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required><div class="form-text">Minimal 12 karakter.</div></div>
                    <button class="btn btn-market w-100 fw-semibold" type="submit">Buat akun admin</button>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php mp_render_footer(); ?>
