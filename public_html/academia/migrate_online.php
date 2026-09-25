<?php

declare(strict_types=1);

/**
 * Renombra Chi kung y Taichi a modalidad Online.
 * Abrí una sola vez: /academia/migrate_online.php
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/migrate_guard.php';
require_migrate_key($config);

header('Content-Type: text/plain; charset=utf-8');

$updates = [
    [
        'slugs' => ['chikung'],
        'names' => ['Chikung', 'Chi kung', 'Chikung Online'],
        'title' => 'Chi kung Online',
        'category' => 'Movimiento energético · Online',
        'description' => 'Prácticas suaves de respiración y movimiento consciente. Modalidad online.',
    ],
    [
        'slugs' => ['taichi'],
        'names' => ['Taichi', 'Tai chi', 'Taichi Online'],
        'title' => 'Taichi Online',
        'category' => 'Movimiento fluido · Online',
        'description' => 'Secuencias conscientes de calma y coordinación. Modalidad online.',
    ],
];

$bySlug = db()->prepare('UPDATE courses SET title = ?, category = ?, description = ? WHERE slug = ?');
$byTitle = db()->prepare('UPDATE courses SET title = ?, category = ?, description = ? WHERE title = ?');

foreach ($updates as $u) {
    $done = false;
    foreach ($u['slugs'] as $slug) {
        $bySlug->execute([$u['title'], $u['category'], $u['description'], $slug]);
        if ($bySlug->rowCount() > 0) {
            echo "OK academia: {$u['title']} (slug {$slug})\n";
            $done = true;
            break;
        }
    }
    if (!$done) {
        foreach ($u['names'] as $name) {
            $byTitle->execute([$u['title'], $u['category'], $u['description'], $name]);
            if ($byTitle->rowCount() > 0) {
                echo "OK academia: {$u['title']} (title {$name})\n";
                $done = true;
                break;
            }
        }
    }
    if (!$done) {
        echo "AVISO: no se encontró curso para {$u['title']}\n";
    }
}

echo "Listo.\n";
