<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Клієнти';
$activePage = 'clients';

$fields = ['company_name', 'contact_person', 'phone', 'email', 'city', 'address', 'notes'];
$formValues = array_fill_keys($fields, '');
$errors = [];
$error = '';
$clients = [];
$pdo = null;
$editId = null;
$deleteError = '';
$deleteClientId = null;
$bulkSelectedIds = [];
$bulkDeletedCount = null;
$bulkBlockedCount = null;
$bulkMissingCount = null;
$clientOrders = [];
$clientInquiries = [];

try {
    require_once __DIR__ . '/config/database.php';
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Не вдалося підключитися до бази даних. Перевірте налаштування та імпортуйте database.sql.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_clients') {
    $requestedIds = $_POST['client_ids'] ?? [];

    if (!is_array($requestedIds)) {
        $error = 'Некоректний список клієнтів для видалення.';
    } else {
        foreach ($requestedIds as $requestedId) {
            if (!is_string($requestedId) || !ctype_digit($requestedId) || (int) $requestedId < 1 || (int) $requestedId > 4294967295) {
                $error = 'Список містить некоректний ідентифікатор клієнта.';
                break;
            }
            $bulkSelectedIds[] = (int) $requestedId;
        }
        $bulkSelectedIds = array_values(array_unique($bulkSelectedIds));
    }

    if ($error === '' && $bulkSelectedIds === []) {
        $error = 'Виберіть хоча б одного клієнта для видалення.';
    }

    if ($error === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }

    if ($error === '' && $pdo instanceof PDO) {
        $placeholders = [];
        $parameters = [];
        foreach ($bulkSelectedIds as $index => $id) {
            $placeholder = ':client_' . $index;
            $placeholders[] = $placeholder;
            $parameters['client_' . $index] = $id;
        }
        $inClause = implode(', ', $placeholders);

        try {
            $pdo->beginTransaction();

            $statement = $pdo->prepare("SELECT id FROM clients WHERE id IN ($inClause) FOR UPDATE");
            $statement->execute($parameters);
            $existingIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
            $bulkMissingCount = count($bulkSelectedIds) - count($existingIds);

            if ($existingIds !== []) {
                $existingPlaceholders = [];
                $existingParameters = [];
                foreach ($existingIds as $index => $id) {
                    $placeholder = ':existing_' . $index;
                    $existingPlaceholders[] = $placeholder;
                    $existingParameters['existing_' . $index] = $id;
                }
                $existingInClause = implode(', ', $existingPlaceholders);

                $statement = $pdo->prepare("SELECT DISTINCT client_id FROM orders WHERE client_id IN ($existingInClause)");
                $statement->execute($existingParameters);
                $protectedIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
                $bulkBlockedCount = count($protectedIds);
                $eligibleIds = array_values(array_diff($existingIds, $protectedIds));
                $bulkDeletedCount = 0;

                if ($eligibleIds !== []) {
                    $eligiblePlaceholders = [];
                    $eligibleParameters = [];
                    foreach ($eligibleIds as $index => $id) {
                        $placeholder = ':eligible_' . $index;
                        $eligiblePlaceholders[] = $placeholder;
                        $eligibleParameters['eligible_' . $index] = $id;
                    }

                    $statement = $pdo->prepare(
                        'DELETE FROM clients
                         WHERE id IN (' . implode(', ', $eligiblePlaceholders) . ')
                           AND NOT EXISTS (
                               SELECT 1 FROM orders WHERE orders.client_id = clients.id
                           )'
                    );
                    $statement->execute($eligibleParameters);
                    $bulkDeletedCount = $statement->rowCount();
                }
            } else {
                $bulkDeletedCount = 0;
                $bulkBlockedCount = 0;
            }

            $pdo->commit();
            header(
                'Location: clients.php?bulk_deleted=' . $bulkDeletedCount
                . '&bulk_blocked=' . $bulkBlockedCount
                . '&bulk_missing=' . $bulkMissingCount
            );
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Не вдалося видалити вибраних клієнтів. Дані не змінено; спробуйте ще раз.';
        }
    } elseif ($error === '') {
        $error = 'Немає підключення до бази даних. Клієнтів не видалено.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_client') {
    $requestedDeleteId = $_POST['client_id'] ?? '';

    if (is_string($requestedDeleteId) && ctype_digit($requestedDeleteId) && (int) $requestedDeleteId > 0) {
        $deleteClientId = (int) $requestedDeleteId;
    } else {
        $deleteError = 'Некоректний ідентифікатор клієнта.';
    }

    if ($deleteError === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $deleteError = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }

    if ($deleteError === '') {
        if ($pdo instanceof PDO) {
            try {
                $statement = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
                $statement->execute(['id' => $deleteClientId]);
                if (!$statement->fetch()) {
                    $deleteError = 'Клієнта не знайдено. Список уже оновлено.';
                } else {
                    $statement = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE client_id = :id');
                    $statement->execute(['id' => $deleteClientId]);

                    if ((int) $statement->fetchColumn() > 0) {
                        $deleteError = 'Не можна видалити клієнта, поки до нього прив’язані замовлення.';
                    } else {
                        $statement = $pdo->prepare('DELETE FROM clients WHERE id = :id');
                        $statement->execute(['id' => $deleteClientId]);
                        header('Location: clients.php?deleted=1');
                        exit;
                    }
                }
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $deleteError = 'Не вдалося видалити клієнта. Перевірте пов’язані дані та спробуйте ще раз.';
            }
        } else {
            $deleteError = 'Немає підключення до бази даних. Клієнта не видалено.';
        }
    }

    $error = $deleteError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['delete_client', 'delete_clients'], true)) {
    $postedEditId = $_POST['edit_id'] ?? '';
    if (is_string($postedEditId) && $postedEditId !== '') {
        if (ctype_digit($postedEditId) && (int) $postedEditId > 0) {
            $editId = (int) $postedEditId;
        } else {
            $error = 'Некоректний ідентифікатор клієнта.';
        }
    }

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';
        $formValues[$field] = is_string($value) ? trim($value) : '';
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    } elseif ($pdo instanceof PDO && $error === '') {
        if ($editId !== null) {
            try {
                $statement = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
                $statement->execute(['id' => $editId]);
                if (!$statement->fetch()) {
                    $error = 'Клієнта не знайдено. Оновіть список і спробуйте ще раз.';
                }
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $error = 'Не вдалося перевірити клієнта для редагування.';
            }
        }

        if ($formValues['company_name'] === '') {
            $errors['company_name'] = 'Вкажіть назву компанії або ПІБ.';
        }

        $fieldLimits = [
            'company_name' => 180,
            'contact_person' => 150,
            'phone' => 40,
            'email' => 190,
            'city' => 100,
            'address' => 255,
        ];

        foreach ($fieldLimits as $field => $limit) {
            if (mb_strlen($formValues[$field], 'UTF-8') > $limit) {
                $errors[$field] = 'Перевищено максимально допустиму довжину.';
            }
        }

        if (strlen($formValues['notes']) > 65535) {
            $errors['notes'] = 'Нотатки перевищують максимально допустимий розмір.';
        }

        if ($formValues['email'] !== '' && !filter_var($formValues['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Вкажіть коректну електронну пошту.';
        }

        if ($errors === [] && $error === '') {
            try {
                $values = [
                    'company_name' => $formValues['company_name'],
                    'contact_person' => $formValues['contact_person'] !== '' ? $formValues['contact_person'] : null,
                    'phone' => $formValues['phone'] !== '' ? $formValues['phone'] : null,
                    'email' => $formValues['email'] !== '' ? $formValues['email'] : null,
                    'city' => $formValues['city'] !== '' ? $formValues['city'] : null,
                    'address' => $formValues['address'] !== '' ? $formValues['address'] : null,
                    'notes' => $formValues['notes'] !== '' ? $formValues['notes'] : null,
                ];

                if ($editId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE clients
                         SET company_name = :company_name,
                             contact_person = :contact_person,
                             phone = :phone,
                             email = :email,
                             city = :city,
                             address = :address,
                             notes = :notes
                         WHERE id = :id'
                    );
                    $statement->execute($values + ['id' => $editId]);
                    header('Location: clients.php?updated=1');
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO clients (company_name, contact_person, phone, email, city, address, notes, created_by)
                         VALUES (:company_name, :contact_person, :phone, :email, :city, :address, :notes, :created_by)'
                    );
                    $statement->execute($values + ['created_by' => currentUser()['id'] ?? null]);
                    header('Location: clients.php?added=1');
                }
                exit;
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $error = $editId !== null
                    ? 'Не вдалося оновити клієнта. Перевірте дані та спробуйте ще раз.'
                    : 'Не вдалося зберегти клієнта. Перевірте дані та спробуйте ще раз.';
            }
        }
    }
}

