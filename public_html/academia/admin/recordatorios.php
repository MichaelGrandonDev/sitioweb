<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión expiró. Probá de nuevo.');
        redirect('recordatorios.php');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        reminder_settings_save([
            'global_enabled' => !empty($_POST['global_enabled']),
            'interval_days' => (int) ($_POST['interval_days'] ?? REMINDER_DEFAULT_INTERVAL_DAYS),
            'subject' => (string) ($_POST['subject'] ?? ''),
            'intro' => (string) ($_POST['intro'] ?? ''),
        ]);
        flash('success', 'Configuración de recordatorios guardada.');
        redirect('recordatorios.php');
    }

    if ($action === 'toggle') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $enable = ((string) ($_POST['enable'] ?? '')) === '1';
        $st = $pdo->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'student'");
        $st->execute([$uid]);
        if (!$st->fetch()) {
            flash('error', 'No encontramos ese alumno.');
            redirect('recordatorios.php');
        }
        if ($enable) {
            $em = $pdo->prepare('SELECT email FROM users WHERE id = ?');
            $em->execute([$uid]);
            $email = (string) ($em->fetchColumn() ?: '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Ese alumno no tiene un email válido. Cargalo antes de activar el recordatorio.');
                redirect('recordatorios.php?filter=no_email');
            }
            $pending = reminder_pending_classes($uid);
            if ($pending === []) {
                reminder_mark_completed($uid);
                flash('success', 'Ese alumno ya completó todas las clases: no hace falta recordatorio.');
                redirect('recordatorios.php?filter=completed');
            }
            reminder_set_enabled($uid, true);
            flash('success', 'Recordatorio activado para ese alumno.');
        } else {
            reminder_set_enabled($uid, false, 'admin');
            flash('success', 'Dejamos de enviar recordatorios a ese alumno.');
        }
        $filter = preg_replace('/[^a-z_]/', '', (string) ($_POST['filter'] ?? 'all')) ?: 'all';
        redirect('recordatorios.php?filter=' . $filter);
    }

    if ($action === 'test_run') {
        $stats = reminder_run_batch(true, 20);
        flash(
            'success',
            'Simulación: revisados ' . $stats['checked']
            . ', candidatos a envío ' . $stats['sent']
            . ', omitidos ' . $stats['skipped']
            . ', completaron ' . $stats['completed'] . '.'
        );
        redirect('recordatorios.php');
    }

    redirect('recordatorios.php');
}

$settings = reminder_settings();
$filter = (string) ($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'active', 'paused', 'completed', 'no_email'], true)) {
    $filter = 'all';
}

