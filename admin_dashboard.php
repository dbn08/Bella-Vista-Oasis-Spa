<?php
require_once 'config.php';

/* ---------- Access control ---------- */
if (empty($_SESSION['admin_id'])) {
    header('Location: admin_login.php'); 
    exit;
}
$adminName = $_SESSION['admin_username'] ?? 'admin';

/* ---------- Rules (keep in sync with book.php / save_booking.php) ---------- */
const BV_SLOTS = ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'];
const BV_MAX_GUESTS = 6;
const BV_EXTRA_RATE = 0.80;
$downRates    = ['cash' => 0.10, 'ewallet' => 0.30, 'bank' => 0.30];
$providers    = ['ewallet' => ['gcash' => 'GCash', 'maya' => 'Maya'], 'bank' => ['bdo' => 'BDO', 'bpi' => 'BPI', 'unionbank' => 'UnionBank']];
$methodLabels = ['cash' => 'Cash', 'ewallet' => 'E-wallet', 'bank' => 'Online banking'];
$statuses     = ['Pending', 'Confirmed', 'Completed', 'Cancelled'];
$payStatuses  = ['unpaid', 'pending', 'verified', 'refunded'];
$kinds        = ['service' => 'services', 'package' => 'packages'];

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

function bv_stats(PDO $pdo): array {
    $r = $pdo->query("SELECT COUNT(*) AS total,
            SUM(status = 'Pending') AS pending,
            SUM(status = 'Confirmed') AS confirmed,
            SUM(appointment_date = CURDATE() AND status <> 'Cancelled') AS today,
            SUM(payment_status = 'pending') AS pay_pending,
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

/* Items (treatments/packages) of several appointments: [appointment_id => [rows]] */
function bv_items_for(PDO $pdo, array $ids): array {
    $out = [];
    if (!$ids) return $out;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT appointment_id, item_type, item_id, item_name FROM appointment_items WHERE appointment_id IN ($in) ORDER BY id");
    $st->execute(array_values($ids));
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['appointment_id']][] = $r;
    }
    return $out;
}

function bv_slot_taken(PDO $pdo, string $date, string $time, int $exceptId = 0): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status <> 'Cancelled' AND id <> ?");
    $st->execute([$date, $time . ':00', $exceptId]);
    return (int)$st->fetchColumn() > 0;
}

function bv_save_image(string $folder, int $id, array $file, ?string $old): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The picture could not be uploaded.');
    if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('Pictures must be 3 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) throw new RuntimeException('Pictures must be JPG, PNG or WEBP.');
    $dir = __DIR__ . '/assets/images/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the pictures folder.');
    $name = $id . '-' . bin2hex(random_bytes(3)) . '.' . $map[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Could not save the picture.');
    $prefix = 'assets/images/' . $folder . '/';
    if ($old && strpos($old, $prefix) === 0 && strpos($old, '..') === false) {
        @unlink(__DIR__ . '/' . $old);
    }
    return $prefix . $name;
}

function bv_remove_file(?string $path, string $prefix): void {
    if ($path && strpos($path, $prefix) === 0 && strpos($path, '..') === false) {
        @unlink(__DIR__ . '/' . $path);
    }
}

