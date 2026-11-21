<?php
ob_start(); // buffer output so stray warnings/whitespace from config.php can never corrupt the JSON slot response
require_once 'config.php';
require_once 'slots_lib.php'; // shared availability rules (also used by save_booking.php)

// Live AJAX Slot Availability Check
if (isset($_GET['slots'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    @ini_set('display_errors', '0');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $d  = (string)$_GET['slots'];
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'booked' => [], 'error' => 'Invalid date.']);
        exit;
    }

    try {
        bv_slot_release_unpaid($pdo);
        $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
        echo json_encode(['ok' => true, 'booked' => bv_slot_booked_list($pdo, $d, $roomId)]);
    } catch (Throwable $e) {
        error_log('book.php slots error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'booked' => [], 'error' => 'Could not load availability.']);
    }
    exit;
}

$preview = !empty($_GET['preview']);
$spaInfo = [
    'address' => 'Bella Vista Oasis Spa front desk',
    'hours'   => 'Tuesday to Sunday, 9:00 AM to 5:30 PM',
    'phone'   => '09XX XXX XXXX',
];
$downPayment = ['cash' => 0.00, 'ewallet' => 0.30, 'bank' => 0.30];

// Admin-editable Payment Settings
$defaultPaymentProviders = [
    'ewallet' => [
        'gcash' => ['name' => 'GCash', 'initial' => 'G', 'account_name' => 'Bella Vista Oasis Spa', 'account_number' => '0917 000 0000', 'qr_image' => ''],
        'maya'  => ['name' => 'Maya',  'initial' => 'M', 'account_name' => 'Bella Vista Oasis Spa', 'account_number' => '0918 000 0000', 'qr_image' => ''],
    ],
    'bank' => [
        'bdo'       => ['name' => 'BDO',       'initial' => 'B', 'bank_name' => 'BDO Unibank',                    'account_name' => 'Bella Vista Oasis Spa', 'account_number' => '0000 0000 0000', 'qr_image' => ''],
        'bpi'       => ['name' => 'BPI',       'initial' => 'B', 'bank_name' => 'Bank of the Philippine Islands', 'account_name' => 'Bella Vista Oasis Spa', 'account_number' => '0000 0000 00',   'qr_image' => ''],
        'unionbank' => ['name' => 'UnionBank', 'initial' => 'U', 'bank_name' => 'UnionBank of the Philippines',   'account_name' => 'Bella Vista Oasis Spa', 'account_number' => '0000 0000 0000', 'qr_image' => ''],
    ],
];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        method VARCHAR(30) NOT NULL,
        provider_key VARCHAR(40) NOT NULL UNIQUE,
        provider_name VARCHAR(80) NOT NULL,
        bank_name VARCHAR(120) DEFAULT '',
        account_name VARCHAR(120) NOT NULL,
        account_number VARCHAR(80) NOT NULL,
        qr_image VARCHAR(255) DEFAULT '',
        active TINYINT(1) DEFAULT 1,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    if ((int)$pdo->query("SELECT COUNT(*) FROM payment_settings")->fetchColumn() === 0) {
        $ins = $pdo->prepare("INSERT INTO payment_settings (method, provider_key, provider_name, bank_name, account_name, account_number, qr_image, active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
        foreach ($defaultPaymentProviders as $mKey => $provs) {
            foreach ($provs as $pKey => $pData) {
                $ins->execute([$mKey, $pKey, $pData['name'], $pData['bank_name'] ?? '', $pData['account_name'], $pData['account_number'], '']);
            }
        }
    }
    $dbProviders = $pdo->query("SELECT * FROM payment_settings WHERE active = 1 ORDER BY id ASC")->fetchAll();
    if ($dbProviders) {
        $loaded = ['ewallet' => [], 'bank' => []];
        foreach ($dbProviders as $row) {
            if (!isset($loaded[$row['method']])) continue;
            $loaded[$row['method']][$row['provider_key']] = [
                'name' => $row['provider_name'],
                'initial' => strtoupper(mb_substr($row['provider_name'], 0, 1)),
                'bank_name' => $row['bank_name'] ?? '',
                'account_name' => $row['account_name'],
                'account_number' => $row['account_number'],
                'qr_image' => $row['qr_image'] ?? '',
            ];
        }
        if ($loaded['ewallet']) $defaultPaymentProviders['ewallet'] = $loaded['ewallet'];
        if ($loaded['bank']) $defaultPaymentProviders['bank'] = $loaded['bank'];
    }
} catch (Throwable $e) {}

$ewalletProvidersFormatted = [];
foreach ($defaultPaymentProviders['ewallet'] as $pk => $pv) {
    $ewalletProvidersFormatted[$pk] = [
        'name' => $pv['name'], 'initial' => $pv['initial'], 'qr_image' => $pv['qr_image'] ?? '',
        'details' => [['Account name', $pv['account_name'], false], [$pv['name'] . ' number', $pv['account_number'], true]],
    ];
}
$bankProvidersFormatted = [];
foreach ($defaultPaymentProviders['bank'] as $pk => $pv) {
    $details = !empty($pv['bank_name']) ? [['Bank', $pv['bank_name'], false]] : [];
    $details[] = ['Account name', $pv['account_name'], false];
    $details[] = ['Account number', $pv['account_number'], true];
    $bankProvidersFormatted[$pk] = ['name' => $pv['name'], 'initial' => $pv['initial'], 'qr_image' => $pv['qr_image'] ?? '', 'details' => $details];
}

$paymentMethods = [
    'cash'    => ['label' => 'Pay at the store', 'icon' => '₱', 'hint' => 'Pay full amount upon arrival'],
    'ewallet' => ['label' => 'E-wallet', 'icon' => '◎', 'hint' => implode(', ', array_column($ewalletProvidersFormatted, 'name')) . ' · Downpayment or Full Payment', 'providers' => $ewalletProvidersFormatted],
    'bank'    => ['label' => 'Online banking', 'icon' => '⌂', 'hint' => implode(', ', array_column($bankProvidersFormatted, 'name')) . ' · Downpayment or Full Payment', 'providers' => $bankProvidersFormatted],
];

$policies = [
    ['Operating Hours', 'Tuesday to Sunday, 9:00 AM to 5:30 PM (Closed on Mondays).'],
    ['Reservation & Confirmation', 'Pending requests are confirmed once verified. Track anytime using your booking code.'],
    ['Cancellation', 'Cancel or reschedule at least 24 hours prior. Late cancellations forfeit reservation fees.'],
    ['Grace Period', 'Slots are held for 15 minutes past your scheduled time.'],
];

// Accommodations: Open Treatment Area, Single Room, Couple Room, Family Lounge (Up to 6 Guests)
try {
    $dbRooms = $pdo->query('SELECT * FROM rooms WHERE active = 1')->fetchAll();
} catch (Throwable $e) {
    $dbRooms = [];
}

$roomMap = [];
foreach ($dbRooms as $r) {
    $n = strtolower($r['name']);
    if (str_contains($n, 'single')) $roomMap['single'] = $r;
    elseif (str_contains($n, 'couple')) $roomMap['couples'] = $r;
    elseif (str_contains($n, 'family')) $roomMap['family'] = $r;
}

$accommodations = [
    [
        'id' => 0,
        'cat' => 'open',
        'name' => 'Open Treatment Area',
        'badge' => 'No Private Room',
        'min_guests' => 1,
        'max_guests' => 1,
        'duration_minutes' => 60,
        'price' => 0.00,
        'description' => 'Shared treatment space with dedicated therapist assignment. Individual spa services only.',
        'amenities' => ['Shared Tranquil Treatment Area', 'Dedicated Certified Therapist', 'Fresh Towels & Linens', 'Complimentary Herbal Tea'],
        'image' => '',
    ],
    [
        'id' => (int)($roomMap['single']['id'] ?? 1),
        'cat' => 'single',
        'name' => $roomMap['single']['name'] ?? 'Single Room',
        'badge' => '1 Guest',
        'min_guests' => 1,
        'max_guests' => 1,
        'duration_minutes' => (int)($roomMap['single']['duration_minutes'] ?? 60),
        'price' => (float)($roomMap['single']['price'] ?? 0),
        'description' => $roomMap['single']['description'] ?? 'Private suite designed for solo relaxation.',
        'amenities' => !empty($roomMap['single']['amenities']) ? array_map('trim', explode(',', $roomMap['single']['amenities'])) : ['Private Treatment Bed', 'Aromatherapy Diffuser', 'Ambient Music', 'Fresh Towels & Plush Robe', 'Complimentary Herbal Tea'],
        'image' => $roomMap['single']['image'] ?? '',
    ],
    [
        'id' => (int)($roomMap['couples']['id'] ?? 2),
        'cat' => 'couples',
        'name' => $roomMap['couples']['name'] ?? 'Couple Room',
        'badge' => '2 Guests',
        'min_guests' => 2,
        'max_guests' => 2,
        'duration_minutes' => (int)($roomMap['couples']['duration_minutes'] ?? 90),
        'price' => (float)($roomMap['couples']['price'] ?? 500),
        'description' => $roomMap['couples']['description'] ?? 'Intimate dual-bed private suite for two guests.',
        'amenities' => !empty($roomMap['couples']['amenities']) ? array_map('trim', explode(',', $roomMap['couples']['amenities'])) : ['Side-by-Side Massage Beds', 'Private En-Suite Shower', 'Warm Ambient Lighting', 'Plush Robes & Slippers', 'Complimentary Herbal Tea'],
        'image' => $roomMap['couples']['image'] ?? '',
    ],
    [
        'id' => (int)($roomMap['family']['id'] ?? 3),
        'cat' => 'family',
        'name' => $roomMap['family']['name'] ?? 'Family Lounge',
        'badge' => 'Up to 6 Guests',
        'min_guests' => 3,
        'max_guests' => 6,
        'duration_minutes' => (int)($roomMap['family']['duration_minutes'] ?? 120),
        'price' => (float)($roomMap['family']['price'] ?? 1200),
        'description' => 'Spacious private wellness lounge accommodating 3 to 6 guests together.',
        'amenities' => ['Private Family Lounge (Up to 6 Guests)', 'Dedicated Lounge Seating', 'Private Restroom & Shower', 'Plush Robes & Towels', 'Complimentary Refreshments'],
        'image' => $roomMap['family']['image'] ?? '',
    ],
];

// Spa Services & Packages Catalog
$serviceCategories = [
    'massage' => 'Massage Services',
    'body'    => 'Body Treatments',
    'facial'  => 'Facial & Skin Care',
    'nail'    => 'Nail Care Services',
    'hair'    => 'Hair Removal Services',
];

$catalogServices = [
    ['id' => 101, 'category' => 'massage', 'name' => 'Swedish Massage', 'duration_minutes' => 60, 'price' => 500, 'description' => 'Gentle massage using flowing strokes for relaxation.'],
    ['id' => 102, 'category' => 'massage', 'name' => 'Deep Tissue Massage', 'duration_minutes' => 60, 'price' => 650, 'description' => 'Focuses on deeper muscle layers to relieve tension.'],
    ['id' => 103, 'category' => 'massage', 'name' => 'Aromatherapy Massage', 'duration_minutes' => 60, 'price' => 600, 'description' => 'Combines massage techniques with essential oils.'],
    ['id' => 104, 'category' => 'massage', 'name' => 'Hot Stone Massage', 'duration_minutes' => 75, 'price' => 750, 'description' => 'Uses warmed stones during the massage.'],
    ['id' => 105, 'category' => 'massage', 'name' => 'Foot Reflexology', 'duration_minutes' => 45, 'price' => 350, 'description' => 'Applies pressure to specific areas of the feet.'],
    ['id' => 106, 'category' => 'massage', 'name' => 'Back and Shoulder Massage', 'duration_minutes' => 30, 'price' => 300, 'description' => 'Focuses on the back, neck, and shoulders.'],
    ['id' => 201, 'category' => 'body', 'name' => 'Body Scrub', 'duration_minutes' => 45, 'price' => 500, 'description' => 'Removes dead skin cells using exfoliating products.'],
    ['id' => 202, 'category' => 'body', 'name' => 'Body Exfoliation', 'duration_minutes' => 45, 'price' => 550, 'description' => 'Smoothens the skin through exfoliation.'],
    ['id' => 203, 'category' => 'body', 'name' => 'Body Wrap', 'duration_minutes' => 60, 'price' => 750, 'description' => 'Applies skincare products to the body.'],
    ['id' => 204, 'category' => 'body', 'name' => 'Herbal Body Treatment', 'duration_minutes' => 60, 'price' => 650, 'description' => 'Uses herbal-based products for body care.'],
    ['id' => 205, 'category' => 'body', 'name' => 'Foot Spa', 'duration_minutes' => 45, 'price' => 400, 'description' => 'Includes foot soaking, exfoliation, and basic foot care.'],
    ['id' => 301, 'category' => 'facial', 'name' => 'Basic Facial', 'duration_minutes' => 45, 'price' => 500, 'description' => 'Basic cleansing and moisturizing treatment.'],
    ['id' => 302, 'category' => 'facial', 'name' => 'Deep Cleansing Facial', 'duration_minutes' => 60, 'price' => 700, 'description' => 'Focuses on deep cleansing and removing impurities.'],
    ['id' => 303, 'category' => 'facial', 'name' => 'Hydrating Facial', 'duration_minutes' => 60, 'price' => 650, 'description' => 'Uses moisturizing products to maintain skin hydration.'],
    ['id' => 304, 'category' => 'facial', 'name' => 'Anti-Aging Facial', 'duration_minutes' => 60, 'price' => 850, 'description' => 'Uses skincare products designed for mature skin.'],
    ['id' => 305, 'category' => 'facial', 'name' => 'Acne Facial', 'duration_minutes' => 60, 'price' => 750, 'description' => 'Facial treatment for acne-prone skin.'],
    ['id' => 401, 'category' => 'nail', 'name' => 'Manicure', 'duration_minutes' => 30, 'price' => 200, 'description' => 'Nail trimming, shaping, and basic hand care.'],
    ['id' => 402, 'category' => 'nail', 'name' => 'Pedicure', 'duration_minutes' => 45, 'price' => 250, 'description' => 'Nail trimming, shaping, and basic foot care.'],
    ['id' => 403, 'category' => 'nail', 'name' => 'Gel Polish', 'duration_minutes' => 60, 'price' => 450, 'description' => 'Applies gel-based nail polish.'],
    ['id' => 404, 'category' => 'nail', 'name' => 'Nail Cleaning', 'duration_minutes' => 20, 'price' => 150, 'description' => 'Basic cleaning and grooming of nails.'],
    ['id' => 405, 'category' => 'nail', 'name' => 'Foot Scrub', 'duration_minutes' => 30, 'price' => 250, 'description' => 'Exfoliates the feet to remove dead skin cells.'],
    ['id' => 501, 'category' => 'hair', 'name' => 'Eyebrow Waxing', 'duration_minutes' => 15, 'price' => 150, 'description' => 'Removes unwanted hair around the eyebrows.'],
    ['id' => 502, 'category' => 'hair', 'name' => 'Underarm Waxing', 'duration_minutes' => 20, 'price' => 250, 'description' => 'Removes unwanted hair from the underarm area.'],
    ['id' => 503, 'category' => 'hair', 'name' => 'Arm Waxing', 'duration_minutes' => 30, 'price' => 400, 'description' => 'Removes unwanted hair from the arms.'],
    ['id' => 504, 'category' => 'hair', 'name' => 'Leg Waxing', 'duration_minutes' => 45, 'price' => 600, 'description' => 'Removes unwanted hair from the legs.'],
];

