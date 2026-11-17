<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Каталог техніки';
$activePage = 'machinery';
$fields = ['name', 'model', 'category', 'price', 'stock_quantity', 'description'];
$formValues = array_fill_keys($fields, '');
$formValues['stock_quantity'] = '0';
$errors = [];
$error = '';
$success = ($_GET['added'] ?? '') === '1';
$products = [];
$productAttributes = [];
$productOrders = [];
$attributes = [];
$showProductForm = false;
$editId = null;
$editAttributes = [];
$reopenProductEditor = false;
$deleteError = '';
$deleteProductId = null;
$bulkSelectedIds = [];
$bulkDeletedCount = null;
$bulkMissingCount = null;
$pdo = null;

try {
    require_once __DIR__ . '/config/database.php';
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Не вдалося підключитися до бази даних. Перевірте налаштування та database.sql.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_products') {
    $requestedIds = $_POST['product_ids'] ?? [];

    if (!is_array($requestedIds)) {
        $error = 'Некоректний список продуктів для видалення.';
    } else {
        foreach ($requestedIds as $requestedId) {
            if (!is_string($requestedId) || !ctype_digit($requestedId) || (int) $requestedId < 1 || (int) $requestedId > 4294967295) {
                $error = 'Список містить некоректний ідентифікатор продукту.';
                break;
            }
            $bulkSelectedIds[] = (int) $requestedId;
        }
        $bulkSelectedIds = array_values(array_unique($bulkSelectedIds));
    }

    if ($error === '' && $bulkSelectedIds === []) {
        $error = 'Виберіть хоча б один продукт для видалення.';
    }
    if ($error === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }
    if ($error === '' && $pdo instanceof PDO) {
        $placeholders = [];
        $parameters = [];
        foreach ($bulkSelectedIds as $index => $id) {
            $placeholders[] = ':product_' . $index;
            $parameters['product_' . $index] = $id;
        }

        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT id FROM machinery WHERE id IN (' . implode(', ', $placeholders) . ') FOR UPDATE'
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
                    'DELETE FROM machinery WHERE id IN (' . implode(', ', $existingPlaceholders) . ')'
                );
                $statement->execute($existingParameters);
                $bulkDeletedCount = $statement->rowCount();
            }

            $pdo->commit();
            header(
                'Location: machinery.php?bulk_deleted=' . $bulkDeletedCount
                . '&bulk_missing=' . $bulkMissingCount
            );
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Не вдалося видалити вибрані продукти. Дані не змінено; спробуйте ще раз.';
        }
    } elseif ($error === '') {
        $error = 'Немає підключення до бази даних. Продукти не видалено.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_product') {
    $requestedDeleteId = $_POST['product_id'] ?? '';
    if (is_string($requestedDeleteId) && ctype_digit($requestedDeleteId) && (int) $requestedDeleteId > 0) {
        $deleteProductId = (int) $requestedDeleteId;
    } else {
        $deleteError = 'Некоректний ідентифікатор продукту.';
    }

    if ($deleteError === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $deleteError = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }
    if ($deleteError === '' && $pdo instanceof PDO) {
        try {
            $statement = $pdo->prepare('DELETE FROM machinery WHERE id = :id');
            $statement->execute(['id' => $deleteProductId]);
            if ($statement->rowCount() === 1) {
                header('Location: machinery.php?deleted=1');
                exit;
            }
            $deleteError = 'Продукт не знайдено. Список уже оновлено.';
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $deleteError = 'Не вдалося видалити продукт. Перевірте пов’язані дані та спробуйте ще раз.';
        }
    } elseif ($deleteError === '') {
        $deleteError = 'Немає підключення до бази даних. Продукт не видалено.';
    }
    $error = $deleteError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['delete_product', 'delete_products'], true)) {
    $action = $_POST['action'] ?? '';
    $showProductForm = $action === 'add_product';
    $postedEditId = $_POST['edit_id'] ?? '';
    if ($action === 'edit_product' && is_string($postedEditId) && ctype_digit($postedEditId) && (int) $postedEditId > 0) {
        $editId = (int) $postedEditId;
    } elseif ($action === 'edit_product') {
        $error = 'Некоректний ідентифікатор продукту.';
    }

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';
        $formValues[$field] = is_string($value) ? trim($value) : '';
    }

    $postedAttributeNames = $_POST['attribute_name'] ?? [];
    $postedAttributeValues = $_POST['attribute_value'] ?? [];
    if (is_array($postedAttributeNames) && is_array($postedAttributeValues)) {
        $attributeCount = max(count($postedAttributeNames), count($postedAttributeValues));
        if ($attributeCount > 30) {
            $errors['attributes'] = 'Можна додати не більше 30 характеристик.';
        }
        for ($index = 0; $index < min($attributeCount, 30); $index++) {
            $name = $postedAttributeNames[$index] ?? '';
            $value = $postedAttributeValues[$index] ?? '';
            $name = is_string($name) ? trim($name) : '';
            $value = is_string($value) ? trim($value) : '';

            if ($name === '' && $value === '') {
                continue;
            }
            $attributes[] = ['name' => $name, 'value' => $value];
            if ($name === '' || $value === '') {
                $errors['attributes'] = 'Заповніть і назву, і значення кожної характеристики.';
            } elseif (mb_strlen($name, 'UTF-8') > 100 || mb_strlen($value, 'UTF-8') > 500) {
                $errors['attributes'] = 'Назва характеристики має бути до 100 символів, а значення — до 500.';
            }
        }
    } elseif (isset($_POST['attribute_name']) || isset($_POST['attribute_value'])) {
        $errors['attributes'] = 'Некоректний список характеристик.';
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    } elseif (!in_array($action, ['add_product', 'edit_product'], true)) {
        $error = 'Невідома дія для каталогу техніки.';
    } elseif ($pdo instanceof PDO) {
        if ($editId !== null) {
            try {
                $statement = $pdo->prepare('SELECT id FROM machinery WHERE id = :id');
                $statement->execute(['id' => $editId]);
                if (!$statement->fetch()) {
                    $error = 'Продукт не знайдено. Оновіть список і спробуйте ще раз.';
                }
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                $error = 'Не вдалося перевірити продукт для редагування.';
            }
        }

        if ($formValues['name'] === '') {
            $errors['name'] = 'Вкажіть назву продукту.';
        }

        foreach (['name' => 180, 'model' => 100, 'category' => 100] as $field => $limit) {
            if (mb_strlen($formValues[$field], 'UTF-8') > $limit) {
                $errors[$field] = 'Перевищено максимально допустиму довжину.';
            }
        }

        if (strlen($formValues['description']) > 65535) {
            $errors['description'] = 'Опис продукту перевищує максимально допустимий розмір.';
        }

        $price = null;
        if ($formValues['price'] !== '') {
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $formValues['price'])) {
                $errors['price'] = 'Вкажіть ціну від 0 до 9 999 999 999,99 з не більш ніж двома знаками після коми.';
            } else {
                $priceParts = explode('.', $formValues['price'], 2);
                $price = $priceParts[0] . '.' . str_pad($priceParts[1] ?? '', 2, '0');
            }
        }

        if ($formValues['stock_quantity'] === '' || !ctype_digit($formValues['stock_quantity']) || (float) $formValues['stock_quantity'] > 4294967295) {
            $errors['stock_quantity'] = 'Вкажіть ціле невід’ємне значення кількості.';
        }

        if ($errors === [] && $error === '') {
            try {
                $pdo->beginTransaction();
                $values = [
                    'name' => $formValues['name'],
                    'model' => $formValues['model'] !== '' ? $formValues['model'] : null,
                    'category' => $formValues['category'] !== '' ? $formValues['category'] : null,
                    'price' => $price,
                    'stock_quantity' => (int) $formValues['stock_quantity'],
                    'description' => $formValues['description'] !== '' ? $formValues['description'] : null,
                ];

                if ($editId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE machinery
                         SET name = :name, model = :model, category = :category, price = :price,
                             stock_quantity = :stock_quantity, description = :description
                         WHERE id = :id'
                    );
                    $statement->execute($values + ['id' => $editId]);
                    $productId = $editId;
                    $pdo->prepare('DELETE FROM machinery_attributes WHERE machinery_id = :id')
                        ->execute(['id' => $productId]);
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO machinery (name, model, category, price, stock_quantity, description)
                         VALUES (:name, :model, :category, :price, :stock_quantity, :description)'
                    );
                    $statement->execute($values);
                    $productId = (int) $pdo->lastInsertId();
                }

                if ($attributes !== []) {
                    $attributeStatement = $pdo->prepare(
                        'INSERT INTO machinery_attributes (machinery_id, attribute_name, attribute_value, sort_order)
                         VALUES (:machinery_id, :attribute_name, :attribute_value, :sort_order)'
                    );
                    foreach ($attributes as $index => $attribute) {
                        $attributeStatement->execute([
                            'machinery_id' => $productId,
                            'attribute_name' => $attribute['name'],
                            'attribute_value' => $attribute['value'],
                            'sort_order' => $index,
                        ]);
                    }
                }

                $pdo->commit();
                header('Location: machinery.php?' . ($editId !== null ? 'updated=1' : 'added=1'));
                exit;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($exception->getMessage());
                $error = $editId !== null
                    ? 'Не вдалося оновити продукт. Перевірте дані та спробуйте ще раз.'
                    : 'Не вдалося зберегти продукт. Перевірте дані та спробуйте ще раз.';
            }
        }
    }
    $editAttributes = $attributes;
}

