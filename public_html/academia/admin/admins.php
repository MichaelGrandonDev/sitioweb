<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
$me = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('admins.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
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
                flash('success', 'Administrador creado: ' . $username);
            } catch (Throwable $e) {
                flash('error', 'No se pudo crear (¿usuario repetido?).');
            }
        }
    }

    if ($action === 'reset') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if ($uid > 0 && strlen($password) >= 6) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'admin'")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
            flash('success', 'Contraseña de administrador actualizada.');
        } else {
            flash('error', 'Contraseña inválida (mínimo 6 caracteres).');
        }
    }

    if ($action === 'toggle') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid === (int) $me['id']) {
            flash('error', 'No podés desactivar tu propia cuenta.');
        } elseif ($uid > 0) {
            $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            $row = $pdo->prepare("SELECT active FROM users WHERE id = ? AND role = 'admin'");
            $row->execute([$uid]);
            $target = $row->fetch();
            if ($target && (int) $target['active'] === 1 && $activeCount <= 1) {
                flash('error', 'Debe quedar al menos un administrador activo.');
            } else {
                $pdo->prepare("UPDATE users SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id = ? AND role='admin'")
                    ->execute([$uid]);
                flash('success', 'Estado del administrador actualizado.');
            }
        }
    }

    if ($action === 'delete') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid === (int) $me['id']) {
            flash('error', 'No podés eliminar tu propia cuenta.');
        } elseif ($uid > 0) {
            $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn();
            $row = $pdo->prepare("SELECT active FROM users WHERE id = ? AND role = 'admin'");
            $row->execute([$uid]);
            $target = $row->fetch();
            if ($target && (int) $target['active'] === 1 && $activeCount <= 1) {
                flash('error', 'No se puede eliminar el único administrador activo.');
            } else {
                $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'admin'")->execute([$uid]);
                flash('success', 'Administrador eliminado.');
            }
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

$pageTitle = 'Administradores';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<p class="eyebrow">Admin</p>
<h1>Administradores</h1>
<p class="lede">Agregá otras personas con acceso completo al panel: alumnos, cursos, unidades, tienda y cuotas. Entran por AcademiaFluxus → Administrador.</p>
<nav class="admin-nav">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
  <a class="btn secondary" href="payments.php">Cuotas</a>
</nav>

<section class="panel" style="margin-bottom:1.25rem">
  <h2 class="panel-title">Nuevo administrador</h2>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="create">
    <div class="form-grid two">
      <label>Nombre completo <input name="name" required placeholder="Nombre y apellido"></label>
      <label>Email <input type="email" name="email" placeholder="opcional"></label>
      <label>Usuario <input name="username" required autocomplete="off" placeholder="ej: laura.admin"></label>
      <label>Contraseña <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
    </div>
    <button class="btn primary" type="submit">Crear administrador</button>
  </form>
  <p class="muted small" style="margin-top:.75rem">
    Entran por <a href="../login.php?as=admin">AcademiaFluxus → Administrador</a>.
  </p>
</section>

<section class="panel">
  <h2 class="panel-title">Administradores actuales</h2>
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
          </p>
          <form method="post" class="stack" style="margin-top:.75rem;max-width:20rem">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset">
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
              <input type="hidden" name="user_id" value="<?= (int) $a['id'] ?>">
              <button class="btn secondary small-btn" type="submit">
                <?= (int) $a['active'] ? 'Desactivar' : 'Activar' ?>
              </button>
            </form>
            <form method="post" onsubmit="return confirm('¿Eliminar este administrador?')">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete">
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