if ($pdo instanceof PDO) {
    try {
        $clients = $pdo->query(
            'SELECT clients.id, clients.company_name, clients.contact_person, clients.phone,
                    clients.email, clients.city, clients.address, clients.notes,
                    clients.created_by, clients.created_at, clients.updated_at,
                    users.full_name AS created_by_name
             FROM clients
             LEFT JOIN users ON users.id = clients.created_by
             ORDER BY clients.created_at DESC, clients.id DESC'
        )->fetchAll();

        if ($clients !== []) {
            $clientIds = array_map(static fn (array $client): int => (int) $client['id'], $clients);
            $placeholders = implode(', ', array_fill(0, count($clientIds), '?'));

            $statement = $pdo->prepare(
                'SELECT id, client_id, order_number, order_name, status, order_date, due_date, total_price
                 FROM orders
                 WHERE client_id IN (' . $placeholders . ')
                 ORDER BY order_date DESC, id DESC'
            );
            $statement->execute($clientIds);
            foreach ($statement->fetchAll() as $order) {
                $clientOrders[(int) $order['client_id']][] = [
                    'id' => (int) $order['id'],
                    'order_number' => $order['order_number'],
                    'order_name' => $order['order_name'],
                    'status' => $order['status'],
                    'order_date' => $order['order_date'],
                    'due_date' => $order['due_date'],
                    'total_price' => $order['total_price'],
                ];
            }

            $statement = $pdo->prepare(
                'SELECT id, client_id, inquiry_number, subject, status, created_at, response_due_date
                 FROM inquiries
                 WHERE client_id IN (' . $placeholders . ')
                 ORDER BY created_at DESC, id DESC'
            );
            $statement->execute($clientIds);
            foreach ($statement->fetchAll() as $inquiry) {
                $clientInquiries[(int) $inquiry['client_id']][] = [
                    'id' => (int) $inquiry['id'],
                    'inquiry_number' => $inquiry['inquiry_number'],
                    'subject' => $inquiry['subject'],
                    'status' => $inquiry['status'],
                    'created_at' => $inquiry['created_at'],
                    'response_due_date' => $inquiry['response_due_date'],
                ];
            }
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = 'Не вдалося завантажити список клієнтів або пов’язані записи. Перевірте структуру бази даних.';
    }
}

$success = ($_GET['added'] ?? '') === '1';
$updated = ($_GET['updated'] ?? '') === '1';
$deleted = ($_GET['deleted'] ?? '') === '1';
$bulkDeletedCount = isset($_GET['bulk_deleted']) && ctype_digit((string) $_GET['bulk_deleted'])
    ? (int) $_GET['bulk_deleted']
    : $bulkDeletedCount;
$bulkBlockedCount = isset($_GET['bulk_blocked']) && ctype_digit((string) $_GET['bulk_blocked'])
    ? (int) $_GET['bulk_blocked']
    : $bulkBlockedCount;
$bulkMissingCount = isset($_GET['bulk_missing']) && ctype_digit((string) $_GET['bulk_missing'])
    ? (int) $_GET['bulk_missing']
    : $bulkMissingCount;
$showAddForm = $_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['delete_client', 'delete_clients'], true)
    && !array_key_exists('edit_id', $_POST)
    && $editId === null;