$students = $pdo->query("
  SELECT u.id, u.name, u.email, u.username,
         COALESCE(r.enabled, 0) AS rem_enabled,
         r.last_sent_at, r.next_due_at, COALESCE(r.stop_reason, '') AS stop_reason
  FROM users u
  LEFT JOIN student_reminders r ON r.user_id = u.id
  WHERE u.role = 'student'
  ORDER BY lower(u.name), u.id
")->fetchAll();

$rows = [];
$counts = ['all' => 0, 'active' => 0, 'paused' => 0, 'completed' => 0, 'no_email' => 0];
foreach ($students as $s) {
    $pending = reminder_pending_classes((int) $s['id']);
    $status = reminder_status_label(
        [
            'enabled' => (int) $s['rem_enabled'],
            'stop_reason' => (string) $s['stop_reason'],
        ],
        (string) ($s['email'] ?? ''),
        count($pending)
    );
    $counts['all']++;
    $counts[$status['key']] = ($counts[$status['key']] ?? 0) + 1;
    if ($filter !== 'all' && $status['key'] !== $filter) {
        continue;
    }
    $rows[] = [
        'user' => $s,
        'pending' => $pending,
        'status' => $status,
    ];
}

$recentLogs = $pdo->query("
  SELECT id, user_id, status, detail, created_at
  FROM reminder_logs
  ORDER BY id DESC
  LIMIT 20
")->fetchAll();

$pageTitle = 'Recordatorios';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Admin</p>
    <h1>Recordatorios por mail</h1>
    <p class="lede">Avisá a los alumnos que ya tienen acceso al campus y todavía les quedan clases por ver. Podés activar o pausar alumno por alumno.</p>
  </div>
</div>

<nav class="admin-nav" style="margin-bottom:1rem">
  <a class="btn secondary" href="index.php">← Panel</a>
  <a class="btn secondary" href="users.php">Alumnos</a>
</nav>

<section class="panel" style="margin-bottom:1.25rem">
  <h2>Automatización</h2>
  <p class="hint">El envío automático lo dispara un cron en el servidor (una vez por día alcanza). Acá definís si está prendido, cada cuántos días y el texto base.</p>
  <form method="post" class="stack">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_settings">
    <label class="check">
      <input type="checkbox" name="global_enabled" value="1" <?= $settings['global_enabled'] ? 'checked' : '' ?>>
      Activar automatización global
    </label>
    <div class="form-grid two">
      <label>Cada cuántos días (1–60)
        <input type="number" name="interval_days" min="1" max="60" value="<?= (int) $settings['interval_days'] ?>" required>
      </label>
      <label>Asunto del mail
        <input type="text" name="subject" maxlength="160" value="<?= h($settings['subject']) ?>" required>
      </label>
    </div>
    <label>Texto de introducción
      <textarea name="intro" rows="4" maxlength="1200" required><?= h($settings['intro']) ?></textarea>
    </label>
    <div class="actions" style="gap:.5rem;flex-wrap:wrap">
      <button class="btn primary" type="submit">Guardar configuración</button>
    </div>
  </form>
  <form method="post" style="margin-top:.75rem">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="test_run">
    <button class="btn secondary small-btn" type="submit">Simular corrida (sin enviar mails)</button>
  </form>
  <p class="hint" style="margin-top:.8rem">
    Cron (en el servidor, con tu clave real en <code>config.php</code>):
    <code>curl "https://www.fluxusterapia.com/academia/cron_reminders.php?key=TU_CLAVE"</code>
  </p>
</section>

<section class="panel">
  <h2>Alumnos</h2>
  <nav class="reminder-filters" aria-label="Filtros">
    <?php
    $labels = [
        'all' => 'Todos',
        'active' => 'Activos',
        'paused' => 'Pausados',
        'completed' => 'Completaron',
        'no_email' => 'Sin email',
    ];
    foreach ($labels as $key => $label):
        ?>
      <a class="<?= $filter === $key ? 'is-on' : '' ?>" href="?filter=<?= h($key) ?>"><?= h($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
    <p class="muted">No hay alumnos en este filtro.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Alumno</th>
          <th>Estado</th>
          <th>Pendientes</th>
          <th>Último envío</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row):
            $u = $row['user'];
            $st = $row['status'];
            $pending = $row['pending'];
            $summary = reminder_pending_summary($pending, 3);
            $statusClass = match ($st['key']) {
                'active' => 'is-active',
                'paused' => 'is-paused',
                'completed' => 'is-done',
                'no_email' => 'is-noemail',
                default => '',
            };
            ?>
          <tr>
            <td>
              <div class="who">
                <span class="avatar sm"><?= h(aca_initial((string) $u['name'])) ?></span>
                <span>
                  <strong><?= h((string) $u['name']) ?></strong>
                  <span><?= h((string) ($u['email'] ?: $u['username'])) ?></span>
                </span>
              </div>
            </td>
            <td data-label="Estado"><span class="reminder-status <?= h($statusClass) ?>"><?= h($st['label']) ?></span></td>
            <td data-label="Pendientes">
              <?php if (count($pending) === 0): ?>
                <span class="muted">Ninguna</span>
              <?php else: ?>
                <strong><?= count($pending) ?></strong>
                <?php if ($summary !== ''): ?>
                  <div class="hint"><?= h($summary) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </td>
            <td data-label="Último envío">
              <?php if (!empty($u['last_sent_at'])): ?>
                <?= h(aca_time_ago((string) $u['last_sent_at'])) ?>
              <?php else: ?>
                <span class="muted">Nunca</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right">
              <?php if ($st['key'] === 'no_email'): ?>
                <span class="hint">Cargá un email en Alumnos</span>
              <?php elseif ($st['key'] === 'completed'): ?>
                <span class="hint">No se envía</span>
              <?php elseif ($st['key'] === 'active'): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="enable" value="0">
                  <input type="hidden" name="filter" value="<?= h($filter) ?>">
                  <button class="btn secondary small-btn" type="submit">Dejar de enviar</button>
                </form>
              <?php else: ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="enable" value="1">
                  <input type="hidden" name="filter" value="<?= h($filter) ?>">
                  <button class="btn primary small-btn" type="submit">Activar</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="panel" style="margin-top:1.25rem">
  <h2>Últimos registros</h2>
  <p class="hint">Solo id de alumno y resultado (sin mostrar el email completo en esta bitácora).</p>
  <?php if (!$recentLogs): ?>
    <p class="muted">Todavía no hay actividad.</p>
  <?php else: ?>
    <ul class="stack" style="list-style:none;padding:0;margin:0;gap:.45rem">
      <?php foreach ($recentLogs as $log): ?>
        <li class="hint">
          #<?= (int) $log['id'] ?>
          · alumno <?= $log['user_id'] !== null ? '#' . (int) $log['user_id'] : '—' ?>
          · <strong><?= h((string) $log['status']) ?></strong>
          <?= $log['detail'] !== '' ? ' · ' . h((string) $log['detail']) : '' ?>
          · <?= h((string) $log['created_at']) ?> UTC
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