/* ---------- Live stats (polled by the page every 20 seconds) ---------- */
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
        bv_flash('error', 'Your session expired. Please try again.');
        bv_back($back);
    }
    $op = $_POST['op'] ?? '';

    try {
        /* ----- quick status changes ----- */
        if ($op === 'set_status') {
            $id = (int)($_POST['appointment_id'] ?? 0);
            $status = $_POST['booking_status'] ?? '';
            if (!$id || !in_array($status, $statuses, true)) {
                bv_flash('error', 'Invalid status.');
                bv_back($back);
            }
            $row = $pdo->prepare('SELECT appointment_date, appointment_time FROM appointments WHERE id = ?');
            $row->execute([$id]);
            $cur = $row->fetch();
            if (!$cur) {
                bv_flash('error', 'Booking not found.');
                bv_back($back);
            }
            if ($status !== 'Cancelled' && bv_slot_taken($pdo, $cur['appointment_date'], substr($cur['appointment_time'], 0, 5), $id)) {
                bv_flash('error', 'That time slot is already taken by another booking.');
                bv_back($back);
            }
            $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $id]);
            bv_flash('success', 'Booking status updated to ' . $status . '.');
            bv_back($back);
        }

        if ($op === 'set_pay_status') {
            $id = (int)($_POST['appointment_id'] ?? 0);
            $ps = $_POST['pay_status'] ?? '';
            if ($id && in_array($ps, $payStatuses, true)) {
                $pdo->prepare('UPDATE appointments SET payment_status = ? WHERE id = ?')->execute([$ps, $id]);
                bv_flash('success', 'Payment marked as ' . $ps . '.');
            }
            bv_back($back);
        }

        /* ----- create / update appointment ----- */
        if ($op === 'save_appointment') {
            $id      = (int)($_POST['appointment_id'] ?? 0);
            $name    = trim($_POST['customer_name'] ?? '');
            $email   = trim($_POST['email'] ?? '');
            $phone   = trim($_POST['phone'] ?? '');
            $date    = $_POST['appointment_date'] ?? '';
            $time    = $_POST['appointment_time'] ?? '';
            $guests  = filter_var($_POST['guests'] ?? '', FILTER_VALIDATE_INT);
            $notes   = trim($_POST['notes'] ?? '');
            $status  = $_POST['booking_status'] ?? 'Pending';
            $method  = $_POST['pay_method'] ?? 'cash';
            $provider = $_POST['pay_provider'] ?? '';
            $payStatus = $_POST['pay_status'] ?? 'unpaid';
            $refNo   = trim($_POST['pay_ref'] ?? '');

            $err = '';
            if ($name === '' || mb_strlen($name) > 120 || !preg_match("/^\p{L}[\p{L}\s.'-]*$/u", $name)) $err = 'Please enter a valid guest name.';
            elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) $err = 'Please enter a valid email address.';
            elseif (!preg_match('/^(09\d{9}|639\d{9})$/', $phone)) $err = 'Phone must be 09XXXXXXXXX or 639XXXXXXXXX.';
            elseif (!$guests || $guests < 1 || $guests > BV_MAX_GUESTS) $err = 'Guests must be between 1 and ' . BV_MAX_GUESTS . '.';
            elseif (mb_strlen($notes) > 1000) $err = 'Notes are too long.';
            elseif (!in_array($status, $statuses, true)) $err = 'Invalid booking status.';
            elseif (!isset($downRates[$method])) $err = 'Invalid payment method.';
            elseif (!in_array($payStatus, $payStatuses, true)) $err = 'Invalid payment status.';
            elseif ($refNo !== '' && !preg_match('/^[A-Za-z0-9-]{4,25}$/', $refNo)) $err = 'Reference number must be 4–25 letters or numbers.';

            $dateObj = $err === '' ? DateTime::createFromFormat('Y-m-d', $date) : null;
            if ($err === '' && (!$dateObj || $dateObj->format('Y-m-d') !== $date)) $err = 'Please choose a valid date.';
            if ($err === '' && (int)$dateObj->format('N') === 1) $err = 'The spa is closed on Mondays.';
            if ($err === '' && !in_array($time, BV_SLOTS, true)) $err = 'Please choose a valid time slot.';

            $orig = null;
            if ($err === '' && $id) {
                $st = $pdo->prepare('SELECT appointment_date, appointment_time FROM appointments WHERE id = ?');
                $st->execute([$id]);
                $orig = $st->fetch();
                if (!$orig) $err = 'Booking not found.';
            }
            if ($err === '') {
                $changed = !$orig || $orig['appointment_date'] !== $date || substr($orig['appointment_time'], 0, 5) !== $time;
                if ($changed && $date < date('Y-m-d')) $err = 'You cannot book a past date.';
                elseif ($changed && $date === date('Y-m-d') && $time <= date('H:i')) $err = 'That time has already passed.';
                elseif ($status !== 'Cancelled' && bv_slot_taken($pdo, $date, $time, $id)) $err = 'That time slot is already booked.';
            }
            if ($err === '' && $method !== 'cash') {
                if (!isset($providers[$method][$provider])) $err = 'Please choose the e-wallet or bank used.';
            } else {
                if ($method === 'cash') $provider = '';
            }

            /* items */
            $items = [];
            if ($err === '') {
                $sel = ['service' => [], 'package' => []];
                foreach ((array)($_POST['items'] ?? []) as $v) {
                    if (preg_match('/^(service|package):(\d+)$/', (string)$v, $m)) $sel[$m[1]][(int)$m[2]] = (int)$m[2];
                }
                foreach ($sel as $type => $ids) {
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
                    if (count($rows) !== count($ids)) { $err = 'A selected treatment or package no longer exists.'; break; }
                    foreach ($rows as $r) {
                        $items[] = ['type' => $type, 'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'duration' => (int)$r['duration_minutes']];
                    }
                }
                if ($err === '' && !$items) $err = 'Please select at least one treatment or package.';
            }

            if ($err !== '') {
                bv_flash('error', $err);
                bv_back($back);
            }

            $total = 0.0;
            foreach ($items as &$it) {
                $it['line_total'] = round($it['price'] + ($guests - 1) * $it['price'] * BV_EXTRA_RATE, 2);
                $total += $it['line_total'];
            }
            unset($it);
            $total = round($total, 2);
            $amountDue = round($total * $downRates[$method], 2);
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
            $ins = $pdo->prepare('INSERT INTO appointment_items (appointment_id, item_type, item_id, item_name, unit_price, duration_minutes, line_total) VALUES (?,?,?,?,?,?,?)');
            foreach ($items as $it) {
                $ins->execute([$id, $it['type'], $it['id'], $it['name'], $it['price'], $it['duration'], $it['line_total']]);
            }
            $pdo->commit();
            bv_flash('success', $msg);
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

        /* ----- create / update service or package ----- */
        if ($op === 'save_item') {
            $kind = $_POST['kind'] ?? '';
            if (!isset($kinds[$kind])) { bv_flash('error', 'Unknown item type.'); bv_back($back); }
            $table = $kinds[$kind];
            $label = $kind === 'package' ? 'Package' : 'Treatment';
            $id    = (int)($_POST['item_id'] ?? 0);
            $iname = trim($_POST['item_name'] ?? '');
            $desc  = trim($_POST['description'] ?? '');
            $dur   = filter_var($_POST['duration'] ?? '', FILTER_VALIDATE_INT);
            $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($iname === '' || mb_strlen($iname) > 120) { bv_flash('error', 'Please enter a name (max 120 characters).'); bv_back($back); }
            if (mb_strlen($desc) > 1000) { bv_flash('error', 'Description is too long (max 1000 characters).'); bv_back($back); }
            if (!$dur || $dur < 5 || $dur > 600) { bv_flash('error', 'Duration must be between 5 and 600 minutes.'); bv_back($back); }
            if ($price === false || $price < 0 || $price > 1000000) { bv_flash('error', 'Please enter a valid price.'); bv_back($back); }

            $old = null;
            if ($id) {
                $st = $pdo->prepare("SELECT image FROM $table WHERE id = ?");
                $st->execute([$id]);
                $found = $st->fetch();
                if (!$found) { bv_flash('error', $label . ' not found.'); bv_back($back); }
                $old = $found['image'] ?? null;
                $pdo->prepare("UPDATE $table SET name = ?, description = ?, duration_minutes = ?, price = ?, active = ? WHERE id = ?")
                    ->execute([$iname, $desc, $dur, $price, $active, $id]);
                $msg = $label . ' updated.';
            } else {
                $pdo->prepare("INSERT INTO $table (name, description, duration_minutes, price, active) VALUES (?,?,?,?,?)")
                    ->execute([$iname, $desc, $dur, $price, $active]);
                $id = (int)$pdo->lastInsertId();
                $msg = $label . ' added.';
            }
            try {
                $path = bv_save_image($table, $id, $_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE], $old);
                if ($path) $pdo->prepare("UPDATE $table SET image = ? WHERE id = ?")->execute([$path, $id]);
            } catch (RuntimeException $e) {
                bv_flash('error', $label . ' saved, but the picture was not: ' . $e->getMessage());
                bv_back($back);
            }
            bv_flash('success', $msg);
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
                $st = $pdo->prepare("SELECT image FROM $table WHERE id = ?");
                $st->execute([$id]);
                $img = $st->fetchColumn();
                try {
                    $pdo->prepare("DELETE FROM $table WHERE id = ?")->execute([$id]);
                    bv_remove_file($img ?: null, 'assets/images/' . $table . '/');
                    bv_flash('success', 'Deleted.');
                } catch (PDOException $e) {
                    bv_flash('error', 'This item is used by existing bookings and cannot be deleted. Deactivate it instead.');
                }
            }
            bv_back($back);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_dashboard: ' . $e->getMessage());
        $local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
        bv_flash('error', 'Something went wrong while saving.' . ($local ? ' [' . $e->getMessage() . ']' : ''));
        bv_back($back);
    }
    bv_back($back);
}

