<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$user = require_teacher_portal();
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Probá de nuevo.';
    } else {
        $action = (string) ($_POST['action'] ?? 'profile');

        if ($action === 'profile') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $bio = trim((string) ($_POST['bio'] ?? ''));
            $syncCourses = !empty($_POST['sync_courses']);

            if ($name === '') {
                $error = 'El nombre no puede quedar vacío.';
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'El email no parece válido.';
            } else {
                $oldAvatar = (string) ($user['avatar_path'] ?? '');
                $pdo->prepare('UPDATE users SET name = ?, email = ?, bio = ? WHERE id = ?')
                    ->execute([$name, $email, $bio, (int) $user['id']]);

                $avatar = store_user_avatar((int) $user['id'], $_FILES['avatar'] ?? []);
                if ($avatar['ok']) {
                    $pdo->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')
                        ->execute([$avatar['path'], (int) $user['id']]);
                    delete_upload($oldAvatar);
                    $oldAvatar = $avatar['path'];
                } elseif (!empty($_POST['remove_avatar']) && $oldAvatar !== '') {
                    $pdo->prepare("UPDATE users SET avatar_path = '' WHERE id = ?")->execute([(int) $user['id']]);
                    delete_upload($oldAvatar);
                    $oldAvatar = '';
                }

                if (($avatar['error'] ?? '') !== '') {
                    flash('error', 'Datos guardados, pero la foto no se cambió: ' . $avatar['error']);
                } else {
                    flash('success', 'Perfil actualizado.');
                }

                // Opcional: volcar nombre/bio/foto a los cursos asignados (lo que ven los alumnos).
                if ($syncCourses && user_has_permission($user, 'edit_course_info')) {
                    $courseIds = teacher_course_ids($user);
                    foreach ($courseIds as $cid) {
                        $pdo->prepare('UPDATE courses SET teacher_name = ?, teacher_bio = ? WHERE id = ?')
                            ->execute([$name, $bio, $cid]);
                        if ($oldAvatar !== '') {
                            // Copia la foto de perfil al path del curso solo si el alumno la ve por curso.
                            $course = $pdo->prepare('SELECT teacher_photo_path FROM courses WHERE id = ?');
                            $course->execute([$cid]);
                            $prev = (string) ($course->fetchColumn() ?: '');
                            $src = resolve_upload_path($oldAvatar);
                            if ($src !== null) {
                                $dir = uploads_root() . '/course_' . $cid;
                                if (!is_dir($dir)) {
                                    mkdir($dir, 0755, true);
                                }
                                $destName = 'profesor-perfil-' . bin2hex(random_bytes(3)) . '.' . pathinfo($src, PATHINFO_EXTENSION);
                                if (@copy($src, $dir . '/' . $destName)) {
                                    $pdo->prepare('UPDATE courses SET teacher_photo_path = ? WHERE id = ?')
                                        ->execute(['course_' . $cid . '/' . $destName, $cid]);
                                    if ($prev !== '' && $prev !== 'course_' . $cid . '/' . $destName) {
                                        delete_upload($prev);
                                    }
                                }
                            }
                        }
                    }
                    flash('success', 'Perfil actualizado y aplicado a tus cursos.');
                }

                redirect('perfil.php');
            }
        }

        if ($action === 'password') {
            $current = (string) ($_POST['current'] ?? '');
            $next = (string) ($_POST['next'] ?? '');
            $repeat = (string) ($_POST['repeat'] ?? '');
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify($current, $hash)) {
                $error = 'La contraseña actual no es correcta.';
            } elseif (strlen($next) < 8) {
                $error = 'La contraseña nueva tiene que tener al menos 8 caracteres.';
            } elseif ($next !== $repeat) {
                $error = 'Las dos contraseñas nuevas no coinciden.';
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
                flash('success', 'Contraseña nueva activa.');
                redirect('perfil.php');
            }
        }
    }
    $user = current_user() ?? $user;
}

$courses = staff_courses($user);
$avatarUrl = user_avatar_url($user);
$perms = teacher_permissions_of((int) $user['id']);

