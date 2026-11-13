<?php123
require_once 'config.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    $allowed = ['Pending', 'Confirmed', 'Cancelled', 'Completed'];
    $status = $_POST['status'] ?? '';
    if ($action === 'status' && $id && in_array($status, $allowed, true)) {
        $stmt = $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
    }
    if ($action === 'add_service') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $duration = filter_input(INPUT_POST, 'duration_minutes', FILTER_VALIDATE_INT);
        $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
        if ($name !== '' && $description !== '' && $duration && $duration > 0 && $price !== false && $price >= 0) {
            $stmt = $pdo->prepare('INSERT INTO services (name, description, duration_minutes, price, active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$name, $description, $duration, $price]);
        }
    }
    if ($action === 'toggle_service') {
        $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
        if ($serviceId) {
            $stmt = $pdo->prepare('UPDATE services SET active = IF(active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$serviceId]);
        }
    }
    header('Location: admin_dashboard.php');
    exit;
}

$stats = [
 'total' => (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn(),
 'pending' => (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Pending'")->fetchColumn(),
 'confirmed' => (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Confirmed'")->fetchColumn(),
 'revenue' => (float)$pdo->query("SELECT COALESCE(SUM(total_price),0) FROM appointments WHERE status IN ('Confirmed','Completed')")->fetchColumn()
];
$appointments = $pdo->query("SELECT a.*, s.name AS service_name FROM appointments a JOIN services s ON s.id = a.service_id ORDER BY a.appointment_date DESC, a.appointment_time DESC, a.id DESC")->fetchAll();
$services = $pdo->query('SELECT * FROM services ORDER BY active DESC, name')->fetchAll();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Dashboard | Bella Vista Oasis Spa</title><link rel="stylesheet" href="assets/style.css"></head><body class="admin-body">
<header class="admin-header"><a class="brand" href="index.php"><span class="brand-mark">✦</span><span>BELLA VISTA <small>OASIS SPA</small></span></a><div class="admin-user">Signed in as <strong><?= e($_SESSION['admin_username'] ?? 'Admin') ?></strong> <a href="logout.php">Log out</a></div></header>
<main class="admin-main"><div class="admin-title"><div><p class="eyebrow">Management portal</p><h1>Good day, <?= e($_SESSION['admin_username'] ?? 'Admin') ?>.</h1><p>Here's an overview of your spa bookings and services.</p></div><a class="button button-dark" href="book.php">View booking page ↗</a></div>
<section class="stats-grid"><div class="stat-card"><span>Total bookings</span><strong><?= $stats['total'] ?></strong></div><div class="stat-card"><span>Pending requests</span><strong><?= $stats['pending'] ?></strong></div><div class="stat-card"><span>Confirmed</span><strong><?= $stats['confirmed'] ?></strong></div><div class="stat-card"><span>Confirmed/completed value</span><strong>₱<?= number_format($stats['revenue'], 2) ?></strong></div></section>
<section class="admin-panel"><div class="panel-heading"><div><p class="eyebrow">Reservation records</p><h2>Appointments</h2></div><span><?= count($appointments) ?> records</span></div>
<div class="table-wrap"><table><thead><tr><th>Reference / Guest</th><th>Treatment</th><th>Date & time</th><th>Guests / Total</th><th>Status</th><th>Update</th></tr></thead><tbody>
<?php if (!$appointments): ?><tr><td colspan="6" class="empty-state">No bookings yet. New requests will appear here.</td></tr><?php endif; ?>
<?php foreach ($appointments as $a): ?><tr><td><strong><?= e($a['booking_code']) ?></strong><small><?= e($a['customer_name']) ?><br><?= e($a['email']) ?><br><?= e($a['phone']) ?></small></td><td><?= e($a['service_name']) ?></td><td><?= e(date('M j, Y', strtotime($a['appointment_date']))) ?><small><?= e(date('g:i A', strtotime($a['appointment_time']))) ?></small></td><td><?= (int)$a['guests'] ?><small>₱<?= number_format((float)$a['total_price'], 2) ?></small></td><td><span class="status-pill status-<?= strtolower($a['status']) ?>"><?= e($a['status']) ?></span></td><td><form method="post" class="status-form"><input type="hidden" name="action" value="status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>"><select name="status" aria-label="Update booking status"><?php foreach (['Pending','Confirmed','Cancelled','Completed'] as $s): ?><option <?= $a['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select><button class="small-button">Save</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="admin-panel"><div class="panel-heading"><div><p class="eyebrow">Treatment menu</p><h2>Manage services</h2></div></div>
<div class="service-admin-grid"><form method="post" class="add-service-form"><h3>Add a service</h3><input type="hidden" name="action" value="add_service"><label>Service name<input name="name" maxlength="120" required></label><label>Description<textarea name="description" rows="3" required></textarea></label><div class="two-inputs"><label>Duration (minutes)<input type="number" name="duration_minutes" min="1" required></label><label>Price (₱)<input type="number" name="price" min="0" step="0.01" required></label></div><button class="button button-dark full">Add Service</button></form>
<div class="service-admin-list"><h3>Existing services</h3><?php foreach ($services as $s): ?><div class="service-admin-row"><div><strong><?= e($s['name']) ?></strong><small><?= (int)$s['duration_minutes'] ?> min · ₱<?= number_format((float)$s['price'],2) ?> · <?= $s['active'] ? 'Active' : 'Inactive' ?></small></div><form method="post"><input type="hidden" name="action" value="toggle_service"><input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>"><button class="small-button"><?= $s['active'] ? 'Deactivate' : 'Activate' ?></button></form></div><?php endforeach; ?></div></div>
</section><p class="small-note">Demo rules: Tuesdays–Sundays, selected fixed time slots, maximum four guests per slot. Review these assumptions before final submission.</p></main><script src="assets/script.js"></script></body></html>
