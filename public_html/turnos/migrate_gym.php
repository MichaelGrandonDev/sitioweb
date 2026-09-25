<?php

declare(strict_types=1);

/**
 * Agrega la terapia/curso Gym pre y post parto si todavía no existe.
 * Abrí una sola vez: /turnos/migrate_gym.php
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/migrate_guard.php';
require_migrate_key($config);

header('Content-Type: text/plain; charset=utf-8');

$name = 'Curso gym pre y post parto';
$exists = db()->prepare('SELECT id FROM therapies WHERE name = ? LIMIT 1');
$exists->execute([$name]);
if ($exists->fetch()) {
    echo "OK: la terapia ya existe ($name).\n";
    exit;
}

$prep = "Antes de tu turno:\n"
    . "• Indicá semana de embarazo o si estás en postparto.\n"
    . "• Traé ropa cómoda y botella de agua.\n"
    . "• Si no podés asistir, avisá con la mayor anticipación posible.";

$max = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM therapies')->fetchColumn();
db()->prepare(
    'INSERT INTO therapies (name, description, duration_min, prep_notes, active, sort_order)
     VALUES (?, ?, ?, ?, 1, ?)'
)->execute([
    $name,
    'Entrenamiento adaptado para embarazadas y recuperación post parto.',
    60,
    $prep,
    $max + 1,
]);

echo "OK: terapia agregada — $name.\n";