/* ---------- Page data ---------- */
$tab = in_array($_GET['tab'] ?? '', ['overview', 'appointments', 'services', 'packages'], true) ? $_GET['tab'] : 'overview';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stats = bv_stats($pdo);
$services = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();
try {
    $packages = $pdo->query('SELECT * FROM packages ORDER BY name')->fetchAll();
    $hasPackages = true;
} catch (PDOException $e) {
    $packages = [];
    $hasPackages = false;
}

/* Overview lists */
$upcoming = $attention = [];
if ($tab === 'overview') {
    $upcoming = $pdo->query("SELECT * FROM appointments WHERE appointment_date >= CURDATE() AND status IN ('Pending','Confirmed') ORDER BY appointment_date, appointment_time LIMIT 8")->fetchAll();
    $attention = $pdo->query("SELECT * FROM appointments WHERE status = 'Pending' OR payment_status = 'pending' ORDER BY id DESC LIMIT 8")->fetchAll();
}

/* Appointments list with filters */
$rows = $itemsMap = [];
$page = 1; $pages = 1; $totalRows = 0;
$q = trim($_GET['q'] ?? '');
$fStatus = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';
$fPay = in_array($_GET['pay'] ?? '', $payStatuses, true) ? $_GET['pay'] : '';
$fDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : '';
if ($tab === 'appointments') {
    $where = ['1=1']; $params = [];
    if ($q !== '') {
        $where[] = '(a.booking_code LIKE ? OR a.customer_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)';
        for ($i = 0; $i < 4; $i++) $params[] = '%' . $q . '%';
    }
    if ($fStatus) { $where[] = 'a.status = ?'; $params[] = $fStatus; }
    if ($fPay) { $where[] = 'a.payment_status = ?'; $params[] = $fPay; }
    if ($fDate) { $where[] = 'a.appointment_date = ?'; $params[] = $fDate; }
    $whereSql = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM appointments a WHERE $whereSql");
    $cnt->execute($params);
    $totalRows = (int)$cnt->fetchColumn();
    $per = 10;
    $pages = max(1, (int)ceil($totalRows / $per));
    $page = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $off = ($page - 1) * $per;

    $st = $pdo->prepare("SELECT a.*, s.name AS service_name FROM appointments a LEFT JOIN services s ON s.id = a.service_id WHERE $whereSql ORDER BY a.id DESC LIMIT $per OFFSET $off");
    $st->execute($params);
    $rows = $st->fetchAll();
    $itemsMap = bv_items_for($pdo, array_column($rows, 'id'));
}

