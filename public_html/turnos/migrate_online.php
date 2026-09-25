<?php

declare(strict_types=1);

/**
 * Renombra Chi kung y Taichi a modalidad Online en turnos.
 * Abrí una sola vez: /turnos/migrate_online.php
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/migrate_guard.php';
require_migrate_key($config);

header('Content-Type: text/plain; charset=utf-8');

$prepOnline = "Antes de tu turno:\n"
    . "• Clase online: asegurate conexión estable y espacio despejado.\n"
    . "• Traé ropa cómoda.\n"
    . "• Si no podés asistir, avisá con la mayor anticipación posible.";

$updates = [
    [
        'match' => ['Chikung', 'Chi kung', 'Chikung Online', 'Chi kung Online'],
        'name' => 'Chi kung Online',
        'description' => 'Movimiento, respiración y conciencia. Modalidad online.',
    ],
    [
        'match' => ['Taichi', 'Tai chi', 'Taichi Online'],
        'name' => 'Taichi Online',
        'description' => 'Secuencias fluidas de movimiento consciente. Modalidad online.',
    ],
];

$upd = db()->prepare(
    'UPDATE therapies SET name = ?, description = ?, prep_notes = ? WHERE name = ?'
);

foreach ($updates as $u) {
    $done = false;
    foreach ($u['match'] as $old) {
        $upd->execute([$u['name'], $u['description'], $prepOnline, $old]);
        if ($upd->rowCount() > 0) {
            echo "OK turnos: {$old} → {$u['name']}\n";
            $done = true;
            break;
        }
    }
    if (!$done) {
        echo "AVISO: no se encontró terapia para {$u['name']}\n";
    }
}

echo "Listo.\n";
