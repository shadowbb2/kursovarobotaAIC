<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respondWithJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET' && $method !== 'POST') {
    header('Allow: GET, POST');
    respondWithJson(['error' => 'Метод запиту не підтримується.'], 405);
}

try {
    require_once __DIR__ . '/config/database.php';
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    respondWithJson(['error' => 'Не вдалося підключитися до бази даних.'], 500);
}

$userId = (int) (currentUser()['id'] ?? 0);
if ($userId < 1) {
    respondWithJson(['error' => 'Не вдалося визначити обліковий запис користувача.'], 401);
}

if ($method === 'GET') {
    try {
        $statement = $pdo->prepare(
            'SELECT id, started_at
             FROM work_sessions
             WHERE user_id = :user_id AND ended_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        $session = $statement->fetch();

        respondWithJson([
            'active' => (bool) $session,
            'started_at' => $session ? str_replace(' ', 'T', $session['started_at']) . 'Z' : null,
        ]);
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        respondWithJson(['error' => 'Не вдалося завантажити стан робочого часу.'], 500);
    }
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
    respondWithJson(['error' => 'Сесія застаріла. Оновіть сторінку та спробуйте ще раз.'], 403);
}

$action = $_POST['action'] ?? '';
if (!is_string($action) || !in_array($action, ['start', 'stop'], true)) {
    respondWithJson(['error' => 'Невідома дія для обліку робочого часу.'], 400);
}

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
    $statement->execute(['user_id' => $userId]);
    if (!$statement->fetch()) {
        $pdo->rollBack();
        respondWithJson(['error' => 'Обліковий запис користувача не знайдено.'], 404);
    }

    $statement = $pdo->prepare(
        'SELECT id, started_at
         FROM work_sessions
         WHERE user_id = :user_id AND ended_at IS NULL
         ORDER BY id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['user_id' => $userId]);
    $session = $statement->fetch();

    if ($action === 'start') {
        if ($session) {
            $pdo->commit();
            respondWithJson([
                'active' => true,
                'started_at' => str_replace(' ', 'T', $session['started_at']) . 'Z',
            ]);
        }

        $statement = $pdo->prepare(
            'INSERT INTO work_sessions (user_id, started_at)
             VALUES (:user_id, UTC_TIMESTAMP())'
        );
        $statement->execute(['user_id' => $userId]);

        $statement = $pdo->prepare('SELECT started_at FROM work_sessions WHERE id = :id');
        $statement->execute(['id' => $pdo->lastInsertId()]);
        $startedAt = $statement->fetchColumn();

        $pdo->commit();
        respondWithJson([
            'active' => true,
            'started_at' => str_replace(' ', 'T', $startedAt) . 'Z',
        ]);
    }

    if (!$session) {
        $pdo->commit();
        respondWithJson(['active' => false, 'started_at' => null]);
    }

    $statement = $pdo->prepare(
        'UPDATE work_sessions
         SET ended_at = UTC_TIMESTAMP()
         WHERE id = :id AND ended_at IS NULL'
    );
    $statement->execute(['id' => $session['id']]);

    $statement = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, started_at, ended_at)
         FROM work_sessions
         WHERE id = :id'
    );
    $statement->execute(['id' => $session['id']]);
    $durationSeconds = (int) $statement->fetchColumn();

    $pdo->commit();
    respondWithJson([
        'active' => false,
        'started_at' => null,
        'duration_seconds' => $durationSeconds,
    ]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    respondWithJson(['error' => 'Не вдалося зберегти робочий час. Спробуйте ще раз.'], 500);
}
