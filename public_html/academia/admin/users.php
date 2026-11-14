<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();

/** Crea un alumno y lo inscribe. Devuelve [id, null] o [null, mensaje de error, id existente]. */
function users_create_student(PDO $pdo, string $name, string $email, string $password, array $courseIds): array
{
    $taken = $pdo->prepare('SELECT id, role FROM users WHERE lower(email) = ? LIMIT 1');
    $taken->execute([$email]);
    if ($row = $taken->fetch()) {
        return [null, 'Ya existe una cuenta con ' . $email . '.', $row['role'] === 'student' ? (int) $row['id'] : 0];
    }
    $pdo->prepare('INSERT INTO users (username, password_hash, name, email, role) VALUES (?, ?, ?, ?, ?)')
        ->execute([unique_username_from_email($email), password_hash($password, PASSWORD_DEFAULT), $name, $email, 'student']);
    $uid = (int) $pdo->lastInsertId();
    users_set_courses($pdo, $uid, $courseIds);
    return [$uid, null, 0];
}

function users_set_courses(PDO $pdo, int $uid, array $courseIds): void
{
    $pdo->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$uid]);
    $enroll = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $courseIds)) as $cid) {
        if ($cid > 0) {
            $enroll->execute([$uid, $cid]);
        }
    }
}

