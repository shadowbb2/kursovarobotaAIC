<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Звернення';
$activePage = 'inquiries';
$statuses = ['Нове', 'В роботі', 'Оброблено'];
$sources = ['Телефон', 'Електронна пошта', 'Сайт', 'Соціальні мережі', 'Особисто', 'Інше'];
$formValues = [
    'contact_type' => 'client',
    'client_id' => '',
    'requester_name' => '',
    'requester_email' => '',
    'requester_phone' => '',
    'subject' => '',
    'short_description' => '',
    'message' => '',
    'source' => '',
    'response_due_date' => '',
    'status' => 'Нове',
];
$errors = [];
$error = '';
$success = ($_GET['added'] ?? '') === '1';
$updated = ($_GET['updated'] ?? '') === '1';
$deleted = ($_GET['deleted'] ?? '') === '1';
$clients = [];
$inquiries = [];
$showInquiryForm = false;
$editId = null;
$deleteError = '';
$deleteInquiryId = null;
$bulkSelectedIds = [];
$bulkDeletedCount = null;
$bulkMissingCount = null;
$clientConversionFields = ['company_name', 'contact_person', 'phone', 'email', 'city', 'address', 'notes'];
$clientConversionValues = array_fill_keys($clientConversionFields, '');
$clientConversionErrors = [];
$clientConversionError = '';
$conversionInquiryId = null;
$showClientConversionForm = false;
$converted = ($_GET['converted'] ?? '') === '1';
$pdo = null;

