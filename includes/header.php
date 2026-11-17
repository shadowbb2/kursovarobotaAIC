<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
$userName = $user['full_name'] ?? 'Користувач';
$roleName = ($user['role'] ?? '') === 'admin' ? 'Адміністратор' : 'Менеджер';
$initials = '';
foreach (preg_split('/\s+/u', trim($userName)) as $part) {
    if ($part !== '') {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
    }
}
$initials = mb_substr($initials, 0, 2, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="AIC — система взаємовідносин із клієнтами підприємства з виробництва сільськогосподарської техніки.">
    <title><?= e($pageTitle ?? 'AIC') ?> — AIC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css?v=24">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-button menu-toggle" id="menuToggle" type="button" aria-label="Відкрити меню">☰</button>
                <div>
                    <div class="topbar-title"><?= e($pageTitle ?? 'AIC') ?></div>
                </div>
            </div>
            <div class="topbar-user"><a href="profile.php" class="user-details user-profile-link"><strong><?= e($userName) ?></strong><span><?= e($roleName) ?></span></a>
                <div class="avatar" aria-label="Аватар користувача"><?= e($initials ?: 'К') ?></div>
                <?php if (($activePage ?? '') === 'dashboard'): ?>
                    <span class="date-chip topbar-date-chip" aria-label="Поточна дата"><?= date('d.m.Y') ?></span>
                <?php endif; ?>
                <button class="work-time-button" id="workTimeButton" type="button" data-csrf-token="<?= e(csrfToken()) ?>" aria-label="Завантаження робочого часу" disabled>
                    <span id="workTimeStartLabel">Почати роботу</span>
                    <span id="workTimeClock" hidden></span>
                    <span id="workTimeStopLabel" aria-hidden="true">Завершити роботу</span>
                </button>
                <span class="work-time-status" id="workTimeStatus" role="status" aria-live="polite"></span>
                <form action="logout.php" method="post" class="logout-form">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <button class="logout-button" type="submit" title="Вийти із системи" aria-label="Вийти із системи">↪</button>
                </form>
            </div>
        </header>
