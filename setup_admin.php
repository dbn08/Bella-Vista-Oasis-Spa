<?php
require_once 'config.php';

// Run this file once in your browser to create the demo administrator.
// Demo login: username admin | password BellaSpa123!
// Delete this file after setup for better security.
$username = 'admin';
$password = 'BellaSpa123!';
$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ?');
$stmt->execute([$username]);

if ($stmt->fetch()) {
    echo 'The demo admin already exists. Delete setup_admin.php now.';
} else {
    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
    $stmt->execute([$username, $hash]);
    echo 'Admin account created. Username: admin | Password: BellaSpa123!<br>Delete setup_admin.php now.';
}
