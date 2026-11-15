<?php
require_once 'config.php';
unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['csrf'], $_SESSION['flash']);
session_regenerate_id(true);
header('Location: admin_login.php');
exit;