$filterQs = http_build_query(array_filter(['q' => $q, 'status' => $fStatus, 'pay' => $fPay, 'date' => $fDate]));
$backQs = 'tab=' . $tab . ($tab === 'appointments' ? '&' . $filterQs . '&page=' . $page : '');
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

/* Table of services or packages */
function bv_render_items(array $list, string $kind, string $csrf, string $back): void {
    $icon = $kind === 'package' ? '❀' : '✦';
    if (!$list) {
        echo '<div class="empty-state">Nothing here yet. Use the Add button to create your first one.</div>';
        return;
    }
    echo '<div class="card table-card"><div class="table-wrap"><table class="tbl"><thead><tr>
        <th>Picture</th><th>Name &amp; description</th><th>Duration</th><th>Price</th><th>Status</th><th class="right">Actions</th></tr></thead><tbody>';
    foreach ($list as $it) {
        $data = ['id' => (int)$it['id'], 'kind' => $kind, 'name' => $it['name'], 'description' => $it['description'] ?? '',
                 'duration' => (int)$it['duration_minutes'], 'price' => (float)$it['price'], 'active' => (int)$it['active'], 'image' => $it['image'] ?? ''];
        echo '<tr>
            <td><div class="thumb">' . bv_thumb($it['image'] ?? '', $icon) . '</div></td>
            <td><strong>' . bv_h($it['name']) . '</strong><small class="clamp">' . bv_h($it['description'] ?? '') . '</small></td>
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
                <form method="post" class="inline" onsubmit="return confirm(\'Delete this permanently?\');">
                    <input type="hidden" name="csrf" value="' . bv_h($csrf) . '"><input type="hidden" name="back" value="' . bv_h($back) . '">
                    <input type="hidden" name="op" value="delete_item"><input type="hidden" name="kind" value="' . $kind . '"><input type="hidden" name="item_id" value="' . (int)$it['id'] . '">
                    <button class="btn btn-sm btn-danger">Delete</button>
                </form>
            </div></td></tr>';
    }
    echo '</tbody></table></div></div>';
}

