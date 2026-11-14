<?php
require_once 'config.php';

/* ---------- Payment settings (edit these to your real details) ---------- */
$downPayment = ['cash' => 0.10, 'ewallet' => 0.30, 'bank' => 0.30];   // down payment per method
$paymentMethods = [
    'cash' => [
        'label' => 'Cash', 'icon' => '₱',
        'hint' => (int)round($downPayment['cash'] * 100) . '% down payment · balance paid at the spa',
        'note' => 'A ' . (int)round($downPayment['cash'] * 100) . '% down payment secures your slot. Our team will contact you to arrange it, and the balance is paid in cash on the day of your appointment.',
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

/* ---------- Booking policies (edit to match your spa's real rules) ---------- */
$policies = [
    ['Operating days & hours', 'Open Tuesday to Sunday, 9:00 AM to 5:30 PM. The spa is closed on Mondays.'],
    ['Confirmation', 'Your request stays pending until our team confirms it by email or SMS. One booking can reserve a time slot.'],
    ['Down payment', 'A down payment secures your slot: ' . (int)round($downPayment['cash'] * 100) . '% for cash, ' . (int)round($downPayment['ewallet'] * 100) . '% for e-wallet and online banking. The balance is paid at the spa.'],
    ['Cancellation & rescheduling', 'Cancel or reschedule at least 24 hours before your appointment. Later cancellations and no-shows forfeit the down payment.'],
    ['Grace period', 'We hold your slot for 15 minutes after your appointment time. After that, the treatment may be shortened or the slot released.'],
    ['Guests & extra fees', 'Up to ' . 6 . ' guests per booking. The first guest pays the full price; each additional guest pays 80% of each treatment price.'],
    ['Health & safety', 'Please tell us about allergies, injuries, pregnancy or medical conditions in the notes so we can care for you properly.'],
];

// Services: use description/image columns if they exist, otherwise fall back to the basic columns.
try {
    $services = $pdo->query('SELECT id, name, duration_minutes, price, description, image FROM services WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    $services = $pdo->query('SELECT id, name, duration_minutes, price FROM services WHERE active = 1 ORDER BY name')->fetchAll();
}

// Packages (optional table). If it doesn't exist yet, the tab is simply hidden.
try {
    $packages = $pdo->query('SELECT id, name, description, duration_minutes, price, image FROM packages WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    try {
        $packages = $pdo->query('SELECT id, name, description, duration_minutes, price FROM packages WHERE active = 1 ORDER BY name')->fetchAll();
    } catch (Throwable $ex2) {
        $packages = [];
    }
}

/*
 * Picture for a service/package. Order:
 *  1) the `image` column (a path like assets/images/massage.jpg, or a full URL)
 *  2) a file named after the id: assets/images/services/{id}.jpg|jpeg|png|webp
 *     (packages: assets/images/packages/{id}.jpg ...)
 * Returns '' when there is none (a soft placeholder is shown instead).
 */
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
$error = $_SESSION['booking_error'] ?? '';
unset($_SESSION['booking_error']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book an Appointment | Bella Vista Oasis Spa</title>
<link rel="stylesheet" href="assets/style.css">
<style>
label .field-error, .field-error { display:block; color:#b83232 !important; font-size:12px !important; margin-top:5px; min-height:0; }
.invalid-input { border-color:#b83232 !important; background:#fdf1f1 !important; box-shadow:0 0 0 3px rgba(184,50,50,.12) !important; }
.valid-input { border-color:#648568 !important; box-shadow:0 0 0 3px rgba(100,133,104,.12) !important; }

.choice-tabs { display:flex; gap:8px; margin:10px 0 14px; border-bottom:1px solid #e4d9cb; }
.choice-tab { background:none; border:0; padding:10px 16px; font:inherit; font-size:14px; color:#7a6a5c; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; }
.choice-tab.active { color:#5a4030; border-bottom-color:#5a4030; font-weight:600; }
.choice-tab .count { display:none; margin-left:6px; background:#648568; color:#fff; border-radius:999px; font-size:11px; padding:1px 7px; }
.choice-tab .count.show { display:inline-block; }
.choice-panel { display:none; }
.choice-panel.active { display:block; }
.choice-list { display:grid; grid-template-columns:1fr; gap:12px; }
.choice-card { position:relative; display:flex; align-items:stretch; gap:16px; padding:12px; border:1px solid #e4d9cb; border-radius:22px; background:#fffdf9; cursor:pointer; font-weight:400; transition:box-shadow .15s, border-color .15s; }
.choice-card:hover { box-shadow:0 6px 16px rgba(90,64,48,.10); }
.choice-card input { position:absolute; opacity:0; pointer-events:none; }
.choice-thumb { flex:0 0 118px; height:118px; border-radius:20px 44px 44px 20px; overflow:hidden; background:linear-gradient(135deg,#efe3d2,#d9c7ae); display:flex; align-items:center; justify-content:center; color:#8b6f55; font-size:30px; }
.choice-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.choice-body { flex:1; min-width:0; display:flex; flex-direction:column; }
.choice-card .choice-badge { position:absolute; top:12px; right:14px; background:#5a4030; color:#fff; font-size:9px; letter-spacing:.08em; text-transform:uppercase; padding:3px 8px; border-radius:999px; z-index:1; }
.choice-card .choice-info strong { display:block; font-size:17px; line-height:1.25; font-family:inherit; padding-right:60px; }
.choice-card .choice-info small { display:block; color:#7a6a5c; font-size:12px; margin-top:3px; line-height:1.4; }
.choice-card .choice-info small.desc { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; color:#9a8b7c; }
.choice-card .choice-meta { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:auto; padding-top:8px; }
.choice-card .choice-price { font-weight:600; font-size:16px; color:#5a4030; }
.choice-card .choice-btn { font-size:12px; padding:6px 14px; border-radius:999px; border:1px solid #5a4030; color:#5a4030; background:#f1e8dc; white-space:nowrap; }
.choice-card .choice-btn .on { display:none; }
.choice-card.selected { border-color:#648568; background:#f1f5ef; box-shadow:0 0 0 1px #648568 inset; }
.choice-card.selected .choice-btn { background:#648568; border-color:#648568; color:#fff; }
.choice-card.selected .choice-btn .on { display:inline; }
.choice-card.selected .choice-btn .off { display:none; }
.choice-card input:focus-visible ~ .choice-body .choice-btn { outline:2px solid #648568; outline-offset:2px; }
@media (max-width:480px) { .choice-thumb { flex-basis:86px; height:86px; } .choice-card .choice-info strong { font-size:15px; } }
.group-title { font-size:13px; font-weight:600; margin-top:6px; }

.slot-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:8px; margin-top:8px; }
.slot { position:relative; }
.slot input { position:absolute; opacity:0; pointer-events:none; }
.slot span { display:flex; flex-direction:column; align-items:center; gap:2px; padding:9px 6px; border-radius:12px; border:1px solid #e4d9cb; background:#fffdf9; cursor:pointer; transition:all .15s; }
.slot span b { font-size:14px; font-weight:600; color:#4a3628; }
.slot span em { font-style:normal; font-size:11px; display:flex; align-items:center; gap:5px; }
.slot span em::before { content:""; width:7px; height:7px; border-radius:50%; background:currentColor; }
.slot.available span em { color:#648568; }
.slot.available span:hover { border-color:#648568; background:#f1f5ef; }
.slot.available input:checked + span { background:#648568; border-color:#648568; }
.slot.available input:checked + span b, .slot.available input:checked + span em { color:#fff; }
.slot.booked span { background:#faf0ef; border-color:#efd5d2; cursor:not-allowed; }
.slot.booked span b { color:#b98a86; }
.slot.booked span em { color:#b83232; }
.slot.passed span { background:#f3f1ee; border-color:#e6e1da; cursor:not-allowed; }
.slot.passed span b { color:#aaa39a; }
.slot.passed span em { color:#aaa39a; }
.slot-hint { font-size:12px; color:#7a6a5c; }

.breakdown { width:100%; margin-top:10px; font-size:13px; }
.breakdown .row { display:flex; justify-content:space-between; gap:12px; padding:4px 0; }
.breakdown .row.sub { color:#7a6a5c; padding-left:12px; font-size:12px; }
.breakdown .row.line { border-top:1px dashed #cdbfae; margin-top:6px; padding-top:8px; font-weight:600; }
.breakdown .empty { color:#7a6a5c; }

/* ---------- Stepper ---------- */
.stepper { list-style:none; display:flex; flex-wrap:wrap; align-items:center; gap:4px 0; margin:16px 0 22px; padding:12px 16px; background:#f1e8dc; border-radius:14px; }
.stepper li { display:flex; align-items:center; gap:8px; font-size:13px; color:#a09283; cursor:default; }
.stepper li:not(:last-child)::after { content:"›"; margin:0 12px; color:#b8aa9a; font-size:16px; }
.stepper .num { width:22px; height:22px; border-radius:50%; border:1px solid #c9bba9; display:inline-flex; align-items:center; justify-content:center; font-size:11px; background:#fffdf9; }
.stepper li.active { color:#3f2d20; font-weight:600; }
.stepper li.active .num { background:#5a4030; border-color:#5a4030; color:#fff; }
.stepper li.done { color:#648568; cursor:pointer; }
.stepper li.done .num { background:#648568; border-color:#648568; color:#fff; }

/* ---------- Layout: steps + summary ---------- */
.booking-cols { display:grid; grid-template-columns:minmax(0,1fr) 290px; gap:24px; align-items:start; }
.step-panel { display:none; }
.step-panel.active { display:block; }
.step-head { margin-bottom:14px; }
.step-head h3 { margin:2px 0 4px; font-size:20px; }
.step-head p { margin:0; font-size:13px; color:#7a6a5c; }
.step-nav { display:flex; justify-content:space-between; gap:10px; margin-top:20px; }
.step-nav .button { width:auto; min-width:130px; }
.btn-back { background:transparent; border:1px solid #5a4030; color:#5a4030; border-radius:999px; padding:12px 22px; font:inherit; font-size:14px; cursor:pointer; }
.guest-row { margin-bottom:18px; max-width:260px; }

/* ---------- Booking summary ---------- */
.summary-card { position:sticky; top:20px; background:#fbf6ee; border:1px solid #eadfce; border-radius:20px; padding:18px; }
.summary-card h4 { margin:0 0 12px; font-size:16px; }
.summary-box { background:#efebe6; border-radius:12px; padding:12px 14px; min-height:60px; }
.summary-meta { margin:12px 0 0; font-size:13px; }
.summary-meta div { display:flex; justify-content:space-between; gap:10px; padding:5px 0; border-bottom:1px dashed #e0d4c3; }
.summary-meta dt { color:#7a6a5c; }
.summary-meta dd { margin:0; font-weight:600; text-align:right; }
.summary-total { display:flex; justify-content:space-between; align-items:baseline; margin-top:12px; }
.summary-total strong { font-size:22px; color:#5a4030; }
.summary-card .breakdown { margin-top:0; }

/* ---------- Review (step 4) ---------- */
.review-box { border:1px solid #e4d9cb; border-radius:16px; background:#fffdf9; padding:6px 16px; margin-bottom:14px; }
.review-box div { display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px solid #f0e8dc; font-size:14px; }
.review-box div:last-child { border-bottom:0; }
.review-box dt { color:#7a6a5c; }
.review-box dd { margin:0; font-weight:600; text-align:right; word-break:break-word; }

@media (max-width:1200px) {
    .booking-cols { grid-template-columns:1fr; }
    .summary-card { position:static; }
}
@media (max-width:640px) {
    .stepper li:not(.active) .lbl { display:none; }
    .stepper li:not(:last-child)::after { margin:0 6px; }
}

/* ---------- Spacing (less crowded) ---------- */
.stepper { margin:22px 0 32px; padding:14px 20px; }
.booking-cols { gap:36px; }
.step-head { margin-bottom:26px; }
.step-head h3 { font-size:22px; margin:0; }
.step-panel .form-grid { gap:24px; }
.guest-row { margin:0 0 32px; max-width:100%; }
.choice-tabs { margin:0 0 22px; }
.choice-tab { padding:12px 20px; }
.choice-list { gap:18px; }
.choice-card { padding:16px; gap:20px; border-radius:24px; }
.choice-thumb { flex:0 0 108px; height:108px; }
.choice-card .choice-info small { margin-top:5px; }
.choice-card .choice-meta { padding-top:14px; }
.slot-grid { gap:14px; margin-top:12px; }
.slot span { padding:12px 8px; gap:4px; }
.group-title { margin-bottom:4px; }
.slot-hint { display:block; margin-top:10px; }
.step-nav { margin-top:36px; }
.summary-card { padding:24px; }
.summary-card h4 { margin-bottom:16px; }
.summary-box { padding:16px; }
.summary-meta { margin-top:16px; }
.summary-meta div { padding:9px 0; }
.summary-total { margin-top:18px; }
.breakdown .row { padding:5px 0; }
.breakdown .row.sub { padding:2px 0 2px 12px; }
.breakdown .row.line { margin-top:10px; }
.review-box { padding:8px 20px; margin-bottom:24px; }
.review-box div { padding:13px 0; }

.summary-box .row { align-items:baseline; }
.summary-box .row > span:first-child { flex:1; min-width:0; }
.summary-box .row > span:last-child { white-space:nowrap; font-weight:600; }
.summary-box .row.sub > span:last-child { font-weight:400; }
.summary-box .row:not(.sub):not(:first-child) { margin-top:10px; padding-top:10px; border-top:1px dashed #d8cdbd; }
.summary-box .row.line > span:last-child { font-weight:700; }

/* ---------- Time slots: clean version ---------- */
.slot-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:12px; }
.slot-grid .slot { display:block; position:relative; margin:0; padding:0; background:none; border:0; border-radius:0; box-shadow:none; }
.slot-grid .slot input { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.slot-grid .slot > span { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; padding:13px 8px; border:1px solid #e4d9cb; border-radius:12px; background:#fffdf9; box-shadow:none; cursor:pointer; text-decoration:none; transition:border-color .15s, background .15s; }
.slot-grid .slot > span b { font-size:15px; font-weight:600; color:#4a3628; }
.slot-grid .slot > span em { display:block; font-style:normal; font-size:11px; letter-spacing:.02em; color:#8b7a6a; }
.slot-grid .slot > span em::before { content:none; display:none; }
.slot-grid .slot.available > span em { color:#648568; }
.slot-grid .slot.available > span:hover { border-color:#5a4030; }
.slot-grid .slot.available input:checked + span { background:#5a4030; border-color:#5a4030; }
.slot-grid .slot.available input:checked + span b,
.slot-grid .slot.available input:checked + span em { color:#fff; }
.slot-grid .slot.booked > span { background:#f4efe9; border-color:#ece3d6; cursor:not-allowed; }
.slot-grid .slot.booked > span b { color:#a79a8c; }
.slot-grid .slot.booked > span em { color:#b83232; }
.slot-grid .slot.passed > span { background:#f4efe9; border-color:#ece3d6; cursor:not-allowed; }
.slot-grid .slot.passed > span b, .slot-grid .slot.passed > span em { color:#b5aca1; }
@media (max-width:480px) { .slot-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }

/* ---------- Payment ---------- */
.pay-icon { flex:0 0 40px; height:40px; border-radius:50%; background:#f1e8dc; color:#5a4030; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:16px; }
.pay-text strong { display:block; font-size:15px; line-height:1.25; font-family:inherit; }
.pay-text small { display:block; margin-top:3px; font-size:12px; color:#8b7a6a; }

.pay-box { border:1px solid #e4d9cb; border-radius:20px; background:#fffdf9; padding:22px; }
.pay-amounts { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.pay-amounts div { background:#f6f0e7; border-radius:14px; padding:14px 16px; }
.pay-amounts span { display:block; font-size:12px; color:#8b7a6a; }
.pay-amounts strong { display:block; margin-top:4px; font-size:20px; color:#5a4030; }
.pay-note { margin:14px 2px 0; font-size:13px; color:#7a6a5c; }
.pay-online { margin-top:22px; padding-top:22px; border-top:1px dashed #e0d4c3; }
.pay-online[hidden], .pay-detail[hidden] { display:none; }
.pay-detail { display:flex; align-items:center; gap:20px; margin-bottom:22px; }
.pay-qr { width:120px; height:120px; object-fit:contain; border-radius:12px; border:1px solid #e4d9cb; background:#fff; padding:6px; }
.pay-detail dl { flex:1; margin:0; }
.pay-detail dl > div { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:9px 0; border-bottom:1px solid #f0e8dc; font-size:14px; }
.pay-detail dl > div:last-child { border-bottom:0; }
.pay-detail dt { color:#8b7a6a; }
.pay-detail dd { margin:0; font-weight:600; display:flex; align-items:center; gap:10px; text-align:right; }
.copy-btn { font:inherit; font-size:11px; padding:4px 12px; border-radius:999px; border:1px solid #5a4030; background:transparent; color:#5a4030; cursor:pointer; }
.copy-btn.copied { background:#648568; border-color:#648568; color:#fff; }
.file-drop { display:block; text-align:center; padding:22px 16px; border:1.5px dashed #cdbfae; border-radius:16px; background:#fbf6ee; cursor:pointer; font-weight:400; transition:border-color .15s, background .15s; }
.file-drop:hover { border-color:#5a4030; }
.file-drop input { position:absolute; opacity:0; width:0; height:0; pointer-events:none; }
.file-drop .file-title { display:block; font-size:14px; font-weight:600; color:#4a3628; }
.file-drop .file-sub { display:block; margin-top:4px; font-size:12px; color:#8b7a6a; }
.file-drop.has-file { border-style:solid; border-color:#648568; background:#f1f5ef; }
.file-drop.invalid { border-color:#b83232; background:#fdf1f1; }
@media (max-width:560px) { .pay-methods, .pay-amounts { grid-template-columns:1fr; } .pay-detail { flex-direction:column; align-items:stretch; } }

.summary-split { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:16px; }
.summary-split div { background:#efebe6; border-radius:12px; padding:10px 12px; }
.summary-split span { display:block; font-size:11px; color:#8b7a6a; }
.summary-split strong { display:block; margin-top:2px; font-size:15px; color:#5a4030; }

/* ---------- Payment dropdown ---------- */
.pay-select { position:relative; margin-bottom:24px; }
.pay-label { display:block; font-size:13px; font-weight:600; margin-bottom:8px; }
.pay-trigger { width:100%; display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; border:1px solid #d9cfc2; border-radius:999px; background:#fffdf9; font:inherit; font-size:15px; color:#8b7a6a; cursor:pointer; text-align:left; transition:border-color .15s, box-shadow .15s; }
.pay-trigger:hover, .pay-trigger[aria-expanded="true"] { border-color:#5a4030; }
.pay-trigger.has-value { color:#3f2d20; font-weight:600; }
.pay-trigger.invalid { border-color:#b83232; background:#fdf1f1; }
.pay-caret { font-size:14px; color:#5a4030; transition:transform .2s; }
.pay-trigger[aria-expanded="true"] .pay-caret { transform:rotate(180deg); }
.pay-options { position:absolute; left:0; right:0; top:calc(100% - 2px); z-index:30; margin:0; padding:8px; list-style:none; background:#fffdf9; border:1px solid #e4d9cb; border-radius:20px; box-shadow:0 14px 30px rgba(63,45,32,.16); }
.pay-options[hidden] { display:none; }
.pay-select .field-error { margin-left:20px; }
.pay-option { display:flex; align-items:center; gap:14px; padding:12px 14px; border-radius:14px; cursor:pointer; outline:none; }
.pay-option:hover, .pay-option:focus-visible { background:#f6f0e7; }
.pay-option.selected { background:#f1f5ef; }
.pay-option.selected .pay-icon { background:#648568; color:#fff; }
.pay-box[hidden] { display:none; }
.pay-detail { display:block; margin:22px 0 0; padding-top:22px; border-top:1px dashed #e0d4c3; }
.pay-detail[hidden] { display:none; }
.pay-text-block { margin:0; font-size:14px; line-height:1.6; color:#6b5a4b; }
.wallet-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin-bottom:24px; }
.wallet-card { display:flex; flex-direction:column; align-items:center; gap:14px; padding:20px 16px; border:1px solid #e4d9cb; border-radius:18px; background:#fbf6ee; }
.wallet-title { font-size:15px; font-family:inherit; }
.wallet-card .pay-qr { width:150px; height:150px; padding:8px; }
.pay-qr.placeholder { display:flex; align-items:center; justify-content:center; border:1.5px dashed #cdbfae; border-radius:12px; background:#fff; color:#a79a8c; font-size:13px; }
.wallet-card dl { width:100%; margin:0; }
.wallet-card dl > div { flex-wrap:wrap; }
@media (max-width:560px) { .wallet-grid { grid-template-columns:1fr; } }

/* ================= Slimmer left panel ================= */
.booking-layout { display:grid !important; grid-template-columns:300px minmax(0,1fr) !important; }
.booking-aside { width:auto !important; max-width:none !important; min-width:0 !important; padding:40px 28px !important; }
.booking-aside h1 { font-size:clamp(30px,2.3vw,40px) !important; line-height:1.12 !important; }
.booking-aside > p { font-size:14px !important; line-height:1.65 !important; }
.booking-aside .aside-detail strong { font-size:17px !important; }
.booking-aside .aside-detail small { font-size:12px !important; }
.booking-form-wrap { padding-left:clamp(24px,4vw,64px) !important; padding-right:clamp(24px,4vw,64px) !important; max-width:none !important; }
.booking-cols { grid-template-columns:minmax(0,1fr) 320px; }
@media (max-width:1100px) { .booking-cols { grid-template-columns:1fr; } }
@media (max-width:900px) { .booking-layout { grid-template-columns:1fr !important; } }

/* ================= Service cards: arch photo, scrollable list ================= */
.choice-list { grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:22px; max-height:min(72vh,780px); overflow-y:auto; padding:14px 14px 18px; margin:0 -14px; scrollbar-width:thin; scrollbar-color:#cdbfae transparent; }
.choice-list::-webkit-scrollbar { width:8px; }
.choice-list::-webkit-scrollbar-thumb { background:#cdbfae; border-radius:999px; }
.choice-card { flex-direction:column; align-items:stretch; gap:0; padding:14px 14px 18px; border-radius:28px; background:#fffdf9; transition:transform .3s ease, box-shadow .3s ease, border-color .2s; }
.choice-card:hover { transform:translateY(-4px) scale(1.03); box-shadow:0 16px 30px rgba(90,64,48,.16); z-index:2; }
.choice-thumb { flex:none; width:100%; height:auto; aspect-ratio:1 / 1.12; border-radius:50% 50% 16px 16px / 40% 40% 16px 16px; overflow:hidden; background:linear-gradient(160deg,#f1e6d6,#d8c4a8); }
.choice-thumb img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .6s ease; }
.choice-card:hover .choice-thumb img { transform:scale(1.08); }
.thumb-ph { font-size:44px; color:#a8896a; }
.choice-card .choice-badge { top:24px; right:24px; }
.choice-body { flex:1; display:flex; flex-direction:column; gap:8px; padding:16px 6px 0; }
.choice-name { font-family:var(--font-display, "Cormorant Garamond", Georgia, serif); font-size:21px; font-weight:600; line-height:1.2; color:#3f2d20; padding:0; }
.choice-desc { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; font-size:13px; line-height:1.55; color:#7a6a5c; min-height:4.65em; }
.choice-facts { display:flex; justify-content:space-between; align-items:center; gap:10px; padding-top:10px; border-top:1px dashed #e0d4c3; }
.choice-facts .fact { font-size:13px; color:#6b5a4b; }
.choice-facts .fact.price { font-size:17px; font-weight:700; color:#5a4030; }
.choice-card .choice-btn { display:block; text-align:center; margin-top:auto; padding:10px 14px; font-size:11px; letter-spacing:.1em; text-transform:uppercase; border-radius:999px; background:#f1e8dc; }
.choice-card:hover .choice-btn { background:#5a4030; color:#fff; }
.choice-card.selected .choice-btn, .choice-card.selected:hover .choice-btn { background:#648568; border-color:#648568; color:#fff; }
@media (prefers-reduced-motion:reduce) { .choice-card, .choice-card:hover, .choice-thumb img { transition:none; transform:none !important; } }

/* ================= Payment providers (wallet / bank) ================= */
.pay-sub { font-size:13px; font-weight:600; margin-bottom:12px; }
.prov-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px; }
.prov-row { position:relative; display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid #e4d9cb; border-radius:16px; background:#fffdf9; cursor:pointer; font-weight:400; transition:border-color .15s, box-shadow .15s; }
.prov-row:hover { border-color:#5a4030; }
.prov-row input { position:absolute; opacity:0; pointer-events:none; }
.prov-logo { flex:0 0 36px; height:36px; border-radius:50%; background:#f1e8dc; color:#5a4030; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; }
.prov-name { flex:1; font-size:14px; font-weight:600; }
.prov-radio { position:relative; flex:0 0 18px; height:18px; border-radius:50%; border:1.5px solid #cdbfae; }
.prov-row.selected { border-color:#648568; background:#f1f5ef; box-shadow:0 0 0 1px #648568 inset; }
.prov-row.selected .prov-logo { background:#648568; color:#fff; }
.prov-row.selected .prov-radio { border-color:#648568; }
.prov-row.selected .prov-radio::after { content:""; position:absolute; inset:3px; border-radius:50%; background:#648568; }
.prov-panel { display:grid; grid-template-columns:170px 1fr; gap:26px; align-items:center; margin-top:18px; padding:22px; border:1px solid #e4d9cb; border-radius:22px; background:#fbf6ee; }
.prov-panel[hidden] { display:none; }
.prov-qr .pay-qr { width:170px; height:170px; padding:8px; border-radius:16px; border:1px solid #e4d9cb; background:#fff; object-fit:contain; }
.prov-qr .pay-qr.placeholder { display:flex; align-items:center; justify-content:center; border:1.5px dashed #cdbfae; color:#a79a8c; font-size:13px; }
.prov-info dl { margin:0; }
.prov-hint { margin:0 0 10px; font-size:13px; line-height:1.5; color:#6b5a4b; }
.prov-amount { display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding:12px 16px; background:#fff; border-radius:14px; font-size:13px; color:#6b5a4b; }
.prov-amount strong { font-size:20px; color:#5a4030; }
.pay-fields-wrap { margin-top:24px; padding-top:24px; border-top:1px dashed #e0d4c3; }
.pay-fields-wrap[hidden] { display:none; }
@media (max-width:640px) { .prov-panel { grid-template-columns:1fr; justify-items:center; } .prov-info { width:100%; } }

/* ---------- Policies ---------- */
.policy-box { border:1px solid #e4d9cb; border-radius:20px; background:#fffdf9; padding:22px 24px; margin-bottom:24px; }
.policy-box h4 { margin:0 0 14px; font-size:16px; }
.policy-box ul { list-style:none; margin:0; padding:0; display:grid; gap:14px; }
.policy-box li { display:grid; gap:2px; padding-left:16px; position:relative; }
.policy-box li::before { content:""; position:absolute; left:0; top:8px; width:6px; height:6px; border-radius:50%; background:#648568; }
.policy-box li strong { font-size:13px; color:#3f2d20; }
.policy-box li span { font-size:13px; line-height:1.55; color:#7a6a5c; }
</style>
</head>

<body class="booking-page">

<header class="site-header">
    <a class="brand" href="index.php">
        <span class="brand-mark">✦</span>
        <span>BELLA VISTA <small>OASIS SPA</small></span>
    </a>
    <nav class="nav-links">
        <a href="index.php">← Back to website</a>
    </nav>
</header>

<main class="booking-layout">

<aside class="booking-aside">
    <p class="eyebrow">Your time, your ritual</p>
    <h1>Make space<br>for <em>yourself.</em></h1>
    <p>Choose a treatment and a time that works for you. Our team will review your request and confirm your appointment.</p>

    <div class="aside-detail">
        <span>✦</span>
        <div>
            <strong>Mindful moments</strong>
            <small>A calmer experience begins with a little time for you.</small>
        </div>
    </div>

    <div class="aside-detail">
        <span>◷</span>
        <div>
            <strong>Plan ahead</strong>
            <small>Choose today or any upcoming date.</small>
        </div>
    </div>
</aside>

<section class="booking-form-wrap">
    <p class="eyebrow">Appointment request</p>
    <h2>Reserve your visit</h2>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <ol class="stepper" id="stepper">
        <li data-step="1" class="active"><span class="num">1</span><span class="lbl">Select Treatment</span></li>
        <li data-step="2"><span class="num">2</span><span class="lbl">Date &amp; Time</span></li>
        <li data-step="3"><span class="num">3</span><span class="lbl">Your Details</span></li>
        <li data-step="4"><span class="num">4</span><span class="lbl">Payment</span></li>
        <li data-step="5"><span class="num">5</span><span class="lbl">Confirmation</span></li>
    </ol>

    <form action="save_booking.php" method="post" id="bookingForm" enctype="multipart/form-data" novalidate>
    <div class="booking-cols">

        <div class="steps-col">

            <!-- Compatibility with existing save_booking.php: first selected service -->
            <input type="hidden" name="service_id" id="service_id" value="">

            <!-- STEP 1 -->
            <div class="step-panel active" data-step="1">
                <div class="step-head">
                    <h3>Guests &amp; treatments</h3>
                </div>
                <div class="guest-row form-grid">
                    <label>
            Number of guests
            <select name="guests" id="guests">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                    <option value="<?= $i ?>"><?= $i ?> guest<?= $i > 1 ? 's' : '' ?></option>
                <?php endfor; ?>
            </select>
        </label>
                </div>
                <div class="full">

            <?php if ($packages): ?>
            <div class="choice-tabs" role="tablist">
                <button type="button" class="choice-tab active" data-panel="panelServices">Treatments <span class="count" data-count="service"></span></button>
                <button type="button" class="choice-tab" data-panel="panelPackages">Packages <span class="count" data-count="package"></span></button>
            </div>
            <?php endif; ?>

            <div class="choice-panel active" id="panelServices">
                    <div class="choice-list">
                        <?php foreach ($services as $s):
                            $img  = item_image($s, 'services');
                            $desc = trim((string)($s['description'] ?? ''));
                            if ($desc === '') { $desc = 'A relaxing treatment designed to restore balance and leave you feeling refreshed.'; } ?>
                            <label class="choice-card">
                                <input type="checkbox" name="service_ids[]" value="<?= (int)$s['id'] ?>"
                                       data-type="service"
                                       data-name="<?= e($s['name']) ?>"
                                       data-price="<?= e((string)$s['price']) ?>"
                                       data-duration="<?= (int)$s['duration_minutes'] ?>"
                                       <?= $selectedService === (int)$s['id'] ? 'checked' : '' ?>>
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
                                    <span class="choice-btn"><span class="off">+ Add to booking</span><span class="on">✓ Added</span></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($packages): ?>
                <div class="choice-panel" id="panelPackages">
                    <div class="choice-list">
                        <?php foreach ($packages as $p):
                            $img  = item_image($p, 'packages');
                            $desc = trim((string)($p['description'] ?? ''));
                            if ($desc === '') { $desc = 'A curated bundle of treatments for a complete spa experience.'; } ?>
                            <label class="choice-card">
                                <input type="checkbox" name="package_ids[]" value="<?= (int)$p['id'] ?>"
                                       data-type="package"
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
                                    <span class="choice-btn"><span class="off">+ Add to booking</span><span class="on">✓ Added</span></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                
<small class="field-error" id="serviceError"></small>
        </div>

            </div>

            <!-- STEP 2 -->
            <div class="step-panel" data-step="2">
                <div class="step-head">
                    <h3>Choose a date &amp; time</h3>
                </div>
                <div class="form-grid">
                    <label>
                        Appointment date
                        <input type="date" name="appointment_date" id="appointment_date" required>
                        <small class="field-error" id="dateError"></small>
                    </label>
                    <div class="full">
            <div class="group-title">Preferred time</div>
            <div class="slot-grid" id="slotGrid"></div>
            <small class="slot-hint" id="slotHint">Select a date to see available times.</small>
            <small class="field-error" id="timeError"></small>
        </div>
                </div>
            </div>

            <!-- STEP 3 -->
            <div class="step-panel" data-step="3">
                <div class="step-head">
                    <h3>Your details</h3>
                </div>
                <div class="form-grid">
                    <label class="full">
                        Full name
                        <input type="text" name="customer_name" id="customer_name"
                               required maxlength="120" autocomplete="name"
                               placeholder="Enter your full name">
                        <small class="field-error" id="nameError"></small>
                    </label>

                    <label>
                        Email address
                        <input type="email" name="email" id="email"
                               required maxlength="150" autocomplete="email"
                               placeholder="example@email.com">
                        <small class="field-error" id="emailError"></small>
                    </label>

                    <label>
                        Phone number
                        <input type="tel" name="phone" id="phone"
                               required maxlength="12" autocomplete="tel"
                               inputmode="numeric" placeholder="09XXXXXXXXX or 639XXXXXXXXX">
                        <small class="field-error" id="phoneError"></small>
                    </label>

                    <label class="full">
                        Notes or preferences (optional)
                        <textarea name="notes" id="notes" rows="3" maxlength="1000"
                                  placeholder="Share any preferences or accessibility needs."></textarea>
                    </label>
                </div>
            </div>

            <!-- STEP 4: PAYMENT -->
            <div class="step-panel" data-step="4">
                <div class="step-head">
                    <h3>Payment</h3>
                </div>

                <div class="pay-select" id="paySelect">
                    <span class="pay-label">Payment method</span>
                    <input type="hidden" name="payment_method" id="payment_method" value="">
                    <input type="hidden" name="payment_provider" id="payment_provider" value="">
                    <button type="button" class="pay-trigger" id="payTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span id="payTriggerText">Select payment method</span>
                        <span class="pay-caret">▾</span>
                    </button>
                    <ul class="pay-options" id="payOptions" role="listbox" hidden>
                        <?php foreach ($paymentMethods as $key => $m): ?>
                            <li class="pay-option" role="option" tabindex="0" data-value="<?= e($key) ?>" data-label="<?= e($m['label']) ?>">
                                <span class="pay-icon"><?= e($m['icon']) ?></span>
                                <span class="pay-text">
                                    <strong><?= e($m['label']) ?></strong>
                                    <small><?= e($m['hint']) ?></small>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <small class="field-error" id="methodError"></small>
                </div>

                <div class="pay-box" id="payBox" hidden>
                    <div class="pay-amounts">
                        <div><span>Pay now</span><strong class="js-due">₱0.00</strong></div>
                        <div><span>Balance at the spa</span><strong class="js-balance">₱0.00</strong></div>
                    </div>
                    <p class="pay-note" id="payNote"></p>
                    <input type="hidden" name="amount_due" id="amount_due" value="0">

                    <!-- Cash -->
                    <div class="pay-detail" data-method="cash" hidden>
                        <p class="pay-text-block"><?= e($paymentMethods['cash']['note']) ?></p>
                    </div>

                    <!-- E-wallet / Online banking -->
                    <?php foreach (['ewallet', 'bank'] as $mk): $mm = $paymentMethods[$mk]; ?>
                        <div class="pay-detail" data-method="<?= $mk ?>" hidden>
                            <div class="pay-sub"><?= $mk === 'ewallet' ? 'Choose your e-wallet' : 'Choose your bank' ?></div>
                            <div class="prov-list">
                                <?php foreach ($mm['providers'] as $pk => $pv): ?>
                                    <label class="prov-row">
                                        <input type="radio" name="prov_<?= $mk ?>" value="<?= e($pk) ?>"
                                               data-method="<?= $mk ?>" data-name="<?= e($pv['name']) ?>">
                                        <span class="prov-logo"><?= e($pv['initial']) ?></span>
                                        <span class="prov-name"><?= e($pv['name']) ?></span>
                                        <span class="prov-radio"></span>
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
                                    <div class="prov-qr">
                                        <?php if ($qr): ?>
                                            <img class="pay-qr" src="<?= e($qr) ?>" alt="<?= e($pv['name']) ?> QR code">
                                        <?php else: ?>
                                            <div class="pay-qr placeholder">QR code</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="prov-info">
                                        <p class="prov-hint"><?= $mk === 'ewallet'
                                            ? 'Scan the QR code with your ' . e($pv['name']) . ' app, or send to the number below.'
                                            : 'Scan the QR code in your banking app, or transfer using the details below.' ?></p>
                                        <dl>
                                            <?php foreach ($pv['details'] as $d): ?>
                                                <div>
                                                    <dt><?= e($d[0]) ?></dt>
                                                    <dd>
                                                        <span><?= e($d[1]) ?></span>
                                                        <?php if ($d[2]): ?><button type="button" class="copy-btn" data-copy="<?= e($d[1]) ?>">Copy</button><?php endif; ?>
                                                    </dd>
                                                </div>
                                            <?php endforeach; ?>
                                        </dl>
                                        <div class="prov-amount"><span>Amount to send</span><strong class="js-due">₱0.00</strong></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="pay-fields-wrap" id="payFields" hidden>
                        <div class="form-grid pay-fields">
                            <label class="full">
                                Reference number
                                <input type="text" name="reference_no" id="reference_no" maxlength="25"
                                       autocomplete="off" placeholder="e.g. 1234567890123">
                                <small class="field-error" id="refError"></small>
                            </label>

                            <div class="full">
                                <label class="file-drop" id="fileDrop" for="proof_image">
                                    <span class="file-title" id="fileTitle">Upload proof of payment</span>
                                    <span class="file-sub" id="fileSub">Screenshot or receipt · JPG, PNG or PDF · max 5 MB</span>
                                    <input type="file" name="proof_image" id="proof_image" accept="image/jpeg,image/png,image/webp,application/pdf">
                                </label>
                                <small class="field-error" id="proofError"></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            
<!-- STEP 5 -->
            <div class="step-panel" data-step="5">
                <div class="step-head">
                    <h3>Confirm your request</h3>
                </div>
                <dl class="review-box" id="reviewBox"></dl>

                <div class="policy-box">
                    <h4>Booking policies</h4>
                    <ul>
                        <?php foreach ($policies as $pol): ?>
                            <li><strong><?= e($pol[0]) ?></strong><span><?= e($pol[1]) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                
                <div class="form-grid">
                    <label class="checkbox full">
                        <input type="checkbox" id="terms" name="agree_terms" value="1" required>
                        I have read and agree to the booking policies above, and understand this request is pending until confirmed.
                    </label>
                    <small class="field-error full" id="termsError"></small>

                    <button class="button button-dark full" type="submit">
                        Submit Appointment Request <span>→</span>
                    </button>
                </div>
            </div>

            <div class="step-nav">
                <button type="button" class="btn-back" id="backBtn" style="display:none;">← Back</button>
                <button type="button" class="button button-dark" id="nextBtn">Next <span>→</span></button>
            </div>
        </div>

        <aside class="summary-card">
            <h4>Booking Summary</h4>
            <div class="summary-box breakdown" id="breakdown"><div class="empty">No service selected yet</div></div>
            <dl class="summary-meta">
                <div><dt>Guests</dt><dd id="sumGuests">1 guest</dd></div>
                <div><dt>Date</dt><dd id="sumDate">—</dd></div>
                <div><dt>Time</dt><dd id="sumTime">—</dd></div>
                <div><dt>Duration</dt><dd id="sumDuration">—</dd></div>
                <div><dt>Payment</dt><dd id="sumPayment">—</dd></div>
            </dl>
            <div class="summary-total"><span>Estimated total</span><strong id="totalPrice">₱0.00</strong></div>
            <div class="summary-split">
                <div><span>Pay now</span><strong id="sumDue">₱0.00</strong></div>
                <div><span>Balance at the spa</span><strong id="sumBalance">₱0.00</strong></div>
            </div>
        </aside>

    </div>
    </form>
</section>
</main>

<footer class="footer compact-footer">
    <small>© <?= date('Y') ?> Bella Vista Oasis Spa</small>
</footer>

<script src="assets/script.js"></script>

<script>
const bookingForm = document.getElementById('bookingForm');
const nameInput = document.getElementById('customer_name');
const emailInput = document.getElementById('email');
const phoneInput = document.getElementById('phone');
const dateInput = document.getElementById('appointment_date');
const guestsInput = document.getElementById('guests');
const slotGrid = document.getElementById('slotGrid');
const slotHint = document.getElementById('slotHint');
const SLOTS = ['09:00','10:30','12:00','13:30','15:00','16:30'];
const esc = t => String(t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const peso = n => '₱' + n.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});

/* Shared error display: same style for every field */
function showError(input, errorId, message) {
    const error = document.getElementById(errorId);
    error.textContent = message;
    if (input) {
        input.classList.toggle('invalid-input', !!message);
        input.classList.toggle('valid-input', !message && input.value.length > 0);
    }
}

function validateName(force) {
    const value = nameInput.value.trim();
    let message = '';
    if (!value) {
        if (force) message = 'Please enter your full name.';
    } else if (/^\d/.test(value)) {
        message = 'Name must not start with a number.';
    } else if (!/^[a-zA-ZÀ-ÿ]/.test(value)) {
        message = 'Name must start with a letter.';
    } else if (!/^[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\s.'-]*$/.test(value)) {
        message = 'Please enter a valid name.';
    }
    showError(nameInput, 'nameError', message);
    return !message && value.length > 0;
}

function validateEmail(force) {
    const value = emailInput.value.trim();
    let message = '';
    if (!value) {
        if (force) message = 'Please enter your email address.';
    } else if (!value.includes('@')) {
        message = 'Email must contain an @ symbol.';
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
        message = 'Please enter a valid email address.';
    }
    showError(emailInput, 'emailError', message);
    return !message && value.length > 0;
}

function validatePhone(force) {
    const value = phoneInput.value;
    let message = '';
    if (!value) {
        if (force) message = 'Please enter your phone number.';
    } else {
        if (!/^(09|639)/.test(value)) {
            const possibleStart = ['0', '09', '6', '63', '639'];
            if (!possibleStart.includes(value)) {
                message = 'Phone number must start with 09 or 639.';
            }
        } else if (!/^09\d{0,9}$/.test(value) && !/^639\d{0,9}$/.test(value)) {
            message = 'Use only digits and enter a valid PH number.';
        }
        if (!message && !/^09\d{9}$/.test(value) && !/^639\d{9}$/.test(value)) {
            message = 'Enter all 11 digits (09) or 12 digits (639).';
        }
    }
    showError(phoneInput, 'phoneError', message);
    return !message && (/^09\d{9}$/.test(value) || /^639\d{9}$/.test(value));
}

nameInput.addEventListener('input', () => validateName(true));
emailInput.addEventListener('input', () => validateEmail(true));
nameInput.addEventListener('blur', () => validateName(true));
emailInput.addEventListener('blur', () => validateEmail(true));
phoneInput.addEventListener('blur', () => validatePhone(true));
phoneInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 12);
    validatePhone(true);
});

/* Date: today up to the future only */
function todayStr() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}
dateInput.min = todayStr();

function validateDate(force) {
    const v = dateInput.value;
    let message = '';
    if (!v) {
        if (force) message = 'Please choose an appointment date.';
    } else if (v < todayStr()) {
        message = 'You cannot book a past date.';
    } else if (new Date(v + 'T00:00:00').getDay() === 1) {
        message = 'The spa is closed on Mondays. Please choose Tuesday to Sunday.';
    }
    showError(dateInput, 'dateError', message);
    return !message && !!v;
}

/* Time slots with Booked / Available indicators */
function fmtTime(t) {
    const [h, m] = t.split(':').map(Number);
    return ((h % 12) || 12) + ':' + String(m).padStart(2, '0') + ' ' + (h >= 12 ? 'PM' : 'AM');
}

async function loadSlots() {
    const date = dateInput.value;
    slotGrid.innerHTML = '';
    showError(null, 'timeError', '');

    if (!date || date < todayStr() || new Date(date + 'T00:00:00').getDay() === 1) {
        slotHint.textContent = date ? 'Please choose a valid date (the spa is closed on Mondays).' : 'Choose a date to see available times.';
        return;
    }

    let booked = [];
    try {
        const res = await fetch('check_slots.php?date=' + encodeURIComponent(date));
        const data = await res.json();
        booked = data.booked || [];
        slotHint.textContent = '';
    } catch (err) {
        slotHint.textContent = 'Could not check availability right now. Please try again.';
    }

    const now = new Date();
    SLOTS.forEach(t => {
        let state = 'available', label = 'Available';
        if (booked.includes(t)) { state = 'booked'; label = 'Booked'; }
        else if (date === todayStr()) {
            const [h, m] = t.split(':').map(Number);
            if (h * 60 + m <= now.getHours() * 60 + now.getMinutes()) { state = 'passed'; label = 'Time passed'; }
        }
        const disabled = state !== 'available' ? 'disabled' : '';
        slotGrid.insertAdjacentHTML('beforeend',
            `<label class="slot ${state}">
                <input type="radio" name="appointment_time" value="${t}" ${disabled}>
                <span><b>${fmtTime(t)}</b><em>${label}</em></span>
             </label>`);
    });
}
dateInput.addEventListener('change', () => { validateDate(true); loadSlots().then(updateSummary); updateSummary(); });
slotGrid.addEventListener('change', updateSummary);

/* Services / packages + detailed breakdown */
const choiceInputs = () => Array.from(document.querySelectorAll('.choice-card input'));

/* ---------- Payment ---------- */
const DOWN_RATES = <?= json_encode($downPayment) ?>;
let currentTotal = 0;
const methodInput = document.getElementById('payment_method');
const providerInput = document.getElementById('payment_provider');
const payTrigger = document.getElementById('payTrigger');
const payOptions = document.getElementById('payOptions');
const payBox = document.getElementById('payBox');
const payFields = document.getElementById('payFields');
const refInput = document.getElementById('reference_no');
const proofInput = document.getElementById('proof_image');
const fileDrop = document.getElementById('fileDrop');
const providerRadios = Array.from(document.querySelectorAll('.prov-row input'));
const getMethod = () => methodInput.value;
const needsProof = () => getMethod() === 'ewallet' || getMethod() === 'bank';
const currentProvider = () => providerRadios.find(r => r.checked && r.dataset.method === getMethod()) || null;

function openMenu() { payOptions.hidden = false; payTrigger.setAttribute('aria-expanded', 'true'); }
function closeMenu() { payOptions.hidden = true; payTrigger.setAttribute('aria-expanded', 'false'); }

function setMethod(value, label) {
    methodInput.value = value;
    document.getElementById('payTriggerText').textContent = label;
    payTrigger.classList.add('has-value');
    payTrigger.classList.remove('invalid');
    payOptions.querySelectorAll('.pay-option').forEach(o => o.classList.toggle('selected', o.dataset.value === value));
    showError(null, 'methodError', '');
    closeMenu();
    updatePayment();
}

payTrigger.addEventListener('click', () => payOptions.hidden ? openMenu() : closeMenu());
payOptions.querySelectorAll('.pay-option').forEach(o => {
    o.addEventListener('click', () => setMethod(o.dataset.value, o.dataset.label));
    o.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setMethod(o.dataset.value, o.dataset.label); } });
});
document.addEventListener('click', e => { if (!document.getElementById('paySelect').contains(e.target)) closeMenu(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });

providerRadios.forEach(r => r.addEventListener('change', () => {
    showError(null, 'provError_' + r.dataset.method, '');
    updatePayment();
}));

function validateMethod() {
    const ok = !!getMethod();
    showError(null, 'methodError', ok ? '' : 'Please choose a payment method.');
    payTrigger.classList.toggle('invalid', !ok);
    return ok;
}

function validateProvider() {
    if (!needsProof()) return true;
    const ok = !!currentProvider();
    showError(null, 'provError_' + getMethod(), ok ? '' : (getMethod() === 'bank' ? 'Please choose a bank.' : 'Please choose an e-wallet.'));
    return ok;
}

function validateRef(force) {
    const v = refInput.value.trim();
    let m = '';
    if (!v) { if (force) m = 'Please enter the reference number.'; }
    else if (!/^[A-Za-z0-9-]{6,25}$/.test(v)) m = 'Use 6–25 letters or numbers only.';
    showError(refInput, 'refError', m);
    return !m && v.length > 0;
}

function validateProof(force) {
    const f = proofInput.files[0];
    let m = '';
    if (!f) { if (force) m = 'Please upload your proof of payment.'; }
    else if (!/^(image\/(jpeg|png|webp)|application\/pdf)$/.test(f.type)) m = 'Only JPG, PNG, WEBP or PDF files are allowed.';
    else if (f.size > 5 * 1024 * 1024) m = 'File must be 5 MB or smaller.';
    showError(null, 'proofError', m);
    fileDrop.classList.toggle('invalid', !!m);
    fileDrop.classList.toggle('has-file', !m && !!f);
    document.getElementById('fileTitle').textContent = f && !m ? f.name : 'Upload proof of payment';
    document.getElementById('fileSub').textContent = f && !m ? 'Click to change file' : 'Screenshot or receipt · JPG, PNG or PDF · max 5 MB';
    return !m && !!f;
}

function paymentValid() {
    if (!validateMethod()) return false;
    if (!needsProof()) return true;
    const p = validateProvider(), a = validateRef(true), b = validateProof(true);
    return p && a && b;
}

function updatePayment() {
    const method = getMethod();
    const rate = method ? (DOWN_RATES[method] || 0) : 0;
    const due = method ? Math.round(currentTotal * rate * 100) / 100 : 0;
    const balance = Math.max(currentTotal - due, 0);
    const pct = Math.round(rate * 100);
    const prov = currentProvider();
    let label = '—';
    if (method) {
        label = payOptions.querySelector('.pay-option[data-value="' + method + '"]').dataset.label;
        if (prov) label += ' · ' + prov.dataset.name;
    }

    document.querySelectorAll('.js-due').forEach(el => el.textContent = peso(due));
    document.querySelectorAll('.js-balance').forEach(el => el.textContent = peso(balance));
    document.getElementById('sumDue').textContent = peso(due);
    document.getElementById('sumBalance').textContent = peso(balance);
    document.getElementById('sumPayment').textContent = label;
    document.getElementById('amount_due').value = due.toFixed(2);
    providerInput.value = prov ? prov.value : '';

    payBox.hidden = !method;
    document.querySelectorAll('.pay-detail').forEach(d => d.hidden = d.dataset.method !== method);
    providerRadios.forEach(r => r.closest('.prov-row').classList.toggle('selected', r.checked));
    document.querySelectorAll('.prov-panel').forEach(p => {
        p.hidden = !(prov && p.dataset.method === method && p.dataset.provider === prov.value);
    });

    const notes = {
        cash: 'Reserve your slot with a ' + pct + '% down payment (' + peso(due) + '). The remaining ' + peso(balance) + ' is paid in cash at the spa.',
        ewallet: 'Send ' + peso(due) + ' (' + pct + '% reservation fee) with your chosen e-wallet, then enter the reference number.',
        bank: 'Transfer ' + peso(due) + ' (' + pct + '% reservation fee) to your chosen bank, then enter the reference number.'
    };
    document.getElementById('payNote').textContent = notes[method] || '';

    const online = needsProof();
    payFields.hidden = !online;
    refInput.disabled = !online;
    proofInput.disabled = !online;
    if (!online) { showError(refInput, 'refError', ''); showError(null, 'proofError', ''); fileDrop.classList.remove('invalid'); }
}

refInput.addEventListener('input', () => validateRef(true));
proofInput.addEventListener('change', () => validateProof(true));
document.querySelectorAll('.copy-btn').forEach(btn => btn.addEventListener('click', () => {
    const done = () => { btn.textContent = 'Copied'; btn.classList.add('copied'); setTimeout(() => { btn.textContent = 'Copy'; btn.classList.remove('copied'); }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(btn.dataset.copy).then(done, done); else done();
}));

function renderSummary() {
    const guests = parseInt(guestsInput.value, 10) || 1;
    const extra = guests - 1;
    const selected = choiceInputs().filter(i => i.checked);

    choiceInputs().forEach(i => i.closest('.choice-card').classList.toggle('selected', i.checked));

    document.querySelectorAll('.choice-tab .count').forEach(c => {
        const n = selected.filter(i => i.dataset.type === c.dataset.count).length;
        c.textContent = n;
        c.classList.toggle('show', n > 0);
    });

    const firstService = selected.find(i => i.dataset.type === 'service');
    document.getElementById('service_id').value = firstService ? firstService.value : '';

    /* meta */
    document.getElementById('sumGuests').textContent = guests + ' guest' + (guests > 1 ? 's' : '');
    const dv = dateInput.value;
    document.getElementById('sumDate').textContent = dv
        ? new Date(dv + 'T00:00:00').toLocaleDateString('en-PH', {weekday:'short', year:'numeric', month:'long', day:'numeric'})
        : '—';
    const tsel = document.querySelector('input[name="appointment_time"]:checked');
    document.getElementById('sumTime').textContent = tsel ? fmtTime(tsel.value) : '—';

    const box = document.getElementById('breakdown');
    if (!selected.length) {
        box.innerHTML = '<div class="empty">No service selected yet</div>';
        document.getElementById('sumDuration').textContent = '—';
        document.getElementById('totalPrice').textContent = peso(0);
        currentTotal = 0;
        return;
    }

    let total = 0, html = '';
    selected.forEach(i => {
        const price = parseFloat(i.dataset.price) || 0;
        const addl = price * 0.8;
        const lineTotal = price + addl * extra;
        total += lineTotal;

        const tag = i.dataset.type === 'package' ? ' (Package)' : '';
        html += `<div class="row"><span>${esc(i.dataset.name)}${tag}</span><span>${peso(lineTotal)}</span></div>`;
        html += `<div class="row sub"><span>Guest 1 × ${peso(price)}</span><span>${peso(price)}</span></div>`;
        if (extra > 0) {
            html += `<div class="row sub"><span>${extra} additional guest${extra > 1 ? 's' : ''} × ${peso(addl)} (80%)</span><span>${peso(addl * extra)}</span></div>`;
        }
    });
    const mins = selected.reduce((sum, i) => sum + (parseInt(i.dataset.duration, 10) || 0), 0);
    document.getElementById('sumDuration').textContent = mins + ' min per guest';

    box.innerHTML = html;
    document.getElementById('totalPrice').textContent = peso(total);
    currentTotal = total;
    showError(null, 'serviceError', '');
}

function updateSummary() {
    renderSummary();
    updatePayment();
}

choiceInputs().forEach(i => i.addEventListener('change', updateSummary));

document.querySelectorAll('.choice-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.choice-tab').forEach(t => t.classList.toggle('active', t === tab));
        document.querySelectorAll('.choice-panel').forEach(p => p.classList.toggle('active', p.id === tab.dataset.panel));
    });
});
guestsInput.addEventListener('change', updateSummary);
updateSummary();

/* ---------- Step wizard ---------- */
let currentStep = 1;
const TOTAL_STEPS = 5;
const panels = Array.from(document.querySelectorAll('.step-panel'));
const stepperItems = Array.from(document.querySelectorAll('#stepper li'));
const backBtn = document.getElementById('backBtn');
const nextBtn = document.getElementById('nextBtn');

function fillReview() {
    const rows = [
        ['Name', nameInput.value.trim()],
        ['Email', emailInput.value.trim()],
        ['Phone', phoneInput.value],
        ['Date', document.getElementById('sumDate').textContent],
        ['Time', document.getElementById('sumTime').textContent],
        ['Guests', document.getElementById('sumGuests').textContent],
        ['Treatments', choiceInputs().filter(i => i.checked).map(i => i.dataset.name).join(', ')],
        ['Notes', document.getElementById('notes').value.trim() || '—'],
        ['Payment', document.getElementById('sumPayment').textContent],
        ...(needsProof() ? [['Reference no.', refInput.value.trim()]] : []),
        ['Estimated total', document.getElementById('totalPrice').textContent],
        ['Pay now', document.getElementById('sumDue').textContent],
        ['Balance at the spa', document.getElementById('sumBalance').textContent]
    ];
    document.getElementById('reviewBox').innerHTML =
        rows.map(r => `<div><dt>${r[0]}</dt><dd>${esc(r[1])}</dd></div>`).join('');
}

function goStep(n) {
    currentStep = n;
    panels.forEach(p => p.classList.toggle('active', +p.dataset.step === n));
    stepperItems.forEach(li => {
        const k = +li.dataset.step;
        li.classList.toggle('active', k === n);
        li.classList.toggle('done', k < n);
    });
    backBtn.style.display = n > 1 ? '' : 'none';
    nextBtn.style.display = n < TOTAL_STEPS ? '' : 'none';
    if (n === TOTAL_STEPS) fillReview();
    document.getElementById('stepper').scrollIntoView({behavior:'smooth', block:'start'});
}

function stepValid(n) {
    if (n === 1) {
        const ok = choiceInputs().some(i => i.checked);
        showError(null, 'serviceError', ok ? '' : 'Please add at least one treatment or package.');
        return ok;
    }
    if (n === 2) {
        const d = validateDate(true);
        const t = !!document.querySelector('input[name="appointment_time"]:checked');
        showError(null, 'timeError', t ? '' : 'Please select an available time.');
        return d && t;
    }
    if (n === 3) {
        const a = validateName(true), b = validateEmail(true), c = validatePhone(true);
        return a && b && c;
    }
    if (n === 4) return paymentValid();
    return true;
}

nextBtn.addEventListener('click', () => { if (stepValid(currentStep)) goStep(currentStep + 1); });
backBtn.addEventListener('click', () => goStep(currentStep - 1));

stepperItems.forEach(li => li.addEventListener('click', () => {
    const k = +li.dataset.step;
    if (k <= currentStep) { goStep(k); return; }
    for (let i = currentStep; i < k; i++) {
        if (!stepValid(i)) { goStep(i); return; }
    }
    goStep(k);
}));

/* Enter key moves to the next step instead of submitting early */
bookingForm.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.type !== 'submit' && currentStep < TOTAL_STEPS) {
        e.preventDefault();
        nextBtn.click();
    }
});

document.getElementById('terms').addEventListener('change', e => showError(null, 'termsError', e.target.checked ? '' : 'Please confirm the booking guidelines.'));

/* Submit: re-check every step, then the confirmation checkbox */
bookingForm.addEventListener('submit', function(event) {
    for (let i = 1; i <= 4; i++) {
        if (!stepValid(i)) { event.preventDefault(); goStep(i); return; }
    }
    if (!document.getElementById('terms').checked) {
        event.preventDefault();
        showError(null, 'termsError', 'Please confirm the booking guidelines.');
    }
});
</script>

</body>
</html>
