<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('users.php');
    }
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'create') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $courseIds = array_map('intval', $_POST['courses'] ?? []);
        $sendWelcome = !empty($_POST['send_welcome']);

        if ($username === '' || $password === '' || $name === '') {
            flash('error', 'Completá nombre, usuario y contraseña.');
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Ingresá un email válido del alumno.');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, name, email, role) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$username, $hash, $name, $email, 'student']);
                $uid = (int) $pdo->lastInsertId();
                $enroll = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');
                foreach ($courseIds as $cid) {
                    if ($cid > 0) {
                        $enroll->execute([$uid, $cid]);
                    }
                }

                $mailOk = true;
                if ($sendWelcome) {
                    $mailOk = send_welcome_email($email, $name, $username, $password);
                }

                if ($sendWelcome && $mailOk) {
                    flash('success', 'Alumno dado de alta y mail de bienvenida enviado a ' . $email);
                } elseif ($sendWelcome && !$mailOk) {
                    flash('error', 'Alumno creado, pero el mail no se pudo enviar. Revisá el correo o el mail del hosting.');
                } else {
                    flash('success', 'Alumno dado de alta: ' . $username);
                }
            } catch (Throwable $e) {
                flash('error', 'No se pudo crear (¿usuario repetido?).');
            }
        }
    }

    if ($action === 'reset') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $resend = !empty($_POST['resend_welcome']);
        if ($uid && $password !== '') {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'student'")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
            $msg = 'Contraseña actualizada.';
            if ($resend) {
                $stmt = $pdo->prepare("SELECT name, username, email FROM users WHERE id = ? AND role = 'student' LIMIT 1");
                $stmt->execute([$uid]);
                $student = $stmt->fetch();
                if ($student && send_welcome_email((string) $student['email'], (string) $student['name'], (string) $student['username'], $password)) {
                    $msg .= ' Mail reenviado a ' . $student['email'] . '.';
                } else {
                    $msg .= ' No se pudo reenviar el mail.';
                }
            }
            flash('success', $msg);
        }
    }

    if ($action === 'toggle') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id = ? AND role='student'")
            ->execute([$uid]);
        flash('success', 'Estado del alumno actualizado.');
    }

    if ($action === 'delete') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid) {
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'")->execute([$uid]);
            flash('success', 'Alumno eliminado.');
        }
    }

    if ($action === 'enroll') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $courseIds = array_map('intval', $_POST['courses'] ?? []);
        if ($uid) {
            $pdo->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$uid]);
            $enroll = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');
            foreach ($courseIds as $cid) {
                if ($cid > 0) {
                    $enroll->execute([$uid, $cid]);
                }
            }
            flash('success', 'Cursos del alumno actualizados.');
        }
    }

    if ($action === 'update_email') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($uid && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ? AND role = 'student'")->execute([$email, $uid]);
            flash('success', 'Email actualizado.');
        } else {
            flash('error', 'Email inválido.');
        }
    }

    if ($action === 'update_profile') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($uid && $name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ? AND role = 'student'")
                ->execute([$name, $email, $uid]);
            flash('success', 'Datos del alumno actualizados.');
        } else {
            flash('error', 'Nombre y email válidos son obligatorios.');
        }
    }

    redirect('users.php');
}

