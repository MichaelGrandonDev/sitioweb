<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$me = require_full_admin();
$pdo = db();

$allCourses = $pdo->query('SELECT id, title FROM courses ORDER BY sort_order, title')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('admins.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_admin') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || $password === '' || $name === '') {
            flash('error', 'Completá nombre, usuario y contraseña.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Email inválido.');
        } elseif (strlen($password) < 6) {
            flash('error', 'La contraseña debe tener al menos 6 caracteres.');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO users (username, password_hash, name, email, role, active) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$username, $hash, $name, $email, 'admin']);
                flash('success', 'Administrador general creado: ' . $username);
            } catch (Throwable $e) {
                flash('error', 'No se pudo crear (¿usuario repetido?).');
            }
        }
    }

    if ($action === 'create_teacher') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $courseIds = array_map('intval', (array) ($_POST['courses'] ?? []));
        $flags = [];
        foreach (TEACHER_PERMISSIONS as $key => $_) {
            $flags[$key] = !empty($_POST['perm'][$key]) ? 1 : 0;
        }

        if ($username === '' || $password === '' || $name === '') {
            flash('error', 'Completá nombre, usuario y contraseña del profesor.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Email inválido.');
        } elseif (strlen($password) < 6) {
            flash('error', 'La contraseña debe tener al menos 6 caracteres.');
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO users (username, password_hash, name, email, role, active) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$username, $hash, $name, $email, 'teacher']);
                $uid = (int) $pdo->lastInsertId();
                teacher_permissions_save($uid, $flags);
                teacher_courses_set($uid, $courseIds);
                flash('success', 'Profesor creado: ' . $username . '. Entra por el mismo login de administración.');
            } catch (Throwable $e) {
                flash('error', 'No se pudo crear (¿usuario repetido?).');
            }
        }
    }

    if ($action === 'update_teacher') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $courseIds = array_map('intval', (array) ($_POST['courses'] ?? []));
        $flags = [];
        foreach (TEACHER_PERMISSIONS as $key => $_) {
            $flags[$key] = !empty($_POST['perm'][$key]) ? 1 : 0;
        }
        $row = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher'");
        $row->execute([$uid]);
        if (!$row->fetch() || $name === '') {
            flash('error', 'Profesor no encontrado o nombre vacío.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Email inválido.');
        } else {
            $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ? AND role = ?')
                ->execute([$name, $email, $uid, 'teacher']);
            teacher_permissions_save($uid, $flags);
            teacher_courses_set($uid, $courseIds);
            flash('success', 'Profesor actualizado (datos, permisos y cursos).');
        }
    }

    if ($action === 'reset') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? 'admin');
        if (!in_array($role, ['admin', 'teacher'], true)) {
            $role = 'admin';
        }
        if ($uid > 0 && strlen($password) >= 6) {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND role = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid, $role]);
            flash('success', 'Contraseña actualizada.');
        } else {
            flash('error', 'Contraseña inválida (mínimo 6 caracteres).');
        }
    }

    if ($action === 'toggle') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'admin');
        if (!in_array($role, ['admin', 'teacher'], true)) {
            $role = 'admin';
        }
        if ($uid === (int) $me['id']) {
            flash('error', 'No podés desactivar tu propia cuenta.');
        } elseif ($uid > 0) {
            if ($role === 'admin') {
                $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
                $row = $pdo->prepare("SELECT active FROM users WHERE id = ? AND role = 'admin'");
                $row->execute([$uid]);
                $target = $row->fetch();
                if ($target && (int) $target['active'] === 1 && $activeCount <= 1) {
                    flash('error', 'Debe quedar al menos un administrador activo.');
                    redirect('admins.php');
                }
            }
            $pdo->prepare('UPDATE users SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id = ? AND role = ?')
                ->execute([$uid, $role]);
            flash('success', 'Estado actualizado.');
        }
    }

    if ($action === 'delete') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? 'admin');
        if (!in_array($role, ['admin', 'teacher'], true)) {
            $role = 'admin';
        }
        if ($uid === (int) $me['id']) {
            flash('error', 'No podés eliminar tu propia cuenta.');
        } elseif ($uid > 0) {
            if ($role === 'admin') {
                $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
                $row = $pdo->prepare("SELECT active FROM users WHERE id = ? AND role = 'admin'");
                $row->execute([$uid]);
                $target = $row->fetch();
                if ($target && (int) $target['active'] === 1 && $activeCount <= 1) {
                    flash('error', 'No se puede eliminar el único administrador activo.');
                    redirect('admins.php');
                }
            }
            $pdo->prepare('DELETE FROM users WHERE id = ? AND role = ?')->execute([$uid, $role]);
            flash('success', $role === 'teacher' ? 'Profesor eliminado.' : 'Administrador eliminado.');
        }
    }

    redirect('admins.php');
}

