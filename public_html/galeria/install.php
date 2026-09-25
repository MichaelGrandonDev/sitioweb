<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (db_ready()) {
    flash('info', 'Galería ya está instalada.');
    redirect('index.php');
}

global $config;
$pdo = db();
migrate_galeria();

if (!is_dir($config['uploads_dir'])) {
    mkdir($config['uploads_dir'], 0755, true);
}

$seed = [
    ['image', '../img/galeria/masoterapia.jpg', 'Masoterapia', 'Sesión de masoterapia', 'https://www.instagram.com/fluxusterapia/', 0, 1.55, 10],
    ['image', '../img/galeria/movimiento.jpg', 'Movimiento', 'Práctica de movimiento consciente', '../academia/', 0, 0.85, 20],
    ['image', '../img/galeria/practica.jpg', 'Videos', 'Práctica al atardecer', 'https://www.youtube.com/@fluxusterapia', 0, 1.35, 30],
    ['image', '../img/galeria/espacio.jpg', 'El espacio', 'Consultorio FluxusTerapia', '../#contacto', 0, 1.25, 40],
    ['image', '../img/galeria/servicios.jpg', 'Bienestar', 'Ambiente de bienestar', '../#servicios', 0, 1.35, 50],
    ['image', '../img/galeria/medicina.jpg', 'Medicina china', 'Medicina china', '../#servicios', 0, 1.15, 60],
    ['image', '../img/galeria/detalle.jpg', 'Cuidado', 'Detalle terapéutico', '', 1, 1.2, 70],
    ['image', '../img/galeria/masoterapia.jpg', 'Instagram', 'Masoterapia', 'https://www.instagram.com/fluxusterapia/', 0, 1.5, 80],
    ['image', '../img/galeria/espacio.jpg', 'Turnos', 'Consultorio', '../turnos/', 0, 1.1, 90],
];

$ins = $pdo->prepare("
  INSERT INTO gallery_items
    (media_type, media_path, video_url, label, alt_text, link_url, use_lightbox, width_ratio, sort_order, active)
  VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, 1)
");
foreach ($seed as $row) {
    $ins->execute($row);
}

flash('success', 'Galería lista. Podés editarla desde el admin.');
redirect('admin.php');