try {
    require_once __DIR__ . '/config/database.php';
    $clients = $pdo->query(
        'SELECT id, company_name
         FROM clients
         ORDER BY company_name, id'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $error = 'Не вдалося завантажити клієнтів або підключитися до бази даних.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert_inquiry_to_client') {
    $showClientConversionForm = true;
    $requestedConversionId = $_POST['inquiry_id'] ?? '';
    if (is_string($requestedConversionId) && ctype_digit($requestedConversionId) && (int) $requestedConversionId > 0) {
        $conversionInquiryId = (int) $requestedConversionId;
    } else {
        $clientConversionError = 'Некоректний ідентифікатор звернення.';
    }

    foreach ($clientConversionFields as $field) {
        $value = $_POST[$field] ?? '';
        $clientConversionValues[$field] = is_string($value) ? trim($value) : '';
    }

    if ($clientConversionError === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $clientConversionError = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }
    if ($clientConversionValues['company_name'] === '') {
        $clientConversionErrors['company_name'] = 'Вкажіть назву компанії або ПІБ клієнта.';
    }
    foreach ([
        'company_name' => 180,
        'contact_person' => 150,
        'phone' => 40,
        'email' => 190,
        'city' => 100,
        'address' => 255,
    ] as $field => $limit) {
        if (mb_strlen($clientConversionValues[$field], 'UTF-8') > $limit) {
            $clientConversionErrors[$field] = 'Перевищено максимально допустиму довжину.';
        }
    }
    if ($clientConversionValues['email'] !== '' && !filter_var($clientConversionValues['email'], FILTER_VALIDATE_EMAIL)) {
        $clientConversionErrors['email'] = 'Вкажіть коректну електронну пошту.';
    }
    if (strlen($clientConversionValues['notes']) > 65535) {
        $clientConversionErrors['notes'] = 'Нотатки перевищують допустимий розмір.';
    }

    if ($clientConversionError === '' && $clientConversionErrors === [] && $pdo instanceof PDO) {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT client_id FROM inquiries WHERE id = :id FOR UPDATE'
            );
            $statement->execute(['id' => $conversionInquiryId]);
            $inquiryToConvert = $statement->fetch();
            if (!$inquiryToConvert) {
                $clientConversionError = 'Звернення не знайдено. Оновіть список і спробуйте ще раз.';
            } elseif ($inquiryToConvert['client_id'] !== null) {
                $clientConversionError = 'Це звернення вже пов’язане з клієнтом.';
                $showClientConversionForm = false;
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO clients
                        (company_name, contact_person, phone, email, city, address, notes, created_by)
                     VALUES
                        (:company_name, :contact_person, :phone, :email, :city, :address, :notes, :created_by)'
                );
                $statement->execute([
                    'company_name' => $clientConversionValues['company_name'],
                    'contact_person' => $clientConversionValues['contact_person'] !== '' ? $clientConversionValues['contact_person'] : null,
                    'phone' => $clientConversionValues['phone'] !== '' ? $clientConversionValues['phone'] : null,
                    'email' => $clientConversionValues['email'] !== '' ? $clientConversionValues['email'] : null,
                    'city' => $clientConversionValues['city'] !== '' ? $clientConversionValues['city'] : null,
                    'address' => $clientConversionValues['address'] !== '' ? $clientConversionValues['address'] : null,
                    'notes' => $clientConversionValues['notes'] !== '' ? $clientConversionValues['notes'] : null,
                    'created_by' => currentUser()['id'] ?? null,
                ]);
                $newClientId = (int) $pdo->lastInsertId();
                $pdo->prepare('UPDATE inquiries SET client_id = :client_id WHERE id = :id')
                    ->execute(['client_id' => $newClientId, 'id' => $conversionInquiryId]);
                $pdo->commit();
                header('Location: inquiries.php?converted=1');
                exit;
            }
            $pdo->rollBack();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $clientConversionError = 'Не вдалося створити клієнта та пов’язати звернення. Дані не змінено; спробуйте ще раз.';
        }
    } elseif ($clientConversionError === '' && !$pdo instanceof PDO) {
        $clientConversionError = 'Немає підключення до бази даних. Клієнта не створено.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_inquiries') {
    $requestedIds = $_POST['inquiry_ids'] ?? [];
    if (!is_array($requestedIds)) {
        $error = 'Некоректний список звернень для видалення.';
    } else {
        foreach ($requestedIds as $requestedId) {
            if (!is_string($requestedId) || !ctype_digit($requestedId) || (int) $requestedId < 1 || (int) $requestedId > 4294967295) {
                $error = 'Список містить некоректний ідентифікатор звернення.';
                break;
            }
            $bulkSelectedIds[] = (int) $requestedId;
        }
        $bulkSelectedIds = array_values(array_unique($bulkSelectedIds));
    }

    if ($error === '' && $bulkSelectedIds === []) {
        $error = 'Виберіть хоча б одне звернення для видалення.';
    }
    if ($error === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }
    if ($error === '' && $pdo instanceof PDO) {
        $placeholders = [];
        $parameters = [];
        foreach ($bulkSelectedIds as $index => $id) {
            $placeholders[] = ':inquiry_' . $index;
            $parameters['inquiry_' . $index] = $id;
        }
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT id FROM inquiries WHERE id IN (' . implode(', ', $placeholders) . ') FOR UPDATE'
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
                    'DELETE FROM inquiries WHERE id IN (' . implode(', ', $existingPlaceholders) . ')'
                );
                $statement->execute($existingParameters);
                $bulkDeletedCount = $statement->rowCount();
            }
            $pdo->commit();
            header(
                'Location: inquiries.php?bulk_deleted=' . $bulkDeletedCount
                . '&bulk_missing=' . $bulkMissingCount
            );
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Не вдалося видалити вибрані звернення. Дані не змінено; спробуйте ще раз.';
        }
    } elseif ($error === '') {
        $error = 'Немає підключення до бази даних. Звернення не видалено.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_inquiry') {
    $requestedDeleteId = $_POST['inquiry_id'] ?? '';
    if (is_string($requestedDeleteId) && ctype_digit($requestedDeleteId) && (int) $requestedDeleteId > 0) {
        $deleteInquiryId = (int) $requestedDeleteId;
    } else {
        $deleteError = 'Некоректний ідентифікатор звернення.';
    }
    if ($deleteError === '' && !verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $deleteError = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    }
    if ($deleteError === '' && $pdo instanceof PDO) {
        try {
            $statement = $pdo->prepare('DELETE FROM inquiries WHERE id = :id');
            $statement->execute(['id' => $deleteInquiryId]);
            if ($statement->rowCount() === 1) {
                header('Location: inquiries.php?deleted=1');
                exit;
            }
            $deleteError = 'Звернення не знайдено. Список уже оновлено.';
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $deleteError = 'Не вдалося видалити звернення. Спробуйте ще раз.';
        }
    } elseif ($deleteError === '') {
        $deleteError = 'Немає підключення до бази даних. Звернення не видалено.';
    }
    $error = $deleteError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($_POST['action'] ?? '', ['delete_inquiry', 'delete_inquiries', 'convert_inquiry_to_client'], true)) {
    $action = $_POST['action'] ?? '';
    $showInquiryForm = $action === 'create_inquiry';
    $postedEditId = $_POST['edit_id'] ?? '';
    if ($action === 'edit_inquiry' && is_string($postedEditId) && ctype_digit($postedEditId) && (int) $postedEditId > 0) {
        $editId = (int) $postedEditId;
    } elseif ($action === 'edit_inquiry') {
        $error = 'Некоректний ідентифікатор звернення.';
    }
    foreach (array_keys($formValues) as $field) {
        $value = $_POST[$field] ?? '';
        $formValues[$field] = is_string($value) ? trim($value) : '';
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    } elseif (!$pdo instanceof PDO && $error === '') {
        $error = 'Немає підключення до бази даних. Звернення не збережено.';
    } elseif (!in_array($action, ['create_inquiry', 'edit_inquiry'], true)) {
        $error = 'Невідома дія для звернень.';
    }

    if (!in_array($formValues['contact_type'], ['client', 'new'], true)) {
        $errors['contact_type'] = 'Оберіть тип автора звернення.';
    }

    $clientId = null;
    if ($formValues['contact_type'] === 'client') {
        if ($formValues['client_id'] === '' || !ctype_digit($formValues['client_id']) || (int) $formValues['client_id'] < 1) {
            $errors['client_id'] = 'Оберіть клієнта зі списку.';
        } else {
            $clientId = (int) $formValues['client_id'];
        }
    } elseif ($formValues['requester_name'] === '') {
        $errors['requester_name'] = 'Вкажіть ім’я нового контакту.';
    }

    if ($formValues['contact_type'] === 'new') {
        foreach (['requester_name' => 150, 'requester_email' => 190, 'requester_phone' => 40] as $field => $limit) {
            if (mb_strlen($formValues[$field], 'UTF-8') > $limit) {
                $errors[$field] = 'Перевищено максимально допустиму довжину.';
            }
        }
        if ($formValues['requester_email'] !== '' && !filter_var($formValues['requester_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['requester_email'] = 'Вкажіть коректну електронну пошту.';
        }
    }

    if ($formValues['subject'] === '') {
        $errors['subject'] = 'Вкажіть тему звернення.';
    } elseif (mb_strlen($formValues['subject'], 'UTF-8') > 180) {
        $errors['subject'] = 'Тема має бути не довша за 180 символів.';
    }
    if ($formValues['short_description'] === '') {
        $errors['short_description'] = 'Додайте короткий опис звернення.';
    } elseif (mb_strlen($formValues['short_description'], 'UTF-8') > 500) {
        $errors['short_description'] = 'Короткий опис має бути не довший за 500 символів.';
    }
    if ($formValues['message'] === '') {
        $errors['message'] = 'Вкажіть текст запитання клієнта.';
    } elseif (strlen($formValues['message']) > 65535) {
        $errors['message'] = 'Текст звернення перевищує допустимий розмір.';
    }
    if (!in_array($formValues['source'], $sources, true)) {
        $errors['source'] = 'Оберіть джерело звернення.';
    }
    if (!in_array($formValues['status'], $statuses, true)) {
        $errors['status'] = 'Оберіть статус звернення.';
    }

    if ($formValues['response_due_date'] === '') {
        $errors['response_due_date'] = 'Оберіть крайній термін відповіді.';
    } else {
        $responseDueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formValues['response_due_date']);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$responseDueDate
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $responseDueDate->format('Y-m-d') !== $formValues['response_due_date']) {
            $errors['response_due_date'] = 'Вкажіть коректну дату відповіді.';
        } elseif ($editId === null && $formValues['response_due_date'] < date('Y-m-d')) {
            $errors['response_due_date'] = 'Крайній термін відповіді не може бути в минулому.';
        }
    }

    if ($errors === [] && $error === '' && $pdo instanceof PDO) {
        try {
            $pdo->beginTransaction();
            if ($editId !== null) {
                $inquiryStatement = $pdo->prepare('SELECT id FROM inquiries WHERE id = :id FOR UPDATE');
                $inquiryStatement->execute(['id' => $editId]);
                if (!$inquiryStatement->fetch()) {
                    $error = 'Звернення не знайдено. Оновіть список і спробуйте ще раз.';
                }
            }
            if ($clientId !== null) {
                $clientStatement = $pdo->prepare('SELECT id FROM clients WHERE id = :id FOR UPDATE');
                $clientStatement->execute(['id' => $clientId]);
                if (!$clientStatement->fetch()) {
                    $errors['client_id'] = 'Клієнта не знайдено. Оновіть сторінку та оберіть іншого.';
                }
            }

            if ($errors !== []) {
                $pdo->rollBack();
            } elseif ($error !== '') {
                $pdo->rollBack();
            } else {
                $requesterName = $formValues['contact_type'] === 'new' ? $formValues['requester_name'] : null;
                $requesterEmail = $formValues['contact_type'] === 'new' && $formValues['requester_email'] !== ''
                    ? $formValues['requester_email']
                    : null;
                $requesterPhone = $formValues['contact_type'] === 'new' && $formValues['requester_phone'] !== ''
                    ? $formValues['requester_phone']
                    : null;
                $parameters = [
                    'client_id' => $clientId,
                    'subject' => $formValues['subject'],
                    'short_description' => $formValues['short_description'],
                    'message' => $formValues['message'],
                    'requester_name' => $requesterName,
                    'requester_email' => $requesterEmail,
                    'requester_phone' => $requesterPhone,
                    'source' => $formValues['source'],
                    'response_due_date' => $formValues['response_due_date'],
                    'status' => $formValues['status'],
                ];
                if ($editId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE inquiries
                         SET client_id = :client_id, subject = :subject, short_description = :short_description,
                             message = :message, requester_name = :requester_name, requester_email = :requester_email,
                             requester_phone = :requester_phone, source = :source, response_due_date = :response_due_date,
                             status = :status
                         WHERE id = :id'
                    );
                    $parameters['id'] = $editId;
                    $statement->execute($parameters);
                } else {
                    $temporaryNumber = 'TMP-' . bin2hex(random_bytes(16));
                    $statement = $pdo->prepare(
                        'INSERT INTO inquiries
                            (inquiry_number, client_id, subject, short_description, message,
                             requester_name, requester_email, requester_phone, source,
                             response_due_date, status, created_by)
                         VALUES
                            (:inquiry_number, :client_id, :subject, :short_description, :message,
                             :requester_name, :requester_email, :requester_phone, :source,
                             :response_due_date, :status, :created_by)'
                    );
                    $statement->execute($parameters + [
                        'inquiry_number' => $temporaryNumber,
                        'created_by' => currentUser()['id'] ?? null,
                    ]);
                    $inquiryId = (int) $pdo->lastInsertId();
                    $inquiryNumber = 'INQ-' . date('Y') . '-' . str_pad((string) $inquiryId, 6, '0', STR_PAD_LEFT);
                    $pdo->prepare('UPDATE inquiries SET inquiry_number = :number WHERE id = :id')
                        ->execute(['number' => $inquiryNumber, 'id' => $inquiryId]);
                }
                $pdo->commit();
                header('Location: inquiries.php?' . ($editId !== null ? 'updated=1' : 'added=1'));
                exit;
            }
        } catch (PDOException | Random\RandomException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            $error = 'Не вдалося зберегти звернення. Дані не змінено; спробуйте ще раз.';
        }
    }
}

