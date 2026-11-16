<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

if ($user = current_user()) {
    redirect(is_staff($user) ? staff_home_for_role($user) : 'dashboard.php');
}

$as = strtolower(trim((string) ($_GET['as'] ?? $_POST['as'] ?? '')));
if ($as === 'paciente' || $as === 'usuario' || $as === 'student') {
    $as = 'alumno';
}
if ($as === 'teacher' || $as === 'profe') {
    $as = 'profesor';
}
if (!in_array($as, ['admin', 'alumno', 'profesor'], true)) {
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
        if ($expect === 'teacher' || $expect === 'profe') {
            $expect = 'profesor';
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
                $error = $role === 'teacher'
                    ? 'Las cuentas de profesor entran por el Portal de profesores.'
                    : 'Esta cuenta es de alumno. Entrá desde el portal del estudiante.';
            } elseif ($expect === 'profesor' && $role !== 'teacher') {
                $error = $role === 'admin'
                    ? 'Esta es la cuenta de administrador general. Entrá por Acceso administración.'
                    : 'Esta cuenta es de alumno. Entrá desde el portal del estudiante.';
            } elseif ($expect === 'alumno' && is_staff_role($role)) {
                $error = $role === 'teacher'
                    ? 'Esta es una cuenta de profesor. Entrá por el Portal de profesores.'
                    : 'Esta es una cuenta de administración. Entrá por Acceso administración.';
            } else {
                fluxus_session_login();
                $_SESSION['user_id'] = (int) $found['id'];
                db()->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $found['id']]);
                require_once __DIR__ . '/../includes/site_admin.php';
                if ($role === 'admin') {
                    site_admin_grant();
                } else {
                    unset($_SESSION['site_admin']);
                }
                redirect(is_staff_role($role) ? staff_home_for_role($found) : 'dashboard.php');
            }
        } else {
            fluxus_login_failed();
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

$pageTitle = match ($as) {
    'admin' => 'Acceso administrador',
    'profesor' => 'Portal de profesores',
    default => 'Portal del estudiante',
};
$minimalShell = true;
require __DIR__ . '/includes/header.php';
?>

<?php if ($as === ''): ?>
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
    <a href="login.php?as=profesor"><?= aca_icon('spark') ?> Portal de profesores</a>
    <a href="login.php?as=admin"><?= aca_icon('lock') ?> Acceso administración</a>
  </nav>
</section>

<?php elseif ($as === 'profesor'): ?>
<section class="student-portal teacher-portal">
  <div class="sp-intro">
    <p class="eyebrow">AcademiaFluxus · Portal de profesores</p>
    <h1>Tu espacio para dar clases</h1>
    <p class="lede">Cargá clases y materiales, mirá el avance de tus alumnos y respondé consultas — solo en los cursos que te asignó el administrador.</p>
  </div>

  <div class="auth-card sp-login">
    <h2>Ingresar como profesor</h2>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="as" value="profesor">
      <label>Usuario o email
        <input type="text" name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
      </label>
      <label>Contraseña
        <input type="password" name="password" required autocomplete="current-password">
      </label>
      <button class="btn primary" type="submit">Entrar al portal</button>
    </form>
    <p class="muted small" style="margin:.9rem 0 0">La cuenta la crea el administrador general desde Administradores.</p>
  </div>

  <ul class="sp-features">
    <li><span><?= aca_icon('user') ?></span><div><strong>Tu perfil</strong><small>Foto, presentación y contraseña.</small></div></li>
    <li><span><?= aca_icon('upload') ?></span><div><strong>Clases y materiales</strong><small>Subí videos, PDFs y ordená el curso.</small></div></li>
    <li><span><?= aca_icon('users') ?></span><div><strong>Alumnos</strong><small>Mirá quién está inscrito y su avance.</small></div></li>
    <li><span><?= aca_icon('chat') ?></span><div><strong>Consultas</strong><small>Respondé las dudas de tus cursos.</small></div></li>
  </ul>

  <nav class="portal-links sp-links" aria-label="Otras opciones">
    <a href="login.php">← Portal del estudiante</a>
    <a href="login.php?as=admin"><?= aca_icon('lock') ?> Administración</a>
  </nav>
</section>

<?php else: ?>
<section class="auth-card auth-card--quiet">
  <p class="eyebrow"><?= $as === 'admin' ? 'Administración' : 'Alumno' ?></p>
  <h1><?= $as === 'admin' ? 'Panel general' : 'Campus' ?></h1>
  <p class="lede">
    <?= $as === 'admin'
      ? 'Solo para el administrador general. Los profesores entran por el Portal de profesores.'
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
    <?php if ($as === 'admin'): ?>
      <a href="login.php?as=profesor">Soy profesor</a>
      <span aria-hidden="true">·</span>
    <?php endif; ?>
    <?php if ($as === 'alumno'): ?>
      <a href="inscripcion.php">Quiero inscribirme</a>
      <span aria-hidden="true">·</span>
    <?php endif; ?>
    <a href="login.php">Volver</a>
  </p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