function bv_item_names(array $a, array $itemsMap): string {
    $names = [];
    foreach ($itemsMap[(int)$a['id']] ?? [] as $r) $names[] = $r['item_name'];
    if (!$names && !empty($a['service_name'])) $names[] = $a['service_name'];
    return $names ? implode(', ', array_map('bv_h', $names)) : '<span class="muted">—</span>';
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
.topbar-in { max-width:1320px; margin:0 auto; padding:14px 28px; display:flex; align-items:center; gap:28px; flex-wrap:wrap; }
.brand { display:flex; align-items:center; gap:12px; text-decoration:none; }
.brand-mark { width:42px; height:42px; border-radius:50%; border:1px solid #cdbfae; display:flex; align-items:center; justify-content:center; color:#b58a52; font-size:18px; }
.brand-text { font-family:var(--serif); font-size:20px; letter-spacing:.2em; line-height:1.1; }
.brand-text small { display:block; font-family:var(--sans); font-size:10px; letter-spacing:.3em; color:var(--muted); }
.tabs { display:flex; gap:4px; flex:1; overflow-x:auto; }
.tabs a { text-decoration:none; padding:9px 18px; border-radius:999px; font-size:14px; color:var(--muted); white-space:nowrap; }
.tabs a:hover { background:#f3ebdf; color:var(--ink); }
.tabs a.active { background:var(--brown); color:#fff; }
.top-actions { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--muted); }
.wrap { max-width:1320px; margin:0 auto; padding:32px 28px 60px; }
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

.card { background:var(--card); border:1px solid var(--line); border-radius:22px; }
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:16px; margin-bottom:28px; }
.stat { padding:20px 22px; }
.stat span { display:block; font-size:13px; color:var(--muted); }
.stat strong { display:block; margin-top:6px; font-family:var(--serif); font-size:34px; font-weight:600; line-height:1.1; }
.stat.hot strong { color:var(--amber); }
.stat small { color:var(--muted); font-size:12px; }

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
.tbl td { padding:16px 18px; border-top:1px solid #f0e7da; vertical-align:top; font-size:14px; }
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
.proof-link { font-size:12.5px; color:var(--green); }

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
.fgrid input[type=text], .fgrid input[type=email], .fgrid input[type=tel], .fgrid input[type=date], .fgrid input[type=number], .fgrid input[type=file], .fgrid select, .fgrid textarea {
    width:100%; margin-top:6px; font:inherit; font-size:14px; padding:11px 16px; border:1px solid var(--line); border-radius:14px; background:#fff; color:var(--ink); }
.fgrid textarea { resize:vertical; min-height:80px; }
.fgrid input:focus, .fgrid select:focus, .fgrid textarea:focus { outline:2px solid rgba(100,133,104,.35); border-color:var(--green); }
.fgrid .check { display:flex; align-items:center; gap:10px; font-weight:400; }
.fgrid .check input { width:auto; margin:0; }
.sect { font-size:11px; letter-spacing:.2em; text-transform:uppercase; color:#b58a52; margin:8px 0 -4px; grid-column:1 / -1; }
.pick { border:1px solid var(--line); border-radius:14px; background:#fff; max-height:190px; overflow-y:auto; padding:6px; margin-top:6px; }
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
            <a href="?tab=services" class="<?= $tab === 'services' ? 'active' : '' ?>">Treatments</a>
            <a href="?tab=packages" class="<?= $tab === 'packages' ? 'active' : '' ?>">Packages</a>
        </nav>
        <div class="top-actions">
            <a class="btn btn-sm" href="book.php" target="_blank">Booking page ↗</a>
            <span><?= bv_h($adminName) ?></span>
            <a class="btn btn-sm btn-ghost" href="admin_logout.php">Log out</a>
        </div>
    </div>
</header>

<main class="wrap">
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
        <div class="card stat"><span>Confirmed / completed value</span><strong data-stat="revenue"><?= bv_h($stats['revenue']) ?></strong></div>
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
            <h2>Needs your attention</h2>
            <?php if (!$attention): ?>
                <div class="empty-state">You're all caught up.</div>
            <?php else: ?>
                <ul class="list">
                <?php foreach ($attention as $a): ?>
                    <li>
                        <div>
                            <strong><?= bv_h($a['customer_name']) ?> <small><?= bv_h($a['booking_code']) ?></small></strong>
                            <small><?= bv_money($a['total_price']) ?> · due now <?= bv_money($a['amount_due']) ?></small>
                        </div>
                        <div style="text-align:right">
                            <?= bv_badge($a['status']) ?>
                            <?php if ($a['payment_status'] === 'pending'): ?> <?= bv_pay_badge('pending') ?><?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
                <p style="margin:14px 0 0"><a class="btn btn-sm" href="?tab=appointments&status=Pending">Review pending bookings →</a></p>
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
        <input type="search" name="q" value="<?= bv_h($q) ?>" placeholder="Search name, email, phone or booking code">
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
            <th>Booking</th><th>Guest</th><th>Treatments</th><th>Schedule</th><th>Payment</th><th>Total</th><th>Status</th><th class="right">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $a):
            $sel = [];
            foreach ($itemsMap[(int)$a['id']] ?? [] as $r) $sel[] = $r['item_type'] . ':' . $r['item_id'];
            if (!$sel && $a['service_id']) $sel[] = 'service:' . (int)$a['service_id'];
            $data = [
                'id' => (int)$a['id'], 'name' => $a['customer_name'], 'email' => $a['email'], 'phone' => $a['phone'],
                'date' => $a['appointment_date'], 'time' => substr($a['appointment_time'], 0, 5), 'guests' => (int)$a['guests'],
                'notes' => $a['notes'] ?? '', 'status' => $a['status'], 'method' => $a['payment_method'], 'provider' => $a['payment_provider'] ?? '',
                'pay_status' => $a['payment_status'], 'ref' => $a['reference_no'] ?? '', 'items' => $sel,
            ];
        ?>
            <tr>
                <td><strong><?= bv_h($a['booking_code']) ?></strong><small><?= date('M j, Y', strtotime($a['created_at'])) ?></small></td>
                <td><strong><?= bv_h($a['customer_name']) ?></strong><small><?= bv_h($a['email']) ?></small><small><?= bv_h($a['phone']) ?></small></td>
                <td><?= bv_item_names($a, $itemsMap) ?></td>
                <td><strong><?= date('M j, Y', strtotime($a['appointment_date'])) ?></strong><small><?= date('g:i A', strtotime($a['appointment_time'])) ?> · <?= (int)$a['guests'] ?> guest<?= $a['guests'] > 1 ? 's' : '' ?></small></td>
                <td>
                    <strong><?= bv_h($methodLabels[$a['payment_method']] ?? $a['payment_method']) ?><?= $a['payment_provider'] ? ' · ' . bv_h($providers[$a['payment_method']][$a['payment_provider']] ?? $a['payment_provider']) : '' ?></strong>
                    <small>Due now <?= bv_money($a['amount_due']) ?><?= $a['reference_no'] ? ' · Ref ' . bv_h($a['reference_no']) : '' ?></small>
                    <?php if ($a['proof_image']): ?><small><a class="proof-link" href="<?= bv_h($a['proof_image']) ?>" target="_blank">View proof ↗</a></small><?php endif; ?>
                    <form method="post" class="inline" style="margin-top:6px;display:block">
                        <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>"><input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
                        <input type="hidden" name="op" value="set_pay_status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>">
                        <select name="pay_status" onchange="this.form.submit()">
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
                        <select name="booking_status" onchange="this.form.submit()">
                            <?php foreach ($statuses as $s): ?><option <?= $a['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                        </select>
                    </form>
                </td>
                <td class="right"><div class="actions">
                    <button type="button" class="btn btn-sm" data-appt="<?= bv_h(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>">Edit</button>
                    <form method="post" class="inline" onsubmit="return confirm('Delete this booking permanently?');">
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

<?php elseif ($tab === 'services'): ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Treatment menu</div>
            <h1>Treatments</h1>
            <p><?= count($services) ?> treatment<?= count($services) === 1 ? '' : 's' ?> · pictures and descriptions appear on the booking page</p>
        </div>
        <button class="btn" type="button" data-new-item="service">+ Add treatment</button>
    </div>
    <?php bv_render_items($services, 'service', $csrf, $backQs); ?>

<?php else: ?>
    <div class="page-head">
        <div>
            <div class="eyebrow">Bundles</div>
            <h1>Packages</h1>
            <p><?= count($packages) ?> package<?= count($packages) === 1 ? '' : 's' ?></p>
        </div>
        <?php if ($hasPackages): ?><button class="btn" type="button" data-new-item="package">+ Add package</button><?php endif; ?>
    </div>
    <?php if ($hasPackages): bv_render_items($packages, 'package', $csrf, $backQs); else: ?>
        <div class="empty-state">The <code>packages</code> table does not exist yet. Import <code>bella_vista_spa.sql</code> first.</div>
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

        <div class="sect">Treatments</div>
        <div class="full">
            <div class="pick">
                <div class="grp">Treatments</div>
                <?php foreach ($services as $s): ?>
                    <label><input type="checkbox" name="items[]" value="service:<?= (int)$s['id'] ?>"><?= bv_h($s['name']) ?><?= $s['active'] ? '' : ' (hidden)' ?><span class="pr"><?= bv_money($s['price']) ?></span></label>
                <?php endforeach; ?>
                <?php if ($packages): ?><div class="grp">Packages</div>
                    <?php foreach ($packages as $p): ?>
                        <label><input type="checkbox" name="items[]" value="package:<?= (int)$p['id'] ?>"><?= bv_h($p['name']) ?><?= $p['active'] ? '' : ' (hidden)' ?><span class="pr"><?= bv_money($p['price']) ?></span></label>
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
        <div class="full hint" style="margin-top:-6px">Closed on Mondays. Booked time slots are disabled.</div>

        <div class="sect">Payment</div>
        <label>Method
            <select name="pay_method"><?php foreach ($methodLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
        </label>
        <label>Provider<select name="pay_provider"></select></label>
        <label>Payment status
            <select name="pay_status"><?php foreach ($payStatuses as $s): ?><option value="<?= $s ?>"><?= ucfirst($s) ?></option><?php endforeach; ?></select>
        </label>
        <label>Reference number (optional)<input type="text" name="pay_ref" maxlength="25"></label>
        <div class="full hint" style="margin-top:-6px">Total and down payment are calculated automatically.</div>

        <label class="full">Notes<textarea name="notes" maxlength="1000"></textarea></label>
    </div></div>
    <div class="dlg-foot">
        <button type="button" class="btn btn-light" data-close>Cancel</button>
        <button type="submit" class="btn">Save booking</button>
    </div>
</form>
</dialog>

<!-- ===== Treatment / package dialog ===== -->
<dialog id="itemDialog">
<form method="post" id="itemForm" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= bv_h($csrf) ?>">
    <input type="hidden" name="back" value="<?= bv_h($backQs) ?>">
    <input type="hidden" name="op" value="save_item">
    <input type="hidden" name="kind" value="service">
    <input type="hidden" name="item_id" value="">
    <div class="dlg-head"><h3 id="itemTitle">Add treatment</h3><button type="button" class="dlg-x" data-close>&times;</button></div>
    <div class="dlg-body"><div class="fgrid">
        <label class="full">Name<input type="text" name="item_name" required maxlength="120"></label>
        <label class="full">Description<textarea name="description" maxlength="1000" placeholder="Shown on the booking page"></textarea></label>
        <label>Duration (minutes)<input type="number" name="duration" min="5" max="600" step="5" required value="60"></label>
        <label>Price (₱)<input type="number" name="price" min="0" step="0.01" required></label>
        <div class="full">
            <label>Picture (JPG, PNG or WEBP, max 3 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            <div class="preview" id="itemPreview" hidden></div>
        </div>
        <label class="check full"><input type="checkbox" name="is_active" value="1" checked> Show on the booking page</label>
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

/* ---- dialogs ---- */
document.querySelectorAll('dialog').forEach(d => {
    d.addEventListener('click', e => { if (e.target === d || e.target.hasAttribute('data-close')) d.close(); });
});

/* ---- appointment dialog ---- */
const apptDlg = document.getElementById('apptDialog');
const af = document.getElementById('apptForm');

function fillProviders(method, selected) {
    const sel = af.elements.pay_provider;
    sel.innerHTML = '';
    const list = PROVIDERS[method];
    if (!list) { sel.add(new Option('Not needed', '')); sel.disabled = true; return; }
    sel.disabled = false;
    Object.entries(list).forEach(([k, v]) => { const o = new Option(v, k); o.selected = (k === selected); sel.add(o); });
}

async function refreshSlots() {
    const date = af.elements.appointment_date.value;
    const sel = af.elements.appointment_time;
    const own = date === af.dataset.origDate ? af.dataset.origTime : '';
    let booked = [];
    if (date) {
        try { booked = (await (await fetch('check_slots.php?date=' + encodeURIComponent(date))).json()).booked || []; } catch (e) {}
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
    if (d) {
        af.elements.customer_name.value = d.name;
        af.elements.email.value = d.email;
        af.elements.phone.value = d.phone;
        af.elements.appointment_date.value = d.date;
        af.elements.guests.value = d.guests;
        af.elements.notes.value = d.notes;
        af.elements.booking_status.value = d.status;
        af.elements.pay_method.value = d.method;
        af.elements.pay_status.value = d.pay_status;
        af.elements.pay_ref.value = d.ref;
    } else {
        af.elements.booking_status.value = 'Confirmed';
    }
    af.querySelectorAll('input[name="items[]"]').forEach(c => c.checked = !!d && d.items.includes(c.value));
    fillProviders(af.elements.pay_method.value, d ? d.provider : '');
    refreshSlots().then(() => { if (d) af.elements.appointment_time.value = d.time; });
    apptDlg.showModal();
}

af.elements.pay_method.addEventListener('change', () => fillProviders(af.elements.pay_method.value, ''));
af.elements.appointment_date.addEventListener('change', refreshSlots);
document.querySelectorAll('[data-appt]').forEach(b => b.addEventListener('click', () => openAppt(JSON.parse(b.dataset.appt))));
document.querySelectorAll('[data-new-appt]').forEach(b => b.addEventListener('click', () => openAppt(null)));
af.elements.phone.addEventListener('input', e => { e.target.value = e.target.value.replace(/\D/g, '').slice(0, 12); });

/* ---- treatment / package dialog ---- */
const itemDlg = document.getElementById('itemDialog');
const itf = document.getElementById('itemForm');
const prev = document.getElementById('itemPreview');

function openItem(kind, d) {
    itf.reset();
    itf.elements.kind.value = kind;
    itf.elements.item_id.value = d ? d.id : '';
    const noun = kind === 'package' ? 'package' : 'treatment';
    document.getElementById('itemTitle').textContent = (d ? 'Edit ' : 'Add ') + noun;
    if (d) {
        itf.elements.item_name.value = d.name;
        itf.elements.description.value = d.description;
        itf.elements.duration.value = d.duration;
        itf.elements.price.value = d.price;
        itf.elements.is_active.checked = !!d.active;
    }
    prev.hidden = !(d && d.image);
    prev.innerHTML = d && d.image ? '<img src="' + d.image.replace(/"/g, '&quot;') + '" alt="">' : '';
    itemDlg.showModal();
}
document.querySelectorAll('[data-item]').forEach(b => b.addEventListener('click', () => { const d = JSON.parse(b.dataset.item); openItem(d.kind, d); }));
document.querySelectorAll('[data-new-item]').forEach(b => b.addEventListener('click', () => openItem(b.dataset.newItem, null)));
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

/* ---- auto-hide flash ---- */
const t = document.querySelector('.toast');
if (t) setTimeout(() => { t.style.transition = 'opacity .5s'; t.style.opacity = '0'; setTimeout(() => t.remove(), 600); }, 5000);
</script>
</body>
</html>