if ($pdo instanceof PDO) {
    try {
        $inquiries = $pdo->query(
            'SELECT inquiries.id, inquiries.inquiry_number, inquiries.client_id, inquiries.subject,
                    inquiries.short_description, inquiries.requester_name,
                    inquiries.requester_email, inquiries.requester_phone, inquiries.source,
                    inquiries.message, inquiries.response_due_date, inquiries.status, inquiries.created_at,
                    inquiries.updated_at, inquiries.created_by, users.full_name AS created_by_name,
                    clients.company_name, clients.contact_person AS client_contact_person,
                    clients.phone AS client_phone, clients.email AS client_email
             FROM inquiries
             LEFT JOIN clients ON clients.id = inquiries.client_id
             LEFT JOIN users ON users.id = inquiries.created_by
             ORDER BY inquiries.created_at DESC, inquiries.id DESC'
        )->fetchAll();
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = 'Не вдалося завантажити звернення. Перевірте, чи застосована міграція бази даних.';
    }
}

$bulkDeletedCount = isset($_GET['bulk_deleted']) && ctype_digit((string) $_GET['bulk_deleted'])
    ? (int) $_GET['bulk_deleted']
    : $bulkDeletedCount;
$bulkMissingCount = isset($_GET['bulk_missing']) && ctype_digit((string) $_GET['bulk_missing'])
    ? (int) $_GET['bulk_missing']
    : $bulkMissingCount;
