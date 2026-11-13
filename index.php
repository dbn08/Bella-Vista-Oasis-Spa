<?php
require_once 'config.php';
$services = $pdo->query('SELECT * FROM services WHERE active = 1 ORDER BY id')->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bella Vista Oasis Spa | Find Your Calm</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="index.php"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></a>
  <button class="menu-toggle" aria-label="Toggle navigation" onclick="document.querySelector('.nav-links').classList.toggle('open')">☰</button>
  <nav class="nav-links">
    <a href="#home">Home</a><a href="#about">Our Story</a><a href="#services">Treatments</a>
    <a href="#policies">Policies</a><a class="nav-book" href="book.php">Book a Visit</a>
  </nav>
</header>
<main>
<section class="hero" id="home">
  <div class="hero-overlay"></div>
  <div class="hero-content">
    <p class="eyebrow">A sanctuary for the senses</p>
    <h1>Pause. Breathe.<br><em>Come back to you.</em></h1>
    <p class="hero-copy">Discover thoughtful treatments and peaceful moments at Bella Vista Oasis Spa — your space to unwind, restore, and feel renewed.</p>
    <a class="button button-light" href="book.php">Reserve Your Escape <span>→</span></a>
    <div class="hero-note">HOLISTIC WELLNESS · DEEP RELAXATION · PERSONAL CARE</div>
  </div>
  <div class="hero-stamp"><span>YOUR</span><strong>OASIS</strong><span>AWAITS</span></div>
</section>
<section class="intro section" id="about">
  <div class="intro-art"><div class="art-circle"><span>BV</span><small>EST. 2021</small></div><div class="art-caption">A gentler pace.<br>A clearer mind.</div></div>
  <div class="intro-text">
    <p class="eyebrow">Welcome to Bella Vista</p><h2>Your moment of<br><em>wellness starts here.</em></h2>
    <p>Bella Vista Oasis Spa is a peaceful sanctuary dedicated to holistic wellness, physical rejuvenation, and deep relaxation. Surrounded by nature and soothing aesthetics, we offer premium massage therapies, therapeutic treatments, and private lounge experiences designed to melt away stress and restore body and mind.</p>
    <p>Established in 2021 as a vital extension of the Bella Vista estate, the spa grew from a commitment to provide guests with an unmatched healing touch. Today, it is a trusted wellness destination for local residents and visitors seeking professional relaxation therapies.</p>
    <a class="text-link" href="#services">Explore our treatments <span>↗</span></a>
  </div>
</section>
<section class="promise">
  <div><span>01</span><h3>Personalized Care</h3><p>Thoughtful treatments tailored to your comfort and preferences.</p></div>
  <div><span>02</span><h3>Peaceful Setting</h3><p>A calm environment designed to help you slow down and reset.</p></div>
  <div><span>03</span><h3>Mindful Wellness</h3><p>Time to reconnect with yourself through restorative experiences.</p></div>
</section>
<section class="services section" id="services">
  <div class="section-heading"><div><p class="eyebrow">Our treatment menu</p><h2>Find your kind<br><em>of calm.</em></h2></div><p>From soothing massages to restorative rituals, choose the experience that feels right for you.</p></div>
  <div class="service-grid">
    <?php foreach ($services as $service): ?>
    <article class="service-card">
      <div class="service-icon"><?= e(mb_substr($service['name'], 0, 1)) ?></div>
      <p class="service-duration"><?= (int)$service['duration_minutes'] ?> MINUTES</p>
      <h3><?= e($service['name']) ?></h3>
      <p><?= e($service['description']) ?></p>
      <div class="service-bottom"><strong>₱<?= number_format((float)$service['price'], 2) ?></strong><a href="book.php?service=<?= (int)$service['id'] ?>">Book treatment →</a></div>
    </article>
    <?php endforeach; ?>
  </div>
</section>
<section class="quote-band"><p class="eyebrow">A note to yourself</p><blockquote>“Rest is not a reward. It is part of the journey.”</blockquote><a class="button button-outline" href="book.php">Make Time for You</a></section>
<section class="policies section" id="policies">
  <p class="eyebrow">Before your visit</p><h2>Our booking <em>guidelines.</em></h2>
  <div class="policy-grid">
    <div><h3>Reservations</h3><p>Please provide accurate contact information. Online requests begin with Pending status until confirmed by our spa team.</p></div>
    <div><h3>Arrival</h3><p>Please arrive 10 minutes before your appointment. Late arrival may shorten your treatment to avoid delaying the next guest.</p></div>
    <div><h3>Changes & cancellations</h3><p>Please contact the spa as early as possible if you need to change or cancel. The team will confirm available options.</p></div>
    <div><h3>Comfort & care</h3><p>Tell your therapist about pressure preferences, allergies, or other comfort concerns before treatment begins.</p></div>
  </div>
  <p class="small-note">Demo policy text: replace these guidelines with the final policies approved by your instructor or client.</p>
</section>
</main>
<footer class="footer"><div class="brand footer-brand"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></div><p>A quiet space to restore body and mind.</p><div><a href="book.php">Book an appointment</a><a href="admin_login.php">Admin</a></div><small>© <?= date('Y') ?> Bella Vista Oasis Spa · Demo academic project</small></footer>
<script src="assets/script.js"></script>
</body></html>
