<?php
require_once 'config.php';
require_once 'mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: book.php');
    exit;
}

/* ---------- Settings (keep in sync with book.php & admin_dashboard.php) ---------- */
const MAX_GUESTS = 6;
const HOLD_HOURS = 24;
const EXTRA_GUEST_RATE = 1.00;
$downPayment = ['cash' => 0.00, 'ewallet' => 0.30, 'bank' => 0.30];
$providers = [
    'ewallet' => ['gcash', 'maya'],
    'bank'    => ['bdo', 'bpi', 'unionbank'],
];
$validTimes = ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'];

/* ---------- Read input first so we can preserve valid inputs on validation error ---------- */
$name     = trim($_POST['customer_name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$phone    = trim($_POST['phone'] ?? '');
$date     = trim($_POST['appointment_date'] ?? '');
$time     = trim($_POST['appointment_time'] ?? '');
$guests   = filter_input(INPUT_POST, 'guests', FILTER_VALIDATE_INT);
$notes    = trim($_POST['notes'] ?? '');
$method   = trim($_POST['payment_method'] ?? '');
$provider = trim($_POST['payment_provider'] ?? '');
$refNo    = trim($_POST['reference_no'] ?? '');

$ids = function (string $key): array {
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $i = filter_var($v, FILTER_VALIDATE_INT);
        if ($i && $i > 0) $out[$i] = $i;
    }
    return array_values($out);
};
$addonIds = $ids('addon_ids');
$roomId   = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);

$guestPicks = [];
$rawGuestItems = $_POST['guest_items'] ?? [];
if (is_array($rawGuestItems)) {
    foreach ($rawGuestItems as $g => $list) {
        $g = filter_var($g, FILTER_VALIDATE_INT);
        if (!$g || $g < 1 || $g > MAX_GUESTS || !is_array($list)) continue;
        foreach ($list as $v) {
            if (!is_string($v) || !preg_match('/^(service|package):(\d{1,9})$/', $v, $m)) continue;
            $guestPicks[$g][$m[1] . ':' . (int)$m[2]] = ['type' => $m[1], 'id' => (int)$m[2]];
        }
    }
}

$fail = function (string $message, int $step = 1, string $field = '') use (
    $name, $email, $phone, $date, $time, $guests, $notes, $method, $provider, $refNo, $addonIds, $roomId, $guestPicks
): void {
    $serializedPicks = [];
    foreach ($guestPicks as $g => $map) {
        $serializedPicks[(string)$g] = array_keys($map);
    }

    $_SESSION['booking_error'] = $message;
    $_SESSION['booking_error_step'] = max(1, min(5, $step));
    $_SESSION['booking_error_field'] = $field;
    $_SESSION['booking_old'] = [
        'customer_name'    => $name,
        'email'            => $email,
        'phone'            => $phone,
        'appointment_date' => $date,
        'appointment_time' => $time,
        'guests'           => $guests ?: 1,
        'notes'            => $notes,
        'payment_method'   => $method,
        'payment_provider' => $provider,
        'reference_no'     => $refNo,
        'addon_ids'        => $addonIds,
        'room_id'          => $roomId ?: 0,
        'guest_items'      => $serializedPicks,
    ];

    header('Location: book.php');
    exit;
};

/* ---------- Step 1: Guests, Room, Treatments & Add-ons ---------- */
if (!$guests || $guests < 1 || $guests > MAX_GUESTS) {
    $fail('Number of guests must be between 1 and ' . MAX_GUESTS . '.', 1, 'guests');
}

ksort($guestPicks);
for ($g = 1; $g <= $guests; $g++) {
    if (empty($guestPicks[$g])) {
        $fail($guests === 1
            ? 'Please select at least one treatment or package.'
            : 'Please choose at least one treatment or package for Guest ' . $g . '.', 1, 'service');
    }
    if (count($guestPicks[$g]) > 10) {
        $fail('Please select fewer treatments for Guest ' . $g . '.', 1, 'service');
    }
}
$guestPicks = array_intersect_key($guestPicks, array_flip(range(1, $guests)));

