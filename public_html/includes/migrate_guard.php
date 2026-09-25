<?php

declare(strict_types=1);

/**
 * Protege scripts de migración one-shot.
 * Uso: require_migrate_key($config);
 * Llamada: /ruta/migrate_x.php?key=TU_CLAVE
 */
function require_migrate_key(array $config): void
{
    $expected = (string) ($config['migrate_key'] ?? '');
    $provided = (string) ($_GET['key'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "No autorizado. Usá ?key=...\n";
        exit;
    }
}
