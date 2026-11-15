<?php
require_once 'config.php';

const HOLD_HOURS = 24;   // keep in sync with book.php
$spaInfo = [
    'address' => 'Bella Vista Oasis Spa front desk (add your street address here)',
    'hours'   => 'Tuesday to Sunday, 9:00 AM to 5:30 PM',
    'phone'   => '09XX XXX XXXX',
];
$methodLabels   = ['cash' => 'Cash', 'ewallet' => 'E-wallet', 'bank' => 'Online banking'];
$providerLabels = ['gcash' => 'GCash', 'maya' => 'Maya', 'bdo' => 'BDO', 'bpi' => 'BPI', 'unionbank' => 'UnionBank'];

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money')) {
    function money($v): string { return '₱' . number_format((float)$v, 2); }
}

// Release unpaid cash reservations whose hold period has ended.
try {
    $pdo->exec("UPDATE appointments SET status = 'Cancelled' WHERE status = 'Pending' AND payment_method = 'cash' AND payment_status = 'unpaid' AND created_at < (NOW() - INTERVAL " . (int)HOLD_HOURS . " HOUR)");
} catch (Throwable $e) {
}

$code  = strtoupper(trim($_POST['code'] ?? $_GET['code'] ?? ''));
$email = trim($_POST['email'] ?? '');
$isNew = !empty($_GET['new']);
$b = null;
$error = '';

