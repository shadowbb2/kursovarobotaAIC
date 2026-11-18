<?php
require_once __DIR__ . '/includes/auth.php';

// Send visitors to the dashboard or login page based on their session
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
