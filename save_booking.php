<?php
require_once 'config.php';
require_once 'mailer.php';
require_once 'slots_lib.php'; // same availability rules as book.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: book.php');
    exit;
}

function fail_booking(string $msg, int $step = 1, string $field = ''): void
{
    $_SESSION['booking_error']       = $msg;
    $_SESSION['booking_error_step']  = $step;
    $_SESSION['booking_error_field'] = $field;
    $_SESSION['booking_old']         = $_POST;
    header('Location: book.php');
    exit;
}

const SLOTS_ALLOWED = BV_SLOTS;
const MAX_GUESTS    = 6;
const DOWN_RATE     = 0.30;

$providersAllowed = [
    'ewallet' => ['gcash', 'maya'],
    'bank'    => ['bdo', 'bpi', 'unionbank'],
];

// Ensure needed columns exist so E-wallet / Online Banking never fail on INSERT/UPDATE
function bv_ensure_booking_col(PDO $pdo, string $table, string $col, string $ddl): void
{
    try {
        if (!$pdo->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $col $ddl");
        }
    } catch (Throwable $e) {
        error_log("bv_ensure_booking_col ($table.$col): " . $e->getMessage());
    }
}
bv_ensure_booking_col($pdo, 'appointments', 'room_id', 'INT NULL');
bv_ensure_booking_col($pdo, 'appointments', 'payment_plan', "VARCHAR(30) DEFAULT 'downpayment'");
bv_ensure_booking_col($pdo, 'appointments', 'payment_provider', 'VARCHAR(50) NULL');
bv_ensure_booking_col($pdo, 'appointments', 'reference_no', 'VARCHAR(50) NULL');
bv_ensure_booking_col($pdo, 'appointments', 'proof_image', 'VARCHAR(255) NULL');
bv_ensure_booking_col($pdo, 'appointments', 'amount_due', 'DECIMAL(10,2) DEFAULT 0.00');
bv_ensure_booking_col($pdo, 'appointment_items', 'guest_no', 'INT NULL');

/* ---------- Step 1: Accommodation, Guest Services & Add-ons ---------- */
$roomId      = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
$accType     = trim((string)($_POST['accommodation_type'] ?? ''));
$guests      = filter_var($_POST['guests'] ?? '', FILTER_VALIDATE_INT);
$guestRaw    = $_POST['guest_items'] ?? [];
$addonQtyRaw = $_POST['addon_qty'] ?? [];

if (!$guests || $guests < 1 || $guests > MAX_GUESTS) {
    fail_booking('Please select a valid number of guests (1 to 6).', 1, 'guests');
}

$room = null;
if ($roomId && $roomId > 0) {
    try {
        $st = $pdo->prepare('SELECT id, name, min_guests, max_guests, duration_minutes, price FROM rooms WHERE id = ? AND active = 1');
        $st->execute([$roomId]);
        $room = $st->fetch();
        if (!$room) {
            fail_booking('Selected room is no longer available.', 1, 'room_id');
        }
        $maxCap = stripos($room['name'], 'family') !== false ? max(6, (int)$room['max_guests']) : (int)$room['max_guests'];
        if ($guests < (int)$room['min_guests'] || $guests > $maxCap) {
            fail_booking($room['name'] . ' accommodates ' . $room['min_guests'] . ' to ' . $maxCap . ' guests.', 1, 'guests');
        }
    } catch (Throwable $e) {
        error_log('save_booking room lookup error: ' . $e->getMessage());
    }
}

// Collect per-guest services/packages
$serviceIds = [];
$packageIds = [];
$guestAssignments = [];

if (is_array($guestRaw)) {
    foreach ($guestRaw as $gNo => $keys) {
        $g = (int)$gNo;
        if ($g < 1 || $g > $guests || !is_array($keys)) continue;
        foreach ($keys as $k) {
            if (preg_match('/^(service|package):(\d+)$/', (string)$k, $m)) {
                $type = $m[1];
                $itemId = (int)$m[2];
                $guestAssignments[] = ['guest_no' => $g, 'type' => $type, 'id' => $itemId];
                if ($type === 'service') $serviceIds[$itemId] =$itemId;
                else $packageIds[$itemId] =$itemId;
            }
        }
    }
}

if (!$guestAssignments) {
    fail_booking('Please select at least one spa service or package.', 1, 'service_id');
}