$serviceIds = $packageIds = [];
foreach ($guestPicks as $list) {
    foreach ($list as $pick) {
        if ($pick['type'] === 'service') $serviceIds[$pick['id']] = $pick['id'];
        else $packageIds[$pick['id']] = $pick['id'];
    }
}
$serviceIds = array_values($serviceIds);
$packageIds = array_values($packageIds);

$catalog = [];
if ($serviceIds) {
    $in = implode(',', array_fill(0, count($serviceIds), '?'));
    $st = $pdo->prepare("SELECT id, name, price, duration_minutes FROM services WHERE active = 1 AND id IN ($in)");
    $st->execute($serviceIds);
    $rows = $st->fetchAll();
    if (count($rows) !== count($serviceIds)) {
        $fail('One of the selected services is no longer available. Please choose again.', 1, 'service');
    }
    foreach ($rows as $r) {
        $catalog['service:' . (int)$r['id']] = [
            'type' => 'service',
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'duration' => (int)$r['duration_minutes'],
        ];
    }
}
if ($packageIds) {
    try {
        $in = implode(',', array_fill(0, count($packageIds), '?'));
        $st = $pdo->prepare("SELECT id, name, price, duration_minutes FROM packages WHERE active = 1 AND id IN ($in)");
        $st->execute($packageIds);
        $rows = $st->fetchAll();
    } catch (PDOException $e) {
        $rows = [];
    }
    if (count($rows) !== count($packageIds)) {
        $fail('One of the selected packages is no longer available. Please choose again.', 1, 'service');
    }
    foreach ($rows as $r) {
        $catalog['package:' . (int)$r['id']] = [
            'type' => 'package',
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'price' => (float)$r['price'],
            'duration' => (int)$r['duration_minutes'],
        ];
    }
}

$extras = [];
try {
    $roomsOn = (int)$pdo->query('SELECT COUNT(*) FROM rooms WHERE active = 1')->fetchColumn() > 0;
} catch (PDOException $e) {
    $roomsOn = false;
}
$room = null;
if ($roomsOn) {
    if (!$roomId) {
        $fail('Please choose a room.', 1, 'room');
    }
    $st = $pdo->prepare('SELECT id, name, max_guests, price FROM rooms WHERE id = ? AND active = 1');
    $st->execute([$roomId]);
    $room = $st->fetch();
    if (!$room) {
        $fail('The selected room is no longer available. Please choose again.', 1, 'room');
    }
    if ($guests > (int)$room['max_guests']) {
        $fail('The ' . $room['name'] . ' fits up to ' . (int)$room['max_guests'] . ' guest' . ((int)$room['max_guests'] > 1 ? 's' : '') . '.', 1, 'room');
    }
    if ((float)$room['price'] > 0) {
        $extras[] = [
            'type' => 'room',
            'id' => (int)$room['id'],
            'name' => $room['name'] . ' (room fee)',
            'price' => (float)$room['price'],
            'duration' => 0,
            'line_total' => round((float)$room['price'], 2),
        ];
    }
}
if ($addonIds) {
    try {
        $in = implode(',', array_fill(0, count($addonIds), '?'));
        $st = $pdo->prepare("SELECT id, name, price FROM addons WHERE active = 1 AND id IN ($in) ORDER BY id");
        $st->execute($addonIds);
        $rows = $st->fetchAll();
        if (count($rows) !== count($addonIds)) {
            $fail('One of the selected add-ons is no longer available. Please choose again.', 1, 'addon');
        }
        foreach ($rows as $r) {
            $extras[] = [
                'type' => 'addon',
                'id' => (int)$r['id'],
                'name' => $r['name'] . ' (add-on)',
                'price' => (float)$r['price'],
                'duration' => 0,
                'line_total' => round((float)$r['price'], 2),
            ];
        }
    } catch (PDOException $e) {
        $fail('Could not verify selected add-ons. Please try again.', 1, 'addon');
    }
}

