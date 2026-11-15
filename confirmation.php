<?php
require_once 'config.php';
$code = $_GET['code'] ?? ($_SESSION['last_booking_code'] ?? '');
header('Location: track.php?new=1&code=' . urlencode((string)$code));
exit;
