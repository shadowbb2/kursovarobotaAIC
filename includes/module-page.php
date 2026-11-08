<main class="page-content">
    <div class="page-heading">
        <div>
            <h1><?= e($pageTitle ?? '') ?></h1>
            <p class="page-description"><?= e($moduleDescription ?? '') ?></p>
        </div>
    </div>

    <section class="panel module-panel">
        <div class="panel-heading">
            <div>
                <h2><?= e($moduleTableTitle ?? 'Інформація') ?></h2>
                <p>Тут відображатимуться дані цього розділу.</p>
            </div>
            <span class="status-pill status-planned">У розробці</span>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php foreach (($moduleColumns ?? []) as $column): ?>
                            <th><?= e($column) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="<?= max(1, count($moduleColumns ?? [])) ?>" class="empty-state">
                            <span class="empty-icon">▤</span>
                            <strong>Поки що немає даних</strong>
                            <span><?= e($moduleEmptyText ?? 'Функції цього розділу будуть додані на наступному етапі розроблення.') ?></span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</main>