/* ---------- Step 2: Date & time ---------- */
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    $fail('Please choose today or a future date.', 2, 'appointment_date');
}
if ((int)$dateObj->format('N') === 1) {
    $fail('The spa is closed on Mondays. Please choose Tuesday to Sunday.', 2, 'appointment_date');
}
if (!in_array($time, $validTimes, true)) {
    $fail('Please select one of the available appointment times.', 2, 'appointment_time');
}
if ($date === date('Y-m-d') && $time <= date('H:i')) {
    $fail('That time has already passed. Please choose a later time.', 2, 'appointment_time');
}

/* ---------- Step 3: Contact details ---------- */
if ($name === '' || mb_strlen($name) > 120 || !preg_match("/^\p{L}[\p{L}\s.'-]*$/u", $name)) {
    $fail('Please enter a valid full name.', 3, 'customer_name');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    $fail('Please enter a valid email address.', 3, 'email');
}
if (!preg_match('/^(09\d{9}|639\d{9})$/', $phone)) {
    $fail('Please enter a valid Philippine mobile number (09XXXXXXXXX or 639XXXXXXXXX).', 3, 'phone');
}
if (mb_strlen($notes) > 1000) {
    $fail('Notes are too long (maximum 1000 characters).', 3, 'notes');
}

/* ---------- Pricing ---------- */
$items = [];
$total = 0.0;
foreach ($guestPicks as $g => $list) {
    $rate = $g === 1 ? 1.0 : EXTRA_GUEST_RATE;
    foreach ($list as $key => $pick) {
        $c = $catalog[$key];
        $line = round($c['price'] * $rate, 2);
        $items[] = $c + ['guest' => $g, 'line_total' => $line];
        $total += $line;
    }
}
foreach ($extras as $ex) {
    $total += $ex['line_total'];
}
$total = round($total, 2);
$allItems = array_merge($items, $extras);

/* ---------- Step 4: Payment ---------- */
if (!isset($downPayment[$method])) {
    $fail('Please select a valid payment method.', 4, 'payment_method');
}
$amountDue = round($total * $downPayment[$method], 2);
$paymentStatus = 'unpaid';
$proofPath = null;

if ($method !== 'cash') {
    if (!in_array($provider, $providers[$method], true)) {
        $fail($method === 'bank' ? 'Please choose a bank.' : 'Please choose an e-wallet.', 4, 'payment_provider');
    }
    if (!preg_match('/^[A-Za-z0-9-]{6,25}$/', $refNo)) {
        $fail('Please enter a valid payment reference number (6–25 letters or numbers).', 4, 'reference_no');
    }
    $f = $_FILES['proof_image'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $fail('Please upload your proof of payment.', 4, 'proof_image');
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        $fail('Proof of payment must be 5 MB or smaller.', 4, 'proof_image');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extMap[$mime])) {
        $fail('Proof of payment must be a JPG, PNG, WEBP or PDF file.', 4, 'proof_image');
    }
    $dir = __DIR__ . '/uploads/payment_proofs';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $fail('We could not store your proof of payment. Please try again.', 4, 'proof_image');
    }
    $fileName = bin2hex(random_bytes(12)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fileName)) {
        $fail('We could not store your proof of payment. Please try again.', 4, 'proof_image');
    }
    $proofPath = 'uploads/payment_proofs/' . $fileName;
    $paymentStatus = 'pending';
} else {
    $provider = '';
}

/* ---------- Step 5: Policy agreement ---------- */
if (empty($_POST['agree_terms'])) {
    if ($proofPath) @unlink(__DIR__ . '/' . $proofPath);
    $fail('Please agree to the booking policies to continue.', 5, 'agree_terms');
}

/* ---------- Save ---------- */
$code = 'BV' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
$firstService = null;
foreach ($items as $it) {
    if ($it['type'] === 'service') {
        $firstService = $it['id'];
        break;
    }
}