// Fetch service and package records
$servicesById = [];
if ($serviceIds) {
    $in = implode(',', array_fill(0, count($serviceIds), '?'));
    $st =$pdo->prepare("SELECT id, name, price, duration_minutes FROM services WHERE id IN ($in) AND active = 1");
    $st->execute(array_values($serviceIds));
    foreach ($st->fetchAll() as $r) {$servicesById[(int)$r['id']] =$r;
    }
}

$packagesById = [];
if ($packageIds) {
    try {
        $in = implode(',', array_fill(0, count($packageIds), '?'));
        $st =$pdo->prepare("SELECT id, name, price, duration_minutes FROM packages WHERE id IN ($in) AND active = 1");
        $st->execute(array_values($packageIds));
        foreach ($st->fetchAll() as $r) {$packagesById[(int)$r['id']] =$r;
        }
    } catch (Throwable $e) {}
}

// Fetch selected add-ons with quantities
$addonItems = [];
if (is_array($addonQtyRaw) && $addonQtyRaw) {$addonQuantities = [];
    foreach ($addonQtyRaw as $rawId =>$rawQty) {
        $id  = (int)$rawId;
        $qty = min(20, max(0, (int)$rawQty));
        if ($id > 0 && $qty > 0) {$addonQuantities[$id] =$qty;
        }
    }
    if ($addonQuantities) {
        try {
            $ids = array_keys($addonQuantities);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st =$pdo->prepare("SELECT id, name, price FROM addons WHERE id IN ($in) AND active = 1");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {$id  = (int)$r['id'];$qty = $addonQuantities[$id] ?? 1;
                $unitPrice = round((float)$r['price'], 2);
                $label =$r['name'] . ($qty > 1 ? " × {$qty}" : '') . ' (add-on)';
                $addonItems[] = [
                    'guest_no'   => null,
                    'type'       => 'addon',
                    'id'         => $id,
                    'name'       => $label,
                    'unit_price' => $unitPrice,
                    'duration'   => 0,
                    'line_total' => round($unitPrice * $qty, 2),
                ];
            }
        } catch (Throwable$e) {}
    }
}

/* ---------- Step 2: Date & Time ---------- */
$date = trim((string)($_POST['appointment_date'] ?? ''));
$time = trim((string)($_POST['appointment_time'] ?? ''));

$dateObj = DateTime::createFromFormat('Y-m-d',$date);
if (!$dateObj || $dateObj->format('Y-m-d') !==$date) {
    fail_booking('Please choose a valid appointment date.', 2, 'appointment_date');
}
if ((int)$dateObj->format('N') === 1) {
    fail_booking('The spa is closed on Mondays. Please choose another day.', 2, 'appointment_date');
}
if ($date < date('Y-m-d')) {
    fail_booking('Please select today or a future date.', 2, 'appointment_date');
}
if (!in_array($time, SLOTS_ALLOWED, true)) {
    fail_booking('Please choose an available time slot.', 2, 'appointment_time');
}
if ($date === date('Y-m-d') &&$time <= date('H:i')) {
    fail_booking('That time slot has already passed today.', 2, 'appointment_time');
}

// Check slot availability (same rules as the live check in book.php)
bv_slot_release_unpaid($pdo);
$slotRoomId = $room ? (int)$room['id'] : (($roomId && $roomId > 0) ? (int)$roomId : 0);
$slotFullMsg = $slotRoomId > 0
    ? 'That time slot was just booked for this room. Please pick another.'
    : 'That time slot is now full. Please pick another.';
try {
    $slotTaken = bv_slot_is_taken($pdo, $date, $time, $slotRoomId);
} catch (Throwable $e) {
    error_log('save_booking slot check error: ' . $e->getMessage());
    fail_booking('We could not verify that time slot. Please try again.', 2, 'appointment_time');
}
if ($slotTaken) {
    fail_booking($slotFullMsg, 2, 'appointment_time');
}

