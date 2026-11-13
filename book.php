
<?php
require_once 'config.php';

// Services
try {
    $services = $pdo->query('SELECT id, name, duration_minutes, price, description, image FROM services WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    $services = $pdo->query('SELECT id, name, duration_minutes, price FROM services WHERE active = 1 ORDER BY name')->fetchAll();
}

// Packages
try {
    $packages = $pdo->query('SELECT id, name, description, duration_minutes, price, image FROM packages WHERE active = 1 ORDER BY name')->fetchAll();
} catch (Throwable $ex) {
    try {
        $packages = $pdo->query('SELECT id, name, description, duration_minutes, price FROM packages WHERE active = 1 ORDER BY name')->fetchAll();
    } catch (Throwable $ex2) {
        $packages = [];
    }
}

function item_image(array $row, string $kind): string
{
    if (!empty($row['image'])) {
        return preg_match('#^https?://#i', $row['image'])
            ? $row['image']
            : ltrim($row['image'], '/');
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
label .field-error, .field-error {
    display:block;
    color:#b83232 !important;
    font-size:12px !important;
    margin-top:5px;
    min-height:0;
}

.invalid-input {
    border-color:#b83232 !important;
    background:#fdf1f1 !important;
    box-shadow:0 0 0 3px rgba(184,50,50,.12) !important;
}

.valid-input {
    border-color:#648568 !important;
    box-shadow:0 0 0 3px rgba(100,133,104,.12) !important;
}

.choice-tabs {
    display:flex;
    gap:8px;
    margin:10px 0 14px;
    border-bottom:1px solid #e4d9cb;
}

.choice-tab {
    background:none;
    border:0;
    padding:10px 16px;
    font:inherit;
    font-size:14px;
    color:#7a6a5c;
    cursor:pointer;
    border-bottom:2px solid transparent;
    margin-bottom:-1px;
}

.choice-tab.active {
    color:#5a4030;
    border-bottom-color:#5a4030;
    font-weight:600;
}

.choice-tab .count {
    display:none;
    margin-left:6px;
    background:#648568;
    color:#fff;
    border-radius:999px;
    font-size:11px;
    padding:1px 7px;
}

.choice-tab .count.show {
    display:inline-block;
}

.choice-panel {
    display:none;
}

.choice-panel.active {
    display:block;
}

.choice-list {
    display:grid;
    grid-template-columns:1fr;
    gap:12px;
}

.choice-card {
    position:relative;
    display:flex;
    align-items:stretch;
    gap:16px;
    padding:12px;
    border:1px solid #e4d9cb;
    border-radius:22px;
    background:#fffdf9;
    cursor:pointer;
    font-weight:400;
    transition:box-shadow .15s, border-color .15s;
}

.choice-card:hover {
    box-shadow:0 6px 16px rgba(90,64,48,.10);
}

.choice-card input {
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.choice-thumb {
    flex:0 0 118px;
    height:118px;
    border-radius:20px 44px 44px 20px;
    overflow:hidden;
    background:linear-gradient(135deg,#efe3d2,#d9c7ae);
    display:flex;
    align-items:center;
    justify-content:center;
    color:#8b6f55;
    font-size:30px;
}

.choice-thumb img {
    width:100%;
    height:100%;
    object-fit:cover;
    display:block;
}

.choice-body {
    flex:1;
    min-width:0;
    display:flex;
    flex-direction:column;
}

.choice-card .choice-badge {
    position:absolute;
    top:12px;
    right:14px;
    background:#5a4030;
    color:#fff;
    font-size:9px;
    letter-spacing:.08em;
    text-transform:uppercase;
    padding:3px 8px;
    border-radius:999px;
    z-index:1;
}

.choice-card .choice-info strong {
    display:block;
    font-size:17px;
    line-height:1.25;
    font-family:inherit;
    padding-right:60px;
}

.choice-card .choice-info small {
    display:block;
    color:#7a6a5c;
    font-size:12px;
    margin-top:3px;
    line-height:1.4;
}

.choice-card .choice-info small.desc {
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    overflow:hidden;
    color:#9a8b7c;
}

.choice-card .choice-meta {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-top:auto;
    padding-top:8px;
}

.choice-card .choice-price {
    font-weight:600;
    font-size:16px;
    color:#5a4030;
}

.choice-card .choice-btn {
    font-size:12px;
    padding:6px 14px;
    border-radius:999px;
    border:1px solid #5a4030;
    color:#5a4030;
    background:#f1e8dc;
    white-space:nowrap;
}

.choice-card .choice-btn .on {
    display:none;
}

.choice-card.selected {
    border-color:#648568;
    background:#f1f5ef;
    box-shadow:0 0 0 1px #648568 inset;
}

.choice-card.selected .choice-btn {
    background:#648568;
    border-color:#648568;
    color:#fff;
}

.choice-card.selected .choice-btn .on {
    display:inline;
}

.choice-card.selected .choice-btn .off {
    display:none;
}

.choice-card input:focus-visible ~ .choice-body .choice-btn {
    outline:2px solid #648568;
    outline-offset:2px;
}

@media (max-width:480px) {
    .choice-thumb {
        flex-basis:86px;
        height:86px;
    }

    .choice-card .choice-info strong {
        font-size:15px;
    }
}

.group-title {
    font-size:13px;
    font-weight:600;
    margin-top:6px;
}

.slot-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(110px,1fr));
    gap:8px;
    margin-top:8px;
}

.slot {
    position:relative;
}

.slot input {
    position:absolute;
    opacity:0;
    pointer-events:none;
}

.slot span {
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:2px;
    padding:9px 6px;
    border-radius:12px;
    border:1px solid #e4d9cb;
    background:#fffdf9;
    cursor:pointer;
    transition:all .15s;
}

.slot span b {
    font-size:14px;
    font-weight:600;
    color:#4a3628;
}

.slot span em {
    font-style:normal;
    font-size:11px;
    display:flex;
    align-items:center;
    gap:5px;
}

.slot span em::before {
    content:"";
    width:7px;
    height:7px;
    border-radius:50%;
    background:currentColor;
}

.slot.available span em {
    color:#648568;
}

.slot.available span:hover {
    border-color:#648568;
    background:#f1f5ef;
}

.slot.available input:checked + span {
    background:#648568;
    border-color:#648568;
}

.slot.available input:checked + span b,
.slot.available input:checked + span em {
    color:#fff;
}

.slot.booked span {
    background:#faf0ef;
    border-color:#efd5d2;
    cursor:not-allowed;
}

.slot.booked span b {
    color:#b98a86;
}

.slot.booked span em {
    color:#b83232;
}

.slot.passed span {
    background:#f3f1ee;
    border-color:#e6e1da;
    cursor:not-allowed;
}

.slot.passed span b {
    color:#aaa39a;
}

.slot.passed span em {
    color:#aaa39a;
}

.slot-hint {
    font-size:12px;
    color:#7a6a5c;
}

.breakdown {
    width:100%;
    margin-top:10px;
    font-size:13px;
}

.breakdown .row {
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:4px 0;
}

.breakdown .row.sub {
    color:#7a6a5c;
    padding-left:12px;
    font-size:12px;
}

.breakdown .row.line {
    border-top:1px dashed #cdbfae;
    margin-top:6px;
    padding-top:8px;
    font-weight:600;
}

.breakdown .empty {
    color:#7a6a5c;
}

/* Stepper */
.stepper {
    list-style:none;
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:4px 0;
    margin:16px 0 22px;
    padding:12px 16px;
    background:#f1e8dc;
    border-radius:14px;
}

.stepper li {
    display:flex;
    align-items:center;
    gap:8px;
    font-size:13px;
    color:#a09283;
    cursor:default;
}

.stepper li:not(:last-child)::after {
    content:"›";
    margin:0 12px;
    color:#b8aa9a;
    font-size:16px;
}

.stepper .num {
    width:22px;
    height:22px;
    border-radius:50%;
    border:1px solid #c9bba9;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:11px;
    background:#fffdf9;
}

.stepper li.active {
    color:#3f2d20;
    font-weight:600;
}

.stepper li.active .num {
    background:#5a4030;
    border-color:#5a4030;
    color:#fff;
}

.stepper li.done {
    color:#648568;
    cursor:pointer;
}

.stepper li.done .num {
    background:#648568;
    border-color:#648568;
    color:#fff;
}

/* Layout */
.booking-cols {
    display:grid;
    grid-template-columns:minmax(0,1fr) 290px;
    gap:24px;
    align-items:start;
}

.step-panel {
    display:none;
}

.step-panel.active {
    display:block;
}

.step-head {
    margin-bottom:14px;
}

.step-head h3 {
    margin:2px 0 4px;
    font-size:20px;
}

.step-head p {
    margin:0;
    font-size:13px;
    color:#7a6a5c;
}

.step-nav {
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:20px;
}

.step-nav .button {
    width:auto;
    min-width:130px;
}

.btn-back {
    background:transparent;
    border:1px solid #5a4030;
    color:#5a4030;
    border-radius:999px;
    padding:12px 22px;
    font:inherit;
    font-size:14px;
    cursor:pointer;
}

.guest-row {
    margin-bottom:18px;
    max-width:260px;
}

/* Booking summary */
.summary-card {
    position:sticky;
    top:20px;
    background:#fbf6ee;
    border:1px solid #eadfce;
    border-radius:20px;
    padding:18px;
}

.summary-card h4 {
    margin:0 0 12px;
    font-size:16px;
}

.summary-box {
    background:#efebe6;
    border-radius:12px;
    padding:12px 14px;
    min-height:60px;
}

.summary-meta {
    margin:12px 0 0;
    font-size:13px;
}

.summary-meta div {
    display:flex;
    justify-content:space-between;
    gap:10px;
    padding:5px 0;
    border-bottom:1px dashed #e0d4c3;
}

.summary-meta dt {
    color:#7a6a5c;
}

.summary-meta dd {
    margin:0;
    font-weight:600;
    text-align:right;
}

.summary-total {
    display:flex;
    justify-content:space-between;
    align-items:baseline;
    margin-top:12px;
}

.summary-total strong {
    font-size:22px;
    color:#5a4030;
}

.summary-card .breakdown {
    margin-top:0;
}

/* Review */
.review-box {
    border:1px solid #e4d9cb;
    border-radius:16px;
    background:#fffdf9;
    padding:6px 16px;
    margin-bottom:14px;
}

.review-box div {
    display:flex;
    justify-content:space-between;
    gap:14px;
    padding:9px 0;
    border-bottom:1px solid #f0e8dc;
    font-size:14px;
}

.review-box div:last-child {
    border-bottom:0;
}

.review-box dt {
    color:#7a6a5c;
}

.review-box dd {
    margin:0;
    font-weight:600;
    text-align:right;
    word-break:break-word;
}

@media (max-width:1200px) {
    .booking-cols {
        grid-template-columns:1fr;
    }

    .summary-card {
        position:static;
    }
}

@media (max-width:640px) {
    .stepper li:not(.active) .lbl {
        display:none;
    }

    .stepper li:not(:last-child)::after {
        margin:0 6px;
    }
}

/* Spacing */
.stepper {
    margin:22px 0 32px;
    padding:14px 20px;
}

.booking-cols {
    gap:36px;
}

.step-head {
    margin-bottom:26px;
}

.step-head h3 {
    font-size:22px;
    margin:0;
}

.step-panel .form-grid {
    gap:24px;
}

.guest-row {
    margin:0 0 32px;
    max-width:100%;
}

.choice-tabs {
    margin:0 0 22px;
}

.choice-tab {
    padding:12px 20px;
}

.choice-list {
    gap:18px;
}

.choice-card {
    padding:16px;
    gap:20px;
    border-radius:24px;
}

.choice-thumb {
    flex:0 0 108px;
    height:108px;
}

.choice-card .choice-info small {
    margin-top:5px;
}

.choice-card .choice-meta {
    padding-top:14px;
}

.slot-grid {
    gap:14px;
    margin-top:12px;
}

.slot span {
    padding:12px 8px;
    gap:4px;
}

.group-title {
    margin-bottom:4px;
}

.slot-hint {
    display:block;
    margin-top:10px;
}

.step-nav {
    margin-top:36px;
}

.summary-card {
    padding:24px;
}

.summary-card h4 {
    margin-bottom:16px;
}

.summary-box {
    padding:16px;
}

.summary-meta {
    margin-top:16px;
}

.summary-meta div {
    padding:9px 0;
}

.summary-total {
    margin-top:18px;
}

.breakdown .row {
    padding:5px 0;
}

.breakdown .row.sub {
    padding:2px 0 2px 12px;
}

.breakdown .row.line {
    margin-top:10px;
}

.review-box {
    padding:8px 20px;
    margin-bottom:24px;
}

.review-box div {
    padding:13px 0;
}

.summary-box .row {
    align-items:baseline;
}

.summary-box .row > span:first-child {
    flex:1;
    min-width:0;
}

.summary-box .row > span:last-child {
    white-space:nowrap;
    font-weight:600;
}

.summary-box .row.sub > span:last-child {
    font-weight:400;
}

.summary-box .row:not(.sub):not(:first-child) {
    margin-top:10px;
    padding-top:10px;
    border-top:1px dashed #d8cdbd;
}

.summary-box .row.line > span:last-child {
    font-weight:700;
}

/* Payment methods */
.payment-section {
    margin-top:22px;
}

.payment-section select {
    width:100%;
}

.payment-details {
    display:none;
    margin-top:14px;
    padding:18px;
    border:1px solid #e4d9cb;
    border-radius:16px;
    background:#fbf6ee;
}

.payment-details.active {
    display:block;
}

.payment-details p {
    margin:5px 0;
    color:#7a6a5c;
    font-size:13px;
    line-height:1.6;
}

.payment-qr {
    width:190px;
    height:190px;
    margin:14px auto 5px;
    border:1px dashed #b8aa9a;
    border-radius:14px;
    background:#fffdf9;
    display:flex;
    align-items:center;
    justify-content:center;
    text-align:center;
    color:#8b6f55;
    font-size:12px;
    padding:12px;
    overflow:hidden;
    transition:transform .25s ease, box-shadow .25s ease;
    cursor:zoom-in;
}

.payment-qr:hover {
    transform:scale(1.08);
    box-shadow:0 8px 22px rgba(90,64,48,.18);
}

.payment-qr img {
    width:100%;
    height:100%;
    object-fit:contain;
}

.payment-details strong {
    color:#5a4030;
}
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
        <li data-step="4"><span class="num">4</span><span class="lbl">Confirmation</span></li>
    </ol>

    <form action="save_booking.php" method="post" id="bookingForm" novalidate>

    <div class="booking-cols">

        <div class="steps-col">

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
                        <button type="button" class="choice-tab active" data-panel="panelServices">
                            Treatments <span class="count" data-count="service"></span>
                        </button>

                        <button type="button" class="choice-tab" data-panel="panelPackages">
                            Packages <span class="count" data-count="package"></span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <div class="choice-panel active" id="panelServices">
                        <div class="choice-list">

                            <?php foreach ($services as $s): $img = item_image($s, 'services'); ?>
                                <label class="choice-card">

                                    <input type="checkbox"
                                           name="service_ids[]"
                                           value="<?= (int)$s['id'] ?>"
                                           data-type="service"
                                           data-name="<?= e($s['name']) ?>"
                                           data-price="<?= e((string)$s['price']) ?>"
                                           data-duration="<?= (int)$s['duration_minutes'] ?>"
                                           <?= $selectedService === (int)$s['id'] ? 'checked' : '' ?>>

                                    <span class="choice-thumb">
                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="<?= e($s['name']) ?>" loading="lazy">
                                        <?php else: ?>
                                            ✦
                                        <?php endif; ?>
                                    </span>

                                    <span class="choice-body">
                                        <span class="choice-info">
                                            <strong><?= e($s['name']) ?></strong>

                                            <?php if (!empty($s['description'])): ?>
                                                <small class="desc"><?= e((string)$s['description']) ?></small>
                                            <?php endif; ?>

                                            <small>◷ <?= (int)$s['duration_minutes'] ?> minutes</small>
                                        </span>

                                        <span class="choice-meta">
                                            <span class="choice-price">₱<?= number_format((float)$s['price'], 2) ?></span>
                                            <span class="choice-btn">
                                                <span class="off">+ Add</span>
                                                <span class="on">✓ Added</span>
                                            </span>
                                        </span>
                                    </span>

                                </label>
                            <?php endforeach; ?>

                        </div>
                    </div>

                    <?php if ($packages): ?>
                    <div class="choice-panel" id="panelPackages">
                        <div class="choice-list">

                            <?php foreach ($packages as $p): $img = item_image($p, 'packages'); ?>
                                <label class="choice-card">

                                    <input type="checkbox"
                                           name="package_ids[]"
                                           value="<?= (int)$p['id'] ?>"
                                           data-type="package"
                                           data-name="<?= e($p['name']) ?>"
                                           data-price="<?= e((string)$p['price']) ?>"
                                           data-duration="<?= (int)$p['duration_minutes'] ?>">

                                    <span class="choice-badge">Package</span>

                                    <span class="choice-thumb">
                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                                        <?php else: ?>
                                            ❀
                                        <?php endif; ?>
                                    </span>

                                    <span class="choice-body">
                                        <span class="choice-info">
                                            <strong><?= e($p['name']) ?></strong>

                                            <?php if (!empty($p['description'])): ?>
                                                <small class="desc"><?= e((string)$p['description']) ?></small>
                                            <?php endif; ?>

                                            <small>◷ <?= (int)$p['duration_minutes'] ?> minutes</small>
                                        </span>

                                        <span class="choice-meta">
                                            <span class="choice-price">₱<?= number_format((float)$p['price'], 2) ?></span>
                                            <span class="choice-btn">
                                                <span class="off">+ Add</span>
                                                <span class="on">✓ Added</span>
                                            </span>
                                        </span>
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
                        <input type="text"
                               name="customer_name"
                               id="customer_name"
                               required
                               maxlength="120"
                               autocomplete="name"
                               placeholder="Enter your full name">
                        <small class="field-error" id="nameError"></small>
                    </label>

                    <label>
                        Email address
                        <input type="email"
                               name="email"
                               id="email"
                               required
                               maxlength="150"
                               autocomplete="email"
                               placeholder="example@email.com">
                        <small class="field-error" id="emailError"></small>
                    </label>

                    <label>
                        Phone number
                        <input type="tel"
                               name="phone"
                               id="phone"
                               required
                               maxlength="12"
                               autocomplete="tel"
                               inputmode="numeric"
                               placeholder="09XXXXXXXXX or 639XXXXXXXXX">
                        <small class="field-error" id="phoneError"></small>
                    </label>

                    <label class="full">
                        Notes or preferences (optional)
                        <textarea name="notes"
                                  id="notes"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="Share any preferences or accessibility needs."></textarea>
                    </label>

                    <!-- PAYMENT METHODS -->
                    <label class="full payment-section">
                        Payment Methods

                        <select name="payment_method" id="payment_method" required>
                            <option value="">Select payment method</option>
                            <option value="Cash">Cash</option>
                            <option value="E-Wallet">E-Wallet (GCash / Maya)</option>
                            <option value="Online Banking">Online Banking</option>
                        </select>

                        <small class="field-error" id="paymentError"></small>
                    </label>

                    <div class="payment-details full" id="cashDetails">
                        <strong>Cash Payment</strong>
                        <p>You can pay your appointment at Bella Vista Oasis Spa upon arrival.</p>
                    </div>

                    <div class="payment-details full" id="ewalletDetails">
                        <strong>GCash / Maya Payment</strong>
                        <p>Scan the official payment QR code to pay using GCash or Maya.</p>

                        <div class="payment-qr">
                            Insert your official GCash / Maya QR code here.
                        </div>

                        <p>Please present your payment receipt to our staff.</p>
                    </div>

                    <div class="payment-details full" id="onlineBankingDetails">
                        <strong>Online Banking</strong>
                        <p>You may pay through your bank's official online banking app or website.</p>
                        <p>Please contact Bella Vista Oasis Spa for the verified bank account details and payment instructions before transferring.</p>
                        <p>For your security, never share your banking password, PIN, or OTP.</p>
                    </div>

                </div>
            </div>

            <!-- STEP 4 -->
            <div class="step-panel" data-step="4">

                <div class="step-head">
                    <h3>Confirm your request</h3>
                </div>

                <dl class="review-box" id="reviewBox"></dl>

                <div class="form-grid">

                    <label class="checkbox full">
                        <input type="checkbox" id="terms" required>
                        I have read the booking guidelines and understand this request is pending until confirmed.
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

            <div class="summary-box breakdown" id="breakdown">
                <div class="empty">No service selected yet</div>
            </div>

            <dl class="summary-meta">
                <div><dt>Guests</dt><dd id="sumGuests">1 guest</dd></div>
                <div><dt>Date</dt><dd id="sumDate">—</dd></div>
                <div><dt>Time</dt><dd id="sumTime">—</dd></div>
                <div><dt>Duration</dt><dd id="sumDuration">—</dd></div>
            </dl>

            <div class="summary-total">
                <span>Estimated total</span>
                <strong id="totalPrice">₱0.00</strong>
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
const paymentMethod = document.getElementById('payment_method');

const SLOTS = ['09:00','10:30','12:00','13:30','15:00','16:30'];

const esc = t => String(t).replace(/[&<>"']/g, c => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#39;'
}[c]));

const peso = n => '₱' + n.toLocaleString('en-PH', {
    minimumFractionDigits:2,
    maximumFractionDigits:2
});

/* Error display */
function showError(input, errorId, message) {
    const error = document.getElementById(errorId);
    error.textContent = message;

    if (input) {
        input.classList.toggle('invalid-input', !!message);
        input.classList.toggle('valid-input', !message && input.value.length > 0);
    }
}

/* Name validation */
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

/* Email validation */
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

/* Phone validation */
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

    return !message && (
        /^09\d{9}$/.test(value) ||
        /^639\d{9}$/.test(value)
    );
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

/* Payment method details */
function updatePaymentDetails() {
    const method = paymentMethod.value;

    document.getElementById('cashDetails').classList.toggle(
        'active',
        method === 'Cash'
    );

    document.getElementById('ewalletDetails').classList.toggle(
        'active',
        method === 'E-Wallet'
    );

    document.getElementById('onlineBankingDetails').classList.toggle(
        'active',
        method === 'Online Banking'
    );

    showError(
        paymentMethod,
        'paymentError',
        method ? '' : 'Please select a payment method.'
    );
}

paymentMethod.addEventListener('change', updatePaymentDetails);

/* Date validation */
function todayStr() {
    const d = new Date();
    const pad = n => String(n).padStart(2, '0');

    return d.getFullYear() + '-' +
        pad(d.getMonth() + 1) + '-' +
        pad(d.getDate());
}

dateInput.min = todayStr();

function validateDate(force) {
    const v = dateInput.value;
    let message = '';

    if (!v) {
        if (force) message = 'Please choose an appointment date.';
    } else if (v < todayStr()) {
        message = 'You cannot book a past date.';
    }

    showError(dateInput, 'dateError', message);
    return !message && !!v;
}

/* Time slots */
function fmtTime(t) {
    const [h, m] = t.split(':').map(Number);

    return ((h % 12) || 12) + ':' +
        String(m).padStart(2, '0') +
        ' ' + (h >= 12 ? 'PM' : 'AM');
}

async function loadSlots() {
    const date = dateInput.value;

    slotGrid.innerHTML = '';
    showError(null, 'timeError', '');

    if (!date || date < todayStr()) {
        slotHint.textContent = 'Select a date to see available times.';
        return;
    }

    let booked = [];

    try {
        const res = await fetch(
            'check_slots.php?date=' + encodeURIComponent(date)
        );

        const data = await res.json();
        booked = data.booked || [];
        slotHint.textContent = '';

    } catch (err) {
        slotHint.textContent = 'Could not check availability right now. Please try again.';
    }

    const now = new Date();

    SLOTS.forEach(t => {
        let state = 'available';
        let label = 'Available';

        if (booked.includes(t)) {
            state = 'booked';
            label = 'Booked';

        } else if (date === todayStr()) {
            const [h, m] = t.split(':').map(Number);

            if (h * 60 + m <= now.getHours() * 60 + now.getMinutes()) {
                state = 'passed';
                label = 'Time passed';
            }
        }

        const disabled = state !== 'available' ? 'disabled' : '';

        slotGrid.insertAdjacentHTML('beforeend',
            `<label class="slot ${state}">
                <input type="radio" name="appointment_time" value="${t}" ${disabled}>
                <span>
                    <b>${fmtTime(t)}</b>
                    <em>${label}</em>
                </span>
             </label>`
        );
    });
}

dateInput.addEventListener('change', () => {
    validateDate(true);
    loadSlots().then(updateSummary);
    updateSummary();
});

slotGrid.addEventListener('change', updateSummary);

/* Services and packages */
const choiceInputs = () =>
    Array.from(document.querySelectorAll('.choice-card input'));

function updateSummary() {
    const guests = parseInt(guestsInput.value, 10) || 1;
    const extra = guests - 1;
    const selected = choiceInputs().filter(i => i.checked);

    choiceInputs().forEach(i => {
        i.closest('.choice-card').classList.toggle('selected', i.checked);
    });

    document.querySelectorAll('.choice-tab .count').forEach(c => {
        const n = selected.filter(
            i => i.dataset.type === c.dataset.count
        ).length;

        c.textContent = n;
        c.classList.toggle('show', n > 0);
    });

    const firstService = selected.find(
        i => i.dataset.type === 'service'
    );

    document.getElementById('service_id').value =
        firstService ? firstService.value : '';

    document.getElementById('sumGuests').textContent =
        guests + ' guest' + (guests > 1 ? 's' : '');

    const dv = dateInput.value;

    document.getElementById('sumDate').textContent = dv
        ? new Date(dv + 'T00:00:00').toLocaleDateString('en-PH', {
            weekday:'short',
            year:'numeric',
            month:'long',
            day:'numeric'
        })
        : '—';

    const tsel = document.querySelector(
        'input[name="appointment_time"]:checked'
    );

    document.getElementById('sumTime').textContent =
        tsel ? fmtTime(tsel.value) : '—';

    const box = document.getElementById('breakdown');

    if (!selected.length) {
        box.innerHTML = '<div class="empty">No service selected yet</div>';
        document.getElementById('sumDuration').textContent = '—';
        document.getElementById('totalPrice').textContent = peso(0);
        return;
    }

    let total = 0;
    let html = '';

    selected.forEach(i => {
        const price = parseFloat(i.dataset.price) || 0;
        const addl = price * 0.8;
        const lineTotal = price + addl * extra;

        total += lineTotal;

        const tag = i.dataset.type === 'package'
            ? ' (Package)'
            : '';

        html += `<div class="row">
                    <span>${esc(i.dataset.name)}${tag}</span>
                    <span>${peso(lineTotal)}</span>
                 </div>`;

        html += `<div class="row sub">
                    <span>Guest 1 × ${peso(price)}</span>
                    <span>${peso(price)}</span>
                 </div>`;

        if (extra > 0) {
            html += `<div class="row sub">
                        <span>${extra} additional guest${extra > 1 ? 's' : ''} × ${peso(addl)} (80%)</span>
                        <span>${peso(addl * extra)}</span>
                     </div>`;
        }
    });

    const mins = selected.reduce(
        (sum, i) => sum + (parseInt(i.dataset.duration, 10) || 0),
        0
    );

    document.getElementById('sumDuration').textContent =
        mins + ' min per guest';

    box.innerHTML = html;

    document.getElementById('totalPrice').textContent = peso(total);

    showError(null, 'serviceError', '');
}

choiceInputs().forEach(i =>
    i.addEventListener('change', updateSummary)
);

document.querySelectorAll('.choice-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.choice-tab').forEach(t => {
            t.classList.toggle('active', t === tab);
        });

        document.querySelectorAll('.choice-panel').forEach(p => {
            p.classList.toggle(
                'active',
                p.id === tab.dataset.panel
            );
        });
    });
});

