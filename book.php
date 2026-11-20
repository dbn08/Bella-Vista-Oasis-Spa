<?php
require_once 'config.php';

const HOLD_HOURS = 24;

function bv_release_unpaid(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE appointments SET status = 'Cancelled'
                    WHERE status = 'Pending' AND payment_method = 'cash' AND payment_status = 'unpaid'
                      AND created_at < (NOW() - INTERVAL " . (int)HOLD_HOURS . " HOUR)");
    } catch (Throwable $e) {}
}

if (isset($_GET['slots'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $d = (string)$_GET['slots'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        echo json_encode(['booked' => []]);
        exit;
    }
    bv_release_unpaid($pdo);
    try {
        $st = $pdo->prepare("SELECT DATE_FORMAT(appointment_time, '%H:%i') FROM appointments WHERE appointment_date = ? AND status <> 'Cancelled'");
        $st->execute([$d]);
        echo json_encode(['booked' => $st->fetchAll(PDO::FETCH_COLUMN)]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['booked' => [], 'error' => 'query failed']);
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
$paymentMethods = [
    'cash' => [
        'label' => 'Pay at the store', 'icon' => '₱',
        'hint' => 'Pay full amount in cash at the spa on your visit',
        'note' => 'No upfront down payment required. Pay the full amount in cash at the store when you arrive for your appointment.',
    ],
    'ewallet' => [
        'label' => 'E-wallet', 'icon' => '◎',
        'hint' => 'GCash or Maya · ' . (int)round($downPayment['ewallet'] * 100) . '% reservation fee',
        'providers' => [
            'gcash' => ['name' => 'GCash', 'initial' => 'G', 'details' => [
                ['Account name', 'Bella Vista Oasis Spa', false],
                ['GCash number', '0917 000 0000', true],
            ]],
            'maya' => ['name' => 'Maya', 'initial' => 'M', 'details' => [
                ['Account name', 'Bella Vista Oasis Spa', false],
                ['Maya number', '0918 000 0000', true],
            ]],
        ],
    ],
    'bank' => [
        'label' => 'Online banking', 'icon' => '⌂',
        'hint' => 'BDO, BPI or UnionBank · ' . (int)round($downPayment['bank'] * 100) . '% reservation fee',
        'providers' => [
            'bdo' => ['name' => 'BDO', 'initial' => 'B', 'details' => [
                ['Bank', 'BDO Unibank', false],
                ['Account name', 'Bella Vista Oasis Spa', false],
                ['Account number', '0000 0000 0000', true],
            ]],
            'bpi' => ['name' => 'BPI', 'initial' => 'B', 'details' => [
                ['Bank', 'Bank of the Philippine Islands', false],
                ['Account name', 'Bella Vista Oasis Spa', false],
                ['Account number', '0000 0000 00', true],
            ]],
            'unionbank' => ['name' => 'UnionBank', 'initial' => 'U', 'details' => [
                ['Bank', 'UnionBank of the Philippines', false],
                ['Account name', 'Bella Vista Oasis Spa', false],
                ['Account number', '0000 0000 0000', true],
            ]],
        ],
    ],
];

$policies = [
    ['Operating days & hours', 'Open Tuesday to Sunday, 9:00 AM to 5:30 PM. The spa is closed on Mondays.'],
    ['Confirmation', 'Your request stays pending until confirmed by our team. Use your booking code on the Track my booking page to see its status anytime.'],
    ['Payment options', 'You may choose Pay at the store (full amount paid upon arrival) or pay a ' . (int)round($downPayment['ewallet'] * 100) . '% reservation fee via e-wallet or online banking.'],
    ['Cancellation & rescheduling', 'Cancel or reschedule at least 24 hours before your appointment. Later cancellations and no-shows forfeit any reservation fee paid.'],
    ['Grace period', 'We hold your slot for 15 minutes after your appointment time. After that, the treatment may be shortened or the slot released.'],
    ['Guests & pricing', 'Up to 6 guests per booking. Each guest is billed based on their selected treatments, packages, room, and optional add-ons.'],
    ['Health & safety', 'Please tell us about allergies, injuries, pregnancy or medical conditions in the notes so we can care for you properly.'],
];

// Fetch Rooms
try {
    $rooms = $pdo->query('SELECT * FROM rooms WHERE active = 1 ORDER BY min_guests ASC, price ASC')->fetchAll();
} catch (Throwable $e) {
    $rooms = [];
}
if (!$rooms) {
    $rooms = [
        ['id' => 1, 'name' => 'Single Room', 'description' => 'Private tranquil suite designed for solo relaxation and mindfulness.', 'min_guests' => 1, 'max_guests' => 1, 'duration_minutes' => 60, 'price' => 0.00, 'image' => ''],
        ['id' => 2, 'name' => 'Couples Suite', 'description' => 'Intimate dual-bed sanctuary with warm ambient lighting for two.', 'min_guests' => 2, 'max_guests' => 2, 'duration_minutes' => 90, 'price' => 500.00, 'image' => ''],
        ['id' => 3, 'name' => 'Family Lounge', 'description' => 'Spacious private wellness lounge accommodating 3 to 6 guests together.', 'min_guests' => 3, 'max_guests' => 6, 'duration_minutes' => 120, 'price' => 1200.00, 'image' => ''],
    ];
}

// Fetch Services
try {
    $services = $pdo->query('SELECT id, name, duration_minutes, price, description, image FROM services WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    $services = $pdo->query('SELECT id, name, duration_minutes, price FROM services WHERE active = 1 ORDER BY name')->fetchAll();
}

// Fetch Packages
try {
    $packages = $pdo->query('SELECT * FROM packages WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    $packages = [];
}

// Fetch Add-ons
try {
    $addons = $pdo->query('SELECT * FROM addons WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    $addons = [];
}

function item_image(array $row, string $kind): string
{
    if (!empty($row['image'])) {
        return preg_match('#^https?://#i', $row['image']) ? $row['image'] : ltrim($row['image'], '/');
    }
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $path = 'assets/images/' . $kind . '/' . (int)$row['id'] . '.' . $ext;
        if (is_file(__DIR__ . '/' . $path)) {
            return $path;
        }
    }
    return '';
}

$selectedService = isset($_GET['service']) ? (int)$_GET['service'] : 0;
$error      = $_SESSION['booking_error'] ?? '';
$errorStep  = (int)($_SESSION['booking_error_step'] ?? 1);
$errorField = (string)($_SESSION['booking_error_field'] ?? '');
$oldForm    = $_SESSION['booking_old'] ?? [];
unset($_SESSION['booking_error'], $_SESSION['booking_error_step'], $_SESSION['booking_error_field'], $_SESSION['booking_old']);

$bookingConfig = [
    'slotsUrl'         => 'book.php?slots=',
    'timeSlots'        => ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'],
    'closedWeekday'    => 1,
    'maxYearsAhead'    => 2,
    'extraGuestRate'   => 1.00,
    'downPaymentRates' => $downPayment,
    'holdHours'        => HOLD_HOURS,
    'initialStep'      => $error ? $errorStep : 1,
    'errorField'       => $error ? $errorField : '',
    'oldForm'          => $oldForm,
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
    --bv-ink: #3f2d20;
    --bv-brown: #5a4030;
    --bv-brown-dark: #46301f;
    --bv-muted: #6f5f52;
    --bv-muted-soft: #8a7a6b;
    --bv-green: #648568;
    --bv-green-soft: #f1f5ef;
    --bv-red: #b83232;
    --bv-red-soft: #fdf1f1;
    --bv-gold: #b58a52;
    --bv-card: #fffdf9;
    --bv-cream: #fbf6ee;
    --bv-sand: #f1e8dc;
    --bv-sand-soft: #f6f0e7;
    --bv-line: #e4d9cb;
    --bv-line-strong: #d9cfc2;
    --bv-font-display: "Cormorant Garamond", Georgia, serif;
    --bv-font-body: "Jost", system-ui, -apple-system, "Segoe UI", sans-serif;
    --bv-radius-card: 20px;
    --bv-radius-pill: 999px;
    --bv-btn-height: 42px;
}

.site-header { min-height: 0 !important; padding-top: 10px !important; padding-bottom: 10px !important; }
.site-header .brand-mark { width: 64px !important; height: 44px !important; }
.booking-layout { display: grid !important; grid-template-columns: 300px minmax(0, 1fr) !important; }
.booking-aside { width: auto !important; max-width: none !important; min-width: 0 !important; padding: 28px 28px !important; }
.booking-aside h1 { font-size: clamp(30px, 2.3vw, 40px) !important; line-height: 1.12 !important; }
.booking-aside > p { font-size: 14px !important; line-height: 1.65 !important; }
.booking-form-wrap { max-width: none !important; padding: 24px clamp(24px, 3vw, 48px) 120px !important; }

.booking-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 28px; margin-bottom: 22px; }
.booking-head h2 { margin: 0; font-size: 34px; line-height: 1.1; }

.step-panel { display: none; }
.step-panel.active { display: block; }
.step-panel .form-grid { gap: 20px; }
.step-head { margin-bottom: 16px; }
.step-head h3 { margin: 0; font-size: 22px; }
.sub-section-title { font-family: var(--bv-font-display); font-size: 22px; font-weight: 600; margin: 22px 0 10px; color: var(--bv-ink); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }

/* Compact container for Steps 3, 4, and 5 */
.compact-step-wrap { max-width: 720px; }

@media (max-width: 900px) { .booking-layout { grid-template-columns: 1fr !important; } }

.field-error { display: block; margin-top: 5px; color: var(--bv-red) !important; font-size: 12px !important; }
.form-grid input.invalid-input,
.invalid-input {
    border-color: var(--bv-red) !important;
    background: var(--bv-red-soft) !important;
    box-shadow: 0 0 0 3px rgba(184, 50, 50, .14) !important;
}
.form-grid input.valid-input,
.valid-input {
    border-color: var(--bv-green) !important;
    box-shadow: 0 0 0 3px rgba(100, 133, 104, .12) !important;
}

/* Stepper & Buttons */
.stepper { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 0; margin: 0; padding: 10px 20px; list-style: none; background: var(--bv-sand); border-radius: var(--bv-radius-pill); }
.stepper li { display: flex; align-items: center; gap: 9px; font-size: 13.5px; color: var(--bv-muted-soft); cursor: default; }
.stepper li::before { content: ""; flex: 0 0 9px; width: 9px; height: 9px; border: 1.5px solid #b8aa9a; border-radius: 50%; background: transparent; transition: background .2s, border-color .2s, box-shadow .2s; }
.stepper li:not(:last-child)::after { content: "›"; margin: 0 12px; color: #b8aa9a; font-size: 16px; }
.stepper li.active { color: var(--bv-ink); font-weight: 600; }
.stepper li.active::before { background: var(--sage-700, #4f6b55); border-color: var(--sage-700, #4f6b55); box-shadow: 0 0 0 3px rgba(79, 107, 85, .18); }
.stepper li.done { color: var(--bv-green); cursor: pointer; }
.stepper li.done::before { background: var(--bv-green); border-color: var(--bv-green); }

.bv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 150px; max-width: 100%; height: var(--bv-btn-height); padding: 0 14px; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); font: inherit; font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; white-space: nowrap; cursor: pointer; transition: background .15s, color .15s; }
.bv-btn--primary { background: var(--bv-brown); color: #fff; }
.bv-btn--primary:hover { background: var(--bv-brown-dark); }
.bv-btn--outline { background: transparent; color: var(--bv-brown); }
.bv-btn--outline:hover { background: var(--bv-sand); }
.step-nav { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
.step-nav.is-compact { max-width: 720px; }
.step-nav #backBtn { margin-right: auto; }

/* Tabs & Slider */
.choice-tabs { display: flex; gap: 8px; margin: 0 0 18px; border-bottom: 1px solid var(--bv-line); }
.choice-tab { margin-bottom: -1px; padding: 10px 20px; border: 0; border-bottom: 2px solid transparent; background: none; color: var(--bv-muted); font: inherit; font-size: 14px; cursor: pointer; }
.choice-tab.active { border-bottom-color: var(--bv-brown); color: var(--bv-brown); font-weight: 600; }
.choice-tab .count { display: none; margin-left: 6px; padding: 1px 7px; border-radius: var(--bv-radius-pill); background: var(--bv-green); color: #fff; font-size: 12px; }
.choice-tab .count.show { display: inline-block; }
.choice-panel { display: none; }
.choice-panel.active { display: block; }

.slider { position: relative; }
.choice-list { display: flex; gap: 22px; margin: 0; padding: 14px 6px 22px; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: none; }
.choice-list::-webkit-scrollbar { display: none; }
.slider-btn { position: absolute; top: 44%; z-index: 5; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; padding: 0; border: 1px solid var(--bv-line-strong); border-radius: 50%; background: var(--bv-card); color: var(--bv-brown); font-size: 22px; cursor: pointer; box-shadow: 0 6px 16px rgba(63, 45, 32, .14); }
.slider-btn:hover { background: var(--bv-brown); color: #fff; }
.slider-btn.prev { left: -14px; }
.slider-btn.next { right: -14px; }
.slider-btn:disabled { opacity: 0; pointer-events: none; }

/* Full-Arch Framed Cards (Matching Homepage Style) */
.choice-card { position: relative; display: flex; flex: 0 0 clamp(255px, 31%, 305px); flex-direction: column; justify-content: flex-end; min-height: 430px; padding: 7px; border: 1px solid rgba(90, 64, 48, .55); border-radius: 220px 220px 18px 18px; background: transparent; font-weight: 400; cursor: pointer; scroll-snap-align: start; transition: transform .25s ease, box-shadow .25s ease, border-color .2s; }
.choice-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
.choice-card:hover:not(.is-disabled) { z-index: 2; transform: translateY(-4px); border-color: var(--bv-brown); box-shadow: 0 16px 32px rgba(45, 30, 18, .22); }
.choice-card.selected { border-color: var(--bv-green); box-shadow: 0 0 0 2px var(--bv-green), 0 14px 28px rgba(100, 133, 104, .22); }
.choice-card.is-disabled { opacity: .48; cursor: not-allowed; filter: grayscale(.3); }
.choice-card[hidden] { display: none !important; }

.choice-badge { position: absolute; top: 24px; left: 50%; transform: translateX(-50%); z-index: 2; padding: 4px 12px; border: 1px solid rgba(255, 255, 255, .25); border-radius: var(--bv-radius-pill); background: rgba(28, 17, 10, .72); backdrop-filter: blur(4px); color: #f7efe4; font-size: 10px; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; white-space: nowrap; }
.choice-thumb { position: absolute; inset: 7px; z-index: 0; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 213px 213px 12px 12px; background: linear-gradient(160deg, #6e543e, #362417); }
.choice-thumb::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(32, 20, 12, .04) 0%, rgba(32, 20, 12, .16) 36%, rgba(28, 17, 10, .85) 62%, rgba(22, 13, 8, .96) 100%); pointer-events: none; }
.choice-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform .5s ease; }
.choice-card:hover:not(.is-disabled) .choice-thumb img { transform: scale(1.06); }
.thumb-ph { position: relative; z-index: 1; margin-bottom: 140px; color: rgba(247, 239, 228, .45); font-size: 46px; }

.choice-body { position: relative; z-index: 1; display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: center; gap: 6px 10px; padding: 175px 18px 18px; }
.choice-facts { display: contents; }
.choice-facts .fact:not(.price) { grid-column: 1 / -1; order: 1; color: rgba(247, 239, 228, .78); font-size: 10.5px; font-weight: 500; letter-spacing: .14em; text-transform: uppercase; }
.choice-name { grid-column: 1 / -1; order: 2; color: #ffffff; font-family: var(--bv-font-display); font-size: 23px; font-weight: 600; line-height: 1.18; }
.choice-desc { grid-column: 1 / -1; order: 3; display: -webkit-box; min-height: 4.2em; margin-bottom: 6px; overflow: hidden; color: rgba(247, 239, 228, .84); font-size: 12.5px; line-height: 1.45; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
.choice-facts .fact.price { grid-column: 1; order: 4; color: #ffffff; font-family: var(--bv-font-display); font-size: 18px; font-weight: 600; white-space: nowrap; }

.choice-btn { grid-column: 2; justify-self: end; order: 5; display: inline-flex; align-items: center; justify-content: center; height: 36px; padding: 0 14px; border: 1px solid rgba(255, 255, 255, .48); border-radius: var(--bv-radius-pill); background: rgba(26, 16, 10, .45); backdrop-filter: blur(4px); color: #ffffff; font-size: 10.5px; font-weight: 600; letter-spacing: .09em; text-transform: uppercase; white-space: nowrap; cursor: pointer; transition: background .18s, border-color .18s, color .18s; }
.choice-btn .on { display: none; }
.choice-card:hover:not(.is-disabled) .choice-btn { border-color: #ffffff; background: rgba(255, 255, 255, .16); color: #ffffff; }
.choice-card.selected .choice-btn { border-color: var(--bv-green); background: var(--bv-green); color: #ffffff; }
.choice-card.selected .choice-btn .on { display: inline; }
.choice-card.selected .choice-btn .off { display: none; }

/* For Guest Dropdown inside each Treatment Card */
.guest-assign-wrap { grid-column: 1 / -1; order: 6; position: relative; margin-top: 6px; }
.guest-assign-wrap[hidden] { display: none !important; }
.guest-dd-trigger { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; padding: 7px 12px; border-radius: var(--bv-radius-pill); border: 1px solid rgba(255, 255, 255, .32); background: rgba(255, 255, 255, .94); color: var(--bv-ink); font: inherit; font-size: 12px; cursor: pointer; transition: border-color .15s; }
.guest-dd-trigger:hover, .guest-dd-trigger[aria-expanded="true"] { border-color: #ffffff; background: #ffffff; }
.guest-dd-trigger strong { color: var(--bv-green); font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.guest-dd-trigger strong.is-empty { color: var(--bv-muted); font-weight: 500; }
.guest-dd-caret { font-size: 11px; color: var(--bv-muted); transition: transform .2s; flex-shrink: 0; }
.guest-dd-trigger[aria-expanded="true"] .guest-dd-caret { transform: rotate(180deg); }
.guest-dd-menu { margin-top: 6px; padding: 6px; border-radius: 14px; background: #fff; border: 1px solid var(--bv-line); box-shadow: 0 8px 20px rgba(63,45,32,.22); max-height: 150px; overflow-y: auto; }
.guest-dd-menu[hidden] { display: none !important; }
.guest-dd-item { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; font-size: 12.5px; color: var(--bv-ink); cursor: pointer; user-select: none; }
.guest-dd-item:hover { background: var(--bv-sand-soft); }
.guest-dd-item input { accent-color: var(--bv-green); width: 14px; height: 14px; margin: 0; cursor: pointer; }
.guest-dd-item.is-all { border-bottom: 1px dashed var(--bv-line); margin-bottom: 4px; padding-bottom: 7px; font-weight: 600; color: var(--bv-brown); }

/* Add-ons Dropdown below Treatments */
.addon-dropdown-wrap { position: relative; margin-top: 22px; max-width: 520px; }
.addon-trigger { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; padding: 14px 20px; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); background: var(--bv-card); color: var(--bv-ink); font: inherit; font-size: 14.5px; cursor: pointer; transition: border-color .15s; }
.addon-trigger:hover, .addon-trigger[aria-expanded="true"] { border-color: var(--bv-brown); }
.addon-trigger .addon-caret { transition: transform .2s; }
.addon-trigger[aria-expanded="true"] .addon-caret { transform: rotate(180deg); }
.addon-menu { margin-top: 8px; background: var(--bv-card); border: 1px solid var(--bv-line); border-radius: 20px; padding: 8px; box-shadow: 0 10px 24px rgba(63,45,32,.10); max-height: 240px; overflow-y: auto; }
.addon-menu[hidden] { display: none; }
.addon-item { display: flex; align-items: flex-start; gap: 12px; padding: 10px 12px; border-radius: 12px; cursor: pointer; font-weight: 400; }
.addon-item:hover { background: var(--bv-sand-soft); }
.addon-item input { margin-top: 3px; accent-color: var(--bv-green); width: 16px; height: 16px; }
.addon-item-info { flex: 1; }
.addon-item-info strong { display: block; font-size: 14px; color: var(--bv-ink); }
.addon-item-info small { display: block; font-size: 12px; color: var(--bv-muted); line-height: 1.45; }
.addon-item-price { font-weight: 600; font-size: 13.5px; color: var(--bv-brown); white-space: nowrap; }

/* Calendar & Time Slots */
.datetime-layout { display: grid; grid-template-columns: minmax(300px, 360px) minmax(300px, 420px); gap: 20px; align-items: stretch; }
.datetime-card { display: flex; flex-direction: column; padding: 18px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }
.datetime-card-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; height: 40px; margin-bottom: 12px; }
.datetime-card-head h4 { margin: 0; color: var(--bv-ink); font-family: var(--bv-font-display); font-size: 22px; font-weight: 600; }
.calendar-nav { flex: 0 0 34px; width: 34px; height: 34px; padding: 0; border: 1px solid var(--bv-line); border-radius: 50%; background: #fff; color: var(--bv-brown); font-size: 18px; cursor: pointer; }
.calendar-nav:disabled { opacity: .3; cursor: not-allowed; }
.calendar-selects { display: flex; align-items: center; gap: 2px; }
.calendar-select { width: auto !important; height: 34px !important; margin: 0 !important; padding: 0 26px 0 10px !important; border: 1px solid transparent !important; border-radius: var(--bv-radius-pill) !important; background: var(--bv-card) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path d='M1 1l4 4 4-4' fill='none' stroke='%235a4030' stroke-width='1.5' stroke-linecap='round'/></svg>") no-repeat right 9px center !important; color: var(--bv-ink); font-family: var(--bv-font-display); font-size: 20px; font-weight: 600; cursor: pointer; appearance: none; }
.calendar-weekdays, .calendar-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.calendar-weekdays span { padding: 6px 0 8px; color: var(--bv-muted-soft); font-size: 12px; text-transform: uppercase; }
.calendar-days { grid-auto-rows: 38px; }
.calendar-day { position: relative; padding: 0; border: 0; border-radius: 50%; background: none; color: var(--bv-ink); font: inherit; font-size: 14px; cursor: pointer; }
.calendar-day:hover:not(:disabled):not(.is-selected) { background: var(--bv-sand); }
.calendar-day:disabled { color: #c3b8aa; cursor: not-allowed; }
.calendar-day.is-today::after { content: ""; position: absolute; bottom: 3px; left: 50%; width: 4px; height: 4px; margin-left: -2px; border-radius: 50%; background: var(--bv-gold); }
.calendar-day.is-selected { background: var(--bv-brown); color: #fff; }
.calendar-note { margin: auto 0 0; padding-top: 12px; color: var(--bv-muted-soft); font-size: 12px; text-align: center; }

.time-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.time-slot { position: relative; display: block; margin: 0; }
.time-slot input { position: absolute; opacity: 0; pointer-events: none; }
.time-slot > span { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 46px; border: 1px solid var(--bv-line-strong); border-radius: 10px; background: var(--bv-card); color: var(--bv-ink); font-size: 14px; font-weight: 500; cursor: pointer; }
.time-slot input:checked + span { border-color: var(--bv-brown); background: var(--bv-brown); color: #fff; }
.time-slot.is-booked > span, .time-slot.is-passed > span { border-color: #ece3d6; background: #f4efe9; color: var(--bv-muted-soft); cursor: not-allowed; }
.time-slot em { font-size: 11px; font-style: normal; }
.time-slot.is-booked em { color: var(--bv-red); }
.time-hint { margin: 12px 0 0; color: var(--bv-muted); font-size: 13px; }
.time-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px 18px; margin: auto 0 0; padding: 12px 0 0; list-style: none; color: var(--bv-muted); font-size: 12px; }
.time-legend li { display: flex; align-items: center; gap: 7px; }
.legend-swatch { width: 12px; height: 12px; border: 1px solid var(--bv-line-strong); border-radius: 4px; background: var(--bv-card); }
.legend-swatch.is-selected { border-color: var(--bv-brown); background: var(--bv-brown); }
.legend-swatch.is-booked { border-color: #ece3d6; background: #f4efe9; }
@media (max-width: 900px) { .datetime-layout { grid-template-columns: 1fr; } }

/* Payment */
.pay-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; }
.pay-select { position: relative; margin-bottom: 18px; }
.pay-trigger { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; padding: 13px 18px; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); background: var(--bv-card); color: var(--bv-muted); font: inherit; font-size: 14.5px; cursor: pointer; }
.pay-trigger.has-value { color: var(--bv-ink); font-weight: 600; }
.pay-trigger.invalid { border-color: var(--bv-red); background: var(--bv-red-soft); }
.pay-options { position: absolute; top: calc(100% + 4px); right: 0; left: 0; z-index: 30; margin: 0; padding: 8px; list-style: none; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); box-shadow: 0 14px 30px rgba(63, 45, 32, .16); }
.pay-options[hidden], .pay-box[hidden], .pay-detail[hidden], .prov-panel[hidden], .pay-fields-wrap[hidden] { display: none; }
.pay-option { display: flex; align-items: center; gap: 14px; padding: 11px 14px; border-radius: 14px; cursor: pointer; }
.pay-option:hover { background: var(--bv-sand-soft); }
.pay-option.selected { background: var(--bv-green-soft); }
.pay-icon { display: flex; flex: 0 0 38px; align-items: center; justify-content: center; height: 38px; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); font-weight: 600; }
.pay-box { padding: 18px 20px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }
.pay-amounts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.pay-amounts div { padding: 12px 14px; border-radius: 12px; background: var(--bv-sand-soft); }
.pay-amounts span { display: block; color: var(--bv-muted); font-size: 12px; }
.pay-amounts strong { display: block; margin-top: 3px; color: var(--bv-brown); font-size: 18px; }
.pay-note { margin: 12px 2px 0; color: var(--bv-muted); font-size: 13px; line-height: 1.55; }
.pay-detail { margin: 18px 0 0; padding-top: 18px; border-top: 1px dashed #e0d4c3; }
.pay-sub { margin-bottom: 12px; font-size: 13px; font-weight: 600; }
.pay-steps, .next-box ol { display: grid; gap: 14px; margin: 0 0 18px; padding: 0; list-style: none; counter-reset: step; }
.pay-steps li, .next-box li { position: relative; display: grid; gap: 2px; padding-left: 40px; counter-increment: step; }
.pay-steps li::before, .next-box li::before { content: counter(step); position: absolute; top: 0; left: 0; display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); font-size: 12.5px; font-weight: 600; }
.pay-steps li strong, .next-box li strong { font-size: 13.5px; color: var(--bv-ink); }
.pay-steps li span, .next-box li span { font-size: 13px; color: var(--bv-muted); line-height: 1.55; }
.pay-where { display: grid; gap: 8px; margin-bottom: 14px; padding: 14px 16px; border-radius: 12px; background: var(--bv-sand-soft); }
.pay-where div { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; line-height: 1.45; }
.pay-where span { color: var(--bv-muted); white-space: nowrap; }
.pay-where strong { font-weight: 600; text-align: right; }
.prov-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
.prov-row { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--bv-line); border-radius: 14px; background: var(--bv-card); cursor: pointer; }
.prov-row input { position: absolute; opacity: 0; }
.prov-row.selected { border-color: var(--bv-green); background: var(--bv-green-soft); }
.prov-logo { display: flex; flex: 0 0 32px; align-items: center; justify-content: center; height: 32px; border-radius: 50%; background: var(--bv-sand); font-size: 13px; font-weight: 700; }
.prov-panel { display: grid; grid-template-columns: 150px 1fr; gap: 20px; align-items: center; margin-top: 16px; padding: 18px; border: 1px solid var(--bv-line); border-radius: 18px; background: var(--bv-cream); }
.pay-qr { width: 150px; height: 150px; padding: 8px; border: 1px solid var(--bv-line); border-radius: 14px; background: #fff; object-fit: contain; }
.pay-qr.placeholder { display: flex; align-items: center; justify-content: center; border: 1.5px dashed #cdbfae; color: var(--bv-muted-soft); }
.prov-info dl > div { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid #f0e8dc; font-size: 13.5px; }
.prov-amount { display: flex; justify-content: space-between; margin-top: 12px; padding: 10px 14px; border-radius: 12px; background: #fff; font-size: 13px; }
.copy-btn { height: 28px; padding: 0 12px; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); background: transparent; cursor: pointer; font-size: 11.5px; }
.pay-fields-wrap { margin-top: 20px; padding-top: 20px; border-top: 1px dashed #e0d4c3; }
.file-drop { display: block; padding: 18px 16px; border: 1.5px dashed #cdbfae; border-radius: 14px; background: var(--bv-cream); text-align: center; cursor: pointer; }
.file-drop input { display: none; }
.file-drop.has-file { border-style: solid; border-color: var(--bv-green); background: var(--bv-green-soft); }

/* Floating Draggable & Collapsible Summary + Apply to All Option */
.summary-card { position: fixed; right: 24px; bottom: 24px; z-index: 1000; width: 330px; max-width: calc(100vw - 24px); border: 1px solid #eadfce; border-radius: var(--bv-radius-card); background: var(--bv-cream); box-shadow: 0 18px 40px rgba(63, 45, 32, .22); }
.summary-card.is-dragging { opacity: .93; box-shadow: 0 24px 50px rgba(63, 45, 32, .3); }
.summary-card.is-dragging .summary-head { cursor: grabbing; }
.summary-head { display: flex; align-items: center; gap: 10px; padding: 10px 12px 10px 14px; cursor: grab; user-select: none; touch-action: none; }
.summary-title { flex: 1; min-width: 0; }
.summary-title strong { display: block; font-size: 14px; }
.summary-title small { display: block; overflow: hidden; color: var(--bv-muted); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.summary-head-total { color: var(--bv-brown); font-size: 16px; white-space: nowrap; }
.summary-toggle { flex: 0 0 30px; width: 30px; height: 30px; border: 1px solid var(--bv-line-strong); border-radius: 50%; background: #fff; cursor: pointer; }
.summary-card.is-open .summary-toggle { transform: rotate(180deg); }
.summary-body { max-height: min(60vh, 460px); padding: 12px 14px 14px; overflow-y: auto; border-top: 1px solid #eadfce; }
.summary-body[hidden] { display: none; }
.apply-all-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; padding: 8px 10px; border-radius: 12px; background: var(--bv-green-soft); border: 1px solid #cfe0cc; }
.apply-all-bar[hidden] { display: none; }
.apply-all-bar span { font-size: 12px; color: #36593b; font-weight: 500; }
.apply-all-btn { padding: 5px 11px; border-radius: var(--bv-radius-pill); border: 1px solid var(--bv-green); background: var(--bv-green); color: #fff; font: inherit; font-size: 11px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.apply-all-btn:hover { background: #4f6d53; }
.summary-box { min-height: 44px; padding: 10px 12px; border-radius: 12px; background: #efebe6; }
.breakdown { width: 100%; font-size: 12.5px; }
.breakdown .row { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; }
.breakdown .row:not(.sub):not(:first-child) { margin-top: 7px; padding-top: 7px; border-top: 1px dashed #d8cdbd; }
.breakdown .row.sub { padding: 2px 0 2px 10px; color: var(--bv-muted); font-size: 12px; }
.summary-meta { margin: 10px 0 0; font-size: 12.5px; }
.summary-meta div { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dashed #e0d4c3; }
.summary-total { display: flex; justify-content: space-between; margin-top: 10px; font-size: 13.5px; }
.summary-total strong { color: var(--bv-brown); font-size: 18px; }
.summary-split { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.summary-split div { padding: 8px 10px; border-radius: 10px; background: #efebe6; }

/* Step 5 Review & Dialog (Compact & Multi-line friendly) */
.review-box, .next-box, .policy-box { margin-bottom: 18px; border: 1px solid var(--bv-line); border-radius: 16px; background: var(--bv-card); padding: 14px 18px; }
.review-box div { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid #f0e8dc; font-size: 13.5px; line-height: 1.5; }
.review-box div:last-child { border-bottom: 0; }
.review-box dt { flex: 0 0 135px; color: var(--bv-muted); font-weight: 500; }
.review-box dd { flex: 1; margin: 0; font-weight: 600; text-align: right; word-break: break-word; overflow-wrap: anywhere; }
.review-box dd.is-multiline { text-align: left; }
@media (max-width: 560px) {
    .review-box div { flex-direction: column; gap: 3px; }
    .review-box dt { flex: none; }
    .review-box dd { text-align: left; }
}
.next-box h4, .policy-box h4 { margin: 0 0 14px; font-size: 15.5px; }
.policy-box ul { display: grid; gap: 12px; margin: 0; padding: 0; list-style: none; }
.policy-box li { position: relative; display: grid; gap: 2px; padding-left: 15px; font-size: 12.5px; line-height: 1.55; }
.policy-box li::before { content: ""; position: absolute; top: 7px; left: 0; width: 5px; height: 5px; border-radius: 50%; background: var(--bv-green); }
dialog.cf { width: min(420px, 92vw); padding: 0; border: 0; border-radius: 24px; background: var(--bv-card); box-shadow: 0 30px 80px rgba(63, 45, 32, .35); }
.cf-body { padding: 32px 30px 6px; text-align: center; }
.cf-foot { display: flex; justify-content: center; gap: 10px; padding: 22px 30px 28px; }

/* ---------- Typography: Cormorant Garamond (headings) + Jost (text) ---------- */
.booking-form-wrap { font-family: var(--bv-font-body); }
.booking-form-wrap h3, .booking-form-wrap h4, dialog.cf h3 { font-family: var(--bv-font-display); font-weight: 600; letter-spacing: .005em; color: var(--bv-ink); }
.step-head h3 { font-size: 30px; line-height: 1.15; }
.sub-section-title { font-size: 24px; }
.next-box h4, .policy-box h4 { font-size: 21px; margin: 0 0 14px; }
.form-grid label, .pay-label, .pay-sub, .addon-trigger, .guest-dd-trigger { font-family: var(--bv-font-body); }
.form-grid label, .pay-label { font-weight: 500; letter-spacing: .02em; }
.booking-form-wrap input, .booking-form-wrap select, .booking-form-wrap textarea, .booking-form-wrap button { font-family: var(--bv-font-body); }
</style>
</head>

<body class="booking-page">

<?php if (!$preview): ?>
<header class="site-header">
    <a class="brand" href="index.php">
        <span class="brand-mark">✦</span>
        <span>BELLA VISTA <small>OASIS SPA</small></span>
    </a>
    <nav class="nav-links">
        <a href="track.php">Track my booking</a>
        <a href="index.php">← Back to website</a>
    </nav>
</header>
<?php endif; ?>

<main class="booking-layout">
<aside class="booking-aside">
    <p class="eyebrow">Your time, your ritual</p>
    <h1>Make space<br>for <em>yourself.</em></h1>
    <p>Choose your room, customize treatments per guest, and pick a schedule that suits you best.</p>
    <div class="aside-detail">
        <span>✦</span>
        <div><strong>Tailored per guest</strong><small>Select which guests will avail each treatment, or apply to all.</small></div>
    </div>
    <div class="aside-detail">
        <span>◷</span>
        <div><strong>Private Suites</strong><small>Single, Couples, and Family Lounge spaces available.</small></div>
    </div>
</aside>

<section class="booking-form-wrap">
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="booking-head">
        <h2>Reserve your visit</h2>
        <ol class="stepper" id="stepper">
            <li data-step="1" class="active"><span class="lbl">Room &amp; Treatments</span></li>
            <li data-step="2"><span class="lbl">Date &amp; Time</span></li>
            <li data-step="3"><span class="lbl">Your Details</span></li>
            <li data-step="4"><span class="lbl">Payment</span></li>
            <li data-step="5"><span class="lbl">Confirmation</span></li>
        </ol>
    </div>

    <form action="save_booking.php" method="post" id="bookingForm" enctype="multipart/form-data" novalidate>
    <div id="hiddenGuestInputs"></div>
    <input type="hidden" name="service_id" id="service_id" value="">

    <!-- STEP 1: GUESTS, ROOM, TREATMENTS & ADD-ONS -->
    <div class="step-panel active" data-step="1">
        <div class="step-head">
            <h3>Guests &amp; Room Selection</h3>
        </div>
        <div class="form-grid" style="max-width:340px; margin-bottom: 14px;">
            <label>
                Number of guests
                <select name="guests" id="guests">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <option value="<?= $i ?>"><?= $i ?> guest<?= $i > 1 ? 's' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </label>
        </div>

        <div class="sub-section-title">Choose Your Room</div>
        <div class="choice-list" id="roomList">
            <?php foreach ($rooms as $r):
                $img = item_image($r, 'rooms');
                $minG = (int)($r['min_guests'] ?? 1);
                $maxG = (int)($r['max_guests'] ?? 6);
                $capLabel = $minG === $maxG ? ($maxG . ' guest' . ($maxG > 1 ? 's' : '')) : ($minG . ' to ' . $maxG . ' guests');
                $desc = trim((string)($r['description'] ?? ''));
                if ($desc === '') $desc = 'Comfortable private sanctuary tailored for your party size.';
                $rCat = $maxG === 1 ? 'single' : ($maxG === 2 ? 'couples' : 'family');
            ?>
            <label class="choice-card room-card"
                   data-min="<?= $minG ?>"
                   data-max="<?= $maxG ?>"
                   data-category="<?= $rCat ?>"
                   data-name="<?= e($r['name']) ?>"
                   data-price="<?= e((string)$r['price']) ?>">
                <input type="radio" name="room_id" value="<?= (int)$r['id'] ?>">
                <span class="choice-badge"><?= e($capLabel) ?></span>
                <span class="choice-thumb">
                    <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($r['name']) ?>" loading="lazy"><?php else: ?><span class="thumb-ph">⌂</span><?php endif; ?>
                </span>
                <span class="choice-body">
                    <strong class="choice-name"><?= e($r['name']) ?></strong>
                    <span class="choice-desc"><?= e($desc) ?></span>
                    <span class="choice-facts">
                        <span class="fact">◷ <?= (int)$r['duration_minutes'] ?> mins · <?= e($capLabel) ?></span>
                        <span class="fact price"><?= (float)$r['price'] > 0 ? '₱' . number_format((float)$r['price'], 2) : 'Included' ?></span>
                    </span>
                    <span class="choice-btn"><span class="off">Select Room</span><span class="on">✓ Selected</span></span>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
        <small class="field-error" id="roomError"></small>

        <div class="sub-section-title">
            <span>Select Treatments &amp; Packages</span>
        </div>

        <?php if ($packages): ?>
        <div class="choice-tabs" role="tablist">
            <button type="button" class="choice-tab active" data-panel="panelServices">Treatments <span class="count" data-count="service"></span></button>
            <button type="button" class="choice-tab" data-panel="panelPackages">Packages <span class="count" data-count="package"></span></button>
        </div>
        <?php endif; ?>

        <!-- Treatments -->
        <div class="choice-panel active" id="panelServices">
            <div class="choice-list">
                <?php foreach ($services as $s):
                    $img  = item_image($s, 'services');
                    $desc = trim((string)($s['description'] ?? ''));
                    if ($desc === '') $desc = 'A relaxing treatment designed to restore balance and leave you feeling refreshed.';
                    $key = 'service:' . (int)$s['id'];
                ?>
                <div class="choice-card treatment-card"
                     data-key="<?= $key ?>"
                     data-type="service"
                     data-id="<?= (int)$s['id'] ?>"
                     data-name="<?= e($s['name']) ?>"
                     data-price="<?= e((string)$s['price']) ?>"
                     data-duration="<?= (int)$s['duration_minutes'] ?>"
                     data-preselect="<?= $selectedService === (int)$s['id'] ? '1' : '0' ?>">
                    <span class="choice-thumb">
                        <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($s['name']) ?>" loading="lazy"><?php else: ?><span class="thumb-ph">✦</span><?php endif; ?>
                    </span>
                    <span class="choice-body">
                        <strong class="choice-name"><?= e($s['name']) ?></strong>
                        <span class="choice-desc"><?= e($desc) ?></span>
                        <span class="choice-facts">
                            <span class="fact">◷ <?= (int)$s['duration_minutes'] ?> mins</span>
                            <span class="fact price">₱<?= number_format((float)$s['price'], 2) ?></span>
                        </span>
                        <button type="button" class="choice-btn js-toggle-item">
                            <span class="off">+ Add to booking</span>
                            <span class="on">✓ Added</span>
                        </button>
                        <div class="guest-assign-wrap js-guest-bar" hidden>
                            <button type="button" class="guest-dd-trigger js-guest-dd-trigger" aria-expanded="false">
                                <span>For guest: <strong class="js-guest-dd-label is-empty">Select guests</strong></span>
                                <span class="guest-dd-caret">▾</span>
                            </button>
                            <div class="guest-dd-menu js-guest-dd-menu" hidden></div>
                        </div>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Packages -->
        <?php if ($packages): ?>
        <div class="choice-panel" id="panelPackages">
            <div class="choice-list">
                <?php foreach ($packages as $p):
                    $img  = item_image($p, 'packages');
                    $desc = trim((string)($p['description'] ?? ''));
                    if ($desc === '') $desc = 'A curated bundle of treatments for a complete spa experience.';
                    $key = 'package:' . (int)$p['id'];
                    $pkgRoomType = strtolower(trim((string)($p['room_type'] ?? 'all')));
                ?>
                <div class="choice-card treatment-card package-card"
                     data-key="<?= $key ?>"
                     data-type="package"
                     data-id="<?= (int)$p['id'] ?>"
                     data-room-type="<?= e($pkgRoomType) ?>"
                     data-name="<?= e($p['name']) ?>"
                     data-price="<?= e((string)$p['price']) ?>"
                     data-duration="<?= (int)$p['duration_minutes'] ?>">
                    <span class="choice-badge">Package</span>
                    <span class="choice-thumb">
                        <?php if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>" loading="lazy"><?php else: ?><span class="thumb-ph">❀</span><?php endif; ?>
                    </span>
                    <span class="choice-body">
                        <strong class="choice-name"><?= e($p['name']) ?></strong>
                        <span class="choice-desc"><?= e($desc) ?></span>
                        <span class="choice-facts">
                            <span class="fact">◷ <?= (int)$p['duration_minutes'] ?> mins</span>
                            <span class="fact price">₱<?= number_format((float)$p['price'], 2) ?></span>
                        </span>
                        <button type="button" class="choice-btn js-toggle-item">
                            <span class="off">+ Add to booking</span>
                            <span class="on">✓ Added</span>
                        </button>
                        <div class="guest-assign-wrap js-guest-bar" hidden>
                            <button type="button" class="guest-dd-trigger js-guest-dd-trigger" aria-expanded="false">
                                <span>For guest: <strong class="js-guest-dd-label is-empty">Select guests</strong></span>
                                <span class="guest-dd-caret">▾</span>
                            </button>
                            <div class="guest-dd-menu js-guest-dd-menu" hidden></div>
                        </div>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <small class="field-error" id="serviceError"></small>

        <!-- Add-ons Dropdown below Treatments -->
        <?php if ($addons): ?>
        <div class="addon-dropdown-wrap" id="addonWrap">
            <label class="pay-label" style="margin-bottom:6px;">Optional Add-ons</label>
            <button type="button" class="addon-trigger" id="addonTrigger" aria-expanded="false">
                <span id="addonTriggerText">Select optional add-ons (0 selected)</span>
                <span class="addon-caret">▾</span>
            </button>
            <div class="addon-menu" id="addonMenu" hidden>
                <?php foreach ($addons as $ad): ?>
                <label class="addon-item">
                    <input type="checkbox" name="addon_ids[]" value="<?= (int)$ad['id'] ?>"
                           data-name="<?= e($ad['name']) ?>"
                           data-price="<?= e((string)$ad['price']) ?>">
                    <span class="addon-item-info">
                        <strong><?= e($ad['name']) ?></strong>
                        <?php if (!empty($ad['description'])): ?><small><?= e($ad['description']) ?></small><?php endif; ?>
                    </span>
                    <span class="addon-item-price">+₱<?= number_format((float)$ad['price'], 2) ?></span>
                </label>
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
                    <div class="calendar-selects">
                        <select class="calendar-select" id="calendarMonth"></select>
                        <select class="calendar-select" id="calendarYear"></select>
                    </div>
                    <button type="button" class="calendar-nav" id="calendarNext">›</button>
                </header>
                <div class="calendar-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                <div class="calendar-days" id="calendarDays"></div>
                <p class="calendar-note">Closed on Mondays</p>
            </section>
            <section class="datetime-card">
                <header class="datetime-card-head"><h4 id="selectedDateLabel">Select a date</h4></header>
                <div class="time-grid" id="timeGrid"></div>
                <p class="time-hint" id="timeHint">Choose a date to see available times.</p>
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
            <div class="form-grid">
                <label class="full">Full name<input type="text" name="customer_name" id="customer_name" required maxlength="120" placeholder="Enter your full name"><small class="field-error" id="nameError"></small></label>
                <label>Email address<input type="email" name="email" id="email" required maxlength="150" placeholder="example@email.com"><small class="field-error" id="emailError"></small></label>
                <label>Phone number<input type="tel" name="phone" id="phone" required maxlength="12" inputmode="numeric" placeholder="09XXXXXXXXX or 639XXXXXXXXX"><small class="field-error" id="phoneError"></small></label>
                <label class="full">Notes or preferences (optional)<textarea name="notes" id="notes" rows="3" maxlength="1000" placeholder="Share any preferences or accessibility needs."></textarea></label>
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
                <button type="button" class="pay-trigger" id="payTrigger" aria-expanded="false">
                    <span id="payTriggerText">Select payment method</span><span>▾</span>
                </button>
                <ul class="pay-options" id="payOptions" hidden>
                    <?php foreach ($paymentMethods as $key => $m): ?>
                        <li class="pay-option" data-value="<?= e($key) ?>" data-label="<?= e($m['label']) ?>">
                            <span class="pay-icon"><?= e($m['icon']) ?></span>
                            <span class="pay-text"><strong><?= e($m['label']) ?></strong><small><?= e($m['hint']) ?></small></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <small class="field-error" id="methodError"></small>
            </div>

            <div class="pay-box" id="payBox" hidden>
                <div class="pay-amounts">
                    <div><span>Pay now</span><strong class="js-due">₱0.00</strong></div>
                    <div><span>Pay at the store</span><strong class="js-balance">₱0.00</strong></div>
                </div>
                <p class="pay-note" id="payNote"></p>
                <input type="hidden" name="amount_due" id="amount_due" value="0">

                <div class="pay-detail" data-method="cash" hidden>
                    <div class="pay-sub">How paying at the store works</div>
                    <ol class="pay-steps">
                        <li><strong>Submit your booking request</strong><span>You will receive a booking code right away. Keep it handy.</span></li>
                        <li><strong>Arrive 10–15 minutes early</strong><span>Present your booking code at the front desk on your scheduled date.</span></li>
                        <li><strong>Pay at the store</strong><span>Pay the full amount of <strong class="js-balance">₱0.00</strong> in cash at the store before your treatment begins.</span></li>
                    </ol>
                    <div class="pay-where">
                        <div><span>Store location</span><strong><?= e($spaInfo['address']) ?></strong></div>
                        <div><span>Store hours</span><strong><?= e($spaInfo['hours']) ?></strong></div>
                        <div><span>Questions</span><strong><?= e($spaInfo['phone']) ?></strong></div>
                    </div>
                </div>

                <?php foreach (['ewallet', 'bank'] as $mk): $mm = $paymentMethods[$mk]; ?>
                <div class="pay-detail" data-method="<?= $mk ?>" hidden>
                    <div class="pay-sub"><?= $mk === 'ewallet' ? 'Choose your e-wallet' : 'Choose your bank' ?></div>
                    <div class="prov-list">
                        <?php foreach ($mm['providers'] as $pk => $pv): ?>
                            <label class="prov-row">
                                <input type="radio" name="prov_<?= $mk ?>" value="<?= e($pk) ?>" data-method="<?= $mk ?>" data-name="<?= e($pv['name']) ?>">
                                <span class="prov-logo"><?= e($pv['initial']) ?></span>
                                <span class="prov-name"><?= e($pv['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <small class="field-error" id="provError_<?= $mk ?>"></small>

                    <?php foreach ($mm['providers'] as $pk => $pv):
                        $qr = '';
                        foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                            $f = 'assets/images/payments/' . $pk . '.' . $ext;
                            if (is_file(__DIR__ . '/' . $f)) { $qr = $f; break; }
                        } ?>
                    <div class="prov-panel" data-method="<?= $mk ?>" data-provider="<?= e($pk) ?>" hidden>
                        <div><?php if ($qr): ?><img class="pay-qr" src="<?= e($qr) ?>" alt="QR"><?php else: ?><div class="pay-qr placeholder">QR code</div><?php endif; ?></div>
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
                                <small id="fileSub">JPG, PNG, WEBP or PDF · max 5 MB</small>
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
            <div class="next-box">
                <h4>What happens next</h4>
                <ol>
                    <li><strong>Payment</strong><span id="nextPay"></span></li>
                    <li><strong>We verify and confirm</strong><span>Your booking turns Confirmed once verified.</span></li>
                    <li><strong>Track your booking</strong><span>Use your booking code anytime on Track my booking.</span></li>
                </ol>
            </div>
            <div class="policy-box">
                <h4>Booking policies</h4>
                <ul><?php foreach ($policies as $pol): ?><li><strong><?= e($pol[0]) ?></strong><span><?= e($pol[1]) ?></span></li><?php endforeach; ?></ul>
            </div>
            <label class="checkbox full"><input type="checkbox" id="terms" name="agree_terms" value="1" required> I have read and agree to the booking policies above.</label>
            <small class="field-error full" id="termsError"></small>
        </div>
    </div>

    <div class="step-nav" id="stepNav">
        <button type="button" class="bv-btn bv-btn--outline" id="backBtn" style="display:none;">← Back</button>
        <button type="button" class="bv-btn bv-btn--primary" id="nextBtn">Next →</button>
        <button type="submit" class="bv-btn bv-btn--primary" id="submitBtn" style="display:none;">Submit →</button>
    </div>

    <!-- Floating Draggable & Collapsible Booking Summary -->
    <aside class="summary-card" id="summaryCard" aria-label="Booking summary">
        <div class="summary-head" id="summaryHandle" tabindex="0" title="Drag to move">
            <span style="color:#8a7a6b;">⋮⋮</span>
            <div class="summary-title">
                <strong>Booking Summary</strong>
                <small id="summaryCount">No treatment yet</small>
            </div>
            <strong class="summary-head-total" id="summaryHeadTotal">₱0.00</strong>
            <button type="button" class="summary-toggle" id="summaryToggle" aria-expanded="false">▾</button>
        </div>
        <div class="summary-body" id="summaryBody" hidden>
            <div class="apply-all-bar" id="applyAllBar" hidden>
                <span>Same treatments for all guests?</span>
                <button type="button" class="apply-all-btn" id="applyAllBtn">Apply to all</button>
            </div>
            <div class="summary-box breakdown" id="breakdown"><div class="empty">No service selected yet</div></div>
            <dl class="summary-meta">
                <div><dt>Room</dt><dd id="sumRoom">—</dd></div>
                <div><dt>Guests</dt><dd id="sumGuests">1 guest</dd></div>
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

<dialog id="confirmDialog" class="cf">
    <div class="cf-body"><h3 id="cfTitle">Please confirm</h3><p id="cfText"></p></div>
    <div class="cf-foot">
        <button type="button" class="bv-btn bv-btn--outline" id="cfNo">No</button>
        <button type="button" class="bv-btn bv-btn--primary" id="cfYes">Yes</button>
    </div>
</dialog>

<script>
window.BOOKING_CONFIG = <?= json_encode($bookingConfig, JSON_UNESCAPED_UNICODE) ?>;

(function () {
    'use strict';
    const config = window.BOOKING_CONFIG;
    const byId = id => document.getElementById(id);
    const formatPeso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const guestsSelect = byId('guests');
    const dateInput = byId('appointment_date');
    const nameInput = byId('customer_name');
    const emailInput = byId('email');
    const phoneInput = byId('phone');
    const notesInput = byId('notes');
    const paymentMethodInput = byId('payment_method');
    const paymentProviderInput = byId('payment_provider');
    const referenceInput = byId('reference_no');
    const proofInput = byId('proof_image');

    let currentStep = 1;
    let currentTotal = 0;

    const guestPicks = { 1: new Set() };
    const activeCards = new Set();

    document.querySelectorAll('.treatment-card[data-preselect="1"]').forEach(card => {
        activeCards.add(card.dataset.key);
        guestPicks[1].add(card.dataset.key);
    });

    function ensureGuestSets(totalGuests) {
        for (let g = 1; g <= totalGuests; g++) {
            if (!guestPicks[g]) guestPicks[g] = new Set();
        }
        Object.keys(guestPicks).forEach(k => {
            if (Number(k) > totalGuests) delete guestPicks[k];
        });
    }

    /* ---------- Rooms & Package Filtering ---------- */
    function updateRoomAvailability() {
        const g = parseInt(guestsSelect.value, 10) || 1;
        const roomCards = Array.from(document.querySelectorAll('.room-card'));
        if (!roomCards.length) return;

        let validSelected = false;
        roomCards.forEach(card => {
            const min = parseInt(card.dataset.min, 10);
            const max = parseInt(card.dataset.max, 10);
            const radio = card.querySelector('input[type="radio"]');
            const fits = g >= min && g <= max;
            card.classList.toggle('is-disabled', !fits);
            radio.disabled = !fits;
            if (!fits && radio.checked) radio.checked = false;
            if (radio.checked && fits) {
                validSelected = true;
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });

        if (!validSelected) {
            const firstFit = roomCards.find(c => !c.classList.contains('is-disabled'));
            if (firstFit) {
                firstFit.querySelector('input[type="radio"]').checked = true;
                firstFit.classList.add('selected');
            }
        }
        filterPackagesByRoom();
    }

    function getSelectedRoomCard() {
        const checked = document.querySelector('.room-card input[type="radio"]:checked');
        return checked ? checked.closest('.room-card') : null;
    }

    function filterPackagesByRoom() {
        const room = getSelectedRoomCard();
        const cat = room ? room.dataset.category : 'all';
        document.querySelectorAll('.package-card').forEach(pkg => {
            const pType = pkg.dataset.roomType || 'all';
            const allowed = (pType === 'all' || pType === cat);
            pkg.hidden = !allowed;
            if (!allowed) {
                activeCards.delete(pkg.dataset.key);
                Object.values(guestPicks).forEach(set => set.delete(pkg.dataset.key));
            }
        });
        refreshSliders();
    }

    document.querySelectorAll('.room-card input[type="radio"]').forEach(r => {
        r.addEventListener('change', () => {
            updateRoomAvailability();
            updateSummary();
        });
    });

    /* ---------- For Guest Dropdown inside each Treatment Card ---------- */
    function getAssignedGuests(key) {
        const gCount = parseInt(guestsSelect.value, 10) || 1;
        const list = [];
        for (let g = 1; g <= gCount; g++) {
            if (guestPicks[g] && guestPicks[g].has(key)) list.push(g);
        }
        return list;
    }

    function toggleItemForGuest(key, guestNum) {
        const gCount = parseInt(guestsSelect.value, 10) || 1;
        ensureGuestSets(gCount);
        if (guestPicks[guestNum].has(key)) {
            guestPicks[guestNum].delete(key);
        } else {
            guestPicks[guestNum].add(key);
        }
        updateSummary();
    }

    function syncTreatmentCardsUI() {
        const gCount = parseInt(guestsSelect.value, 10) || 1;
        ensureGuestSets(gCount);

        const applyBar = byId('applyAllBar');
        if (applyBar) applyBar.hidden = gCount <= 1;

        document.querySelectorAll('.treatment-card').forEach(card => {
            const key = card.dataset.key;
            const assigned = getAssignedGuests(key);
            if (assigned.length > 0) activeCards.add(key);

            if (gCount === 1 && activeCards.has(key)) {
                guestPicks[1].add(key);
            }

            const isSelected = activeCards.has(key);
            card.classList.toggle('selected', isSelected);

            const toggleBtn = card.querySelector('.js-toggle-item');
            toggleBtn.querySelector('.off').style.display = isSelected ? 'none' : 'inline';
            toggleBtn.querySelector('.on').style.display = isSelected ? 'inline' : 'none';

            const assignBar = card.querySelector('.js-guest-bar');
            const ddLabel = card.querySelector('.js-guest-dd-label');
            const ddMenu = card.querySelector('.js-guest-dd-menu');
            const ddTrigger = card.querySelector('.js-guest-dd-trigger');

            if (gCount > 1 && isSelected) {
                assignBar.hidden = false;

                if (assigned.length === 0) {
                    ddLabel.textContent = 'Select guests';
                    ddLabel.classList.add('is-empty');
                } else if (assigned.length === gCount) {
                    ddLabel.textContent = `All guests (${gCount})`;
                    ddLabel.classList.remove('is-empty');
                } else {
                    ddLabel.textContent = assigned.map(g => 'G' + g).join(', ');
                    ddLabel.classList.remove('is-empty');
                }

                const allChecked = assigned.length === gCount;
                let menuHtml = `<label class="guest-dd-item is-all"><input type="checkbox" data-guest="all" ${allChecked ? 'checked' : ''}><span>All guests (${gCount})</span></label>`;
                for (let g = 1; g <= gCount; g++) {
                    const isOn = assigned.includes(g);
                    menuHtml += `<label class="guest-dd-item"><input type="checkbox" data-guest="${g}" ${isOn ? 'checked' : ''}><span>Guest ${g}</span></label>`;
                }
                ddMenu.innerHTML = menuHtml;
            } else {
                assignBar.hidden = true;
                ddMenu.hidden = true;
                ddTrigger.setAttribute('aria-expanded', 'false');
            }
        });

        ['service', 'package'].forEach(type => {
            let count = 0;
            Object.values(guestPicks).forEach(set => {
                set.forEach(k => { if (k.startsWith(type + ':')) count++; });
            });
            const badge = document.querySelector(`.choice-tab .count[data-count="${type}"]`);
            if (badge) {
                badge.textContent = count;
                badge.classList.toggle('show', count > 0);
            }
        });
    }

    document.querySelectorAll('.treatment-card').forEach(card => {
        const key = card.dataset.key;
        const ddTrigger = card.querySelector('.js-guest-dd-trigger');
        const ddMenu = card.querySelector('.js-guest-dd-menu');

        card.querySelector('.js-toggle-item').addEventListener('click', e => {
            e.preventDefault();
            const gCount = parseInt(guestsSelect.value, 10) || 1;
            ensureGuestSets(gCount);

            if (activeCards.has(key)) {
                activeCards.delete(key);
                for (let g = 1; g <= gCount; g++) guestPicks[g].delete(key);
                ddMenu.hidden = true;
                ddTrigger.setAttribute('aria-expanded', 'false');
            } else {
                activeCards.add(key);
                if (gCount === 1) {
                    guestPicks[1].add(key);
                } else {
                    document.querySelectorAll('.js-guest-dd-menu').forEach(m => { m.hidden = true; });
                    document.querySelectorAll('.js-guest-dd-trigger').forEach(t => t.setAttribute('aria-expanded', 'false'));
                    ddMenu.hidden = false;
                    ddTrigger.setAttribute('aria-expanded', 'true');
                }
            }
            updateSummary();
        });

        ddTrigger.addEventListener('click', e => {
            e.preventDefault();
            e.stopPropagation();
            const willOpen = ddMenu.hidden;
            document.querySelectorAll('.js-guest-dd-menu').forEach(m => { m.hidden = true; });
            document.querySelectorAll('.js-guest-dd-trigger').forEach(t => t.setAttribute('aria-expanded', 'false'));
            ddMenu.hidden = !willOpen;
            ddTrigger.setAttribute('aria-expanded', String(willOpen));
        });

        ddMenu.addEventListener('change', e => {
            const cb = e.target;
            if (!cb || cb.type !== 'checkbox') return;
            const gCount = parseInt(guestsSelect.value, 10) || 1;
            ensureGuestSets(gCount);

            if (cb.dataset.guest === 'all') {
                for (let g = 1; g <= gCount; g++) {
                    if (cb.checked) guestPicks[g].add(key);
                    else guestPicks[g].delete(key);
                }
            } else {
                toggleItemForGuest(key, parseInt(cb.dataset.guest, 10));
                return;
            }
            updateSummary();
        });
    });

    document.addEventListener('click', e => {
        if (!e.target.closest('.guest-assign-wrap')) {
            document.querySelectorAll('.js-guest-dd-menu').forEach(m => { m.hidden = true; });
            document.querySelectorAll('.js-guest-dd-trigger').forEach(t => t.setAttribute('aria-expanded', 'false'));
        }
    });

    byId('applyAllBtn').addEventListener('click', () => {
        const gCount = parseInt(guestsSelect.value, 10) || 1;
        const selectedKeys = new Set(activeCards);

        if (selectedKeys.size === 0) {
            byId('serviceError').textContent = 'Select at least one treatment first before applying to all guests.';
            return;
        }
        for (let g = 1; g <= gCount; g++) {
            guestPicks[g] = new Set(selectedKeys);
        }
        updateSummary();
    });

    /* ---------- Add-ons Dropdown ---------- */
    const addonTrigger = byId('addonTrigger');
    const addonMenu = byId('addonMenu');
    if (addonTrigger && addonMenu) {
        addonTrigger.addEventListener('click', () => {
            const open = addonMenu.hidden;
            addonMenu.hidden = !open;
            addonTrigger.setAttribute('aria-expanded', String(open));
        });
        document.addEventListener('click', e => {
            if (!byId('addonWrap').contains(e.target)) {
                addonMenu.hidden = true;
                addonTrigger.setAttribute('aria-expanded', 'false');
            }
        });
        addonMenu.querySelectorAll('input[type="checkbox"]').forEach(cb => {
            cb.addEventListener('change', updateSummary);
        });
    }

    function getSelectedAddons() {
        return Array.from(document.querySelectorAll('#addonMenu input[type="checkbox"]:checked'));
    }

    /* ---------- Sync Hidden Form Inputs & Summary Calculation ---------- */
    function updateSummary() {
        const gCount = parseInt(guestsSelect.value, 10) || 1;
        syncTreatmentCardsUI();

        const hiddenContainer = byId('hiddenGuestInputs');
        hiddenContainer.innerHTML = '';
        let firstServiceId = '';

        for (let g = 1; g <= gCount; g++) {
            (guestPicks[g] || []).forEach(key => {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = `guest_items[${g}][]`;
                inp.value = key;
                hiddenContainer.appendChild(inp);
                if (!firstServiceId && key.startsWith('service:')) {
                    firstServiceId = key.split(':')[1];
                }
            });
        }
        byId('service_id').value = firstServiceId;

        let total = 0;
        let rowsHtml = '';
        let totalItemCount = 0;

        // 1. Room fee
        const room = getSelectedRoomCard();
        byId('sumRoom').textContent = room ? room.dataset.name : '—';
        if (room) {
            const rPrice = parseFloat(room.dataset.price) || 0;
            if (rPrice > 0) {
                total += rPrice;
                rowsHtml += `<div class="row"><span>${escapeHtml(room.dataset.name)}</span><span>${formatPeso(rPrice)}</span></div>`;
            }
        }

        // 2. Per-guest treatments/packages
        for (let g = 1; g <= gCount; g++) {
            const picks = Array.from(guestPicks[g] || []);
            if (!picks.length) continue;
            rowsHtml += `<div class="row"><span><strong>Guest ${g}</strong></span><span></span></div>`;
            picks.forEach(key => {
                const card = document.querySelector(`.treatment-card[data-key="${key}"]`);
                if (!card) return;
                const line = Math.round((parseFloat(card.dataset.price) || 0) * 100) / 100;
                total += line;
                totalItemCount++;
                rowsHtml += `<div class="row sub"><span>${escapeHtml(card.dataset.name)}</span><span>${formatPeso(line)}</span></div>`;
            });
        }

        // 3. Add-ons
        const selectedAddons = getSelectedAddons();
        if (addonTrigger) {
            byId('addonTriggerText').textContent = selectedAddons.length
                ? `${selectedAddons.length} add-on${selectedAddons.length > 1 ? 's' : ''} selected`
                : 'Select optional add-ons (0 selected)';
        }
        selectedAddons.forEach(cb => {
            const aPrice = parseFloat(cb.dataset.price) || 0;
            total += aPrice;
            rowsHtml += `<div class="row"><span>+ ${escapeHtml(cb.dataset.name)}</span><span>${formatPeso(aPrice)}</span></div>`;
        });

        currentTotal = Math.round(total * 100) / 100;
        byId('breakdown').innerHTML = rowsHtml || '<div class="empty">No service selected yet</div>';
        byId('sumGuests').textContent = gCount + ' guest' + (gCount > 1 ? 's' : '');
        byId('summaryCount').textContent = totalItemCount
            ? `${totalItemCount} item${totalItemCount > 1 ? 's' : ''} · ${gCount} guest${gCount > 1 ? 's' : ''}`
            : 'No treatment yet';
        byId('totalPrice').textContent = formatPeso(currentTotal);
        byId('summaryHeadTotal').textContent = formatPeso(currentTotal);
        if (totalItemCount > 0) byId('serviceError').textContent = '';

        updatePayment();
    }

    /* ---------- Calendar & Time Slots ---------- */
    const pad2 = n => String(n).padStart(2, '0');
    const todayISO = () => { const d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); };
    const dateFromISO = iso => new Date(iso + 'T00:00:00');
    const formatTime = t => { const [h, m] = t.split(':').map(Number); return ((h % 12) || 12) + ':' + pad2(m) + ' ' + (h >= 12 ? 'PM' : 'AM'); };

    const MONTH_NAMES = Array.from({ length: 12 }, (_, m) => new Date(2000, m, 1).toLocaleDateString('en-US', { month: 'long' }));
    const visibleMonth = new Date(); visibleMonth.setDate(1);
    const calMonth = byId('calendarMonth'), calYear = byId('calendarYear'), calDays = byId('calendarDays');

    function fillCalendarSelects() {
        calMonth.innerHTML = MONTH_NAMES.map((n, i) => `<option value="${i}">${n}</option>`).join('');
        let opts = '';
        for (let y = new Date().getFullYear(); y <= new Date().getFullYear() + config.maxYearsAhead; y++) opts += `<option value="${y}">${y}</option>`;
        calYear.innerHTML = opts;
    }

    function renderCalendar() {
        const y = visibleMonth.getFullYear(), m = visibleMonth.getMonth();
        const firstDay = new Date(y, m, 1).getDay(), daysInMonth = new Date(y, m + 1, 0).getDate();
        const today = todayISO(), sel = dateInput.value;
        calYear.value = y; calMonth.value = m;
        let html = '<span></span>'.repeat(firstDay);
        for (let d = 1; d <= daysInMonth; d++) {
            const iso = y + '-' + pad2(m + 1) + '-' + pad2(d);
            const dis = iso < today || new Date(y, m, d).getDay() === config.closedWeekday;
            html += `<button type="button" class="calendar-day ${iso === today ? 'is-today' : ''} ${iso === sel ? 'is-selected' : ''}" data-date="${iso}" ${dis ? 'disabled' : ''}>${d}</button>`;
        }
        calDays.innerHTML = html;
    }

    calDays.addEventListener('click', async e => {
        const btn = e.target.closest('.calendar-day');
        if (!btn || btn.disabled) return;
        dateInput.value = btn.dataset.date;
        byId('dateError').textContent = '';
        renderCalendar();
        byId('sumDate').textContent = dateFromISO(dateInput.value).toLocaleDateString('en-PH', { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
        byId('selectedDateLabel').textContent = dateFromISO(dateInput.value).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

        let booked = [];
        try { booked = (await (await fetch(config.slotsUrl + encodeURIComponent(dateInput.value))).json()).booked || []; } catch (err) {}
        byId('timeHint').textContent = '';
        byId('timeGrid').innerHTML = config.timeSlots.map(t => {
            let state = booked.includes(t) ? 'booked' : 'available';
            if (state === 'available' && dateInput.value === todayISO()) {
                const [hh, mm] = t.split(':').map(Number); const now = new Date();
                if (hh * 60 + mm <= now.getHours() * 60 + now.getMinutes()) state = 'passed';
            }
            return `<label class="time-slot is-${state}"><input type="radio" name="appointment_time" value="${t}" ${state !== 'available' ? 'disabled' : ''}><span>${formatTime(t)}${state !== 'available' ? `<em>${state}</em>` : ''}</span></label>`;
        }).join('');
    });
    byId('calendarPrev').addEventListener('click', () => { visibleMonth.setMonth(visibleMonth.getMonth() - 1); renderCalendar(); });
    byId('calendarNext').addEventListener('click', () => { visibleMonth.setMonth(visibleMonth.getMonth() + 1); renderCalendar(); });
    calMonth.addEventListener('change', () => { visibleMonth.setMonth(+calMonth.value); renderCalendar(); });
    calYear.addEventListener('change', () => { visibleMonth.setFullYear(+calYear.value); renderCalendar(); });
    byId('timeGrid').addEventListener('change', () => {
        const t = document.querySelector('input[name="appointment_time"]:checked');
        byId('sumTime').textContent = t ? formatTime(t.value) : '—';
        byId('timeError').textContent = '';
    });

    /* ---------- Payment & Summary ---------- */
    const payTrigger = byId('payTrigger'), payOptions = byId('payOptions');
    payTrigger.addEventListener('click', () => { payOptions.hidden = !payOptions.hidden; });
    payOptions.querySelectorAll('.pay-option').forEach(opt => opt.addEventListener('click', () => {
        paymentMethodInput.value = opt.dataset.value;
        byId('payTriggerText').textContent = opt.dataset.label;
        payTrigger.classList.add('has-value');
        payOptions.hidden = true;
        byId('methodError').textContent = '';
        updatePayment();
    }));
    document.querySelectorAll('.prov-row input').forEach(r => r.addEventListener('change', updatePayment));
    document.querySelectorAll('.copy-btn').forEach(btn => btn.addEventListener('click', () => {
        if (navigator.clipboard) navigator.clipboard.writeText(btn.dataset.copy);
        btn.textContent = 'Copied';
        setTimeout(() => { btn.textContent = 'Copy'; }, 1500);
    }));

    function updatePayment() {
        const m = paymentMethodInput.value;
        const rate = m ? (config.downPaymentRates[m] || 0) : 0;
        const due = Math.round(currentTotal * rate * 100) / 100;
        const bal = Math.max(currentTotal - due, 0);
        const prov = Array.from(document.querySelectorAll('.prov-row input')).find(r => r.checked && r.dataset.method === m);

        document.querySelectorAll('.js-due').forEach(el => el.textContent = formatPeso(due));
        document.querySelectorAll('.js-balance').forEach(el => el.textContent = formatPeso(bal));
        byId('sumDue').textContent = formatPeso(due);
        byId('sumBalance').textContent = formatPeso(bal);
        byId('amount_due').value = due.toFixed(2);
        paymentProviderInput.value = prov ? prov.value : '';
        byId('sumPayment').textContent = m ? (payOptions.querySelector(`[data-value="${m}"]`).dataset.label + (prov ? ' · ' + prov.dataset.name : '')) : '—';

        const notes = {
            cash: `No upfront payment needed. Pay the full amount of ${formatPeso(bal)} at the store when you arrive for your appointment.`,
            ewallet: `Send ${formatPeso(due)} (${Math.round(rate * 100)}% reservation fee) with your chosen e-wallet, then enter the reference number.`,
            bank: `Transfer ${formatPeso(due)} (${Math.round(rate * 100)}% reservation fee) to your chosen bank, then enter the reference number.`
        };
        byId('payNote').textContent = notes[m] || '';

        byId('payBox').hidden = !m;
        document.querySelectorAll('.pay-detail').forEach(d => d.hidden = d.dataset.method !== m);
        document.querySelectorAll('.prov-row').forEach(row => row.classList.toggle('selected', row.querySelector('input').checked));
        document.querySelectorAll('.prov-panel').forEach(p => p.hidden = !(prov && p.dataset.method === m && p.dataset.provider === prov.value));

        const online = ['ewallet', 'bank'].includes(m);
        byId('payFields').hidden = !online;
        referenceInput.disabled = !online;
        proofInput.disabled = !online;
    }

    proofInput.addEventListener('change', () => {
        const f = proofInput.files[0];
        byId('fileDrop').classList.toggle('has-file', !!f);
        byId('fileTitle').textContent = f ? f.name : 'Upload proof of payment';
    });

    /* ---------- Slider & Tabs ---------- */
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
        const update = () => {
            prev.disabled = list.scrollLeft <= 4;
            next.disabled = list.scrollLeft + list.clientWidth >= list.scrollWidth - 4;
        };
        prev.addEventListener('click', () => list.scrollBy({ left: -280, behavior: 'smooth' }));
        next.addEventListener('click', () => list.scrollBy({ left: 280, behavior: 'smooth' }));
        list.addEventListener('scroll', update, { passive: true });
        list.updateSlider = update;
        update();
    }
    function refreshSliders() { document.querySelectorAll('.choice-list').forEach(l => l.updateSlider && l.updateSlider()); }

    /* ---------- Floating Booking Summary (Drag & Collapse) ---------- */
    (function initSummaryPanel() {
        const card = byId('summaryCard');
        const handle = byId('summaryHandle');
        const toggle = byId('summaryToggle');
        const body = byId('summaryBody');
        let dragOffset = null;

        function placeAt(left, top) {
            const maxLeft = Math.max(window.innerWidth - card.offsetWidth - 8, 8);
            const maxTop = Math.max(window.innerHeight - card.offsetHeight - 8, 8);
            card.style.left = Math.min(Math.max(left, 8), maxLeft) + 'px';
            card.style.top = Math.min(Math.max(top, 8), maxTop) + 'px';
            card.style.right = 'auto';
            card.style.bottom = 'auto';
        }

        function keepInView() {
            if (!card.style.left) return;
            const box = card.getBoundingClientRect();
            placeAt(box.left, box.top);
        }

        toggle.addEventListener('click', () => {
            const willOpen = body.hidden;
            body.hidden = !willOpen;
            card.classList.toggle('is-open', willOpen);
            toggle.setAttribute('aria-expanded', String(willOpen));
            requestAnimationFrame(keepInView);
        });

        handle.addEventListener('pointerdown', e => {
            if (e.target.closest('button')) return;
            const box = card.getBoundingClientRect();
            dragOffset = { x: e.clientX - box.left, y: e.clientY - box.top };
            handle.setPointerCapture(e.pointerId);
            card.classList.add('is-dragging');
        });

        handle.addEventListener('pointermove', e => {
            if (dragOffset) placeAt(e.clientX - dragOffset.x, e.clientY - dragOffset.y);
        });

        const stopDrag = () => {
            if (!dragOffset) return;
            dragOffset = null;
            card.classList.remove('is-dragging');
        };
        handle.addEventListener('pointerup', stopDrag);
        handle.addEventListener('pointercancel', stopDrag);
        window.addEventListener('resize', keepInView);
    })();

    /* ---------- Live Field Validation (Step 3) ---------- */
    let nameTouched = false;

    function validateNameField(showEmpty = true) {
        const val = nameInput.value.trim();
        const words = val.split(/\s+/).filter(Boolean);
        const validSuffixes = /^(jr\.?|sr\.?|ii|iii|iv|v)$/i;
        let msg = '';

        if (!val) {
            if (showEmpty || nameTouched) msg = 'Please enter your full name.';
        } else if (/\d/.test(val)) {
            msg = 'Full name must not contain numbers.';
        } else if (!/^[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\s.'-]*$/.test(val)) {
            msg = 'Please enter a valid name (letters only).';
        } else if (words.length < 2) {
            msg = 'Please enter both your first and last name.';
        } else if (
            /(.)\1{2,}/i.test(val) ||
            /[bcdfghjklmnpqrstvwxz]{5,}/i.test(val) ||
            /(qwerty|asdf|zxcv|hjkl)/i.test(val)
        ) {
            msg = 'Please enter a real full name.';
        } else {
            const hasInvalidWord = words.some(w => {
                if (validSuffixes.test(w)) return false;
                if (/^[a-zA-ZÀ-ÿ]\.$/.test(w)) return false;
                const lettersOnly = w.replace(/[^a-zA-ZÀ-ÿ]/g, '');
                return lettersOnly.length < 2 || !/[aeiouyÀ-ÖØ-öø-ÿ]/i.test(lettersOnly);
            });
            if (hasInvalidWord) {
                msg = 'Please enter a valid first and last name.';
            }
        }

        const isValid = !msg && val.length > 0;
        byId('nameError').textContent = msg;
        nameInput.classList.toggle('invalid-input', !!msg);
        nameInput.classList.toggle('valid-input', isValid);
        return isValid;
    }

    function validateEmailField(showEmpty = true) {
        const val = emailInput.value.trim();
        let msg = '';
        if (!val) { if (showEmpty) msg = 'Please enter your email address.'; }
        else if (!val.includes('@')) msg = 'Email must contain an @ symbol.';
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(val)) msg = 'Invalid email format (e.g. name@email.com).';
        byId('emailError').textContent = msg;
        emailInput.classList.toggle('invalid-input', !!msg);
        emailInput.classList.toggle('valid-input', !msg && val.length > 0);
        return !msg && val.length > 0;
    }

    function validatePhoneField(showEmpty = true) {
        phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 12);
        const val = phoneInput.value.trim();
        let msg = '';
        if (!val) { if (showEmpty) msg = 'Please enter your phone number.'; }
        else if (!/^(09|639)/.test(val)) msg = 'Phone number must start with 09 or 639.';
        else if (!/^(09\d{9}|639\d{9})$/.test(val)) msg = 'Enter 11 digits (09XXXXXXXXX) or 12 digits (639XXXXXXXXX).';
        byId('phoneError').textContent = msg;
        phoneInput.classList.toggle('invalid-input', !!msg);
        phoneInput.classList.toggle('valid-input', !msg && val.length > 0);
        return !msg && val.length > 0;
    }

    nameInput.addEventListener('input', () => {
        nameTouched = true;
        validateNameField(false);
    });
    nameInput.addEventListener('blur', () => {
        nameTouched = true;
        validateNameField(true);
    });
    emailInput.addEventListener('input', () => validateEmailField(false));
    emailInput.addEventListener('blur', () => validateEmailField(true));
    phoneInput.addEventListener('input', () => validatePhoneField(false));
    phoneInput.addEventListener('blur', () => validatePhoneField(true));

    /* ---------- Wizard Validation & Navigation ---------- */
    function isStepValid(step) {
        if (step === 1) {
            const gCount = parseInt(guestsSelect.value, 10) || 1;
            const roomCards = document.querySelectorAll('.room-card');
            if (roomCards.length && !getSelectedRoomCard()) {
                byId('roomError').textContent = 'Please select a room for your party.';
                return false;
            }
            byId('roomError').textContent = '';

            let totalSelected = 0;
            for (let g = 1; g <= gCount; g++) {
                totalSelected += (guestPicks[g] ? guestPicks[g].size : 0);
            }
            if (totalSelected === 0) {
                byId('serviceError').textContent = activeCards.size > 0
                    ? 'Please choose which guest(s) will avail your selected treatment.'
                    : 'Please select at least one treatment or package.';
                return false;
            }
            byId('serviceError').textContent = '';
            return true;
        }
        if (step === 2) {
            if (!dateInput.value) { byId('dateError').textContent = 'Please choose an appointment date.'; return false; }
            if (!document.querySelector('input[name="appointment_time"]:checked')) { byId('timeError').textContent = 'Please select an available time.'; return false; }
            return true;
        }
        if (step === 3) {
            nameTouched = true;
            const nameOk = validateNameField(true);
            const emailOk = validateEmailField(true);
            const phoneOk = validatePhoneField(true);
            if (!nameOk) { nameInput.focus(); return false; }
            if (!emailOk) { emailInput.focus(); return false; }
            if (!phoneOk) { phoneInput.focus(); return false; }
            return true;
        }
        if (step === 4) {
            const m = paymentMethodInput.value;
            if (!m) { byId('methodError').textContent = 'Please select a payment method.'; return false; }
            if (['ewallet', 'bank'].includes(m)) {
                const prov = Array.from(document.querySelectorAll('.prov-row input')).find(r => r.checked && r.dataset.method === m);
                if (!prov) { byId('provError_' + m).textContent = 'Please choose a provider.'; return false; }
                if (!/^[A-Za-z0-9-]{6,25}$/.test(referenceInput.value.trim())) {
                    byId('refError').textContent = 'Enter valid reference number (6–25 chars).';
                    referenceInput.focus();
                    return false;
                }
                if (!proofInput.files.length) { byId('proofError').textContent = 'Please upload proof of payment.'; return false; }
            }
            return true;
        }
        return true;
    }

    function goToStep(step) {
        currentStep = step;
        document.querySelectorAll('.step-panel').forEach(p => p.classList.toggle('active', +p.dataset.step === step));
        document.querySelectorAll('#stepper li').forEach(li => {
            li.classList.toggle('active', +li.dataset.step === step);
            li.classList.toggle('done', +li.dataset.step < step);
        });
        byId('stepNav').classList.toggle('is-compact', step >= 3);
        byId('backBtn').style.display = step > 1 ? '' : 'none';
        byId('nextBtn').style.display = step < 5 ? '' : 'none';
        byId('submitBtn').style.display = step === 5 ? '' : 'none';
        if (step === 5) {
            byId('nextPay').textContent = paymentMethodInput.value === 'cash'
                ? 'Pay the full amount at the store upon arrival.'
                : 'Our team will verify your payment and reference number.';
            const gCount = parseInt(guestsSelect.value, 10) || 1;
            const summaryLines = [];
            for (let g = 1; g <= gCount; g++) {
                const names = Array.from(guestPicks[g] || []).map(k => document.querySelector(`.treatment-card[data-key="${k}"]`)?.dataset.name).filter(Boolean);
                if (names.length) {
                    summaryLines.push(gCount > 1
                        ? `<strong>Guest ${g}:</strong> ${escapeHtml(names.join(', '))}`
                        : escapeHtml(names.join(', ')));
                }
            }
            const addons = getSelectedAddons().map(cb => escapeHtml(cb.dataset.name));
            const rows = [
                { label: 'Name', value: escapeHtml(nameInput.value.trim()) },
                { label: 'Email', value: escapeHtml(emailInput.value.trim()) },
                { label: 'Phone', value: escapeHtml(phoneInput.value.trim()) },
                { label: 'Room', value: escapeHtml(byId('sumRoom').textContent) },
                { label: 'Guests', value: escapeHtml(byId('sumGuests').textContent) },
                { label: 'Treatments', value: summaryLines.join('<br>'), multiline: gCount > 1 || summaryLines.join('').length > 40 },
                ...(addons.length ? [{ label: 'Add-ons', value: addons.join('<br>'), multiline: addons.length > 1 }] : []),
                { label: 'Date & Time', value: escapeHtml(byId('sumDate').textContent + ' · ' + byId('sumTime').textContent) },
                { label: 'Payment', value: escapeHtml(byId('sumPayment').textContent) },
                { label: 'Estimated Total', value: escapeHtml(byId('totalPrice').textContent) },
                { label: 'Pay Now', value: escapeHtml(byId('sumDue').textContent) },
                { label: 'Pay at the store', value: escapeHtml(byId('sumBalance').textContent) }
            ];
            byId('reviewBox').innerHTML = rows
                .map(r => `<div><dt>${r.label}</dt><dd${r.multiline ? ' class="is-multiline"' : ''}>${r.value}</dd></div>`)
                .join('');
        }
        byId('stepper').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    byId('bookingForm').addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit') {
            e.preventDefault();
            if (currentStep < 5 && isStepValid(currentStep)) {
                goToStep(currentStep + 1);
            }
        }
    });

    byId('bookingForm').addEventListener('submit', e => {
        for (let s = 1; s <= 4; s++) {
            if (!isStepValid(s)) {
                e.preventDefault();
                goToStep(s);
                return;
            }
        }
        if (!byId('terms').checked) {
            e.preventDefault();
            byId('termsError').textContent = 'Please agree to the booking policies above.';
        }
    });

    byId('nextBtn').addEventListener('click', () => { if (isStepValid(currentStep)) goToStep(currentStep + 1); });
    byId('backBtn').addEventListener('click', () => goToStep(currentStep - 1));
    guestsSelect.addEventListener('change', () => { updateRoomAvailability(); updateSummary(); });

    /* ---------- Restore Previous Inputs & Jump to Error Step ---------- */
    async function restoreOldBookingState() {
        const old = config.oldForm || {};
        if (!old || Object.keys(old).length === 0) return;

        if (old.guests) guestsSelect.value = String(old.guests);
        updateRoomAvailability();
        if (old.room_id) {
            const roomRadio = document.querySelector(`.room-card input[name="room_id"][value="${old.room_id}"]`);
            if (roomRadio && !roomRadio.disabled) {
                roomRadio.checked = true;
                updateRoomAvailability();
            }
        }

        if (old.guest_items && typeof old.guest_items === 'object') {
            activeCards.clear();
            Object.keys(guestPicks).forEach(k => delete guestPicks[k]);
            Object.entries(old.guest_items).forEach(([g, keys]) => {
                const gNum = parseInt(g, 10);
                if (!guestPicks[gNum]) guestPicks[gNum] = new Set();
                (keys || []).forEach(key => {
                    guestPicks[gNum].add(key);
                    activeCards.add(key);
                });
            });
        }

        if (Array.isArray(old.addon_ids)) {
            old.addon_ids.forEach(id => {
                const cb = document.querySelector(`#addonMenu input[type="checkbox"][value="${id}"]`);
                if (cb) cb.checked = true;
            });
        }

        if (old.customer_name !== undefined) nameInput.value = old.customer_name;
        if (old.email !== undefined) emailInput.value = old.email;
        if (old.phone !== undefined) phoneInput.value = old.phone;
        if (old.notes !== undefined) notesInput.value = old.notes;

        if (old.payment_method) {
            const opt = payOptions.querySelector(`.pay-option[data-value="${old.payment_method}"]`);
            if (opt) {
                paymentMethodInput.value = opt.dataset.value;
                byId('payTriggerText').textContent = opt.dataset.label;
                payTrigger.classList.add('has-value');
            }
            if (old.payment_provider) {
                const provRadio = document.querySelector(`.prov-row input[data-method="${old.payment_method}"][value="${old.payment_provider}"]`);
                if (provRadio) provRadio.checked = true;
            }
            if (old.reference_no) referenceInput.value = old.reference_no;
        }

        updateSummary();

        if (old.appointment_date) {
            const dObj = dateFromISO(old.appointment_date);
            if (!isNaN(dObj.getTime())) {
                visibleMonth.setFullYear(dObj.getFullYear(), dObj.getMonth(), 1);
                dateInput.value = old.appointment_date;
                renderCalendar();
                byId('sumDate').textContent = dObj.toLocaleDateString('en-PH', { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
                byId('selectedDateLabel').textContent = dObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

                let booked = [];
                try { booked = (await (await fetch(config.slotsUrl + encodeURIComponent(dateInput.value))).json()).booked || []; } catch (err) {}
                byId('timeHint').textContent = '';
                byId('timeGrid').innerHTML = config.timeSlots.map(t => {
                    let state = booked.includes(t) ? 'booked' : 'available';
                    if (state === 'available' && dateInput.value === todayISO()) {
                        const [hh, mm] = t.split(':').map(Number); const now = new Date();
                        if (hh * 60 + mm <= now.getHours() * 60 + now.getMinutes()) state = 'passed';
                    }
                    return `<label class="time-slot is-${state}"><input type="radio" name="appointment_time" value="${t}" ${state !== 'available' ? 'disabled' : ''}><span>${formatTime(t)}${state !== 'available' ? `<em>${state}</em>` : ''}</span></label>`;
                }).join('');

                if (old.appointment_time) {
                    const tRadio = document.querySelector(`input[name="appointment_time"][value="${old.appointment_time}"]`);
                    if (tRadio && !tRadio.disabled) {
                        tRadio.checked = true;
                        byId('sumTime').textContent = formatTime(old.appointment_time);
                    }
                }
            }
        }

        const targetStep = Number(config.initialStep) || 1;
        if (targetStep > 1) {
            goToStep(targetStep);
        }
        const fieldMap = {
            customer_name: [nameInput, 'nameError'],
            email: [emailInput, 'emailError'],
            phone: [phoneInput, 'phoneError'],
            reference_no: [referenceInput, 'refError'],
            appointment_date: [null, 'dateError'],
            appointment_time: [null, 'timeError'],
            payment_method: [null, 'methodError'],
            payment_provider: [null, 'provError_' + (old.payment_method || '')],
            proof_image: [null, 'proofError'],
            agree_terms: [null, 'termsError']
        };
        const errTarget = fieldMap[config.errorField];
        if (errTarget) {
            const [el, errId] = errTarget;
            if (el) {
                el.classList.add('invalid-input');
                el.focus();
            }
            const errBox = byId(errId);
            const topAlert = document.querySelector('.alert.error');
            if (errBox && topAlert) errBox.textContent = topAlert.textContent;
        }
    }

    fillCalendarSelects();
    renderCalendar();
    document.querySelectorAll('.choice-list').forEach(createSlider);
    updateRoomAvailability();
    updateSummary();
    restoreOldBookingState();
})();
</script>
</body>
</html>
