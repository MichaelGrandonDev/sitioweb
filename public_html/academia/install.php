<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (db_ready()) {
    flash('info', 'La academia ya está instalada. Iniciá sesión.');
    redirect('login.php');
}

$pdo = db();
$pdo->exec("
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL DEFAULT '',
  role TEXT NOT NULL CHECK(role IN ('admin','student')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE courses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  slug TEXT NOT NULL UNIQUE,
  category TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  meet_url TEXT NOT NULL DEFAULT '',
  meet_schedule TEXT NOT NULL DEFAULT '',
  published INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE lessons (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  video_url TEXT NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lesson_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  instructions TEXT NOT NULL DEFAULT '',
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

CREATE TABLE enrollments (
  user_id INTEGER NOT NULL,
  course_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, course_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE lesson_progress (
  user_id INTEGER NOT NULL,
  lesson_id INTEGER NOT NULL,
  completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, lesson_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

CREATE TABLE task_completions (
  user_id INTEGER NOT NULL,
  task_id INTEGER NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, task_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
);

CREATE TABLE course_sections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE course_resources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER NOT NULL,
  section_id INTEGER NOT NULL,
  type TEXT NOT NULL DEFAULT 'link',
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL DEFAULT '',
  content TEXT NOT NULL DEFAULT '',
  file_path TEXT NOT NULL DEFAULT '',
  track_completion INTEGER NOT NULL DEFAULT 1,
  lesson_id INTEGER DEFAULT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY(section_id) REFERENCES course_sections(id) ON DELETE CASCADE
);

CREATE TABLE resource_progress (
  user_id INTEGER NOT NULL,
  resource_id INTEGER NOT NULL,
  completed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(user_id, resource_id),
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY(resource_id) REFERENCES course_resources(id) ON DELETE CASCADE
);

CREATE TABLE course_announcements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE course_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  course_id INTEGER NOT NULL,
  title TEXT NOT NULL,
  starts_at TEXT NOT NULL,
  location TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL DEFAULT '',
  FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE CASCADE
);
");

$hash = password_hash($config['admin_pass'], PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (username, password_hash, name, role) VALUES (?, ?, ?, ?)');
$stmt->execute([$config['admin_user'], $hash, $config['admin_name'], 'admin']);

// Sample courses
$courses = [
    ['Chi kung Online', 'chikung', 'Movimiento energético · Online', 'Prácticas suaves de respiración y movimiento consciente. Modalidad online.'],
    ['Hipopresivos', 'hipopresivos', 'Core y postura', 'Talleres posturales y respiratorios.'],
    ['Gym pre y post parto', 'gym-pre-post-parto', 'Embarazo y postparto', 'Curso de gym pre y post parto para embarazadas y recuperación después del parto.'],
    ['Taichi Online', 'taichi', 'Movimiento fluido · Online', 'Secuencias conscientes de calma y coordinación. Modalidad online.'],
    ['Kinesiología', 'kinesiologia', 'Rehabilitación', 'Clases orientadas a movilidad y recuperación.'],
];

$insCourse = $pdo->prepare('INSERT INTO courses (title, slug, category, description, sort_order) VALUES (?, ?, ?, ?, ?)');
foreach ($courses as $i => $c) {
    $insCourse->execute([$c[0], $c[1], $c[2], $c[3], $i + 1]);
}

flash('success', 'Instalación completa. Usuario admin: ' . $config['admin_user']);
redirect('login.php');
