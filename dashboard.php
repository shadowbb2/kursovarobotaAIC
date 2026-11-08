<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Головна';
$activePage = 'dashboard';

function tableCount(PDO $pdo, string $table): int
{
    $allowedTables = ['clients', 'orders', 'machinery', 'inquiries'];
    if (!in_array($table, $allowedTables, true)) {
        return 0;
    }

    return (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

$stats = [
    ['label' => 'Клієнти', 'value' => tableCount($pdo, 'clients'), 'icon' => '♙', 'tone' => 'green', 'href' => 'clients.php'],
    ['label' => 'Замовлення', 'value' => tableCount($pdo, 'orders'), 'icon' => '▤', 'tone' => 'blue', 'href' => 'orders.php'],
    ['label' => 'Одиниці техніки', 'value' => tableCount($pdo, 'machinery'), 'icon' => '⚙', 'tone' => 'orange', 'href' => 'machinery.php'],
    ['label' => 'Звернення', 'value' => tableCount($pdo, 'inquiries'), 'icon' => '✉', 'tone' => 'purple', 'href' => 'inquiries.php'],
];

require __DIR__ . '/includes/header.php';
?>
<main class="page-content">
    <section class="welcome-banner">
        <div>
            <h1>Вітаємо, <?= e(currentUser()['full_name'] ?? 'користувачу') ?>!</h1>
        </div>
        <div class="welcome-art" aria-hidden="true">🌱</div>
    </section>

    <div class="section-title-row">
        <div><h2>Огляд системи</h2></div>
    </div>

    <section class="stats-grid" aria-label="Статистика системи">
        <?php foreach ($stats as $stat): ?>
            <a class="stat-card" href="<?= e($stat['href']) ?>">
                <div class="stat-top"><span><?= e($stat['label']) ?></span><span class="stat-icon <?= e($stat['tone']) ?>"><?= e($stat['icon']) ?></span></div>
                <div class="stat-value"><?= number_format($stat['value'], 0, ',', ' ') ?></div>
                <span class="stat-link">Переглянути розділ <span aria-hidden="true">→</span></span>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="panel quick-panel dashboard-quick-panel">
        <div class="panel-heading"><div><h2>Швидкий доступ</h2></div></div>
        <div class="quick-links">
            <a href="clients.php"><span class="quick-icon green">♙</span><span><strong>Клієнти</strong><small>Інформація та контакти</small></span><span class="quick-arrow">→</span></a>
            <a href="orders.php"><span class="quick-icon blue">▤</span><span><strong>Замовлення</strong><small>Облік і статуси замовлень</small></span><span class="quick-arrow">→</span></a>
            <a href="machinery.php"><span class="quick-icon orange">⚙</span><span><strong>Каталог техніки</strong><small>Перелік доступної продукції</small></span><span class="quick-arrow">→</span></a>
            <a href="inquiries.php"><span class="quick-icon purple">✉</span><span><strong>Звернення</strong><small>Запити та повідомлення клієнтів</small></span><span class="quick-arrow">→</span></a>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
