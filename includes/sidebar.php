<?php
$navigation = [
    ['id' => 'dashboard', 'label' => 'Головна', 'icon' => '▦', 'href' => 'dashboard.php'],
    ['id' => 'clients', 'label' => 'Клієнти', 'icon' => '♙', 'href' => 'clients.php'],
    ['id' => 'orders', 'label' => 'Замовлення', 'icon' => '▤', 'href' => 'orders.php'],
    ['id' => 'machinery', 'label' => 'Каталог техніки', 'icon' => '⚙', 'href' => 'machinery.php'],
    ['id' => 'inquiries', 'label' => 'Звернення', 'icon' => '✉', 'href' => 'inquiries.php'],
    ['id' => 'profile', 'label' => 'Мій профіль', 'icon' => '●', 'href' => 'profile.php'],
];
?>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark">A</span>
        <span class="brand-text"><span>AIC</span><small>КЕРУВАННЯ КЛІЄНТАМИ</small></span>
    </a>

    <div class="sidebar-label">МЕНЮ</div>
    <nav class="sidebar-nav" aria-label="Головна навігація">
        <?php foreach ($navigation as $item): ?>
            <a class="nav-link <?= ($activePage ?? '') === $item['id'] ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
                <span class="nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="sidebar-help">
            <span class="help-icon">?</span>
            <div><strong>Потрібна допомога?</strong><small>Зверніться до адміністратора</small></div>
        </div>
        <div class="sidebar-version">AIC · Версія 1.0</div>
    </div>
</aside>
