<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';

$pageTitle = 'Мій профіль';
$activePage = '';

$user = currentUser();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Сесія форми застаріла. Оновіть сторінку та спробуйте ще раз.';
    } else {

        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Перевірка імені
        if ($fullName === '') {
            $error = 'Введіть ім’я користувача.';
        }

        // Перевірка електронної пошти
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Введіть коректну електронну пошту.';
        }

        // Перевірка, чи зайнята пошта іншим користувачем
        else {
            $statement = $pdo->prepare(
                'SELECT id FROM users
                 WHERE email = :email AND id != :id
                 LIMIT 1'
            );

            $statement->execute([
                'email' => $email,
                'id' => $user['id']
            ]);

            $existingUser = $statement->fetch();

            if ($existingUser) {
                $error = 'Користувач із такою електронною поштою вже існує.';
            }

            // Якщо користувач хоче змінити пароль
            elseif ($newPassword !== '' || $confirmPassword !== '' || $currentPassword !== '') {

                if ($currentPassword === '') {
                    $error = 'Для зміни пароля введіть поточний пароль.';
                }

                elseif ($newPassword === '') {
                    $error = 'Введіть новий пароль.';
                }

                elseif (strlen($newPassword) < 6) {
                    $error = 'Новий пароль повинен містити щонайменше 6 символів.';
                }

                elseif ($newPassword !== $confirmPassword) {
                    $error = 'Новий пароль та підтвердження пароля не збігаються.';
                }

                else {
                    // Отримуємо поточний хеш пароля
                    $statement = $pdo->prepare(
                        'SELECT password_hash FROM users
                         WHERE id = :id
                         LIMIT 1'
                    );

                    $statement->execute([
                        'id' => $user['id']
                    ]);

                    $databaseUser = $statement->fetch();

                    if (!$databaseUser || !password_verify($currentPassword, $databaseUser['password_hash'])) {
                        $error = 'Поточний пароль введено неправильно.';
                    } else {

                        $newPasswordHash = password_hash(
                            $newPassword,
                            PASSWORD_DEFAULT
                        );

                        $statement = $pdo->prepare(
                            'UPDATE users
                             SET full_name = :full_name,
                                 email = :email,
                                 password_hash = :password_hash
                             WHERE id = :id'
                        );

                        $statement->execute([
                            'full_name' => $fullName,
                            'email' => $email,
                            'password_hash' => $newPasswordHash,
                            'id' => $user['id']
                        ]);

                        $_SESSION['user']['full_name'] = $fullName;
                        $_SESSION['user']['email'] = $email;

                        $user = currentUser();

                        $message = 'Дані профілю та пароль успішно оновлено.';
                    }
                }
            }

            // Якщо пароль не змінюється
            else {

                $statement = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name,
                         email = :email
                     WHERE id = :id'
                );

                $statement->execute([
                    'full_name' => $fullName,
                    'email' => $email,
                    'id' => $user['id']
                ]);

                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['email'] = $email;

                $user = currentUser();

                $message = 'Дані профілю успішно оновлено.';
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<main class="page-content">

    <div class="page-heading">
        <div>
            <h1>Мій профіль</h1>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-success" role="alert">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="profile-grid">

        <section class="panel">

            <div class="panel-heading">
                <div>
                    <h2>Особисті дані</h2>
                </div>
            </div>

            <form method="post" action="profile.php" class="form-grid">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <div class="form-group full-width">
                    <label for="full_name">Ім’я користувача</label>

                    <input
                        id="full_name"
                        name="full_name"
                        type="text"
                        value="<?= e($user['full_name'] ?? '') ?>"
                        placeholder="Введіть ім’я"
                        required
                    >
                </div>

                <div class="form-group full-width">
                    <label for="email">Електронна пошта</label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="<?= e($user['email'] ?? '') ?>"
                        placeholder="name@example.com"
                        required
                    >
                </div>

                <div class="form-actions full-width">
                    <button
                        class="button button-primary"
                        type="submit"
                    >
                        Зберегти зміни
                    </button>
                </div>

            </form>

        </section>


        <section class="panel">

            <div class="panel-heading">
                <div>
                    <h2>Зміна пароля</h2>
                </div>
            </div>

            <form method="post" action="profile.php" class="form-grid">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(csrfToken()) ?>"
                >

                <input
                    type="hidden"
                    name="full_name"
                    value="<?= e($user['full_name'] ?? '') ?>"
                >

                <input
                    type="hidden"
                    name="email"
                    value="<?= e($user['email'] ?? '') ?>"
                >

                <div class="form-group full-width">
                    <label for="current_password">
                        Поточний пароль
                    </label>

                    <input
                        id="current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Введіть поточний пароль"
                    >
                </div>

                <div class="form-group full-width">
                    <label for="new_password">
                        Новий пароль
                    </label>

                    <input
                        id="new_password"
                        name="new_password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Мінімум 6 символів"
                    >
                </div>

                <div class="form-group full-width">
                    <label for="confirm_password">
                        Підтвердження нового пароля
                    </label>

                    <input
                        id="confirm_password"
                        name="confirm_password"
                        type="password"
                        autocomplete="new-password"
                        placeholder="Повторіть новий пароль"
                    >
                </div>

                <div class="form-actions full-width">
                    <button
                        class="button button-primary"
                        type="submit"
                    >
                        Змінити пароль
                    </button>
                </div>

            </form>

        </section>

    </div>

</main>

<?php require __DIR__ . '/includes/footer.php'; ?>