guestsInput.addEventListener('change', updateSummary);
updateSummary();

/* Step wizard */
let currentStep = 1;
const TOTAL_STEPS = 4;

const panels = Array.from(
    document.querySelectorAll('.step-panel')
);

const stepperItems = Array.from(
    document.querySelectorAll('#stepper li')
);

const backBtn = document.getElementById('backBtn');
const nextBtn = document.getElementById('nextBtn');

/* Review */
function fillReview() {
    const rows = [
        ['Name', nameInput.value.trim()],
        ['Email', emailInput.value.trim()],
        ['Phone', phoneInput.value],
        ['Date', document.getElementById('sumDate').textContent],
        ['Time', document.getElementById('sumTime').textContent],
        ['Guests', document.getElementById('sumGuests').textContent],
        [
            'Treatments',
            choiceInputs()
                .filter(i => i.checked)
                .map(i => i.dataset.name)
                .join(', ')
        ],
        ['Notes', document.getElementById('notes').value.trim() || '—'],
        ['Payment Method', paymentMethod.value || '—'],
        ['Estimated total', document.getElementById('totalPrice').textContent]
    ];

    document.getElementById('reviewBox').innerHTML =
        rows.map(r =>
            `<div><dt>${esc(r[0])}</dt><dd>${esc(r[1])}</dd></div>`
        ).join('');
}

