<?php
require_once 'config.php';
if (is_admin()) { header('Location: admin_dashboard.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        header('Location: admin_dashboard.php');
        exit;
    }
    $error = 'Incorrect username or password.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Login | Bella Vista Oasis Spa</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-body">
<a class="back-home" href="index.php">← Return to website</a><main class="login-card"><div class="brand login-brand"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></div><p class="eyebrow">Staff access</p><h1>Welcome back.</h1><p>Sign in to manage spa appointments and services.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="stack-form"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="button button-dark full">Sign In</button></form><small class="login-hint">Demo credentials are provided in README.md after running setup_admin.php.</small></main></body></html>