if ($pdo instanceof PDO) {
    try {
        $products = $pdo->query(
            'SELECT id, name, model, category, price, stock_quantity, description, created_at, updated_at
             FROM machinery
             ORDER BY created_at DESC, id DESC'
        )->fetchAll();

        if ($products !== []) {
            $productIds = array_map(static fn (array $product): int => (int) $product['id'], $products);
            $placeholders = [];
            $parameters = [];
            foreach ($productIds as $index => $id) {
                $placeholders[] = ':product_' . $index;
                $parameters['product_' . $index] = $id;
            }

            $statement = $pdo->prepare(
                'SELECT machinery_id, attribute_name, attribute_value
                 FROM machinery_attributes
                 WHERE machinery_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY sort_order, id'
            );
            $statement->execute($parameters);
            foreach ($statement->fetchAll() as $attribute) {
                $productAttributes[(int) $attribute['machinery_id']][] = [
                    'name' => $attribute['attribute_name'],
                    'value' => $attribute['attribute_value'],
                ];
            }

            $legacyPlaceholders = [];
            $itemPlaceholders = [];
            $orderParameters = [];
            foreach ($productIds as $index => $id) {
                $legacyPlaceholder = ':legacy_product_' . $index;
                $itemPlaceholder = ':item_product_' . $index;
                $legacyPlaceholders[] = $legacyPlaceholder;
                $itemPlaceholders[] = $itemPlaceholder;
                $orderParameters['legacy_product_' . $index] = $id;
                $orderParameters['item_product_' . $index] = $id;
            }
            $statement = $pdo->prepare(
                'SELECT linked_products.product_id, orders.id, orders.order_number, orders.order_name,
                        orders.status, orders.order_date, orders.due_date, orders.total_price,
                        clients.company_name
                 FROM (
                     SELECT machinery_id AS product_id, id AS order_id
                     FROM orders
                     WHERE machinery_id IN (' . implode(', ', $legacyPlaceholders) . ')
                     UNION
                     SELECT machinery_id AS product_id, order_id
                     FROM order_items
                     WHERE machinery_id IN (' . implode(', ', $itemPlaceholders) . ')
                 ) AS linked_products
                 INNER JOIN orders ON orders.id = linked_products.order_id
                 INNER JOIN clients ON clients.id = orders.client_id
                 ORDER BY orders.created_at DESC, orders.id DESC'
            );
            $statement->execute($orderParameters);
            foreach ($statement->fetchAll() as $order) {
                $productOrders[(int) $order['product_id']][] = [
                    'id' => (int) $order['id'],
                    'order_number' => $order['order_number'],
                    'order_name' => $order['order_name'],
                    'status' => $order['status'],
                    'order_date' => $order['order_date'],
                    'due_date' => $order['due_date'],
                    'total_price' => $order['total_price'],
                    'client_name' => $order['company_name'],
                ];
            }
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = 'Не вдалося завантажити каталог. Перевірте структуру бази даних та імпортуйте актуальний database.sql.';
    }
}

$success = ($_GET['added'] ?? '') === '1';
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
$editAttributesJson = json_encode(
    $editAttributes,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$reopenProductEditor = $editId !== null && ($errors !== [] || $error !== '');
$modalViewId = $deleteProductId;
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
        <button class="button button-primary clients-add-link" id="showProductForm" type="button" aria-expanded="<?= $showProductForm ? 'true' : 'false' ?>" aria-controls="add-product">＋ Додати продукт</button>
    </div>

    <?php if ($success): ?>
        <div class="alert-success clients-notice" role="status">Продукт успішно додано до каталогу.</div>
    <?php endif; ?>
    <?php if ($updated): ?>
        <div class="alert-success clients-notice" role="status">Дані продукту успішно оновлено.</div>
    <?php endif; ?>
    <?php if ($deleted): ?>
        <div class="alert-success clients-notice" role="status">Продукт успішно видалено.</div>
    <?php endif; ?>
    <?php if ($bulkDeletedCount !== null): ?>
        <div class="alert-success clients-notice" role="status">
            Видалено продуктів: <?= number_format($bulkDeletedCount, 0, ',', ' ') ?>.
            <?php if ($bulkMissingCount > 0): ?>
                Не знайдено: <?= number_format($bulkMissingCount, 0, ',', ' ') ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error clients-notice" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="panel client-form-panel product-form-panel" id="add-product" <?= $showProductForm ? '' : 'hidden' ?>>
        <div class="panel-heading">
            <div>
                <h2>Новий продукт</h2>
                <p>Вкажіть основні дані та додайте характеристики продукту.</p>
            </div>
            <button class="button button-secondary client-form-close" id="hideProductForm" type="button">Закрити</button>
        </div>

        <form method="post" action="machinery.php" class="client-form product-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="add_product">
            <div class="client-form-grid">
                <div class="form-group">
                    <label for="product_name">Назва продукту <span class="required-mark">*</span></label>
                    <input id="product_name" name="name" type="text" maxlength="180" required value="<?= e($formValues['name']) ?>" placeholder="Наприклад, трактор Agro 200">
                    <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="product_model">Модель</label>
                    <input id="product_model" name="model" type="text" maxlength="100" value="<?= e($formValues['model']) ?>" placeholder="Модель продукту">
                    <?php if (isset($errors['model'])): ?><span class="field-error"><?= e($errors['model']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="product_category">Категорія</label>
                    <input id="product_category" name="category" type="text" maxlength="100" value="<?= e($formValues['category']) ?>" placeholder="Наприклад, трактори">
                    <?php if (isset($errors['category'])): ?><span class="field-error"><?= e($errors['category']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="product_price">Ціна</label>
                    <input id="product_price" name="price" type="number" min="0" max="9999999999.99" step="0.01" value="<?= e($formValues['price']) ?>" placeholder="0.00">
                    <?php if (isset($errors['price'])): ?><span class="field-error"><?= e($errors['price']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="product_quantity">Кількість на складі</label>
                    <input id="product_quantity" name="stock_quantity" type="number" min="0" max="4294967295" step="1" required value="<?= e($formValues['stock_quantity']) ?>">
                    <?php if (isset($errors['stock_quantity'])): ?><span class="field-error"><?= e($errors['stock_quantity']) ?></span><?php endif; ?>
                </div>
                <div class="form-group product-description-group">
                    <label for="product_description">Опис</label>
                    <textarea id="product_description" name="description" rows="3" maxlength="65535" placeholder="Короткий опис продукту"><?= e($formValues['description']) ?></textarea>
                    <?php if (isset($errors['description'])): ?><span class="field-error"><?= e($errors['description']) ?></span><?php endif; ?>
                </div>

                <div class="product-attributes-group">
                    <div class="product-attributes-heading">
                        <div>
                            <h3>Характеристики</h3>
                            <p>Додавайте власні пари «назва — значення».</p>
                        </div>
                        <button class="button button-secondary product-add-attribute" id="addProductAttribute" type="button">＋ Додати характеристику</button>
                    </div>
                    <?php if (isset($errors['attributes'])): ?><span class="field-error product-attributes-error"><?= e($errors['attributes']) ?></span><?php endif; ?>
                    <div class="product-attribute-rows" id="productAttributeRows">
                        <?php foreach ($attributes as $attribute): ?>
                            <div class="product-attribute-row">
                                <input name="attribute_name[]" type="text" maxlength="100" value="<?= e($attribute['name']) ?>" aria-label="Назва характеристики" placeholder="Назва, наприклад: Потужність">
                                <input name="attribute_value[]" type="text" maxlength="500" value="<?= e($attribute['value']) ?>" aria-label="Значення характеристики" placeholder="Значення, наприклад: 200 к.с.">
                                <button class="icon-button remove-product-attribute" type="button" aria-label="Видалити характеристику">×</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <template id="productAttributeTemplate">
                        <div class="product-attribute-row">
                            <input name="attribute_name[]" type="text" maxlength="100" aria-label="Назва характеристики" placeholder="Назва, наприклад: Потужність">
                            <input name="attribute_value[]" type="text" maxlength="500" aria-label="Значення характеристики" placeholder="Значення, наприклад: 200 к.с.">
                            <button class="icon-button remove-product-attribute" type="button" aria-label="Видалити характеристику">×</button>
                        </div>
                    </template>
                </div>

                <div class="client-form-actions">
                    <button class="button button-primary" type="submit" <?= $pdo instanceof PDO ? '' : 'disabled' ?>>Зберегти продукт</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel module-panel clients-list-panel product-list-panel">
        <form method="post" action="machinery.php" class="client-bulk-form product-bulk-form" id="productBulkForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_products">
            <div class="panel-heading client-list-heading">
                <div>
                    <h2>Каталог продукції</h2>
                    <p>Усього продуктів: <?= number_format(count($products), 0, ',', ' ') ?> · Натисніть на рядок, щоб переглянути продукт.</p>
                </div>
                <div class="client-bulk-actions">
                    <button class="button button-danger" id="startProductBulkDelete" type="button" <?= $products === [] ? 'disabled' : '' ?>>Видалити продукти</button>
                    <button class="button button-danger" id="productBulkDeleteButton" type="submit" disabled hidden>Видалити вибрані</button>
                    <button class="button button-secondary" id="cancelProductBulkDelete" type="button" hidden>Скасувати</button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="client-select-cell"><input type="checkbox" id="selectAllProducts" aria-label="Вибрати всі продукти"></th>
                            <th>Назва</th>
                            <th>Модель</th>
                            <th>Категорія</th>
                            <th>Ціна</th>
                            <th>Кількість</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($products === []): ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <span class="empty-icon">⚙</span>
                                    <strong>У каталозі поки немає продуктів</strong>
                                    <span>Натисніть «Додати продукт», щоб створити перший запис.</span>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                            <?php
                            $productDetails = [
                                'id' => (int) $product['id'],
                                'name' => $product['name'],
                                'model' => $product['model'],
                                'category' => $product['category'],
                                'price' => $product['price'],
                                'stock_quantity' => (int) $product['stock_quantity'],
                                'description' => $product['description'],
                                'created_at' => $product['created_at'],
                                'updated_at' => $product['updated_at'],
                                'attributes' => $productAttributes[(int) $product['id']] ?? [],
                                'orders' => $productOrders[(int) $product['id']] ?? [],
                            ];
                            $productDetailsJson = json_encode(
                                $productDetails,
                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                            );
                            ?>
                            <tr class="client-row product-row" tabindex="0" role="button" aria-haspopup="dialog" aria-label="Переглянути продукт: <?= e($product['name']) ?>" data-product-details="<?= e($productDetailsJson) ?>">
                                <td class="client-select-cell"><input class="client-select-checkbox product-select-checkbox" type="checkbox" name="product_ids[]" value="<?= (int) $product['id'] ?>" aria-label="Вибрати продукт: <?= e($product['name']) ?>" <?= in_array((int) $product['id'], $bulkSelectedIds, true) ? 'checked' : '' ?>></td>
                                <td class="client-company-cell"><?= e($product['name']) ?></td>
                                <td><?= e($product['model'] ?: '—') ?></td>
                                <td><?= e($product['category'] ?: '—') ?></td>
                                <td><?= $product['price'] !== null ? number_format((float) $product['price'], 2, ',', ' ') . ' ₴' : '—' ?></td>
                                <td><?= number_format((int) $product['stock_quantity'], 0, ',', ' ') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </section>

    <div class="client-details-dialog" id="productDetailsDialog" hidden
         data-reopen-edit="<?= $reopenProductEditor ? '1' : '0' ?>"
         data-edit-product-id="<?= $editId !== null ? $editId : '' ?>"
         data-edit-values="<?= e($formValuesJson) ?>"
         data-edit-attributes="<?= e($editAttributesJson) ?>"
         data-view-product-id="<?= $modalViewId !== null ? $modalViewId : '' ?>">
        <section class="client-details-content product-details-content" role="dialog" aria-modal="true" aria-labelledby="productDetailsTitle" tabindex="-1">
            <div class="client-details-heading">
                <div>
                    <p class="eyebrow">КАТАЛОГ ТЕХНІКИ</p>
                    <h2 id="productDetailsTitle"></h2>
                </div>
                <button class="icon-button product-details-close" type="button" aria-label="Закрити вікно">×</button>
            </div>
            <?php if ($deleteError !== ''): ?>
                <div class="alert alert-error client-modal-error" role="alert"><?= e($deleteError) ?></div>
            <?php endif; ?>
            <dl class="client-details-list" id="productDetailsList"></dl>
            <div class="product-detail-attributes" id="productDetailAttributes" hidden>
                <h3>Характеристики</h3>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Назва</th><th>Значення</th></tr></thead>
                        <tbody id="productDetailAttributesBody"></tbody>
                    </table>
                </div>
            </div>
            <section class="client-related-records product-related-orders" id="productRelatedOrders" aria-labelledby="productRelatedOrdersTitle">
                <h3 id="productRelatedOrdersTitle">Замовлення з цим продуктом</h3>
                <ul class="client-related-list" id="productRelatedOrdersList"></ul>
            </section>
            <form method="post" action="machinery.php" class="client-details-form product-details-form" id="productDetailsForm" hidden>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="edit_id" id="editProductId" value="">
                <div class="client-details-form-grid">
                    <div class="form-group">
                        <label for="modal_product_name">Назва продукту <span class="required-mark">*</span></label>
                        <input id="modal_product_name" name="name" type="text" maxlength="180" required>
                        <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_product_model">Модель</label>
                        <input id="modal_product_model" name="model" type="text" maxlength="100">
                        <?php if (isset($errors['model'])): ?><span class="field-error"><?= e($errors['model']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_product_category">Категорія</label>
                        <input id="modal_product_category" name="category" type="text" maxlength="100">
                        <?php if (isset($errors['category'])): ?><span class="field-error"><?= e($errors['category']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_product_price">Ціна</label>
                        <input id="modal_product_price" name="price" type="number" min="0" max="9999999999.99" step="0.01">
                        <?php if (isset($errors['price'])): ?><span class="field-error"><?= e($errors['price']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="modal_product_quantity">Кількість на складі</label>
                        <input id="modal_product_quantity" name="stock_quantity" type="number" min="0" max="4294967295" step="1" required>
                        <?php if (isset($errors['stock_quantity'])): ?><span class="field-error"><?= e($errors['stock_quantity']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group product-description-group">
                        <label for="modal_product_description">Опис</label>
                        <textarea id="modal_product_description" name="description" rows="3" maxlength="65535"></textarea>
                        <?php if (isset($errors['description'])): ?><span class="field-error"><?= e($errors['description']) ?></span><?php endif; ?>
                    </div>
                    <div class="product-attributes-group">
                        <div class="product-attributes-heading">
                            <div>
                                <h3>Характеристики</h3>
                                <p>Додавайте власні пари «назва — значення».</p>
                            </div>
                            <button class="button button-secondary product-add-attribute" id="addEditProductAttribute" type="button">＋ Додати характеристику</button>
                        </div>
                        <?php if (isset($errors['attributes'])): ?><span class="field-error product-attributes-error"><?= e($errors['attributes']) ?></span><?php endif; ?>
                        <div class="product-attribute-rows" id="editProductAttributeRows"></div>
                        <template id="editProductAttributeTemplate">
                            <div class="product-attribute-row">
                                <input name="attribute_name[]" type="text" maxlength="100" aria-label="Назва характеристики" placeholder="Назва, наприклад: Потужність">
                                <input name="attribute_value[]" type="text" maxlength="500" aria-label="Значення характеристики" placeholder="Значення, наприклад: 200 к.с.">
                                <button class="icon-button remove-product-attribute" type="button" aria-label="Видалити характеристику">×</button>
                            </div>
                        </template>
                    </div>
                </div>
                <?php if ($error !== '' && $deleteError === ''): ?>
                    <div class="alert alert-error client-modal-error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <div class="client-details-actions">
                    <button class="button button-primary" type="submit">Зберегти зміни</button>
                    <button class="button button-secondary product-details-cancel-edit" type="button">Скасувати</button>
                </div>
            </form>
            <div class="client-details-actions" id="productDetailsActions">
                <button class="button button-primary" id="productDetailsEdit" type="button">Редагувати дані</button>
                <form method="post" action="machinery.php" class="client-delete-form" id="productDeleteForm">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="product_id" id="deleteProductId" value="">
                    <button class="button button-danger" type="submit">Видалити продукт</button>
                </form>
                <button class="button button-secondary product-details-close" type="button">Закрити</button>
            </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
