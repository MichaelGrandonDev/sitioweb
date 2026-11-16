<?php
/**
 * Tests de la lógica de recordatorios (sin enviar mail ni tocar la base real).
 *
 * Uso: php qa/test_reminders.php
 */

declare(strict_types=1);

require __DIR__ . '/../public_html/academia/includes/reminders.php';

$failures = 0;
$passed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failures++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "Candidato a envío\n";

$base = [
    'global_enabled' => true,
    'enabled' => true,
    'email_ok' => true,
    'pending_count' => 3,
    'stop_reason' => '',
    'last_sent_at' => null,
    'interval_days' => 7,
    'now' => 1_700_000_000,
];

$r = reminder_should_send($base);
check('envía si está activo y hay pendientes', $r['ok'] === true && $r['reason'] === 'ready', $r['reason']);

$r = reminder_should_send(array_merge($base, ['global_enabled' => false]));
check('no envía si la automatización global está apagada', $r['ok'] === false && $r['reason'] === 'global_off', $r['reason']);

$r = reminder_should_send(array_merge($base, ['enabled' => false, 'stop_reason' => 'admin']));
check('no envía si el admin lo pausó', $r['ok'] === false && $r['reason'] === 'disabled', $r['reason']);

$r = reminder_should_send(array_merge($base, ['email_ok' => false]));
check('no envía sin email válido', $r['ok'] === false && $r['reason'] === 'no_email', $r['reason']);

$r = reminder_should_send(array_merge($base, ['pending_count' => 0]));
check('no envía si completó todas las clases', $r['ok'] === false && $r['reason'] === 'completed', $r['reason']);

$r = reminder_should_send(array_merge($base, [
    'enabled' => false,
    'stop_reason' => 'completed',
    'pending_count' => 0,
]));
check('motivo completed cuando ya terminó', $r['ok'] === false && $r['reason'] === 'completed', $r['reason']);

$r = reminder_should_send(array_merge($base, [
    'last_sent_at' => gmdate('Y-m-d H:i:s', 1_700_000_000 - 2 * 86400),
    'interval_days' => 7,
    'now' => 1_700_000_000,
]));
check('no reenvía antes de cumplir el intervalo', $r['ok'] === false && $r['reason'] === 'too_soon', $r['reason']);

$r = reminder_should_send(array_merge($base, [
    'last_sent_at' => gmdate('Y-m-d H:i:s', 1_700_000_000 - 8 * 86400),
    'interval_days' => 7,
    'now' => 1_700_000_000,
]));
check('sí envía cuando ya pasó el intervalo', $r['ok'] === true && $r['reason'] === 'ready', $r['reason']);

echo "Etiquetas de estado\n";
$st = reminder_status_label(['enabled' => 1, 'stop_reason' => ''], 'alumno@ejemplo.com', 2);
check('estado activo', $st['key'] === 'active', $st['key']);

$st = reminder_status_label(['enabled' => 0, 'stop_reason' => 'admin'], 'alumno@ejemplo.com', 2);
check('estado pausado', $st['key'] === 'paused', $st['key']);

$st = reminder_status_label(['enabled' => 0, 'stop_reason' => 'completed'], 'alumno@ejemplo.com', 0);
check('estado completó', $st['key'] === 'completed', $st['key']);

$st = reminder_status_label(['enabled' => 1, 'stop_reason' => ''], '', 2);
check('estado sin email', $st['key'] === 'no_email', $st['key']);

echo "Resumen de pendientes\n";
$sum = reminder_pending_summary([
    ['title' => 'Respiración'],
    ['title' => 'Postura'],
    ['title' => 'Secuencia'],
    ['title' => 'Cierre'],
    ['title' => 'Extra'],
], 3);
check('lista corta con “y N más”', str_contains($sum, 'Respiración') && str_contains($sum, 'y 2 más'), $sum);

echo "\nResultado: {$passed} ok, {$failures} fallos\n";
exit($failures > 0 ? 1 : 0);
