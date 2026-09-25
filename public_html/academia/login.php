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

        $stmt = db()->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([$username]);
        $found = $stmt->fetch();

        if ($found && password_verify($password, $found['password_hash'])) {
            $role = (string) $found['role'];
            if ($expect === 'admin' && $role !== 'admin') {
                $error = 'Esta cuenta no es de administrador. Entrá por “Alumno / Paciente”.';
            } elseif ($expect === 'alumno' && $role === 'admin') {
                $error = 'Esta es una cuenta de administrador. Entrá por “Administrador”.';
            } else {
                $_SESSION['user_id'] = (int) $found['id'];
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
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}

$pageTitle = $as === 'admin' ? 'Acceso administrador' : ($as === 'alumno' ? 'Acceso alumno' : 'Ingresar');
$minimalShell = true;
require __DIR__ . '/includes/header.php';
?>

<?php if ($as === ''): ?>
<section class="portal">
  <div class="portal-hero">
    <p class="eyebrow">AcademiaFluxus</p>
    <h1>Ingresá</h1>
    <p class="lede">Elegí cómo querés continuar.</p>
  </div>

  <div class="portal-grid">
    <a class="portal-card admin" href="login.php?as=admin">
      <span class="portal-kicker">Gestión</span>
      <h2>Administrador</h2>
      <p>Alumnos, cupos, cursos, contenidos y pagos.</p>
      <span class="portal-go">Entrar →</span>
    </a>
    <a class="portal-card student" href="login.php?as=alumno">
      <span class="portal-kicker">Campus</span>
      <h2>Alumno</h2>
      <p>Cursos, clases, progreso y cuotas.</p>
      <span class="portal-go">Entrar →</span>
    </a>
  </div>

  <nav class="portal-links" aria-label="Otras opciones">
    <a href="inscripcion.php">Inscribirme</a>
    <a href="../cursos/">Cursos grabados</a>
    <a href="../">FluxusTerapia</a>
  </nav>
</section>
<?php else: ?>
<section class="auth-card auth-card--quiet">
  <p class="eyebrow"><?= $as === 'admin' ? 'Administrador' : 'Alumno' ?></p>
  <h1><?= $as === 'admin' ? 'Panel' : 'Campus' ?></h1>
  <p class="lede">
    <?= $as === 'admin'
      ? 'Ingresá con tu usuario de administrador.'
      : 'Usá el usuario y la contraseña que te enviamos.' ?>
  </p>
  <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="as" value="<?= h($as) ?>">
    <label>Usuario
      <input type="text" name="username" required autocomplete="username" autofocus>
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
