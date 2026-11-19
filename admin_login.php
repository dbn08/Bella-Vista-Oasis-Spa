<?php
require_once 'config.php';
if (is_admin()) {
    header('Location: admin_dashboard.php');
    exit;
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
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
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login | Bella Vista Oasis Spa</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body.login-body {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      background: linear-gradient(160deg, #f7f1e8, #eadcc8);
    }

    .login-card {
      width: 100%;
      max-width: 390px !important;
      padding: 32px 30px !important;
      border-radius: 24px !important;
      background: #fffdf9 !important;
      border: 1px solid #e8dccb !important;
      box-shadow: 0 20px 50px rgba(63, 45, 32, 0.12) !important;
    }

    .login-brand {
      margin-bottom: 18px !important;
    }

    .login-brand .brand-mark {
      width: 34px !important;
      height: 34px !important;
      font-size: 14px !important;
    }

    .login-card .eyebrow {
      font-size: 0.68rem !important;
      letter-spacing: 0.2em !important;
      margin-bottom: 4px !important;
    }

    .login-card h1 {
      font-size: 1.85rem !important;
      line-height: 1.15 !important;
      margin: 0 0 6px !important;
    }

    .login-card > p {
      font-size: 0.86rem !important;
      line-height: 1.5 !important;
      color: #6f5f52 !important;
      margin: 0 0 20px !important;
    }

    .login-card .stack-form {
      gap: 14px !important;
    }

    .login-card .stack-form label {
      font-size: 0.8rem !important;
      font-weight: 500 !important;
      gap: 5px !important;
    }

    .login-card .stack-form input {
      height: 42px !important;
      padding: 8px 16px !important;
      font-size: 0.88rem !important;
      border-radius: 999px !important;
    }

    .password-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-wrap input {
      width: 100%;
      padding-right: 3.8rem !important;
    }

    .toggle-pw {
      position: absolute;
      right: 12px;
      background: none;
      border: none;
      font-size: 0.75rem;
      font-weight: 600;
      color: #8b7a6a;
      cursor: pointer;
      padding: 4px 6px;
    }

    .toggle-pw:hover {
      color: #3f2d20;
    }

    .login-card .button.full {
      height: 42px !important;
      padding: 0 20px !important;
      font-size: 0.78rem !important;
      letter-spacing: 0.14em !important;
      margin-top: 4px !important;
    }

    .login-hint {
      display: block;
      margin-top: 16px !important;
      font-size: 0.74rem !important;
      line-height: 1.45 !important;
      color: #8b7a6a !important;
      text-align: center;
    }

    .login-card .alert.error {
      padding: 9px 14px !important;
      font-size: 0.8rem !important;
      border-radius: 12px !important;
      margin-bottom: 14px !important;
    }

    .back-link-bottom {
      display: inline-block;
      margin-top: 16px;
      font-size: 0.8rem;
      color: #6f5f52;
      text-decoration: none;
    }

    .back-link-bottom:hover {
      color: #3f2d20;
      text-decoration: underline;
    }
  </style>
</head>
<body class="login-body">

  <main class="login-card">
    <div class="brand login-brand">
      <span class="brand-mark">✦</span>
      <span>BELLA VISTA <small>OASIS SPA</small></span>
    </div>

    <p class="eyebrow">Staff access</p>
    <h1>Welcome back.</h1>
    <p>Sign in to manage bookings, rooms, packages, treatments, and add-ons.</p>

    <?php if ($error): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="stack-form">
      <label>
        Username
        <input name="username" value="<?= e($username) ?>" required autocomplete="username" autofocus>
      </label>

      <label>
        Password
        <div class="password-wrap">
          <input type="password" id="passwordInput" name="password" required autocomplete="current-password">
          <button type="button" class="toggle-pw" id="togglePwBtn" aria-label="Show password">Show</button>
        </div>
      </label>

      <button class="button button-dark full" type="submit">Sign In</button>
    </form>

    <small class="login-hint">Authorized spa staff only · Default: <strong>admin</strong> / <strong>Admin@12345</strong></small>
  </main>

  <a class="back-link-bottom" href="index.php">← Return to website</a>

  <script>
    const pwInput = document.getElementById('passwordInput');
    const toggleBtn = document.getElementById('togglePwBtn');
    if (pwInput && toggleBtn) {
      toggleBtn.addEventListener('click', () => {
        const isHidden = pwInput.type === 'password';
        pwInput.type = isHidden ? 'text' : 'password';
        toggleBtn.textContent = isHidden ? 'Hide' : 'Show';
      });
    }
  </script>
</body>
</html>