$courses = db()->query('SELECT id, title FROM courses ORDER BY sort_order, title')->fetchAll();
$users = db()->query("
  SELECT u.*,
    (SELECT COUNT(*) FROM enrollments e WHERE e.user_id = u.id) AS course_count,
    (
      SELECT COUNT(*) FROM enrollments e
      JOIN lessons l ON l.course_id = e.course_id
      WHERE e.user_id = u.id
    ) AS total_lessons,
    (
      SELECT COUNT(*) FROM lesson_progress lp
      JOIN lessons l ON l.id = lp.lesson_id
      JOIN enrollments e ON e.course_id = l.course_id AND e.user_id = u.id
      WHERE lp.user_id = u.id
    ) AS done_lessons
  FROM users u
  WHERE u.role = 'student'
  ORDER BY u.created_at DESC
")->fetchAll();

$enrollMap = [];
$rows = db()->query('SELECT user_id, course_id FROM enrollments')->fetchAll();
foreach ($rows as $row) {
    $enrollMap[(int) $row['user_id']][] = (int) $row['course_id'];
}

$pageTitle = 'Alumnos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Gestionar alumnos</h1>
<p class="lede">Altas, bajas, contraseñas y asignación de cursos. Un alumno dado de baja no puede ingresar al campus.</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Métricas</a>
  <a class="btn secondary" href="admins.php">Administradores</a>
  <a class="btn secondary" href="courses.php">Cursos y clases</a>
</nav>

<section class="panel" style="margin-bottom:1.25rem">
  <h2 class="panel-title">Dar de alta un alumno</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="form-grid two">
      <label>Nombre completo <input name="name" required placeholder="Nombre y apellido"></label>
      <label>Email <input type="email" name="email" required placeholder="alumno@email.com"></label>
      <label>Usuario <input name="username" required placeholder="ej: maria.perez" autocomplete="off"></label>
      <label>Contraseña <input name="password" required placeholder="Clave temporal" autocomplete="new-password"></label>
    </div>
    <fieldset class="check-box">
      <legend>Cursos a los que tendrá acceso</legend>
      <div class="check-list">
        <?php foreach ($courses as $course): ?>
          <label class="check">
            <input type="checkbox" name="courses[]" value="<?= (int) $course['id'] ?>">
            <span><?= h($course['title']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (!$courses): ?>
        <p class="muted small">Primero creá cursos en “Cursos y clases”.</p>
      <?php endif; ?>
    </fieldset>
    <label class="check">
      <input type="checkbox" name="send_welcome" value="1" checked>
      <span>Enviar mail de bienvenida con usuario y contraseña</span>
    </label>
    <button class="btn primary" type="submit">Crear alumno</button>
  </form>
</section>

<?php if (!$users): ?>
  <div class="panel empty-state">
    <strong>Todavía no hay alumnos</strong>
    <p class="muted" style="margin:0">Cuando des de alta el primero, va a aparecer acá con su avance y cursos.</p>
  </div>
<?php else: ?>
  <?php foreach ($users as $u): ?>
    <?php
      $uid = (int) $u['id'];
      $total = (int) $u['total_lessons'];
      $done = (int) $u['done_lessons'];
      $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
      $assigned = $enrollMap[$uid] ?? [];
    ?>
    <section class="panel student-card">
      <div>
        <h2><?= h($u['name']) ?></h2>
        <p class="muted small student-meta">
          Usuario: <strong><?= h($u['username']) ?></strong>
          · <?= h((string) ($u['email'] ?? '')) ?: 'sin email' ?>
          · <?= (int) $u['course_count'] ?> cursos
        </p>
        <p>
          <?= (int) $u['active'] ? '<span class="badge done">Activo</span>' : '<span class="badge">Dado de baja</span>' ?>
          <span class="muted small"> · Avance <?= $pct ?>% (<?= $done ?>/<?= $total ?> clases)</span>
        </p>
        <div class="progress" style="max-width:220px;margin-top:.5rem" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
      </div>

      <form method="post" class="stack" style="margin-top:1rem">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <div class="form-grid two">
          <label>Nombre
            <input name="name" required value="<?= h((string) $u['name']) ?>">
          </label>
          <label>Email
            <input type="email" name="email" required value="<?= h((string) ($u['email'] ?? '')) ?>" placeholder="alumno@email.com">
          </label>
        </div>
        <button class="btn secondary" type="submit">Guardar datos</button>
      </form>

      <form method="post" class="stack" style="margin-top:1rem">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="enroll">
        <input type="hidden" name="user_id" value="<?= $uid ?>">
        <fieldset class="check-box">
          <legend>Cursos asignados</legend>
          <div class="check-list">
            <?php foreach ($courses as $course): ?>
              <label class="check">
                <input
                  type="checkbox"
                  name="courses[]"
                  value="<?= (int) $course['id'] ?>"
                  <?= in_array((int) $course['id'], $assigned, true) ? 'checked' : '' ?>
                >
                <span><?= h($course['title']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <button class="btn secondary" type="submit">Guardar cursos</button>
      </form>

      <div class="actions" style="margin-top:1rem">
        <form method="post" class="actions">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="action" value="reset">
          <input type="text" name="password" placeholder="Nueva contraseña" required>
          <label class="check">
            <input type="checkbox" name="resend_welcome" value="1">
            <span>Reenviar mail</span>
          </label>
          <button class="btn secondary" type="submit">Cambiar clave</button>
        </form>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="action" value="toggle">
          <button class="btn secondary" type="submit">
            <?= (int) $u['active'] ? 'Dar de baja' : 'Reactivar' ?>
          </button>
        </form>
        <form method="post" onsubmit="return confirm('¿Eliminar alumno de forma permanente?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="user_id" value="<?= $uid ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn danger" type="submit">Eliminar</button>
        </form>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
