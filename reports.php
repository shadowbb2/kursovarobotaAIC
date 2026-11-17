<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$pageTitle = 'Звіти';
$activePage = 'reports';
$moduleTableTitle = 'Звітність';
$moduleColumns = ['Назва звіту', 'Період', 'Дата формування', 'Дії'];
$moduleEmptyText = '';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/module-page.php';
require __DIR__ . '/includes/footer.php';
