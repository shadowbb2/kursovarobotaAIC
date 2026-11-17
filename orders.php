<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Замовлення';
$activePage = 'orders';
$statuses = ['в роботі', 'Виконано', 'Нове', 'Закрито', 'Відкладено'];
$formValues = [
    'order_name' => '',
    'client_id' => '',
    'order_date' => date('Y-m-d'),
    'due_date' => '',
    'status' => 'Нове',
    'notes' => '',
];
$formItems = [['machinery_id' => '', 'quantity' => '1']];
$errors = [];
$error = '';
$success = ($_GET['added'] ?? '') === '1';
$clients = [];
$products = [];
$hasPricedProducts = false;
$orders = [];
$orderItems = [];
$showOrderForm = false;
$editId = null;
$editItems = [];
$reopenOrderEditor = false;
$deleteError = '';
$deleteOrderId = null;
$bulkSelectedIds = [];
$bulkDeletedCount = null;
$bulkMissingCount = null;
$pdo = null;

try {
    require_once __DIR__ . '/config/database.php';
    $clients = $pdo->query(
        'SELECT id, company_name
         FROM clients
         ORDER BY company_name, id'
    )->fetchAll();
    $products = $pdo->query(
        'SELECT id, name, model, price
         FROM machinery
         ORDER BY name, model, id'
    )->fetchAll();
    foreach ($products as $product) {
        if ($product['price'] !== null) {
            $hasPricedProducts = true;
            break;
        }
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Не вдалося завантажити клієнтів, продукцію або підключитися до бази даних.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_orders') {
   $requestedIds = $_POST['order_ids'] ?? [];
   if (!is_array($requestedIds)) {
       $error = 'Некоректний список замовлень для видалення.';
   } else {
       foreach ($requestedIds as $requestedId) {
           if (!is_string($requestedId) || !ctype_digit($requestedId) || (int) $requestedId < 1 || (int) $requestedId > 4294967295) {
               $error = 'Список містить некоректний ідентифікатор замовлення.';
               break;
           }
           $bulkSelectedIds[] = (int) $requestedId;
       }
       $bulkSelectedIds = array_values(array_unique($bulkSelectedIds));
   }

   if ($error === '' && $bulkSelectedIds === []) {
       $error = 'Виберіть хоча б одне замовлення для видалення.';
   }
   if ($error === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
       $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
   }
   if ($error === '' && $pdo instanceof PDO) {
       $placeholders = [];
       $parameters = [];
       foreach ($bulkSelectedIds as $index => $id) {
           $placeholders[] = ':order_' . $index;
           $parameters['order_' . $index] = $id;
       }

       try {
           $pdo->beginTransaction();
           $statement = $pdo->prepare(
               'SELECT id FROM orders WHERE id IN (' . implode(', ', $placeholders) . ') FOR UPDATE'
           );
           $statement->execute($parameters);
           $existingIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
           $bulkMissingCount = count($bulkSelectedIds) - count($existingIds);
           $bulkDeletedCount = 0;
           if ($existingIds !== []) {
               $existingPlaceholders = [];
               $existingParameters = [];
               foreach ($existingIds as $index => $id) {
                   $existingPlaceholders[] = ':existing_' . $index;
                   $existingParameters['existing_' . $index] = $id;
               }
               $statement = $pdo->prepare(
                   'DELETE FROM orders WHERE id IN (' . implode(', ', $existingPlaceholders) . ')'
               );
               $statement->execute($existingParameters);
               $bulkDeletedCount = $statement->rowCount();
           }

           $pdo->commit();
           header(
               'Location: orders.php?bulk_deleted=' . $bulkDeletedCount
               . '&bulk_missing=' . $bulkMissingCount
           );
           exit;
       } catch (PDOException $exception) {
           if ($pdo->inTransaction()) {
               $pdo->rollBack();
           }
           error_log($exception->getMessage());
           $error = 'Не вдалося видалити вибрані замовлення. Дані не змінено; спробуйте ще раз.';
       }
   } elseif ($error === '') {
       $error = 'Немає підключення до бази даних. Замовлення не видалено.';
   }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_order') {
   $requestedDeleteId = $_POST['order_id'] ?? '';
   if (is_string($requestedDeleteId) && ctype_digit($requestedDeleteId) && (int) $requestedDeleteId > 0) {
       $deleteOrderId = (int) $requestedDeleteId;
   } else {
       $deleteError = 'Некоректний ідентифікатор замовлення.';
   }
   if ($deleteError === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
       $deleteError = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
   }
   if ($deleteError === '' && $pdo instanceof PDO) {
       try {
           $statement = $pdo->prepare('DELETE FROM orders WHERE id = :id');
           $statement->execute(['id' => $deleteOrderId]);
           if ($statement->rowCount() === 1) {
               header('Location: orders.php?deleted=1');
               exit;
           }
           $deleteError = 'Замовлення не знайдено. Список уже оновлено.';
       } catch (PDOException $exception) {
           error_log($exception->getMessage());
           $deleteError = 'Не вдалося видалити замовлення. Спробуйте ще раз.';
       }
   } elseif ($deleteError === '') {
       $deleteError = 'Немає підключення до бази даних. Замовлення не видалено.';
   }
   $error = $deleteError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
   && !in_array($_POST['action'] ?? '', ['delete_order', 'delete_orders'], true)) {
   $action = $_POST['action'] ?? '';
   $showOrderForm = $action === 'create_order';
   $postedEditId = $_POST['edit_id'] ?? '';
   if ($action === 'edit_order' && is_string($postedEditId) && ctype_digit($postedEditId) && (int) $postedEditId > 0) {
       $editId = (int) $postedEditId;
   } elseif ($action === 'edit_order') {
       $error = 'Некоректний ідентифікатор замовлення.';
   }
   foreach (['order_name', 'client_id', 'due_date', 'status', 'notes'] as $field) {
       $value = $_POST[$field] ?? '';
       $formValues[$field] = is_string($value) ? trim($value) : '';
    }

    $postedOrderItems = $_POST['machinery_id'] ?? [];
    $postedQuantities = $_POST['item_quantity'] ?? [];
    if (!is_array($postedOrderItems) || !is_array($postedQuantities)) {
        $errors['items'] = 'Некоректний список продукції у замовленні.';
    } else {
        $itemCount = max(count($postedOrderItems), count($postedQuantities));
        if ($itemCount > 30) {
            $errors['items'] = 'В одному замовленні можна додати не більше 30 позицій.';
        }
        $formItems = [];
        for ($index = 0; $index < min($itemCount, 30); $index++) {
            $productId = $postedOrderItems[$index] ?? '';
            $quantity = $postedQuantities[$index] ?? '';
            $formItems[] = [
                'machinery_id' => is_string($productId) ? trim($productId) : '',
                'quantity' => is_string($quantity) ? trim($quantity) : '',
            ];
        }
        if ($formItems === []) {
            $formItems[] = ['machinery_id' => '', 'quantity' => '1'];
        }
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    } elseif (!$pdo instanceof PDO && $error === '') {
        $error = 'Немає підключення до бази даних. Замовлення не збережено.';
    } elseif (!in_array($action, ['create_order', 'edit_order'], true)) {
        $error = 'Невідома дія для замовлень.';
    } elseif ($formValues['order_name'] === '') {
        $errors['order_name'] = 'Вкажіть назву замовлення.';
    } elseif (mb_strlen($formValues['order_name'], 'UTF-8') > 180) {
        $errors['order_name'] = 'Назва замовлення має бути не довша за 180 символів.';
    }

    $existingOrderDate = date('Y-m-d');
    if ($editId !== null && $pdo instanceof PDO && $error === '') {
        try {
            $statement = $pdo->prepare('SELECT order_date FROM orders WHERE id = :id');
            $statement->execute(['id' => $editId]);
            $existingOrderDate = $statement->fetchColumn() ?: '';
            if ($existingOrderDate === '') {
                $error = 'Замовлення не знайдено. Оновіть список і спробуйте ще раз.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Не вдалося перевірити замовлення для редагування.';
        }
    }

    if ($formValues['client_id'] === '' || !ctype_digit($formValues['client_id']) || (int) $formValues['client_id'] < 1) {
        $errors['client_id'] = 'Оберіть клієнта зі списку.';
    }
    if ($formValues['due_date'] === '') {
        $errors['due_date'] = 'Оберіть заплановану дату завершення.';
    } else {
        $dueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formValues['due_date']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dueDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dueDate->format('Y-m-d') !== $formValues['due_date']) {
            $errors['due_date'] = 'Вкажіть коректну дату завершення.';
        } elseif ($formValues['due_date'] < $existingOrderDate) {
            $errors['due_date'] = 'Дата завершення не може бути раніше дати створення замовлення.';
        }
    }
    if (!in_array($formValues['status'], $statuses, true)) {
        $errors['status'] = 'Оберіть статус замовлення зі списку.';
    }
    if (strlen($formValues['notes']) > 65535) {
        $errors['notes'] = 'Коментар до замовлення перевищує допустимий розмір.';
    }

    $selectedProductIds = [];
    $totalQuantity = 0;
    foreach ($formItems as $index => $item) {
        if ($item['machinery_id'] === '' && $item['quantity'] === '') {
            continue;
        }
        if (!ctype_digit($item['machinery_id']) || (int) $item['machinery_id'] < 1) {
            $errors['items'] = 'Оберіть продукт для кожної заповненої позиції.';
            continue;
        }
        if (!ctype_digit($item['quantity']) || (int) $item['quantity'] < 1 || (int) $item['quantity'] > 100000) {
            $errors['items'] = 'Кількість у кожній позиції має бути від 1 до 100 000.';
            continue;
        }
        $productId = (int) $item['machinery_id'];
        if (in_array($productId, $selectedProductIds, true)) {
            $errors['items'] = 'Додайте кожен продукт у замовлення лише один раз і змініть його кількість у відповідній позиції.';
            continue;
        }
        $selectedProductIds[] = $productId;
        $totalQuantity += (int) $item['quantity'];
    }
    if ($selectedProductIds === []) {
        $errors['items'] = 'Додайте до замовлення хоча б один продукт.';
    }
    if ($totalQuantity > 4294967295) {
        $errors['items'] = 'Загальна кількість продуктів у замовленні перевищує допустиме значення.';
    }

    if ($errors === [] && $error === '' && $pdo instanceof PDO) {
        try {
            $pdo->beginTransaction();

            if ($editId !== null) {
                $orderLock = $pdo->prepare('SELECT order_date FROM orders WHERE id = :id FOR UPDATE');
                $orderLock->execute(['id' => $editId]);
                $lockedOrderDate = $orderLock->fetchColumn();
                if (!$lockedOrderDate) {
                    $errors['order'] = 'Замовлення не знайдено. Оновіть список і спробуйте ще раз.';
                } else {
                    $existingOrderDate = $lockedOrderDate;
                    if ($formValues['due_date'] < $existingOrderDate) {
                        $errors['due_date'] = 'Дата завершення не може бути раніше дати створення замовлення.';
                    }
                }
            }

            $clientStatement = $pdo->prepare('SELECT id FROM clients WHERE id = :id FOR UPDATE');
            $clientStatement->execute(['id' => (int) $formValues['client_id']]);
            if (!$clientStatement->fetch()) {
                $errors['client_id'] = 'Вибраного клієнта не знайдено. Оновіть сторінку та оберіть іншого.';
            }

            $productPlaceholders = [];
            $productParameters = [];
            foreach ($selectedProductIds as $index => $productId) {
                $productPlaceholders[] = ':product_' . $index;
                $productParameters['product_' . $index] = $productId;
            }
            $productStatement = $pdo->prepare(
                'SELECT id, name, model, price
                 FROM machinery
                 WHERE id IN (' . implode(', ', $productPlaceholders) . ')
                 FOR UPDATE'
            );
            $productStatement->execute($productParameters);
            $selectedProducts = [];
            foreach ($productStatement->fetchAll() as $product) {
                $selectedProducts[(int) $product['id']] = $product;
            }
            if (count($selectedProducts) !== count($selectedProductIds)) {
                $errors['items'] = 'Один із продуктів більше недоступний. Оновіть сторінку та перевірте замовлення.';
            }

            $itemsForInsert = [];
            $totalCents = 0;
            foreach ($formItems as $item) {
                if ($item['machinery_id'] === '' || !ctype_digit($item['machinery_id'])
                    || !ctype_digit($item['quantity']) || (int) $item['quantity'] < 1) {
                    continue;
                }
                $productId = (int) $item['machinery_id'];
                if (!isset($selectedProducts[$productId])) {
                    continue;
                }
                $product = $selectedProducts[$productId];
                if ($product['price'] === null
                    || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string) $product['price'])) {
                    $errors['items'] = 'Для кожного продукту в замовленні має бути вказана коректна ціна у каталозі.';
                    continue;
                }
                $priceParts = explode('.', (string) $product['price'], 2);
                $unitCents = ((int) $priceParts[0] * 100) + (int) str_pad($priceParts[1] ?? '', 2, '0');
                $quantity = (int) $item['quantity'];
                $lineCents = $unitCents * $quantity;
                $totalCents += $lineCents;
                $itemsForInsert[] = [
                    'machinery_id' => $productId,
                    'product_name' => mb_substr(
                        trim($product['name'] . ($product['model'] ? ' ' . $product['model'] : '')),
                        0,
                        180,
                        'UTF-8'
                    ),
                    'quantity' => $quantity,
                    'unit_cents' => $unitCents,
                    'line_cents' => $lineCents,
                ];
            }
            if ($totalCents > 999999999999) {
                $errors['items'] = 'Загальна сума перевищує допустиме значення.';
            }

            if ($errors !== []) {
                $pdo->rollBack();
            } else {
                $orderId = $editId;
                if ($editId !== null) {
                    $orderStatement = $pdo->prepare(
                        'UPDATE orders
                         SET order_name = :order_name, client_id = :client_id, machinery_id = :machinery_id,
                             quantity = :quantity, total_price = :total_price, status = :status,
                             due_date = :due_date, notes = :notes
                         WHERE id = :id'
                    );
                    $orderStatement->execute([
                        'order_name' => $formValues['order_name'],
                        'client_id' => (int) $formValues['client_id'],
                        'machinery_id' => $itemsForInsert[0]['machinery_id'],
                        'quantity' => $totalQuantity,
                        'total_price' => number_format($totalCents / 100, 2, '.', ''),
                        'status' => $formValues['status'],
                        'due_date' => $formValues['due_date'],
                        'notes' => $formValues['notes'] !== '' ? $formValues['notes'] : null,
                        'id' => $editId,
                    ]);
                    $pdo->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $editId]);
                } else {
                    $temporaryNumber = 'TMP-' . bin2hex(random_bytes(16));
                    $orderStatement = $pdo->prepare(
                        'INSERT INTO orders
                            (order_number, order_name, client_id, machinery_id, quantity, total_price,
                             status, order_date, due_date, notes, created_by)
                         VALUES
                            (:order_number, :order_name, :client_id, :machinery_id, :quantity, :total_price,
                             :status, :order_date, :due_date, :notes, :created_by)'
                    );
                    $orderStatement->execute([
                        'order_number' => $temporaryNumber,
                        'order_name' => $formValues['order_name'],
                        'client_id' => (int) $formValues['client_id'],
                        'machinery_id' => $itemsForInsert[0]['machinery_id'],
                        'quantity' => $totalQuantity,
                        'total_price' => number_format($totalCents / 100, 2, '.', ''),
                        'status' => $formValues['status'],
                        'order_date' => date('Y-m-d'),
                        'due_date' => $formValues['due_date'],
                        'notes' => $formValues['notes'] !== '' ? $formValues['notes'] : null,
                        'created_by' => currentUser()['id'] ?? null,
                    ]);
                    $orderId = (int) $pdo->lastInsertId();
                    $orderNumber = 'ORD-' . date('Y') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
                    $pdo->prepare('UPDATE orders SET order_number = :order_number WHERE id = :id')
                        ->execute(['order_number' => $orderNumber, 'id' => $orderId]);
                }

                $itemStatement = $pdo->prepare(
                    'INSERT INTO order_items
                        (order_id, machinery_id, product_name, quantity, unit_price, line_total)
                     VALUES
                        (:order_id, :machinery_id, :product_name, :quantity, :unit_price, :line_total)'
                );
                foreach ($itemsForInsert as $item) {
                    $itemStatement->execute([
                        'order_id' => $orderId,
                        'machinery_id' => $item['machinery_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => number_format($item['unit_cents'] / 100, 2, '.', ''),
                        'line_total' => number_format($item['line_cents'] / 100, 2, '.', ''),
                    ]);
                }

                $pdo->commit();
                header('Location: orders.php?' . ($editId !== null ? 'updated=1' : 'added=1'));
                exit;
            }
        } catch (PDOException | Random\RandomException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Не вдалося зберегти замовлення. Дані не змінено; перевірте структуру бази даних і спробуйте ще раз.';
        }
    }
    if ($editId !== null && ($errors !== [] || $error !== '')) {
        $editItems = $formItems;
        $reopenOrderEditor = true;
    }
}

