<?php

declare(strict_types=1);

/**
 * Cron de recordatorios a alumnos con clases pendientes.
 * Protegido con reminder_cron_key de config.php (?key=... o header X-Reminder-Key).
 *
 * Ejemplo diario en HostGator:
 *   curl -fsS "https://www.fluxusterapia.com/academia/cron_reminders.php?key=TU_CLAVE"
 */

require __DIR__ . '/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$provided = (string) ($_GET['key'] ?? '');
if (!reminder_cron_key_ok($provided)) {
    http_response_code(403);
    echo "No autorizado.\n";
    exit;
}

// Rate limit simple por archivo (mínimo 30 s entre corridas).
$lockDir = __DIR__ . '/data';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/.reminder_cron_lock';
$now = time();
if (is_file($lockFile)) {
    $last = (int) @file_get_contents($lockFile);
    if ($last > 0 && ($now - $last) < 30) {
        http_response_code(429);
        echo "Esperá unos segundos antes de volver a correr el cron.\n";
        exit;
    }
}
@file_put_contents($lockFile, (string) $now);

$dry = isset($_GET['dry']) && (string) $_GET['dry'] === '1';
$stats = reminder_run_batch($dry, REMINDER_CRON_MAX_SENDS);

echo $dry ? "Simulación OK\n" : "Cron OK\n";
echo 'revisados=' . $stats['checked'] . "\n";
echo 'enviados=' . $stats['sent'] . "\n";
echo 'omitidos=' . $stats['skipped'] . "\n";
echo 'completaron=' . $stats['completed'] . "\n";
echo 'errores=' . $stats['errors'] . "\n";
