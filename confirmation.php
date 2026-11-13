<?php
require_once 'config.php';
$code = $_GET['code'] ?? '';
$stmt = $pdo->prepare('SELECT a.*, s.name AS service_name, s.duration_minutes FROM appointments a JOIN services s ON s.id = a.service_id WHERE a.booking_code = ?');
$stmt->execute([$code]);
$booking = $stmt->fetch();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Booking Confirmation | Bella Vista Oasis Spa</title><link rel="stylesheet" href="assets/style.css"></head><body class="confirmation-body">
<header class="site-header"><a class="brand" href="index.php"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></a></header>
<main class="confirmation-card">
<?php if ($booking): ?>
<div class="success-icon">✓</div><p class="eyebrow">Your request has been received</p><h1>Thank you, <?= e($booking['customer_name']) ?>.</h1><p class="confirmation-intro">Your appointment request is saved. Please keep your booking reference and wait for the spa team to confirm your reservation.</p>
<div class="booking-code"><small>BOOKING REFERENCE</small><strong><?= e($booking['booking_code']) ?></strong></div>
<div class="receipt-row"><span>Treatment</span><strong><?= e($booking['service_name']) ?></strong></div>
<div class="receipt-row"><span>Date</span><strong><?= e(date('F j, Y', strtotime($booking['appointment_date']))) ?></strong></div>
<div class="receipt-row"><span>Time</span><strong><?= e(date('g:i A', strtotime($booking['appointment_time']))) ?></strong></div>
<div class="receipt-row"><span>Guests</span><strong><?= (int)$booking['guests'] ?></strong></div>
<div class="receipt-row"><span>Status</span><strong class="status-pill"><?= e($booking['status']) ?></strong></div>
<div class="receipt-total"><span>Estimated total</span><strong>₱<?= number_format((float)$booking['total_price'], 2) ?></strong></div>
<a class="button button-dark full" href="index.php">Return to Home</a>
<?php else: ?><p class="eyebrow">No booking found</p><h1>We couldn't find that reference.</h1><a class="button button-dark" href="book.php">Make a booking</a><?php endif; ?>
</main><footer class="footer compact-footer"><small>© <?= date('Y') ?> Bella Vista Oasis Spa</small></footer></body></html>
