<?php
require_once 'config.php';

/* ---------- Reservation hold: unpaid cash bookings are released after this many hours ---------- */
const HOLD_HOURS = 24;

function bv_release_unpaid(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE appointments SET status = 'Cancelled'
                    WHERE status = 'Pending' AND payment_method = 'cash' AND payment_status = 'unpaid'
                      AND created_at < (NOW() - INTERVAL " . (int)HOLD_HOURS . " HOUR)");
    } catch (Throwable $e) {
        // ignore: column may not exist yet
    }
}

/* ---------- Availability endpoint:  book.php?slots=YYYY-MM-DD ---------- */
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

$preview = !empty($_GET['preview']);   // true when shown inside the admin dashboard

/* ---------- Spa details shown to customers (edit these) ---------- */
$spaInfo = [
    'address' => 'Bella Vista Oasis Spa front desk (add your street address here)',
    'hours'   => 'Tuesday to Sunday, 9:00 AM to 5:30 PM',
    'phone'   => '09XX XXX XXXX',
];

/* ---------- Payment settings (edit these to your real details) ---------- */
$downPayment = ['cash' => 0.10, 'ewallet' => 0.30, 'bank' => 0.30];   // down payment per method
$paymentMethods = [
    'cash' => [
        'label' => 'Cash', 'icon' => '₱',
        'hint' => (int)round($downPayment['cash'] * 100) . '% down payment · pay at the spa front desk',
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
    ['Confirmation', 'Your request stays pending until your down payment is verified by our team. Use your booking code on the Track my booking page to see its status anytime.'],
    ['Down payment', 'A down payment secures your slot: ' . (int)round($downPayment['cash'] * 100) . '% for cash, ' . (int)round($downPayment['ewallet'] * 100) . '% for e-wallet and online banking. The balance is paid at the spa.'],
    ['Reservation hold', 'Cash reservations are held for ' . HOLD_HOURS . ' hours while you pay the down payment at the spa front desk. Unpaid reservations are released automatically.'],
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

/* Settings handed to the booking script at the bottom of this file. Keep timeSlots / rates in sync with save_booking.php. */
$bookingConfig = [
    'slotsUrl'         => 'book.php?slots=',
    'timeSlots'        => ['09:00', '10:30', '12:00', '13:30', '15:00', '16:30'],
    'closedWeekday'    => 1,                 // 0 = Sunday ... 1 = Monday
    'maxYearsAhead'    => 2,                 // how far ahead the calendar goes
    'extraGuestRate'   => 0.80,              // each extra guest pays 80%
    'downPaymentRates' => $downPayment,
    'holdHours'        => HOLD_HOURS,
];

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
/* =====================================================================
   Bella Vista Oasis Spa - booking page styles
   Loaded after assets/style.css (site-wide styles).

   Contents
     1. Design tokens (colors, fonts, button height)
     2. Page layout (compact header, left panel, title row)
     3. Form feedback (errors, valid state)
     4. Stepper and buttons (one button size everywhere)
     5. Step 1 - treatment slider and cards
     6. Step 2 - calendar and time slots
     7. Step 4 - payment
     8. Booking summary (floating, draggable, collapsible)
     9. Step 5 - review, next steps, policies
    10. Yes / No confirmation dialog
   ===================================================================== */

/* ---------- 1. Design tokens ---------- */
:root {
    --bv-ink: #3f2d20;            /* main text */
    --bv-brown: #5a4030;          /* buttons, selected states */
    --bv-brown-dark: #46301f;
    --bv-muted: #6f5f52;          /* secondary text (AA contrast on cream) */
    --bv-muted-soft: #8a7a6b;     /* hints, disabled labels */
    --bv-green: #648568;          /* success / available */
    --bv-green-soft: #f1f5ef;
    --bv-red: #b83232;            /* errors / booked */
    --bv-red-soft: #fdf1f1;
    --bv-gold: #b58a52;
    --bv-card: #fffdf9;           /* card background */
    --bv-cream: #fbf6ee;
    --bv-sand: #f1e8dc;           /* subtle fills */
    --bv-sand-soft: #f6f0e7;
    --bv-line: #e4d9cb;           /* borders */
    --bv-line-strong: #d9cfc2;
    --bv-font-display: "Cormorant Garamond", Georgia, serif;
    --bv-radius-card: 20px;
    --bv-radius-pill: 999px;
    --bv-btn-height: 42px;        /* every main button uses this height */
}

/* ---------- 2. Page layout ----------
   The !important flags override the taller header and wider left panel
   from assets/style.css so the form starts higher on the page. */
.site-header { min-height: 0 !important; padding-top: 10px !important; padding-bottom: 10px !important; }
.site-header .brand-mark { width: 38px !important; height: 38px !important; }
.booking-layout { display: grid !important; grid-template-columns: 300px minmax(0, 1fr) !important; }
.booking-aside { width: auto !important; max-width: none !important; min-width: 0 !important; padding: 28px 28px !important; }
.booking-aside h1 { font-size: clamp(30px, 2.3vw, 40px) !important; line-height: 1.12 !important; }
.booking-aside > p { font-size: 14px !important; line-height: 1.65 !important; }
.booking-aside .aside-detail strong { font-size: 17px !important; }
.booking-aside .aside-detail small { font-size: 12px !important; }
.booking-form-wrap { max-width: none !important; padding: 24px clamp(24px, 3vw, 48px) 110px !important; }   /* bottom space keeps the floating summary off the buttons */

/* Title and stepper share one row */
.booking-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 28px; margin-bottom: 22px; }
.booking-head h2 { margin: 0; font-size: 34px; line-height: 1.1; }

.booking-cols { display: block; }
.step-panel { display: none; }
.step-panel.active { display: block; }
.step-panel .form-grid { gap: 24px; }
.step-head { margin-bottom: 18px; }
.step-head h3 { margin: 0; font-size: 22px; }
.guest-row { margin: 0 0 24px; }

@media (max-width: 900px) {
    .booking-layout { grid-template-columns: 1fr !important; }
}

/* ---------- 3. Form feedback ---------- */
.field-error { display: block; margin-top: 5px; color: var(--bv-red) !important; font-size: 12px !important; }
.invalid-input { border-color: var(--bv-red) !important; background: var(--bv-red-soft) !important; box-shadow: 0 0 0 3px rgba(184, 50, 50, .12) !important; }
.valid-input { border-color: var(--bv-green) !important; box-shadow: 0 0 0 3px rgba(100, 133, 104, .12) !important; }

/* ---------- 4. Stepper and buttons ---------- */
.stepper { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 0; margin: 0; padding: 9px 18px; list-style: none; background: var(--bv-sand); border-radius: var(--bv-radius-pill); }
.stepper li { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--bv-muted-soft); cursor: default; }
.stepper li:not(:last-child)::after { content: "›"; margin: 0 12px; color: #b8aa9a; font-size: 16px; }
.stepper .num { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border: 1px solid #c9bba9; border-radius: 50%; background: var(--bv-card); font-size: 12px; }
.stepper li.active { color: var(--bv-ink); font-weight: 600; }
.stepper li.active .num { background: var(--bv-brown); border-color: var(--bv-brown); color: #fff; }
.stepper li.done { color: var(--bv-green); cursor: pointer; }
.stepper li.done .num { background: var(--bv-green); border-color: var(--bv-green); color: #fff; }
@media (max-width: 640px) {
    .stepper li:not(.active) .lbl { display: none; }
    .stepper li:not(:last-child)::after { margin: 0 6px; }
}

/* One button size across the page: same height, same minimum width, pill shaped.
   Use .bv-btn with .bv-btn--primary (filled) or .bv-btn--outline. */
.bv-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 150px; max-width: 100%; height: var(--bv-btn-height); padding: 0 14px; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); font: inherit; font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; white-space: nowrap; cursor: pointer; transition: background .15s, color .15s; }
.bv-btn--primary { background: var(--bv-brown); color: #fff; }
.bv-btn--primary:hover { background: var(--bv-brown-dark); }
.bv-btn--outline { background: transparent; color: var(--bv-brown); }
.bv-btn--outline:hover { background: var(--bv-sand); }
/* Back stays on the left, Next / Submit on the right - same spot on every step */
.step-nav { display: flex; justify-content: flex-end; gap: 10px; margin-top: 28px; }
.step-nav #backBtn { margin-right: auto; }

/* ---------- 5. Step 1 - treatment slider and cards ---------- */
.choice-tabs { display: flex; gap: 8px; margin: 0 0 22px; border-bottom: 1px solid var(--bv-line); }
.choice-tab { margin-bottom: -1px; padding: 12px 20px; border: 0; border-bottom: 2px solid transparent; background: none; color: var(--bv-muted); font: inherit; font-size: 14px; cursor: pointer; }
.choice-tab.active { border-bottom-color: var(--bv-brown); color: var(--bv-brown); font-weight: 600; }
.choice-tab .count { display: none; margin-left: 6px; padding: 1px 7px; border-radius: var(--bv-radius-pill); background: var(--bv-green); color: #fff; font-size: 12px; }
.choice-tab .count.show { display: inline-block; }
.choice-panel { display: none; }
.choice-panel.active { display: block; }

/* Slider: a horizontally scrolling row of cards. Arrows and dots are added by booking.js. */
.slider { position: relative; }
.choice-list { display: flex; gap: 20px; margin: 0; padding: 18px 6px 26px; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x mandatory; scroll-behavior: smooth; scrollbar-width: none; }
.choice-list::-webkit-scrollbar { display: none; }
.slider-btn { position: absolute; top: 32%; z-index: 5; display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; padding: 0; border: 1px solid var(--bv-line-strong); border-radius: 50%; background: var(--bv-card); color: var(--bv-brown); font-size: 24px; line-height: 1; cursor: pointer; box-shadow: 0 6px 16px rgba(63, 45, 32, .14); transition: opacity .2s; }
.slider-btn:hover { background: var(--bv-brown); color: #fff; }
.slider-btn.prev { left: -16px; }
.slider-btn.next { right: -16px; }
.slider-btn:disabled { opacity: 0; pointer-events: none; }
.slider-dots { display: flex; justify-content: center; gap: 8px; margin-top: 2px; }
.slider-dots button { width: 8px; height: 8px; padding: 0; border: 0; border-radius: var(--bv-radius-pill); background: var(--bv-line-strong); cursor: pointer; transition: width .25s, background .25s; }
.slider-dots button.active { width: 26px; background: var(--bv-brown); }

/* Treatment / package card */
.choice-card { position: relative; display: flex; flex: 0 0 clamp(230px, 31.5%, 300px); flex-direction: column; padding: 14px 14px 18px; border: 1px solid var(--bv-line); border-radius: 28px; background: var(--bv-card); font-weight: 400; cursor: pointer; scroll-snap-align: start; transition: transform .3s ease, box-shadow .3s ease, border-color .2s; }
.choice-card input { position: absolute; opacity: 0; pointer-events: none; }
.choice-card:hover { z-index: 2; transform: translateY(-4px) scale(1.03); box-shadow: 0 16px 30px rgba(90, 64, 48, .16); }
.choice-card.selected { border-color: var(--bv-green); background: var(--bv-green-soft); box-shadow: 0 0 0 1px var(--bv-green) inset; }
.choice-badge { position: absolute; top: 24px; right: 24px; z-index: 1; padding: 3px 9px; border-radius: var(--bv-radius-pill); background: var(--bv-brown); color: #fff; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; }

.choice-thumb { display: flex; align-items: center; justify-content: center; aspect-ratio: 1 / 1.12; overflow: hidden; border-radius: 50% 50% 16px 16px / 40% 40% 16px 16px; background: linear-gradient(160deg, #f1e6d6, #d8c4a8); }
.choice-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform .6s ease; }
.choice-card:hover .choice-thumb img { transform: scale(1.08); }
.thumb-ph { color: #a8896a; font-size: 44px; }

.choice-body { display: flex; flex: 1; flex-direction: column; gap: 8px; padding: 16px 6px 0; }
.choice-name { padding: 0; color: var(--bv-ink); font-family: var(--bv-font-display); font-size: 21px; font-weight: 600; line-height: 1.2; }
.choice-desc { display: -webkit-box; min-height: 4.65em; overflow: hidden; color: var(--bv-muted); font-size: 13px; line-height: 1.55; -webkit-line-clamp: 3; -webkit-box-orient: vertical; }
.choice-facts { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 10px; border-top: 1px dashed #e0d4c3; }
.choice-facts .fact { color: var(--bv-muted); font-size: 13px; }
.choice-facts .fact.price { color: var(--bv-brown); font-size: 17px; font-weight: 700; }

.choice-btn { display: flex; align-items: center; justify-content: center; height: var(--bv-btn-height); margin-top: auto; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); background: var(--bv-sand); color: var(--bv-brown); font-size: 12px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; }
.choice-btn .on { display: none; }
.choice-card:hover .choice-btn { background: var(--bv-brown); color: #fff; }
.choice-card.selected .choice-btn,
.choice-card.selected:hover .choice-btn { border-color: var(--bv-green); background: var(--bv-green); color: #fff; }
.choice-card.selected .choice-btn .on { display: inline; }
.choice-card.selected .choice-btn .off { display: none; }
.choice-card input:focus-visible ~ .choice-body .choice-btn { outline: 2px solid var(--bv-green); outline-offset: 2px; }

@media (prefers-reduced-motion: reduce) {
    .choice-card, .choice-card:hover, .choice-thumb img { transition: none; transform: none !important; }
}

/* ---------- 6. Step 2 - calendar and time slots ----------
   Both cards share .datetime-card, so they have the same height, the same
   header row, and their footers (note / legend) sit on the same line.
   The weekday row lines up with the first row of time slots. */
.datetime-layout { display: grid; grid-template-columns: minmax(320px, 380px) minmax(320px, 460px); gap: 24px; align-items: stretch; }
.datetime-card { display: flex; flex-direction: column; padding: 20px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }
.datetime-card-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; height: 40px; margin-bottom: 12px; }
.datetime-card-head h4 { margin: 0; color: var(--bv-ink); font-family: var(--bv-font-display); font-size: 22px; font-weight: 600; line-height: 1.2; }

/* Calendar: month and year are drop-downs, so any date is reachable without paging */
.calendar-nav { flex: 0 0 34px; width: 34px; height: 34px; padding: 0; border: 1px solid var(--bv-line); border-radius: 50%; background: #fff; color: var(--bv-brown); font-size: 18px; line-height: 1; cursor: pointer; }
.calendar-nav:hover:not(:disabled) { border-color: var(--bv-brown); }
.calendar-nav:disabled { opacity: .3; cursor: not-allowed; }
.calendar-selects { display: flex; align-items: center; gap: 2px; }
.calendar-select { width: auto !important; height: 34px !important; margin: 0 !important; padding: 0 26px 0 10px !important; border: 1px solid transparent !important; border-radius: var(--bv-radius-pill) !important; background: var(--bv-card) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path d='M1 1l4 4 4-4' fill='none' stroke='%235a4030' stroke-width='1.5' stroke-linecap='round'/></svg>") no-repeat right 9px center !important; box-shadow: none !important; color: var(--bv-ink); font-family: var(--bv-font-display); font-size: 20px; font-weight: 600; cursor: pointer; -webkit-appearance: none; appearance: none; }
.calendar-select:hover, .calendar-select:focus { border-color: var(--bv-line-strong) !important; outline: none; }
.calendar-weekdays, .calendar-days { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
.calendar-weekdays span { padding: 6px 0 8px; color: var(--bv-muted-soft); font-size: 12px; letter-spacing: .1em; text-transform: uppercase; }
.calendar-days { grid-auto-rows: 38px; }
.calendar-day { position: relative; padding: 0; border: 0; border-radius: 50%; background: none; color: var(--bv-ink); font: inherit; font-size: 14px; cursor: pointer; transition: background .15s; }
.calendar-day:hover:not(:disabled):not(.is-selected) { background: var(--bv-sand); }
.calendar-day:disabled { color: #c3b8aa; cursor: not-allowed; }
.calendar-day.is-today::after { content: ""; position: absolute; bottom: 3px; left: 50%; width: 4px; height: 4px; margin-left: -2px; border-radius: 50%; background: var(--bv-gold); }
.calendar-day.is-selected { background: var(--bv-brown); color: #fff; }
.calendar-day.is-selected::after { background: #fff; }
.calendar-note { margin: auto 0 0; padding-top: 12px; color: var(--bv-muted-soft); font-size: 12px; text-align: center; }

/* Time slots: 3 even, medium-sized buttons per row */
.time-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.time-slot { position: relative; display: block; margin: 0; padding: 0; border: 0; border-radius: 0; background: none; box-shadow: none; font-weight: 400; }
.time-slot input { position: absolute; width: 0; height: 0; opacity: 0; pointer-events: none; }
.time-slot > span { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1px; min-height: 46px; border: 1px solid var(--bv-line-strong); border-radius: 10px; background: var(--bv-card); color: var(--bv-ink); font-size: 14px; font-weight: 500; cursor: pointer; transition: border-color .15s, background .15s; }
.time-slot.is-available > span:hover { border-color: var(--bv-brown); }
.time-slot input:checked + span { border-color: var(--bv-brown); background: var(--bv-brown); color: #fff; }
.time-slot.is-booked > span,
.time-slot.is-passed > span { border-color: #ece3d6; background: #f4efe9; color: var(--bv-muted-soft); cursor: not-allowed; }
.time-slot em { font-size: 11px; font-style: normal; letter-spacing: .03em; }
.time-slot.is-booked em { color: var(--bv-red); }
.time-hint { margin: 12px 0 0; color: var(--bv-muted); font-size: 13px; }
.time-hint:empty { display: none; }
.time-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px 18px; margin: auto 0 0; padding: 12px 0 0; list-style: none; color: var(--bv-muted); font-size: 12px; }
.time-legend li { display: flex; align-items: center; gap: 7px; }
.legend-swatch { width: 12px; height: 12px; border: 1px solid var(--bv-line-strong); border-radius: 4px; background: var(--bv-card); }
.legend-swatch.is-selected { border-color: var(--bv-brown); background: var(--bv-brown); }
.legend-swatch.is-booked { border-color: #ece3d6; background: #f4efe9; }
@media (max-width: 900px) { .datetime-layout { grid-template-columns: 1fr; } }

/* ---------- 7. Step 4 - payment ---------- */
.pay-label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; }
.pay-select { position: relative; margin-bottom: 24px; }
.pay-select .field-error { margin-left: 20px; }
.pay-trigger { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: 100%; padding: 16px 20px; border: 1px solid var(--bv-line-strong); border-radius: var(--bv-radius-pill); background: var(--bv-card); color: var(--bv-muted); font: inherit; font-size: 15px; text-align: left; cursor: pointer; transition: border-color .15s; }
.pay-trigger:hover, .pay-trigger[aria-expanded="true"] { border-color: var(--bv-brown); }
.pay-trigger.has-value { color: var(--bv-ink); font-weight: 600; }
.pay-trigger.invalid { border-color: var(--bv-red); background: var(--bv-red-soft); }
.pay-caret { color: var(--bv-brown); font-size: 14px; transition: transform .2s; }
.pay-trigger[aria-expanded="true"] .pay-caret { transform: rotate(180deg); }
.pay-options { position: absolute; top: calc(100% - 2px); right: 0; left: 0; z-index: 30; margin: 0; padding: 8px; list-style: none; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); box-shadow: 0 14px 30px rgba(63, 45, 32, .16); }
.pay-options[hidden], .pay-box[hidden], .pay-detail[hidden], .prov-panel[hidden], .pay-fields-wrap[hidden] { display: none; }
.pay-option { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border-radius: 14px; outline: none; cursor: pointer; }
.pay-option:hover, .pay-option:focus-visible { background: var(--bv-sand-soft); }
.pay-option.selected { background: var(--bv-green-soft); }
.pay-option.selected .pay-icon { background: var(--bv-green); color: #fff; }
.pay-icon { display: flex; flex: 0 0 40px; align-items: center; justify-content: center; height: 40px; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); font-size: 16px; font-weight: 600; }
.pay-text strong { display: block; font-family: inherit; font-size: 15px; line-height: 1.25; }
.pay-text small { display: block; margin-top: 3px; color: var(--bv-muted); font-size: 12px; }

.pay-box { padding: 22px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }
.pay-amounts { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.pay-amounts div { padding: 14px 16px; border-radius: 14px; background: var(--bv-sand-soft); }
.pay-amounts span { display: block; color: var(--bv-muted); font-size: 12px; }
.pay-amounts strong { display: block; margin-top: 4px; color: var(--bv-brown); font-size: 20px; }
.pay-note { margin: 14px 2px 0; color: var(--bv-muted); font-size: 13px; }
.pay-detail { margin: 22px 0 0; padding-top: 22px; border-top: 1px dashed #e0d4c3; }
.pay-sub { margin-bottom: 12px; font-size: 13px; font-weight: 600; }
.pay-text-block { margin: 0; color: var(--bv-muted); font-size: 14px; line-height: 1.6; }

/* Cash instructions */
.pay-steps, .next-box ol { display: grid; gap: 16px; margin: 0; padding: 0; list-style: none; counter-reset: step; }
.pay-steps { margin-bottom: 20px; }
.pay-steps li, .next-box li { position: relative; display: grid; gap: 2px; padding-left: 44px; counter-increment: step; }
.pay-steps li::before, .next-box li::before { content: counter(step); position: absolute; top: 0; left: 0; display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); font-size: 13px; font-weight: 600; }
.pay-steps li strong, .next-box li strong { color: var(--bv-ink); font-size: 14px; }
.pay-steps li span, .next-box li span { color: var(--bv-muted); font-size: 13px; line-height: 1.55; }
.pay-where { display: grid; gap: 10px; margin-bottom: 16px; padding: 16px 18px; border-radius: 14px; background: var(--bv-sand-soft); }
.pay-where div { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; }
.pay-where span { color: var(--bv-muted); white-space: nowrap; }
.pay-where strong { font-weight: 600; text-align: right; }

/* E-wallet / bank choices */
.prov-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
.prov-row { position: relative; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid var(--bv-line); border-radius: 16px; background: var(--bv-card); font-weight: 400; cursor: pointer; transition: border-color .15s; }
.prov-row:hover { border-color: var(--bv-brown); }
.prov-row input { position: absolute; opacity: 0; pointer-events: none; }
.prov-logo { display: flex; flex: 0 0 36px; align-items: center; justify-content: center; height: 36px; border-radius: 50%; background: var(--bv-sand); color: var(--bv-brown); font-size: 14px; font-weight: 700; }
.prov-name { flex: 1; font-size: 14px; font-weight: 600; }
.prov-radio { position: relative; flex: 0 0 18px; height: 18px; border: 1.5px solid #cdbfae; border-radius: 50%; }
.prov-row.selected { border-color: var(--bv-green); background: var(--bv-green-soft); box-shadow: 0 0 0 1px var(--bv-green) inset; }
.prov-row.selected .prov-logo { background: var(--bv-green); color: #fff; }
.prov-row.selected .prov-radio { border-color: var(--bv-green); }
.prov-row.selected .prov-radio::after { content: ""; position: absolute; inset: 3px; border-radius: 50%; background: var(--bv-green); }

.prov-panel { display: grid; grid-template-columns: 170px 1fr; gap: 26px; align-items: center; margin-top: 18px; padding: 22px; border: 1px solid var(--bv-line); border-radius: 22px; background: var(--bv-cream); }
.pay-qr { width: 170px; height: 170px; padding: 8px; border: 1px solid var(--bv-line); border-radius: 16px; background: #fff; object-fit: contain; }
.pay-qr.placeholder { display: flex; align-items: center; justify-content: center; border: 1.5px dashed #cdbfae; color: var(--bv-muted-soft); font-size: 13px; }
.prov-info dl { margin: 0; }
.prov-info dl > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 9px 0; border-bottom: 1px solid #f0e8dc; font-size: 14px; }
.prov-info dl > div:last-child { border-bottom: 0; }
.prov-info dt { color: var(--bv-muted); }
.prov-info dd { display: flex; align-items: center; gap: 10px; margin: 0; font-weight: 600; text-align: right; }
.prov-hint { margin: 0 0 10px; color: var(--bv-muted); font-size: 13px; line-height: 1.5; }
.prov-amount { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; padding: 12px 16px; border-radius: 14px; background: #fff; color: var(--bv-muted); font-size: 13px; }
.prov-amount strong { color: var(--bv-brown); font-size: 20px; }
.copy-btn { height: 32px; padding: 0 14px; border: 1px solid var(--bv-brown); border-radius: var(--bv-radius-pill); background: transparent; color: var(--bv-brown); font: inherit; font-size: 12px; cursor: pointer; }
.copy-btn.copied { border-color: var(--bv-green); background: var(--bv-green); color: #fff; }

/* Reference number and proof of payment */
.pay-fields-wrap { margin-top: 24px; padding-top: 24px; border-top: 1px dashed #e0d4c3; }
.file-drop { display: block; padding: 22px 16px; border: 1.5px dashed #cdbfae; border-radius: 16px; background: var(--bv-cream); font-weight: 400; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; }
.file-drop:hover { border-color: var(--bv-brown); }
.file-drop input { position: absolute; width: 0; height: 0; opacity: 0; pointer-events: none; }
.file-drop .file-title { display: block; color: var(--bv-ink); font-size: 14px; font-weight: 600; }
.file-drop .file-sub { display: block; margin-top: 4px; color: var(--bv-muted); font-size: 12px; }
.file-drop.has-file { border-style: solid; border-color: var(--bv-green); background: var(--bv-green-soft); }
.file-drop.invalid { border-color: var(--bv-red); background: var(--bv-red-soft); }

@media (max-width: 640px) {
    .pay-amounts { grid-template-columns: 1fr; }
    .prov-panel { grid-template-columns: 1fr; justify-items: center; }
    .prov-info { width: 100%; }
}

/* ---------- 8. Booking summary ----------
   A small floating panel. It stays one size: only the header (total) shows until
   the arrow is pressed; the details then scroll inside a fixed maximum height.
   Drag the header to move it (booking.js remembers the spot). */
.summary-card { position: fixed; right: 24px; bottom: 24px; z-index: 1000; width: 340px; max-width: calc(100vw - 24px); border: 1px solid #eadfce; border-radius: var(--bv-radius-card); background: var(--bv-cream); box-shadow: 0 18px 40px rgba(63, 45, 32, .22); }
.summary-card.is-dragging { opacity: .93; box-shadow: 0 24px 50px rgba(63, 45, 32, .3); }
.summary-head { display: flex; align-items: center; gap: 10px; padding: 10px 12px 10px 14px; cursor: grab; user-select: none; touch-action: none; }
.summary-card.is-dragging .summary-head { cursor: grabbing; }
.summary-head:focus-visible { outline: 2px solid var(--bv-green); outline-offset: -2px; border-radius: var(--bv-radius-card); }
.summary-grip { color: var(--bv-muted-soft); font-size: 16px; letter-spacing: -2px; }
.summary-title { flex: 1; min-width: 0; }
.summary-title strong { display: block; font-size: 14px; }
.summary-title small { display: block; overflow: hidden; color: var(--bv-muted); font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.summary-head-total { color: var(--bv-brown); font-size: 17px; white-space: nowrap; }
.summary-toggle { flex: 0 0 32px; width: 32px; height: 32px; padding: 0; border: 1px solid var(--bv-line-strong); border-radius: 50%; background: #fff; color: var(--bv-brown); font-size: 13px; cursor: pointer; transition: transform .2s; }
.summary-card.is-open .summary-toggle { transform: rotate(180deg); }
.summary-body { max-height: min(56vh, 440px); padding: 12px 16px 16px; overflow-y: auto; border-top: 1px solid #eadfce; }
.summary-body[hidden] { display: none; }

.summary-box { min-height: 48px; padding: 12px 14px; border-radius: 12px; background: #efebe6; }
.summary-meta { margin: 12px 0 0; font-size: 13px; }
.summary-meta div { display: flex; justify-content: space-between; gap: 10px; padding: 7px 0; border-bottom: 1px dashed #e0d4c3; }
.summary-meta dt { color: var(--bv-muted); }
.summary-meta dd { margin: 0; font-weight: 600; text-align: right; }
.summary-total { display: flex; align-items: baseline; justify-content: space-between; margin-top: 12px; }
.summary-total strong { color: var(--bv-brown); font-size: 20px; }
.summary-split { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
.summary-split div { padding: 10px 12px; border-radius: 12px; background: #efebe6; }
.summary-split span { display: block; color: var(--bv-muted); font-size: 12px; }
.summary-split strong { display: block; margin-top: 2px; color: var(--bv-brown); font-size: 15px; }

/* Price breakdown rows (built by booking.js) */
.breakdown { width: 100%; font-size: 13px; }
.breakdown .empty { color: var(--bv-muted); }
.breakdown .row { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 4px 0; }
.breakdown .row > span:first-child { flex: 1; min-width: 0; }
.breakdown .row > span:last-child { font-weight: 600; white-space: nowrap; }
.breakdown .row:not(.sub):not(:first-child) { margin-top: 8px; padding-top: 8px; border-top: 1px dashed #d8cdbd; }
.breakdown .row.sub { padding: 2px 0 2px 12px; color: var(--bv-muted); font-size: 12px; }
.breakdown .row.sub > span:last-child { font-weight: 400; }

@media (max-width: 640px) {
    .summary-card { right: 12px; bottom: 12px; left: 12px; width: auto; }
}

/* ---------- 9. Step 5 - review, next steps, policies ---------- */
.review-box, .next-box, .policy-box { margin-bottom: 24px; border: 1px solid var(--bv-line); border-radius: var(--bv-radius-card); background: var(--bv-card); }
.review-box { padding: 8px 20px; }
.review-box div { display: flex; justify-content: space-between; gap: 14px; padding: 13px 0; border-bottom: 1px solid #f0e8dc; font-size: 14px; }
.review-box div:last-child { border-bottom: 0; }
.review-box dt { color: var(--bv-muted); }
.review-box dd { margin: 0; font-weight: 600; text-align: right; word-break: break-word; }

.next-box, .policy-box { padding: 22px 24px; }
.next-box h4, .policy-box h4 { margin: 0 0 16px; font-size: 16px; }
.policy-box ul { display: grid; gap: 14px; margin: 0; padding: 0; list-style: none; }
.policy-box li { position: relative; display: grid; gap: 2px; padding-left: 16px; }
.policy-box li::before { content: ""; position: absolute; top: 8px; left: 0; width: 6px; height: 6px; border-radius: 50%; background: var(--bv-green); }
.policy-box li strong { color: var(--bv-ink); font-size: 13px; }
.policy-box li span { color: var(--bv-muted); font-size: 13px; line-height: 1.55; }

/* ---------- 10. Yes / No confirmation dialog (buttons use .bv-btn) ---------- */
dialog.cf { width: min(420px, 92vw); padding: 0; border: 0; border-radius: 24px; background: var(--bv-card); color: var(--bv-ink); box-shadow: 0 30px 80px rgba(63, 45, 32, .35); }
dialog.cf::backdrop { background: rgba(63, 45, 32, .55); }
.cf-body { padding: 32px 30px 6px; text-align: center; }
.cf-body h3 { margin: 0 0 8px; font-family: var(--bv-font-display); font-size: 28px; font-weight: 600; }
.cf-body p { margin: 0; color: var(--bv-muted); font-size: 14.5px; line-height: 1.6; }
.cf-foot { display: flex; justify-content: center; gap: 10px; padding: 22px 30px 28px; }
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
    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="booking-head">
        <h2>Reserve your visit</h2>
        <ol class="stepper" id="stepper">
        <li data-step="1" class="active"><span class="num">1</span><span class="lbl">Select Treatment</span></li>
        <li data-step="2"><span class="num">2</span><span class="lbl">Date &amp; Time</span></li>
        <li data-step="3"><span class="num">3</span><span class="lbl">Your Details</span></li>
        <li data-step="4"><span class="num">4</span><span class="lbl">Payment</span></li>
        <li data-step="5"><span class="num">5</span><span class="lbl">Confirmation</span></li>
    </ol>
    </div>

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

            <!-- STEP 2: date & time -->
            <div class="step-panel" data-step="2">
                <div class="step-head">
                    <h3>Choose a date &amp; time</h3>
                </div>
                <input type="hidden" name="appointment_date" id="appointment_date">

                <div class="datetime-layout">
                    <section class="datetime-card" aria-label="Choose a date">
                        <header class="datetime-card-head">
                            <button type="button" class="calendar-nav" id="calendarPrev" aria-label="Previous month">‹</button>
                            <div class="calendar-selects">
                                <select class="calendar-select" id="calendarMonth" aria-label="Month"></select>
                                <select class="calendar-select" id="calendarYear" aria-label="Year"></select>
                            </div>
                            <button type="button" class="calendar-nav" id="calendarNext" aria-label="Next month">›</button>
                        </header>
                        <div class="calendar-weekdays"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                        <div class="calendar-days" id="calendarDays"></div>
                        <p class="calendar-note">Closed on Mondays</p>
                    </section>

                    <section class="datetime-card" aria-label="Choose a time">
                        <header class="datetime-card-head">
                            <h4 id="selectedDateLabel">Select a date</h4>
                        </header>
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
                        <div class="pay-sub">How to pay your down payment</div>
                        <ol class="pay-steps">
                            <li><strong>Submit your request</strong><span>You will receive a booking code right away. Keep it handy.</span></li>
                            <li><strong>Pay at the spa front desk within <?= (int)HOLD_HOURS ?> hours</strong><span>Give your booking code and pay the <span class="js-due">₱0.00</span> down payment in cash. You will receive an official receipt.</span></li>
                            <li><strong>Get your confirmation</strong><span>Once the front desk records your payment, your booking is confirmed. You can check its status anytime on the Track my booking page.</span></li>
                        </ol>
                        <div class="pay-where">
                            <div><span>Where</span><strong><?= e($spaInfo['address']) ?></strong></div>
                            <div><span>Front desk hours</span><strong><?= e($spaInfo['hours']) ?></strong></div>
                            <div><span>Questions</span><strong><?= e($spaInfo['phone']) ?></strong></div>
                        </div>
                        <p class="pay-text-block">Reservations without a payment after <?= (int)HOLD_HOURS ?> hours are released automatically so the time slot can be booked by someone else.</p>
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

                <div class="next-box">
                    <h4>What happens next</h4>
                    <ol>
                        <li><strong>Pay your down payment</strong><span id="nextPay"></span></li>
                        <li><strong>We verify and confirm</strong><span>Your booking turns Confirmed once the down payment is verified.</span></li>
                        <li><strong>Track your booking</strong><span>Use your booking code on the Track my booking page to see its status anytime.</span></li>
                    </ol>
                </div>

                
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

                    
                </div>
            </div>

            <div class="step-nav">
                <button type="button" class="bv-btn bv-btn--outline" id="backBtn" style="display:none;">← Back</button>
                <button type="button" class="bv-btn bv-btn--primary" id="nextBtn">Next <span>→</span></button>
                <button type="submit" class="bv-btn bv-btn--primary" id="submitBtn" style="display:none;">Submit <span>→</span></button>
            </div>
        </div>

        <!-- Floating booking summary: drag the header to move it, press the arrow for details -->
        <aside class="summary-card" id="summaryCard" aria-label="Booking summary">
            <div class="summary-head" id="summaryHandle" tabindex="0" title="Drag to move">
                <span class="summary-grip" aria-hidden="true">⋮⋮</span>
                <div class="summary-title">
                    <strong>Booking Summary</strong>
                    <small id="summaryCount">No treatment yet</small>
                </div>
                <strong class="summary-head-total" id="summaryHeadTotal">₱0.00</strong>
                <button type="button" class="summary-toggle" id="summaryToggle" aria-expanded="false" aria-controls="summaryBody" aria-label="Show booking details">▾</button>
            </div>
            <div class="summary-body" id="summaryBody" hidden>
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
            </div>
        </aside>

    </div>
    </form>
</section>
</main>

<footer class="footer compact-footer">
    <small>© <?= date('Y') ?> Bella Vista Oasis Spa</small>
</footer>

<dialog id="confirmDialog" class="cf">
    <div class="cf-body"><h3 id="cfTitle">Please confirm</h3><p id="cfText"></p></div>
    <div class="cf-foot">
        <button type="button" class="bv-btn bv-btn--outline" id="cfNo">No</button>
        <button type="button" class="bv-btn bv-btn--primary" id="cfYes">Yes</button>
    </div>
</dialog>

<script src="assets/script.js"></script>

<script>
/* Settings from PHP, used by the script below */
window.BOOKING_CONFIG = <?= json_encode($bookingConfig, JSON_UNESCAPED_UNICODE) ?>;

/**
 * Bella Vista Oasis Spa - booking form behaviour.
 *
 * Loaded by book.php after it defines window.BOOKING_CONFIG
 * (down payment rates, time slots, hold hours, ...).
 *
 * Sections
 *   1. Configuration and element references
 *   2. Small helpers
 *   3. Field validation (name, email, phone, reference number)
 *   4. Calendar
 *   5. Time slots
 *   6. Treatments, price breakdown and summary
 *   7. Payment
 *   8. Treatment slider
 *   9. Floating booking summary (drag + collapse)
 *  10. Yes / No confirmation dialog
 *  11. Step wizard, keyboard and submit handling
 *  12. Start-up
 */
(function () {
    'use strict';

    /* =====================================================================
       1. Configuration and element references
       ===================================================================== */
    const config = window.BOOKING_CONFIG;
    const TOTAL_STEPS = 5;
    const ONLINE_PAYMENT_METHODS = ['ewallet', 'bank'];   // need a reference number + proof of payment
    const MAX_PROOF_FILE_BYTES = 5 * 1024 * 1024;
    const ALLOWED_PROOF_TYPES = /^(image\/(jpeg|png|webp)|application\/pdf)$/;

    const byId = id => document.getElementById(id);

    const bookingForm = byId('bookingForm');
    const nameInput = byId('customer_name');
    const emailInput = byId('email');
    const phoneInput = byId('phone');
    const dateInput = byId('appointment_date');        // hidden, filled by the calendar
    const guestsSelect = byId('guests');
    const notesInput = byId('notes');

    const calendarMonthSelect = byId('calendarMonth');
    const calendarYearSelect = byId('calendarYear');
    const calendarDays = byId('calendarDays');
    const calendarPrev = byId('calendarPrev');
    const calendarNext = byId('calendarNext');
    const selectedDateLabel = byId('selectedDateLabel');
    const timeGrid = byId('timeGrid');
    const timeHint = byId('timeHint');

    const paymentMethodInput = byId('payment_method');
    const paymentProviderInput = byId('payment_provider');
    const paymentTrigger = byId('payTrigger');
    const paymentOptions = byId('payOptions');
    const paymentBox = byId('payBox');
    const paymentFields = byId('payFields');
    const referenceInput = byId('reference_no');
    const proofInput = byId('proof_image');
    const proofDropZone = byId('fileDrop');
    const providerRadios = Array.from(document.querySelectorAll('.prov-row input'));

    const stepPanels = Array.from(document.querySelectorAll('.step-panel'));
    const stepperItems = Array.from(document.querySelectorAll('#stepper li'));
    const backButton = byId('backBtn');
    const nextButton = byId('nextBtn');
    const submitButton = byId('submitBtn');

    let currentStep = 1;
    let currentTotal = 0;                              // estimated total of the selected treatments


    /* =====================================================================
       2. Small helpers
       ===================================================================== */
    const padTwo = number => String(number).padStart(2, '0');

    const formatPeso = amount =>
        '₱' + amount.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const escapeHtml = text => String(text).replace(/[&<>"']/g, character =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));

    /** Today as YYYY-MM-DD in the visitor's own time zone. */
    function todayISO() {
        const now = new Date();
        return now.getFullYear() + '-' + padTwo(now.getMonth() + 1) + '-' + padTwo(now.getDate());
    }

    const dateFromISO = iso => new Date(iso + 'T00:00:00');
    const isClosedDay = date => date.getDay() === config.closedWeekday;

    /** "13:30" -> "1:30 PM" */
    function formatTime(time24h) {
        const [hours, minutes] = time24h.split(':').map(Number);
        return ((hours % 12) || 12) + ':' + padTwo(minutes) + ' ' + (hours >= 12 ? 'PM' : 'AM');
    }

    /** Shows (or clears) an error message and colours the related input. */
    function showError(input, errorElementId, message) {
        byId(errorElementId).textContent = message;
        if (input) {
            input.classList.toggle('invalid-input', !!message);
            input.classList.toggle('valid-input', !message && input.value.length > 0);
        }
    }

    const getSelectedTimeInput = () => document.querySelector('input[name="appointment_time"]:checked');


    /* =====================================================================
       3. Field validation
       Each rule returns an error message, or '' when the value is fine.
       ===================================================================== */
    const NAME_PATTERN = /^[a-zA-ZÀ-ÿ][a-zA-ZÀ-ÿ\s.'-]*$/;
    const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const PHONE_PATTERN = /^(09\d{9}|639\d{9})$/;
    const REFERENCE_PATTERN = /^[A-Za-z0-9-]{6,25}$/;

    function checkName(value) {
        if (/^\d/.test(value)) return 'Name must not start with a number.';
        if (!/^[a-zA-ZÀ-ÿ]/.test(value)) return 'Name must start with a letter.';
        if (!NAME_PATTERN.test(value)) return 'Please enter a valid name.';
        return '';
    }

    function checkEmail(value) {
        if (!value.includes('@')) return 'Email must contain an @ symbol.';
        if (!EMAIL_PATTERN.test(value)) return 'Please enter a valid email address.';
        return '';
    }

    function checkPhone(value) {
        const startsCorrectly = /^(09|639)/.test(value) || ['0', '6', '63'].includes(value);
        if (!startsCorrectly) return 'Phone number must start with 09 or 639.';
        if (value.startsWith('09') && value.length > 11) return 'Numbers starting with 09 have 11 digits.';
        if (!PHONE_PATTERN.test(value)) return 'Enter all 11 digits (09) or 12 digits (639).';
        return '';
    }

    function checkReference(value) {
        return REFERENCE_PATTERN.test(value) ? '' : 'Use 6–25 letters or numbers only.';
    }

    /** Validation rules for the text inputs, keyed by input id. */
    const FIELD_RULES = {
        customer_name: { errorId: 'nameError',  requiredMessage: 'Please enter your full name.',      check: checkName },
        email:         { errorId: 'emailError', requiredMessage: 'Please enter your email address.',  check: checkEmail },
        phone:         { errorId: 'phoneError', requiredMessage: 'Please enter your phone number.',   check: checkPhone },
        reference_no:  { errorId: 'refError',   requiredMessage: 'Please enter the reference number.', check: checkReference }
    };

    /** Error message for an input; an empty value only counts as an error when requireValue is true. */
    function getFieldMessage(input, requireValue) {
        const rule = FIELD_RULES[input.id];
        const value = input.id === 'phone' ? input.value : input.value.trim();
        if (!value) return requireValue ? rule.requiredMessage : '';
        return rule.check(value);
    }

    /** Validates an input, shows its message, and returns true when it is filled in correctly. */
    function validateField(input, requireValue = true) {
        const message = getFieldMessage(input, requireValue);
        showError(input, FIELD_RULES[input.id].errorId, message);
        return !message && input.value.trim() !== '';
    }

    /** Same check without touching the page - used to decide where the Enter key goes next. */
    const isFieldValid = input => !(FIELD_RULES[input.id] && getFieldMessage(input, true));

    function validateDate(requireValue) {
        const value = dateInput.value;
        let message = '';
        if (!value) {
            if (requireValue) message = 'Please choose an appointment date.';
        } else if (value < todayISO()) {
            message = 'You cannot book a past date.';
        } else if (isClosedDay(dateFromISO(value))) {
            message = 'The spa is closed on Mondays. Please choose Tuesday to Sunday.';
        }
        showError(dateInput, 'dateError', message);
        return !message && !!value;
    }


    /* =====================================================================
       4. Calendar
       Month and year are drop-downs, so any date is reachable without paging.
       ===================================================================== */
    const MONTH_NAMES = Array.from({ length: 12 }, (_, month) =>
        new Date(2000, month, 1).toLocaleDateString('en-US', { month: 'long' }));
    const visibleMonth = new Date();
    visibleMonth.setDate(1);

    const lastAllowedYear = () => new Date().getFullYear() + config.maxYearsAhead;

    /** Keeps the shown month between this month and December of the last allowed year. */
    function clampVisibleMonth() {
        const now = new Date();
        const isBeforeThisMonth = visibleMonth.getFullYear() * 12 + visibleMonth.getMonth() < now.getFullYear() * 12 + now.getMonth();
        if (isBeforeThisMonth) visibleMonth.setFullYear(now.getFullYear(), now.getMonth(), 1);
        if (visibleMonth.getFullYear() > lastAllowedYear()) visibleMonth.setFullYear(lastAllowedYear(), 11, 1);
    }

    function fillCalendarSelects() {
        calendarMonthSelect.innerHTML = MONTH_NAMES.map((name, index) => `<option value="${index}">${name}</option>`).join('');
        let yearOptions = '';
        for (let year = new Date().getFullYear(); year <= lastAllowedYear(); year++) {
            yearOptions += `<option value="${year}">${year}</option>`;
        }
        calendarYearSelect.innerHTML = yearOptions;
    }

    function renderCalendar() {
        const year = visibleMonth.getFullYear();
        const month = visibleMonth.getMonth();
        const firstWeekday = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = todayISO();
        const selectedDate = dateInput.value;
        const now = new Date();

        calendarYearSelect.value = year;
        calendarMonthSelect.value = month;
        Array.from(calendarMonthSelect.options).forEach(option => {     // earlier months of this year are not bookable
            option.disabled = year === now.getFullYear() && Number(option.value) < now.getMonth();
        });

        let html = '<span></span>'.repeat(firstWeekday);               // empty cells before the 1st
        for (let day = 1; day <= daysInMonth; day++) {
            const iso = year + '-' + padTwo(month + 1) + '-' + padTwo(day);
            const isUnavailable = iso < today || isClosedDay(new Date(year, month, day));
            const classes = ['calendar-day'];
            if (iso === today) classes.push('is-today');
            if (iso === selectedDate) classes.push('is-selected');
            html += `<button type="button" class="${classes.join(' ')}" data-date="${iso}"${isUnavailable ? ' disabled' : ''}>${day}</button>`;
        }
        calendarDays.innerHTML = html;

        calendarPrev.disabled = year === now.getFullYear() && month <= now.getMonth();
        calendarNext.disabled = year >= lastAllowedYear() && month === 11;
    }

    function changeVisibleMonth(offset) {
        visibleMonth.setMonth(visibleMonth.getMonth() + offset);
        clampVisibleMonth();
        renderCalendar();
    }

    function handleMonthSelect() {
        visibleMonth.setMonth(Number(calendarMonthSelect.value));
        clampVisibleMonth();
        renderCalendar();
    }

    function handleYearSelect() {
        visibleMonth.setFullYear(Number(calendarYearSelect.value));
        clampVisibleMonth();
        renderCalendar();
    }

    function handleDayClick(event) {
        const dayButton = event.target.closest('.calendar-day');
        if (!dayButton || dayButton.disabled) return;
        dateInput.value = dayButton.dataset.date;
        dateInput.dispatchEvent(new Event('change'));
        renderCalendar();
    }


    /* =====================================================================
       5. Time slots
       ===================================================================== */

    /** 'available', 'booked' (taken by someone else) or 'passed' (earlier today). */
    function getSlotState(time, bookedTimes, dateISO) {
        if (bookedTimes.includes(time)) return 'booked';
        if (dateISO === todayISO()) {
            const [hours, minutes] = time.split(':').map(Number);
            const now = new Date();
            if (hours * 60 + minutes <= now.getHours() * 60 + now.getMinutes()) return 'passed';
        }
        return 'available';
    }

    function buildTimeSlotHtml(time, state) {
        const stateLabels = { booked: 'Booked', passed: 'Passed' };
        const label = stateLabels[state] ? `<em>${stateLabels[state]}</em>` : '';
        const disabled = state === 'available' ? '' : ' disabled';
        return `<label class="time-slot is-${state}">
                    <input type="radio" name="appointment_time" value="${time}"${disabled}>
                    <span>${formatTime(time)}${label}</span>
                </label>`;
    }

    /** Asks the server which times are already booked on a date (null when it cannot be checked). */
    async function fetchBookedTimes(dateISO) {
        try {
            const response = await fetch(config.slotsUrl + encodeURIComponent(dateISO), { cache: 'no-store' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            return (await response.json()).booked || [];
        } catch (error) {
            return null;
        }
    }

    async function loadTimeSlots() {
        const dateISO = dateInput.value;
        timeGrid.innerHTML = '';
        showError(null, 'timeError', '');

        const hasValidDate = dateISO && dateISO >= todayISO() && !isClosedDay(dateFromISO(dateISO));
        if (!hasValidDate) {
            selectedDateLabel.textContent = 'Select a date';
            timeHint.textContent = 'Choose a date to see available times.';
            return;
        }

        selectedDateLabel.textContent = dateFromISO(dateISO)
            .toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });

        const bookedTimes = await fetchBookedTimes(dateISO);
        timeHint.textContent = bookedTimes === null
            ? 'We could not verify availability right now. The spa will confirm your time after you submit.'
            : '';

        timeGrid.innerHTML = config.timeSlots
            .map(time => buildTimeSlotHtml(time, getSlotState(time, bookedTimes || [], dateISO)))
            .join('');
    }

    function handleDateChange() {
        validateDate(true);
        loadTimeSlots().then(updateSummary);
        updateSummary();
    }


    /* =====================================================================
       6. Treatments, price breakdown and summary
       ===================================================================== */
    const getTreatmentInputs = () => Array.from(document.querySelectorAll('.choice-card input'));
    const getSelectedTreatments = () => getTreatmentInputs().filter(input => input.checked);

    /** First guest pays the full price, every extra guest pays config.extraGuestRate of it. */
    function calculateLineTotal(price, extraGuests) {
        return price + price * config.extraGuestRate * extraGuests;
    }

    function buildBreakdownRows(treatment, extraGuests) {
        const price = parseFloat(treatment.dataset.price) || 0;
        const extraGuestPrice = price * config.extraGuestRate;
        const packageTag = treatment.dataset.type === 'package' ? ' (Package)' : '';
        const extraPercent = Math.round(config.extraGuestRate * 100);

        let html = `<div class="row"><span>${escapeHtml(treatment.dataset.name)}${packageTag}</span><span>${formatPeso(calculateLineTotal(price, extraGuests))}</span></div>`;
        html += `<div class="row sub"><span>Guest 1 × ${formatPeso(price)}</span><span>${formatPeso(price)}</span></div>`;
        if (extraGuests > 0) {
            const plural = extraGuests > 1 ? 's' : '';
            html += `<div class="row sub"><span>${extraGuests} additional guest${plural} × ${formatPeso(extraGuestPrice)} (${extraPercent}%)</span><span>${formatPeso(extraGuestPrice * extraGuests)}</span></div>`;
        }
        return html;
    }

    function updateTreatmentTabs(selectedTreatments) {
        document.querySelectorAll('.choice-tab .count').forEach(badge => {
            const count = selectedTreatments.filter(input => input.dataset.type === badge.dataset.count).length;
            badge.textContent = count;
            badge.classList.toggle('show', count > 0);
        });
    }

    function renderSummary() {
        const guests = parseInt(guestsSelect.value, 10) || 1;
        const extraGuests = guests - 1;
        const selected = getSelectedTreatments();

        getTreatmentInputs().forEach(input => input.closest('.choice-card').classList.toggle('selected', input.checked));
        updateTreatmentTabs(selected);

        // Old single-service field kept for compatibility with save_booking.php
        const firstService = selected.find(input => input.dataset.type === 'service');
        byId('service_id').value = firstService ? firstService.value : '';

        byId('sumGuests').textContent = guests + ' guest' + (guests > 1 ? 's' : '');
        byId('summaryCount').textContent = selected.length
            ? selected.length + ' treatment' + (selected.length > 1 ? 's' : '') + ' · ' + guests + ' guest' + (guests > 1 ? 's' : '')
            : 'No treatment yet';
        byId('sumDate').textContent = dateInput.value
            ? dateFromISO(dateInput.value).toLocaleDateString('en-PH', { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' })
            : '—';
        const selectedTime = getSelectedTimeInput();
        byId('sumTime').textContent = selectedTime ? formatTime(selectedTime.value) : '—';

        const breakdown = byId('breakdown');
        if (!selected.length) {
            breakdown.innerHTML = '<div class="empty">No service selected yet</div>';
            byId('sumDuration').textContent = '—';
            byId('totalPrice').textContent = formatPeso(0);
            byId('summaryHeadTotal').textContent = formatPeso(0);
            currentTotal = 0;
            return;
        }

        currentTotal = selected.reduce((sum, treatment) =>
            sum + calculateLineTotal(parseFloat(treatment.dataset.price) || 0, extraGuests), 0);
        const totalMinutes = selected.reduce((sum, treatment) => sum + (parseInt(treatment.dataset.duration, 10) || 0), 0);

        breakdown.innerHTML = selected.map(treatment => buildBreakdownRows(treatment, extraGuests)).join('');
        byId('sumDuration').textContent = totalMinutes + ' min per guest';
        byId('totalPrice').textContent = formatPeso(currentTotal);
        byId('summaryHeadTotal').textContent = formatPeso(currentTotal);
        showError(null, 'serviceError', '');
    }

    function updateSummary() {
        renderSummary();
        updatePayment();
    }

    function switchTreatmentTab(clickedTab) {
        document.querySelectorAll('.choice-tab').forEach(tab => tab.classList.toggle('active', tab === clickedTab));
        document.querySelectorAll('.choice-panel').forEach(panel => panel.classList.toggle('active', panel.id === clickedTab.dataset.panel));
        setTimeout(refreshSliders, 40);                // dots/arrows need the panel to be visible first
    }


    /* =====================================================================
       7. Payment
       ===================================================================== */
    const getPaymentMethod = () => paymentMethodInput.value;
    const needsProofOfPayment = () => ONLINE_PAYMENT_METHODS.includes(getPaymentMethod());
    const getSelectedProvider = () =>
        providerRadios.find(radio => radio.checked && radio.dataset.method === getPaymentMethod()) || null;

    function openPaymentMenu() { paymentOptions.hidden = false; paymentTrigger.setAttribute('aria-expanded', 'true'); }
    function closePaymentMenu() { paymentOptions.hidden = true; paymentTrigger.setAttribute('aria-expanded', 'false'); }

    function choosePaymentMethod(value, label) {
        paymentMethodInput.value = value;
        byId('payTriggerText').textContent = label;
        paymentTrigger.classList.add('has-value');
        paymentTrigger.classList.remove('invalid');
        paymentOptions.querySelectorAll('.pay-option').forEach(option => option.classList.toggle('selected', option.dataset.value === value));
        showError(null, 'methodError', '');
        closePaymentMenu();
        updatePayment();
    }

    function validatePaymentMethod() {
        const isChosen = !!getPaymentMethod();
        showError(null, 'methodError', isChosen ? '' : 'Please choose a payment method.');
        paymentTrigger.classList.toggle('invalid', !isChosen);
        return isChosen;
    }

    function validateProvider() {
        if (!needsProofOfPayment()) return true;
        const isChosen = !!getSelectedProvider();
        const message = getPaymentMethod() === 'bank' ? 'Please choose a bank.' : 'Please choose an e-wallet.';
        showError(null, 'provError_' + getPaymentMethod(), isChosen ? '' : message);
        return isChosen;
    }

    function validateProof(requireFile) {
        const file = proofInput.files[0];
        let message = '';
        if (!file) {
            if (requireFile) message = 'Please upload your proof of payment.';
        } else if (!ALLOWED_PROOF_TYPES.test(file.type)) {
            message = 'Only JPG, PNG, WEBP or PDF files are allowed.';
        } else if (file.size > MAX_PROOF_FILE_BYTES) {
            message = 'File must be 5 MB or smaller.';
        }
        const isAccepted = !message && !!file;

        showError(null, 'proofError', message);
        proofDropZone.classList.toggle('invalid', !!message);
        proofDropZone.classList.toggle('has-file', isAccepted);
        byId('fileTitle').textContent = isAccepted ? file.name : 'Upload proof of payment';
        byId('fileSub').textContent = isAccepted ? 'Click to change file' : 'Screenshot or receipt · JPG, PNG or PDF · max 5 MB';
        return isAccepted;
    }

    function isPaymentValid() {
        if (!validatePaymentMethod()) return false;
        if (!needsProofOfPayment()) return true;
        // Run all three so every problem is shown at once
        const providerOk = validateProvider();
        const referenceOk = validateField(referenceInput, true);
        const proofOk = validateProof(true);
        return providerOk && referenceOk && proofOk;
    }

    function buildPaymentNote(method, downPayment, balance, percent) {
        const notes = {
            cash: `Reserve your slot with a ${percent}% down payment (${formatPeso(downPayment)}), paid in cash at the spa front desk within ${config.holdHours} hours. The remaining ${formatPeso(balance)} is paid at the spa.`,
            ewallet: `Send ${formatPeso(downPayment)} (${percent}% reservation fee) with your chosen e-wallet, then enter the reference number.`,
            bank: `Transfer ${formatPeso(downPayment)} (${percent}% reservation fee) to your chosen bank, then enter the reference number.`
        };
        return notes[method] || '';
    }

    /** Refreshes every payment figure and shows only the details of the chosen method/provider. */
    function updatePayment() {
        const method = getPaymentMethod();
        const rate = method ? (config.downPaymentRates[method] || 0) : 0;
        const downPayment = method ? Math.round(currentTotal * rate * 100) / 100 : 0;
        const balance = Math.max(currentTotal - downPayment, 0);
        const provider = getSelectedProvider();

        let paymentLabel = '—';
        if (method) {
            paymentLabel = paymentOptions.querySelector(`.pay-option[data-value="${method}"]`).dataset.label;
            if (provider) paymentLabel += ' · ' + provider.dataset.name;
        }

        document.querySelectorAll('.js-due').forEach(element => { element.textContent = formatPeso(downPayment); });
        document.querySelectorAll('.js-balance').forEach(element => { element.textContent = formatPeso(balance); });
        byId('sumDue').textContent = formatPeso(downPayment);
        byId('sumBalance').textContent = formatPeso(balance);
        byId('sumPayment').textContent = paymentLabel;
        byId('amount_due').value = downPayment.toFixed(2);
        paymentProviderInput.value = provider ? provider.value : '';
        byId('payNote').textContent = buildPaymentNote(method, downPayment, balance, Math.round(rate * 100));

        paymentBox.hidden = !method;
        document.querySelectorAll('.pay-detail').forEach(detail => { detail.hidden = detail.dataset.method !== method; });
        providerRadios.forEach(radio => radio.closest('.prov-row').classList.toggle('selected', radio.checked));
        document.querySelectorAll('.prov-panel').forEach(panel => {
            panel.hidden = !(provider && panel.dataset.method === method && panel.dataset.provider === provider.value);
        });

        const online = needsProofOfPayment();
        paymentFields.hidden = !online;
        referenceInput.disabled = !online;
        proofInput.disabled = !online;
        if (!online) {
            showError(referenceInput, 'refError', '');
            showError(null, 'proofError', '');
            proofDropZone.classList.remove('invalid');
        }
    }

    function copyToClipboard(button) {
        const showCopied = () => {
            button.textContent = 'Copied';
            button.classList.add('copied');
            setTimeout(() => { button.textContent = 'Copy'; button.classList.remove('copied'); }, 1500);
        };
        if (navigator.clipboard) navigator.clipboard.writeText(button.dataset.copy).then(showCopied, showCopied);
        else showCopied();
    }


    /* =====================================================================
       8. Treatment slider (arrows, dots, swipe)
       ===================================================================== */
    const SLIDER_GAP_PX = 20;

    /** Wraps one .choice-list in a slider with previous/next buttons and page dots. */
    function createSlider(list) {
        const wrapper = document.createElement('div');
        wrapper.className = 'slider';
        list.parentNode.insertBefore(wrapper, list);
        wrapper.appendChild(list);

        const previousButton = createSliderButton('prev', '&#8249;', 'Previous');
        const nextSliderButton = createSliderButton('next', '&#8250;', 'Next');
        const dots = document.createElement('div');
        dots.className = 'slider-dots';
        wrapper.append(previousButton, nextSliderButton, dots);

        const pageCount = () => Math.max(1, Math.ceil((list.scrollWidth - 8) / Math.max(list.clientWidth, 1)));
        const currentPage = () => Math.min(pageCount() - 1, Math.round(list.scrollLeft / Math.max(list.clientWidth, 1)));
        const scrollStep = () => {
            const card = list.querySelector('.choice-card');
            return card ? card.getBoundingClientRect().width + SLIDER_GAP_PX : list.clientWidth * 0.8;
        };

        function rebuildDots(count) {
            dots.innerHTML = '';
            for (let page = 0; page < count; page++) {
                const dot = document.createElement('button');
                dot.type = 'button';
                dot.setAttribute('aria-label', 'Go to slide ' + (page + 1));
                dot.addEventListener('click', () => list.scrollTo({ left: page * list.clientWidth, behavior: 'smooth' }));
                dots.appendChild(dot);
            }
        }

        function update() {
            if (!list.clientWidth) return;             // panel is hidden
            const pages = pageCount();
            if (dots.children.length !== pages) rebuildDots(pages);
            Array.from(dots.children).forEach((dot, index) => dot.classList.toggle('active', index === currentPage()));
            previousButton.disabled = list.scrollLeft <= 4;
            nextSliderButton.disabled = list.scrollLeft + list.clientWidth >= list.scrollWidth - 4;
            dots.style.display = pages > 1 ? 'flex' : 'none';
        }

        previousButton.addEventListener('click', () => list.scrollBy({ left: -scrollStep(), behavior: 'smooth' }));
        nextSliderButton.addEventListener('click', () => list.scrollBy({ left: scrollStep(), behavior: 'smooth' }));
        list.addEventListener('scroll', () => requestAnimationFrame(update), { passive: true });
        window.addEventListener('resize', update);
        list.updateSlider = update;
        update();
    }

    function createSliderButton(direction, symbol, label) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'slider-btn ' + direction;
        button.setAttribute('aria-label', label);
        button.innerHTML = symbol;
        return button;
    }

    function refreshSliders() {
        document.querySelectorAll('.choice-list').forEach(list => list.updateSlider && list.updateSlider());
    }


    /* =====================================================================
       9. Floating booking summary
       Drag the header to move it, press the arrow to show / hide the details.
       The position is remembered for the rest of the visit.
       ===================================================================== */
    const SUMMARY_POSITION_KEY = 'bvSummaryPosition';
    const SUMMARY_EDGE_GAP_PX = 8;
    const SUMMARY_KEY_STEP_PX = 24;

    function initSummaryPanel() {
        const card = byId('summaryCard');
        const handle = byId('summaryHandle');
        const toggle = byId('summaryToggle');
        const body = byId('summaryBody');
        let dragOffset = null;

        /** Moves the panel, keeping it fully inside the window. */
        function placeAt(left, top) {
            const maxLeft = Math.max(window.innerWidth - card.offsetWidth - SUMMARY_EDGE_GAP_PX, SUMMARY_EDGE_GAP_PX);
            const maxTop = Math.max(window.innerHeight - card.offsetHeight - SUMMARY_EDGE_GAP_PX, SUMMARY_EDGE_GAP_PX);
            card.style.left = Math.min(Math.max(left, SUMMARY_EDGE_GAP_PX), maxLeft) + 'px';
            card.style.top = Math.min(Math.max(top, SUMMARY_EDGE_GAP_PX), maxTop) + 'px';
            card.style.right = 'auto';
            card.style.bottom = 'auto';
        }

        function keepInView() {
            if (!card.style.left) return;                        // still in its default corner
            const box = card.getBoundingClientRect();
            placeAt(box.left, box.top);
        }

        function savePosition() {
            try { sessionStorage.setItem(SUMMARY_POSITION_KEY, JSON.stringify({ left: card.offsetLeft, top: card.offsetTop })); } catch (error) { /* private mode */ }
        }

        function restorePosition() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(SUMMARY_POSITION_KEY));
                if (saved) placeAt(saved.left, saved.top);
            } catch (error) { /* nothing saved */ }
        }

        function toggleDetails() {
            const willOpen = body.hidden;
            body.hidden = !willOpen;
            card.classList.toggle('is-open', willOpen);
            toggle.setAttribute('aria-expanded', String(willOpen));
            toggle.setAttribute('aria-label', willOpen ? 'Hide booking details' : 'Show booking details');
            requestAnimationFrame(keepInView);                   // the panel got taller / shorter
        }

        function stopDragging() {
            if (!dragOffset) return;
            dragOffset = null;
            card.classList.remove('is-dragging');
            savePosition();
        }

        function moveWithKeyboard(event) {
            const moves = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
            const move = moves[event.key];
            if (!move) return;
            event.preventDefault();
            const box = card.getBoundingClientRect();
            placeAt(box.left + move[0] * SUMMARY_KEY_STEP_PX, box.top + move[1] * SUMMARY_KEY_STEP_PX);
            savePosition();
        }

        toggle.addEventListener('click', toggleDetails);
        handle.addEventListener('pointerdown', event => {
            if (event.target.closest('button')) return;
            const box = card.getBoundingClientRect();
            dragOffset = { x: event.clientX - box.left, y: event.clientY - box.top };
            handle.setPointerCapture(event.pointerId);
            card.classList.add('is-dragging');
        });
        handle.addEventListener('pointermove', event => {
            if (dragOffset) placeAt(event.clientX - dragOffset.x, event.clientY - dragOffset.y);
        });
        handle.addEventListener('pointerup', stopDragging);
        handle.addEventListener('pointercancel', stopDragging);
        handle.addEventListener('keydown', moveWithKeyboard);
        window.addEventListener('resize', keepInView);
        restorePosition();
    }


    /* =====================================================================
       10. Yes / No confirmation dialog
       Resolves to true when the customer presses the "Yes" button.
       ===================================================================== */
    function askConfirm(title, message, yesLabel, noLabel) {
        return new Promise(resolve => {
            const dialog = byId('confirmDialog');
            const yesButton = byId('cfYes');
            const noButton = byId('cfNo');
            let confirmed = false;

            byId('cfTitle').textContent = title;
            byId('cfText').textContent = message;
            yesButton.textContent = yesLabel || 'Yes';
            noButton.textContent = noLabel || 'No';

            yesButton.onclick = () => { confirmed = true; dialog.close(); };
            noButton.onclick = () => dialog.close();
            const onClose = () => { dialog.removeEventListener('close', onClose); resolve(confirmed); };
            dialog.addEventListener('close', onClose);

            dialog.showModal();
            noButton.focus();
        });
    }


    /* =====================================================================
       11. Step wizard, keyboard and submit handling
       ===================================================================== */

    /** Fills the confirmation step with everything the customer entered. */
    function fillReview() {
        byId('nextPay').textContent = getPaymentMethod() === 'cash'
            ? `Pay the down payment in cash at the spa front desk within ${config.holdHours} hours.`
            : 'Our team will verify your payment and reference number.';

        const rows = [
            ['Name', nameInput.value.trim()],
            ['Email', emailInput.value.trim()],
            ['Phone', phoneInput.value],
            ['Date', byId('sumDate').textContent],
            ['Time', byId('sumTime').textContent],
            ['Guests', byId('sumGuests').textContent],
            ['Treatments', getSelectedTreatments().map(input => input.dataset.name).join(', ')],
            ['Notes', notesInput.value.trim() || '—'],
            ['Payment', byId('sumPayment').textContent],
            ...(needsProofOfPayment() ? [['Reference no.', referenceInput.value.trim()]] : []),
            ['Estimated total', byId('totalPrice').textContent],
            ['Pay now', byId('sumDue').textContent],
            ['Balance at the spa', byId('sumBalance').textContent]
        ];
        byId('reviewBox').innerHTML = rows
            .map(([label, value]) => `<div><dt>${label}</dt><dd>${escapeHtml(value)}</dd></div>`)
            .join('');
    }

    function goToStep(step) {
        currentStep = step;
        stepPanels.forEach(panel => panel.classList.toggle('active', +panel.dataset.step === step));
        stepperItems.forEach(item => {
            const itemStep = +item.dataset.step;
            item.classList.toggle('active', itemStep === step);
            item.classList.toggle('done', itemStep < step);
        });
        backButton.style.display = step > 1 ? '' : 'none';
        nextButton.style.display = step < TOTAL_STEPS ? '' : 'none';
        submitButton.style.display = step === TOTAL_STEPS ? '' : 'none';
        if (step === TOTAL_STEPS) fillReview();
        byId('stepper').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /** Validates one step and shows its errors. */
    function isStepValid(step) {
        switch (step) {
            case 1: {
                const hasTreatment = getSelectedTreatments().length > 0;
                showError(null, 'serviceError', hasTreatment ? '' : 'Please add at least one treatment or package.');
                return hasTreatment;
            }
            case 2: {
                const dateOk = validateDate(true);
                const hasTime = !!getSelectedTimeInput();
                showError(null, 'timeError', hasTime ? '' : 'Please select an available time.');
                return dateOk && hasTime;
            }
            case 3: {
                // Validate all three so every problem is shown at once
                const nameOk = validateField(nameInput);
                const emailOk = validateField(emailInput);
                const phoneOk = validateField(phoneInput);
                return nameOk && emailOk && phoneOk;
            }
            case 4:
                return isPaymentValid();
            default:
                return true;
        }
    }

    function handleStepperClick(item) {
        const targetStep = +item.dataset.step;
        if (targetStep > currentStep) {
            for (let step = currentStep; step < targetStep; step++) {
                if (!isStepValid(step)) { goToStep(step); return; }
            }
        }
        goToStep(targetStep);
    }

    /**
     * Enter key: check the current field, jump to the next field that still needs
     * input, and move to the next step only when everything on this step is valid.
     */
    function handleEnterKey(event) {
        if (event.key !== 'Enter') return;
        const target = event.target;
        if (target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON' || target.type === 'submit') return;
        event.preventDefault();
        if (currentStep >= TOTAL_STEPS) return;        // never submit by accident on the last step

        const activePanel = document.querySelector('.step-panel.active');
        const typeableFields = Array.from(activePanel.querySelectorAll('input, select')).filter(field =>
            !field.disabled && field.offsetParent !== null &&
            !['hidden', 'radio', 'checkbox', 'file', 'button', 'submit'].includes(field.type));

        const position = typeableFields.indexOf(target);
        if (position === -1) { nextButton.click(); return; }
        if (FIELD_RULES[target.id] && !validateField(target)) return;   // stay and show the error

        const fieldsAfter = typeableFields.slice(position + 1).concat(typeableFields.slice(0, position));
        const nextIncomplete = fieldsAfter.find(field => !isFieldValid(field));
        if (nextIncomplete) nextIncomplete.focus();
        else nextButton.click();
    }

    function handleSubmit(event) {
        if (bookingForm.dataset.confirmed === '1') return;          // customer already said "Yes"

        for (let step = 1; step <= 4; step++) {
            if (!isStepValid(step)) { event.preventDefault(); goToStep(step); return; }
        }
        event.preventDefault();
        if (!byId('terms').checked) {
            showError(null, 'termsError', 'Please confirm the booking guidelines.');
            return;
        }
        askConfirm('Submit your request?',
                   'Please make sure your details are correct. Your booking request will be sent to the spa.',
                   'Yes, submit', 'No, go back')
            .then(confirmed => {
                if (!confirmed) return;
                bookingForm.dataset.confirmed = '1';
                bookingForm.requestSubmit();
            });
    }


    /* =====================================================================
       12. Start-up: connect everything and draw the first screen
       ===================================================================== */
    function init() {
        // Personal details
        [nameInput, emailInput].forEach(input => {
            input.addEventListener('input', () => validateField(input));
            input.addEventListener('blur', () => validateField(input));
        });
        phoneInput.addEventListener('input', () => {
            phoneInput.value = phoneInput.value.replace(/\D/g, '').slice(0, 12);   // digits only
            validateField(phoneInput);
        });
        phoneInput.addEventListener('blur', () => validateField(phoneInput));

        // Calendar and time slots
        dateInput.min = todayISO();
        calendarDays.addEventListener('click', handleDayClick);
        calendarPrev.addEventListener('click', () => changeVisibleMonth(-1));
        calendarNext.addEventListener('click', () => changeVisibleMonth(1));
        calendarMonthSelect.addEventListener('change', handleMonthSelect);
        calendarYearSelect.addEventListener('change', handleYearSelect);
        dateInput.addEventListener('change', handleDateChange);
        timeGrid.addEventListener('change', updateSummary);
        fillCalendarSelects();
        renderCalendar();

        // Treatments and guests
        getTreatmentInputs().forEach(input => input.addEventListener('change', updateSummary));
        guestsSelect.addEventListener('change', updateSummary);
        document.querySelectorAll('.choice-tab').forEach(tab => tab.addEventListener('click', () => switchTreatmentTab(tab)));
        document.querySelectorAll('.choice-list').forEach(createSlider);
        window.addEventListener('load', refreshSliders);

        // Payment
        paymentTrigger.addEventListener('click', () => (paymentOptions.hidden ? openPaymentMenu() : closePaymentMenu()));
        paymentOptions.querySelectorAll('.pay-option').forEach(option => {
            const choose = () => choosePaymentMethod(option.dataset.value, option.dataset.label);
            option.addEventListener('click', choose);
            option.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(); }
            });
        });
        document.addEventListener('click', event => { if (!byId('paySelect').contains(event.target)) closePaymentMenu(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closePaymentMenu(); });
        providerRadios.forEach(radio => radio.addEventListener('change', () => {
            showError(null, 'provError_' + radio.dataset.method, '');
            updatePayment();
        }));
        referenceInput.addEventListener('input', () => validateField(referenceInput));
        proofInput.addEventListener('change', () => validateProof(true));
        document.querySelectorAll('.copy-btn').forEach(button => button.addEventListener('click', () => copyToClipboard(button)));

        // Wizard
        nextButton.addEventListener('click', () => { if (isStepValid(currentStep)) goToStep(currentStep + 1); });
        backButton.addEventListener('click', () => goToStep(currentStep - 1));
        stepperItems.forEach(item => item.addEventListener('click', () => handleStepperClick(item)));
        bookingForm.addEventListener('keydown', handleEnterKey);
        bookingForm.addEventListener('submit', handleSubmit);
        byId('terms').addEventListener('change', event =>
            showError(null, 'termsError', event.target.checked ? '' : 'Please confirm the booking guidelines.'));

        initSummaryPanel();
        updateSummary();
    }

    init();
})();
</script>

</body>
</html>
