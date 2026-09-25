<?php

declare(strict_types=1);

/**
 * Agrega el curso Gym pre y post parto si todavía no existe.
 * Abrí una sola vez: /academia/migrate_gym.php
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/migrate_guard.php';
require_migrate_key($config);

header('Content-Type: text/plain; charset=utf-8');

$slug = 'gym-pre-post-parto';
$exists = db()->prepare('SELECT id FROM courses WHERE slug = ? LIMIT 1');
$exists->execute([$slug]);
if ($exists->fetch()) {
    echo "OK: el curso ya existe ($slug).\n";
    exit;
}

$max = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM courses')->fetchColumn();
db()->prepare(
    'INSERT INTO courses (title, slug, category, description, sort_order) VALUES (?, ?, ?, ?, ?)'
)->execute([
    'Gym pre y post parto',
    $slug,
    'Embarazo y postparto',
    'Curso de gym pre y post parto para embarazadas y recuperación después del parto.',
    $max + 1,
]);

echo "OK: curso agregado — Gym pre y post parto.\n";