$pageTitle = 'Mi perfil';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Portal profesores · AcademiaFluxus</p>
    <h1>Mi perfil</h1>
    <p class="lede">Tu presentación en la plataforma. Si querés, también se refleja en la ficha “Tu profesor” de tus cursos.</p>
  </div>
</div>

<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start;gap:1.25rem">
  <section class="panel">
    <h2 style="font-size:1.45rem;margin:0 0 1rem">Datos públicos</h2>
    <form method="post" class="stack" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="profile">
      <div class="cover-field" style="margin-bottom:.5rem">
        <?php if ($avatarUrl !== ''): ?>
          <img class="cover-preview" src="<?= h($avatarUrl) ?>" alt="Tu foto" width="120" height="120" style="border-radius:50%;width:120px;height:120px;object-fit:cover">
        <?php else: ?>
          <span class="avatar lg" style="width:120px;height:120px;font-size:2.2rem"><?= h(aca_initial((string) $user['name'])) ?></span>
        <?php endif; ?>
        <div class="stack">
          <label>Foto de perfil <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"></label>
          <?php if ($avatarUrl !== ''): ?>
            <label class="check"><input type="checkbox" name="remove_avatar" value="1"> Quitar la foto</label>
          <?php endif; ?>
        </div>
      </div>
      <label>Nombre <input name="name" required value="<?= h((string) $user['name']) ?>"></label>
      <label>Email <input name="email" type="email" value="<?= h((string) $user['email']) ?>" placeholder="para que te escriban los alumnos"></label>
      <label>Usuario <input value="<?= h((string) $user['username']) ?>" disabled></label>
      <label>Breve presentación <textarea name="bio" rows="4" placeholder="Formación, estilo de enseñanza, una frase de presentación…"><?= h((string) ($user['bio'] ?? '')) ?></textarea></label>
      <?php if (user_has_permission($user, 'edit_course_info') && $courses): ?>
        <label class="check">
          <input type="checkbox" name="sync_courses" value="1">
          Aplicar nombre, presentación y foto a mis cursos (lo que ven los alumnos)
        </label>
      <?php endif; ?>
      <button class="btn primary" type="submit">Guardar perfil</button>
    </form>
  </section>

  <div class="stack" style="gap:1.25rem">
    <section class="panel">
      <h2 style="font-size:1.45rem;margin:0 0 1rem;display:flex;align-items:center;gap:.5rem"><?= aca_icon('lock') ?> Contraseña</h2>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="password">
        <label>Contraseña actual <input type="password" name="current" required autocomplete="current-password"></label>
        <label>Contraseña nueva <input type="password" name="next" required minlength="8" autocomplete="new-password"></label>
        <label>Repetir nueva <input type="password" name="repeat" required minlength="8" autocomplete="new-password"></label>
        <button class="btn secondary" type="submit">Cambiar contraseña</button>
      </form>
    </section>

    <section class="panel">
      <h2 style="font-size:1.45rem;margin:0 0 .75rem">Mis cursos</h2>
      <?php if (!$courses): ?>
        <p class="muted">Todavía no tenés cursos asignados. Pedile al administrador general que te asigne uno.</p>
      <?php else: ?>
        <ul class="stack" style="list-style:none;padding:0;margin:0;gap:.55rem">
          <?php foreach ($courses as $c): ?>
            <li style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;padding:.55rem 0;border-bottom:1px solid var(--line)">
              <span><strong><?= h($c['title']) ?></strong><br><em class="muted small"><?= h((string) ($c['category'] ?? '')) ?></em></span>
              <?php if (user_has_permission($user, 'edit_course_info')): ?>
                <a class="btn secondary small-btn" href="courses.php">Editar</a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2 style="font-size:1.45rem;margin:0 0 .75rem">Permisos que te dio el admin</h2>
      <ul class="stack" style="list-style:none;padding:0;margin:0;gap:.4rem">
        <?php foreach (TEACHER_PERMISSIONS as $key => $meta): ?>
          <li class="muted small" style="display:flex;gap:.5rem;align-items:center">
            <?= !empty($perms[$key]) ? '<span class="badge done">Sí</span>' : '<span class="badge">No</span>' ?>
            <?= h($meta['label']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="hint" style="margin-top:.85rem">Solo el administrador general puede cambiar estos permisos.</p>
    </section>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
