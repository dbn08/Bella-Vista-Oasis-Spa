<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: book.php');
    exit;
}

$name = trim($_POST['customer_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
$date = $_POST['appointment_date'] ?? '';
$time = $_POST['appointment_time'] ?? '';
$guests = filter_input(INPUT_POST, 'guests', FILTER_VALIDATE_INT);
$notes = trim($_POST['notes'] ?? '');

$fail = function(string $message): void {
    $_SESSION['booking_error'] = $message;
    header('Location: book.php');
    exit;
};

if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    mb_strlen($email) > 150 || $phone === '' || mb_strlen($phone) > 30 ||
    !$serviceId || !$guests || $guests < 1 || $guests > 4 || mb_strlen($notes) > 1000) {
    $fail('Please check all required fields and enter valid contact details.');
}

$serviceStmt = $pdo->prepare('SELECT * FROM services WHERE id = ? AND active = 1');
$serviceStmt->execute([$serviceId]);
$service = $serviceStmt->fetch();
if (!$service) $fail('That service is not available. Please choose another service.');

$validTimes = ['09:00','10:30','12:00','13:30','15:00','16:30'];
if (!in_array($time, $validTimes, true)) $fail('Please select one of the available appointment times.');

$dateObj = DateTime::createFromFormat('Y-m-d', $date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $date || $date < date('Y-m-d', strtotime('+1 day'))) {
    $fail('Please choose a valid date at least one day in advance.');
}
// Demo schedule: Tuesday to Sunday. Monday is closed.
if ((int)$dateObj->format('N') === 1) $fail('The spa is closed on Mondays. Please choose Tuesday to Sunday.');

$basePrice = (float)$service['price'];
$total = $basePrice + (($guests - 1) * $basePrice * 0.80);

// Demo capacity: up to 4 guests per exact time slot across all bookings.
// Cancelled appointments do not count toward capacity.
$capacityStmt = $pdo->prepare("SELECT COALESCE(SUM(guests), 0) AS booked FROM appointments WHERE appointment_date = ? AND appointment_time = ? AND status <> 'Cancelled'");
$capacityStmt->execute([$date, $time]);
$booked = (int)$capacityStmt->fetch()['booked'];
if ($booked + $guests > 4) $fail('That time slot has reached its demo capacity. Please choose another time.');

$code = 'BV' . date('ymd') . strtoupper(bin2hex(random_bytes(3)));
try {
    $stmt = $pdo->prepare('INSERT INTO appointments (booking_code, customer_name, email, phone, service_id, appointment_date, appointment_time, guests, notes, total_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$code, $name, $email, $phone, $serviceId, $date, $time . ':00', $guests, $notes ?: null, $total]);
    $_SESSION['last_booking_code'] = $code;
    header('Location: confirmation.php?code=' . urlencode($code));
    exit;
} catch (PDOException $e) {
    $fail('We could not save your booking. Please try again or contact the spa.');
}