try {
    $pdo->exec("UPDATE appointments SET status = 'Cancelled' WHERE status = 'Pending' AND payment_method = 'cash' AND payment_status = 'unpaid' AND created_at < (NOW() - INTERVAL " . (int)HOLD_HOURS . " HOUR)");
} catch (PDOException $e) {
}

try {
    $pdo->beginTransaction();

    $check = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status <> 'Cancelled' FOR UPDATE");
    $check->execute([$date, $time . ':00']);
    if ((int)$check->fetchColumn() > 0) {
        $pdo->rollBack();
        if ($proofPath) @unlink(__DIR__ . '/' . $proofPath);
        $fail('Sorry, that time slot was just booked. Please choose another time.', 2, 'appointment_time');
    }

    $stmt = $pdo->prepare('INSERT INTO appointments
        (booking_code, customer_name, email, phone, service_id, appointment_date, appointment_time, guests, notes, total_price,
         payment_method, payment_provider, payment_status, amount_due, reference_no, proof_image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $code, $name, $email, $phone, $firstService, $date, $time . ':00', $guests, $notes ?: null, $total,
        $method, $provider ?: null, $paymentStatus, $amountDue, $refNo ?: null, $proofPath,
    ]);
    $appointmentId = (int)$pdo->lastInsertId();
    if ($room) {
        $pdo->prepare('UPDATE appointments SET room_id = ? WHERE id = ?')->execute([(int)$room['id'], $appointmentId]);
    }

    $hasGuestNo = false;
    try {
        $hasGuestNo = (bool)$pdo->query("SHOW COLUMNS FROM appointment_items LIKE 'guest_no'")->fetch();
    } catch (PDOException $e) {
    }
    if ($hasGuestNo) {
        $itemStmt = $pdo->prepare('INSERT INTO appointment_items
            (appointment_id, item_type, item_id, item_name, unit_price, duration_minutes, line_total, guest_no)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($allItems as $it) {
            $itemStmt->execute([$appointmentId, $it['type'], $it['id'], $it['name'], $it['price'], $it['duration'], $it['line_total'], $it['guest'] ?? null]);
        }
    } else {
        $itemStmt = $pdo->prepare('INSERT INTO appointment_items
            (appointment_id, item_type, item_id, item_name, unit_price, duration_minutes, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($allItems as $it) {
            $label = (isset($it['guest']) && $guests > 1 ? 'Guest ' . $it['guest'] . ': ' : '') . $it['name'];
            $itemStmt->execute([$appointmentId, $it['type'], $it['id'], $label, $it['price'], $it['duration'], $it['line_total']]);
        }
    }

    $pdo->commit();

    unset($_SESSION['booking_old'], $_SESSION['booking_error_step'], $_SESSION['booking_error_field']);

    [$emailSubject, $emailHtml, $emailText] = bv_email_booking_received([
        'code'         => $code,
        'name'         => $name,
        'date'         => $date,
        'time'         => $time,
        'guests'       => $guests,
        'items'        => array_map(fn($item) => [(isset($item['guest']) && $guests > 1 ? 'Guest ' . $item['guest'] . ': ' : '') . $item['name'], $item['line_total']], $allItems),
        'total'        => $total,
        'down_payment' => $amountDue,
        'method'       => $method,
        'provider'     => $provider,
        'reference'    => $refNo,
        'hold_hours'   => HOLD_HOURS,
    ]);
    [$emailSent, $emailReason] = bv_send_mail($email, $name, $emailSubject, $emailHtml, $emailText);
    $_SESSION['last_booking_mail'] = ['code' => $code, 'sent' => $emailSent, 'email' => $email, 'reason' => $emailReason];

    $_SESSION['last_booking_code'] = $code;
    header('Location: confirmation.php?code=' . urlencode($code));
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($proofPath) @unlink(__DIR__ . '/' . $proofPath);
    error_log('save_booking: ' . $e->getMessage());
    $local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
    $fail('We could not save your booking. Please try again or contact the spa.' . ($local ? ' [' . $e->getMessage() . ']' : ''), 5);
}