function users_name_from_email(string $email): string
{
    $local = (string) strstr($email, '@', true);
    $words = preg_split('/[._\-+0-9]+/', $local, -1, PREG_SPLIT_NO_EMPTY) ?: [$local];
    return ucwords(strtolower(implode(' ', $words))) ?: $email;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión expiró. Probá de nuevo.');
        redirect('users.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $pdo = db();
    $courseIds = array_map('intval', (array) ($_POST['courses'] ?? []));
    $sendWelcome = !empty($_POST['send_welcome']);

    if ($action === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = trim((string) ($_POST['password'] ?? ''));
        $old = ['name' => $name, 'email' => $email, 'password' => $password, 'courses' => $courseIds, 'send_welcome' => $sendWelcome];
        $error = null;
        $existing = 0;
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingresá un email válido.';
        } elseif ($password !== '' && strlen($password) < 6) {
            $error = 'La contraseña tiene que tener al menos 6 caracteres.';
        } else {
            $name = $name !== '' ? $name : users_name_from_email($email);
            $password = $password !== '' ? $password : aca_generate_password();
            [$uid, $error, $existing] = users_create_student($pdo, $name, $email, $password, $courseIds);
        }
        if ($error !== null) {
            $_SESSION['users_form'] = ['dialog' => 'dlg-new', 'old' => $old, 'error' => $error, 'existing' => $existing];
            redirect('users.php');
        }
        $mail = $sendWelcome ? send_welcome_email($email, $name, $email, $password) : null;
        $_SESSION['users_creds'] = [['name' => $name, 'email' => $email, 'password' => $password, 'mail' => $mail]];
        flash('success', $name . ' ya puede entrar al Campus.');
        redirect('users.php');
    }

    if ($action === 'bulk') {
        $lines = preg_split('/\r?\n/', (string) ($_POST['lines'] ?? '')) ?: [];
        $creds = [];
        $skipped = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line, $m)) {
                $skipped[] = $line . ' (sin email)';
                continue;
            }
            $email = strtolower($m[0]);
            $name = trim((string) preg_replace('/[<>,;:\t]+/', ' ', str_replace($m[0], '', $line)));
            $name = trim((string) preg_replace('/\s+/', ' ', $name)) ?: users_name_from_email($email);
            $password = aca_generate_password();
            [$uid, $error] = users_create_student($pdo, $name, $email, $password, $courseIds);
            if ($error !== null) {
                $skipped[] = $email . ' (ya existe)';
                continue;
            }
            $mail = $sendWelcome ? send_welcome_email($email, $name, $email, $password) : null;
            $creds[] = ['name' => $name, 'email' => $email, 'password' => $password, 'mail' => $mail];
        }
        if ($creds) {
            $_SESSION['users_creds'] = $creds;
        }
        $msg = count($creds) . (count($creds) === 1 ? ' alumno creado.' : ' alumnos creados.');
        if ($skipped) {
            $msg .= ' Omitidos: ' . implode(', ', $skipped) . '.';
        }
        flash($creds ? 'success' : 'error', $msg);
        redirect('users.php');
    }

    $uid = (int) ($_POST['user_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$uid]);
    $student = $stmt->fetch();
    if (!$student) {
        flash('error', 'No encontramos ese alumno.');
        redirect('users.php');
    }

    if ($action === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $taken = $pdo->prepare('SELECT 1 FROM users WHERE lower(email) = ? AND id <> ? LIMIT 1');
        $taken->execute([$email, $uid]);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Nombre y un email válido son obligatorios.');
        } elseif ($taken->fetch()) {
            flash('error', 'Ese email ya lo usa otra cuenta.');
        } else {
            $active = !empty($_POST['active']) ? 1 : 0;
            $pdo->prepare('UPDATE users SET name = ?, email = ?, active = ? WHERE id = ?')->execute([$name, $email, $active, $uid]);
            users_set_courses($pdo, $uid, $courseIds);
            flash('success', 'Cambios guardados para ' . $name . '.');
        }
    }

    if ($action === 'reset') {
        $password = trim((string) ($_POST['password'] ?? ''));
        if ($password !== '' && strlen($password) < 6) {
            flash('error', 'La contraseña tiene que tener al menos 6 caracteres.');
        } else {
            $password = $password !== '' ? $password : aca_generate_password();
            $pdo->prepare('UPDATE users SET password_hash = ?, active = 1 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
            $email = (string) ($student['email'] ?: $student['username']);
            $mail = !empty($_POST['resend_welcome']) && $student['email'] !== ''
                ? send_welcome_email((string) $student['email'], (string) $student['name'], (string) $student['email'], $password)
                : null;
            $_SESSION['users_creds'] = [['name' => (string) $student['name'], 'email' => $email, 'password' => $password, 'mail' => $mail]];
            flash('success', 'Nueva contraseña para ' . $student['name'] . '.');
        }
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'student'")->execute([$uid]);
        flash('success', $student['name'] . ' fue eliminado.');
    }

    redirect('users.php');
}

$pdo = db();
$courses = $pdo->query('SELECT id, title FROM courses ORDER BY sort_order, title')->fetchAll();
$courseTitles = array_column($courses, 'title', 'id');
$users = $pdo->query("
  SELECT u.id, u.name, u.email, u.username, u.active, u.created_at, u.last_login_at,
    (
      SELECT COUNT(*) FROM enrollments e
      JOIN course_resources r ON r.course_id = e.course_id AND r.track_completion = 1
      WHERE e.user_id = u.id
    ) AS total_lessons,
    (
      SELECT COUNT(*) FROM resource_progress rp
      JOIN course_resources r ON r.id = rp.resource_id AND r.track_completion = 1
      JOIN enrollments e ON e.course_id = r.course_id AND e.user_id = u.id
      WHERE rp.user_id = u.id
    ) AS done_lessons
  FROM users u
  WHERE u.role = 'student'
  ORDER BY u.created_at DESC, u.id DESC
")->fetchAll();
$enrollMap = [];
foreach ($pdo->query('SELECT user_id, course_id FROM enrollments')->fetchAll() as $row) {
    $enrollMap[(int) $row['user_id']][] = (int) $row['course_id'];
}
$activeCount = count(array_filter($users, static fn ($u) => (int) $u['active'] === 1));

$form = $_SESSION['users_form'] ?? null;
unset($_SESSION['users_form']);
$creds = $_SESSION['users_creds'] ?? [];
unset($_SESSION['users_creds']);
$old = $form['old'] ?? ['name' => '', 'email' => '', 'password' => '', 'courses' => [], 'send_welcome' => true];

$pageTitle = 'Alumnos';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';

$coursePicks = static function (string $prefix, array $checked) use ($courses): string {
    if (!$courses) {
        return '<p class="hint">Todavía no hay cursos. Crealos en <a href="courses.php">Cursos</a>.</p>';
    }
    $out = '<div class="course-picks">';
    foreach ($courses as $c) {
        $id = (int) $c['id'];
        $out .= '<label><input type="checkbox" name="courses[]" value="' . $id . '" data-course="' . $id . '"'
            . (in_array($id, $checked, true) ? ' checked' : '') . '>' . aca_icon('check', 'ico') . h($c['title']) . '</label>';
    }
    return $out . '</div>';
};
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administración</p>
    <h1>Alumnos</h1>
    <p class="lede">Agregá alumnos con su email y una contraseña. Con esos datos entran al Campus y ven las clases grabadas de los cursos que les asignes.</p>
  </div>
  <div class="actions">
    <button class="btn secondary" type="button" data-open-dialog="dlg-bulk"><?= aca_icon('users') ?> Agregar varios</button>
    <button class="btn primary" type="button" data-open-dialog="dlg-new"><?= aca_icon('plus') ?> Nuevo alumno</button>
  </div>
</div>

<?php if ($creds): ?>
  <section class="creds" id="creds">
    <button class="icon-btn creds-close" type="button" onclick="this.parentElement.remove()" aria-label="Cerrar"><?= aca_icon('x') ?></button>
    <h2><?= count($creds) === 1 ? 'Datos de acceso' : 'Datos de acceso (' . count($creds) . ')' ?></h2>
    <p>Guardalos o mandáselos ahora: por seguridad, la contraseña no se vuelve a mostrar.</p>
    <div class="creds-grid">
      <?php foreach ($creds as $i => $c): ?>
        <?php $msg = aca_access_message($c['name'], $c['email'], $c['password']); ?>
        <div class="creds-item">
          <dl>
            <div><dt>Alumno</dt><dd><?= h($c['name']) ?></dd></div>
            <div><dt>Email</dt><dd><?= h($c['email']) ?></dd></div>
            <div><dt>Contraseña</dt><dd><?= h($c['password']) ?></dd></div>
          </dl>
          <div class="creds-actions">
            <textarea id="cred-msg-<?= $i ?>" hidden><?= h($msg) ?></textarea>
            <button class="btn secondary small-btn" type="button" data-copy="cred-msg-<?= $i ?>"><?= aca_icon('copy') ?> Copiar</button>
            <a class="btn whatsapp small-btn" href="https://wa.me/?text=<?= rawurlencode($msg) ?>" target="_blank" rel="noopener"><?= aca_icon('whatsapp') ?> WhatsApp</a>
          </div>
          <?php if ($c['mail'] === true): ?>
            <p class="creds-mail"><?= aca_icon('mail') ?> Le enviamos un mail de bienvenida con estos datos.</p>
          <?php elseif ($c['mail'] === false): ?>
            <p class="creds-mail"><?= aca_icon('x') ?> El mail no se pudo enviar: mandale los datos por WhatsApp.</p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<div class="toolbar">
  <label class="search">
    <?= aca_icon('search') ?>
    <input type="search" placeholder="Buscar por nombre o email" aria-label="Buscar alumno" data-filter="[data-student]" data-filter-empty="#no-results">
  </label>
  <div class="seg" role="group" aria-label="Filtrar por estado">
    <button type="button" class="is-on" data-filter-status="all">Todos <?= count($users) ?></button>
    <button type="button" data-filter-status="1">Activos <?= $activeCount ?></button>
    <button type="button" data-filter-status="0">De baja <?= count($users) - $activeCount ?></button>
  </div>
</div>

<section class="panel data-card">
  <?php if (!$users): ?>
    <div class="empty-state">
      <strong>Todavía no hay alumnos</strong>
      <p class="muted">Agregá el primero con su email: le llega un mail con sus datos para entrar.</p>
      <button class="btn primary" type="button" data-open-dialog="dlg-new"><?= aca_icon('plus') ?> Nuevo alumno</button>
    </div>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr><th>Alumno</th><th>Cursos</th><th>Avance</th><th>Último ingreso</th><th>Estado</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <?php
            $uid = (int) $u['id'];
            $total = (int) $u['total_lessons'];
            $done = (int) $u['done_lessons'];
            $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
            $assigned = $enrollMap[$uid] ?? [];
            $payload = [
                'id' => $uid,
                'name' => (string) $u['name'],
                'email' => (string) $u['email'],
                'login' => (string) ($u['email'] ?: $u['username']),
                'active' => (int) $u['active'] === 1,
                'courses' => $assigned,
            ];
          ?>
          <tr data-student data-status="<?= (int) $u['active'] ?>" data-search="<?= h($u['name'] . ' ' . $u['email'] . ' ' . $u['username']) ?>">
            <td>
              <div class="who">
                <span class="avatar sm"><?= h(aca_initial((string) $u['name'])) ?></span>
                <span><strong><?= h($u['name']) ?></strong><span><?= h((string) ($u['email'] ?: $u['username'])) ?></span></span>
              </div>
            </td>
            <td data-label="Cursos">
              <?php if ($assigned): ?>
                <div class="chips"><?php foreach ($assigned as $cid): ?><span class="chip"><?= h($courseTitles[$cid] ?? 'Curso') ?></span><?php endforeach; ?></div>
              <?php else: ?>
                <span class="muted-dash">Sin cursos</span>
              <?php endif; ?>
            </td>
            <td data-label="Avance">
              <div class="mini-progress"><div class="progress"><span style="width:<?= $pct ?>%"></span></div><b><?= $pct ?>%</b></div>
            </td>
            <td data-label="Último ingreso"><span class="muted"><?= h(aca_time_ago($u['last_login_at'] ?? null)) ?></span></td>
            <td data-label="Estado"><?= (int) $u['active'] ? '<span class="badge soft">Activo</span>' : '<span class="badge off">De baja</span>' ?></td>
            <td style="text-align:right">
              <button class="btn secondary small-btn" type="button" data-edit-user='<?= h(json_encode($payload, JSON_UNESCAPED_UNICODE)) ?>'><?= aca_icon('pen') ?> Editar</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="empty-row" id="no-results" hidden>No hay alumnos que coincidan con la búsqueda.</p>
  <?php endif; ?>
</section>

<dialog class="modal" id="dlg-new" aria-labelledby="dlg-new-title">
  <form method="post">
    <div class="modal-head">
      <span class="avatar"><?= aca_icon('user') ?></span>
      <div>
        <h2 id="dlg-new-title">Nuevo alumno</h2>
        <p>Entra al Campus con este email y la contraseña.</p>
      </div>
      <button class="icon-btn" type="button" data-close-dialog aria-label="Cerrar"><?= aca_icon('x') ?></button>
    </div>
    <div class="modal-body">
      <?php if ($form && $form['dialog'] === 'dlg-new'): ?>
        <div class="alert error" style="margin:0">
          <?= h($form['error']) ?>
          <?php if (!empty($form['existing'])): ?>
            <button class="btn secondary small-btn" type="button" style="margin-left:.4rem" data-edit-existing="<?= (int) $form['existing'] ?>">Editar ese alumno</button>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="create">
      <label>Email del alumno
        <input type="email" name="email" required placeholder="alumno@email.com" autocomplete="off" value="<?= h($old['email']) ?>">
      </label>
      <label>Nombre y apellido
        <input name="name" placeholder="Si lo dejás vacío, lo tomamos del email" value="<?= h($old['name']) ?>">
      </label>
      <label>Contraseña
        <span class="inline-field">
          <input name="password" id="new-pass" minlength="6" placeholder="Vacío = la generamos automáticamente" autocomplete="new-password" value="<?= h($old['password']) ?>">
          <button class="btn secondary" type="button" data-gen-pass="new-pass"><?= aca_icon('refresh') ?> Generar</button>
        </span>
      </label>
      <div class="modal-section">
        <h3><?= aca_icon('book') ?> Cursos que puede ver</h3>
        <?= $coursePicks('new', array_map('intval', $old['courses'])) ?>
      </div>
      <label class="check">
        <input type="checkbox" name="send_welcome" value="1"<?= !empty($old['send_welcome']) ? ' checked' : '' ?>>
        <span>Enviarle un mail de bienvenida con sus datos de acceso</span>
      </label>
      <div class="modal-foot">
        <button class="btn ghost" type="button" data-close-dialog>Cancelar</button>
        <button class="btn primary" type="submit"><?= aca_icon('check') ?> Crear alumno</button>
      </div>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-bulk" aria-labelledby="dlg-bulk-title">
  <form method="post">
    <div class="modal-head">
      <span class="avatar"><?= aca_icon('users') ?></span>
      <div>
        <h2 id="dlg-bulk-title">Agregar varios alumnos</h2>
        <p>Uno por línea. A cada uno le generamos su contraseña.</p>
      </div>
      <button class="icon-btn" type="button" data-close-dialog aria-label="Cerrar"><?= aca_icon('x') ?></button>
    </div>
    <div class="modal-body">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="bulk">
      <label>Emails
        <textarea name="lines" rows="6" required placeholder="maria@gmail.com&#10;Juan Pérez, juan.perez@hotmail.com&#10;Ana Gómez &lt;ana@yahoo.com&gt;"></textarea>
      </label>
      <p class="hint">Podés pegar solo el email o "Nombre, email". Los emails que ya tienen cuenta se saltean.</p>
      <div class="modal-section">
        <h3><?= aca_icon('book') ?> Cursos que pueden ver</h3>
        <?= $coursePicks('bulk', []) ?>
      </div>
      <label class="check">
        <input type="checkbox" name="send_welcome" value="1" checked>
        <span>Enviar a cada uno un mail de bienvenida con sus datos</span>
      </label>
      <div class="modal-foot">
        <button class="btn ghost" type="button" data-close-dialog>Cancelar</button>
        <button class="btn primary" type="submit"><?= aca_icon('check') ?> Crear alumnos</button>
      </div>
    </div>
  </form>
</dialog>

<dialog class="modal" id="dlg-edit" aria-labelledby="dlg-edit-title">
  <div class="modal-head">
    <span class="avatar" data-f="initial"></span>
    <div>
      <h2 id="dlg-edit-title" data-f="title">Alumno</h2>
      <p>Ingresa con <strong data-f="login"></strong></p>
    </div>
    <button class="icon-btn" type="button" data-close-dialog aria-label="Cerrar"><?= aca_icon('x') ?></button>
  </div>
  <div class="modal-body">
    <form method="post" class="stack" id="edit-form">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="user_id" data-f="id">
      <div class="form-grid two">
        <label>Nombre <input name="name" required data-f="name"></label>
        <label>Email <input type="email" name="email" required data-f="email"></label>
      </div>
      <div class="modal-section">
        <h3><?= aca_icon('book') ?> Cursos que puede ver</h3>
        <?= $coursePicks('edit', []) ?>
      </div>
      <label class="check">
        <input type="checkbox" name="active" value="1" data-f="active">
        <span>Cuenta activa (si la desactivás, no puede entrar al Campus)</span>
      </label>
      <div class="modal-foot">
        <button class="btn primary" type="submit"><?= aca_icon('check') ?> Guardar cambios</button>
      </div>
    </form>

    <form method="post" class="modal-section">
      <h3><?= aca_icon('lock') ?> Nueva contraseña</h3>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="reset">
      <input type="hidden" name="user_id" data-f="id">
      <span class="inline-field">
        <input name="password" id="reset-pass" minlength="6" placeholder="Vacío = la generamos automáticamente" autocomplete="new-password">
        <button class="btn secondary" type="button" data-gen-pass="reset-pass"><?= aca_icon('refresh') ?> Generar</button>
      </span>
      <label class="check">
        <input type="checkbox" name="resend_welcome" value="1" checked>
        <span>Enviarle por mail los datos nuevos</span>
      </label>
      <div class="modal-foot">
        <button class="btn secondary" type="submit"><?= aca_icon('key') ?> Cambiar contraseña</button>
      </div>
    </form>

    <form method="post" class="modal-section" onsubmit="return confirm('¿Eliminar a este alumno para siempre? Se borra su avance y sus consultas.');">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="user_id" data-f="id">
      <div class="modal-foot">
        <button class="btn danger" type="submit"><?= aca_icon('trash') ?> Eliminar alumno</button>
        <button class="btn ghost" type="button" data-close-dialog>Cerrar</button>
      </div>
    </form>
  </div>
</dialog>

<script>
(function () {
  var dlg = document.getElementById('dlg-edit');
  function openEdit(u) {
    dlg.querySelectorAll('[data-f="id"]').forEach(function (i) { i.value = u.id; });
    dlg.querySelector('[data-f="name"]').value = u.name;
    dlg.querySelector('[data-f="email"]').value = u.email;
    dlg.querySelector('[data-f="active"]').checked = u.active;
    dlg.querySelector('[data-f="title"]').textContent = u.name;
    dlg.querySelector('[data-f="login"]').textContent = u.login;
    dlg.querySelector('[data-f="initial"]').textContent = (u.name || '?').trim().charAt(0).toUpperCase();
    dlg.querySelector('#reset-pass').value = '';
    dlg.querySelectorAll('[data-course]').forEach(function (c) {
      c.checked = u.courses.indexOf(Number(c.getAttribute('data-course'))) !== -1;
    });
    dlg.showModal();
  }
  document.querySelectorAll('[data-edit-user]').forEach(function (btn) {
    btn.addEventListener('click', function () { openEdit(JSON.parse(btn.getAttribute('data-edit-user'))); });
  });
  var existing = document.querySelector('[data-edit-existing]');
  if (existing) {
    existing.addEventListener('click', function () {
      var id = Number(existing.getAttribute('data-edit-existing'));
      document.getElementById('dlg-new').close();
      document.querySelectorAll('[data-edit-user]').forEach(function (b) {
        var u = JSON.parse(b.getAttribute('data-edit-user'));
        if (u.id === id) { openEdit(u); }
      });
    });
  }
  <?php if ($form): ?>
  document.getElementById(<?= json_encode($form['dialog']) ?>).showModal();
  <?php endif; ?>
  if (location.search.indexOf('nuevo=1') !== -1 && !document.querySelector('dialog[open]')) {
    document.getElementById('dlg-new').showModal();
  }
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
