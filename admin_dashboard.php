<?php
require_once 'config.php';
require_once 'mailer.php';

/* ---------- Access control ---------- */
if (empty($_SESSION['admin_id'])) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'login') {
        $st = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ?');
        $st->execute([trim($_POST['username'] ?? '')]);
        $admin = $st->fetch();
        if ($admin && password_verify((string)($_POST['password'] ?? ''), $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            header('Location: admin_dashboard.php');
            exit;
        }
        usleep(400000);
        $loginError = 'Invalid username or password.';
    }
    $loginNotice = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    bv_render_login($loginError, $loginNotice);
    exit;
}
$adminName = $_SESSION['admin_username'] ?? 'admin';

/* ---------- Rules (synced with book.php, save_booking.php & track.php) ---------- */
const BV_SLOTS = ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'];
const BV_MAX_GUESTS = 6;
const BV_DOWN_RATE  = 0.30;

$providers    = ['ewallet' => ['gcash' => 'GCash', 'maya' => 'Maya'], 'bank' => ['bdo' => 'BDO', 'bpi' => 'BPI', 'unionbank' => 'UnionBank']];
$methodLabels = ['cash' => 'Pay at the store', 'ewallet' => 'E-wallet', 'bank' => 'Online banking'];
$statuses     = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];
$payStatuses  = ['unpaid', 'pending', 'verified', 'refunded'];
$kinds        = ['service' => 'services', 'package' => 'packages', 'addon' => 'addons'];

/* Must match the catalog used on book.php */
$serviceCategories = [
    'massage' => 'Massage Services',
    'body'    => 'Body Treatments',
    'facial'  => 'Facial & Skin Care',
    'nail'    => 'Nail Care Services',
    'hair'    => 'Hair Removal Services',
];
$packageRoomTypes = [
    'single'  => 'Single Room',
    'couples' => 'Couple Room',
    'family'  => 'Family Lounge',
    'all'     => 'All rooms',
];