$admins = $pdo->query("
  SELECT id, username, name, email, active, created_at
  FROM users
  WHERE role = 'admin'
  ORDER BY id ASC
")->fetchAll();

$teachers = $pdo->query("
  SELECT id, username, name, email, active, created_at
  FROM users
  WHERE role = 'teacher'
  ORDER BY name ASC, id ASC
")->fetchAll();

$teacherCourses = [];
$teacherPerms = [];
foreach ($teachers as $t) {
    $tid = (int) $t['id'];
    $teacherPerms[$tid] = teacher_permissions_of($tid);
    $cst = $pdo->prepare('SELECT course_id FROM course_teachers WHERE user_id = ?');
    $cst->execute([$tid]);
    $teacherCourses[$tid] = array_map('intval', $cst->fetchAll(PDO::FETCH_COLUMN));
}

$pageTitle = 'Administradores y profesores';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Administradores / Profesores</h1>
<p class="lede">
  El <strong>administrador general</strong> tiene acceso total (alumnos, pagos, inscripciones, sitio).
  Un <strong>profesor</strong> entra al mismo login de administración, pero solo ve y edita los cursos que le asignes y los permisos que marques.
</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
  <a class="btn secondary" href="#profesores">Profesores</a>
</nav>

<section class="panel" style="margin-bottom:1.25rem">
  <h2 class="panel-title">Nuevo administrador general</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_admin">
    <div class="form-grid two">
      <label>Nombre completo <input name="name" required placeholder="Nombre y apellido"></label>
      <label>Email <input type="email" name="email" placeholder="opcional"></label>
      <label>Usuario <input name="username" required autocomplete="off" placeholder="ej: laura.admin"></label>
      <label>Contraseña <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
    </div>
    <button class="btn primary" type="submit">Crear administrador</button>
  </form>
</section>

<section class="panel" style="margin-bottom:1.25rem">
  <h2 class="panel-title">Administradores generales</h2>
  <?php if (!$admins): ?>
    <p class="muted">No hay administradores.</p>
  <?php else: ?>
    <?php foreach ($admins as $a): ?>
      <?php $isMe = (int) $a['id'] === (int) $me['id']; ?>
      <article class="lesson-card" style="margin-bottom:.75rem;align-items:flex-start">
        <div>
          <h3 style="margin:0;font-size:1.15rem">
            <?= h($a['name']) ?>
            <?php if ($isMe): ?><span class="badge done">Vos</span><?php endif; ?>
            <?php if (!(int) $a['active']): ?><span class="badge">Inactivo</span><?php else: ?><span class="badge done">Activo</span><?php endif; ?>
          </h3>
          <p class="muted small" style="margin:.25rem 0 0">
            @<?= h($a['username']) ?>
            <?= $a['email'] !== '' ? ' · ' . h($a['email']) : '' ?>
            · Acceso total
          </p>
          <form method="post" class="stack" style="margin-top:.75rem;max-width:20rem">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="role" value="admin">
            <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
            <label>Nueva contraseña
              <input type="password" name="password" minlength="6" required placeholder="Mínimo 6 caracteres" autocomplete="new-password">
            </label>
            <button class="btn secondary small-btn" type="submit">Cambiar contraseña</button>
          </form>
        </div>
        <div class="actions" style="flex-direction:column;align-items:stretch">
          <?php if (!$isMe): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="role" value="admin">
              <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
              <button class="btn secondary small-btn" type="submit">
                <?= (int) $a['active'] ? 'Desactivar' : 'Activar' ?>
              </button>
            </form>
            <form method="post" onsubmit="return confirm('¿Eliminar este administrador?')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="role" value="admin">
              <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
              <button class="btn danger small-btn" type="submit">Eliminar</button>
            </form>
          <?php else: ?>
            <p class="muted small">No podés desactivar ni borrar tu propia cuenta.</p>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="panel" id="profesores" style="margin-bottom:1.25rem">
  <h2 class="panel-title">Nuevo profesor (subadministrador)</h2>
  <p class="muted small">Entra por el <a href="../login.php?as=profesor">Portal de profesores</a>. Solo verá lo que marques abajo.</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_teacher">
    <div class="form-grid two">
      <label>Nombre completo <input name="name" required placeholder="Nombre y apellido"></label>
      <label>Email <input type="email" name="email" placeholder="opcional"></label>
      <label>Usuario <input name="username" required autocomplete="off" placeholder="ej: ana.profesor"></label>
      <label>Contraseña <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
    </div>

    <fieldset class="teacher-fieldset">
      <legend>Cursos asignados</legend>
      <p class="muted small" style="margin:0 0 .6rem">El profesor solo puede editar estos cursos. Si el curso no tiene nombre de profesor, se completa con el suyo.</p>
      <?php if (!$allCourses): ?>
        <p class="muted">Todavía no hay cursos. Creá uno en Cursos.</p>
      <?php else: ?>
        <div class="form-grid two">
          <?php foreach ($allCourses as $c): ?>
            <label class="check">
              <input type="checkbox" name="courses[]" value="<?= (int) $c['id'] ?>">
              <?= h($c['title']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </fieldset>

    <fieldset class="teacher-fieldset">
      <legend>Permisos</legend>
      <div class="form-grid two">
        <?php foreach (TEACHER_PERMISSIONS as $key => $meta): ?>
          <label class="check">
            <input type="checkbox" name="perm[<?= h($key) ?>]" value="1"<?= !empty($meta['default']) ? ' checked' : '' ?>>
            <?= h($meta['label']) ?>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <button class="btn primary" type="submit">Crear profesor</button>
  </form>
</section>

<section class="panel">
  <h2 class="panel-title">Profesores actuales</h2>
  <?php if (!$teachers): ?>
    <p class="muted">Todavía no hay profesores. Creá el primero arriba.</p>
  <?php else: ?>
    <?php foreach ($teachers as $t): ?>
      <?php
        $tid = (int) $t['id'];
        $perms = $teacherPerms[$tid] ?? teacher_permission_defaults();
        $assigned = $teacherCourses[$tid] ?? [];
      ?>
      <article class="lesson-card" style="margin-bottom:1.25rem;align-items:flex-start;flex-direction:column">
        <div style="width:100%">
          <h3 style="margin:0;font-size:1.15rem">
            <?= h($t['name']) ?>
            <?php if (!(int) $t['active']): ?><span class="badge">Inactivo</span><?php else: ?><span class="badge done">Activo</span><?php endif; ?>
            <span class="badge">Profesor</span>
          </h3>
          <p class="muted small" style="margin:.25rem 0 .75rem">
            @<?= h($t['username']) ?>
            <?= $t['email'] !== '' ? ' · ' . h($t['email']) : '' ?>
          </p>

          <form method="post" class="stack">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_teacher">
            <input type="hidden" name="user_id" value="<?= $tid ?>">
            <div class="form-grid two">
              <label>Nombre <input name="name" required value="<?= h($t['name']) ?>"></label>
              <label>Email <input type="email" name="email" value="<?= h((string) $t['email']) ?>"></label>
            </div>

            <fieldset class="teacher-fieldset">
              <legend>Cursos asignados</legend>
              <div class="form-grid two">
                <?php foreach ($allCourses as $c): ?>
                  <label class="check">
                    <input type="checkbox" name="courses[]" value="<?= (int) $c['id'] ?>"<?= in_array((int) $c['id'], $assigned, true) ? ' checked' : '' ?>>
                    <?= h($c['title']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>

            <fieldset class="teacher-fieldset">
              <legend>Permisos</legend>
              <div class="form-grid two">
                <?php foreach (TEACHER_PERMISSIONS as $key => $meta): ?>
                  <label class="check">
                    <input type="checkbox" name="perm[<?= h($key) ?>]" value="1"<?= !empty($perms[$key]) ? ' checked' : '' ?>>
                    <?= h($meta['label']) ?>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>

            <button class="btn primary" type="submit">Guardar profesor</button>
          </form>

          <form method="post" class="stack" style="margin-top:.75rem;max-width:20rem">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="role" value="teacher">
            <input type="hidden" name="user_id" value="<?= $tid ?>">
            <label>Nueva contraseña
              <input type="password" name="password" minlength="6" required placeholder="Mínimo 6 caracteres" autocomplete="new-password">
            </label>
            <button class="btn secondary small-btn" type="submit">Cambiar contraseña</button>
          </form>
        </div>
        <div class="actions" style="margin-top:.75rem">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="role" value="teacher">
            <input type="hidden" name="user_id" value="<?= $tid ?>">
            <button class="btn secondary small-btn" type="submit">
              <?= (int) $t['active'] ? 'Desactivar' : 'Activar' ?>
            </button>
          </form>
          <form method="post" onsubmit="return confirm('¿Eliminar este profesor?')">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="role" value="teacher">
            <input type="hidden" name="user_id" value="<?= $tid ?>">
            <button class="btn danger small-btn" type="submit">Eliminar</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<p class="muted small" style="margin-top:1rem">
  Entran por el <a href="../login.php?as=profesor">Portal de profesores</a>.
</p>
<?php require __DIR__ . '/../includes/footer.php'; ?>