/* ---------- Step 3: Guest Details ---------- */
$name  = trim((string)($_POST['customer_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if ($name === '' || mb_strlen($name) > 120 || !preg_match("/^\p{L}[\p{L}\s.'-]*$/u", $name)) {
    fail_booking('Please enter your valid full name.', 3, 'customer_name');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
    fail_booking('Please enter a valid email address.', 3, 'email');
}
if (!preg_match('/^(09\d{9}|639\d{9})$/',$phone)) {
    fail_booking('Please enter a valid PH mobile number (09XXXXXXXXX).', 3, 'phone');
}
if (mb_strlen($notes) > 1000) {
    fail_booking('Notes cannot exceed 1000 characters.', 3, 'notes');
}

/* ---------- Step 4: Payment & Proof Upload ---------- */
$method   = strtolower(trim((string)($_POST['payment_method'] ?? '')));
$provider = strtolower(trim((string)($_POST['payment_provider'] ?? '')));
$plan     = strtolower(trim((string)($_POST['payment_plan'] ?? 'downpayment')));
$refNo    = trim((string)($_POST['reference_no'] ?? ''));

if (!in_array($method, ['cash', 'ewallet', 'bank'], true)) {
    fail_booking('Please select a payment method.', 4, 'payment_method');
}

// Allow dynamically added active providers from payment_settings table as well
try {
    $dbProvs =$pdo->query("SELECT method, provider_key FROM payment_settings WHERE active = 1")->fetchAll();
    foreach ($dbProvs as$dp) {
        $mKey = strtolower(trim((string)$dp['method']));
        $pKey = strtolower(trim((string)$dp['provider_key']));
        if (isset($providersAllowed[$mKey]) &&$pKey !== '' && !in_array($pKey,$providersAllowed[$mKey], true)) {$providersAllowed[$mKey][] =$pKey;
        }
    }
} catch (Throwable $e) {}

$proofRelPath = null;
if ($method === 'cash') {$provider  = null;
    $plan      = 'store';$refNo     = null;
    $payStatus = 'unpaid';
} else {
    if (!isset($providersAllowed[$method]) || !in_array($provider, $providersAllowed[$method], true)) {
        fail_booking('Please select your e-wallet or online banking provider.', 4, 'payment_provider');
    }
    if (!in_array($plan, ['downpayment', 'full'], true)) {$plan = 'downpayment';
    }
    if (!preg_match('/^[A-Za-z0-9-]{4,25}$/',$refNo)) {
        fail_booking('Please enter a valid payment reference number (4–25 characters).', 4, 'reference_no');
    }
    if (empty($_FILES['proof_image']) || ($_FILES['proof_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        fail_booking('Please upload your proof of payment screenshot or receipt.', 4, 'proof_image');
    }
    if ($_FILES['proof_image']['size'] > 5 * 1024 * 1024) {
        fail_booking('Proof of payment file must be 5 MB or less.', 4, 'proof_image');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['proof_image']['tmp_name']);$extMap = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    if (!isset($extMap[$mime])) {
        fail_booking('Upload a JPG, PNG, WEBP, or PDF file for proof of payment.', 4, 'proof_image');
    }

    $uploadDir = __DIR__ . '/uploads/payment_proofs';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        fail_booking('Could not prepare upload directory. Please contact the spa.', 4, 'proof_image');
    }
    $filename = 'proof_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($_FILES['proof_image']['tmp_name'], $uploadDir . '/' .$filename)) {
        fail_booking('Failed to upload proof of payment. Please try again.', 4, 'proof_image');
    }
    $proofRelPath = 'uploads/payment_proofs/' .$filename;
    $payStatus    = 'pending';
}

/* ---------- Step 5: Terms ---------- */
if (empty($_POST['agree_terms'])) {
    fail_booking('Please agree to the booking policies before submitting.', 5, 'agree_terms');
}

/* ---------- Compute Totals & Build Line Items ---------- */
$total = 0.0;
$lineItems = [];$firstServiceId = null;

if ($room && (float)$room['price'] > 0) {
    $roomFee = round((float)$room['price'], 2);
    $total +=$roomFee;
    $lineItems[] = [
        'guest_no'   => null,
        'type'       => 'room',
        'id'         => (int)$room['id'],
        'name'       => $room['name'] . ' (room fee)',
        'unit_price' => $roomFee,
        'duration'   => (int)($room['duration_minutes'] ?? 0),
        'line_total' =>$roomFee,
    ];
}

foreach ($guestAssignments as$ga) {
    $src =$ga['type'] === 'service' ? ($servicesById[$ga['id']] ?? null) : ($packagesById[$ga['id']] ?? null);
    if (!$src) {
        fail_booking('One of your selected services is no longer available.', 1, 'service_id');
    }
    if ($ga['type'] === 'service' &&$firstServiceId === null) {
        $firstServiceId = (int)$src['id'];
    }
    $price = round((float)$src['price'], 2);
    $total +=$price;
    $lineItems[] = [
        'guest_no'   => $ga['guest_no'],
        'type'       => $ga['type'],
        'id'         => (int)$src['id'],
        'name'       => $src['name'],
        'unit_price' => $price,
        'duration'   => (int)$src['duration_minutes'],
        'line_total' => $price,
    ];
}

foreach ($addonItems as$ad) {
    $total +=$ad['line_total'];
    $lineItems[] =$ad;
}

$total = round($total, 2);
if ($method === 'cash') {$amountDue = 0.00;
} else {
    $rate = ($plan === 'full') ? 1.00 : DOWN_RATE;
    $amountDue = round($total * $rate, 2);
}

$bookingCode = 'BV' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));

