<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
