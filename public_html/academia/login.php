<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

if ($user = current_user()) {
    redirect($user['role'] === 'admin' ? 'admin/index.php' : 'dashboard.php');
}

$as = strtolower(trim((string) ($_GET['as'] ?? $_POST['as'] ?? '')));
if ($as === 'paciente' || $as === 'usuario' || $as === 'student') {
    $as = 'alumno';
}
if ($as !== 'admin' && $as !== 'alumno') {
    $as = '';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'Sesión inválida. Probá de nuevo.';
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $expect = strtolower(trim((string) ($_POST['as'] ?? '')));
        if ($expect === 'paciente' || $expect === 'usuario' || $expect === 'student') {
            $expect = 'alumno';
        }

        $stmt = db()->prepare("
          SELECT * FROM users
          WHERE active = 1 AND (username = ? OR (email <> '' AND lower(email) = lower(?)))
          ORDER BY (username = ?) DESC
          LIMIT 1
        ");
        $stmt->execute([$username, $username, $username]);
        $found = $stmt->fetch();

        if ($found && password_verify($password, $found['password_hash'])) {
            $role = (string) $found['role'];
            if ($expect === 'admin' && $role !== 'admin') {
                $error = 'Esta cuenta es de alumno. Entrá desde el portal del estudiante.';
            } elseif ($expect === 'alumno' && $role === 'admin') {
                $error = 'Esta es una cuenta de administrador. Entrá por “Administrador”.';
            } else {
                fluxus_session_login();
                $_SESSION['user_id'] = (int) $found['id'];
                db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $found['id']]);
                if ($role === 'admin') {
                    require_once __DIR__ . '/../includes/site_admin.php';
                    site_admin_grant();
                } else {
                    require_once __DIR__ . '/../includes/site_admin.php';
                    unset($_SESSION['site_admin']);
                }
                redirect($role === 'admin' ? 'admin/index.php' : 'dashboard.php');
            }
        } else {
            fluxus_login_failed();
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

$pageTitle = $as === 'admin' ? 'Acceso administrador' : 'Portal del estudiante';
$minimalShell = true;
require __DIR__ . '/includes/header.php';
?>

<?php if ($as !== 'admin'): ?>
<section class="student-portal">
  <div class="sp-intro">
    <p class="eyebrow">AcademiaFluxus · Portal del estudiante</p>
    <h1>Tus clases, en vivo y grabadas</h1>
    <p class="lede">Entrá con tu email y contraseña para sumarte a la clase en vivo o mirar las grabadas cuando quieras.</p>
  </div>

  <div class="auth-card sp-login">
    <h2>Ingresar al campus</h2>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Email
        <input type="text" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" inputmode="email" autofocus>
      </label>
      <label>Contraseña
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button class="btn primary" type="submit">Entrar al campus</button>
    </form>
    <p class="muted small" style="margin:.9rem 0 0">¿Olvidaste la contraseña? Escribinos por WhatsApp y te mandamos una nueva.</p>
    <div class="sp-join">
      <span>¿Todavía no sos alumno?</span>
      <a class="btn secondary" href="inscripcion.php">Inscribirme</a>
    </div>
  </div>

  <ul class="sp-features">
    <li><span><?= aca_icon('video') ?></span><div><strong>Clases en vivo</strong><small>Día, horario y botón para entrar a Meet.</small></div></li>
    <li><span><?= aca_icon('play') ?></span><div><strong>Clases grabadas</strong><small>Miralas a tu ritmo, retomá donde dejaste.</small></div></li>
    <li><span><?= aca_icon('chat') ?></span><div><strong>Consultas</strong><small>Escribile al profesor y te avisamos por email.</small></div></li>
    <li><span><?= aca_icon('book') ?></span><div><strong>Materiales</strong><small>PDFs y recursos de cada curso.</small></div></li>
  </ul>

  <nav class="portal-links sp-links" aria-label="Otras opciones">
    <a href="../">← FluxusTerapia</a>
    <a href="login.php?as=admin"><?= aca_icon('lock') ?> Acceso administración</a>
  </nav>
</section>
<?php else: ?>
<section class="auth-card auth-card--quiet">
  <p class="eyebrow"><?= $as === 'admin' ? 'Administrador' : 'Alumno' ?></p>
  <h1><?= $as === 'admin' ? 'Panel' : 'Campus' ?></h1>
  <p class="lede">
    <?= $as === 'admin'
      ? 'Ingresá con tu usuario de administrador.'
      : 'Usá tu email y la contraseña que te enviamos.' ?>
  </p>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="as" value="<?= h($as) ?>">
    <label><?= $as === 'admin' ? 'Usuario' : 'Email' ?>
      <input type="text" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
    </label>
    <label>Contraseña
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn primary" type="submit">
      <?= $as === 'admin' ? 'Ingresar' : 'Entrar al campus' ?>
    </button>
  </form>
  <p class="auth-back">
    <?php if ($as === 'alumno'): ?>
      <a href="inscripcion.php">Quiero inscribirme</a>
      <span aria-hidden="true">·</span>
    <?php endif; ?>
    <a href="login.php">Volver</a>
  </p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
