<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: book.php');
    exit;
}

/* ---------- Settings (keep in sync with book.php) ---------- */
const MAX_GUESTS = 6;
const HOLD_HOURS = 24;                              // unpaid cash reservations are released after this
const EXTRA_GUEST_RATE = 0.80;                       // each additional guest pays 80%
$downPayment = ['cash' => 0.10, 'ewallet' => 0.30, 'bank' => 0.30];
$providers = [
    'ewallet' => ['gcash', 'maya'],
    'bank'    => ['bdo', 'bpi', 'unionbank'],
];
$validTimes = ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'];

$fail = function (string $message): void {
    $_SESSION['booking_error'] = $message;
    header('Location: book.php');
    exit;
};

/* ---------- Read input ---------- */
$name    = trim($_POST['customer_name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$date    = $_POST['appointment_date'] ?? '';
$time    = $_POST['appointment_time'] ?? '';
$guests  = filter_input(INPUT_POST, 'guests', FILTER_VALIDATE_INT);
$notes   = trim($_POST['notes'] ?? '');
$method  = $_POST['payment_method'] ?? '';
$provider = $_POST['payment_provider'] ?? '';
$refNo   = trim($_POST['reference_no'] ?? '');

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
$serviceIds = $ids('service_ids');
$packageIds = $ids('package_ids');
if (!$serviceIds && !empty($_POST['service_id'])) {            // fallback for the old single-service form
    $one = filter_var($_POST['service_id'], FILTER_VALIDATE_INT);
    if ($one) $serviceIds = [$one];
}

/* ---------- Contact details ---------- */
if ($name === '' || mb_strlen($name) > 120 || !preg_match("/^\p{L}[\p{L}\s.'-]*$/u", $name)) {
    $fail('Please enter a valid full name.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    $fail('Please enter a valid email address.');
}
if (!preg_match('/^(09\d{9}|639\d{9})$/', $phone)) {
    $fail('Please enter a valid Philippine mobile number (09XXXXXXXXX or 639XXXXXXXXX).');
}
if (!$guests || $guests < 1 || $guests > MAX_GUESTS) {
    $fail('Number of guests must be between 1 and ' . MAX_GUESTS . '.');
}
if (empty($_POST['agree_terms'])) {
    $fail('Please agree to the booking policies to continue.');
}
if (mb_strlen($notes) > 1000) {
    $fail('Notes are too long (maximum 1000 characters).');
}

/* ---------- Treatments & packages ---------- */
if (!$serviceIds && !$packageIds) {
    $fail('Please select at least one treatment or package.');
}
if (count($serviceIds) + count($packageIds) > 15) {
    $fail('Please select fewer treatments.');
}

$items = [];   // each: type, id, name, price, duration
if ($serviceIds) {
    $in = implode(',', array_fill(0, count($serviceIds), '?'));
    $st = $pdo->prepare("SELECT id, name, price, duration_minutes FROM services WHERE active = 1 AND id IN ($in)");
    $st->execute($serviceIds);
    $rows = $st->fetchAll();
    if (count($rows) !== count($serviceIds)) $fail('One of the selected services is no longer available. Please choose again.');
    foreach ($rows as $r) {
        $items[] = ['type' => 'service', 'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'duration' => (int)$r['duration_minutes']];
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
    if (count($rows) !== count($packageIds)) $fail('One of the selected packages is no longer available. Please choose again.');
    foreach ($rows as $r) {
        $items[] = ['type' => 'package', 'id' => (int)$r['id'], 'name' => $r['name'], 'price' => (float)$r['price'], 'duration' => (int)$r['duration_minutes']];
    }
}

/* ---------- Date & time ---------- */
$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date || $date < date('Y-m-d')) {
    $fail('Please choose today or a future date.');
}
if ((int)$dateObj->format('N') === 1) {
    $fail('The spa is closed on Mondays. Please choose Tuesday to Sunday.');
}
if (!in_array($time, $validTimes, true)) {
    $fail('Please select one of the available appointment times.');
}
if ($date === date('Y-m-d') && $time <= date('H:i')) {
    $fail('That time has already passed. Please choose a later time.');
}

/* ---------- Pricing (always computed on the server) ---------- */
$total = 0.0;
foreach ($items as &$it) {
    $it['line_total'] = round($it['price'] + ($guests - 1) * $it['price'] * EXTRA_GUEST_RATE, 2);
    $total += $it['line_total'];
}
unset($it);
$total = round($total, 2);

/* ---------- Payment ---------- */
if (!isset($downPayment[$method])) {
    $fail('Please select a valid payment method.');
}
$amountDue = round($total * $downPayment[$method], 2);
$paymentStatus = 'unpaid';          // cash: down payment still to be collected
$proofPath = null;

if ($method !== 'cash') {
    if (!in_array($provider, $providers[$method], true)) {
        $fail($method === 'bank' ? 'Please choose a bank.' : 'Please choose an e-wallet.');
    }
    if (!preg_match('/^[A-Za-z0-9-]{6,25}$/', $refNo)) {
        $fail('Please enter a valid payment reference number (6–25 letters or numbers).');
    }
    $f = $_FILES['proof_image'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        $fail('Please upload your proof of payment.');
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        $fail('Proof of payment must be 5 MB or smaller.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extMap[$mime])) {
        $fail('Proof of payment must be a JPG, PNG, WEBP or PDF file.');
    }
    $dir = __DIR__ . '/uploads/payment_proofs';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        $fail('We could not store your proof of payment. Please try again.');
    }
    $fileName = bin2hex(random_bytes(12)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $fileName)) {
        $fail('We could not store your proof of payment. Please try again.');
    }
    $proofPath = 'uploads/payment_proofs/' . $fileName;
    $paymentStatus = 'pending';     // waiting for staff to verify
} else {
    $provider = '';
}

/* ---------- Save ---------- */
$code = 'BV' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
$firstService = null;
foreach ($items as $it) { if ($it['type'] === 'service') { $firstService = $it['id']; break; } }

// Free up slots held by unpaid cash reservations whose hold period has ended.
try {
    $pdo->exec("UPDATE appointments SET status = 'Cancelled' WHERE status = 'Pending' AND payment_method = 'cash' AND payment_status = 'unpaid' AND created_at < (NOW() - INTERVAL " . (int)HOLD_HOURS . " HOUR)");
} catch (PDOException $e) {
}

try {
    $pdo->beginTransaction();

    // A time slot can only be booked once (cancelled appointments don't count).
    $check = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status <> 'Cancelled' FOR UPDATE");
    $check->execute([$date, $time . ':00']);
    if ((int)$check->fetchColumn() > 0) {
        $pdo->rollBack();
        if ($proofPath) @unlink(__DIR__ . '/' . $proofPath);
        $fail('Sorry, that time slot was just booked. Please choose another time.');
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

    $itemStmt = $pdo->prepare('INSERT INTO appointment_items
        (appointment_id, item_type, item_id, item_name, unit_price, duration_minutes, line_total)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($items as $it) {
        $itemStmt->execute([$appointmentId, $it['type'], $it['id'], $it['name'], $it['price'], $it['duration'], $it['line_total']]);
    }

    $pdo->commit();

    // Best-effort email (needs a configured mail server; the Track my booking page always works).
    $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
          . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $due = number_format($amountDue, 2);
    $next = $method === 'cash'
        ? "Please pay your down payment of PHP $due in cash at the spa front desk within " . HOLD_HOURS . " hours. Bring this booking code."
        : "We will verify your payment (PHP $due) and update your booking shortly.";
    $msg = "Hi $name,\r\n\r\nThank you for booking with Bella Vista Oasis Spa.\r\nBooking code: $code\r\nDate: "
         . date('F j, Y', strtotime($date)) . ' at ' . date('g:i A', strtotime($time))
         . "\r\nTotal: PHP " . number_format($total, 2) . "\r\n\r\n$next\r\n\r\nTrack your booking: $base/track.php?code=$code\r\n";
    @mail($email, "Bella Vista Oasis Spa - booking $code received", $msg,
          "From: Bella Vista Oasis Spa <no-reply@bellavista.local>\r\nContent-Type: text/plain; charset=UTF-8");

    $_SESSION['last_booking_code'] = $code;
    header('Location: confirmation.php?code=' . urlencode($code));
    exit;
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($proofPath) @unlink(__DIR__ . '/' . $proofPath);
    error_log('save_booking: ' . $e->getMessage());
    // Show the technical reason only on a local machine, to make setup problems easy to spot.
    $local = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
    $fail('We could not save your booking. Please try again or contact the spa.' . ($local ? ' [' . $e->getMessage() . ']' : ''));
}