$catalogPackages = [
    ['id' => 1, 'room_type' => 'single', 'max_guests' => 1, 'name' => 'Relaxation Package', 'duration_minutes' => 90, 'price' => 999, 'description' => 'Swedish Massage, Foot Spa, Complimentary Tea'],
    ['id' => 2, 'room_type' => 'single', 'max_guests' => 1, 'name' => 'Beauty Care Package', 'duration_minutes' => 120, 'price' => 1199, 'description' => 'Basic Facial, Foot Spa, Manicure'],
    ['id' => 3, 'room_type' => 'single', 'max_guests' => 1, 'name' => 'Premium Wellness Package', 'duration_minutes' => 150, 'price' => 1499, 'description' => 'Aromatherapy Massage, Body Scrub, Hydrating Facial'],
    ['id' => 4, 'room_type' => 'couples', 'max_guests' => 2, 'name' => 'Couple Relaxation Package', 'duration_minutes' => 90, 'price' => 1899, 'description' => 'Swedish Massage (2), Foot Spa (2), Complimentary Tea'],
    ['id' => 5, 'room_type' => 'couples', 'max_guests' => 2, 'name' => 'Couple Premium Package', 'duration_minutes' => 120, 'price' => 2499, 'description' => 'Hot Stone Massage (2), Body Scrub (2), Complimentary Refreshments'],
    ['id' => 6, 'room_type' => 'couples', 'max_guests' => 2, 'name' => 'Couple Beauty Package', 'duration_minutes' => 120, 'price' => 2199, 'description' => 'Swedish Massage (2), Basic Facial (2), Foot Spa (2)'],
    ['id' => 7, 'room_type' => 'family', 'max_guests' => 6, 'name' => 'Family Bonding Package', 'duration_minutes' => 90, 'price' => 3499, 'description' => 'Swedish Massage, Foot Spa, Complimentary Refreshments'],
    ['id' => 8, 'room_type' => 'family', 'max_guests' => 6, 'name' => 'Family Wellness Package', 'duration_minutes' => 120, 'price' => 4499, 'description' => 'Swedish Massage, Body Scrub, Complimentary Tea'],
    ['id' => 9, 'room_type' => 'family', 'max_guests' => 6, 'name' => 'Family Premium Package', 'duration_minutes' => 150, 'price' => 5499, 'description' => 'Aromatherapy Massage, Basic Facial, Foot Spa, Complimentary Refreshments'],
];

try {
    $svcCols = $pdo->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category', $svcCols, true)) $pdo->exec("ALTER TABLE services ADD COLUMN category VARCHAR(50) DEFAULT 'massage'");
    $existingSvcs = [];
    foreach ($pdo->query("SELECT id, name FROM services")->fetchAll() as $r) $existingSvcs[strtolower(trim($r['name']))] = (int)$r['id'];
    $updSvc = $pdo->prepare("UPDATE services SET category = ?, description = ?, duration_minutes = ?, price = ?, active = 1 WHERE id = ?");
    $insSvc = $pdo->prepare("INSERT INTO services (category, name, description, duration_minutes, price, active) VALUES (?, ?, ?, ?, ?, 1)");
    foreach ($catalogServices as $cs) {
        $k = strtolower(trim($cs['name']));
        isset($existingSvcs[$k])
            ? $updSvc->execute([$cs['category'], $cs['description'], $cs['duration_minutes'], $cs['price'], $existingSvcs[$k]])
            : $insSvc->execute([$cs['category'], $cs['name'], $cs['description'], $cs['duration_minutes'], $cs['price']]);
    }
    $services = $pdo->query('SELECT * FROM services WHERE active = 1 ORDER BY id ASC')->fetchAll();
} catch (Throwable $ex) {
    $services = $catalogServices;
}

$servicesByCategory = array_fill_keys(array_keys($serviceCategories), []);
foreach ($services as $s) {
    $cat = strtolower(trim((string)($s['category'] ?? 'massage')));
    if (!isset($servicesByCategory[$cat])) $cat = 'massage';
    $servicesByCategory[$cat][] = $s;
}

try {
    $pkgCols = $pdo->query("SHOW COLUMNS FROM packages")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('room_type', $pkgCols, true)) $pdo->exec("ALTER TABLE packages ADD COLUMN room_type VARCHAR(30) DEFAULT 'all'");
    if (!in_array('max_guests', $pkgCols, true)) $pdo->exec("ALTER TABLE packages ADD COLUMN max_guests INT DEFAULT 1");
    $existingPkgs = [];
    foreach ($pdo->query("SELECT id, name FROM packages")->fetchAll() as $r) $existingPkgs[strtolower(trim($r['name']))] = (int)$r['id'];
    $updPkg = $pdo->prepare("UPDATE packages SET room_type = ?, max_guests = ?, description = ?, duration_minutes = ?, price = ?, active = 1 WHERE id = ?");
    $insPkg = $pdo->prepare("INSERT INTO packages (room_type, max_guests, name, description, duration_minutes, price, active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    foreach ($catalogPackages as $cp) {
        $k = strtolower(trim($cp['name']));
        isset($existingPkgs[$k])
            ? $updPkg->execute([$cp['room_type'], $cp['max_guests'], $cp['description'], $cp['duration_minutes'], $cp['price'], $existingPkgs[$k]])
            : $insPkg->execute([$cp['room_type'], $cp['max_guests'], $cp['name'], $cp['description'], $cp['duration_minutes'], $cp['price']]);
    }
    $packages = $pdo->query('SELECT * FROM packages WHERE active = 1 ORDER BY id ASC')->fetchAll();
} catch (Throwable $ex) {
    $packages = $catalogPackages;
}

try {
    $addons = $pdo->query('SELECT * FROM addons WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    $addons = [];
}

function item_image(array $row, string $kind): string
{
    if (!empty($row['image'])) return preg_match('#^https?://#i', $row['image']) ? $row['image'] : ltrim($row['image'], '/');
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $path = 'assets/images/' . $kind . '/' . (int)$row['id'] . '.' . $ext;
        if (is_file(__DIR__ . '/' . $path)) return $path;
    }
    return '';
}

function payment_qr_image(array $pv, string $pk): string
{
    if (!empty($pv['qr_image'])) return preg_match('#^https?://#i', $pv['qr_image']) ? $pv['qr_image'] : ltrim($pv['qr_image'], '/');
    foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
        $f = 'assets/images/payments/' . $pk . '.' . $ext;
        if (is_file(__DIR__ . '/' . $f)) return $f;
    }
    return '';
}

$selectedService = isset($_GET['service']) ? (int)$_GET['service'] : 0;
$error      = $_SESSION['booking_error'] ?? '';
$errorStep  = (int)($_SESSION['booking_error_step'] ?? 1);
$errorField = (string)($_SESSION['booking_error_field'] ?? '');
// Only these fields are sent back to the browser to refill the form after a server-side error.
$oldForm = [];
if ($error && is_array($_SESSION['booking_old'] ?? null)) {
    foreach (['room_id', 'accommodation_type', 'guests', 'guest_items', 'addon_qty', 'appointment_date', 'appointment_time',
              'customer_name', 'email', 'phone', 'notes', 'payment_method', 'payment_provider', 'payment_plan', 'reference_no'] as $k) {
        if (isset($_SESSION['booking_old'][$k])) $oldForm[$k] = $_SESSION['booking_old'][$k];
    }
}
unset($_SESSION['booking_error'], $_SESSION['booking_error_step'], $_SESSION['booking_error_field'], $_SESSION['booking_old']);

$bookingConfig = [
    'slotsUrl'         => 'book.php?slots=',
    'timeSlots'        => BV_SLOTS,
    'closedWeekday'    => 1,
    'maxYearsAhead'    => 2,
    'downPaymentRates' => $downPayment,
    'initialStep'      => $error ? $errorStep : 1,
    'errorField'       => $error ? $errorField : '',
    'oldForm'          => $oldForm,
    'errorMessage'     => $error,
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book an Appointment | Bella Vista Oasis Spa</title>
<link rel="stylesheet" href="assets/style.css">
<style>
:root {
    --bv-ink: #3f2d20; --bv-brown: #5a4030; --bv-brown-dark: #46301f;
    --bv-muted: #6f5f52; --bv-muted-soft: #8a7a6b;
    --bv-green: #648568; --bv-green-soft: #f1f5ef;
    --bv-red: #b83232; --bv-red-soft: #fdf1f1; --bv-gold: #b58a52;
    --bv-card: #fffdf9; --bv-cream: #fbf6ee; --bv-sand: #f1e8dc; --bv-sand-soft: #f6f0e7;
    --bv-line: #e4d9cb; --bv-line-strong: #d9cfc2;
    --bv-font-display: "Cormorant Garamond", Georgia, serif;
    --bv-font-body: "Jost", system-ui, -apple-system, "Segoe UI", sans-serif;
    --bv-radius-card: 20px; --bv-radius-pill: 999px; --bv-btn-height: 42px;
}

.site-header { min-height: 0 !important; padding: 10px 24px !important; }
.booking-layout { display: grid !important; grid-template-columns: 280px minmax(0, 1fr) !important; }
.booking-aside { padding: 28px 24px !important; }
.booking-aside h1 { font-size: clamp(28px, 2.3vw, 38px) !important; line-height: 1.12 !important; }
.booking-form-wrap { max-width: none !important; padding: 24px clamp(20px, 3vw, 44px) 120px !important; font-family: var(--bv-font-body); }
.booking-form-wrap h3, .booking-form-wrap h4 { font-family: var(--bv-font-display); font-weight: 600; color: var(--bv-ink); }
.booking-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 20px; margin-bottom: 22px; }
.booking-head h2 { margin: 0; font-size: 32px; line-height: 1.1; }
@media (max-width: 900px) { .booking-layout { grid-template-columns: 1fr !important; } }