if ($code !== '') {
    $st = $pdo->prepare('SELECT * FROM appointments WHERE booking_code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    $fromSession = $row && !empty($_SESSION['last_booking_code']) && hash_equals((string)$_SESSION['last_booking_code'], (string)$row['booking_code']);
    $emailMatches = $row && $email !== '' && strcasecmp($email, $row['email']) === 0;
    if ($row && ($fromSession || $emailMatches)) {
        $b = $row;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $error = 'We could not find a booking with that code and email. Please check and try again.';
    }
}

$items = [];
$timeline = [];
$notice = ['info', '', ''];
if ($b) {
    $st = $pdo->prepare('SELECT item_type, item_name, unit_price, line_total, duration_minutes FROM appointment_items WHERE appointment_id = ? ORDER BY id');
    $st->execute([$b['id']]);
    $items = $st->fetchAll();
    if (!$items) {
        $sv = $pdo->prepare('SELECT name, price, duration_minutes FROM services WHERE id = ?');
        $sv->execute([$b['service_id']]);
        if ($r = $sv->fetch()) {
            $items[] = ['item_type' => 'service', 'item_name' => $r['name'], 'unit_price' => $r['price'], 'line_total' => $b['total_price'], 'duration_minutes' => $r['duration_minutes']];
        }
    }

    $status    = $b['status'];
    $method    = $b['payment_method'] ?? 'cash';
    $payState  = $b['payment_status'] ?? 'unpaid';
    $cancelled = $status === 'Cancelled';
    $confirmed = in_array($status, ['Confirmed', 'Completed'], true);
    $completed = $status === 'Completed';
    $payDone   = in_array($payState, ['verified', 'refunded'], true) || $confirmed;
    $due       = (float)$b['amount_due'];
    $balance   = max((float)$b['total_price'] - $due, 0);
    $deadline  = date('F j, Y \a\t g:i A', strtotime($b['created_at'] . ' +' . HOLD_HOURS . ' hours'));
    $when      = date('l, F j, Y', strtotime($b['appointment_date'])) . ' at ' . date('g:i A', strtotime($b['appointment_time']));

    $timeline = [
        ['Request received', 'We have your booking request.', true, false],
        [$method === 'cash' ? 'Down payment at the spa' : 'Payment verification',
         $method === 'cash' ? 'Pay your down payment at the front desk.' : 'Our team checks your payment and reference number.',
         $payDone, !$payDone && !$cancelled],
        ['Booking confirmed', 'Your time slot is reserved for you.', $confirmed, $payDone && !$confirmed && !$cancelled],
        ['Appointment completed', 'We hope you enjoyed your visit.', $completed, $confirmed && !$completed],
    ];

    if ($cancelled) {
        $notice = ['bad', 'This booking was cancelled', $method === 'cash' && $payState === 'unpaid'
            ? 'The down payment was not received within ' . HOLD_HOURS . ' hours, or the booking was cancelled by the spa. You are welcome to make a new booking.'
            : 'If you think this is a mistake, please contact the spa and mention your booking code.'];
    } elseif ($completed) {
        $notice = ['good', 'Thank you for visiting Bella Vista', 'We hope to see you again soon.'];
    } elseif ($confirmed) {
        $notice = ['good', 'Your booking is confirmed', 'See you on ' . $when . '. Please arrive about 10 minutes early. Your slot is held for 15 minutes after the appointment time. The remaining balance of ' . money($balance) . ' is paid at the spa.'];
    } elseif ($method === 'cash') {
        $notice = ['warn', 'Action needed: pay your down payment', 'Please pay ' . money($due) . ' in cash at the spa front desk by ' . $deadline . ' and give your booking code. Your booking is confirmed once the front desk records your payment. Unpaid reservations are released automatically.'];
    } else {
        $notice = ['warn', 'We are verifying your payment', 'Our team is checking your ' . ($providerLabels[$b['payment_provider']] ?? 'payment') . ' payment of ' . money($due) . ' (reference ' . h($b['reference_no']) . '). This page updates as soon as your booking is confirmed.'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Track my booking | Bella Vista Oasis Spa</title>
<link rel="stylesheet" href="assets/style.css">
<style>
body { background:#f7f1e8; }
.trk { max-width:900px; margin:0 auto; padding:48px 24px 80px; color:#3f2d20; }
.trk .eyebrow { font-size:11px; letter-spacing:.25em; text-transform:uppercase; color:#b58a52; }
.trk h1 { margin:6px 0 8px; font-family:"Cormorant Garamond", Georgia, serif; font-weight:600; font-size:42px; line-height:1.1; }
.trk p.lead { margin:0 0 28px; color:#7a6a5c; }
.card { background:#fffdf9; border:1px solid #e8dccb; border-radius:24px; padding:28px 30px; margin-bottom:20px; }
.card h2 { margin:0 0 16px; font-family:"Cormorant Garamond", Georgia, serif; font-size:24px; font-weight:600; }
.lookup { display:grid; grid-template-columns:1fr 1fr auto; gap:14px; align-items:end; }
.lookup label { font-size:13px; font-weight:500; display:block; }
.lookup input { width:100%; margin-top:6px; font:inherit; padding:13px 18px; border:1px solid #d9cfc2; border-radius:999px; background:#fff; color:#3f2d20; }
.lookup input:focus { outline:2px solid rgba(100,133,104,.35); border-color:#648568; }
.btn { font:inherit; font-size:13px; letter-spacing:.12em; text-transform:uppercase; padding:14px 28px; border:0; border-radius:999px; background:#5a4030; color:#fff; cursor:pointer; text-decoration:none; display:inline-block; }
.btn:hover { background:#46301f; }
.err { background:#fbeceb; color:#b83232; border:1px solid #f0c9c6; border-radius:14px; padding:12px 18px; margin-bottom:18px; font-size:14px; }
.notice { border-radius:20px; padding:22px 26px; margin-bottom:20px; border:1px solid; }
.notice h3 { margin:0 0 6px; font-size:18px; }
.notice p { margin:0; font-size:14px; line-height:1.6; }
.notice.good { background:#eaf1e8; border-color:#cfe0cc; color:#34583a; }
.notice.warn { background:#fbf1dc; border-color:#f0dcae; color:#7d5a17; }
.notice.bad  { background:#fbeceb; border-color:#f0c9c6; color:#8f2a2a; }
.codebox { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; }
.codebox small { display:block; color:#8b7a6a; font-size:12px; letter-spacing:.14em; text-transform:uppercase; }
.codebox strong { font-size:26px; letter-spacing:.06em; font-family:"Cormorant Garamond", Georgia, serif; }
.badge { display:inline-block; padding:5px 16px; border-radius:999px; font-size:13px; font-weight:500; }
.s-pending { background:#fbf1dc; color:#b9842b; } .s-confirmed { background:#eaf1e8; color:#648568; }
.s-completed { background:#e8eff5; color:#4a6a86; } .s-cancelled { background:#fbeceb; color:#b83232; }
.tl { list-style:none; margin:0; padding:0; display:grid; gap:0; }
.tl li { position:relative; padding:0 0 24px 48px; }
.tl li:last-child { padding-bottom:0; }
.tl li::before { content:""; position:absolute; left:14px; top:30px; bottom:0; width:2px; background:#e8dccb; }
.tl li:last-child::before { display:none; }
.tl .dot { position:absolute; left:0; top:0; width:30px; height:30px; border-radius:50%; border:2px solid #d9cfc2; background:#fffdf9; display:flex; align-items:center; justify-content:center; font-size:13px; color:#a09283; }
.tl li.done .dot { background:#648568; border-color:#648568; color:#fff; }
.tl li.done::before { background:#648568; }
.tl li.now .dot { border-color:#b9842b; color:#b9842b; box-shadow:0 0 0 4px rgba(185,132,43,.15); }
.tl strong { display:block; font-size:15px; } .tl span { color:#8b7a6a; font-size:13px; }
.rows { margin:0; } .rows div { display:flex; justify-content:space-between; gap:18px; padding:11px 0; border-top:1px solid #f0e7da; font-size:14px; }
.rows div:first-child { border-top:0; } .rows dt { color:#8b7a6a; } .rows dd { margin:0; font-weight:600; text-align:right; }
.rows .sub dt, .rows .sub dd { font-weight:400; font-size:13px; }
.rows .total dd { font-size:18px; color:#5a4030; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.where div { display:flex; justify-content:space-between; gap:16px; padding:9px 0; font-size:13.5px; border-top:1px solid #f0e7da; }
.where div:first-child { border-top:0; } .where span { color:#8b7a6a; } .where strong { text-align:right; }
@media (max-width:760px) { .lookup, .grid2 { grid-template-columns:1fr; } .trk h1 { font-size:34px; } .card { padding:22px; } }
</style>
</head>
<body>
<header class="site-header">
    <a class="brand" href="index.php">
        <span class="brand-mark">✦</span>
        <span>BELLA VISTA <small>OASIS SPA</small></span>
    </a>
    <nav class="nav-links">
        <a href="book.php">Book an appointment</a>
        <a href="index.php">← Back to website</a>
    </nav>
</header>

<main class="trk">
<?php if (!$b): ?>
    <div class="eyebrow">Booking status</div>
    <h1>Track my booking</h1>
    <p class="lead">Enter your booking code and the email you used to see whether your booking is confirmed.</p>
    <form class="card" method="post">
        <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>
        <div class="lookup">
            <label>Booking code<input type="text" name="code" value="<?= h($code) ?>" placeholder="e.g. BV261114ABC123" required></label>
            <label>Email address<input type="email" name="email" value="<?= h($email) ?>" placeholder="you@email.com" required></label>
            <button class="btn" type="submit">Check status</button>
        </div>
    </form>
<?php else: ?>
    <div class="eyebrow"><?= $isNew ? 'Request received' : 'Booking status' ?></div>
    <h1><?= $isNew ? 'Thank you, ' . h(explode(' ', $b['customer_name'])[0]) . '.' : 'Your booking' ?></h1>
    <p class="lead"><?= $isNew ? 'Your request is in. Keep your booking code and follow the steps below.' : 'Here is where your booking stands.' ?></p>

    <div class="notice <?= $notice[0] ?>">
        <h3><?= h($notice[1]) ?></h3>
        <p><?= $notice[2] ?></p>
    </div>

    <div class="card codebox">
        <div><small>Booking code</small><strong><?= h($b['booking_code']) ?></strong></div>
        <span class="badge s-<?= strtolower($b['status']) ?>"><?= h($b['status']) ?></span>
    </div>

    <div class="card">
        <h2>Progress</h2>
        <ol class="tl">
            <?php foreach ($timeline as $i => $t): ?>
                <li class="<?= $t[2] ? 'done' : ($t[3] ? 'now' : '') ?>">
                    <span class="dot"><?= $t[2] ? '✓' : ($i + 1) ?></span>
                    <strong><?= h($t[0]) ?></strong><span><?= h($t[1]) ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>

    <div class="grid2">
        <div class="card" style="margin:0">
            <h2>Appointment</h2>
            <dl class="rows">
                <div><dt>Date</dt><dd><?= h(date('D, M j, Y', strtotime($b['appointment_date']))) ?></dd></div>
                <div><dt>Time</dt><dd><?= h(date('g:i A', strtotime($b['appointment_time']))) ?></dd></div>
                <div><dt>Guests</dt><dd><?= (int)$b['guests'] ?></dd></div>
                <div><dt>Name</dt><dd><?= h($b['customer_name']) ?></dd></div>
                <div><dt>Contact</dt><dd><?= h($b['phone']) ?></dd></div>
            </dl>
        </div>
        <div class="card" style="margin:0">
            <h2>Payment</h2>
            <dl class="rows">
                <?php foreach ($items as $it): ?>
                    <div><dt><?= h($it['item_name']) ?></dt><dd><?= money($it['line_total']) ?></dd></div>
                <?php endforeach; ?>
                <div class="total"><dt>Total</dt><dd><?= money($b['total_price']) ?></dd></div>
                <div class="sub"><dt>Down payment</dt><dd><?= money($due) ?></dd></div>
                <div class="sub"><dt>Balance at the spa</dt><dd><?= money($balance) ?></dd></div>
                <div class="sub"><dt>Method</dt><dd><?= h($methodLabels[$method] ?? $method) ?><?= $b['payment_provider'] ? ' · ' . h($providerLabels[$b['payment_provider']] ?? $b['payment_provider']) : '' ?></dd></div>
                <div class="sub"><dt>Payment status</dt><dd><?= h(ucfirst($payState)) ?></dd></div>
            </dl>
        </div>
    </div>

    <?php if ($method === 'cash' && !$cancelled && !$payDone): ?>
        <div class="card" style="margin-top:20px">
            <h2>Where to pay your down payment</h2>
            <div class="where">
                <div><span>Where</span><strong><?= h($spaInfo['address']) ?></strong></div>
                <div><span>Front desk hours</span><strong><?= h($spaInfo['hours']) ?></strong></div>
                <div><span>Pay by</span><strong><?= h($deadline) ?></strong></div>
                <div><span>Bring</span><strong>Your booking code <?= h($b['booking_code']) ?></strong></div>
                <div><span>Questions</span><strong><?= h($spaInfo['phone']) ?></strong></div>
            </div>
        </div>
    <?php endif; ?>

    <p style="margin-top:26px"><a class="btn" href="track.php">Track another booking</a></p>
<?php endif; ?>
</main>
</body>
</html>
