<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$databaseError = false;

try {
    require_once __DIR__ . '/config/database.php';
} catch (PDOException $exception) {
    $databaseError = true;
    $error = 'Не вдалося підключитися до бази даних. Перевірте налаштування у файлі config/database.php та імпортуйте database.sql.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$databaseError) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Заповніть електронну пошту та пароль.';
        } else {
            $statement = $pdo->prepare(
                'SELECT id, full_name, email, password_hash, role
                 FROM users
                 WHERE email = :email AND is_active = 1
                 LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'        => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'role'      => $user['role'],
                ];
                unset($_SESSION['csrf_token']);
                header('Location: dashboard.php');
                exit;
            }

            $error = 'Неправильна електронна пошта або пароль.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вхід — AIC</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-page">
    <main class="login-card">
        <div class="brand-mark login-brand-mark">A</div>
        <p class="eyebrow">СИСТЕМА УПРАВЛІННЯ КЛІЄНТАМИ</p>
        <h1>Вхід до AIC</h1>
        <p class="login-subtitle">Увійдіть, щоб продовжити роботу із системою.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" class="login-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label for="email">Електронна пошта</label>
            <input id="email" name="email" type="email" autocomplete="username" placeholder="name@example.com" required>

            <label for="password">Пароль</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Введіть пароль" required>

            <button class="button button-primary button-full" type="submit" <?= $databaseError ? 'disabled' : '' ?>>Увійти</button>
        </form>

        <p class="login-footnote">AIC · Навчальний проєкт</p>
    </main>
</body>
</html>