/* Clickable Stepper */
.stepper { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 0; margin: 0; padding: 9px 18px; list-style: none; background: var(--bv-sand); border-radius: var(--bv-radius-pill); }
.stepper li { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--bv-muted-soft); cursor: pointer; user-select: none; transition: color .15s; }
.stepper li:hover { color: var(--bv-ink); }
.stepper li::before { content: ""; width: 8px; height: 8px; border: 1.5px solid #b8aa9a; border-radius: 50%; }
.stepper li:not(:last-child)::after { content: "›"; margin: 0 10px; color: #b8aa9a; }
.stepper li.active { color: var(--bv-ink); font-weight: 600; }
.stepper li.active::before { background: var(--bv-brown); border-color: var(--bv-brown); }
.stepper li.done { color: var(--bv-green); }
.stepper li.done::before { background: var(--bv-green); border-color: var(--bv-green); }

.step-panel { display: none; }
.step-panel.active { display: block; }
.step-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.step-head h3 { margin: 0; font-size: 28px; }
.sub-section-title { font-family: var(--bv-font-display); font-size: 24px; font-weight: 600; margin: 24px 0 12px; color: var(--bv-ink); }
.compact-step-wrap { max-width: 660px; }

.bv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 150px; height: var(--bv-btn-height); padding: 0 14px; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); font: inherit; font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; cursor: pointer; transition: .15s; line-height: 1; }
.bv-btn--primary { background: var(--bv-brown); color: #fff; }
.bv-btn--primary:hover { background: var(--bv-brown-dark); }
.bv-btn--outline { background: transparent; color: var(--bv-brown); }
.bv-btn--outline:hover { background: var(--bv-sand); }
.step-nav { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
.step-nav.is-compact { max-width: 660px; }
.step-nav #backBtn { margin-right: auto; }

/* Error Alert Box */
.alert.error { padding: 13px 18px; margin-bottom: 18px; border-radius: 14px; background: var(--bv-red-soft); border: 1px solid #f0c9c6; color: var(--bv-red); font-size: 14px; font-weight: 500; }

/* Selected Accommodation Pill & Slide-Over Drawer */
.selected-room-pill { display: inline-flex; align-items: center; gap: 10px; padding: 6px 14px; border-radius: var(--bv-radius-pill); background: var(--bv-green-soft); border: 1px solid #cfe0cc; color: #36593b; font-size: 13px; }
.selected-room-pill[hidden] { display: none !important; }
.selected-room-pill button { padding: 3px 10px; border-radius: var(--bv-radius-pill); border: 1px solid var(--bv-green); background: #fff; color: var(--bv-green); font: inherit; font-size: 11px; font-weight: 600; text-transform: uppercase; cursor: pointer; }

.room-drawer-backdrop { position: fixed; inset: 0; z-index: 1200; background: rgba(32, 20, 12, .42); backdrop-filter: blur(2px); opacity: 0; visibility: hidden; transition: .25s; }
.room-drawer-backdrop.is-open { opacity: 1; visibility: visible; }
.room-drawer { position: fixed; top: 0; right: 0; bottom: 0; z-index: 1201; width: min(390px, 92vw); display: flex; flex-direction: column; justify-content: space-between; padding: 26px 24px; background: var(--bv-card); border-left: 1px solid var(--bv-line); box-shadow: -20px 0 50px rgba(32, 20, 12, .22); transform: translateX(100%); transition: transform .3s ease; overflow-y: auto; }
.room-drawer.is-open { transform: translateX(0); }
.room-drawer-top { display: flex; justify-content: space-between; gap: 12px; }
.room-drawer-eyebrow { font-size: 10.5px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: var(--bv-green); }
.room-drawer-title { margin: 4px 0 0; font-size: 28px; }
.room-drawer-meta { margin: 4px 0 0; font-size: 13px; color: var(--bv-muted); }
.room-drawer-desc { margin: 10px 0 0; font-size: 13.5px; color: var(--bv-muted); line-height: 1.5; }
.room-drawer-close { width: 34px; height: 34px; border-radius: 50%; border: 1px solid var(--bv-line-strong); background: var(--bv-cream); font-size: 18px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.room-drawer-section { margin-top: 18px; padding-top: 16px; border-top: 1px dashed var(--bv-line); }
.room-drawer-label { display: block; font-size: 11px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; color: var(--bv-muted); margin-bottom: 10px; }
.guest-fixed-pill { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; border-radius: var(--bv-radius-pill); background: var(--bv-green-soft); border: 1px solid #cfe0cc; color: #36593b; font-size: 14px; font-weight: 600; }
.guest-fixed-pill[hidden], .guest-family-select[hidden] { display: none !important; }
.guest-family-select { width: 100%; height: 42px; padding: 0 16px; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); background: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.amenities-clean-list { display: grid; gap: 9px; margin: 0; padding: 0; list-style: none; font-size: 13.5px; }
.amenities-clean-list li { display: flex; gap: 9px; }
.amenities-clean-list li::before { content: "✓"; width: 18px; height: 18px; border-radius: 50%; background: var(--bv-green-soft); color: var(--bv-green); display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; flex-shrink: 0; }

/* Validation Red Warnings */
.field-error { display: flex; align-items: center; gap: 5px; margin-top: 6px; color: var(--bv-red) !important; font-size: 12px !important; font-weight: 500; }
.field-error:empty, .field-error[hidden] { display: none !important; }
.field-error::before { content: "⚠"; font-size: 12px; }
.invalid-input { border-color: var(--bv-red) !important; background: var(--bv-red-soft) !important; box-shadow: 0 0 0 3px rgba(184, 50, 50, .15) !important; }
.valid-input { border-color: var(--bv-green) !important; }

/* Category Tabs & Sliders */
.choice-tabs { display: flex; flex-wrap: wrap; gap: 6px 4px; margin: 0 0 16px; border-bottom: 1px solid var(--bv-line); }
.choice-tab { margin-bottom: -1px; padding: 9px 14px; border: 0; border-bottom: 2px solid transparent; background: none; color: var(--bv-muted); font: inherit; font-size: 13px; cursor: pointer; }
.choice-tab.active { border-bottom-color: var(--bv-brown); color: var(--bv-brown); font-weight: 600; }
.choice-tab[hidden] { display: none !important; }
.choice-tab .count { display: none; margin-left: 5px; padding: 1px 7px; border-radius: var(--bv-radius-pill); background: var(--bv-green); color: #fff; font-size: 11px; }
.choice-tab .count.show { display: inline-block; }
.choice-panel { display: none; }
.choice-panel.active { display: block; }

.slider { position: relative; }
.choice-list { display: flex; gap: 18px; padding: 8px 4px 14px; overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: none; }
.choice-list::-webkit-scrollbar { display: none; }
.slider-btn { position: absolute; top: 44%; z-index: 5; width: 40px; height: 40px; border: 1px solid var(--bv-line-strong); border-radius: 50%; background: var(--bv-card); color: var(--bv-brown); font-size: 20px; cursor: pointer; box-shadow: 0 6px 16px rgba(63, 45, 32, .14); display: inline-flex; align-items: center; justify-content: center; }
.slider-btn.prev { left: -12px; }
.slider-btn.next { right: -12px; }
.slider-btn:disabled { opacity: 0; pointer-events: none; }

/* Arch Cards */
.choice-card { position: relative; display: flex; flex: 0 0 clamp(240px, 30%, 285px); flex-direction: column; justify-content: flex-end; height: 405px; padding: 7px; border: 1px solid rgba(90, 64, 48, .55); border-radius: 210px 210px 18px 18px; cursor: pointer; scroll-snap-align: start; transition: transform .22s, border-color .22s, box-shadow .22s; overflow: visible; }
.choice-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.choice-card:hover { transform: translateY(-4px); border-color: var(--bv-brown); box-shadow: 0 14px 28px rgba(45, 30, 18, .2); }
.choice-card.selected { border-color: var(--bv-green); box-shadow: 0 0 0 2px var(--bv-green), 0 12px 24px rgba(100, 133, 104, .22); }
.choice-card.is-dd-open { z-index: 40; }
.choice-card[hidden] { display: none !important; }

.choice-thumb { position: absolute; inset: 7px; z-index: 0; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 203px 203px 12px 12px; background: linear-gradient(160deg, #6e543e, #362417); pointer-events: none; }
.choice-thumb::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(32, 20, 12, .05) 0%, rgba(28, 17, 10, .86) 62%, rgba(22, 13, 8, .96) 100%); }
.choice-thumb img { width: 100%; height: 100%; object-fit: cover; }
.thumb-ph { position: relative; z-index: 1; margin-bottom: 135px; color: rgba(247, 239, 228, .45); font-size: 44px; }

.choice-body { position: relative; z-index: 1; display: grid; grid-template-columns: auto 1fr; align-items: center; gap: 6px 10px; padding: 155px 16px 14px; overflow: visible; }
.choice-facts { display: contents; }
.choice-facts .fact:not(.price) { grid-column: 1 / -1; order: 1; color: rgba(247, 239, 228, .78); font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; }
.choice-name { grid-column: 1 / -1; order: 2; color: #fff; font-family: var(--bv-font-display); font-size: 22px; font-weight: 600; line-height: 1.18; }
.choice-desc { grid-column: 1 / -1; order: 3; display: -webkit-box; min-height: 4.1em; margin-bottom: 4px; overflow: hidden; color: rgba(247, 239, 228, .84); font-size: 12.5px; line-height: 1.42; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
.choice-facts .fact.price { grid-column: 1; order: 4; color: #fff; font-family: var(--bv-font-display); font-size: 18px; font-weight: 600; }

/* Card Action Button */
.choice-card .choice-btn {
    grid-column: 2 !important;
    justify-self: end !important;
    order: 5 !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 5px !important;
    height: 34px !important;
    min-height: 34px !important;
    padding: 0 15px !important;
    margin: 0 !important;
    border: 1px solid rgba(255, 255, 255, .48) !important;
    border-radius: var(--bv-radius-pill) !important;
    background: rgba(26, 16, 10, .48) !important;
    color: #fff !important;
    font-family: var(--bv-font-body) !important;
    font-size: 10.5px !important;
    font-weight: 600 !important;
    letter-spacing: .09em !important;
    text-transform: uppercase !important;
    line-height: 1 !important;
    white-space: nowrap !important;
    vertical-align: middle !important;
    cursor: pointer !important;
}
.choice-card .choice-btn span { display: inline-flex; align-items: center; line-height: 1; }
.choice-card .choice-btn .on { display: none !important; }
.choice-card.selected .choice-btn { border-color: var(--bv-green) !important; background: var(--bv-green) !important; color: #fff !important; }
.choice-card.selected .choice-btn .on { display: inline-flex !important; }
.choice-card.selected .choice-btn .off { display: none !important; }

/* Floating Guest Dropdown */
.guest-assign-wrap { position: relative; grid-column: 1 / -1; order: 6; margin-top: 6px; }
.guest-assign-wrap[hidden] { display: none !important; }
.guest-dd-trigger { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 6px 12px; border-radius: var(--bv-radius-pill); border: 1px solid rgba(255, 255, 255, .35); background: rgba(255, 255, 255, .96); color: var(--bv-ink); font: inherit; font-size: 12px; cursor: pointer; }
.guest-dd-trigger strong { color: var(--bv-green); }
.guest-dd-trigger strong.is-empty { color: var(--bv-muted); font-weight: 500; }
.guest-dd-menu { position: absolute; left: 0; right: 0; bottom: calc(100% + 6px); z-index: 60; padding: 6px; border-radius: 14px; background: #fff; border: 1px solid var(--bv-line-strong); box-shadow: 0 12px 28px rgba(32, 20, 12, .28); max-height: 165px; overflow-y: auto; }
.guest-dd-menu[hidden] { display: none !important; }
.guest-dd-item { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 7px; font-size: 12px; color: var(--bv-ink); cursor: pointer; }
.guest-dd-item:hover { background: var(--bv-sand-soft); }
.guest-dd-item.is-all { border-bottom: 1px dashed var(--bv-line); margin-bottom: 4px; font-weight: 600; }

/* Add-ons with - / + Stepper */
.addon-dropdown-wrap { margin-top: 20px; max-width: 500px; }
.addon-trigger { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 12px 18px; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); background: var(--bv-card); font: inherit; font-size: 14px; cursor: pointer; }
.addon-trigger.has-items { border-color: var(--bv-green); font-weight: 600; color: var(--bv-ink); }
.addon-menu { margin-top: 6px; background: var(--bv-card); border: 1px solid var(--bv-line); border-radius: 18px; padding: 8px; box-shadow: 0 10px 24px rgba(63, 45, 32, .1); }
.addon-menu[hidden] { display: none; }
.addon-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 12px; font-size: 13.5px; transition: background .15s; }
.addon-item:hover { background: var(--bv-sand-soft); }
.addon-item.is-selected { background: var(--bv-green-soft); }
.addon-info { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.addon-info strong { color: var(--bv-ink); font-size: 13.5px; }
.addon-info small { color: var(--bv-muted); font-size: 12px; }
.addon-stepper { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); padding: 3px 6px; flex-shrink: 0; }
.addon-item.is-selected .addon-stepper { border-color: var(--bv-green); }
.addon-qty-btn { width: 26px; height: 26px; border: 0; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); font: inherit; font-size: 15px; font-weight: 700; line-height: 1; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: .15s; }
.addon-qty-btn:hover:not(:disabled) { background: var(--bv-brown); color: #fff; }
.addon-qty-btn:disabled { opacity: .35; cursor: not-allowed; }
.addon-qty-val { min-width: 22px; text-align: center; font-weight: 700; font-size: 13px; color: var(--bv-ink); user-select: none; }

/* Date & Time */
.datetime-layout { display: grid; grid-template-columns: minmax(290px, 350px) minmax(290px, 410px); gap: 20px; }
.datetime-card { display: flex; flex-direction: column; padding: 18px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }
.datetime-card-head { display: flex; align-items: center; justify-content: space-between; height: 38px; margin-bottom: 12px; }
.datetime-card-head h4 { margin: 0; font-size: 22px; }
.calendar-nav { width: 32px; height: 32px; border: 1px solid var(--bv-line); border-radius: 50%; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.calendar-select { border: 0; background: transparent; font-family: var(--bv-font-display); font-size: 20px; font-weight: 600; color: var(--bv-ink); cursor: pointer; }
.calendar-weekdays, .calendar-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.calendar-weekdays span { font-size: 11.5px; color: var(--bv-muted-soft); text-transform: uppercase; padding-bottom: 6px; }
.calendar-days { grid-auto-rows: 36px; }
.calendar-day { border: 0; border-radius: 50%; background: none; font: inherit; font-size: 13.5px; cursor: pointer; }
.calendar-day:hover:not(:disabled) { background: var(--bv-sand); }
.calendar-day:disabled { color: #c3b8aa; cursor: not-allowed; }
.calendar-day.is-selected { background: var(--bv-brown); color: #fff; }
.calendar-note { margin: auto 0 0; padding-top: 10px; font-size: 12px; color: var(--bv-muted-soft); text-align: center; }

.time-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.time-slot input { position: absolute; opacity: 0; }
.time-slot > span { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 46px; border: 1px solid var(--bv-line-strong); border-radius: 10px; background: var(--bv-card); font-size: 13.5px; font-weight: 500; cursor: pointer; }
.time-slot input:checked + span { border-color: var(--bv-brown); background: var(--bv-brown); color: #fff; }
.time-slot.is-booked > span, .time-slot.is-passed > span, .time-slot.is-unknown > span { background: #f4efe9; color: var(--bv-muted-soft); cursor: not-allowed; }
.time-slot em { font-size: 10.5px; font-style: normal; }
.time-slot.is-booked em { color: var(--bv-red); }
.time-hint { margin: 12px 0 0; font-size: 13px; color: var(--bv-muted); text-align: center; }
.time-legend { display: flex; justify-content: center; gap: 16px; margin: auto 0 0; padding: 12px 0 0; list-style: none; font-size: 12px; color: var(--bv-muted); }
.time-legend li { display: flex; align-items: center; gap: 6px; }
.legend-swatch { width: 11px; height: 11px; border: 1px solid var(--bv-line-strong); border-radius: 3px; }
.legend-swatch.is-selected { background: var(--bv-brown); border-color: var(--bv-brown); }
.legend-swatch.is-booked { background: #f4efe9; }
@media (max-width: 900px) { .datetime-layout { grid-template-columns: 1fr; } }

/* Payment */
.pay-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; }
.pay-select { position: relative; margin-bottom: 16px; }
.pay-trigger { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 12px 18px; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); background: var(--bv-card); font: inherit; font-size: 14px; cursor: pointer; }
.pay-trigger.has-value { font-weight: 600; color: var(--bv-ink); }
.pay-options { position: absolute; top: calc(100% + 4px); inset-inline: 0; z-index: 30; margin: 0; padding: 8px; list-style: none; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); box-shadow: 0 14px 30px rgba(63, 45, 32, .16); }
.pay-options[hidden], .pay-box[hidden], .pay-detail[hidden], .prov-panel[hidden], .pay-fields-wrap[hidden], .pay-mode-group[hidden] { display: none !important; }
.pay-option { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 12px; cursor: pointer; }
.pay-option:hover { background: var(--bv-sand-soft); }
.pay-icon { width: 36px; height: 36px; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); display: flex; align-items: center; justify-content: center; font-weight: 600; }
.pay-box { padding: 18px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }

.pay-mode-group { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
.pay-mode-pill { display: flex; flex-direction: column; gap: 2px; padding: 11px 14px; border: 1px solid var(--bv-line-strong); border-radius: 14px; background: var(--bv-cream); cursor: pointer; transition: .15s; }
.pay-mode-pill input { position: absolute; opacity: 0; }
.pay-mode-pill strong { font-size: 13.5px; color: var(--bv-ink); }
.pay-mode-pill small { font-size: 11.5px; color: var(--bv-muted); }
.pay-mode-pill.selected { border-color: var(--bv-green); background: var(--bv-green-soft); }

.pay-amounts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.pay-amounts.is-single { grid-template-columns: 1fr; }
.pay-amounts div { padding: 13px 16px; border-radius: 12px; background: var(--bv-sand-soft); }
.pay-amounts span { display: block; font-size: 12px; color: var(--bv-muted); }
.pay-amounts strong { font-size: 20px; color: var(--bv-brown); }
.pay-note { margin: 12px 0 0; font-size: 13px; color: var(--bv-muted); }
.pay-detail { margin-top: 16px; padding-top: 16px; border-top: 1px dashed #e0d4c3; }
.pay-where { display: grid; gap: 8px; padding: 14px; border-radius: 12px; background: var(--bv-sand-soft); font-size: 13px; }
.pay-where div { display: flex; justify-content: space-between; }
.prov-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(145px, 1fr)); gap: 10px; }
.prov-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--bv-line); border-radius: 14px; background: var(--bv-card); cursor: pointer; }
.prov-row input { position: absolute; opacity: 0; }
.prov-row.selected { border-color: var(--bv-green); background: var(--bv-green-soft); }
.prov-logo { width: 30px; height: 30px; border-radius: 50%; background: var(--bv-sand); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; }
.prov-panel { display: grid; grid-template-columns: 140px 1fr; gap: 18px; align-items: center; margin-top: 14px; padding: 16px; border: 1px solid var(--bv-line); border-radius: 16px; background: var(--bv-cream); }
.pay-qr { width: 140px; height: 140px; padding: 6px; border: 1px solid var(--bv-line); border-radius: 12px; background: #fff; object-fit: contain; }
.pay-qr.placeholder { display: flex; align-items: center; justify-content: center; border-style: dashed; color: var(--bv-muted-soft); font-size: 12px; }
.prov-info dl > div { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px solid #f0e8dc; font-size: 13px; }
.prov-info dd { display: inline-flex; align-items: center; gap: 8px; margin: 0; font-weight: 600; }
.prov-amount { display: flex; justify-content: space-between; margin-top: 10px; padding: 9px 12px; border-radius: 10px; background: #fff; font-size: 13px; }
.copy-btn { height: 26px; padding: 0 10px; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); background: transparent; cursor: pointer; font-size: 11px; display: inline-flex; align-items: center; justify-content: center; line-height: 1; }
.pay-fields-wrap { margin-top: 18px; padding-top: 18px; border-top: 1px dashed #e0d4c3; }
.file-drop { display: block; padding: 16px; border: 1.5px dashed #cdbfae; border-radius: 14px; background: var(--bv-cream); text-align: center; cursor: pointer; }
.file-drop input { display: none; }
.file-drop.has-file { border-style: solid; border-color: var(--bv-green); background: var(--bv-green-soft); }

/* Floating Summary */
.summary-card { position: fixed; right: 24px; bottom: 24px; z-index: 1000; width: 325px; max-width: calc(100vw - 24px); border: 1px solid #eadfce; border-radius: var(--bv-radius-card); background: var(--bv-cream); box-shadow: 0 18px 40px rgba(63, 45, 32, .22); }
.summary-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; cursor: grab; user-select: none; }
.summary-title { flex: 1; min-width: 0; }
.summary-title strong { display: block; font-size: 14px; }
.summary-title small { display: block; overflow: hidden; color: var(--bv-muted); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.summary-head-total { color: var(--bv-brown); font-size: 16px; }
.summary-toggle { width: 28px; height: 28px; border: 1px solid var(--bv-line-strong); border-radius: 50%; background: #fff; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.summary-card.is-open .summary-toggle { transform: rotate(180deg); }
.summary-body { max-height: 60vh; padding: 12px 14px 14px; overflow-y: auto; border-top: 1px solid #eadfce; }
.summary-body[hidden] { display: none; }
.apply-all-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; padding: 8px 10px; border-radius: 12px; background: var(--bv-green-soft); font-size: 12px; color: #36593b; }
.apply-all-bar[hidden] { display: none; }
.apply-all-btn { padding: 4px 10px; border-radius: var(--bv-radius-pill); border: 0; background: var(--bv-green); color: #fff; font: inherit; font-size: 11px; font-weight: 600; cursor: pointer; }
.summary-box { padding: 10px 12px; border-radius: 12px; background: #efebe6; font-size: 12.5px; }
.breakdown .row { display: flex; justify-content: space-between; padding: 3px 0; }
.breakdown .row.sub { padding-left: 10px; color: var(--bv-muted); font-size: 12px; }
.summary-meta { margin: 10px 0 0; font-size: 12.5px; }
.summary-meta div { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px dashed #e0d4c3; }
.summary-total { display: flex; justify-content: space-between; margin-top: 10px; font-size: 13.5px; }
.summary-total strong { color: var(--bv-brown); font-size: 18px; }
.summary-split { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.summary-split div { padding: 8px 10px; border-radius: 10px; background: #efebe6; font-size: 12px; }

/* Review Box & Clean Booking Policies */
.review-box { margin-bottom: 18px; border: 1px solid var(--bv-line); border-radius: 16px; background: var(--bv-card); padding: 14px 18px; }
.review-box div { display: flex; justify-content: space-between; gap: 16px; padding: 9px 0; border-bottom: 1px solid #f0e8dc; font-size: 13.5px; }
.review-box div:last-child { border-bottom: 0; }
.review-box dt { color: var(--bv-muted); }
.review-box dd { margin: 0; font-weight: 600; text-align: right; }

.policy-box { margin-bottom: 16px; border: 1px solid var(--bv-line); border-left: 3.5px solid var(--bv-brown); border-radius: 16px; background: var(--bv-card); padding: 18px 20px; }
.policy-box h4 { margin: 0 0 12px; font-size: 22px; display: flex; align-items: center; gap: 8px; }
.policy-box ul { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
.policy-box li { display: grid; grid-template-columns: 14px 1fr; gap: 8px; font-size: 13px; line-height: 1.48; color: var(--bv-muted); padding-bottom: 9px; border-bottom: 1px dashed #efe6d8; }
.policy-box li:last-child { border-bottom: 0; padding-bottom: 0; }
.policy-box li::before { content: "•"; color: var(--bv-gold); font-weight: 700; font-size: 15px; line-height: 1.2; }
.policy-box li .pol-text { min-width: 0; overflow-wrap: break-word; }
.policy-box li strong { color: var(--bv-ink); font-weight: 600; }
.terms-check-row { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 14px; background: var(--bv-sand-soft); border: 1px solid var(--bv-line); font-size: 13.5px; font-weight: 500; color: var(--bv-ink); cursor: pointer; }
.terms-check-row input { width: 17px; height: 17px; accent-color: var(--bv-green); cursor: pointer; }
</style>
</head>

<body class="booking-page">
<?php if (!$preview): ?>
<header class="site-header">
    <a class="brand" href="index.php"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></a>
    <nav class="nav-links"><a href="track.php">Track my booking</a><a href="index.php">← Back to website</a></nav>
</header>
<?php endif; ?>

<main class="booking-layout">
<aside class="booking-aside">
    <p class="eyebrow">Your time, your ritual</p>
    <h1>Make space<br>for <em>yourself.</em></h1>
    <p>Choose your accommodation, select your treatments, and pick your preferred schedule.</p>
    <div class="aside-detail"><span>✦</span><div><strong>Flexible Spaces</strong><small>Open Treatment Area or Private Suites.</small></div></div>
    <div class="aside-detail"><span>◷</span><div><strong>Tailored Care</strong><small>Individual services or curated packages.</small></div></div>
</aside>

<section class="booking-form-wrap">
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <div class="booking-head">
        <h2>Reserve your visit</h2>
        <ol class="stepper" id="stepper">
            <li data-step="1" class="active">Accommodation &amp; Services</li>
            <li data-step="2">Date &amp; Time</li>
            <li data-step="3">Your Details</li>
            <li data-step="4">Payment</li>
            <li data-step="5">Confirmation</li>
        </ol>
    </div>

    <form action="save_booking.php" method="post" id="bookingForm" enctype="multipart/form-data" novalidate>
    <div id="hiddenGuestInputs"></div>
    <input type="hidden" name="service_id" id="service_id" value="">
    <input type="hidden" name="guests" id="guests" value="1">
    <input type="hidden" name="accommodation_type" id="accommodation_type" value="">

    <!-- STEP 1: ACCOMMODATION & SERVICES -->
    <div class="step-panel active" data-step="1">
        <div class="step-head">
            <h3>Choose Accommodation</h3>
            <div class="selected-room-pill" id="selectedRoomPill" hidden>
                <span>✓ <strong id="pillRoomName">Open Treatment Area</strong> · <span id="pillGuestCount">1 guest</span></span>
                <button type="button" id="reopenRoomDrawerBtn">Details &amp; Guests</button>
            </div>
        </div>

        <div class="choice-list" id="roomList">
            <?php foreach ($accommodations as $acc):
                $img = $acc['id'] > 0 ? item_image($acc, 'rooms') : '';
                $priceLabel = $acc['price'] > 0 ? '₱' . number_format($acc['price'], 2) : 'Free / Included';
                $valAttr = $acc['id'] > 0 ? (string)$acc['id'] : '';
            ?>
            <label class="choice-card room-card"
                   data-min="<?= (int)$acc['min_guests'] ?>"
                   data-max="<?= (int)$acc['max_guests'] ?>"
                   data-category="<?= e($acc['cat']) ?>"
                   data-name="<?= e($acc['name']) ?>"
                   data-desc="<?= e($acc['description']) ?>"
                   data-duration="<?= (int)$acc['duration_minutes'] ?>"
                   data-price="<?= e((string)$acc['price']) ?>"
                   data-price-label="<?= e($priceLabel) ?>"
                   data-amenities="<?= e(json_encode($acc['amenities'], JSON_UNESCAPED_UNICODE)) ?>">
                <input type="radio" name="room_id" value="<?= e($valAttr) ?>" data-cat="<?= e($acc['cat']) ?>">
                <span class="choice-thumb">
                    <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($acc['name']) ?>" loading="lazy"><?php else: ?><span class="thumb-ph"><?= $acc['cat'] === 'open' ? '✦' : '⌂' ?></span><?php endif; ?>
                </span>
                <span class="choice-body">
                    <strong class="choice-name"><?= e($acc['name']) ?></strong>
                    <span class="choice-desc"><?= e($acc['description']) ?></span>
                    <span class="choice-facts">
                        <span class="fact"><?= e($acc['badge']) ?></span>
                        <span class="fact price"><?= e($priceLabel) ?></span>
                    </span>
                    <span class="choice-btn"><span class="off">Select</span><span class="on">✓ Selected</span></span>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
        <small class="field-error" id="roomError"></small>

        <div class="sub-section-title">Spa Services</div>

        <div class="choice-tabs" role="tablist">
            <?php $first = true; foreach ($serviceCategories as $catKey => $catLabel): ?>
            <button type="button" class="choice-tab <?= $first ? 'active' : '' ?>" data-panel="panel_<?= e($catKey) ?>">
                <?= e($catLabel) ?> <span class="count" data-count-cat="<?= e($catKey) ?>"></span>
            </button>
            <?php $first = false; endforeach; ?>
            <button type="button" class="choice-tab" id="tabPackages" data-panel="panel_packages">
                Spa Packages <span class="count" data-count-cat="package"></span>
            </button>
        </div>

        <?php $first = true; foreach ($serviceCategories as $catKey => $catLabel): ?>
        <div class="choice-panel <?= $first ? 'active' : '' ?>" id="panel_<?= e($catKey) ?>">
            <div class="choice-list">
                <?php foreach ($servicesByCategory[$catKey] as $s):
                    $img = item_image($s, 'services');
                    $key = 'service:' . (int)$s['id'];
                ?>
                <div class="choice-card treatment-card"
                     data-key="<?= $key ?>" data-type="service" data-category="<?= e($catKey) ?>"
                     data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['name']) ?>"
                     data-price="<?= e((string)$s['price']) ?>" data-duration="<?= (int)$s['duration_minutes'] ?>"
                     data-preselect="<?= $selectedService === (int)$s['id'] ? '1' : '0' ?>">
                    <span class="choice-thumb">
                        <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($s['name']) ?>" loading="lazy"><?php else: ?><span class="thumb-ph">✦</span><?php endif; ?>
                    </span>
                    <span class="choice-body">
                        <strong class="choice-name"><?= e($s['name']) ?></strong>
                        <span class="choice-desc"><?= e($s['description'] ?? '') ?></span>
                        <span class="choice-facts">
                            <span class="fact">◷ <?= (int)$s['duration_minutes'] ?> mins</span>
                            <span class="fact price">₱<?= number_format((float)$s['price'], 2) ?></span>
                        </span>
                        <button type="button" class="choice-btn js-toggle-item"><span class="off">+ Add</span><span class="on">✓ Added</span></button>
                        <div class="guest-assign-wrap js-guest-bar" hidden>
                            <button type="button" class="guest-dd-trigger js-guest-dd-trigger" aria-expanded="false">
                                <span>For guest: <strong class="js-guest-dd-label is-empty">Select guests</strong></span><span>▾</span>
                            </button>
                            <div class="guest-dd-menu js-guest-dd-menu" hidden></div>
                        </div>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php $first = false; endforeach; ?>

        <div class="choice-panel" id="panel_packages">
            <div class="choice-list">
                <?php foreach ($packages as $p):
                    $img = item_image($p, 'packages');
                    $key = 'package:' . (int)$p['id'];
                    $pkgType = strtolower(trim((string)($p['room_type'] ?? 'all')));
                    if ($pkgType === 'couple') $pkgType = 'couples';
                    $pkgBadge = $pkgType === 'single' ? 'Single Room' : ($pkgType === 'couples' ? 'Couple Room' : 'Family Lounge');
                ?>
                <div class="choice-card treatment-card package-card"
                     data-key="<?= $key ?>" data-type="package" data-category="package"
                     data-id="<?= (int)$p['id'] ?>" data-room-type="<?= e($pkgType) ?>"
                     data-name="<?= e($p['name']) ?>" data-price="<?= e((string)$p['price']) ?>"
                     data-duration="<?= (int)$p['duration_minutes'] ?>">
                    <span class="choice-thumb">
                        <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>" loading="lazy"><?php else: ?><span class="thumb-ph">❀</span><?php endif; ?>
                    </span>
                    <span class="choice-body">
                        <strong class="choice-name"><?= e($p['name']) ?></strong>
                        <span class="choice-desc"><?= e($p['description'] ?? '') ?></span>
                        <span class="choice-facts">
                            <span class="fact"><?= e($pkgBadge) ?> · ◷ <?= (int)$p['duration_minutes'] ?> mins</span>
                            <span class="fact price">₱<?= number_format((float)$p['price'], 2) ?></span>
                        </span>
                        <button type="button" class="choice-btn js-toggle-item"><span class="off">+ Add</span><span class="on">✓ Added</span></button>
                        <div class="guest-assign-wrap js-guest-bar" hidden>
                            <button type="button" class="guest-dd-trigger js-guest-dd-trigger" aria-expanded="false">
                                <span>For guest: <strong class="js-guest-dd-label is-empty">Select guests</strong></span><span>▾</span>
                            </button>
                            <div class="guest-dd-menu js-guest-dd-menu" hidden></div>
                        </div>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <small class="field-error" id="serviceError"></small>

        <?php if ($addons): ?>
        <div class="addon-dropdown-wrap" id="addonWrap">
            <label class="pay-label">Optional Add-ons</label>
            <button type="button" class="addon-trigger" id="addonTrigger" aria-expanded="false">
                <span id="addonTriggerText">Select add-ons (0 selected)</span><span>▾</span>
            </button>
            <div class="addon-menu" id="addonMenu" hidden>
                <?php foreach ($addons as $ad): $adId = (int)$ad['id']; ?>
                <div class="addon-item" data-id="<?= $adId ?>" data-name="<?= e($ad['name']) ?>" data-price="<?= e((string)$ad['price']) ?>">
                    <input type="hidden" name="addon_qty[<?= $adId ?>]" value="0" class="js-addon-qty-input">
                    <div class="addon-info">
                        <strong><?= e($ad['name']) ?></strong>
                        <small>+₱<?= number_format((float)$ad['price'], 2) ?> each</small>
                    </div>
                    <div class="addon-stepper">
                        <button type="button" class="addon-qty-btn js-addon-minus" aria-label="Decrease quantity" disabled>−</button>
                        <span class="addon-qty-val js-addon-qty-display">0</span>
                        <button type="button" class="addon-qty-btn js-addon-plus" aria-label="Increase quantity">+</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- STEP 2: DATE & TIME -->
    <div class="step-panel" data-step="2">
        <div class="step-head"><h3>Choose a date &amp; time</h3></div>
        <input type="hidden" name="appointment_date" id="appointment_date">
        <div class="datetime-layout">
            <section class="datetime-card">
                <header class="datetime-card-head">
                    <button type="button" class="calendar-nav" id="calendarPrev">‹</button>
                    <div><select class="calendar-select" id="calendarMonth"></select><select class="calendar-select" id="calendarYear"></select></div>
                    <button type="button" class="calendar-nav" id="calendarNext">›</button>
                </header>
                <div class="calendar-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                <div class="calendar-days" id="calendarDays"></div>
                <p class="calendar-note">Closed on Mondays</p>
            </section>
            <section class="datetime-card">
                <header class="datetime-card-head"><h4 id="selectedDateLabel">Select a date</h4></header>
                <div class="time-grid" id="timeGrid"></div>
                <p class="time-hint" id="timeHint">Pick a date first.</p>
                <ul class="time-legend">
                    <li><i class="legend-swatch"></i>Available</li>
                    <li><i class="legend-swatch is-selected"></i>Selected</li>
                    <li><i class="legend-swatch is-booked"></i>Booked</li>
                </ul>
            </section>
        </div>
        <small class="field-error" id="dateError"></small>
        <small class="field-error" id="timeError"></small>
    </div>

    <!-- STEP 3: YOUR DETAILS -->
    <div class="step-panel" data-step="3">
        <div class="compact-step-wrap">
            <div class="step-head"><h3>Your details</h3></div>
            <div class="form-grid" id="detailsGrid">
                <label class="full">Full name<input type="text" name="customer_name" id="customer_name" required maxlength="120" placeholder="First and last name"><small class="field-error" id="nameError"></small></label>
                <label>Email address<input type="email" name="email" id="email" required maxlength="150" placeholder="name@email.com"><small class="field-error" id="emailError"></small></label>
                <label>Phone number<input type="tel" name="phone" id="phone" required maxlength="12" inputmode="numeric" placeholder="09XXXXXXXXX"><small class="field-error" id="phoneError"></small></label>
                <label class="full">Notes (optional)<textarea name="notes" id="notes" rows="3" maxlength="1000" placeholder="Allergies, injuries, or special requests"></textarea></label>
            </div>
        </div>
    </div>

    <!-- STEP 4: PAYMENT -->
    <div class="step-panel" data-step="4">
        <div class="compact-step-wrap">
            <div class="step-head"><h3>Payment</h3></div>
            <div class="pay-select" id="paySelect">
                <span class="pay-label">Payment method</span>
                <input type="hidden" name="payment_method" id="payment_method" value="">
                <input type="hidden" name="payment_provider" id="payment_provider" value="">
                <input type="hidden" name="payment_plan" id="payment_plan" value="downpayment">
                <button type="button" class="pay-trigger" id="payTrigger"><span id="payTriggerText">Select payment method</span><span>▾</span></button>
                <ul class="pay-options" id="payOptions" hidden>
                    <?php foreach ($paymentMethods as $key => $m): ?>
                        <li class="pay-option" data-value="<?= e($key) ?>" data-label="<?= e($m['label']) ?>">
                            <span class="pay-icon"><?= e($m['icon']) ?></span>
                            <span><strong><?= e($m['label']) ?></strong><br><small><?= e($m['hint']) ?></small></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <small class="field-error" id="methodError"></small>
            </div>

            <div class="pay-box" id="payBox" hidden>
                <div class="pay-mode-group" id="payModeGroup" hidden>
                    <label class="pay-mode-pill selected" id="modeDownpaymentPill">
                        <input type="radio" name="pay_plan_radio" value="downpayment" checked>
                        <strong>Downpayment</strong>
                        <small>30% reservation fee</small>
                    </label>
                    <label class="pay-mode-pill" id="modeFullPill">
                        <input type="radio" name="pay_plan_radio" value="full">
                        <strong>Full Payment</strong>
                        <small>Pay 100% online now</small>
                    </label>
                </div>

                <div class="pay-amounts" id="payAmountsGrid">
                    <div id="payNowBox"><span>30% Reservation Fee</span><strong class="js-due">₱0.00</strong></div>
                    <div id="payStoreBox"><span id="payStoreLabel">Pay at the store</span><strong class="js-balance">₱0.00</strong></div>
                </div>
                <p class="pay-note" id="payNote"></p>
                <input type="hidden" name="amount_due" id="amount_due" value="0">

                <div class="pay-detail" data-method="cash" hidden>
                    <div class="pay-where">
                        <div><span>Location</span><strong><?= e($spaInfo['address']) ?></strong></div>
                        <div><span>Hours</span><strong><?= e($spaInfo['hours']) ?></strong></div>
                        <div><span>Contact</span><strong><?= e($spaInfo['phone']) ?></strong></div>
                    </div>
                </div>

                <?php foreach (['ewallet', 'bank'] as $mk): $mm = $paymentMethods[$mk]; ?>
                <div class="pay-detail" data-method="<?= $mk ?>" hidden>
                    <div class="pay-label"><?= $mk === 'ewallet' ? 'Choose e-wallet' : 'Choose bank' ?></div>
                    <div class="prov-list">
                        <?php foreach ($mm['providers'] as $pk => $pv): ?>
                            <label class="prov-row">
                                <input type="radio" name="prov_<?= $mk ?>" value="<?= e($pk) ?>" data-method="<?= $mk ?>" data-name="<?= e($pv['name']) ?>">
                                <span class="prov-logo"><?= e($pv['initial']) ?></span>
                                <span><?= e($pv['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="field-error" id="provError_<?= $mk ?>"></small>

                    <?php foreach ($mm['providers'] as $pk => $pv): $qr = payment_qr_image($pv, $pk); ?>
                    <div class="prov-panel" data-method="<?= $mk ?>" data-provider="<?= e($pk) ?>" hidden>
                        <div><?= $qr ? '<img class="pay-qr" src="' . e($qr) . '" alt="' . e($pv['name']) . ' QR">' : '<div class="pay-qr placeholder">QR code</div>' ?></div>
                        <div class="prov-info">
                            <dl>
                                <?php foreach ($pv['details'] as $d): ?>
                                <div><dt><?= e($d[0]) ?></dt><dd><span><?= e($d[1]) ?></span><?php if ($d[2]): ?><button type="button" class="copy-btn" data-copy="<?= e($d[1]) ?>">Copy</button><?php endif; ?></dd></div>
                                <?php endforeach; ?>
                            </dl>
                            <div class="prov-amount"><span>Amount to send</span><strong class="js-due">₱0.00</strong></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <div class="pay-fields-wrap" id="payFields" hidden>
                    <div class="form-grid">
                        <label class="full">Reference number<input type="text" name="reference_no" id="reference_no" maxlength="25" placeholder="e.g. 1234567890123"><small class="field-error" id="refError"></small></label>
                        <div class="full">
                            <label class="file-drop" id="fileDrop" for="proof_image">
                                <strong id="fileTitle">Upload proof of payment</strong><br>
                                <small>JPG, PNG, WEBP or PDF · max 5 MB</small>
                                <input type="file" name="proof_image" id="proof_image" accept="image/jpeg,image/png,image/webp,application/pdf">
                            </label>
                            <small class="field-error" id="proofError"></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 5: CONFIRMATION -->
    <div class="step-panel" data-step="5">
        <div class="compact-step-wrap">
            <div class="step-head"><h3>Confirm your request</h3></div>
            <dl class="review-box" id="reviewBox"></dl>
            <div class="policy-box">
                <h4>Booking policies</h4>
                <ul><?php foreach ($policies as $pol): ?><li><span class="pol-text"><strong><?= e($pol[0]) ?>:</strong> <?= e($pol[1]) ?></span></li><?php endforeach; ?></ul>
            </div>
            <label class="terms-check-row"><input type="checkbox" id="terms" name="agree_terms" value="1"> I agree to the booking policies above.</label>
            <small class="field-error" id="termsError" hidden></small>
        </div>
    </div>

    <div class="step-nav" id="stepNav">
        <button type="button" class="bv-btn bv-btn--outline" id="backBtn" style="display:none;">← Back</button>
        <button type="button" class="bv-btn bv-btn--primary" id="nextBtn">Next →</button>
        <button type="submit" class="bv-btn bv-btn--primary" id="submitBtn" style="display:none;">Submit →</button>
    </div>

    <!-- Floating Draggable Summary -->
    <aside class="summary-card" id="summaryCard">
        <div class="summary-head" id="summaryHandle">
            <span style="color:#8a7a6b;">⋮⋮</span>
            <div class="summary-title"><strong>Booking Summary</strong><small id="summaryCount">No service yet</small></div>
            <strong class="summary-head-total" id="summaryHeadTotal">₱0.00</strong>
            <button type="button" class="summary-toggle" id="summaryToggle">▾</button>
        </div>
        <div class="summary-body" id="summaryBody" hidden>
            <div class="apply-all-bar" id="applyAllBar" hidden>
                <span>Same services for all guests?</span>
                <button type="button" class="apply-all-btn" id="applyAllBtn">Apply to all</button>
            </div>
            <div class="summary-box breakdown" id="breakdown">No service selected yet</div>
            <dl class="summary-meta">
                <div><dt>Accommodation</dt><dd id="sumRoom">—</dd></div>
                <div><dt>Guests</dt><dd id="sumGuests">—</dd></div>
                <div><dt>Date</dt><dd id="sumDate">—</dd></div>
                <div><dt>Time</dt><dd id="sumTime">—</dd></div>
                <div><dt>Payment</dt><dd id="sumPayment">—</dd></div>
            </dl>
            <div class="summary-total"><span>Estimated total</span><strong id="totalPrice">₱0.00</strong></div>
            <div class="summary-split">
                <div><span>Pay now</span><strong id="sumDue">₱0.00</strong></div>
                <div><span>Pay at the store</span><strong id="sumBalance">₱0.00</strong></div>
            </div>
        </div>
    </aside>
    </form>
</section>
</main>

<!-- Slide-Over Drawer for Accommodation Details & Guests -->
<div class="room-drawer-backdrop" id="roomDrawerBackdrop"></div>
<aside class="room-drawer" id="roomDrawer">
    <div>
        <div class="room-drawer-top">
            <div>
                <span class="room-drawer-eyebrow">✦ Selected Accommodation</span>
                <h4 class="room-drawer-title" id="drawerRoomName">Open Treatment Area</h4>
                <p class="room-drawer-meta" id="drawerRoomMeta">Free / Included</p>
            </div>
            <button type="button" class="room-drawer-close" id="closeRoomDrawerBtn">×</button>
        </div>
        <p class="room-drawer-desc" id="drawerRoomDesc"></p>

        <div class="room-drawer-section">
            <span class="room-drawer-label">Number of Guests</span>
            <div id="guestFixedDisplay" class="guest-fixed-pill"><span id="guestFixedText">1 guest</span><small>Fixed</small></div>
            <select id="familyGuestsSelect" class="guest-family-select" hidden>
                <option value="3">3 guests</option>
                <option value="4">4 guests</option>
                <option value="5">5 guests</option>
                <option value="6">6 guests</option>
            </select>
        </div>

        <div class="room-drawer-section">
            <span class="room-drawer-label">Included Features</span>
            <ul class="amenities-clean-list" id="roomAmenitiesList"></ul>
        </div>
    </div>
    <div style="margin-top:20px;"><button type="button" class="bv-btn bv-btn--primary" style="width:100%;" id="confirmRoomDrawerBtn">Confirm →</button></div>
</aside>

<script>
window.BOOKING_CONFIG = <?= json_encode($bookingConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

(function () {
    'use strict';
    const config = window.BOOKING_CONFIG;
    const byId = id => document.getElementById(id);
    const formatPeso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const normTime = t => { const m = String(t || '').trim().match(/^(\d{1,2}):(\d{2})/); return m ? m[1].padStart(2, '0') + ':' + m[2] : ''; };

    const guestsInput = byId('guests'), familySelect = byId('familyGuestsSelect'), dateInput = byId('appointment_date');
    const nameInput = byId('customer_name'), emailInput = byId('email'), phoneInput = byId('phone');
    const paymentMethodInput = byId('payment_method'), paymentProviderInput = byId('payment_provider'), paymentPlanInput = byId('payment_plan'), referenceInput = byId('reference_no'), proofInput = byId('proof_image');

    let currentStep = 1, currentTotal = 0;
    const guestPicks = { 1: new Set() }, activeCards = new Set();

    document.querySelectorAll('.treatment-card[data-preselect="1"]').forEach(c => { activeCards.add(c.dataset.key); guestPicks[1].add(c.dataset.key); });

    const ensureGuestSets = n => {
        for (let g = 1; g <= n; g++) if (!guestPicks[g]) guestPicks[g] = new Set();
        Object.keys(guestPicks).forEach(k => { if (+k > n) delete guestPicks[k]; });
    };

    const getSelectedRoomCard = () => document.querySelector('.room-card input[type="radio"]:checked')?.closest('.room-card') || null;
    const openDrawer = () => { byId('roomDrawer').classList.add('is-open'); byId('roomDrawerBackdrop').classList.add('is-open'); };
    const closeDrawer = () => { byId('roomDrawer').classList.remove('is-open'); byId('roomDrawerBackdrop').classList.remove('is-open'); };

    ['closeRoomDrawerBtn', 'confirmRoomDrawerBtn', 'roomDrawerBackdrop'].forEach(id => byId(id).addEventListener('click', closeDrawer));
    byId('reopenRoomDrawerBtn').addEventListener('click', openDrawer);

    /* ---------- Accommodation Selection ---------- */
    function handleAccommodationChange(preferredGuests = null, showDrawer = false) {
        const selected = getSelectedRoomCard();
        document.querySelectorAll('.room-card').forEach(c => c.classList.toggle('selected', c === selected));

        if (!selected) {
            byId('selectedRoomPill').hidden = true;
            guestsInput.value = '1';
            filterPackagesByAccommodation('all');
            return;
        }

        byId('roomError').textContent = '';
        const min = +selected.dataset.min || 1, max = +selected.dataset.max || 1, cat = selected.dataset.category;
        byId('accommodation_type').value = cat;
        byId('drawerRoomName').textContent = selected.dataset.name;
        byId('drawerRoomMeta').textContent = selected.dataset.priceLabel;
        byId('drawerRoomDesc').textContent = selected.dataset.desc;

        if (min === max) {
            byId('guestFixedDisplay').hidden = false;
            familySelect.hidden = true;
            byId('guestFixedText').textContent = min === 1 ? '1 guest' : `${min} guests`;
            guestsInput.value = String(min);
        } else {
            byId('guestFixedDisplay').hidden = true;
            familySelect.hidden = false;
            let opts = '';
            for (let i = min; i <= max; i++) opts += `<option value="${i}">${i} guests</option>`;
            familySelect.innerHTML = opts;
            const val = (preferredGuests >= min && preferredGuests <= max) ? preferredGuests : min;
            familySelect.value = String(val);
            guestsInput.value = String(val);
        }

        const gVal = +guestsInput.value || 1;
        byId('selectedRoomPill').hidden = false;
        byId('pillRoomName').textContent = selected.dataset.name;
        byId('pillGuestCount').textContent = gVal === 1 ? '1 guest' : `${gVal} guests`;

        let am = [];
        try { am = JSON.parse(selected.dataset.amenities || '[]'); } catch (e) {}
        byId('roomAmenitiesList').innerHTML = am.map(a => `<li><span>${escapeHtml(a)}</span></li>`).join('');

        filterPackagesByAccommodation(cat);
        if (showDrawer) openDrawer();
    }

    function filterPackagesByAccommodation(cat) {
        const tabPackages = byId('tabPackages');
        if (cat === 'open') {
            tabPackages.hidden = true;
            if (tabPackages.classList.contains('active')) {
                document.querySelector('.choice-tab[data-panel="panel_massage"]').click();
            }
            document.querySelectorAll('.package-card').forEach(pkg => {
                pkg.hidden = true;
                activeCards.delete(pkg.dataset.key);
                Object.values(guestPicks).forEach(set => set.delete(pkg.dataset.key));
            });
        } else {
            tabPackages.hidden = false;
            document.querySelectorAll('.package-card').forEach(pkg => {
                const allowed = (cat === 'all' || pkg.dataset.roomType === cat);
                pkg.hidden = !allowed;
                if (!allowed) {
                    activeCards.delete(pkg.dataset.key);
                    Object.values(guestPicks).forEach(set => set.delete(pkg.dataset.key));
                }
            });
        }
        refreshSliders();
    }

    document.querySelectorAll('.room-card').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        radio.addEventListener('change', () => {
            handleAccommodationChange(null, true);
            updateSummary();
            if (dateInput.value) loadSlotsForDate(dateInput.value, document.querySelector('input[name="appointment_time"]:checked')?.value || '');
        });
        card.addEventListener('click', e => { if (radio.checked && e.target !== radio) openDrawer(); });
    });

    familySelect.addEventListener('change', () => {
        guestsInput.value = familySelect.value;
        byId('pillGuestCount').textContent = `${guestsInput.value} guests`;
        updateSummary();
    });

    /* ---------- Services & Per-Guest Assignment ---------- */
    function getAssignedGuests(key) {
        const gCount = +guestsInput.value || 1, list = [];
        for (let g = 1; g <= gCount; g++) if (guestPicks[g]?.has(key)) list.push(g);
        return list;
    }

    function closeAllGuestMenus() {
        document.querySelectorAll('.guest-dd-menu').forEach(m => m.hidden = true);
        document.querySelectorAll('.choice-card').forEach(c => c.classList.remove('is-dd-open'));
    }

    function syncTreatmentCardsUI() {
        const gCount = +guestsInput.value || 1;
        ensureGuestSets(gCount);
        byId('applyAllBar').hidden = gCount <= 1;

        document.querySelectorAll('.treatment-card').forEach(card => {
            const key = card.dataset.key, assigned = getAssignedGuests(key);
            if (assigned.length > 0) activeCards.add(key);
            if (gCount === 1 && activeCards.has(key)) guestPicks[1].add(key);

            const isSelected = activeCards.has(key);
            card.classList.toggle('selected', isSelected);

            const assignBar = card.querySelector('.js-guest-bar'), ddLabel = card.querySelector('.js-guest-dd-label'), ddMenu = card.querySelector('.js-guest-dd-menu');
            if (gCount > 1 && isSelected) {
                assignBar.hidden = false;
                ddLabel.textContent = assigned.length === 0 ? 'Select guests' : (assigned.length === gCount ? `All guests (${gCount})` : assigned.map(g => 'G' + g).join(', '));
                ddLabel.classList.toggle('is-empty', assigned.length === 0);

                let html = `<label class="guest-dd-item is-all"><input type="checkbox" data-guest="all" ${assigned.length === gCount ? 'checked' : ''}><span>All guests (${gCount})</span></label>`;
                for (let g = 1; g <= gCount; g++) html += `<label class="guest-dd-item"><input type="checkbox" data-guest="${g}" ${assigned.includes(g) ? 'checked' : ''}><span>Guest ${g}</span></label>`;
                ddMenu.innerHTML = html;
            } else {
                assignBar.hidden = true;
                ddMenu.hidden = true;
                card.classList.remove('is-dd-open');
            }
        });

        const counts = { massage: 0, body: 0, facial: 0, nail: 0, hair: 0, package: 0 };
        Object.values(guestPicks).forEach(set => set.forEach(k => {
            const cat = document.querySelector(`.treatment-card[data-key="${k}"]`)?.dataset.category;
            if (cat && counts[cat] !== undefined) counts[cat]++;
        }));
        Object.entries(counts).forEach(([cat, c]) => {
            const b = document.querySelector(`.choice-tab .count[data-count-cat="${cat}"]`);
            if (b) { b.textContent = c; b.classList.toggle('show', c > 0); }
        });
    }

    document.querySelectorAll('.treatment-card').forEach(card => {
        const key = card.dataset.key, ddTrigger = card.querySelector('.js-guest-dd-trigger'), ddMenu = card.querySelector('.js-guest-dd-menu');
        card.querySelector('.js-toggle-item').addEventListener('click', e => {
            e.preventDefault();
            const gCount = +guestsInput.value || 1;
            ensureGuestSets(gCount);
            if (activeCards.has(key)) {
                activeCards.delete(key);
                for (let g = 1; g <= gCount; g++) guestPicks[g].delete(key);
                ddMenu.hidden = true;
                card.classList.remove('is-dd-open');
            } else {
                activeCards.add(key);
                if (gCount === 1) guestPicks[1].add(key);
                else {
                    closeAllGuestMenus();
                    ddMenu.hidden = false;
                    card.classList.add('is-dd-open');
                }
            }
            updateSummary();
        });

        ddTrigger.addEventListener('click', e => {
            e.preventDefault(); e.stopPropagation();
            const open = ddMenu.hidden;
            closeAllGuestMenus();
            if (open) {
                ddMenu.hidden = false;
                card.classList.add('is-dd-open');
            }
        });

        ddMenu.addEventListener('change', e => {
            const cb = e.target; if (!cb || cb.type !== 'checkbox') return;
            const gCount = +guestsInput.value || 1;
            ensureGuestSets(gCount);
            if (cb.dataset.guest === 'all') {
                for (let g = 1; g <= gCount; g++) cb.checked ? guestPicks[g].add(key) : guestPicks[g].delete(key);
            } else {
                const g = +cb.dataset.guest;
                guestPicks[g].has(key) ? guestPicks[g].delete(key) : guestPicks[g].add(key);
            }
            updateSummary();
        });
    });

    document.addEventListener('click', e => { if (!e.target.closest('.guest-assign-wrap')) closeAllGuestMenus(); });
    byId('applyAllBtn').addEventListener('click', () => {
        const gCount = +guestsInput.value || 1;
        if (!activeCards.size) return;
        for (let g = 1; g <= gCount; g++) guestPicks[g] = new Set(activeCards);
        updateSummary();
    });

    /* ---------- Add-ons (- / + Quantity Stepper) ---------- */
    const addonTrigger = byId('addonTrigger'), addonMenu = byId('addonMenu');
    if (addonTrigger && addonMenu) {
        addonTrigger.addEventListener('click', () => { addonMenu.hidden = !addonMenu.hidden; });
        document.addEventListener('click', e => { if (!byId('addonWrap').contains(e.target)) addonMenu.hidden = true; });

        addonMenu.querySelectorAll('.addon-item').forEach(item => {
            const input   = item.querySelector('.js-addon-qty-input');
            const display = item.querySelector('.js-addon-qty-display');
            const minus   = item.querySelector('.js-addon-minus');
            const plus    = item.querySelector('.js-addon-plus');

            const setQty = next => {
                const qty = Math.max(0, Math.min(20, next));
                input.value = String(qty);
                display.textContent = String(qty);
                minus.disabled = qty === 0;
                item.classList.toggle('is-selected', qty > 0);
                updateSummary();
            };

            minus.addEventListener('click', () => setQty((+input.value || 0) - 1));
            plus.addEventListener('click', () => setQty((+input.value || 0) + 1));
        });
    }

    function getSelectedAddons() {
        return Array.from(document.querySelectorAll('#addonMenu .addon-item'))
            .map(item => ({
                id: +item.dataset.id,
                name: item.dataset.name,
                price: +item.dataset.price,
                qty: +(item.querySelector('.js-addon-qty-input')?.value || 0),
            }))
            .filter(a => a.qty > 0);
    }

    /* ---------- Summary & Hidden Inputs ---------- */
    function updateSummary() {
        const gCount = +guestsInput.value || 1;
        syncTreatmentCardsUI();

        const hiddenBox = byId('hiddenGuestInputs');
        hiddenBox.innerHTML = '';
        let firstServiceId = '';

        for (let g = 1; g <= gCount; g++) {
            (guestPicks[g] || []).forEach(key => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = `guest_items[${g}][]`; inp.value = key;
                hiddenBox.appendChild(inp);
                if (!firstServiceId && key.startsWith('service:')) firstServiceId = key.split(':')[1];
            });
        }
        byId('service_id').value = firstServiceId;

        let total = 0, rowsHtml = '', serviceCount = 0;
        const room = getSelectedRoomCard();
        byId('sumRoom').textContent = room ? room.dataset.name : '—';
        if (room && +room.dataset.price > 0) {
            total += +room.dataset.price;
            rowsHtml += `<div class="row"><span>${escapeHtml(room.dataset.name)}</span><span>${formatPeso(+room.dataset.price)}</span></div>`;
        }

        for (let g = 1; g <= gCount; g++) {
            const picks = Array.from(guestPicks[g] || []);
            if (!picks.length) continue;
            rowsHtml += `<div class="row"><strong>Guest ${g}</strong><span></span></div>`;
            picks.forEach(key => {
                const c = document.querySelector(`.treatment-card[data-key="${key}"]`);
                if (!c) return;
                total += +c.dataset.price; serviceCount++;
                rowsHtml += `<div class="row sub"><span>${escapeHtml(c.dataset.name)}</span><span>${formatPeso(+c.dataset.price)}</span></div>`;
            });
        }

        const addons = getSelectedAddons();
        const totalAddonQty = addons.reduce((sum, a) => sum + a.qty, 0);
        if (addonTrigger) {
            byId('addonTriggerText').textContent = totalAddonQty ? `Select add-ons (${totalAddonQty} selected)` : 'Select add-ons (0 selected)';
            addonTrigger.classList.toggle('has-items', totalAddonQty > 0);
        }
        addons.forEach(a => {
            const lineTotal = a.price * a.qty;
            total += lineTotal;
            rowsHtml += `<div class="row"><span>+ ${escapeHtml(a.name)}${a.qty > 1 ? ` × ${a.qty}` : ''}</span><span>${formatPeso(lineTotal)}</span></div>`;
        });

        const totalItems = serviceCount + totalAddonQty;
        currentTotal = Math.round(total * 100) / 100;
        byId('breakdown').innerHTML = rowsHtml || 'No service selected yet';
        byId('sumGuests').textContent = room ? `${gCount} guest${gCount > 1 ? 's' : ''}` : '—';
        byId('summaryCount').textContent = totalItems ? `${totalItems} item(s) · ${gCount} guest(s)` : 'No service yet';
        byId('totalPrice').textContent = formatPeso(currentTotal);
        byId('summaryHeadTotal').textContent = formatPeso(currentTotal);
        if (serviceCount > 0) byId('serviceError').textContent = '';

        updatePayment();
    }

    /* ---------- Calendar & Time Slots ---------- */
    const pad2 = n => String(n).padStart(2, '0');
    const todayISO = () => { const d = new Date(); return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`; };
    const formatTime = t => { const [h, m] = normTime(t).split(':').map(Number); return `${(h % 12) || 12}:${pad2(m)} ${h >= 12 ? 'PM' : 'AM'}`; };
    const MONTHS = Array.from({ length: 12 }, (_, m) => new Date(2000, m, 1).toLocaleDateString('en-US', { month: 'long' }));
    const visMonth = new Date(); visMonth.setDate(1);
    const calMonth = byId('calendarMonth'), calYear = byId('calendarYear'), calDays = byId('calendarDays');

    calMonth.innerHTML = MONTHS.map((n, i) => `<option value="${i}">${n}</option>`).join('');
    for (let y = new Date().getFullYear(); y <= new Date().getFullYear() + config.maxYearsAhead; y++) calYear.innerHTML += `<option value="${y}">${y}</option>`;

    function renderCalendar() {
        const y = visMonth.getFullYear(), m = visMonth.getMonth(), first = new Date(y, m, 1).getDay(), days = new Date(y, m + 1, 0).getDate();
        const today = todayISO(), sel = dateInput.value;
        calYear.value = y; calMonth.value = m;
        let html = '<span></span>'.repeat(first);
        for (let d = 1; d <= days; d++) {
            const iso = `${y}-${pad2(m + 1)}-${pad2(d)}`, dis = iso < today || new Date(y, m, d).getDay() === config.closedWeekday;
            html += `<button type="button" class="calendar-day ${iso === sel ? 'is-selected' : ''}" data-date="${iso}" ${dis ? 'disabled' : ''}>${d}</button>`;
        }
        calDays.innerHTML = html;
    }

    let slotRequestId = 0;
    async function loadSlotsForDate(isoDate, preserve = '') {
        const reqId = ++slotRequestId;
        const roomVal = document.querySelector('.room-card input:checked')?.value || '0';
        let booked = [], failed = false;
        try {
            const res = await fetch(`${config.slotsUrl}${encodeURIComponent(isoDate)}&room_id=${encodeURIComponent(roomVal)}&_=${Date.now()}`, { cache: 'no-store' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (!data || data.ok !== true || !Array.isArray(data.booked)) throw new Error('Unexpected response');
            booked = data.booked.map(normTime);
        } catch (e) {
            failed = true;
            console.error('Could not load time slot availability:', e);
        }

        // A newer request started while this one was loading: ignore this older answer.
        if (reqId !== slotRequestId) return { booked, failed, stale: true };

        const hint = byId('timeHint');
        hint.textContent = '';
        if (failed) {
            hint.innerHTML = 'We could not check availability right now. <button type="button" id="retrySlots" style="background:none;border:0;padding:0;font:inherit;color:inherit;text-decoration:underline;cursor:pointer;">Try again</button>';
            byId('retrySlots').addEventListener('click', () => loadSlotsForDate(isoDate, preserve));
        }

        byId('timeGrid').innerHTML = config.timeSlots.map(raw => {
            const t = normTime(raw);
            let state = failed ? 'unknown' : (booked.includes(t) ? 'booked' : 'available');
            if (state === 'available' && isoDate === todayISO()) {
                const [hh, mm] = t.split(':').map(Number), now = new Date();
                if (hh * 60 + mm <= now.getHours() * 60 + now.getMinutes()) state = 'passed';
            }
            const chk = (state === 'available' && preserve && normTime(preserve) === t) ? 'checked' : '';
            const label = state === 'unknown' ? 'unavailable' : state;
            return `<label class="time-slot is-${state}"><input type="radio" name="appointment_time" value="${t}" ${state !== 'available' ? 'disabled' : ''} ${chk}><span>${formatTime(t)}${state !== 'available' ? `<em>${label}</em>` : ''}</span></label>`;
        }).join('');

        if (!failed && preserve && booked.includes(normTime(preserve))) {
            byId('timeError').textContent = 'That time slot is no longer available. Please select another.';
        }
        const active = document.querySelector('input[name="appointment_time"]:checked');
        byId('sumTime').textContent = active ? formatTime(active.value) : '—';
        return { booked, failed, stale: false };
    }

    calDays.addEventListener('click', async e => {
        const btn = e.target.closest('.calendar-day'); if (!btn || btn.disabled) return;
        dateInput.value = btn.dataset.date; byId('dateError').textContent = ''; byId('timeError').textContent = '';
        renderCalendar();
        const label = new Date(dateInput.value + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' });
        byId('sumDate').textContent = label; byId('selectedDateLabel').textContent = label;
        await loadSlotsForDate(dateInput.value);
    });

    byId('calendarPrev').addEventListener('click', () => { visMonth.setMonth(visMonth.getMonth() - 1); renderCalendar(); });
    byId('calendarNext').addEventListener('click', () => { visMonth.setMonth(visMonth.getMonth() + 1); renderCalendar(); });
    calMonth.addEventListener('change', () => { visMonth.setMonth(+calMonth.value); renderCalendar(); });
    calYear.addEventListener('change', () => { visMonth.setFullYear(+calYear.value); renderCalendar(); });
    byId('timeGrid').addEventListener('change', () => {
        const t = document.querySelector('input[name="appointment_time"]:checked');
        byId('sumTime').textContent = t ? formatTime(t.value) : '—'; byId('timeError').textContent = '';
    });

    /* ---------- Payment (Pay at Store vs Downpayment / Full Payment) ---------- */
    const payTrigger = byId('payTrigger'), payOptions = byId('payOptions');
    payTrigger.addEventListener('click', () => { payOptions.hidden = !payOptions.hidden; });
    payOptions.querySelectorAll('.pay-option').forEach(opt => opt.addEventListener('click', () => {
        paymentMethodInput.value = opt.dataset.value;
        byId('payTriggerText').textContent = opt.dataset.label;
        payTrigger.classList.add('has-value'); payOptions.hidden = true; byId('methodError').textContent = '';
        updatePayment();
    }));

    document.querySelectorAll('input[name="pay_plan_radio"]').forEach(r => r.addEventListener('change', () => {
        paymentPlanInput.value = r.value;
        byId('modeDownpaymentPill').classList.toggle('selected', r.value === 'downpayment');
        byId('modeFullPill').classList.toggle('selected', r.value === 'full');
        updatePayment();
    }));

    document.querySelectorAll('.prov-row input').forEach(r => r.addEventListener('change', updatePayment));
    document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', () => {
        navigator.clipboard?.writeText(b.dataset.copy); b.textContent = 'Copied'; setTimeout(() => b.textContent = 'Copy', 1500);
    }));

    function updatePayment() {
        const m = paymentMethodInput.value;
        const plan = paymentPlanInput.value || 'downpayment';
        const online = ['ewallet', 'bank'].includes(m);
        const rate = !online ? 0 : (plan === 'full' ? 1.0 : (config.downPaymentRates[m] || 0.30));
        const due = Math.round(currentTotal * rate * 100) / 100;
        const bal = Math.max(currentTotal - due, 0);
        const prov = Array.from(document.querySelectorAll('.prov-row input')).find(r => r.checked && r.dataset.method === m);

        byId('payModeGroup').hidden = !online;
        byId('payNowBox').hidden = !online;
        byId('payAmountsGrid').classList.toggle('is-single', !online);

        if (online) {
            byId('payNowBox').querySelector('span').textContent = plan === 'full' ? 'Full Payment (100%)' : '30% Reservation Fee';
            byId('payStoreLabel').textContent = 'Remaining balance at store';
        } else {
            byId('payStoreLabel').textContent = 'Pay at the store';
        }

        document.querySelectorAll('.js-due').forEach(el => el.textContent = formatPeso(due));
        document.querySelectorAll('.js-balance').forEach(el => el.textContent = formatPeso(bal));
        byId('sumDue').textContent = formatPeso(due);
        byId('sumBalance').textContent = formatPeso(bal);
        byId('amount_due').value = due.toFixed(2);
        paymentProviderInput.value = prov ? prov.value : '';

        const methodLabel = m ? payOptions.querySelector(`[data-value="${m}"]`).dataset.label : '';
        const planLabel = online ? (plan === 'full' ? 'Full Payment' : 'Downpayment') : '';
        byId('sumPayment').textContent = m ? [methodLabel, planLabel, prov ? prov.dataset.name : ''].filter(Boolean).join(' · ') : '—';

        byId('payNote').textContent = m === 'cash'
            ? `Pay ${formatPeso(bal)} upon arrival at the spa.`
            : (online ? (plan === 'full' ? `Send full amount of ${formatPeso(due)} and upload your receipt.` : `Send ${formatPeso(due)} (30% reservation fee) now; remaining ${formatPeso(bal)} payable at the store.`) : '');

        byId('payBox').hidden = !m;
        document.querySelectorAll('.pay-detail').forEach(d => d.hidden = d.dataset.method !== m);
        document.querySelectorAll('.prov-row').forEach(r => r.classList.toggle('selected', r.querySelector('input').checked));
        document.querySelectorAll('.prov-panel').forEach(p => p.hidden = !(prov && p.dataset.method === m && p.dataset.provider === prov.value));

        byId('payFields').hidden = !online; referenceInput.disabled = !online; proofInput.disabled = !online;
    }

    proofInput.addEventListener('change', () => {
        const f = proofInput.files[0];
        byId('fileDrop').classList.toggle('has-file', !!f);
        byId('fileTitle').textContent = f ? f.name : 'Upload proof of payment';
        if (f) byId('proofError').textContent = '';
    });

    referenceInput.addEventListener('input', () => {
        if (referenceInput.value.trim().length >= 4) byId('refError').textContent = '';
    });

    /* ---------- Tabs, Sliders & Summary Toggle ---------- */
    document.querySelectorAll('.choice-tab').forEach(tab => tab.addEventListener('click', () => {
        document.querySelectorAll('.choice-tab').forEach(t => t.classList.toggle('active', t === tab));
        document.querySelectorAll('.choice-panel').forEach(p => p.classList.toggle('active', p.id === tab.dataset.panel));
        refreshSliders();
    }));

    function createSlider(list) {
        const wrap = document.createElement('div'); wrap.className = 'slider';
        list.parentNode.insertBefore(wrap, list); wrap.appendChild(list);
        const prev = document.createElement('button'); prev.type = 'button'; prev.className = 'slider-btn prev'; prev.innerHTML = '&#8249;';
        const next = document.createElement('button'); next.type = 'button'; next.className = 'slider-btn next'; next.innerHTML = '&#8250;';
        wrap.append(prev, next);
        const upd = () => { prev.disabled = list.scrollLeft <= 4; next.disabled = list.scrollLeft + list.clientWidth >= list.scrollWidth - 4; };
        prev.addEventListener('click', () => list.scrollBy({ left: -280, behavior: 'smooth' }));
        next.addEventListener('click', () => list.scrollBy({ left: 280, behavior: 'smooth' }));
        list.addEventListener('scroll', upd, { passive: true });
        list.updateSlider = upd; upd();
    }
    const refreshSliders = () => document.querySelectorAll('.choice-list').forEach(l => l.updateSlider?.());

    byId('summaryToggle').addEventListener('click', () => {
        const body = byId('summaryBody'), open = body.hidden;
        body.hidden = !open; byId('summaryCard').classList.toggle('is-open', open);
    });

    /* ---------- Real-time Red Input Validation & Enter-to-Next ---------- */
    function validateName() {
        const val = nameInput.value.trim(), words = val.split(/\s+/).filter(Boolean);
        let msg = '';
        if (!val) msg = 'Please enter your full name.';
        else if (/\d/.test(val)) msg = 'Full name cannot contain numbers.';
        else if (!/^[a-zA-ZÀ-ÿ\s.'-]+$/.test(val) || words.length < 2) msg = 'Please enter your first and last name.';
        byId('nameError').textContent = msg;
        nameInput.classList.toggle('invalid-input', !!msg); nameInput.classList.toggle('valid-input', !msg);
        return !msg;
    }
    function validateEmail() {
        const val = emailInput.value.trim();
        let msg = '';
        if (!val) msg = 'Please enter your email address.';
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val)) msg = 'Invalid email format (e.g. name@email.com).';
        byId('emailError').textContent = msg;
        emailInput.classList.toggle('invalid-input', !!msg); emailInput.classList.toggle('valid-input', !msg);
        return !msg;
    }
    function validatePhone() {
        phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 12);
        const val = phoneInput.value.trim();
        let msg = '';
        if (!val) msg = 'Please enter your phone number.';
        else if (!/^(09\d{9}|639\d{9})$/.test(val)) msg = 'Enter a valid PH mobile number (09XXXXXXXXX).';
        byId('phoneError').textContent = msg;
        phoneInput.classList.toggle('invalid-input', !!msg); phoneInput.classList.toggle('valid-input', !msg);
        return !msg;
    }
    nameInput.addEventListener('input', validateName); nameInput.addEventListener('blur', validateName);
    emailInput.addEventListener('input', validateEmail); emailInput.addEventListener('blur', validateEmail);
    phoneInput.addEventListener('input', validatePhone); phoneInput.addEventListener('blur', validatePhone);

    const detailFlow = [
        { el: nameInput, validate: validateName, next: emailInput },
        { el: emailInput, validate: validateEmail, next: phoneInput },
        { el: phoneInput, validate: validatePhone, next: byId('notes') }
    ];
    byId('detailsGrid').addEventListener('keydown', e => {
        if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA') return;
        e.preventDefault();
        const step = detailFlow.find(f => f.el === e.target);
        if (!step) return;
        if (step.validate()) step.next.focus();
    });

    /* ---------- Step Navigation & Clickable Stepper ---------- */
    async function isStepValid(step) {
        if (step === 1) {
            if (!getSelectedRoomCard()) { byId('roomError').textContent = 'Please select an accommodation type first.'; return false; }
            let count = 0;
            for (let g = 1; g <= (+guestsInput.value || 1); g++) count += (guestPicks[g]?.size || 0);
            if (!count) { byId('serviceError').textContent = 'Please select at least one spa service or package.'; return false; }
            return true;
        }
        if (step === 2) {
            if (!dateInput.value) { byId('dateError').textContent = 'Please choose a date.'; return false; }
            const t = document.querySelector('input[name="appointment_time"]:checked');
            if (!t) { byId('timeError').textContent = 'Please select an available time slot.'; return false; }
            let r = await loadSlotsForDate(dateInput.value, t.value);
            if (r.stale) r = await loadSlotsForDate(dateInput.value, t.value);
            if (r.failed) { byId('timeError').textContent = 'We could not check availability. Please try again.'; return false; }
            if (r.booked.includes(normTime(t.value))) { byId('timeError').textContent = 'That slot was just booked. Please select another.'; return false; }
            return true;
        }
        if (step === 3) return validateName() && validateEmail() && validatePhone();
        if (step === 4) {
            const m = paymentMethodInput.value;
            if (!m) { byId('methodError').textContent = 'Please select a payment method.'; return false; }
            if (['ewallet', 'bank'].includes(m)) {
                const prov = Array.from(document.querySelectorAll('.prov-row input')).find(r => r.checked && r.dataset.method === m);
                if (!prov) { byId('provError_' + m).textContent = 'Please choose a provider.'; return false; }
                byId('provError_' + m).textContent = '';
                if (!/^[A-Za-z0-9-]{4,25}$/.test(referenceInput.value.trim())) { byId('refError').textContent = 'Enter a valid reference number (4–25 characters).'; return false; }
                if (!proofInput.files.length) { byId('proofError').textContent = 'Please upload proof of payment.'; return false; }
            }
            return true;
        }
        return true;
    }

    function goToStep(step) {
        currentStep = step;
        document.querySelectorAll('.step-panel').forEach(p => p.classList.toggle('active', +p.dataset.step === step));
        document.querySelectorAll('#stepper li').forEach(li => { li.classList.toggle('active', +li.dataset.step === step); li.classList.toggle('done', +li.dataset.step < step); });
        byId('stepNav').classList.toggle('is-compact', step >= 3);
        byId('backBtn').style.display = step > 1 ? '' : 'none';
        byId('nextBtn').style.display = step < 5 ? '' : 'none';
        byId('submitBtn').style.display = step === 5 ? '' : 'none';

        if (step === 5) {
            byId('termsError').textContent = '';
            byId('termsError').hidden = true;

            const gCount = +guestsInput.value || 1, lines = [];
            for (let g = 1; g <= gCount; g++) {
                const names = Array.from(guestPicks[g] || []).map(k => document.querySelector(`.treatment-card[data-key="${k}"]`)?.dataset.name).filter(Boolean);
                if (names.length) lines.push(gCount > 1 ? `Guest ${g}: ${escapeHtml(names.join(', '))}` : escapeHtml(names.join(', ')));
            }
            const addons = getSelectedAddons().map(a => `+ ${escapeHtml(a.name)}${a.qty > 1 ? ` × ${a.qty}` : ''} (${formatPeso(a.price * a.qty)})`);
            const isCash = paymentMethodInput.value === 'cash';
            const rows = [
                ['Name', escapeHtml(nameInput.value.trim())],
                ['Email', escapeHtml(emailInput.value.trim())],
                ['Phone', escapeHtml(phoneInput.value.trim())],
                ['Accommodation', escapeHtml(byId('sumRoom').textContent)],
                ['Guests', escapeHtml(byId('sumGuests').textContent)],
                ['Spa Services', lines.join('<br>') || '—'],
            ];
            if (addons.length) rows.push(['Add-ons', addons.join('<br>')]);
            rows.push(
                ['Date & Time', escapeHtml(`${byId('sumDate').textContent} · ${byId('sumTime').textContent}`)],
                ['Payment', escapeHtml(byId('sumPayment').textContent)],
                ['Total', escapeHtml(byId('totalPrice').textContent)]
            );
            if (!isCash) rows.push(['Pay Now', escapeHtml(byId('sumDue').textContent)]);
            rows.push(['Pay at Store', escapeHtml(byId('sumBalance').textContent)]);
            byId('reviewBox').innerHTML = rows.map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('');
        }
        byId('stepper').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    document.querySelectorAll('#stepper li').forEach(li => {
        li.addEventListener('click', async () => {
            const target = +li.dataset.step;
            if (target === currentStep) return;
            if (target < currentStep) {
                goToStep(target);
                return;
            }
            for (let s = currentStep; s < target; s++) {
                if (!(await isStepValid(s))) return;
            }
            goToStep(target);
        });
    });

    byId('nextBtn').addEventListener('click', async () => { if (await isStepValid(currentStep)) goToStep(currentStep + 1); });
    byId('backBtn').addEventListener('click', () => goToStep(currentStep - 1));

    byId('terms').addEventListener('change', () => {
        if (byId('terms').checked) {
            byId('termsError').textContent = '';
            byId('termsError').hidden = true;
        }
    });

    byId('bookingForm').addEventListener('submit', e => {
        if (!byId('terms').checked) {
            e.preventDefault();
            byId('termsError').textContent = 'Please agree to the booking policies above.';
            byId('termsError').hidden = false;
        }
    });

    /* ---------- Restore the form after save_booking.php sends the customer back with an error ---------- */
    async function restoreAfterServerError() {
        const old = config.oldForm;
        if (!old || Array.isArray(old) || !Object.keys(old).length) return;
        const val = k => (old[k] === undefined || old[k] === null) ? '' : String(old[k]);

        // 1) Accommodation + number of guests
        const roomRadio = Array.from(document.querySelectorAll('.room-card input[type="radio"]')).find(r => r.value === val('room_id'));
        if (roomRadio) {
            roomRadio.checked = true;
            handleAccommodationChange(+val('guests') || null, false);
        }

        // 2) Treatments / packages for each guest
        Object.keys(guestPicks).forEach(k => delete guestPicks[k]);
        activeCards.clear();
        const gCount = +guestsInput.value || 1;
        ensureGuestSets(gCount);
        const picks = (old.guest_items && typeof old.guest_items === 'object') ? old.guest_items : {};
        Object.entries(picks).forEach(([g, keys]) => {
            const n = +g;
            if (!(n >= 1) || n > gCount || !Array.isArray(keys)) return;
            keys.forEach(k => {
                const card = document.querySelector(`.treatment-card[data-key="${CSS.escape(String(k))}"]`);
                if (!card || card.hidden) return;
                guestPicks[n].add(String(k));
                activeCards.add(String(k));
            });
        });

        // 3) Add-ons
        const addons = (old.addon_qty && typeof old.addon_qty === 'object') ? old.addon_qty : {};
        document.querySelectorAll('#addonMenu .addon-item').forEach(item => {
            const input = item.querySelector('.js-addon-qty-input');
            if (!input) return;
            const qty = Math.max(0, Math.min(20, parseInt(addons[item.dataset.id], 10) || 0));
            input.value = String(qty);
            item.querySelector('.js-addon-qty-display').textContent = String(qty);
            item.querySelector('.js-addon-minus').disabled = qty === 0;
            item.classList.toggle('is-selected', qty > 0);
        });

        // 4) Your details
        nameInput.value = val('customer_name');
        emailInput.value = val('email');
        phoneInput.value = val('phone');
        byId('notes').value = val('notes');

        // 5) Payment (the proof-of-payment file cannot be restored by the browser, so it must be re-uploaded)
        const method = val('payment_method');
        const opt = method ? payOptions.querySelector(`.pay-option[data-value="${method}"]`) : null;
        if (opt) {
            opt.click();
            const planRadio = document.querySelector(`input[name="pay_plan_radio"][value="${val('payment_plan')}"]`);
            if (planRadio) { planRadio.checked = true; planRadio.dispatchEvent(new Event('change')); }
            const provRadio = Array.from(document.querySelectorAll('.prov-row input')).find(r => r.dataset.method === method && r.value === val('payment_provider'));
            if (provRadio) { provRadio.checked = true; provRadio.dispatchEvent(new Event('change')); }
            referenceInput.value = val('reference_no');
        }
        updateSummary();

        // 6) Date & time. If the server rejected the date/time itself, the customer must pick it again.
        const dateRejected = config.errorField === 'appointment_date';
        const timeRejected = config.errorField === 'appointment_time';
        const date = val('appointment_date');
        if (!dateRejected && /^\d{4}-\d{2}-\d{2}$/.test(date) && date >= todayISO()) {
            dateInput.value = date;
            const [y, mo] = date.split('-').map(Number);
            visMonth.setFullYear(y); visMonth.setMonth(mo - 1);
            renderCalendar();
            const label = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'long', day: 'numeric', year: 'numeric' });
            byId('sumDate').textContent = label; byId('selectedDateLabel').textContent = label;
            await loadSlotsForDate(date, timeRejected ? '' : val('appointment_time'));
        }

        // 7) Jump back to the step that had the problem
        goToStep(Math.min(5, Math.max(1, +config.initialStep || 1)));
        if (timeRejected) byId('timeError').textContent = config.errorMessage || '';
        if (dateRejected) byId('dateError').textContent = config.errorMessage || '';
    }

    renderCalendar();
    document.querySelectorAll('.choice-list').forEach(createSlider);
    updateSummary();
    restoreAfterServerError().catch(err => console.error('Could not restore the booking form:', err));
})();
</script>
</body>
</html>
