<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (db_ready()) {
    flash('info', 'Blogs ya está instalado.');
    redirect('index.php');
}

$pdo = db();
$pdo->exec("
CREATE TABLE posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  excerpt TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  pdf_path TEXT NOT NULL DEFAULT '',
  pdf_name TEXT NOT NULL DEFAULT '',
  cover_path TEXT NOT NULL DEFAULT '',
  video_url TEXT NOT NULL DEFAULT '',
  published_at TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL
);
");

global $config;
if (!is_dir($config['uploads_dir'])) {
    mkdir($config['uploads_dir'], 0755, true);
}

$now = date('c');
$pdo->prepare(
    'INSERT INTO posts (title, slug, excerpt, body, pdf_path, pdf_name, published_at, active, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
)->execute([
    'Bienvenida al blog de FluxusTerapia',
    'bienvenida',
    'Espacio para noticias, papers y material de lectura sobre bienestar y terapias.',
    "Desde acá vamos a compartir novedades del consultorio, tips de bienestar y documentos en PDF (papers, revistas o guías) para que puedas leerlos o descargarlos.\n\nPronto vas a ver las primeras notas acá.",
    '',
    '',
    $now,
    $now,
]);

flash('success', 'Blogs listo. Ya podés publicar notas y PDFs desde el admin.');
redirect('index.php');