/* ---------- Save to Database ---------- */
try {
    $pdo->beginTransaction();

    // Final, locked re-check: stops two customers from taking the same slot at the same moment.
    if (bv_slot_is_taken($pdo, $date, $time, $slotRoomId, true)) {
        $pdo->rollBack();
        if ($proofRelPath && is_file(__DIR__ . '/' . $proofRelPath)) {
            @unlink(__DIR__ . '/' . $proofRelPath);
        }
        fail_booking($slotFullMsg, 2, 'appointment_time');
    }

    $insAppt =$pdo->prepare('INSERT INTO appointments (
        booking_code, customer_name, email, phone, service_id, room_id,
        appointment_date, appointment_time, guests, notes,
        total_price, payment_method, payment_provider, payment_plan,
        payment_status, amount_due, reference_no, proof_image, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $insAppt->execute([
        $bookingCode,$name,
        $email,$phone,
        $firstServiceId,$room ? (int)$room['id'] : null,$date,
        $time . ':00',$guests,
        $notes !== '' ? $notes : null,
        $total,$method,
        $provider,$plan,
        $payStatus,$amountDue,
        $refNo,$proofRelPath,
        'Pending',
    ]);

    $appointmentId = (int)$pdo->lastInsertId();

    $insItem =$pdo->prepare('INSERT INTO appointment_items (
        appointment_id, guest_no, item_type, item_id, item_name, unit_price, duration_minutes, line_total
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

    foreach ($lineItems as $it) {$insItem->execute([
            $appointmentId,$it['guest_no'],
            $it['type'],$it['id'],
            $it['name'],$it['unit_price'],
            $it['duration'],$it['line_total'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {$pdo->rollBack();
    }
    if ($proofRelPath && is_file(__DIR__ . '/' .$proofRelPath)) {
        @unlink(__DIR__ . '/' . $proofRelPath);
    }
    error_log('save_booking error: ' . $e->getMessage());
    fail_booking('Unable to save your booking right now: ' . $e->getMessage(), 4);
}

/* ---------- Send Confirmation Email & Redirect to Tracking ---------- */
$mailSent = false;
$mailError = '';
try {
    if (function_exists('bv_email_booking_received') && function_exists('bv_send_mail')) {
        [$subject, $html,$text] = bv_email_booking_received([
            'code'             => $bookingCode,
            'name'             => $name,
            'date'             => $date,
            'time'             => $time,
            'guests'           => $guests,
            'total'            => $total,
            'amount_due'       => $amountDue,
            'method'           => $method,
            'payment_method'   => $method,
            'payment_provider' => $provider,
            'payment_plan'     => $plan,
            'reference_no'     => $refNo,
        ]);
        $mailRes = bv_send_mail($email, $name,$subject, $html,$text);
        $mailSent = is_array($mailRes) ? (bool)($mailRes[0] ?? false) : (bool)$mailRes;
        $mailError = is_array($mailRes) ? (string)($mailRes[1] ?? '') : '';
        if (!$mailSent) {
            error_log("save_booking mail failed for {$bookingCode} ({$email}): " . ($mailError ?: 'Unknown mailer error'));
        }
    } else {
        $mailError = 'Mailer functions missing.';
        error_log("save_booking mail failed: bv_email_booking_received or bv_send_mail not defined.");
    }
} catch (Throwable $e) {
    $mailError =$e->getMessage();
    error_log("save_booking mail exception for {$bookingCode}: " . $mailError);
}

$_SESSION['last_booking_code'] =$bookingCode;
$_SESSION['last_booking_mail'] = [
    'code'  => $bookingCode,
    'email' => $email,
    'sent'  => $mailSent,
    'error' => $mailError,
];

header('Location: track.php?new=1&code=' . urlencode($bookingCode));
exit;