/* Navigate between steps */
function goStep(n) {
    currentStep = n;

    panels.forEach(p => {
        p.classList.toggle('active', +p.dataset.step === n);
    });

    stepperItems.forEach(li => {
        const k = +li.dataset.step;

        li.classList.toggle('active', k === n);
        li.classList.toggle('done', k < n);
    });

    backBtn.style.display = n > 1 ? '' : 'none';
    nextBtn.style.display = n < TOTAL_STEPS ? '' : 'none';

    if (n === TOTAL_STEPS) {
        fillReview();
    }

    document.getElementById('stepper').scrollIntoView({
        behavior:'smooth',
        block:'start'
    });
}

/* Step validation */
function stepValid(n) {
    if (n === 1) {
        const ok = choiceInputs().some(i => i.checked);

        showError(
            null,
            'serviceError',
            ok ? '' : 'Please add at least one treatment or package.'
        );

        return ok;
    }

    if (n === 2) {
        const d = validateDate(true);

        const t = !!document.querySelector(
            'input[name="appointment_time"]:checked'
        );

        showError(
            null,
            'timeError',
            t ? '' : 'Please select an available time.'
        );

        return d && t;
    }

    if (n === 3) {
        const a = validateName(true);
        const b = validateEmail(true);
        const c = validatePhone(true);
        const d = !!paymentMethod.value;

        showError(
            paymentMethod,
            'paymentError',
            d ? '' : 'Please select a payment method.'
        );

        return a && b && c && d;
    }

    return true;
}