if ($pdo instanceof PDO) {
    try {
        $orders = $pdo->query(
            'SELECT orders.id, orders.order_number, orders.order_name, orders.client_id, orders.total_price,
                    orders.status, orders.order_date, orders.due_date, orders.notes,
                    orders.machinery_id, orders.quantity, clients.company_name,
                    machinery.name AS legacy_product_name, machinery.model AS legacy_product_model
             FROM orders
             INNER JOIN clients ON clients.id = orders.client_id
             LEFT JOIN machinery ON machinery.id = orders.machinery_id
             ORDER BY orders.created_at DESC, orders.id DESC'
        )->fetchAll();

        if ($orders !== []) {
            $orderIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);
            $placeholders = [];
            $parameters = [];
            foreach ($orderIds as $index => $orderId) {
                $placeholders[] = ':order_' . $index;
                $parameters['order_' . $index] = $orderId;
            }
            $itemStatement = $pdo->prepare(
                'SELECT order_id, machinery_id, product_name, quantity, unit_price, line_total
                 FROM order_items
                 WHERE order_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY id'
            );
            $itemStatement->execute($parameters);
            foreach ($itemStatement->fetchAll() as $item) {
                $orderItems[(int) $item['order_id']][] = $item;
            }
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = 'Не вдалося завантажити замовлення. Перевірте, чи застосована міграція бази даних.';
    }
}

