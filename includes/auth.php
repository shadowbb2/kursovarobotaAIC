<?php
// Start the PHP session once for the current request. This gives us a place to store
// the logged-in user, other auth-related state.
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie so it is harder to steal or tamper with in the browser.
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Redirect the user to the login page if they are not currently authenticated.
function requireLogin(): void
{
    if (empty($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

// Return the currently logged-in user data from the session, or an empty array if nobody is logged in.
function currentUser(): array
{
    return $_SESSION['user'] ?? [];
}

// Generate a CSRF token 
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Check whether a submitted form token matches the session token
function verifyCsrfToken(mixed $token): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

// Escape output before printing it into HTML to avoid XSS issues.
// This is used whenever user-provided text is displayed in a page.
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