nextBtn.addEventListener('click', () => {
    if (stepValid(currentStep)) {
        goStep(currentStep + 1);
    }
});

backBtn.addEventListener('click', () => {
    goStep(currentStep - 1);
});

stepperItems.forEach(li => {
    li.addEventListener('click', () => {
        const k = +li.dataset.step;

        if (k <= currentStep) {
            goStep(k);
            return;
        }

        for (let i = currentStep; i < k; i++) {
            if (!stepValid(i)) {
                goStep(i);
                return;
            }
        }

        goStep(k);
    });
});

/* Enter key moves to next step */
bookingForm.addEventListener('keydown', e => {
    if (
        e.key === 'Enter' &&
        e.target.tagName !== 'TEXTAREA' &&
        e.target.type !== 'submit' &&
        currentStep < TOTAL_STEPS
    ) {
        e.preventDefault();
        nextBtn.click();
    }
});

/* Terms checkbox */
document.getElementById('terms').addEventListener('change', e => {
    showError(
        null,
        'termsError',
        e.target.checked
            ? ''
            : 'Please confirm the booking guidelines.'
    );
});

/* Final submission */
bookingForm.addEventListener('submit', function(event) {
    for (let i = 1; i <= 3; i++) {
        if (!stepValid(i)) {
            event.preventDefault();
            goStep(i);
            return;
        }
    }

    if (!document.getElementById('terms').checked) {
        event.preventDefault();

        showError(
            null,
            'termsError',
            'Please confirm the booking guidelines.'
        );
    }
});
</script>

</body>
</html>