$reopenInquiryEditor = $_SERVER['REQUEST_METHOD'] === 'POST'
    && $editId !== null
    && ($errors !== [] || $error !== '');
$inquiryFormValuesJson = json_encode(
    $formValues,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$modalViewId = isset($_GET['view']) && is_string($_GET['view']) && ctype_digit($_GET['view'])
    ? (int) $_GET['view']
    : ($conversionInquiryId ?? $deleteInquiryId ?? $editId);

require __DIR__ . '/includes/header.php';
?>
<main class="page-content">
    <div class="page-heading clients-page-heading">
        <div>
            <h1><?= e($pageTitle) ?></h1>
        </div>
        <button class="button button-primary clients-add-link" id="showInquiryForm" type="button" aria-expanded="<?= $showInquiryForm ? 'true' : 'false' ?>" aria-controls="add-inquiry">＋ Додати звернення</button>
    </div>

    <?php if ($success): ?>
        <div class="alert-success clients-notice" role="status">Звернення успішно додано.</div>
    <?php endif; ?>
    <?php if ($updated): ?>
        <div class="alert-success clients-notice" role="status">Звернення успішно оновлено.</div>
    <?php endif; ?>
    <?php if ($deleted): ?>
        <div class="alert-success clients-notice" role="status">Звернення успішно видалено.</div>
    <?php endif; ?>
    <?php if ($converted): ?>
        <div class="alert-success clients-notice" role="status">Клієнта створено та пов’язано зі зверненням.</div>
    <?php endif; ?>
    <?php if ($bulkDeletedCount !== null): ?>
        <div class="alert-success clients-notice" role="status">
            Видалено звернень: <?= number_format($bulkDeletedCount, 0, ',', ' ') ?>.
            <?php if ($bulkMissingCount > 0): ?>
                Не знайдено: <?= number_format($bulkMissingCount, 0, ',', ' ') ?>.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-error clients-notice" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($clientConversionError !== ''): ?>
        <div class="alert alert-error clients-notice" role="alert"><?= e($clientConversionError) ?></div>
    <?php endif; ?>

    <section class="panel client-form-panel inquiry-form-panel" id="add-inquiry" <?= $showInquiryForm ? '' : 'hidden' ?>>
        <div class="panel-heading">
            <div>
                <h2>Нове звернення</h2>
                <p>Номер і дата створення будуть додані автоматично.</p>
            </div>
            <button class="button button-secondary client-form-close" id="hideInquiryForm" type="button">Закрити</button>
        </div>

        <form method="post" action="inquiries.php" class="client-form" id="inquiryForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create_inquiry">
            <div class="client-form-grid">
                <fieldset class="inquiry-contact-type">
                    <legend>Хто звернувся?</legend>
                    <label>
                        <input type="radio" name="contact_type" value="client" <?= $formValues['contact_type'] === 'client' ? 'checked' : '' ?>>
                        Існуючий клієнт
                    </label>
                    <label>
                        <input type="radio" name="contact_type" value="new" <?= $formValues['contact_type'] === 'new' ? 'checked' : '' ?>>
                        Новий контакт
                    </label>
                    <?php if (isset($errors['contact_type'])): ?><span class="field-error"><?= e($errors['contact_type']) ?></span><?php endif; ?>
                </fieldset>

                <div class="form-group inquiry-client-field" id="inquiryClientField" <?= $formValues['contact_type'] === 'client' ? '' : 'hidden' ?>>
                    <label for="inquiry_client">Клієнт <span class="required-mark">*</span></label>
                    <select id="inquiry_client" name="client_id" <?= $formValues['contact_type'] === 'client' ? 'required' : 'disabled' ?>>
                        <option value="">Оберіть клієнта</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>" <?= $formValues['client_id'] === (string) $client['id'] ? 'selected' : '' ?>><?= e($client['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($clients === []): ?><span class="form-hint">У списку поки немає клієнтів. Нове звернення можна зареєструвати як контакт.</span><?php endif; ?>
                    <?php if (isset($errors['client_id'])): ?><span class="field-error"><?= e($errors['client_id']) ?></span><?php endif; ?>
                </div>

                <div class="form-group inquiry-new-contact-field" id="inquiryNewContactField" <?= $formValues['contact_type'] === 'new' ? '' : 'hidden' ?>>
                    <label for="requester_name">Ім’я контакту <span class="required-mark">*</span></label>
                    <input id="requester_name" name="requester_name" type="text" maxlength="150" value="<?= e($formValues['requester_name']) ?>" <?= $formValues['contact_type'] === 'new' ? 'required' : 'disabled' ?> placeholder="Ім’я та прізвище">
                    <?php if (isset($errors['requester_name'])): ?><span class="field-error"><?= e($errors['requester_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-new-contact-field" <?= $formValues['contact_type'] === 'new' ? '' : 'hidden' ?>>
                    <label for="requester_email">Електронна пошта контакту</label>
                    <input id="requester_email" name="requester_email" type="email" maxlength="190" value="<?= e($formValues['requester_email']) ?>" <?= $formValues['contact_type'] === 'new' ? '' : 'disabled' ?> placeholder="name@example.com">
                    <?php if (isset($errors['requester_email'])): ?><span class="field-error"><?= e($errors['requester_email']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-new-contact-field" <?= $formValues['contact_type'] === 'new' ? '' : 'hidden' ?>>
                    <label for="requester_phone">Телефон контакту</label>
                    <input id="requester_phone" name="requester_phone" type="tel" maxlength="40" value="<?= e($formValues['requester_phone']) ?>" <?= $formValues['contact_type'] === 'new' ? '' : 'disabled' ?> placeholder="+380 00 000 00 00">
                    <?php if (isset($errors['requester_phone'])): ?><span class="field-error"><?= e($errors['requester_phone']) ?></span><?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="inquiry_subject">Тема звернення <span class="required-mark">*</span></label>
                    <input id="inquiry_subject" name="subject" type="text" maxlength="180" required value="<?= e($formValues['subject']) ?>" placeholder="Наприклад, Питання щодо гарантії">
                    <?php if (isset($errors['subject'])): ?><span class="field-error"><?= e($errors['subject']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="inquiry_source">Джерело звернення <span class="required-mark">*</span></label>
                    <select id="inquiry_source" name="source" required>
                        <option value="">Оберіть джерело</option>
                        <?php foreach ($sources as $source): ?>
                            <option value="<?= e($source) ?>" <?= $formValues['source'] === $source ? 'selected' : '' ?>><?= e($source) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['source'])): ?><span class="field-error"><?= e($errors['source']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="inquiry_created_at">Дата створення</label>
                    <input id="inquiry_created_at" type="date" value="<?= e(date('Y-m-d')) ?>" readonly>
                    <span class="form-hint">Встановлюється автоматично під час збереження.</span>
                </div>
                <div class="form-group">
                    <label for="response_due_date">Крайній термін відповіді <span class="required-mark">*</span></label>
                    <input id="response_due_date" name="response_due_date" type="date" min="<?= e(date('Y-m-d')) ?>" required value="<?= e($formValues['response_due_date']) ?>">
                    <?php if (isset($errors['response_due_date'])): ?><span class="field-error"><?= e($errors['response_due_date']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="inquiry_status">Статус</label>
                    <select id="inquiry_status" name="status">
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= e($status) ?>" <?= $formValues['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?><span class="field-error"><?= e($errors['status']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-description-field">
                    <label for="short_description">Короткий опис <span class="required-mark">*</span></label>
                    <input id="short_description" name="short_description" type="text" maxlength="500" required value="<?= e($formValues['short_description']) ?>" placeholder="Стисло опишіть суть звернення">
                    <?php if (isset($errors['short_description'])): ?><span class="field-error"><?= e($errors['short_description']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-message-field">
                    <label for="inquiry_message">Текст запитання клієнта <span class="required-mark">*</span></label>
                    <textarea id="inquiry_message" name="message" rows="5" maxlength="65535" required placeholder="Повний текст вхідного запитання"><?= e($formValues['message']) ?></textarea>
                    <?php if (isset($errors['message'])): ?><span class="field-error"><?= e($errors['message']) ?></span><?php endif; ?>
                </div>
                <div class="client-form-actions">
                    <button class="button button-primary" type="submit" <?= !$pdo instanceof PDO ? 'disabled' : '' ?>>Зберегти звернення</button>
                </div>
            </div>
        </form>
    </section>

    <section class="panel module-panel clients-list-panel inquiry-list-panel">
        <form method="post" action="inquiries.php" class="client-bulk-form inquiry-bulk-form" id="inquiryBulkForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_inquiries">
            <div class="panel-heading client-list-heading">
                <div>
                    <h2>Список звернень</h2>
                    <p>Усього звернень: <?= number_format(count($inquiries), 0, ',', ' ') ?> · Натисніть на рядок, щоб переглянути повну інформацію.</p>
                </div>
                <div class="client-bulk-actions">
                    <button class="button button-danger" id="startInquiryBulkDelete" type="button" <?= $inquiries === [] ? 'disabled' : '' ?>>Видалити звернення</button>
                    <button class="button button-danger" id="inquiryBulkDeleteButton" type="submit" disabled hidden>Видалити вибрані</button>
                    <button class="button button-secondary" id="cancelInquiryBulkDelete" type="button" hidden>Скасувати</button>
                </div>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                <thead>
                    <tr>
                        <th class="client-select-cell"><input type="checkbox" id="selectAllInquiries" aria-label="Вибрати всі звернення"></th>
                        <th>Номер</th>
                        <th>Автор</th>
                        <th>Тема</th>
                        <th>Короткий опис</th>
                        <th>Створено</th>
                        <th>Відповісти до</th>
                        <th>Джерело</th>
                        <th>Статус</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($inquiries === []): ?>
                        <tr>
                            <td colspan="9" class="empty-state">
                                <span class="empty-icon">✉</span>
                                <strong>Звернень поки немає</strong>
                                <span>Натисніть «Додати звернення», щоб зареєструвати перше.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($inquiries as $inquiry): ?>
                            <?php
                            $author = $inquiry['company_name'] ?: $inquiry['requester_name'] ?: 'Невідомий контакт';
                            $statusClass = match ($inquiry['status']) {
                                'В роботі' => 'inquiry-status-progress',
                                'Оброблено' => 'inquiry-status-processed',
                                default => 'inquiry-status-new',
                            };
                            $inquiryDetailsJson = json_encode(
                                [
                                    'id' => (int) $inquiry['id'],
                                    'inquiry_number' => $inquiry['inquiry_number'],
                                    'client_id' => $inquiry['client_id'] !== null ? (int) $inquiry['client_id'] : null,
                                    'client_name' => $inquiry['company_name'],
                                    'client_contact_person' => $inquiry['client_contact_person'],
                                    'client_phone' => $inquiry['client_phone'],
                                    'client_email' => $inquiry['client_email'],
                                    'requester_name' => $inquiry['requester_name'],
                                    'requester_email' => $inquiry['requester_email'],
                                    'requester_phone' => $inquiry['requester_phone'],
                                    'subject' => $inquiry['subject'],
                                    'short_description' => $inquiry['short_description'],
                                    'message' => $inquiry['message'],
                                    'source' => $inquiry['source'],
                                    'response_due_date' => $inquiry['response_due_date'],
                                    'status' => $inquiry['status'],
                                    'created_at' => $inquiry['created_at'],
                                    'updated_at' => $inquiry['updated_at'],
                                    'created_by_name' => $inquiry['created_by_name'],
                                ],
                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
                            );
                            ?>
                            <tr class="client-row inquiry-row" tabindex="0" role="button" aria-haspopup="dialog" aria-label="Переглянути звернення <?= e($inquiry['inquiry_number']) ?>" data-inquiry-details="<?= e($inquiryDetailsJson) ?>">
                                <td class="client-select-cell"><input class="client-select-checkbox inquiry-select-checkbox" type="checkbox" name="inquiry_ids[]" value="<?= (int) $inquiry['id'] ?>" aria-label="Вибрати звернення <?= e($inquiry['inquiry_number']) ?>" <?= in_array((int) $inquiry['id'], $bulkSelectedIds, true) ? 'checked' : '' ?>></td>
                                <td class="inquiry-number-cell"><?= e($inquiry['inquiry_number']) ?></td>
                                <td><?= e($author) ?></td>
                                <td><?= e($inquiry['subject']) ?></td>
                                <td><?= e($inquiry['short_description']) ?></td>
                                <td><?= e(substr($inquiry['created_at'], 0, 10)) ?></td>
                                <td><?= e($inquiry['response_due_date']) ?></td>
                                <td><?= e($inquiry['source']) ?></td>
                                <td><span class="status-pill <?= e($statusClass) ?>"><?= e($inquiry['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        </form>
    </section>

    <div class="client-details-dialog inquiry-details-dialog" id="inquiryDetailsDialog" hidden
         data-reopen-edit="<?= $reopenInquiryEditor ? '1' : '0' ?>"
         data-edit-inquiry-id="<?= $editId !== null ? $editId : '' ?>"
         data-edit-values="<?= e($inquiryFormValuesJson) ?>"
         data-reopen-convert="<?= $showClientConversionForm ? '1' : '0' ?>"
         data-view-inquiry-id="<?= $modalViewId !== null ? $modalViewId : '' ?>">
        <section class="client-details-content inquiry-details-content" role="dialog" aria-modal="true" aria-labelledby="inquiryDetailsTitle" tabindex="-1">
        <div class="client-details-heading">
            <div>
                <p class="eyebrow">ІНФОРМАЦІЯ ПРО ЗВЕРНЕННЯ</p>
                <h2 id="inquiryDetailsTitle"></h2>
            </div>
            <button class="icon-button inquiry-details-close" type="button" aria-label="Закрити вікно">×</button>
        </div>
        <?php if ($deleteError !== ''): ?>
            <div class="alert alert-error client-modal-error" role="alert"><?= e($deleteError) ?></div>
        <?php endif; ?>
        <dl class="client-details-list" id="inquiryDetailsList"></dl>
        <form method="post" action="inquiries.php" class="client-details-form inquiry-client-conversion-form" id="inquiryClientConversionForm" hidden>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="convert_inquiry_to_client">
            <input type="hidden" name="inquiry_id" id="convertInquiryId" value="<?= $conversionInquiryId !== null ? $conversionInquiryId : '' ?>">
            <div class="client-details-form-grid">
                <div class="form-group">
                    <label for="convert_company_name">Компанія / ПІБ <span class="required-mark">*</span></label>
                    <input id="convert_company_name" name="company_name" type="text" maxlength="180" required value="<?= e($clientConversionValues['company_name']) ?>" placeholder="Назва компанії або ім’я клієнта">
                    <?php if (isset($clientConversionErrors['company_name'])): ?><span class="field-error"><?= e($clientConversionErrors['company_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="convert_contact_person">Контактна особа</label>
                    <input id="convert_contact_person" name="contact_person" type="text" maxlength="150" value="<?= e($clientConversionValues['contact_person']) ?>" placeholder="Ім’я та прізвище">
                    <?php if (isset($clientConversionErrors['contact_person'])): ?><span class="field-error"><?= e($clientConversionErrors['contact_person']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="convert_phone">Телефон</label>
                    <input id="convert_phone" name="phone" type="tel" maxlength="40" value="<?= e($clientConversionValues['phone']) ?>" placeholder="+380 00 000 00 00">
                    <?php if (isset($clientConversionErrors['phone'])): ?><span class="field-error"><?= e($clientConversionErrors['phone']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="convert_email">Електронна пошта</label>
                    <input id="convert_email" name="email" type="email" maxlength="190" value="<?= e($clientConversionValues['email']) ?>" placeholder="name@example.com">
                    <?php if (isset($clientConversionErrors['email'])): ?><span class="field-error"><?= e($clientConversionErrors['email']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="convert_city">Місто</label>
                    <input id="convert_city" name="city" type="text" maxlength="100" value="<?= e($clientConversionValues['city']) ?>" placeholder="Місто">
                    <?php if (isset($clientConversionErrors['city'])): ?><span class="field-error"><?= e($clientConversionErrors['city']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="convert_address">Адреса</label>
                    <input id="convert_address" name="address" type="text" maxlength="255" value="<?= e($clientConversionValues['address']) ?>" placeholder="Вулиця, будинок">
                    <?php if (isset($clientConversionErrors['address'])): ?><span class="field-error"><?= e($clientConversionErrors['address']) ?></span><?php endif; ?>
                </div>
                <div class="form-group client-details-notes">
                    <label for="convert_notes">Нотатки</label>
                    <textarea id="convert_notes" name="notes" rows="3" maxlength="65535" placeholder="Додаткова інформація про клієнта"><?= e($clientConversionValues['notes']) ?></textarea>
                    <?php if (isset($clientConversionErrors['notes'])): ?><span class="field-error"><?= e($clientConversionErrors['notes']) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="client-details-actions">
                <button class="button button-primary" type="submit">Створити клієнта</button>
                <button class="button button-secondary inquiry-conversion-cancel" type="button">Скасувати</button>
            </div>
        </form>
        <form method="post" action="inquiries.php" class="client-details-form inquiry-details-form" id="inquiryDetailsForm" hidden>
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="edit_inquiry">
            <input type="hidden" name="edit_id" id="editInquiryId" value="">
            <div class="client-details-form-grid">
                <fieldset class="inquiry-contact-type inquiry-edit-contact-type">
                    <legend>Хто звернувся?</legend>
                    <label><input type="radio" name="contact_type" value="client" checked> Існуючий клієнт</label>
                    <label><input type="radio" name="contact_type" value="new"> Новий контакт</label>
                    <?php if (isset($errors['contact_type'])): ?><span class="field-error"><?= e($errors['contact_type']) ?></span><?php endif; ?>
                </fieldset>
                <div class="form-group inquiry-client-field" id="editInquiryClientField">
                    <label for="edit_inquiry_client">Клієнт <span class="required-mark">*</span></label>
                    <select id="edit_inquiry_client" name="client_id" required>
                        <option value="">Оберіть клієнта</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>"><?= e($client['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['client_id'])): ?><span class="field-error"><?= e($errors['client_id']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-new-contact-field" hidden>
                    <label for="edit_requester_name">Ім’я контакту <span class="required-mark">*</span></label>
                    <input id="edit_requester_name" name="requester_name" type="text" maxlength="150" disabled>
                    <?php if (isset($errors['requester_name'])): ?><span class="field-error"><?= e($errors['requester_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-new-contact-field" hidden>
                    <label for="edit_requester_email">Електронна пошта контакту</label>
                    <input id="edit_requester_email" name="requester_email" type="email" maxlength="190" disabled>
                    <?php if (isset($errors['requester_email'])): ?><span class="field-error"><?= e($errors['requester_email']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-new-contact-field" hidden>
                    <label for="edit_requester_phone">Телефон контакту</label>
                    <input id="edit_requester_phone" name="requester_phone" type="tel" maxlength="40" disabled>
                    <?php if (isset($errors['requester_phone'])): ?><span class="field-error"><?= e($errors['requester_phone']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="edit_inquiry_subject">Тема звернення <span class="required-mark">*</span></label>
                    <input id="edit_inquiry_subject" name="subject" type="text" maxlength="180" required>
                    <?php if (isset($errors['subject'])): ?><span class="field-error"><?= e($errors['subject']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="edit_inquiry_source">Джерело звернення <span class="required-mark">*</span></label>
                    <select id="edit_inquiry_source" name="source" required>
                        <?php foreach ($sources as $source): ?><option value="<?= e($source) ?>"><?= e($source) ?></option><?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['source'])): ?><span class="field-error"><?= e($errors['source']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="edit_inquiry_created_at">Дата створення</label>
                    <input id="edit_inquiry_created_at" type="date" readonly>
                </div>
                <div class="form-group">
                    <label for="edit_response_due_date">Крайній термін відповіді <span class="required-mark">*</span></label>
                    <input id="edit_response_due_date" name="response_due_date" type="date" required>
                    <?php if (isset($errors['response_due_date'])): ?><span class="field-error"><?= e($errors['response_due_date']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="edit_inquiry_status">Статус</label>
                    <select id="edit_inquiry_status" name="status">
                        <?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>"><?= e($status) ?></option><?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['status'])): ?><span class="field-error"><?= e($errors['status']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-description-field">
                    <label for="edit_short_description">Короткий опис <span class="required-mark">*</span></label>
                    <input id="edit_short_description" name="short_description" type="text" maxlength="500" required>
                    <?php if (isset($errors['short_description'])): ?><span class="field-error"><?= e($errors['short_description']) ?></span><?php endif; ?>
                </div>
                <div class="form-group inquiry-message-field">
                    <label for="edit_inquiry_message">Текст запитання клієнта <span class="required-mark">*</span></label>
                    <textarea id="edit_inquiry_message" name="message" rows="5" maxlength="65535" required></textarea>
                    <?php if (isset($errors['message'])): ?><span class="field-error"><?= e($errors['message']) ?></span><?php endif; ?>
                </div>
            </div>
            <?php if ($error !== '' && $deleteError === ''): ?>
                <div class="alert alert-error client-modal-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>
            <div class="client-details-actions">
                <button class="button button-primary" type="submit">Зберегти зміни</button>
                <button class="button button-secondary inquiry-details-cancel-edit" type="button">Скасувати</button>
            </div>
        </form>
        <div class="client-details-actions" id="inquiryDetailsActions">
            <button class="button button-primary" id="inquiryDetailsEdit" type="button">Редагувати дані</button>
            <button class="button button-secondary" id="inquiryConvertToClient" type="button" hidden>Додати як нового клієнта</button>
            <form method="post" action="inquiries.php" class="client-delete-form" id="inquiryDeleteForm">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="delete_inquiry">
                <input type="hidden" name="inquiry_id" id="deleteInquiryId" value="">
                <button class="button button-danger" type="submit">Видалити звернення</button>
            </form>
            <button class="button button-secondary inquiry-details-close" type="button">Закрити</button>
        </div>
        </section>
    </div>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
