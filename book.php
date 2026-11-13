123<?php
require_once 'config.php';
$services = $pdo->query('SELECT id, name, duration_minutes, price FROM services WHERE active = 1 ORDER BY name')->fetchAll();
$selectedService = isset($_GET['service']) ? (int)$_GET['service'] : 0;
$error = $_SESSION['booking_error'] ?? '';
unset($_SESSION['booking_error']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Book an Appointment | Bella Vista Oasis Spa</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="booking-page">
<header class="site-header"><a class="brand" href="index.php"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></a><nav class="nav-links"><a href="index.php">← Back to website</a></nav></header>
<main class="booking-layout"><aside class="booking-aside"><p class="eyebrow">Your time, your ritual</p><h1>Make space<br>for <em>yourself.</em></h1><p>Choose a treatment and a time that works for you. Our team will review your request and confirm your appointment.</p><div class="aside-detail"><span>✦</span><div><strong>Mindful moments</strong><small>A calmer experience begins with a little time for you.</small></div></div><div class="aside-detail"><span>◷</span><div><strong>Plan ahead</strong><small>Choose a date at least one day in advance.</small></div></div></aside>
<section class="booking-form-wrap"><p class="eyebrow">Appointment request</p><h2>Reserve your visit</h2>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form action="save_booking.php" method="post" id="bookingForm" class="form-grid">
  <label class="full">Full name<input type="text" name="customer_name" required maxlength="120" autocomplete="name"></label>
  <label>Email address<input type="email" name="email" required maxlength="150" autocomplete="email"></label>
  <label>Phone number<input type="tel" name="phone" required maxlength="30" autocomplete="tel"></label>
  <label class="full">Select a treatment<select name="service_id" id="service_id" required><option value="">Choose a service</option><?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>" data-price="<?= e((string)$s['price']) ?>" data-duration="<?= (int)$s['duration_minutes'] ?>" <?= $selectedService === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> — ₱<?= number_format((float)$s['price'], 2) ?> (<?= (int)$s['duration_minutes'] ?> min)</option><?php endforeach; ?></select></label>
  <label>Appointment date<input type="date" name="appointment_date" id="appointment_date" required></label>
  <label>Preferred time<select name="appointment_time" required><option value="">Choose time</option><?php foreach (['09:00','10:30','12:00','13:30','15:00','16:30'] as $time): ?><option value="<?= $time ?>"><?= date('g:i A', strtotime($time)) ?></option><?php endforeach; ?></select><small>Sample slots: 9:00 AM–5:30 PM, Tuesday–Sunday.</small></label>
  <label>Number of guests<select name="guests" id="guests"><option value="1">1 guest</option><option value="2">2 guests</option><option value="3">3 guests</option><option value="4">4 guests</option></select></label>
  <label class="full">Notes or preferences (optional)<textarea name="notes" rows="3" maxlength="1000" placeholder="Share any preferences or accessibility needs."></textarea></label>
  <div class="price-summary full"><span>Estimated total</span><strong id="totalPrice">₱0.00</strong><small>Sample pricing: first guest pays the service price; each additional guest is charged 80% of the service price.</small></div>
  <label class="checkbox full"><input type="checkbox" required> I have read the booking guidelines and understand this request is pending until confirmed.</label>
  <button class="button button-dark full" type="submit">Submit Appointment Request <span>→</span></button>
</form></section></main><footer class="footer compact-footer"><small>© <?= date('Y') ?> Bella Vista Oasis Spa</small></footer><script src="assets/script.js"></script></body></html>