/* Make sure columns used by book.php, save_booking.php & admin_dashboard.php exist */
function bv_ensure_col(PDO $pdo, string $table, string $col, string $ddl): void {
    try {
        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $col $ddl");
        }
    } catch (Throwable $e) {
        error_log("bv_ensure_col ($table.$col): " . $e->getMessage());
    }
}
bv_ensure_col($pdo, 'services', 'category', "VARCHAR(50) DEFAULT 'massage'");
bv_ensure_col($pdo, 'packages', 'room_type', "VARCHAR(30) DEFAULT 'all'");
bv_ensure_col($pdo, 'packages', 'max_guests', "INT DEFAULT 1");
bv_ensure_col($pdo, 'rooms', 'amenities', "TEXT NULL");
bv_ensure_col($pdo, 'appointments', 'room_id', "INT NULL");
bv_ensure_col($pdo, 'appointments', 'payment_plan', "VARCHAR(30) DEFAULT 'downpayment'");
bv_ensure_col($pdo, 'appointment_items', 'guest_no', "INT NULL");

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/* ---------- Helpers ---------- */
function bv_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function bv_money($v): string { return '₱' . number_format((float)$v, 2); }
function bv_flash(string $type, string $msg): void { $_SESSION['flash'] = [$type, $msg]; }
function bv_back(string $qs): void {
    $qs = preg_replace('/[^A-Za-z0-9=&_%\-\.]/', '', $qs);
    header('Location: admin_dashboard.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

/* Sign-in screen */
function bv_render_login(string $error, ?array $notice): void { ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin sign in | Bella Vista Oasis Spa</title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
<style>
* { box-sizing:border-box; }
body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px;
       background:linear-gradient(160deg,#f7f1e8,#eadcc8); font-family:"Jost",system-ui,sans-serif; color:#3f2d20; }
.box { width:min(420px,100%); background:#fffdf9; border:1px solid #e8dccb; border-radius:28px; padding:40px 36px; box-shadow:0 24px 60px rgba(63,45,32,.14); }
.mark { width:54px; height:54px; margin:0 auto 14px; border-radius:50%; border:1px solid #cdbfae; display:flex; align-items:center; justify-content:center; color:#b58a52; font-size:22px; }
h1 { margin:0; text-align:center; font-family:"Cormorant Garamond",Georgia,serif; font-weight:600; font-size:30px; letter-spacing:.18em; }
.sub { text-align:center; font-size:12px; letter-spacing:.3em; color:#6f5f52; margin:4px 0 28px; }
label { display:block; font-size:13px; font-weight:500; margin-bottom:16px; }
input { width:100%; margin-top:6px; font:inherit; padding:13px 18px; border:1px solid #e8dccb; border-radius:999px; background:#fff; color:#3f2d20; }
input:focus { outline:2px solid rgba(100,133,104,.35); border-color:#648568; }
button { width:100%; height:44px; margin-top:8px; font:inherit; font-size:13px; font-weight:600; letter-spacing:.12em; text-transform:uppercase; border:0; border-radius:999px; background:#5a4030; color:#fff; cursor:pointer; }
button:hover { background:#46301f; }
.msg { padding:11px 16px; border-radius:14px; font-size:14px; margin-bottom:18px; }
.msg.err { background:#fbeceb; color:#b83232; border:1px solid #f0c9c6; }
.msg.ok { background:#eaf1e8; color:#3f6644; border:1px solid #cfe0cc; }
.back { display:block; text-align:center; margin-top:20px; font-size:13px; color:#6f5f52; text-decoration:none; }
</style>
</head>
<body>
<form class="box" method="post" autocomplete="off">
    <div class="mark">✦</div>
    <h1>BELLA VISTA</h1>
    <div class="sub">OASIS SPA · ADMIN</div>
    <?php if ($notice): ?><div class="msg <?= $notice[0] === 'success' ? 'ok' : 'err' ?>"><?= bv_h($notice[1]) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg err"><?= bv_h($error) ?></div><?php endif; ?>
    <input type="hidden" name="op" value="login">
    <label>Username<input type="text" name="username" required autofocus></label>
    <label>Password<input type="password" name="password" required></label>
    <button type="submit">Sign in</button>
    <a class="back" href="index.php">← Back to website</a>
</form>
</body>
</html>
<?php }

function bv_stats(PDO $pdo): array {
    $r = $pdo->query("SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'Pending'), 0) AS pending,
            COALESCE(SUM(status = 'Confirmed'), 0) AS confirmed,
            COALESCE(SUM(appointment_date = CURDATE() AND status NOT IN ('Cancelled', 'Rejected', 'Declined')), 0) AS today,
            COALESCE(SUM(payment_status = 'pending' AND payment_method IN ('ewallet', 'bank')), 0) AS pay_pending,
            COALESCE(SUM(CASE WHEN status IN ('Confirmed','Completed') THEN total_price END), 0) AS revenue,
            COALESCE(MAX(id), 0) AS latest
        FROM appointments")->fetch();
    return [
        'total'       => (int)($r['total'] ?? 0),
        'pending'     => (int)($r['pending'] ?? 0),
        'confirmed'   => (int)($r['confirmed'] ?? 0),
        'today'       => (int)($r['today'] ?? 0),
        'pay_pending' => (int)($r['pay_pending'] ?? 0),
        'revenue'     => bv_money($r['revenue'] ?? 0),
        'latest'      => (int)($r['latest'] ?? 0),
    ];
}

function bv_items_for(PDO $pdo, array $ids): array {
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $hasGuestNo = false;
    try {
        $hasGuestNo = (bool)$pdo->query("SHOW COLUMNS FROM appointment_items LIKE 'guest_no'")->fetch();
    } catch (PDOException $e) {}
    $cols = $hasGuestNo
        ? 'appointment_id, item_type, item_id, item_name, guest_no'
        : 'appointment_id, item_type, item_id, item_name, NULL AS guest_no';
    $st = $pdo->prepare("SELECT $cols FROM appointment_items WHERE appointment_id IN ($in) ORDER BY id");
    $st->execute(array_values($ids));
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['appointment_id']][] = $r;
    }
    return $out;
}

function bv_slot_taken(PDO $pdo, string $date, string $time, int $exceptId = 0, ?int $roomId = null): bool {
    if ($roomId && $roomId > 0) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND room_id = ? AND status NOT IN ('Cancelled', 'Rejected', 'Declined') AND id <> ?");
        $st->execute([$date, $time . ':00', $roomId, $exceptId]);
        return (int)$st->fetchColumn() > 0;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND (room_id IS NULL OR room_id = 0) AND status NOT IN ('Cancelled', 'Rejected', 'Declined') AND id <> ?");
    $st->execute([$date, $time . ':00', $exceptId]);
    return (int)$st->fetchColumn() >= 3;
}

function bv_notify(PDO $pdo, int $id, string $status): array {
    if (!in_array($status, ['Confirmed', 'Cancelled', 'Completed'], true)) {
        return ['sent' => null, 'error' => ''];
    }
    $st = $pdo->prepare('SELECT booking_code, customer_name, email, appointment_date, appointment_time, total_price, amount_due, payment_method, payment_provider, payment_plan, reference_no FROM appointments WHERE id = ?');
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) return ['sent' => null, 'error' => 'Booking record not found.'];
    if (empty($a['email']) || !filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'error' => 'Invalid or missing guest email.'];
    }
    try {
        if (!function_exists('bv_email_status_changed') || !function_exists('bv_send_mail')) {
            return ['sent' => false, 'error' => 'Mailer helper functions not found in mailer.php.'];
        }
        [$subject, $html, $text] = bv_email_status_changed([
            'code'             => $a['booking_code'],
            'name'             => $a['customer_name'],
            'date'             => $a['appointment_date'],
            'time'             => substr($a['appointment_time'], 0, 5),
            'total'            => (float)($a['total_price'] ?? 0),
            'amount_due'       => (float)($a['amount_due'] ?? 0),
            'payment_method'   => $a['payment_method'] ?? 'cash',
            'payment_provider' => $a['payment_provider'] ?? '',
            'payment_plan'     => $a['payment_plan'] ?? 'downpayment',
            'reference_no'     => $a['reference_no'] ?? '',
        ], $status);
        $res = bv_send_mail($a['email'], $a['customer_name'], $subject, $html, $text);
        $sent = is_array($res) ? (bool)($res[0] ?? false) : (bool)$res;
        $errMsg = is_array($res) && !empty($res[1]) ? (string)$res[1] : '';
        if (!$sent) {
            error_log("bv_notify failed for {$a['booking_code']} ({$a['email']}): " . ($errMsg ?: 'Unknown mailer error'));
        }
        return ['sent' => $sent, 'error' => $errMsg];
    } catch (Throwable $e) {
        error_log("bv_notify exception for {$a['booking_code']}: " . $e->getMessage());
        return ['sent' => false, 'error' => $e->getMessage()];
    }
}

function bv_email_note(array $info): string {
    if ($info['sent'] === null) return '';
    if ($info['sent']) return ' Guest notified via email.';
    $detail = $info['error'] !== '' ? ' (' . $info['error'] . ')' : ' (check mailer.php / SMTP config)';
    return ' Email failed to send' . $detail . '.';
}

function bv_save_image(string $folder, int $id, array $file, ?string $old): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed.');
    if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('Max file size is 3 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) throw new RuntimeException('Use JPG, PNG, or WEBP.');
    $dir = __DIR__ . '/assets/images/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Could not create image folder.');
    $name = $id . '-' . bin2hex(random_bytes(3)) . '.' . $map[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save picture.');
    $prefix = 'assets/images/' . $folder . '/';
    if ($old && strpos($old, $prefix) === 0 && strpos($old, '..') === false) {
        @unlink(__DIR__ . '/' . $old);
    }
    return $prefix . $name;
}

/* Payment QR codes: one file per provider in assets/images/payments/<provider>.<ext> (book.php reads them from there) */
function bv_qr_find(string $provider): string {
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $rel = 'assets/images/payments/' . $provider . '.' . $ext;
        if (is_file(__DIR__ . '/' . $rel)) return $rel;
    }
    return '';
}
function bv_qr_remove(string $provider): void {
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $f = __DIR__ . '/assets/images/payments/' . $provider . '.' . $ext;
        if (is_file($f)) @unlink($f);
    }
}

/* Payment account details (shown to customers on book.php). Table is created automatically. */
function bv_pay_defaults(): array {
    return [
        'gcash'     => ['', 'Bella Vista Oasis Spa', '0917 000 0000'],
        'maya'      => ['', 'Bella Vista Oasis Spa', '0918 000 0000'],
        'bdo'       => ['BDO Unibank', 'Bella Vista Oasis Spa', '0000 0000 0000'],
        'bpi'       => ['Bank of the Philippine Islands', 'Bella Vista Oasis Spa', '0000 0000 00'],
        'unionbank' => ['UnionBank of the Philippines', 'Bella Vista Oasis Spa', '0000 0000 0000'],
    ];
}
function bv_pay_accounts(PDO $pdo): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_accounts (
        provider VARCHAR(20) NOT NULL PRIMARY KEY,
        bank_name VARCHAR(80) NOT NULL DEFAULT '',
        account_name VARCHAR(80) NOT NULL DEFAULT '',
        account_number VARCHAR(40) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ins = $pdo->prepare('INSERT IGNORE INTO payment_accounts (provider, bank_name, account_name, account_number) VALUES (?,?,?,?)');
    foreach (bv_pay_defaults() as $p => $d) $ins->execute([$p, $d[0], $d[1], $d[2]]);
    $out = [];
    foreach ($pdo->query('SELECT * FROM payment_accounts')->fetchAll() as $r) $out[$r['provider']] = $r;
    return $out;
}

function bv_remove_file(?string $path, string $prefix): void {
    if ($path && strpos($path, $prefix) === 0 && strpos($path, '..') === false) {
        @unlink(__DIR__ . '/' . $path);
    }
}

/* ---------- Live stats ---------- */
if (($_GET['ajax'] ?? '') === 'stats') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(bv_stats($pdo));
    exit;
}

/* ---------- Actions (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = $_POST['back'] ?? 'tab=overview';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        bv_flash('error', 'Session expired. Try again.');
        bv_back($back);
    }
    $op = $_POST['op'] ?? '';

    if ($op === 'logout') {
        unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['csrf']);
        session_regenerate_id(true);
        bv_flash('success', 'Logged out.');
        header('Location: admin_dashboard.php');
        exit;
    }

    try {
        /* ----- quick status changes ----- */
        if ($op === 'set_status') {
            $id = (int)($_POST['appointment_id'] ?? 0);
            $status = $_POST['booking_status'] ?? '';
            if (!$id || !in_array($status, $statuses, true)) {
                bv_flash('error', 'Invalid status.');
                bv_back($back);
            }
            $row = $pdo->prepare('SELECT appointment_date, appointment_time, room_id FROM appointments WHERE id = ?');
            $row->execute([$id]);
            $cur = $row->fetch();
            if (!$cur) {
                bv_flash('error', 'Booking not found.');
                bv_back($back);
            }
            $rId = isset($cur['room_id']) && (int)$cur['room_id'] > 0 ? (int)$cur['room_id'] : null;
            if (!in_array($status, ['Cancelled', 'Rejected', 'Declined'], true) && bv_slot_taken($pdo, $cur['appointment_date'], substr($cur['appointment_time'], 0, 5), $id, $rId)) {
                bv_flash('error', 'Time slot is already taken.');
                bv_back($back);
            }
            $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $id]);
            $notifyInfo = bv_notify($pdo, $id, $status);
            bv_flash($notifyInfo['sent'] === false ? 'error' : 'success', 'Status updated to ' . $status . '.' . bv_email_note($notifyInfo));
            bv_back($back);
        }

        if ($op === 'set_pay_status') {
            $id = (int)($_POST['appointment_id'] ?? 0);
            $ps = $_POST['pay_status'] ?? '';
            if ($id && in_array($ps, $payStatuses, true)) {
                $pdo->prepare('UPDATE appointments SET payment_status = ? WHERE id = ?')->execute([$ps, $id]);
                $msg = 'Payment marked as ' . $ps . '.';
                $notifyInfo = ['sent' => null, 'error' => ''];
                if ($ps === 'verified') {
                    $cur = $pdo->prepare('SELECT status, appointment_date, appointment_time, room_id FROM appointments WHERE id = ?');
                    $cur->execute([$id]);
                    $c = $cur->fetch();
                    $rId = isset($c['room_id']) && (int)$c['room_id'] > 0 ? (int)$c['room_id'] : null;
                    if ($c && $c['status'] === 'Pending' && !bv_slot_taken($pdo, $c['appointment_date'], substr($c['appointment_time'], 0, 5), $id, $rId)) {
                        $pdo->prepare("UPDATE appointments SET status = 'Confirmed' WHERE id = ?")->execute([$id]);
                        $notifyInfo = bv_notify($pdo, $id, 'Confirmed');
                        $msg = 'Payment verified & booking confirmed.' . bv_email_note($notifyInfo);
                    }
                }
                bv_flash($notifyInfo['sent'] === false ? 'error' : 'success', $msg);
            }
            bv_back($back);
        }

        /* ----- create / update appointment ----- */
        if ($op === 'save_appointment') {
            $id        = (int)($_POST['appointment_id'] ?? 0);
            $name      = trim($_POST['customer_name'] ?? '');
            $email     = trim($_POST['email'] ?? '');
            $phone     = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
            $date      = $_POST['appointment_date'] ?? '';
            $time      = $_POST['appointment_time'] ?? '';
            $guests    = filter_var($_POST['guests'] ?? '', FILTER_VALIDATE_INT);
            $roomId    = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
            $notes     = trim($_POST['notes'] ?? '');
            $status    = $_POST['booking_status'] ?? 'Pending';
            $method    = $_POST['pay_method'] ?? 'cash';
            $plan      = $_POST['pay_plan'] ?? 'downpayment';
            $provider  = $_POST['pay_provider'] ?? '';
            $payStatus = $_POST['pay_status'] ?? 'unpaid';
            $refNo     = trim($_POST['pay_ref'] ?? '');

            $err = '';
            if ($name === '' || mb_strlen($name) > 120 || !preg_match("/^\p{L}[\p{L}\s.'-]*$/u", $name)) $err = 'Enter a valid guest name.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $err = 'Enter a valid email address.';
            elseif (!preg_match('/^(09\d{9}|639\d{9})$/', $phone)) $err = 'Phone must be 09XXXXXXXXX.';
            elseif (!$guests || $guests < 1 || $guests > BV_MAX_GUESTS) $err = 'Guests must be 1 to ' . BV_MAX_GUESTS . '.';
            elseif (mb_strlen($notes) > 1000) $err = 'Notes cannot exceed 1000 characters.';
            elseif (!in_array($status, $statuses, true)) $err = 'Invalid booking status.';
            elseif (!in_array($method, ['cash', 'ewallet', 'bank'], true)) $err = 'Invalid payment method.';
            elseif (!in_array($payStatus, $payStatuses, true)) $err = 'Invalid payment status.';
            elseif ($refNo !== '' && !preg_match('/^[A-Za-z0-9-]{4,25}$/', $refNo)) $err = 'Reference must be 4–25 alphanumeric characters.';

            $dateObj = $err === '' ? DateTime::createFromFormat('Y-m-d', $date) : null;
            if ($err === '' && (!$dateObj || $dateObj->format('Y-m-d') !== $date)) $err = 'Choose a valid date.';
            if ($err === '' && (int)$dateObj->format('N') === 1) $err = 'Closed on Mondays.';
            if ($err === '' && !in_array($time, BV_SLOTS, true)) $err = 'Choose a valid time slot.';

            $orig = null;
            if ($err === '' && $id) {
                $st = $pdo->prepare('SELECT appointment_date, appointment_time, status FROM appointments WHERE id = ?');
                $st->execute([$id]);
                $orig = $st->fetch();
                if (!$orig) $err = 'Booking not found.';
            }
            $rIdCheck = ($roomId && $roomId > 0) ? (int)$roomId : null;
            if ($err === '') {
                $changed = !$orig || $orig['appointment_date'] !== $date || substr($orig['appointment_time'], 0, 5) !== $time;
                if ($changed && $date < date('Y-m-d')) $err = 'Cannot book a past date.';
                elseif ($changed && $date === date('Y-m-d') && $time <= date('H:i')) $err = 'Selected time has already passed.';
                elseif ($status !== 'Cancelled' && bv_slot_taken($pdo, $date, $time, $id, $rIdCheck)) $err = 'Time slot is already booked.';
            }
            if ($err === '' && $method !== 'cash') {
                if (!isset($providers[$method][$provider])) $err = 'Choose the e-wallet or bank used.';
                if (!in_array($plan, ['downpayment', 'full'], true)) $plan = 'downpayment';
            } else {
                if ($method === 'cash') {
                    $provider = '';
                    $plan = 'store';
                }
            }

            /* Room check */
            $room = null;
            if ($err === '' && $roomId && $roomId > 0) {
                try {
                    $st = $pdo->prepare('SELECT id, name, min_guests, max_guests, price FROM rooms WHERE id = ?');
                    $st->execute([$roomId]);
                    $room = $st->fetch();
                    if (!$room) $err = 'Selected room not found.';
                    else {
                        $maxCap = stripos($room['name'], 'family') !== false ? max(6, (int)$room['max_guests']) : (int)$room['max_guests'];
                        if ($guests < (int)$room['min_guests'] || $guests > $maxCap) {
                            $err = $room['name'] . ' fits ' . (int)$room['min_guests'] . ' to ' . $maxCap . ' guest(s).';
                        }
                    }
                } catch (PDOException $e) {}
            }

            /* Services, packages, add-ons (1 line per guest for services/packages, synced with save_booking.php) */
            $items = [];
            $addonItems = [];
            if ($err === '') {
                $sel = ['service' => [], 'package' => [], 'addon' => []];
                foreach ((array)($_POST['items'] ?? []) as $v) {
                    if (preg_match('/^(service|package|addon):(\d+)$/', (string)$v, $m)) $sel[$m[1]][(int)$m[2]] = (int)$m[2];
                }
                foreach (['service', 'package'] as $type) {
                    $ids = $sel[$type];
                    if (!$ids) continue;
                    $tbl = $kinds[$type];
                    try {
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        $st = $pdo->prepare("SELECT id, name, price, duration_minutes FROM $tbl WHERE id IN ($in)");
                        $st->execute(array_values($ids));
                        $rows = $st->fetchAll();
                    } catch (PDOException $e) {
                        $rows = [];
                    }
                    if (count($rows) !== count($ids)) { $err = 'A selected item no longer exists.'; break; }
                    foreach ($rows as $r) {
                        $unitPrice = round((float)$r['price'], 2);
                        for ($g = 1; $g <= $guests; $g++) {
                            $items[] = [
                                'guest_no'   => $g,
                                'type'       => $type,
                                'id'         => (int)$r['id'],
                                'name'       => $r['name'],
                                'price'      => $unitPrice,
                                'duration'   => (int)$r['duration_minutes'],
                                'line_total' => $unitPrice,
                            ];
                        }
                    }
                }
                if ($err === '' && $sel['addon']) {
                    try {
                        $ids = $sel['addon'];
                        $in = implode(',', array_fill(0, count($ids), '?'));
                        $st = $pdo->prepare("SELECT id, name, price FROM addons WHERE id IN ($in)");
                        $st->execute(array_values($ids));
                        foreach ($st->fetchAll() as $r) {
                            $p = round((float)$r['price'], 2);
                            $addonItems[] = [
                                'guest_no'   => null,
                                'type'       => 'addon',
                                'id'         => (int)$r['id'],
                                'name'       => $r['name'] . ' (add-on)',
                                'price'      => $p,
                                'duration'   => 0,
                                'line_total' => $p,
                            ];
                        }
                    } catch (PDOException $e) {}
                }
                if ($err === '' && !$items) $err = 'Select at least one service or package.';
            }

            if ($err !== '') {
                bv_flash('error', $err);
                bv_back($back);
            }

            $total = 0.0;
            foreach ($items as $it) {
                $total += $it['line_total'];
            }

            $extras = [];
            if ($room && (float)$room['price'] > 0) {
                $rfee = round((float)$room['price'], 2);
                $extras[] = [
                    'guest_no'   => null,
                    'type'       => 'room',
                    'id'         => (int)$room['id'],
                    'name'       => $room['name'] . ' (room fee)',
                    'price'      => $rfee,
                    'duration'   => 0,
                    'line_total' => $rfee,
                ];
            }
            $extras = array_merge($extras, $addonItems);
            foreach ($extras as $ex) {
                $total += $ex['line_total'];
            }
            $total = round($total, 2);
            $allItems = array_merge($items, $extras);

            if ($method === 'cash') {
                $amountDue = 0.00;
            } else {
                $rate = ($plan === 'full') ? 1.00 : BV_DOWN_RATE;
                $amountDue = round($total * $rate, 2);
            }

            $firstService = null;
            foreach ($items as $it) { if ($it['type'] === 'service') { $firstService = $it['id']; break; } }

            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE appointments SET customer_name=?, email=?, phone=?, service_id=?, appointment_date=?, appointment_time=?, guests=?, notes=?, total_price=?, payment_method=?, payment_provider=?, payment_status=?, amount_due=?, reference_no=?, status=? WHERE id=?')
                    ->execute([$name, $email, $phone, $firstService, $date, $time . ':00', $guests, $notes ?: null, $total, $method, $provider ?: null, $payStatus, $amountDue, $refNo ?: null, $status, $id]);
                $pdo->prepare('DELETE FROM appointment_items WHERE appointment_id = ?')->execute([$id]);
                $msg = 'Booking updated.';
            } else {
                $code = 'BV' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
                $pdo->prepare('INSERT INTO appointments (booking_code, customer_name, email, phone, service_id, appointment_date, appointment_time, guests, notes, total_price, payment_method, payment_provider, payment_status, amount_due, reference_no, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$code, $name, $email, $phone, $firstService, $date, $time . ':00', $guests, $notes ?: null, $total, $method, $provider ?: null, $payStatus, $amountDue, $refNo ?: null, $status]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Booking ' . $code . ' created.';
            }
            try {
                $pdo->prepare('UPDATE appointments SET room_id = ? WHERE id = ?')->execute([$room ? (int)$room['id'] : null, $id]);
            } catch (PDOException $e) {}
            try {
                $hasPlan = (bool)$pdo->query("SHOW COLUMNS FROM appointments LIKE 'payment_plan'")->fetch();
                if ($hasPlan) {
                    $pdo->prepare('UPDATE appointments SET payment_plan = ? WHERE id = ?')->execute([$plan, $id]);
                }
            } catch (PDOException $e) {}

            $hasGuestCol = false;
            try { $hasGuestCol = (bool)$pdo->query("SHOW COLUMNS FROM appointment_items LIKE 'guest_no'")->fetch(); } catch (PDOException $e) {}

            if ($hasGuestCol) {
                $ins = $pdo->prepare('INSERT INTO appointment_items (appointment_id, guest_no, item_type, item_id, item_name, unit_price, duration_minutes, line_total) VALUES (?,?,?,?,?,?,?,?)');
                foreach ($allItems as $it) {
                    $ins->execute([$id, $it['guest_no'], $it['type'], $it['id'], $it['name'], $it['price'], $it['duration'], $it['line_total']]);
                }
            } else {
                $ins = $pdo->prepare('INSERT INTO appointment_items (appointment_id, item_type, item_id, item_name, unit_price, duration_minutes, line_total) VALUES (?,?,?,?,?,?,?)');
                foreach ($allItems as $it) {
                    $ins->execute([$id, $it['type'], $it['id'], $it['name'], $it['price'], $it['duration'], $it['line_total']]);
                }
            }
            $pdo->commit();

            $notifyInfo = ['sent' => null, 'error' => ''];
            if (in_array($status, ['Confirmed', 'Cancelled', 'Completed'], true) && (!$orig || $orig['status'] !== $status)) {
                $notifyInfo = bv_notify($pdo, $id, $status);
            }
            bv_flash($notifyInfo['sent'] === false ? 'error' : 'success', $msg . bv_email_note($notifyInfo));
            bv_back($back);
        }

        /* ----- delete appointment ----- */
        if ($op === 'delete_appointment') {
            $id = (int)($_POST['appointment_id'] ?? 0);
            $st = $pdo->prepare('SELECT proof_image FROM appointments WHERE id = ?');
            $st->execute([$id]);
            $proof = $st->fetchColumn();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM appointment_items WHERE appointment_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
            $pdo->commit();
            bv_remove_file($proof ?: null, 'uploads/payment_proofs/');
            bv_flash('success', 'Booking deleted.');
            bv_back($back);
        }

        /* ----- create / update room ----- */
        /* ----- payment QR codes ----- */
        if ($op === 'save_account') {
            $prov = (string)($_POST['provider'] ?? '');
            $known = array_merge(array_keys($providers['ewallet']), array_keys($providers['bank']));
            if (!in_array($prov, $known, true)) throw new RuntimeException('Unknown payment provider.');
            $aname = trim(preg_replace('/\s+/', ' ', (string)($_POST['account_name'] ?? '')));
            $anum  = trim(preg_replace('/\s+/', ' ', (string)($_POST['account_number'] ?? '')));
            $bname = trim(preg_replace('/\s+/', ' ', (string)($_POST['bank_name'] ?? '')));
            if (mb_strlen($aname) < 2 || mb_strlen($aname) > 80) throw new RuntimeException('Account name must be 2 to 80 characters.');
            if (!preg_match('/^[0-9 +\-]{5,40}$/', $anum)) throw new RuntimeException('Account number: use digits, spaces, + or - only (5 to 40 characters).');
            if (mb_strlen($bname) > 80) throw new RuntimeException('Bank name is too long.');
            if (isset($providers['bank'][$prov]) && $bname === '') throw new RuntimeException('Bank name is required for banks.');
            if (isset($providers['ewallet'][$prov])) $bname = '';
            bv_pay_accounts($pdo);
            $pdo->prepare('UPDATE payment_accounts SET bank_name = ?, account_name = ?, account_number = ? WHERE provider = ?')->execute([$bname, $aname, $anum, $prov]);
            bv_flash('success', 'Account details saved.');
            bv_back($back);
        }

        if ($op === 'save_qr' || $op === 'delete_qr') {
            $prov = (string)($_POST['provider'] ?? '');
            $known = array_merge(array_keys($providers['ewallet']), array_keys($providers['bank']));
            if (!in_array($prov, $known, true)) throw new RuntimeException('Unknown payment provider.');
            if ($op === 'delete_qr') {
                bv_qr_remove($prov);
                bv_flash('success', 'QR code removed.');
                bv_back($back);
            }
            $file = $_FILES['qr'] ?? ['error' => UPLOAD_ERR_NO_FILE];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a QR code image first.');
            if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed.');
            if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('Max file size is 3 MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($map[$mime])) throw new RuntimeException('Use JPG, PNG, or WEBP.');
            $dir = __DIR__ . '/assets/images/payments';
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the payments folder.');
            bv_qr_remove($prov);   // replace: remove the old QR (any extension) so only the new one is used
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $prov . '.' . $map[$mime])) throw new RuntimeException('Could not save the QR code.');
            bv_flash('success', 'QR code saved.');
            bv_back($back);
        }

        if ($op === 'save_room') {
            $id     = (int)($_POST['room_id'] ?? 0);
            $rname  = trim($_POST['room_name'] ?? '');
            $desc   = trim($_POST['description'] ?? '');
            $minG   = filter_var($_POST['min_guests'] ?? '', FILTER_VALIDATE_INT);
            $maxG   = filter_var($_POST['max_guests'] ?? '', FILTER_VALIDATE_INT);
            $dur    = filter_var($_POST['duration'] ?? '', FILTER_VALIDATE_INT);
            $price  = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $amenList = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)($_POST['amenities'] ?? ''))), 'strlen');
            $amen   = implode(',', array_unique($amenList));

            if ($rname === '' || mb_strlen($rname) > 120) { bv_flash('error', 'Enter a room name (max 120 chars).'); bv_back($back); }
            if (!$minG || !$maxG || $minG < 1 || $maxG < $minG || $maxG > BV_MAX_GUESTS) { bv_flash('error', 'Guest capacity must be 1 to ' . BV_MAX_GUESTS . '.'); bv_back($back); }
            if (!$dur || $dur < 5 || $dur > 600) { bv_flash('error', 'Duration must be 5 to 600 mins.'); bv_back($back); }
            if ($price === false || $price < 0 || $price > 1000000) { bv_flash('error', 'Enter a valid room fee.'); bv_back($back); }

            $old = null;
            if ($id) {
                $st = $pdo->prepare('SELECT image FROM rooms WHERE id = ?');
                $st->execute([$id]);
                $found = $st->fetch();
                if (!$found) { bv_flash('error', 'Room not found.'); bv_back($back); }
                $old = $found['image'] ?? null;
                $pdo->prepare('UPDATE rooms SET name = ?, description = ?, amenities = ?, min_guests = ?, max_guests = ?, duration_minutes = ?, price = ?, active = ? WHERE id = ?')
                    ->execute([$rname, $desc, $amen, $minG, $maxG, $dur, $price, $active, $id]);
                $msg = 'Room updated.';
            } else {
                $pdo->prepare('INSERT INTO rooms (name, description, amenities, min_guests, max_guests, duration_minutes, price, active) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$rname, $desc, $amen, $minG, $maxG, $dur, $price, $active]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Room added.';
            }
            try {
                $path = bv_save_image('rooms', $id, $_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE], $old);
                if ($path) $pdo->prepare('UPDATE rooms SET image = ? WHERE id = ?')->execute([$path, $id]);
            } catch (RuntimeException $e) {
                bv_flash('error', 'Room saved, but image failed: ' . $e->getMessage());
                bv_back($back);
            }
            bv_flash('success', $msg);
            bv_back($back);
        }

        if ($op === 'toggle_room' || $op === 'delete_room') {
            $id = (int)($_POST['room_id'] ?? 0);
            if (!$id) { bv_flash('error', 'Invalid request.'); bv_back($back); }
            if ($op === 'toggle_room') {
                $pdo->prepare('UPDATE rooms SET active = 1 - active WHERE id = ?')->execute([$id]);
                bv_flash('success', 'Room visibility updated.');
            } else {
                $st = $pdo->prepare('SELECT image FROM rooms WHERE id = ?');
                $st->execute([$id]);
                $img = $st->fetchColumn();
                try {
                    $pdo->prepare('DELETE FROM rooms WHERE id = ?')->execute([$id]);
                    bv_remove_file($img ?: null, 'assets/images/rooms/');
                    bv_flash('success', 'Room deleted.');
                } catch (PDOException $e) {
                    bv_flash('error', 'Room is linked to bookings. Deactivate it instead.');
                }
            }
            bv_back($back);
        }

        /* ----- create / update service, package, or add-on ----- */
        if ($op === 'save_item') {
            $kind = $_POST['kind'] ?? '';
            if (!isset($kinds[$kind])) { bv_flash('error', 'Unknown item type.'); bv_back($back); }
            $table = $kinds[$kind];
            $label = $kind === 'package' ? 'Spa Package' : ($kind === 'addon' ? 'Add-on' : 'Service');
            $id    = (int)($_POST['item_id'] ?? 0);
            $iname = trim($_POST['item_name'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $dur   = filter_var($_POST['duration'] ?? 60, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($iname === '' || mb_strlen($iname) > 120) { bv_flash('error', 'Enter a name (max 120 chars).'); bv_back($back); }
            if (mb_strlen($desc) > 1000) { bv_flash('error', 'Description max 1000 chars.'); bv_back($back); }
            if ($kind !== 'addon' && (!$dur || $dur < 5 || $dur > 600)) { bv_flash('error', 'Duration must be 5 to 600 mins.'); bv_back($back); }
            if ($price === false || $price < 0 || $price > 1000000) { bv_flash('error', 'Enter a valid price.'); bv_back($back); }

            $cat = strtolower(trim((string)($_POST['category'] ?? 'massage')));
            if ($kind === 'service' && !isset($serviceCategories[$cat])) { bv_flash('error', 'Choose a valid category.'); bv_back($back); }
            $rtype = strtolower(trim((string)($_POST['room_type'] ?? 'all')));
            if ($kind === 'package' && !isset($packageRoomTypes[$rtype])) { bv_flash('error', 'Choose a valid room type.'); bv_back($back); }
            $pmax = filter_var($_POST['max_guests'] ?? 1, FILTER_VALIDATE_INT);
            if ($kind === 'package' && (!$pmax || $pmax < 1 || $pmax > BV_MAX_GUESTS)) { bv_flash('error', 'Package guests must be 1 to ' . BV_MAX_GUESTS . '.'); bv_back($back); }

            if ($kind === 'addon') {
                if ($id) {
                    $pdo->prepare('UPDATE addons SET name = ?, description = ?, price = ?, active = ? WHERE id = ?')
                        ->execute([$iname, $desc, $price, $active, $id]);
                    $msg = 'Add-on updated.';
                } else {
                    $pdo->prepare('INSERT INTO addons (name, description, price, active) VALUES (?,?,?,?)')
                        ->execute([$iname, $desc, $price, $active]);
                    $msg = 'Add-on added.';
                }
                bv_flash('success', $msg);
                bv_back($back);
            }

            $old = null;
            if ($id) {
                $st = $pdo->prepare("SELECT image FROM $table WHERE id = ?");
                $st->execute([$id]);
                $found = $st->fetch();
                if (!$found) { bv_flash('error', $label . ' not found.'); bv_back($back); }
                $old = $found['image'] ?? null;
                if ($kind === 'service') {
                    $pdo->prepare("UPDATE services SET name = ?, category = ?, description = ?, duration_minutes = ?, price = ?, active = ? WHERE id = ?")
                        ->execute([$iname, $cat, $desc, $dur, $price, $active, $id]);
                } else {
                    $pdo->prepare("UPDATE packages SET name = ?, room_type = ?, max_guests = ?, description = ?, duration_minutes = ?, price = ?, active = ? WHERE id = ?")
                        ->execute([$iname, $rtype, $pmax, $desc, $dur, $price, $active, $id]);
                }
                $msg = $label . ' updated.';
            } else {
                if ($kind === 'service') {
                    $pdo->prepare("INSERT INTO services (name, category, description, duration_minutes, price, active) VALUES (?,?,?,?,?,?)")
                        ->execute([$iname, $cat, $desc, $dur, $price, $active]);
                } else {
                    $pdo->prepare("INSERT INTO packages (name, room_type, max_guests, description, duration_minutes, price, active) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$iname, $rtype, $pmax, $desc, $dur, $price, $active]);
                }
                $id = (int)$pdo->lastInsertId();
                $msg = $label . ' added.';
            }
            try {
                $path = bv_save_image($table, $id, $_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE], $old);
                if ($path) $pdo->prepare("UPDATE $table SET image = ? WHERE id = ?")->execute([$path, $id]);
            } catch (RuntimeException $e) {
                bv_flash('error', $label . ' saved, but image failed: ' . $e->getMessage());
                bv_back($back);
            }
            bv_flash('success', $msg);
            bv_back($back);
        }

        /* ----- admin accounts ----- */
        if ($op === 'save_admin') {
            $id       = (int)($_POST['admin_id'] ?? 0);
            $username = trim($_POST['admin_username'] ?? '');
            $password = (string)($_POST['admin_password'] ?? '');
            $confirm  = (string)($_POST['admin_password_confirm'] ?? '');

            $err = '';
            if (!$id && !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) $err = 'Username must be 3–50 characters.';
            elseif (strlen($password) < 8) $err = 'Password must be at least 8 characters.';
            elseif ($password !== $confirm) $err = 'Passwords do not match.';
            if ($err === '' && !$id) {
                $dup = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
                $dup->execute([$username]);
                if ((int)$dup->fetchColumn() > 0) $err = 'Username already taken.';
            }
            if ($err !== '') { bv_flash('error', $err); bv_back($back); }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($id) {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
                bv_flash('success', 'Password updated.');
            } else {
                $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')->execute([$username, $hash]);
                bv_flash('success', 'Admin "' . $username . '" added.');
            }
            bv_back($back);
        }

        if ($op === 'delete_admin') {
            $id = (int)($_POST['admin_id'] ?? 0);
            if ($id === (int)$_SESSION['admin_id']) {
                bv_flash('error', 'Cannot delete your active account.');
            } elseif ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() <= 1) {
                bv_flash('error', 'At least one admin must remain.');
            } else {
                $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
                bv_flash('success', 'Admin deleted.');
            }
            bv_back($back);
        }

        if ($op === 'toggle_item' || $op === 'delete_item') {
            $kind = $_POST['kind'] ?? '';
            $id = (int)($_POST['item_id'] ?? 0);
            if (!isset($kinds[$kind]) || !$id) { bv_flash('error', 'Invalid request.'); bv_back($back); }
            $table = $kinds[$kind];
            if ($op === 'toggle_item') {
                $pdo->prepare("UPDATE $table SET active = 1 - active WHERE id = ?")->execute([$id]);
                bv_flash('success', 'Visibility updated.');
            } else {
                $img = null;
                if ($kind !== 'addon') {
                    $st = $pdo->prepare("SELECT image FROM $table WHERE id = ?");
                    $st->execute([$id]);
                    $img = $st->fetchColumn();
                }
                try {
                    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
                    if ($img) bv_remove_file($img, 'assets/images/' . $table . '/');
                    bv_flash('success', 'Deleted.');
                } catch (PDOException $e) {
                    bv_flash('error', 'Item is linked to bookings. Deactivate it instead.');
                }
            }
            bv_back($back);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_dashboard: ' . $e->getMessage());
        $local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
        bv_flash('error', 'Error saving changes.' . ($local ? ' [' . $e->getMessage() . ']' : ''));
        bv_back($back);
    }
    bv_back($back);
}

/* ---------- Page data ---------- */
$validTabs = ['overview', 'appointments', 'rooms', 'services', 'packages', 'addons', 'payments', 'admins', 'preview'];
$tab = in_array($_GET['tab'] ?? '', $validTabs, true) ? $_GET['tab'] : 'overview';
$fSvcCat = isset($_GET['cat']) && isset($serviceCategories[$_GET['cat']]) ? $_GET['cat'] : 'all';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stats = bv_stats($pdo);
$services = $pdo->query('SELECT * FROM services ORDER BY category, name')->fetchAll();
try {
    $packages = $pdo->query('SELECT * FROM packages ORDER BY room_type, price')->fetchAll();
    $hasPackages = true;
} catch (PDOException $e) {
    $packages = [];
    $hasPackages = false;
}
try {
    $rooms = $pdo->query('SELECT * FROM rooms ORDER BY min_guests ASC, price ASC')->fetchAll();
    $hasRooms = true;
} catch (PDOException $e) {
    $rooms = [];
    $hasRooms = false;
}
try {
    $addons = $pdo->query('SELECT * FROM addons ORDER BY name')->fetchAll();
    $hasAddons = true;
} catch (PDOException $e) {
    $addons = [];
    $hasAddons = false;
}

$payAccounts = [];
if ($tab === 'payments') {
    try { $payAccounts = bv_pay_accounts($pdo); } catch (Throwable $e) { $payAccounts = []; }
}

$admins = [];
if ($tab === 'admins') {
    $admins = $pdo->query('SELECT id, username, created_at FROM admins ORDER BY id')->fetchAll();
}

$upcoming = $attention = [];
if ($tab === 'overview') {
    $upcoming = $pdo->query("SELECT * FROM appointments WHERE appointment_date >= CURDATE() AND status IN ('Pending','Confirmed') ORDER BY appointment_date, appointment_time LIMIT 8")->fetchAll();
    $attention = $pdo->query("SELECT * FROM appointments WHERE status = 'Pending' OR payment_status = 'pending' ORDER BY id DESC LIMIT 8")->fetchAll();
}

$rows = $itemsMap = [];
$page = 1; $pages = 1; $totalRows = 0;
$q = trim($_GET['q'] ?? '');
$fStatus = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$fPay = in_array($_GET['pay'] ?? '', $payStatuses, true) ? $_GET['pay'] : '';
$fMethod = in_array($_GET['method'] ?? '', ['cash', 'ewallet', 'bank'], true) ? $_GET['method'] : '';
$fDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
if ($tab === 'appointments') {
    $where = ['1=1']; $params = [];
    if ($q !== '') {
        $where[] = '(a.booking_code LIKE ? OR a.customer_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ? OR a.reference_no LIKE ?)';
        for ($i = 0; $i < 5; $i++) $params[] = '%' . $q . '%';
    }
    if ($fStatus) { $where[] = 'a.status = ?'; $params[] = $fStatus; }
    if ($fPay) { $where[] = 'a.payment_status = ?'; $params[] = $fPay; }
    if ($fMethod) { $where[] = 'a.payment_method = ?'; $params[] = $fMethod; }
    if ($fDate) { $where[] = 'a.appointment_date = ?'; $params[] = $fDate; }
    $whereSql = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM appointments a WHERE $whereSql");
    $cnt->execute($params);
    $totalRows = (int)$cnt->fetchColumn();
    $per = 10;
    $pages = max(1, (int)ceil($totalRows / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $off = ($page - 1) * $per;

    $hasRoomCol = false;
    try { $hasRoomCol = (bool)$pdo->query("SHOW COLUMNS FROM appointments LIKE 'room_id'")->fetch(); } catch (PDOException $e) {}
    $sql = $hasRoomCol
        ? "SELECT a.*, s.name AS service_name, r.name AS room_name FROM appointments a LEFT JOIN services s ON s.id = a.service_id LEFT JOIN rooms r ON r.id = a.room_id WHERE $whereSql ORDER BY a.id DESC LIMIT $per OFFSET $off"
        : "SELECT a.*, s.name AS service_name, NULL AS room_name FROM appointments a LEFT JOIN services s ON s.id = a.service_id WHERE $whereSql ORDER BY a.id DESC LIMIT $per OFFSET $off";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $itemsMap = bv_items_for($pdo, array_column($rows, 'id'));
}

$filterQs = http_build_query(array_filter(['q' => $q, 'status' => $fStatus, 'pay' => $fPay, 'method' => $fMethod, 'date' => $fDate]));
$backQs = 'tab=' . $tab . ($tab === 'appointments' ? '&' . $filterQs . '&page=' . $page : ($tab === 'services' && $fSvcCat !== 'all' ? '&cat=' . $fSvcCat : ''));
$todayLabel = date('l, F j, Y');

function bv_badge(string $status): string {
    return '<span class="badge b-' . strtolower($status) . '">' . bv_h($status) . '</span>';
}
function bv_pay_badge(string $s): string {
    return '<span class="badge p-' . bv_h($s) . '">' . bv_h(ucfirst($s)) . '</span>';
}
function bv_thumb(?string $img, string $fallback): string {
    if ($img) return '<img src="' . bv_h($img) . '" alt="" loading="lazy">';
    return '<span class="ph">' . $fallback . '</span>';
}

function bv_render_items_table_rows(array $list, string $kind, string $csrf, string $back): void {
    global $serviceCategories, $packageRoomTypes;
    $icon = $kind === 'package' ? '❀' : '✦';
    foreach ($list as $it) {
        $data = [
            'id' => (int)$it['id'], 'kind' => $kind, 'name' => $it['name'], 'description' => $it['description'] ?? '',
            'duration' => (int)$it['duration_minutes'], 'price' => (float)$it['price'], 'active' => (int)$it['active'], 'image' => $it['image'] ?? '',
            'category' => $it['category'] ?? 'massage', 'room_type' => $it['room_type'] ?? 'all', 'max_guests' => (int)($it['max_guests'] ?? 1)
        ];
        $tag = $kind === 'service'
            ? ($serviceCategories[strtolower((string)($it['category'] ?? 'massage'))] ?? 'Massage Services')
            : (($packageRoomTypes[strtolower((string)($it['room_type'] ?? 'all'))] ?? 'All rooms') . ' · up to ' . (int)($it['max_guests'] ?? 1) . ' guest' . ((int)($it['max_guests'] ?? 1) > 1 ? 's' : ''));
        echo '<tr>
            <td><div class="thumb">' . bv_thumb($it['image'] ?? '', $icon) . '</div></td>
            <td><strong>' . bv_h($it['name']) . '</strong> <span class="badge b-pending">' . bv_h($tag) . '</span><small class="clamp">' . bv_h($it['description'] ?? '') . '</small></td>
            <td>' . (int)$it['duration_minutes'] . ' min</td>
            <td><strong>' . bv_money($it['price']) . '</strong></td>
            <td>' . ($it['active'] ? '<span class="badge b-confirmed">Active</span>' : '<span class="badge b-cancelled">Hidden</span>') . '</td>
            <td class="right"><div class="actions">
                <button type="button" class="btn btn-sm" data-item="' . bv_h(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">Edit</button>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="' . bv_h($csrf) . '"><input type="hidden" name="back" value="' . bv_h($back) . '">
                    <input type="hidden" name="op" value="toggle_item"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">
                    <button class="btn btn-sm btn-ghost">' . ($it['active'] ? 'Deactivate' : 'Activate') . '</button>
                </form>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="' . bv_h($csrf) . '"><input type="hidden" name="back" value="' . bv_h($back) . '">
                    <input type="hidden" name="op" value="delete_item"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">
                    <button class="btn btn-sm btn-danger">Delete</button>
                </form>
            </div></td></tr>';
    }
}

function bv_render_items(array $list, string $kind, string $csrf, string $back): void {
    if (!$list) {
        echo '<div class="empty-state">Nothing here yet. Click Add to create one.</div>';
        return;
    }
    echo '<div class="card table-card"><div class="table-wrap"><table class="tbl"><thead><tr>
        <th>Picture</th><th>Name &amp; description</th><th>Duration</th><th>Price</th><th>Status</th><th class="right">Actions</th></tr></thead><tbody>';
    bv_render_items_table_rows($list, $kind, $csrf, $back);
    echo '</tbody></table></div></div>';
}

function bv_render_categorized_services(array $list, string $activeCat, string $csrf, string $back): void {
    global $serviceCategories;
    $grouped = array_fill_keys(array_keys($serviceCategories), []);
    foreach ($list as $s) {
        $c = strtolower(trim((string)($s['category'] ?? 'massage')));
        if (!isset($grouped[$c])) $c = 'massage';
        $grouped[$c][] = $s;
    }

    echo '<div class="cat-pills" role="tablist">';
    echo '<a class="cat-pill ' . ($activeCat === 'all' ? 'active' : '') . '" href="?tab=services">All Services <span class="cat-cnt">' . count($list) . '</span></a>';
    foreach ($serviceCategories as $ck => $cv) {
        $cnt = count($grouped[$ck]);
        echo '<a class="cat-pill ' . ($activeCat === $ck ? 'active' : '') . '" href="?tab=services&cat=' . bv_h($ck) . '">' . bv_h($cv) . ' <span class="cat-cnt">' . $cnt . '</span></a>';
    }
    echo '</div>';

    if (!$list) {
        echo '<div class="empty-state">No services yet. Click + Add service to create one.</div>';
        return;
    }

    $catsToShow = $activeCat === 'all' ? array_keys($serviceCategories) : [$activeCat];
    foreach ($catsToShow as $ck) {
        $itemsInCat = $grouped[$ck] ?? [];
        echo '<section class="cat-section">';
        echo '<div class="cat-head"><h2>' . bv_h($serviceCategories[$ck]) . '</h2><span>' . count($itemsInCat) . ' service' . (count($itemsInCat) === 1 ? '' : 's') . '</span></div>';
        if (!$itemsInCat) {
            echo '<div class="empty-state">No services in ' . bv_h($serviceCategories[$ck]) . ' yet.</div>';
        } else {
            echo '<div class="card table-card"><div class="table-wrap"><table class="tbl"><thead><tr>
                <th>Picture</th><th>Name &amp; description</th><th>Duration</th><th>Price</th><th>Status</th><th class="right">Actions</th></tr></thead><tbody>';
            bv_render_items_table_rows($itemsInCat, 'service', $csrf, $back);
            echo '</tbody></table></div></div>';
        }
        echo '</section>';
    }
}

function bv_item_names(array $a, array $itemsMap): string {
    $lines = [];
    $guests = (int)($a['guests'] ?? 1);
    foreach ($itemsMap[(int)$a['id']] ?? [] as $r) {
        if ($r['item_type'] === 'room') continue;
        $prefix = (!empty($r['guest_no']) && $guests > 1) ? 'G' . (int)$r['guest_no'] . ': ' : '';
        $lines[] = bv_h($prefix . $r['item_name']);
    }
    if (!$lines && !empty($a['service_name'])) $lines[] = bv_h($a['service_name']);
    return $lines ? implode('<br>', $lines) : '<span class="muted">—</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin | Bella Vista Oasis Spa</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg:#f7f1e8; --card:#fffdf9; --line:#e8dccb; --ink:#3f2d20; --muted:#8b7a6a; --brown:#5a4030; --brown-d:#46301f;
    --green:#648568; --green-l:#eaf1e8; --red:#b83232; --red-l:#fbeceb; --amber:#b9842b; --amber-l:#fbf1dc; --blue:#4a6a86; --blue-l:#e8eff5;
    --serif:"Cormorant Garamond", Georgia, serif; --sans:"Jost", system-ui, -apple-system, "Segoe UI", sans-serif;
}
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--ink); font-family:var(--sans); font-size:15px; line-height:1.5; }
a { color:inherit; }
.topbar { background:var(--card); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:20; }
.topbar-in { max-width:1360px; margin:0 auto; padding:14px 24px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
.brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
.brand-mark { width:42px; height:42px; border-radius:50%; border:1px solid #cdbfae; display:flex; align-items:center; justify-content:center; color:#b58a52; font-size:18px; }
.brand-text { font-family:var(--serif); font-size:20px; letter-spacing:.2em; line-height:1.1; }
.brand-text small { display:block; font-family:var(--sans); font-size:10px; letter-spacing:.3em; color:var(--muted); }
.tabs { display:flex; gap:4px; flex:1; overflow-x:auto; }
.tabs a { text-decoration:none; padding:8px 15px; border-radius:999px; font-size:13.5px; color:var(--muted); white-space:nowrap; }
.tabs a:hover { background:#f3ebdf; color:var(--ink); }
.tabs a.active { background:var(--brown); color:#fff; }
.top-actions { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--muted); }
.wrap { max-width:1360px; margin:0 auto; padding:32px 24px 60px; }
.page-head { display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:26px; }
.page-head h1 { margin:0; font-family:var(--serif); font-weight:600; font-size:36px; line-height:1.1; }
.page-head p { margin:4px 0 0; color:var(--muted); }
.eyebrow { font-size:11px; letter-spacing:.25em; text-transform:uppercase; color:#b58a52; }

.btn { font:inherit; font-size:14px; padding:11px 22px; border-radius:999px; border:1px solid var(--brown); background:var(--brown); color:#fff; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:background .15s, transform .1s; }
.btn:hover { background:var(--brown-d); }
.btn-sm { padding:6px 14px; font-size:13px; background:transparent; color:var(--brown); }
.btn-sm:hover { background:#f3ebdf; }
.btn-ghost { border-color:var(--line); color:var(--muted); }
.btn-danger { border-color:#e3b9b6; color:var(--red); }
.btn-danger:hover { background:var(--red-l); }
.btn-light { background:transparent; color:var(--brown); }
.btn-light:hover { background:#f3ebdf; }

.cat-pills { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:22px; }
.cat-pill { text-decoration:none; display:inline-flex; align-items:center; gap:8px; padding:8px 16px; border-radius:999px; border:1px solid var(--line); background:var(--card); color:var(--muted); font-size:13.5px; transition:.15s; }
.cat-pill:hover { border-color:var(--brown); color:var(--ink); }
.cat-pill.active { background:var(--brown); border-color:var(--brown); color:#fff; font-weight:500; }
.cat-cnt { font-size:11.5px; padding:1px 7px; border-radius:999px; background:rgba(90,64,48,.1); }
.cat-pill.active .cat-cnt { background:rgba(255,255,255,.22); color:#fff; }
.cat-section { margin-bottom:28px; }
.cat-head { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px; }
.cat-head h2 { margin:0; font-family:var(--serif); font-size:25px; font-weight:600; }
.cat-head span { font-size:13px; color:var(--muted); }

.card { background:var(--card); border:1px solid var(--line); border-radius:22px; }
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:16px; margin-bottom:28px; }
.stat { padding:20px 22px; }
.stat span { display:block; font-size:13px; color:var(--muted); }
.stat strong { display:block; margin-top:6px; font-family:var(--serif); font-size:34px; font-weight:600; line-height:1.1; }
.stat.hot strong { color:var(--amber); }

.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.panel { padding:22px 24px; }
.panel h2 { margin:0 0 14px; font-family:var(--serif); font-size:24px; font-weight:600; }
.list { list-style:none; margin:0; padding:0; }
.list li { display:flex; justify-content:space-between; align-items:center; gap:14px; padding:13px 0; border-top:1px solid #f0e7da; }
.list li:first-child { border-top:0; }
.list strong { display:block; font-size:15px; }
.list small { color:var(--muted); font-size:12.5px; }
.empty-state { padding:34px; text-align:center; color:var(--muted); border:1px dashed var(--line); border-radius:18px; background:var(--card); }

.filters { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.filters input, .filters select { font:inherit; font-size:14px; padding:10px 16px; border:1px solid var(--line); border-radius:999px; background:var(--card); color:var(--ink); }
.filters input[type=search] { flex:1; min-width:220px; }
.table-card { overflow:hidden; }
.table-wrap { overflow-x:auto; }
.tbl { width:100%; border-collapse:collapse; min-width:980px; }
.tbl th { text-align:left; font-size:11px; letter-spacing:.14em; text-transform:uppercase; color:var(--muted); font-weight:500; padding:14px 18px; background:#f3ebdf; white-space:nowrap; }
.tbl td { padding:16px 18px; border-top:1px solid #f0e7da; vertical-align:top; font-size:14px; line-height:1.5; }
.tbl tr:hover td { background:#fcf8f1; }
.tbl small { display:block; color:var(--muted); font-size:12.5px; margin-top:2px; }
.tbl .clamp { max-width:360px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.right { text-align:right !important; }
.muted { color:var(--muted); }
.actions { display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap; }
form.inline { display:inline; margin:0; }
.tbl select { font:inherit; font-size:13px; padding:6px 10px; border:1px solid var(--line); border-radius:999px; background:#fff; color:var(--ink); }
.thumb { width:58px; height:58px; border-radius:50% 50% 10px 10px / 42% 42% 10px 10px; overflow:hidden; background:linear-gradient(160deg,#f1e6d6,#d8c4a8); display:flex; align-items:center; justify-content:center; color:#a8896a; font-size:20px; }
.thumb img { width:100%; height:100%; object-fit:cover; }
.proof-link { font-size:12.5px; color:var(--green); font-weight:500; }

.badge { display:inline-block; padding:3px 11px; border-radius:999px; font-size:12px; font-weight:500; }
.b-pending { background:var(--amber-l); color:var(--amber); }
.b-confirmed { background:var(--green-l); color:var(--green); }
.b-completed { background:var(--blue-l); color:var(--blue); }
.b-cancelled { background:var(--red-l); color:var(--red); }
.p-unpaid { background:#efe9e0; color:var(--muted); }
.p-pending { background:var(--amber-l); color:var(--amber); }
.p-verified { background:var(--green-l); color:var(--green); }
.p-refunded { background:var(--blue-l); color:var(--blue); }

.pager { display:flex; gap:6px; justify-content:center; margin-top:20px; flex-wrap:wrap; }
.pager a, .pager span { padding:7px 14px; border-radius:999px; border:1px solid var(--line); text-decoration:none; font-size:13px; background:var(--card); }
.pager .cur { background:var(--brown); border-color:var(--brown); color:#fff; }

.toast { margin-bottom:22px; padding:14px 20px; border-radius:16px; font-size:14px; display:flex; justify-content:space-between; gap:12px; }
.toast.success { background:var(--green-l); color:#3f6644; border:1px solid #cfe0cc; }
.toast.error { background:var(--red-l); color:var(--red); border:1px solid #f0c9c6; }
.live-toast { position:fixed; right:24px; bottom:24px; z-index:50; background:var(--brown); color:#fff; padding:14px 20px; border-radius:18px; box-shadow:0 12px 30px rgba(63,45,32,.3); display:none; align-items:center; gap:14px; }
.live-toast a { color:#fff; font-weight:600; }
.live-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--green); margin-right:6px; animation:pulse 1.8s infinite; }
@keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }

dialog { border:0; border-radius:26px; padding:0; width:min(760px,94vw); max-height:92vh; background:var(--card); color:var(--ink); box-shadow:0 30px 80px rgba(63,45,32,.35); }
dialog::backdrop { background:rgba(63,45,32,.55); }
.dlg-head { padding:24px 30px 6px; display:flex; justify-content:space-between; align-items:center; }
.dlg-head h3 { margin:0; font-family:var(--serif); font-size:28px; font-weight:600; }
.dlg-x { background:none; border:0; font-size:26px; cursor:pointer; color:var(--muted); line-height:1; }
.dlg-body { padding:14px 30px 8px; overflow-y:auto; max-height:calc(92vh - 150px); }
.dlg-foot { padding:16px 30px 26px; display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #f0e7da; }
.fgrid { display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; }
.fgrid .full { grid-column:1 / -1; }
.fgrid label { display:block; font-size:13px; font-weight:500; }
.fgrid input[type=text], .fgrid input[type=password], .fgrid input[type=email], .fgrid input[type=tel], .fgrid input[type=date], .fgrid input[type=number], .fgrid input[type=file], .fgrid select, .fgrid textarea {
    width:100%; margin-top:6px; font:inherit; font-size:14px; padding:11px 16px; border:1px solid var(--line); border-radius:14px; background:#fff; color:var(--ink); }
.fgrid textarea { resize:vertical; min-height:80px; }
.fgrid input:focus, .fgrid select:focus, .fgrid textarea:focus { outline:2px solid rgba(100,133,104,.35); border-color:var(--green); }
.fgrid .check { display:flex; align-items:center; gap:10px; font-weight:400; }
.fgrid .check input { width:auto; margin:0; }
.sect { font-size:11px; letter-spacing:.2em; text-transform:uppercase; color:#b58a52; margin:8px 0 -4px; grid-column:1 / -1; }
.pick { border:1px solid var(--line); border-radius:14px; background:#fff; max-height:210px; overflow-y:auto; padding:6px; margin-top:6px; }
.pick label { display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:10px; cursor:pointer; font-weight:400; }
.pick label:hover { background:#f6f0e7; }
.pick input { width:auto; margin:0; }
.pick span.pr { margin-left:auto; color:var(--muted); font-size:13px; }
.pick .grp { font-size:11px; letter-spacing:.15em; text-transform:uppercase; color:var(--muted); padding:8px 10px 2px; }
.hint { font-size:12px; color:var(--muted); margin-top:4px; }
.preview { width:90px; height:90px; margin-top:8px; border-radius:50% 50% 12px 12px / 42% 42% 12px 12px; overflow:hidden; background:#efe3d2; }
.preview img { width:100%; height:100%; object-fit:cover; display:block; }

@media (max-width:900px) {
    .grid-2 { grid-template-columns:1fr; }
    .fgrid { grid-template-columns:1fr; }
    .wrap { padding:22px 16px 50px; }
    .topbar-in { padding:12px 16px; gap:14px; }
    .page-head h1 { font-size:30px; }
}

dialog.cf { border:0; border-radius:24px; padding:0; width:min(440px,92vw); background:#fffdf9; color:#3f2d20; box-shadow:0 30px 80px rgba(63,45,32,.35); }
dialog.cf::backdrop { background:rgba(63,45,32,.55); }
.cf-body { padding:32px 30px 6px; text-align:center; }
.cf-body h3 { margin:0 0 8px; font-family:var(--serif); font-size:28px; font-weight:600; }
.cf-body p { margin:0; font-size:14.5px; line-height:1.6; color:#7a6a5c; }
.cf-foot { display:flex; gap:10px; justify-content:center; padding:22px 30px 28px; }
.cf-no, .cf-yes { min-width:120px; padding:12px 26px; border-radius:999px; font:inherit; font-size:14px; cursor:pointer; border:1px solid #5a4030; }
.cf-no { background:transparent; color:#5a4030; }
.cf-no:hover { background:#f3ebdf; }
.cf-yes { background:#5a4030; color:#fff; }
.cf-yes:hover { background:#46301f; }

.wrap-full { max-width:none; padding:0; }
.wrap-full .toast { margin:14px 24px; }
.preview-frame { display:block; width:100%; height:calc(100vh - var(--topbar-h, 86px)); min-height:560px; border:0; background:#fff; }
@media(max-width:640px){
  .tabs{overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch}
  .tabs>*{flex:0 0 auto}
  dialog{max-height:92dvh;max-height:92vh;padding:18px}
  .live-toast{left:10px!important;right:10px!important;bottom:10px!important;max-width:none!important}
  input,select,textarea{font-size:16px}
}
@media(max-height:520px) and (orientation:landscape){dialog{max-height:96vh;overflow-y:auto}}
</style>
</head>
<body>

<header class="topbar">
    <div class="topbar-in">
        <a class="brand" href="admin_dashboard.php">
            <span class="brand-mark">✦</span>
            <span class="brand-text">BELLA VISTA<small>OASIS SPA · ADMIN</small></span>
        </a>
        <nav class="tabs">
            <a href="?tab=overview" class="<?= $tab === 'overview' ? 'active' : '' ?>">Overview</a>
            <a href="?tab=appointments" class="<?= $tab === 'appointments' ? 'active' : '' ?>">Appointments</a>
            <a href="?tab=rooms" class="<?= $tab === 'rooms' ? 'active' : '' ?>">Rooms</a>
            <a href="?tab=services" class="<?= $tab === 'services' ? 'active' : '' ?>">Services</a>
            <a href="?tab=packages" class="<?= $tab === 'packages' ? 'active' : '' ?>">Spa Packages</a>
            <a href="?tab=addons" class="<?= $tab === 'addons' ? 'active' : '' ?>">Add-ons</a>
            <a href="?tab=payments" class="<?= $tab === 'payments' ? 'active' : '' ?>">Payments</a>
            <a href="?tab=admins" class="<?= $tab === 'admins' ? 'active' : '' ?>">Admins</a>
            <a href="?tab=preview" class="<?= $tab === 'preview' ? 'active' : '' ?>">Booking page</a>
        </nav>
        <div class="top-actions">
            <span><?= bv_h($adminName) ?></span>
            <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="tab=overview">
                <input type="hidden" name="op" value="logout">
                <button class="btn btn-sm btn-ghost">Log out</button>
            </form>
        </div>
    </div>
</header>

<main class="wrap<?= $tab === 'preview' ? ' wrap-full' : '' ?>">
<?php if ($flash): ?>
    <div class="toast <?= bv_h($flash[0]) ?>"><span><?= bv_h($flash[1]) ?></span></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Dashboard</div>
            <h1>Good day, <?= bv_h($adminName) ?>.</h1>
            <p><?= bv_h($todayLabel) ?> · <span class="live-dot"></span>Live overview</p>
        </div>
        <button class="btn" type="button" data-new-appt>+ New booking</button>
    </div>

    <section class="stats">
        <div class="card stat"><span>Total bookings</span><strong data-stat="total"><?= $stats['total'] ?></strong></div>
        <div class="card stat hot"><span>Pending requests</span><strong data-stat="pending"><?= $stats['pending'] ?></strong></div>
        <div class="card stat"><span>Confirmed</span><strong data-stat="confirmed"><?= $stats['confirmed'] ?></strong></div>
        <div class="card stat"><span>Today's appointments</span><strong data-stat="today"><?= $stats['today'] ?></strong></div>
        <div class="card stat hot"><span>Payments to verify</span><strong data-stat="pay_pending"><?= $stats['pay_pending'] ?></strong></div>
        <div class="card stat"><span>Confirmed revenue</span><strong data-stat="revenue"><?= bv_h($stats['revenue']) ?></strong></div>
    </section>

    <section class="grid-2">
        <div class="card panel">
            <h2>Upcoming appointments</h2>
            <?php if (!$upcoming): ?>
                <div class="empty-state">No upcoming appointments.</div>
            <?php else: ?>
                <ul class="list">
                <?php foreach ($upcoming as $a): ?>
                    <li>
                        <div>
                            <strong><?= bv_h($a['customer_name']) ?></strong>
                            <small><?= date('D, M j', strtotime($a['appointment_date'])) ?> · <?= date('g:i A', strtotime($a['appointment_time'])) ?> · <?= (int)$a['guests'] ?> guest<?= $a['guests'] > 1 ? 's' : '' ?></small>
                        </div>
                        <?= bv_badge($a['status']) ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="card panel">
            <h2>Needs attention</h2>
            <?php if (!$attention): ?>
                <div class="empty-state">All caught up.</div>
            <?php else: ?>
                <ul class="list">
                <?php foreach ($attention as $a):
                    $pm = $a['payment_method'] ?? 'cash';
                    $pmLabel = $pm === 'cash' ? 'Pay at store' : (($methodLabels[$pm] ?? strtoupper($pm)) . (!empty($a['payment_provider']) ? ' · ' . strtoupper($a['payment_provider']) : '') . ' ' . bv_money($a['amount_due']));
                ?>
                    <li>
                        <div>
                            <strong><?= bv_h($a['customer_name']) ?> <small><?= bv_h($a['booking_code']) ?></small></strong>
                            <small><?= bv_money($a['total_price']) ?> · <?= bv_h($pmLabel) ?></small>
                        </div>
                        <div style="text-align:right">
                            <?= bv_badge($a['status']) ?>
                            <?php if ($a['payment_status'] === 'pending'): ?> <?= bv_pay_badge('pending') ?><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
                <p style="margin:14px 0 0"><a class="btn btn-sm" href="?tab=appointments&status=Pending">Review pending →</a></p>
            <?php endif; ?>
        </div>
    </section>

<?php elseif ($tab === 'appointments'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Reservation records</div>
            <h1>Appointments</h1>
            <p><?= $totalRows ?> record<?= $totalRows === 1 ? '' : 's' ?></p>
        </div>
        <button class="btn" type="button" data-new-appt>+ New booking</button>
    </div>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="appointments">
        <input type="search" name="q" value="<?= bv_h($q) ?>" placeholder="Search name, email, phone, code or ref">
        <select name="method">
            <option value="">All payment methods</option>
            <?php foreach ($methodLabels as $mk => $ml): ?><option value="<?= bv_h($mk) ?>" <?= $fMethod === $mk ? 'selected' : '' ?>><?= bv_h($ml) ?></option><?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?><option <?= $fStatus === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <select name="pay">
            <option value="">All payments</option>
            <?php foreach ($payStatuses as $s): ?><option value="<?= $s ?>" <?= $fPay === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?= bv_h($fDate) ?>">
        <button class="btn" type="submit">Filter</button>
        <?php if ($filterQs): ?><a class="btn btn-sm btn-ghost" href="?tab=appointments">Clear</a><?php endif; ?>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state">No appointments found.</div>
    <?php else: ?>
    <div class="card table-card"><div class="table-wrap">
    <table class="tbl">
        <thead><tr>
            <th>Booking</th><th>Guest</th><th>Room &amp; Services</th><th>Schedule</th><th>Payment</th><th>Total</th><th>Status</th><th class="right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $a):
            $sel = [];
            foreach ($itemsMap[(int)$a['id']] ?? [] as $r) {
                if (in_array($r['item_type'], ['service', 'package', 'addon'], true)) {
                    $sel[] = $r['item_type'] . ':' . $r['item_id'];
                }
            }
            if (!$sel && $a['service_id']) $sel[] = 'service:' . (int)$a['service_id'];
            $totalVal = (float)$a['total_price'];
            $dueVal = (float)$a['amount_due'];
            $pm = $a['payment_method'] ?? 'cash';
            $detectedPlan = ($pm === 'cash') ? 'store' : (($dueVal >= $totalVal - 0.01 && $totalVal > 0) ? 'full' : 'downpayment');
            $data = [
                'id' => (int)$a['id'], 'name' => $a['customer_name'], 'email' => $a['email'], 'phone' => $a['phone'],
                'date' => $a['appointment_date'], 'time' => substr($a['appointment_time'], 0, 5), 'guests' => (int)$a['guests'],
                'room_id' => (int)($a['room_id'] ?? 0), 'notes' => $a['notes'] ?? '', 'status' => $a['status'],
                'method' => $pm, 'plan' => $a['payment_plan'] ?? $detectedPlan,
                'provider' => $a['payment_provider'] ?? '',
                'pay_status' => $a['payment_status'], 'ref' => $a['reference_no'] ?? '', 'items' => $sel,
            ];
            $provLabel = $a['payment_provider'] ? ($providers[$pm][$a['payment_provider']] ?? strtoupper($a['payment_provider'])) : '';
        ?>
            <tr>
                <td><strong><?= bv_h($a['booking_code']) ?></strong><small><?= date('M j, Y', strtotime($a['created_at'])) ?></small></td>
                <td><strong><?= bv_h($a['customer_name']) ?></strong><small><?= bv_h($a['email']) ?></small><small><?= bv_h($a['phone']) ?></small></td>
                <td>
                    <?php if (!empty($a['room_name'])): ?>
                        <span class="badge b-confirmed" style="margin-bottom:4px;"><?= bv_h($a['room_name']) ?></span><br>
                    <?php endif; ?>
                    <?= bv_item_names($a, $itemsMap) ?>
                </td>
                <td><strong><?= date('M j, Y', strtotime($a['appointment_date'])) ?></strong><small><?= date('g:i A', strtotime($a['appointment_time'])) ?> · <?= (int)$a['guests'] ?> guest<?= $a['guests'] > 1 ? 's' : '' ?></small></td>
                <td>
                    <strong><?= bv_h($methodLabels[$pm] ?? $pm) ?><?= $provLabel ? ' · ' . bv_h($provLabel) : '' ?></strong>
                    <?php if ($pm === 'cash'): ?>
                        <small>Settle <?= bv_money($totalVal) ?> at store</small>
                    <?php elseif ($detectedPlan === 'full'): ?>
                        <small>Full payment <?= bv_money($dueVal) ?><?= $a['reference_no'] ? ' · Ref ' . bv_h($a['reference_no']) : '' ?></small>
                    <?php else: ?>
                        <small>30% Down <?= bv_money($dueVal) ?> · Bal <?= bv_money(max($totalVal - $dueVal, 0)) ?><?= $a['reference_no'] ? ' · Ref ' . bv_h($a['reference_no']) : '' ?></small>
                    <?php endif; ?>
                    <?php if (!empty($a['proof_image'])): ?><small><a class="proof-link" href="<?= bv_h($a['proof_image']) ?>" target="_blank">View proof ↗</a></small><?php elseif ($pm !== 'cash'): ?><small class="muted">No proof file attached</small><?php endif; ?>
                    <form method="post" class="inline" style="margin-top:6px;display:block">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="set_pay_status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                        <select name="pay_status" data-confirm-select="pay" data-code="<?= bv_h($a['booking_code']) ?>">
                            <?php foreach ($payStatuses as $s): ?><option value="<?= $s ?>" <?= $a['payment_status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td><strong><?= bv_money($a['total_price']) ?></strong></td>
                <td>
                    <?= bv_badge($a['status']) ?>
                    <form method="post" class="inline" style="margin-top:6px;display:block">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="set_status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                        <select name="booking_status" data-confirm-select="status" data-code="<?= bv_h($a['booking_code']) ?>">
                            <?php foreach ($statuses as $s): ?><option <?= $a['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td class="right"><div class="actions">
                    <button type="button" class="btn btn-sm" data-appt="<?= bv_h(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="delete_appointment"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div></div>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php for ($i = 1; $i <= $pages; $i++):
                $link = '?tab=appointments&' . ($filterQs ? $filterQs . '&' : '') . 'page=' . $i; ?>
                <?php if ($i === $page): ?><span class="cur"><?= $i ?></span><?php else: ?><a href="<?= bv_h($link) ?>"><?= $i ?></a><?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
    <?php endif; ?>

<?php elseif ($tab === 'rooms'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Suites &amp; spaces</div>
            <h1>Rooms</h1>
            <p><?= count($rooms) ?> room<?= count($rooms) === 1 ? '' : 's' ?></p>
        </div>
        <?php if ($hasRooms): ?><button class="btn" type="button" data-new-room>+ Add room</button><?php endif; ?>
    </div>
    <?php if (!$hasRooms): ?>
        <div class="empty-state">The <code>rooms</code> table does not exist yet.</div>
    <?php elseif (!$rooms): ?>
        <div class="empty-state">No rooms yet. Click Add room to create one.</div>
    <?php else: ?>
    <div class="card table-card"><div class="table-wrap"><table class="tbl">
        <thead><tr><th>Picture</th><th>Room &amp; description</th><th>Capacity</th><th>Duration</th><th>Room Fee</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rooms as $r):
            $rdata = ['id' => (int)$r['id'], 'name' => $r['name'], 'description' => $r['description'] ?? '', 'amenities' => str_replace(',', "\n", (string)($r['amenities'] ?? '')),
                      'min_guests' => (int)$r['min_guests'], 'max_guests' => (int)$r['max_guests'],
                      'duration' => (int)$r['duration_minutes'], 'price' => (float)$r['price'], 'active' => (int)$r['active'], 'image' => $r['image'] ?? ''];
        ?>
            <tr>
                <td><div class="thumb"><?= bv_thumb($r['image'] ?? '', '⌂') ?></div></td>
                <td><strong><?= bv_h($r['name']) ?></strong><small class="clamp"><?= bv_h($r['description'] ?? '') ?></small></td>
                <td><?= (int)$r['min_guests'] ?>–<?= (int)$r['max_guests'] ?> guest<?= (int)$r['max_guests'] > 1 ? 's' : '' ?></td>
                <td><?= (int)$r['duration_minutes'] ?> min</td>
                <td><strong><?= (float)$r['price'] > 0 ? bv_money($r['price']) : 'Included' ?></strong></td>
                <td><?= $r['active'] ? '<span class="badge b-confirmed">Active</span>' : '<span class="badge b-cancelled">Hidden</span>' ?></td>
                <td class="right"><div class="actions">
                    <button type="button" class="btn btn-sm" data-room="<?= bv_h(json_encode($rdata, JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="toggle_room"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-ghost"><?= $r['active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="delete_room"><input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <?php endif; ?>

<?php elseif ($tab === 'addons'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Enhancements</div>
            <h1>Optional Add-ons</h1>
            <p><?= count($addons) ?> add-on<?= count($addons) === 1 ? '' : 's' ?></p>
        </div>
        <?php if ($hasAddons): ?><button class="btn" type="button" data-new-addon>+ Add add-on</button><?php endif; ?>
    </div>
    <?php if (!$hasAddons): ?>
        <div class="empty-state">The <code>addons</code> table does not exist yet.</div>
    <?php elseif (!$addons): ?>
        <div class="empty-state">No add-ons yet. Click Add add-on to create one.</div>
    <?php else: ?>
    <div class="card table-card"><div class="table-wrap"><table class="tbl" style="min-width:680px">
        <thead><tr><th>Name &amp; description</th><th>Price</th><th>Status</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($addons as $ad):
            $adata = ['id' => (int)$ad['id'], 'kind' => 'addon', 'name' => $ad['name'], 'description' => $ad['description'] ?? '',
                      'price' => (float)$ad['price'], 'active' => (int)$ad['active']];
        ?>
            <tr>
                <td><strong><?= bv_h($ad['name']) ?></strong><small class="clamp"><?= bv_h($ad['description'] ?? '') ?></small></td>
                <td><strong>+<?= bv_money($ad['price']) ?></strong></td>
                <td><?= $ad['active'] ? '<span class="badge b-confirmed">Active</span>' : '<span class="badge b-cancelled">Hidden</span>' ?></td>
                <td class="right"><div class="actions">
                    <button type="button" class="btn btn-sm" data-addon="<?= bv_h(json_encode($adata, JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="toggle_item"><input type="hidden" name="kind" value="addon"><input type="hidden" name="item_id" value="<?= (int)$ad['id'] ?>">
                        <button class="btn btn-sm btn-ghost"><?= $ad['active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="delete_item"><input type="hidden" name="kind" value="addon"><input type="hidden" name="item_id" value="<?= (int)$ad['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <?php endif; ?>

<?php elseif ($tab === 'preview'): ?>
    <iframe class="preview-frame" src="book.php?preview=1" title="Booking page"></iframe>

<?php elseif ($tab === 'payments'): ?>
    <style>
    .pay-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; margin-bottom:8px; }
    .pay-card { padding:22px; display:flex; flex-direction:column; gap:18px; }
    .pay-head { display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .pay-head h3 { margin:0; font-family:var(--serif); font-size:26px; font-weight:600; letter-spacing:.02em; }
    .pay-body { display:grid; grid-template-columns:132px minmax(0,1fr); gap:20px; align-items:start; }
    .pay-qrbox { width:132px; height:132px; border-radius:16px; border:1px solid var(--line); background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .pay-qrbox img { width:100%; height:100%; object-fit:contain; padding:6px; }
    .pay-qrbox.empty { border:1.5px dashed #cdbfae; background:var(--bg); color:var(--muted); font-size:13px; text-align:center; padding:10px; }
    .pay-fields { display:grid; gap:12px; }
    .pay-fields label { display:block; margin:0; font-size:12px; font-weight:500; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
    .pay-fields input[type=text] { width:100%; margin-top:5px; font:inherit; font-size:15px; letter-spacing:0; text-transform:none; padding:10px 14px; border:1px solid var(--line); border-radius:12px; background:#fff; color:var(--ink); }
    .pay-fields input[type=text]:focus { outline:2px solid rgba(100,133,104,.35); border-color:var(--green); }
    .pay-fields .btn { justify-self:start; }
    .pay-foot { display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding-top:16px; border-top:1px solid var(--line); }
    .pay-foot form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0; }
    .pay-foot input[type=file] { font-size:13px; max-width:210px; color:var(--muted); }
    .pay-foot .spacer { flex:1; }
    .pay-sec { margin:28px 0 12px; font-family:var(--serif); font-size:24px; font-weight:600; }
    .pay-note { margin-top:18px; font-size:13px; color:var(--muted); }
    @media (max-width:980px) { .pay-grid { grid-template-columns:1fr; } }
    @media (max-width:520px) { .pay-body { grid-template-columns:1fr; } .pay-qrbox { width:100%; max-width:200px; height:200px; } .pay-card { padding:18px; } }
    </style>
    <div class="page-head">
        <div>
            <div class="eyebrow">Down payments</div>
            <h1>Payments</h1>
            <p>QR codes and account details shown to customers on the booking page. Edit anytime.</p>
        </div>
    </div>
    <?php foreach (['ewallet' => 'E-wallets', 'bank' => 'Online banking'] as $grp => $grpLabel): ?>
    <div class="pay-sec"><?= bv_h($grpLabel) ?></div>
    <div class="pay-grid">
    <?php foreach ($providers[$grp] as $pk => $pname):
        $qr = bv_qr_find($pk);
        $ac = $payAccounts[$pk] ?? ['bank_name' => '', 'account_name' => '', 'account_number' => '']; ?>
        <div class="card pay-card">
            <div class="pay-head">
                <h3><?= bv_h($pname) ?></h3>
                <?= $qr ? '<span class="badge b-confirmed">QR active</span>' : '<span class="badge b-cancelled">No QR yet</span>' ?>
            </div>
            <div class="pay-body">
                <?php if ($qr): $qv = (int)@filemtime(__DIR__ . '/' . $qr); ?>
                    <a class="pay-qrbox" href="<?= bv_h($qr) ?>?v=<?= $qv ?>" target="_blank" title="Open full size"><img src="<?= bv_h($qr) ?>?v=<?= $qv ?>" alt="<?= bv_h($pname) ?> QR"></a>
                <?php else: ?><div class="pay-qrbox empty">No QR uploaded</div><?php endif; ?>

                <form method="post" class="pay-fields" onsubmit="return confirm('Save the new <?= bv_h($pname) ?> account details? Customers will see them on the booking page.')">
                    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="tab=payments">
                    <input type="hidden" name="op" value="save_account"><input type="hidden" name="provider" value="<?= bv_h($pk) ?>">
                    <?php if ($grp === 'bank'): ?>
                    <label>Bank name<input type="text" name="bank_name" value="<?= bv_h($ac['bank_name']) ?>" maxlength="80" required></label>
                    <?php endif; ?>
                    <label>Account name<input type="text" name="account_name" value="<?= bv_h($ac['account_name']) ?>" maxlength="80" required></label>
                    <label><?= $grp === 'bank' ? 'Account number' : 'Mobile number' ?><input type="text" name="account_number" value="<?= bv_h($ac['account_number']) ?>" maxlength="40" required></label>
                    <button class="btn">Save details</button>
                </form>
            </div>
            <div class="pay-foot">
                <form method="post" enctype="multipart/form-data" onsubmit="return confirm('<?= $qr ? 'Replace' : 'Upload' ?> the <?= bv_h($pname) ?> QR code?<?= $qr ? ' The current QR will be removed.' : '' ?>')">
                    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="tab=payments">
                    <input type="hidden" name="op" value="save_qr"><input type="hidden" name="provider" value="<?= bv_h($pk) ?>">
                    <input type="file" name="qr" accept="image/jpeg,image/png,image/webp" required>
                    <button class="btn btn-sm"><?= $qr ? 'Replace QR' : 'Upload QR' ?></button>
                </form>
                <span class="spacer"></span>
                <?php if ($qr): ?>
                <form method="post" onsubmit="return confirm('Delete the <?= bv_h($pname) ?> QR code? Customers will no longer see it on the booking page.')">
                    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="tab=payments">
                    <input type="hidden" name="op" value="delete_qr"><input type="hidden" name="provider" value="<?= bv_h($pk) ?>">
                    <button class="btn btn-sm btn-danger">Delete QR</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <p class="pay-note">QR: JPG, PNG or WEBP, max 3 MB. Changes appear right away on the booking page.</p>

<?php elseif ($tab === 'admins'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Access</div>
            <h1>Admins</h1>
            <p><?= count($admins) ?> account<?= count($admins) === 1 ? '' : 's' ?></p>
        </div>
        <button class="btn" type="button" data-new-admin>+ Add admin</button>
    </div>
    <div class="card table-card"><div class="table-wrap">
    <table class="tbl" style="min-width:520px">
        <thead><tr><th>Username</th><th>Created</th><th class="right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($admins as $ad): $isMe = (int)$ad['id'] === (int)$_SESSION['admin_id']; ?>
            <tr>
                <td><strong><?= bv_h($ad['username']) ?></strong><?= $isMe ? ' <span class="badge b-confirmed">You</span>' : '' ?></td>
                <td><?= $ad['created_at'] ? bv_h(date('M j, Y', strtotime($ad['created_at']))) : '—' ?></td>
                <td class="right"><div class="actions">
                    <button type="button" class="btn btn-sm" data-admin="<?= bv_h(json_encode(['id' => (int)$ad['id'], 'username' => $ad['username']])) ?>">Reset password</button>
                    <?php if (!$isMe): ?>
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="delete_admin"><input type="hidden" name="admin_id" value="<?= (int)$ad['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div></div>

<?php elseif ($tab === 'services'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Services catalog</div>
            <h1>Services</h1>
            <p><?= count($services) ?> service<?= count($services) === 1 ? '' : 's' ?> across <?= count($serviceCategories) ?> categories</p>
        </div>
        <button class="btn" type="button" data-new-item="service">+ Add service</button>
    </div>
    <?php bv_render_categorized_services($services, $fSvcCat, $csrf, $backQs); ?>

<?php else: ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Bundles</div>
            <h1>Spa Packages</h1>
            <p><?= count($packages) ?> package<?= count($packages) === 1 ? '' : 's' ?></p>
        </div>
        <?php if ($hasPackages): ?><button class="btn" type="button" data-new-item="package">+ Add package</button><?php endif; ?>
    </div>
    <?php if ($hasPackages): bv_render_items($packages, 'package', $csrf, $backQs); else: ?>
        <div class="empty-state">The <code>packages</code> table does not exist yet.</div>
    <?php endif; ?>
<?php endif; ?>
</main>

<div class="live-toast" id="liveToast"><span>🔔 New booking received</span><a href="?tab=appointments">View</a><button class="dlg-x" style="color:#fff;font-size:20px" onclick="this.parentNode.style.display='none'">×</button></div>

<!-- ===== Appointment dialog ===== -->
<dialog id="apptDialog">
<form method="post" id="apptForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>">
    <input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
    <input type="hidden" name="op" value="save_appointment">
    <input type="hidden" name="appointment_id" value="">
    <div class="dlg-head"><h3 id="apptTitle">New booking</h3><button type="button" class="dlg-x" data-close>&times;</button></div>
    <div class="dlg-body"><div class="fgrid">
        <div class="sect">Guest</div>
        <label class="full">Full name<input type="text" name="customer_name" required maxlength="120"></label>
        <label>Email<input type="email" name="email" required maxlength="150"></label>
        <label>Phone<input type="tel" name="phone" required maxlength="12" inputmode="numeric" placeholder="09XXXXXXXXX"></label>

        <?php if ($rooms): ?>
        <div class="sect">Room</div>
        <label class="full">Suite / Room
            <select name="room_id">
                <option value="">Open Treatment Area / No specific room</option>
                <?php foreach ($rooms as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= bv_h($r['name']) ?> (<?= (int)$r['min_guests'] ?>–<?= (int)$r['max_guests'] ?> guests · <?= (float)$r['price'] > 0 ? bv_money($r['price']) : 'Included' ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <div class="sect">Services &amp; Add-ons</div>
        <div class="full">
            <div class="pick">
                <?php foreach ($serviceCategories as $ck => $cl):
                    $catSvcs = array_filter($services, fn($s) => strtolower(trim((string)($s['category'] ?? 'massage'))) === $ck);
                    if (!$catSvcs) continue;
                ?>
                    <div class="grp"><?= bv_h($cl) ?></div>
                    <?php foreach ($catSvcs as $s): ?>
                        <label><input type="checkbox" name="items[]" value="service:<?= (int)$s['id'] ?>"><?= bv_h($s['name']) ?><?= $s['active'] ? '' : ' (hidden)' ?><span class="pr"><?= bv_money($s['price']) ?></span></label>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($packages): ?><div class="grp">Spa Packages</div>
                    <?php foreach ($packages as $p): ?>
                        <label><input type="checkbox" name="items[]" value="package:<?= (int)$p['id'] ?>"><?= bv_h($p['name']) ?><?= $p['active'] ? '' : ' (hidden)' ?><span class="pr"><?= bv_money($p['price']) ?></span></label>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($addons): ?><div class="grp">Optional Add-ons</div>
                    <?php foreach ($addons as $ad): ?>
                        <label><input type="checkbox" name="items[]" value="addon:<?= (int)$ad['id'] ?>">+ <?= bv_h($ad['name']) ?><?= $ad['active'] ? '' : ' (hidden)' ?><span class="pr"><?= bv_money($ad['price']) ?></span></label>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="sect">Schedule</div>
        <label>Date<input type="date" name="appointment_date" required></label>
        <label>Time
            <select name="appointment_time" required>
                <option value="">Choose time</option>
                <?php foreach (BV_SLOTS as $t): ?><option value="<?= $t ?>" data-label="<?= date('g:i A', strtotime($t)) ?>"><?= date('g:i A', strtotime($t)) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Guests
            <select name="guests"><?php for ($i = 1; $i <= BV_MAX_GUESTS; $i++): ?><option value="<?= $i ?>"><?= $i ?> guest<?= $i > 1 ? 's' : '' ?></option><?php endfor; ?></select>
        </label>
        <label>Booking status
            <select name="booking_status"><?php foreach ($statuses as $s): ?><option><?= $s ?></option><?php endforeach; ?></select>
        </label>

        <div class="sect">Payment</div>
        <label>Method
            <select name="pay_method"><?php foreach ($methodLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
        </label>
        <label>Payment option
            <select name="pay_plan">
                <option value="downpayment">30% Downpayment</option>
                <option value="full">Full Payment (100%)</option>
            </select>
        </label>
        <label>Provider<select name="pay_provider"></select></label>
        <label>Payment status
            <select name="pay_status"><?php foreach ($payStatuses as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?></select>
        </label>
        <label class="full">Reference number (optional)<input type="text" name="pay_ref" maxlength="25"></label>

        <label class="full">Notes<textarea name="notes" maxlength="1000"></textarea></label>
    </div></div>
    <div class="dlg-foot">
        <button type="button" class="btn btn-light" data-close>Cancel</button>
        <button type="submit" class="btn">Save booking</button>
    </div>
</form>
</dialog>

<!-- ===== Room dialog ===== -->
<dialog id="roomDialog">
<form method="post" id="roomForm" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>">
    <input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
    <input type="hidden" name="op" value="save_room">
    <input type="hidden" name="room_id" value="">
    <div class="dlg-head"><h3 id="roomTitle">Add room</h3><button type="button" class="dlg-x" data-close>&times;</button></div>
    <div class="dlg-body"><div class="fgrid">
        <label class="full">Room Name<input type="text" name="room_name" required maxlength="120"></label>
        <label class="full">Description<textarea name="description" maxlength="1000"></textarea></label>
        <label class="full">Amenities (one per line)<textarea name="amenities" rows="4" placeholder="Private Treatment Bed&#10;Aromatherapy Diffuser"></textarea></label>
        <label>Minimum Guests<input type="number" name="min_guests" min="1" max="6" required value="1"></label>
        <label>Maximum Guests<input type="number" name="max_guests" min="1" max="6" required value="1"></label>
        <label>Duration (minutes)<input type="number" name="duration" min="5" max="600" step="5" required value="60"></label>
        <label>Room Fee (₱)<input type="number" name="price" min="0" step="0.01" required value="0"></label>
        <div class="full">
            <label>Picture (JPG, PNG or WEBP, max 3 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            <div class="preview" id="roomPreview" hidden></div>
        </div>
        <label class="check full"><input type="checkbox" name="is_active" value="1" checked> Active on booking page</label>
    </div></div>
    <div class="dlg-foot">
        <button type="button" class="btn btn-light" data-close>Cancel</button>
        <button type="submit" class="btn">Save</button>
    </div>
</form>
</dialog>

<!-- ===== Service / package / add-on dialog ===== -->
<dialog id="itemDialog">
<form method="post" id="itemForm" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>">
    <input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
    <input type="hidden" name="op" value="save_item">
    <input type="hidden" name="kind" value="service">
    <input type="hidden" name="item_id" value="">
    <div class="dlg-head"><h3 id="itemTitle">Add service</h3><button type="button" class="dlg-x" data-close>&times;</button></div>
    <div class="dlg-body"><div class="fgrid">
        <label class="full">Name<input type="text" name="item_name" required maxlength="120"></label>
        <label class="full" id="itemCatWrap">Category<select name="category"><?php foreach ($serviceCategories as $ck => $cv): ?><option value="<?= bv_h($ck) ?>"><?= bv_h($cv) ?></option><?php endforeach; ?></select></label>
        <label id="itemRoomWrap">Room type<select name="room_type"><?php foreach ($packageRoomTypes as $rk => $rv): ?><option value="<?= bv_h($rk) ?>"><?= bv_h($rv) ?></option><?php endforeach; ?></select></label>
        <label id="itemMaxWrap">Max guests<input type="number" name="max_guests" min="1" max="6" value="1"></label>
        <label class="full">Description<textarea name="description" maxlength="1000"></textarea></label>
        <label id="itemDurWrap">Duration (minutes)<input type="number" name="duration" min="5" max="600" step="5" required value="60"></label>
        <label>Price (₱)<input type="number" name="price" min="0" step="0.01" required></label>
        <div class="full" id="itemImgWrap">
            <label>Picture (JPG, PNG or WEBP, max 3 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            <div class="preview" id="itemPreview" hidden></div>
        </div>
        <label class="check full"><input type="checkbox" name="is_active" value="1" checked> Active on booking page</label>
    </div></div>
    <div class="dlg-foot">
        <button type="button" class="btn btn-light" data-close>Cancel</button>
        <button type="submit" class="btn">Save</button>
    </div>
</form>
</dialog>

<dialog id="confirmDialog" class="cf">
    <div class="cf-body"><h3 id="cfTitle">Confirm action</h3><p id="cfText"></p></div>
    <div class="cf-foot">
        <button type="button" class="cf-no" id="cfNo">No</button>
        <button type="button" class="cf-yes" id="cfYes">Yes</button>
    </div>
</dialog>

<!-- ===== Admin account dialog ===== -->
<dialog id="adminDialog">
<form method="post" id="adminForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>">
    <input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
    <input type="hidden" name="op" value="save_admin">
    <input type="hidden" name="admin_id" value="">
    <div class="dlg-head"><h3 id="adminTitle">Add admin</h3><button type="button" class="dlg-x" data-close>&times;</button></div>
    <div class="dlg-body"><div class="fgrid">
        <label class="full">Username<input type="text" name="admin_username" maxlength="50" autocomplete="off"></label>
        <label>Password<input type="password" name="admin_password" required minlength="8" autocomplete="new-password"></label>
        <label>Confirm password<input type="password" name="admin_password_confirm" required minlength="8" autocomplete="new-password"></label>
    </div></div>
    <div class="dlg-foot">
        <button type="button" class="btn btn-light" data-close>Cancel</button>
        <button type="submit" class="btn">Save</button>
    </div>
</form>
</dialog>

<script>
const PROVIDERS = <?= json_encode($providers) ?>;
const INITIAL_LATEST = <?= (int)$stats['latest'] ?>;
const TODAY = '<?= date('Y-m-d') ?>';

document.querySelectorAll('dialog').forEach(d => {
    d.addEventListener('click', e => { if (e.target === d || e.target.hasAttribute('data-close')) d.close(); });
});

/* ---- appointment dialog ---- */
const apptDlg = document.getElementById('apptDialog');
const af = document.getElementById('apptForm');

function fillProviders(method, selected) {
    const sel = af.elements.pay_provider;
    const planSel = af.elements.pay_plan;
    sel.innerHTML = '';
    const list = PROVIDERS[method];
    if (!list) {
        sel.add(new Option('Not needed', ''));
        sel.disabled = true;
        planSel.disabled = true;
        return;
    }
    sel.disabled = false;
    planSel.disabled = false;
    Object.entries(list).forEach(([k, v]) => { const o = new Option(v, k); o.selected = (k === selected); sel.add(o); });
}

async function refreshSlots() {
    const date = af.elements.appointment_date.value;
    const roomVal = af.elements.room_id ? af.elements.room_id.value : '';
    const sel = af.elements.appointment_time;
    const own = date === af.dataset.origDate ? af.dataset.origTime : '';
    let booked = [];
    if (date) {
        try {
            const url = 'book.php?slots=' + encodeURIComponent(date) + (roomVal ? '&room_id=' + encodeURIComponent(roomVal) : '');
            booked = (await (await fetch(url)).json()).booked || [];
        } catch (e) {}
    }
    Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        const taken = booked.includes(o.value) && o.value !== own;
        o.disabled = taken;
        o.textContent = o.dataset.label + (taken ? ' — booked' : '');
    });
}

function openAppt(d) {
    af.reset();
    af.elements.appointment_id.value = d ? d.id : '';
    document.getElementById('apptTitle').textContent = d ? 'Edit booking' : 'New booking';
    af.elements.appointment_date.min = d ? '' : TODAY;
    af.dataset.origDate = d ? d.date : '';
    af.dataset.origTime = d ? d.time : '';
    if (af.elements.room_id) af.elements.room_id.value = d && d.room_id ? d.room_id : '';
    if (d) {
        af.elements.customer_name.value = d.name;
        af.elements.email.value = d.email;
        af.elements.phone.value = d.phone;
        af.elements.appointment_date.value = d.date;
        af.elements.guests.value = d.guests;
        af.elements.notes.value = d.notes;
        af.elements.booking_status.value = d.status;
        af.elements.pay_method.value = d.method;
        af.elements.pay_plan.value = (d.plan === 'full') ? 'full' : 'downpayment';
        af.elements.pay_status.value = d.pay_status;
        af.elements.pay_ref.value = d.ref;
    } else {
        af.elements.booking_status.value = 'Confirmed';
        af.elements.pay_plan.value = 'downpayment';
    }
    af.querySelectorAll('input[name="items[]"]').forEach(c => c.checked = !!d && d.items.includes(c.value));
    fillProviders(af.elements.pay_method.value, d ? d.provider : '');
    refreshSlots().then(() => { if (d) af.elements.appointment_time.value = d.time; });
    apptDlg.showModal();
}

af.elements.pay_method.addEventListener('change', () => fillProviders(af.elements.pay_method.value, ''));
af.elements.appointment_date.addEventListener('change', refreshSlots);
if (af.elements.room_id) af.elements.room_id.addEventListener('change', refreshSlots);
document.querySelectorAll('[data-appt]').forEach(b => b.addEventListener('click', () => openAppt(JSON.parse(b.dataset.appt))));
document.querySelectorAll('[data-new-appt]').forEach(b => b.addEventListener('click', () => openAppt(null)));
af.elements.phone.addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 12); });

/* ---- room dialog ---- */
const roomDlg = document.getElementById('roomDialog');
const rf = document.getElementById('roomForm');
const roomPrev = document.getElementById('roomPreview');
function openRoom(d) {
    rf.reset();
    rf.elements.room_id.value = d ? d.id : '';
    document.getElementById('roomTitle').textContent = d ? 'Edit room' : 'Add room';
    if (d) {
        rf.elements.room_name.value = d.name;
        rf.elements.description.value = d.description;
        rf.elements.amenities.value = d.amenities || '';
        rf.elements.min_guests.value = d.min_guests;
        rf.elements.max_guests.value = d.max_guests;
        rf.elements.duration.value = d.duration;
        rf.elements.price.value = d.price;
        rf.elements.is_active.checked = !!d.active;
    }
    roomPrev.hidden = !(d && d.image);
    roomPrev.innerHTML = d && d.image ? '<img src="' + d.image.replace(/"/g, '&quot;') + '" alt="">' : '';
    roomDlg.showModal();
}
document.querySelectorAll('[data-room]').forEach(b => b.addEventListener('click', () => openRoom(JSON.parse(b.dataset.room))));
document.querySelectorAll('[data-new-room]').forEach(b => b.addEventListener('click', () => openRoom(null)));
rf.elements.image.addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    roomPrev.hidden = false;
    roomPrev.innerHTML = '';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    roomPrev.appendChild(img);
});

/* ---- service / package / add-on dialog ---- */
const itemDlg = document.getElementById('itemDialog');
const itf = document.getElementById('itemForm');
const prev = document.getElementById('itemPreview');

function openItem(kind, d) {
    itf.reset();
    itf.elements.kind.value = kind;
    itf.elements.item_id.value = d ? d.id : '';
    const isAddon = kind === 'addon';
    const noun = kind === 'package' ? 'package' : (isAddon ? 'add-on' : 'service');
    document.getElementById('itemTitle').textContent = (d ? 'Edit ' : 'Add ') + noun;
    document.getElementById('itemDurWrap').style.display = isAddon ? 'none' : '';
    itf.elements.duration.required = !isAddon;
    document.getElementById('itemImgWrap').style.display = isAddon ? 'none' : '';
    document.getElementById('itemCatWrap').style.display = kind === 'service' ? '' : 'none';
    document.getElementById('itemRoomWrap').style.display = kind === 'package' ? '' : 'none';
    document.getElementById('itemMaxWrap').style.display = kind === 'package' ? '' : 'none';

    if (d) {
        itf.elements.item_name.value = d.name;
        itf.elements.description.value = d.description;
        if (!isAddon) itf.elements.duration.value = d.duration;
        itf.elements.price.value = d.price;
        itf.elements.is_active.checked = !!d.active;
        if (kind === 'service') itf.elements.category.value = d.category || 'massage';
        if (kind === 'package') { itf.elements.room_type.value = d.room_type || 'all'; itf.elements.max_guests.value = d.max_guests || 1; }
    }
    prev.hidden = isAddon || !(d && d.image);
    prev.innerHTML = (!isAddon && d && d.image) ? '<img src="' + d.image.replace(/"/g, '&quot;') + '" alt="">' : '';
    itemDlg.showModal();
}
document.querySelectorAll('[data-item]').forEach(b => b.addEventListener('click', () => { const d = JSON.parse(b.dataset.item); openItem(d.kind, d); }));
document.querySelectorAll('[data-new-item]').forEach(b => b.addEventListener('click', () => openItem(b.dataset.newItem, null)));
document.querySelectorAll('[data-addon]').forEach(b => b.addEventListener('click', () => openItem('addon', JSON.parse(b.dataset.addon))));
document.querySelectorAll('[data-new-addon]').forEach(b => b.addEventListener('click', () => openItem('addon', null)));
itf.elements.image.addEventListener('change', e => {
    const f = e.target.files[0];
    if (!f) return;
    prev.hidden = false;
    prev.innerHTML = '';
    const img = document.createElement('img');
    img.src = URL.createObjectURL(f);
    prev.appendChild(img);
});

/* ---- live updates ---- */
async function poll() {
    try {
        const s = await (await fetch('admin_dashboard.php?ajax=stats', { cache: 'no-store' })).json();
        Object.keys(s).forEach(k => { const el = document.querySelector('[data-stat="' + k + '"]'); if (el) el.textContent = s[k]; });
        if (s.latest > INITIAL_LATEST) document.getElementById('liveToast').style.display = 'flex';
    } catch (e) {}
}
setInterval(poll, 20000);

/* ---------- Yes / No confirmation ---------- */
function askConfirm(title, message, yesLabel, noLabel) {
    return new Promise(resolve => {
        const d = document.getElementById('confirmDialog');
        const yes = document.getElementById('cfYes'), no = document.getElementById('cfNo');
        document.getElementById('cfTitle').textContent = title;
        document.getElementById('cfText').textContent = message;
        yes.textContent = yesLabel || 'Yes';
        no.textContent = noLabel || 'No';
        let result = false;
        yes.onclick = () => { result = true; d.close(); };
        no.onclick = () => d.close();
        const done = () => { d.removeEventListener('close', done); resolve(result); };
        d.addEventListener('close', done);
        d.showModal();
        no.focus();
    });
}

const CONFIRMS = {
    delete_appointment: ['Delete booking?', 'This cannot be undone.', 'Yes, delete'],
    delete_room:        ['Delete room?', 'This cannot be undone.', 'Yes, delete'],
    delete_item:        ['Delete item?', 'This cannot be undone.', 'Yes, delete'],
    save_appointment:   ['Save booking?', 'Booking details and totals will be saved.', 'Yes, save'],
    save_room:          ['Save room?', 'Room changes will appear on the booking page.', 'Yes, save'],
    save_item:          ['Save changes?', 'Updated details will appear on the booking page.', 'Yes, save'],
    delete_admin:       ['Delete admin?', 'This account will lose dashboard access.', 'Yes, delete'],
    logout:             ['Log out?', 'Sign out of admin dashboard?', 'Yes, log out']
};
document.querySelectorAll('form').forEach(f => {
    const opEl = f.querySelector('input[name="op"]');
    if (!opEl) return;
    let c = CONFIRMS[opEl.value];
    if (opEl.value === 'toggle_item' || opEl.value === 'toggle_room') {
        const label = (f.querySelector('button') || {}).textContent.trim();
        c = [label + ' item?', label === 'Deactivate' ? 'Hide from booking page?' : 'Show on booking page?', 'Yes'];
    }
    if (c) { f.dataset.cTitle = c[0]; f.dataset.cText = c[1]; f.dataset.cYes = c[2]; }
});
document.addEventListener('submit', async e => {
    const f = e.target;
    if (!f.dataset.cTitle || f.dataset.ok === '1') return;
    e.preventDefault();
    if (!f.reportValidity()) return;
    const ok = await askConfirm(f.dataset.cTitle, f.dataset.cText, f.dataset.cYes, 'No');
    if (ok) { f.dataset.ok = '1'; f.requestSubmit(); }
});

document.querySelectorAll('select[data-confirm-select]').forEach(sel => {
    sel.dataset.orig = sel.value;
    sel.addEventListener('change', async () => {
        const v = sel.value, code = sel.dataset.code;
        let title, text;
        if (sel.dataset.confirmSelect === 'status') {
            title = 'Mark as ' + v + '?';
            text = 'Update booking ' + code + ' to ' + v + '?';
        } else {
            title = 'Mark payment as ' + v + '?';
            text = 'Update payment for ' + code + ' to ' + v + '?';
        }
        const ok = await askConfirm(title, text, 'Yes', 'No');
        if (ok) sel.form.submit(); else sel.value = sel.dataset.orig;
    });
});

/* ---- admin account dialog ---- */
const adminDlg = document.getElementById('adminDialog');
const adminForm = document.getElementById('adminForm');
function openAdmin(account) {
    adminForm.reset();
    delete adminForm.dataset.ok;
    const isReset = !!account;
    adminForm.elements.admin_id.value = isReset ? account.id : '';
    adminForm.elements.admin_username.value = isReset ? account.username : '';
    adminForm.elements.admin_username.readOnly = isReset;
    adminForm.elements.admin_username.required = !isReset;
    document.getElementById('adminTitle').textContent = isReset ? 'Reset password' : 'Add admin';
    adminForm.dataset.cTitle = isReset ? 'Reset password?' : 'Add admin?';
    adminForm.dataset.cText = isReset
        ? 'Set a new password for ' + account.username + '?'
        : 'Grant dashboard access to this admin?';
    adminForm.dataset.cYes = isReset ? 'Yes, reset' : 'Yes, add';
    adminDlg.showModal();
}
document.querySelectorAll('[data-new-admin]').forEach(b => b.addEventListener('click', () => openAdmin(null)));
document.querySelectorAll('[data-admin]').forEach(b => b.addEventListener('click', () => openAdmin(JSON.parse(b.dataset.admin))));
const passwordField = adminForm.elements.admin_password, repeatField = adminForm.elements.admin_password_confirm;
const checkPasswordsMatch = () => repeatField.setCustomValidity(repeatField.value && passwordField.value !== repeatField.value ? 'Passwords do not match.' : '');
passwordField.addEventListener('input', checkPasswordsMatch);
repeatField.addEventListener('input', checkPasswordsMatch);

/* ---- iframe height ---- */
(function () {
    const topBar = document.querySelector('.topbar');
    const setHeight = () => document.documentElement.style.setProperty('--topbar-h', topBar.offsetHeight + 'px');
    setHeight();
    window.addEventListener('resize', setHeight);
})();

/* ---- auto-hide flash ---- */
const t = document.querySelector('.toast');
if (t) setTimeout(() => { t.style.transition = 'opacity .5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 600); }, 5000);
</script>
</body>
</html>