$reopenClientEditor = $_SERVER['REQUEST_METHOD'] === 'POST'
    && $editId !== null
    && ($errors !== [] || $error !== '');
$clientFormValuesJson = json_encode(
    $formValues,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$modalViewId = isset($_GET['view']) && is_string($_GET['view']) && ctype_digit($_GET['view'])
    ? (int) $_GET['view']
    : $deleteClientId;

require __DIR__ . '/includes/header.php';
?>
<main class="page-content">
    <div class="page-heading clients-page-heading">
        <div>
            <h1><?= e($pageTitle) ?></h1>
        </div>
        <button class="button button-primary clients-add-link" id="showClientForm" type="button" aria-expanded="<?= $showAddForm ? 'true' : 'false' ?>" aria-controls="add-client">＋ Додати клієнта</button>
    </div>

    <?php if ($success): ?>
        <div class="alert-success clients-notice" role="status">Клієнта успішно додано.</div>
    <?php endif; ?>
    <?php if ($updated): ?>
        <div class="alert-success clients-notice" role="status">Дані клієнта успішно оновлено.</div>
    <?php endif; ?>
    <?php if ($deleted): ?>
        <div class="alert-success clients-notice" role="status">Клієнта успішно видалено.</div>
    <?php endif; ?>
    <?php if ($bulkDeletedCount !== null): ?>
        <div class="<?= $bulkDeletedCount === 0 && $bulkBlockedCount > 0 ? 'alert alert-error' : 'alert-success' ?> clients-notice" role="status">
            Видалено клієнтів: <?= number_format($bulkDeletedCount, 0, ',', ' ') ?>.
            <?php if ($bulkBlockedCount > 0): ?>
                Не видалено через пов’язані замовлення: <?= number_format($bulkBlockedCount, 0, ',', ' ') ?>.
            <?php endif; ?>
            <?php if ($bulkMissingCount > 0): ?>
                Не знайдено: <?= number_format($bulkMissingCount, 0, ',', ' ') ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error clients-notice" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel client-form-panel" id="add-client" <?= $showAddForm ? '' : 'hidden' ?>>
        <div class="panel-heading">
            <div>
                <h2>Новий клієнт</h2>
                <p>Додайте компанію або контакт до бази клієнтів.</p>
            </div>
            <div class="client-form-heading-actions">
                <span class="client-required-note"><span aria-hidden="true">*</span> Обов’язкове поле</span>
                <button class="button button-secondary client-form-close" id="hideClientForm" type="button">Закрити</button>
            </div>
        </div>

        <form method="post" action="clients.php" class="client-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <div class="client-form-grid">
                <div class="form-group">
                    <label for="company_name">Компанія / ПІБ <span class="required-mark">*</span></label>
                    <input id="company_name" name="company_name" type="text" maxlength="180" required value="<?= e($formValues['company_name']) ?>" placeholder="Наприклад, ТОВ «Агро-Плюс»">
                    <?php if (isset($errors['company_name'])): ?><span class="field-error"><?= e($errors['company_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="contact_person">Контактна особа</label>
                    <input id="contact_person" name="contact_person" type="text" maxlength="150" value="<?= e($formValues['contact_person']) ?>" placeholder="Ім’я та прізвище">
                    <?php if (isset($errors['contact_person'])): ?><span class="field-error"><?= e($errors['contact_person']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="phone">Телефон</label>
                    <input id="phone" name="phone" type="tel" maxlength="40" value="<?= e($formValues['phone']) ?>" placeholder="+380 00 000 00 00">
                    <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="email">Електронна пошта</label>
                    <input id="email" name="email" type="email" maxlength="190" value="<?= e($formValues['email']) ?>" placeholder="name@example.com">
                    <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="city">Місто</label>
                    <input id="city" name="city" type="text" maxlength="100" value="<?= e($formValues['city']) ?>" placeholder="Наприклад, Київ">
                    <?php if (isset($errors['city'])): ?><span class="field-error"><?= e($errors['city']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="address">Адреса</label>
                    <input id="address" name="address" type="text" maxlength="255" value="<?= e($formValues['address']) ?>" placeholder="Вулиця, будинок">
                    <?php if (isset($errors['address'])): ?><span class="field-error"><?= e($errors['address']) ?></span><?php endif; ?>
                </div>
                <div class="form-group client-notes-group">
                    <label for="notes">Нотатки</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="65535" placeholder="Додаткова інформація про клієнта"><?= e($formValues['notes']) ?></textarea>
                    <?php if (isset($errors['notes'])): ?><span class="field-error"><?= e($errors['notes']) ?></span><?php endif; ?>
                </div>
                <div class="client-form-actions">
                    <button class="button button-primary" type="submit" <?= $pdo instanceof PDO ? '' : 'disabled' ?>>Зберегти клієнта</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel module-panel clients-list-panel">
        <form method="post" action="clients.php" class="client-bulk-form" id="clientBulkForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_clients">
            <div class="panel-heading client-list-heading">
                <div>
                    <h2>Список клієнтів</h2>
                    <p>Усього записів: <?= number_format(count($clients), 0, ',', ' ') ?></p>
                </div>
                <div class="client-bulk-actions">
                    <button class="button button-danger" id="startBulkDelete" type="button" <?= $clients === [] ? 'disabled' : '' ?>>Видалити клієнтів</button>
                    <button class="button button-danger" id="bulkDeleteButton" type="submit" disabled hidden>Видалити вибраних</button>
                    <button class="button button-secondary" id="cancelBulkDelete" type="button" hidden>Скасувати</button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="client-select-cell"><input type="checkbox" id="selectAllClients" aria-label="Вибрати всіх клієнтів"></th>
                            <th>Компанія / ПІБ</th>
                            <th>Контактна особа</th>
                            <th>Телефон</th>
                            <th>Електронна пошта</th>
                            <th>Місто</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($clients === []): ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                <span class="empty-icon">▤</span>
                                <strong>Поки що немає клієнтів</strong>
                                <span>Заповніть форму вище, щоб додати перший запис.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                $clientDetails = [
                                    'id' => (int) $client['id'],
                                    'company_name' => $client['company_name'],
                                    'contact_person' => $client['contact_person'],
                                    'phone' => $client['phone'],
                                    'email' => $client['email'],
                                    'city' => $client['city'],
                                    'address' => $client['address'],
                                    'notes' => $client['notes'],
                                    'created_by' => $client['created_by'],
                                    'created_by_name' => $client['created_by_name'],
                                    'created_at' => $client['created_at'],
                                    'updated_at' => $client['updated_at'],
                                    'orders' => $clientOrders[(int) $client['id']] ?? [],
                                    'inquiries' => $clientInquiries[(int) $client['id']] ?? [],
                                ];
                                $clientDetailsJson = json_encode(
                                    $clientDetails,
                                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                                );
                                ?>
                                <tr class="client-row" tabindex="0" role="button" aria-haspopup="dialog" aria-label="Переглянути дані клієнта: <?= e($client['company_name']) ?>" data-client-details="<?= e($clientDetailsJson) ?>">
                                    <td class="client-select-cell"><input class="client-select-checkbox" type="checkbox" name="client_ids[]" value="<?= (int) $client['id'] ?>" aria-label="Вибрати клієнта: <?= e($client['company_name']) ?>" <?= in_array((int) $client['id'], $bulkSelectedIds, true) ? 'checked' : '' ?>></td>
                                    <td class="client-company-cell"><?= e($client['company_name']) ?></td>
                                    <td><?= e($client['contact_person'] ?: '—') ?></td>
                                    <td><?= e($client['phone'] ?: '—') ?></td>
                                    <td><?= e($client['email'] ?: '—') ?></td>
                                    <td><?= e($client['city'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </section>

    <div class="client-details-dialog" id="clientDetailsDialog" hidden
         data-reopen-edit="<?= $reopenClientEditor ? '1' : '0' ?>"
         data-edit-client-id="<?= $editId !== null ? $editId : '' ?>"
         data-edit-values="<?= e($clientFormValuesJson) ?>"
         data-view-client-id="<?= $modalViewId !== null ? $modalViewId : '' ?>">
        <section class="client-details-content" role="dialog" aria-modal="true" aria-labelledby="clientDetailsTitle" tabindex="-1">
            <div class="client-details-heading">
                <div>
                    <p class="eyebrow">ІНФОРМАЦІЯ ПРО КЛІЄНТА</p>
                    <h2 id="clientDetailsTitle"></h2>
                </div>
                <button class="icon-button client-details-close" type="button" aria-label="Закрити вікно">×</button>
            </div>
            <?php if ($deleteError !== ''): ?>
                <div class="alert alert-error client-modal-error" role="alert"><?= e($deleteError) ?></div>
            <?php endif; ?>
            <div id="clientDetailsView">
                <dl class="client-details-list" id="clientDetailsList"></dl>
                <section class="client-related-records" aria-labelledby="clientRelatedOrdersTitle">
                    <h3 id="clientRelatedOrdersTitle">Замовлення</h3>
                    <ul class="client-related-list" id="clientRelatedOrders"></ul>
                </section>
                <section class="client-related-records" aria-labelledby="clientRelatedInquiriesTitle">
                    <h3 id="clientRelatedInquiriesTitle">Звернення</h3>
                    <ul class="client-related-list" id="clientRelatedInquiries"></ul>
                </section>
            </div>
            <form method="post" action="clients.php" class="client-details-form" id="clientDetailsForm" hidden>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="edit_id" id="clientEditId" value="">
                <div class="client-details-form-grid">
                    <div class="form-group">
                        <label for="modal_company_name">Компанія / ПІБ <span class="required-mark">*</span></label>
                        <input id="modal_company_name" name="company_name" type="text" maxlength="180" required>
                        <?php if (isset($errors['company_name'])): ?><span class="field-error"><?= e($errors['company_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_contact_person">Контактна особа</label>
                        <input id="modal_contact_person" name="contact_person" type="text" maxlength="150">
                        <?php if (isset($errors['contact_person'])): ?><span class="field-error"><?= e($errors['contact_person']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_phone">Телефон</label>
                        <input id="modal_phone" name="phone" type="tel" maxlength="40">
                        <?php if (isset($errors['phone'])): ?><span class="field-error"><?= e($errors['phone']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_email">Електронна пошта</label>
                        <input id="modal_email" name="email" type="email" maxlength="190">
                        <?php if (isset($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_city">Місто</label>
                        <input id="modal_city" name="city" type="text" maxlength="100">
                        <?php if (isset($errors['city'])): ?><span class="field-error"><?= e($errors['city']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_address">Адреса</label>
                        <input id="modal_address" name="address" type="text" maxlength="255">
                        <?php if (isset($errors['address'])): ?><span class="field-error"><?= e($errors['address']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group client-details-notes">
                        <label for="modal_notes">Додаткова інформація</label>
                        <textarea id="modal_notes" name="notes" rows="3" maxlength="65535"></textarea>
                        <?php if (isset($errors['notes'])): ?><span class="field-error"><?= e($errors['notes']) ?></span><?php endif; ?>
                    </div>
                </div>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-error client-modal-error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <div class="client-details-actions">
                    <button class="button button-primary" type="submit">Зберегти зміни</button>
                    <button class="button button-secondary client-details-cancel-edit" type="button">Скасувати</button>
                </div>
            </form>
            <div class="client-details-actions" id="clientDetailsActions">
                <button class="button button-primary" id="clientDetailsEdit" type="button">Редагувати дані</button>
                <form method="post" action="clients.php" class="client-delete-form" id="clientDeleteForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_client">
                    <input type="hidden" name="client_id" id="clientDeleteId" value="">
                    <button class="button button-danger" type="submit">Видалити клієнта</button>
                </form>
                <button class="button button-secondary client-details-close" type="button">Закрити</button>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
