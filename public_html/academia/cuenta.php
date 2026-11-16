<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$user = require_login();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current'] ?? '');
    $next = (string) ($_POST['next'] ?? '');
    $repeat = (string) ($_POST['repeat'] ?? '');
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([(int) $user['id']]);
    $hash = (string) $stmt->fetchColumn();
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Probá de nuevo.';
    } elseif (!password_verify($current, $hash)) {
        $error = 'La contraseña actual no es correcta.';
    } elseif (strlen($next) < 8) {
        $error = 'La contraseña nueva tiene que tener al menos 8 caracteres.';
    } elseif ($next !== $repeat) {
        $error = 'Las dos contraseñas nuevas no coinciden.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]);
        flash('success', 'Listo, tu contraseña nueva ya está activa.');
        redirect('cuenta.php');
    }
}

$pageTitle = 'Mi cuenta';
$basePath = '';
$assetPrefix = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Campus alumno</p>
    <h1>Mi cuenta</h1>
  </div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));align-items:start">
  <section class="panel">
    <div class="account-card">
      <span class="avatar lg"><?= h(aca_initial((string) $user['name'])) ?></span>
      <div>
        <strong><?= h($user['name']) ?></strong>
        <span><?= h((string) ($user['email'] ?: $user['username'])) ?></span>
      </div>
    </div>
    <p class="hint" style="margin-top:1rem">Para cambiar tu nombre o email, escribile a administración desde <a href="tickets.php?nuevo=1&amp;to=admin#nueva" style="color:var(--accent)">Consultas</a>.</p>
    <a class="btn secondary home-site-btn" href="<?= h(aca_site_home_url()) ?>" target="_blank" rel="noopener noreferrer" style="margin-top:1rem">
      <?= aca_icon('globe') ?> FluxusTerapia
    </a>
    <p class="hint" style="margin-top:0.45rem">Abrí el sitio público de la clínica (fuera del campus).</p>
  </section>

  <section class="panel">
    <h2 style="font-size:1.5rem;display:flex;align-items:center;gap:.5rem"><?= aca_icon('lock') ?> Cambiar contraseña</h2>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Contraseña actual <input type="password" name="current" required autocomplete="current-password"></label>
      <label>Contraseña nueva <input type="password" name="next" required minlength="8" autocomplete="new-password"></label>
      <label>Repetir contraseña nueva <input type="password" name="repeat" required minlength="8" autocomplete="new-password"></label>
      <button class="btn primary" type="submit">Guardar contraseña</button>
    </form>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