$updated = ($_GET['updated'] ?? '') === '1';
$deleted = ($_GET['deleted'] ?? '') === '1';
$bulkDeletedCount = isset($_GET['bulk_deleted']) && ctype_digit((string) $_GET['bulk_deleted'])
    ? (int) $_GET['bulk_deleted']
    : $bulkDeletedCount;
$bulkMissingCount = isset($_GET['bulk_missing']) && ctype_digit((string) $_GET['bulk_missing'])
    ? (int) $_GET['bulk_missing']
    : $bulkMissingCount;
$formValuesJson = json_encode(
    $formValues,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$editItemsJson = json_encode(
    $editItems,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$reopenOrderEditor = $editId !== null && ($errors !== [] || $error !== '');
$modalViewId = $deleteOrderId;
if (isset($_GET['view']) && is_string($_GET['view']) && ctype_digit($_GET['view'])) {
    $modalViewId = (int) $_GET['view'];
}

require __DIR__ . '/includes/header.php';
?>
<main class="page-content">
    <div class="page-heading clients-page-heading">
        <div>
            <h1><?= e($pageTitle) ?></h1>
        </div>
        <button class="button button-primary clients-add-link" id="showOrderForm" type="button" aria-expanded="<?= $showOrderForm ? 'true' : 'false' ?>" aria-controls="add-order">＋ Створити замовлення</button>
    </div>

    <?php if ($success): ?>
        <div class="alert-success clients-notice" role="status">Замовлення успішно створено.</div>
    <?php endif; ?>
    <?php if ($updated): ?>
        <div class="alert-success clients-notice" role="status">Замовлення успішно оновлено.</div>
    <?php endif; ?>
    <?php if ($deleted): ?>
        <div class="alert-success clients-notice" role="status">Замовлення успішно видалено.</div>
    <?php endif; ?>
    <?php if ($bulkDeletedCount !== null): ?>
        <div class="alert-success clients-notice" role="status">
            Видалено замовлень: <?= number_format($bulkDeletedCount, 0, ',', ' ') ?>.
            <?php if ($bulkMissingCount > 0): ?>Не знайдено: <?= number_format($bulkMissingCount, 0, ',', ' ') ?>.<?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error clients-notice" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel client-form-panel order-form-panel" id="add-order" <?= $showOrderForm ? '' : 'hidden' ?>>
        <div class="panel-heading">
            <div>
                <h2>Нове замовлення</h2>
                <p>Номер замовлення буде створено автоматично після збереження.</p>
            </div>
            <button class="button button-secondary client-form-close" id="hideOrderForm" type="button">Закрити</button>
        </div>

        <form method="post" action="orders.php" class="client-form" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_order">
            <div class="client-form-grid">
                <div class="form-group">
                    <label for="order_name">Назва замовлення <span class="required-mark">*</span></label>
                    <input id="order_name" name="order_name" type="text" maxlength="180" required value="<?= e($formValues['order_name']) ?>" placeholder="Наприклад, Трактор для ферми">
                    <?php if (isset($errors['order_name'])): ?><span class="field-error"><?= e($errors['order_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="order_client">Клієнт <span class="required-mark">*</span></label>
                    <select id="order_client" name="client_id" required>
                        <option value="">Оберіть клієнта</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>" <?= $formValues['client_id'] === (string) $client['id'] ? 'selected' : '' ?>><?= e($client['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($clients === []): ?><span class="field-error">Спочатку додайте клієнта у вкладці «Клієнти».</span><?php endif; ?>
                    <?php if (isset($errors['client_id'])): ?><span class="field-error"><?= e($errors['client_id']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="order_date">Дата створення</label>
                    <input id="order_date" type="date" value="<?= e(date('Y-m-d')) ?>" readonly>
                    <span class="form-hint">Встановлюється автоматично під час збереження.</span>
                </div>
                <div class="form-group">
                    <label for="due_date">Запланована дата завершення <span class="required-mark">*</span></label>
                    <input id="due_date" name="due_date" type="date" min="<?= e(date('Y-m-d')) ?>" required value="<?= e($formValues['due_date']) ?>">
                    <?php if (isset($errors['due_date'])): ?><span class="field-error"><?= e($errors['due_date']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="order_status">Статус</label>
                    <select id="order_status" name="status">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= e($status) ?>" <?= $formValues['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?><span class="field-error"><?= e($errors['status']) ?></span><?php endif; ?>
                </div>

                <div class="order-products-group">
                    <div class="order-products-heading">
                        <div>
                            <h3>Продукція</h3>
                            <p>Додавайте продукти з каталогу та вкажіть кількість.</p>
                        </div>
                        <button class="button button-secondary" id="addOrderItem" type="button">＋ Додати продукт</button>
                    </div>
                    <?php if (isset($errors['items'])): ?><span class="field-error order-items-error"><?= e($errors['items']) ?></span><?php endif; ?>
                    <div class="order-item-rows" id="orderItemRows">
                        <?php foreach ($formItems as $item): ?>
                            <div class="order-item-row">
                                <select name="machinery_id[]" aria-label="Продукт" required>
                                    <option value="">Оберіть продукт</option>
                                    <?php foreach ($products as $product): ?>
                                        <?php $productLabel = $product['name'] . ($product['model'] ? ' · ' . $product['model'] : '') . ($product['price'] !== null ? ' — ' . number_format((float) $product['price'], 2, ',', ' ') . ' ₴' : ' — ціну не вказано'); ?>
                                        <option value="<?= (int) $product['id'] ?>" data-price="<?= e($product['price'] ?? '') ?>" <?= $product['price'] === null ? 'disabled' : '' ?> <?= $item['machinery_id'] === (string) $product['id'] ? 'selected' : '' ?>><?= e($productLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="item_quantity[]" type="number" min="1" max="100000" step="1" required value="<?= e($item['quantity'] ?: '1') ?>" aria-label="Кількість">
                                <button class="icon-button remove-order-item" type="button" aria-label="Видалити продукт із замовлення">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <template id="orderItemTemplate">
                        <div class="order-item-row">
                            <select name="machinery_id[]" aria-label="Продукт" required>
                                <option value="">Оберіть продукт</option>
                                <?php foreach ($products as $product): ?>
                                    <?php $productLabel = $product['name'] . ($product['model'] ? ' · ' . $product['model'] : '') . ($product['price'] !== null ? ' — ' . number_format((float) $product['price'], 2, ',', ' ') . ' ₴' : ' — ціну не вказано'); ?>
                                    <option value="<?= (int) $product['id'] ?>" data-price="<?= e($product['price'] ?? '') ?>" <?= $product['price'] === null ? 'disabled' : '' ?>><?= e($productLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="item_quantity[]" type="number" min="1" max="100000" step="1" value="1" aria-label="Кількість">
                            <button class="icon-button remove-order-item" type="button" aria-label="Видалити продукт із замовлення">×</button>
                        </div>
                    </template>
                    <?php if (!$hasPricedProducts): ?><span class="field-error">Додайте продукт із вказаною ціною у каталозі техніки.</span><?php endif; ?>
                    <div class="order-total-row">
                        <span>Попередня сума</span>
                        <strong id="orderTotal">0,00 ₴</strong>
                    </div>
                    <span class="field-error order-total-error" id="orderTotalError" hidden>Сума перевищує допустиме значення для замовлення.</span>
                </div>

                <div class="form-group order-notes-group">
                    <label for="order_notes">Коментар</label>
                    <textarea id="order_notes" name="notes" rows="3" maxlength="65535" placeholder="Додаткова інформація про замовлення"><?= e($formValues['notes']) ?></textarea>
                    <?php if (isset($errors['notes'])): ?><span class="field-error"><?= e($errors['notes']) ?></span><?php endif; ?>
                </div>
                <div class="client-form-actions">
                    <button class="button button-primary" type="submit" <?= $clients === [] || !$hasPricedProducts || !$pdo instanceof PDO ? 'disabled' : '' ?>>Зберегти замовлення</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel module-panel clients-list-panel order-list-panel">
        <form method="post" action="orders.php" class="client-bulk-form order-bulk-form" id="orderBulkForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_orders">
            <div class="panel-heading client-list-heading">
                <div>
                    <h2>Список замовлень</h2>
                    <p>Усього замовлень: <?= number_format(count($orders), 0, ',', ' ') ?> · Натисніть на рядок, щоб переглянути дані.</p>
                </div>
                <div class="client-bulk-actions">
                    <button class="button button-danger" id="startOrderBulkDelete" type="button" <?= $orders === [] ? 'disabled' : '' ?>>Видалити замовлення</button>
                    <button class="button button-danger" id="orderBulkDeleteButton" type="submit" disabled hidden>Видалити вибрані</button>
                    <button class="button button-secondary" id="cancelOrderBulkDelete" type="button" hidden>Скасувати</button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="client-select-cell"><input type="checkbox" id="selectAllOrders" aria-label="Вибрати всі замовлення"></th>
                            <th>Номер</th>
                            <th>Назва замовлення</th>
                            <th>Клієнт</th>
                            <th>Продукція</th>
                            <th>Створено</th>
                            <th>Завершити до</th>
                            <th>Сума</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders === []): ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <span class="empty-icon">▤</span>
                                    <strong>Замовлень поки немає</strong>
                                    <span>Натисніть «Створити замовлення», щоб додати перше.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                            <?php
                            $statusClass = match ($order['status']) {
                                'в роботі' => 'order-status-progress',
                                'Виконано' => 'order-status-complete',
                                'Закрито' => 'order-status-closed',
                                'Відкладено' => 'order-status-deferred',
                                default => 'order-status-new',
                            };
                            $detailsItems = $orderItems[(int) $order['id']] ?? [];
                            if ($detailsItems === [] && $order['legacy_product_name'] !== null) {
                                $detailsItems[] = [
                                    'machinery_id' => $order['machinery_id'],
                                    'product_name' => trim($order['legacy_product_name'] . ($order['legacy_product_model'] ? ' ' . $order['legacy_product_model'] : '')),
                                    'quantity' => (int) $order['quantity'],
                                    'unit_price' => $order['total_price'],
                                    'line_total' => $order['total_price'],
                                ];
                            }
                            $orderDetailsJson = json_encode(
                                [
                                    'id' => (int) $order['id'],
                                    'order_number' => $order['order_number'],
                                    'order_name' => $order['order_name'],
                                    'client_id' => (int) $order['client_id'],
                                    'client_name' => $order['company_name'],
                                    'order_date' => $order['order_date'],
                                    'due_date' => $order['due_date'],
                                    'status' => $order['status'],
                                    'notes' => $order['notes'],
                                    'total_price' => $order['total_price'],
                                    'items' => array_map(static fn (array $item): array => [
                                        'machinery_id' => $item['machinery_id'] !== null ? (int) $item['machinery_id'] : null,
                                        'product_name' => $item['product_name'],
                                        'quantity' => (int) $item['quantity'],
                                        'unit_price' => $item['unit_price'],
                                        'line_total' => $item['line_total'],
                                    ], $detailsItems),
                                ],
                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                            );
                            ?>
                            <tr class="client-row order-row" tabindex="0" role="button" aria-haspopup="dialog" aria-label="Переглянути замовлення <?= e($order['order_number']) ?>" data-order-details="<?= e($orderDetailsJson) ?>">
                                <td class="client-select-cell"><input class="client-select-checkbox order-select-checkbox" type="checkbox" name="order_ids[]" value="<?= (int) $order['id'] ?>" aria-label="Вибрати замовлення <?= e($order['order_number']) ?>" <?= in_array((int) $order['id'], $bulkSelectedIds, true) ? 'checked' : '' ?>></td>
                                <td class="order-number-cell"><?= e($order['order_number']) ?></td>
                                <td><?= e($order['order_name']) ?></td>
                                <td><?= e($order['company_name']) ?></td>
                                <td class="order-products-cell">
                                    <?php foreach ($detailsItems as $item): ?>
                                        <span><?= e($item['product_name']) ?> × <?= number_format((int) $item['quantity'], 0, ',', ' ') ?></span>
                                    <?php endforeach; ?>
                                    <?php if (empty($orderItems[(int) $order['id']]) && $order['legacy_product_name'] !== null): ?>
                                        <span><?= e(trim($order['legacy_product_name'] . ($order['legacy_product_model'] ? ' ' . $order['legacy_product_model'] : ''))) ?> × <?= number_format((int) $order['quantity'], 0, ',', ' ') ?></span>
                                    <?php elseif (empty($orderItems[(int) $order['id']])): ?>
                                        <span>—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($order['order_date']) ?></td>
                                <td><?= e($order['due_date']) ?></td>
                                <td><?= number_format((float) $order['total_price'], 2, ',', ' ') ?> ₴</td>
                                <td><span class="status-pill <?= e($statusClass) ?>"><?= e($order['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </section>

    <div class="client-details-dialog" id="orderDetailsDialog" hidden
         data-reopen-edit="<?= $reopenOrderEditor ? '1' : '0' ?>"
         data-edit-order-id="<?= $editId !== null ? $editId : '' ?>"
         data-edit-values="<?= e($formValuesJson) ?>"
         data-edit-items="<?= e($editItemsJson) ?>"
         data-view-order-id="<?= $modalViewId !== null ? $modalViewId : '' ?>">
        <section class="client-details-content order-details-content" role="dialog" aria-modal="true" aria-labelledby="orderDetailsTitle" tabindex="-1">
            <div class="client-details-heading">
                <div>
                    <p class="eyebrow">ІНФОРМАЦІЯ ПРО ЗАМОВЛЕННЯ</p>
                    <h2 id="orderDetailsTitle"></h2>
                </div>
                <button class="icon-button order-details-close" type="button" aria-label="Закрити вікно">×</button>
            </div>
            <?php if ($deleteError !== ''): ?>
                <div class="alert alert-error client-modal-error" role="alert"><?= e($deleteError) ?></div>
            <?php endif; ?>
            <dl class="client-details-list" id="orderDetailsList"></dl>
            <div class="order-detail-items" id="orderDetailItems">
                <h3>Продукція</h3>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Продукт</th><th>Кількість</th><th>Ціна</th><th>Сума</th></tr></thead>
                        <tbody id="orderDetailItemsBody"></tbody>
                    </table>
                </div>
            </div>
            <form method="post" action="orders.php" class="client-details-form order-details-form" id="orderDetailsForm" hidden>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="edit_order">
                <input type="hidden" name="edit_id" id="editOrderId" value="">
                <div class="client-details-form-grid">
                    <div class="form-group">
                        <label for="modal_order_name">Назва замовлення <span class="required-mark">*</span></label>
                        <input id="modal_order_name" name="order_name" type="text" maxlength="180" required>
                        <?php if (isset($errors['order_name'])): ?><span class="field-error"><?= e($errors['order_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_order_client">Клієнт <span class="required-mark">*</span></label>
                        <select id="modal_order_client" name="client_id" required>
                            <option value="">Оберіть клієнта</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= (int) $client['id'] ?>"><?= e($client['company_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['client_id'])): ?><span class="field-error"><?= e($errors['client_id']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_order_date">Дата створення</label>
                        <input id="modal_order_date" name="order_date" type="date" readonly>
                    </div>
                    <div class="form-group">
                        <label for="modal_due_date">Запланована дата завершення <span class="required-mark">*</span></label>
                        <input id="modal_due_date" name="due_date" type="date" required>
                        <?php if (isset($errors['due_date'])): ?><span class="field-error"><?= e($errors['due_date']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_order_status">Статус</label>
                        <select id="modal_order_status" name="status">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= e($status) ?>"><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['status'])): ?><span class="field-error"><?= e($errors['status']) ?></span><?php endif; ?>
                    </div>
                    <div class="order-products-group">
                        <div class="order-products-heading">
                            <div>
                                <h3>Продукція</h3>
                                <p>Змініть товари та їхню кількість.</p>
                            </div>
                            <button class="button button-secondary" id="addEditOrderItem" type="button">＋ Додати продукт</button>
                        </div>
                        <?php if (isset($errors['items'])): ?><span class="field-error order-items-error"><?= e($errors['items']) ?></span><?php endif; ?>
                        <div class="order-item-rows" id="editOrderItemRows"></div>
                        <template id="editOrderItemTemplate">
                            <div class="order-item-row">
                                <select name="machinery_id[]" aria-label="Продукт" required>
                                    <option value="">Оберіть продукт</option>
                                    <?php foreach ($products as $product): ?>
                                        <?php $productLabel = $product['name'] . ($product['model'] ? ' · ' . $product['model'] : '') . ($product['price'] !== null ? ' — ' . number_format((float) $product['price'], 2, ',', ' ') . ' ₴' : ' — ціну не вказано'); ?>
                                        <option value="<?= (int) $product['id'] ?>" data-price="<?= e($product['price'] ?? '') ?>" <?= $product['price'] === null ? 'disabled' : '' ?>><?= e($productLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="item_quantity[]" type="number" min="1" max="100000" step="1" value="1" aria-label="Кількість" required>
                                <button class="icon-button remove-order-item" type="button" aria-label="Видалити продукт із замовлення">×</button>
                            </div>
                        </template>
                        <div class="order-total-row">
                            <span>Попередня сума</span>
                            <strong id="editOrderTotal">0,00 ₴</strong>
                        </div>
                        <span class="field-error order-total-error" id="editOrderTotalError" hidden>Сума перевищує допустиме значення для замовлення.</span>
                    </div>
                    <div class="form-group order-notes-group">
                        <label for="modal_order_notes">Коментар</label>
                        <textarea id="modal_order_notes" name="notes" rows="3" maxlength="65535"></textarea>
                        <?php if (isset($errors['notes'])): ?><span class="field-error"><?= e($errors['notes']) ?></span><?php endif; ?>
                    </div>
                </div>
                <?php if ($error !== '' && $deleteError === ''): ?>
                    <div class="alert alert-error client-modal-error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <div class="client-details-actions">
                    <button class="button button-primary" type="submit">Зберегти зміни</button>
                    <button class="button button-secondary order-details-cancel-edit" type="button">Скасувати</button>
                </div>
            </form>
            <div class="client-details-actions" id="orderDetailsActions">
                <button class="button button-primary" id="orderDetailsEdit" type="button">Редагувати дані</button>
                <form method="post" action="orders.php" class="client-delete-form" id="orderDeleteForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_order">
                    <input type="hidden" name="order_id" id="deleteOrderId" value="">
                    <button class="button button-danger" type="submit">Видалити замовлення</button>
                </form>
                <button class="button button-secondary order-details-close" type="button">Закрити</button>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
