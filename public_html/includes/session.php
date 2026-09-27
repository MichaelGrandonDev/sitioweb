<?php

declare(strict_types=1);

/**
 * Sesión compartida por todos los módulos (academia, cursos, turnos, blogs, galería).
 * El path "/" es necesario: el login de admin de la academia habilita galería y blogs.
 */
function fluxus_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Nuevo ID de sesión al iniciar sesión, contra la fijación de sesión. */
function fluxus_session_login(): void
{
    session_regenerate_id(true);
}

/** Las claves de ejemplo del repo nunca sirven para entrar. */
function fluxus_password_configured(string $pass): bool
{
    return $pass !== '' && $pass !== 'CHANGE_ME';
}

/** Frena la fuerza bruta: cada intento fallido tarda un poco más, hasta 5 s. */
function fluxus_login_failed(): void
{
    $_SESSION['login_failures'] = (int) ($_SESSION['login_failures'] ?? 0) + 1;
    sleep(min(5, $_SESSION['login_failures']));
}
