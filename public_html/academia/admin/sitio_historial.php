<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
require_once __DIR__ . '/../includes/sitio_admin.php';

$self = 'sitio_historial.php';
sitio_admin_handle_undo($self);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sitio_admin_require_post($self);
    if (($_POST['action'] ?? '') === 'restore') {
        sitio_restore((string) ($_POST['id'] ?? ''))
            ? flash('success', 'Listo, el sitio volvió a esa versión. Si no era lo que querías, tocá "Deshacer último cambio".')
            : flash('error', 'No se encontró esa versión.');
    }
    sitio_admin_go($self);
}

$history = sitio_history_list();
$updated = sitio_load()['updated_at'] ?? null;

$pageTitle = 'Historial del sitio';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
sitio_admin_header('sitio_historial.php', 'Historial de cambios', 'Cada vez que guardás algo, queda una copia de cómo estaba antes. Desde acá podés volver atrás.');
?>
<section class="panel">
  <p class="muted small">
    <?= $updated ? 'Último cambio guardado: ' . h(date('d/m/Y H:i', (int) strtotime($updated))) . '.' : 'Todavía no se guardó ningún cambio: el sitio está como fue diseñado.' ?>
    Se guardan las últimas <?= SITIO_HISTORY_KEEP ?> versiones.
  </p>
  <?php if (!$history): ?>
    <p class="empty-state"><strong>Sin cambios todavía</strong>Cuando guardes textos, fotos o datos de contacto, vas a ver acá cada versión anterior.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Fecha</th><th>Qué se cambió después</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($history as $i => $item): ?>
          <tr>
            <td><?= h($item['at'] ? date('d/m/Y H:i', (int) strtotime($item['at'])) : '—') ?></td>
            <td><?= h($item['label']) ?><?= $i === 0 ? ' <span class="badge soft">el más reciente</span>' : '' ?></td>
            <td style="text-align:right">
              <form method="post" onsubmit="return confirm('¿Volver el sitio a como estaba antes de este cambio?');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="id" value="<?= h($item['id']) ?>">
                <button class="btn secondary small-btn" type="submit"><?= aca_icon('refresh') ?> Volver a como estaba antes</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
