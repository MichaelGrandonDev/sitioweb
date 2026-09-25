<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (db_ready()) {
    flash('info', 'El sistema de turnos ya está instalado.');
    redirect('index.php');
}

$pdo = db();
$pdo->exec("
CREATE TABLE therapies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  duration_min INTEGER NOT NULL DEFAULT 60,
  prep_notes TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE weekly_hours (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  weekday INTEGER NOT NULL,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE blocked_dates (
  date TEXT PRIMARY KEY,
  reason TEXT NOT NULL DEFAULT ''
);

CREATE TABLE appointments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  token TEXT NOT NULL UNIQUE,
  therapy_id INTEGER NOT NULL,
  date TEXT NOT NULL,
  time TEXT NOT NULL,
  patient_name TEXT NOT NULL,
  patient_phone TEXT NOT NULL,
  patient_email TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'confirmed',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(therapy_id) REFERENCES therapies(id)
);

CREATE INDEX idx_appointments_date ON appointments(date);
");

$prepDefault = "• No comas ni bebas (salvo agua) 1 hora antes del turno.\n"
    . "• Evitá alcohol y comidas muy pesadas el día previo.\n"
    . "• Llegá 5–10 minutos antes.\n"
    . "• Usá ropa cómoda.\n"
    . "• Si no podés asistir, avisá con la mayor anticipación posible.";

$therapies = [
    ['Masoterapia', 'Masaje relax / descontracturante y técnicas complementarias.', 60, $prepDefault, 1],
    ['Rehabilitación kinésica', 'Tratamiento personalizado para movilidad y recuperación.', 60, $prepDefault, 2],
    ['Medicina china', 'Enfoque integral desde la tradición de la Medicina China.', 60, $prepDefault . "\n• Conviene no venir con el estómago demasiado lleno.", 3],
    ['Talleres hipopresivos', 'Práctica postural y respiratoria (consultar modalidad).', 60, $prepDefault, 4],
    ['Curso gym pre y post parto', 'Entrenamiento adaptado para embarazadas y recuperación post parto.', 60, $prepDefault . "\n• Indicá semana de embarazo o si estás en postparto.\n• Traé ropa cómoda y botella de agua.", 5],
    ['Chi kung Online', 'Movimiento, respiración y conciencia. Modalidad online.', 60, $prepDefault . "\n• Clase online: asegurate conexión estable y espacio despejado.", 6],
    ['Taichi Online', 'Secuencias fluidas de movimiento consciente. Modalidad online.', 60, $prepDefault . "\n• Clase online: asegurate conexión estable y espacio despejado.", 7],
];

$ins = $pdo->prepare('INSERT INTO therapies (name, description, duration_min, prep_notes, sort_order) VALUES (?, ?, ?, ?, ?)');
foreach ($therapies as $t) {
    $ins->execute($t);
}

// Lun–Vie 09–12 y 16–20; Sáb 09–13
$hours = $pdo->prepare('INSERT INTO weekly_hours (weekday, start_time, end_time) VALUES (?, ?, ?)');
foreach ([1, 2, 3, 4, 5] as $wd) {
    $hours->execute([$wd, '09:00', '12:00']);
    $hours->execute([$wd, '16:00', '20:00']);
}
$hours->execute([6, '09:00', '13:00']);

flash('success', 'Turnos listos. Ya podés reservar.');
redirect('index.php');
