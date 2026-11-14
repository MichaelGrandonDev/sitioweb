<?php

declare(strict_types=1);

/** Piezas compartidas de las páginas "Sitio web" del admin. */

require_once __DIR__ . '/../../includes/sitio.php';

function sitio_admin_pages(): array
{
    return [
        'sitio_textos.php' => ['Textos', 'pen'],
        'sitio_fotos.php' => ['Fotos', 'image'],
        'sitio_contacto.php' => ['Contacto y WhatsApp', 'whatsapp'],
        'sitio_estilo.php' => ['Letra y colores', 'type'],
        'sitio_historial.php' => ['Historial', 'clock'],
    ];
}

/** Después de un POST: redirige (o, si el pedido vino por JavaScript, responde a dónde ir). */
function sitio_admin_go(string $url): never
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['go' => $url]);
        exit;
    }
    redirect($url);
}

/** Atiende "Deshacer último cambio" (lo usan todas las páginas). */
function sitio_admin_handle_undo(string $back): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'undo') {
        return;
    }
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión venció. Recargá la página e intentá de nuevo.');
        sitio_admin_go($back);
    }
    $label = sitio_undo();
    if ($label === null) {
        flash('info', 'No hay cambios para deshacer.');
    } else {
        flash('success', 'Listo, se deshizo el último cambio (' . $label . ').');
    }
    sitio_admin_go($back);
}

function sitio_admin_require_post(string $back): void
{
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'La sesión venció. Recargá la página e intentá de nuevo.');
        sitio_admin_go($back);
    }
}

/** Encabezado común: título, botones "Ver en el sitio" y "Deshacer", y pestañas. */
function sitio_admin_header(string $current, string $title, string $lede, string $anchor = ''): void
{
    $history = sitio_history_list();
    $last = $history[0] ?? null;
    ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Sitio web · fluxusterapia.com</p>
    <h1><?= h($title) ?></h1>
    <p class="lede"><?= h($lede) ?></p>
  </div>
  <div class="sitio-head-actions">
    <a class="btn secondary" href="../../<?= $anchor !== '' ? '#' . h($anchor) : '' ?>" target="_blank" rel="noopener"><?= aca_icon('external') ?> Ver en el sitio</a>
    <?php if ($last): ?>
      <form method="post" onsubmit="return confirm('¿Deshacer el último cambio?\n\n<?= h(addslashes($last['label'])) ?>');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="undo">
        <button class="btn secondary" type="submit" title="Último cambio: <?= h($last['label']) ?>"><?= aca_icon('refresh') ?> Deshacer último cambio</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<nav class="sitio-tabs" aria-label="Secciones del sitio web">
  <?php foreach (sitio_admin_pages() as $file => [$label, $icon]): ?>
    <a class="<?= $file === $current ? 'is-on' : '' ?>" href="<?= h($file) ?>"<?= $file === $current ? ' aria-current="page"' : '' ?>><?= aca_icon($icon) ?><span><?= h($label) ?></span></a>
  <?php endforeach; ?>
</nav>
    <?php
}

/** Vista previa de la home dentro del admin. */
function sitio_admin_preview(string $anchor = ''): void
{
    $src = '../../?vista=' . time() . ($anchor !== '' ? '#' . $anchor : '');
    ?>
<section class="panel sitio-preview">
  <div class="sitio-preview-head">
    <h2>Vista previa</h2>
    <a class="btn secondary small-btn" href="<?= h($src) ?>" target="_blank" rel="noopener"><?= aca_icon('external') ?> Abrir en otra pestaña</a>
  </div>
  <iframe src="<?= h($src) ?>" title="Vista previa del sitio" loading="lazy"></iframe>
</section>
    <?php
}

function sitio_admin_clip(string $value, int $max): string
{
    $value = str_replace(["\r\n", "\r"], "\n", trim($value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

function sitio_admin_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}
