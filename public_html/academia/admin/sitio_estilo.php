<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
require_once __DIR__ . '/../includes/sitio_admin.php';

$self = 'sitio_estilo.php';
sitio_admin_handle_undo($self);
$fonts = sitio_fonts();
$scales = sitio_scales();
$accents = sitio_accents();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sitio_admin_require_post($self);
    $action = (string) ($_POST['action'] ?? '');
    $data = sitio_load(true);
    if ($action === 'reset') {
        $data['style'] = [];
        sitio_save($data, 'Letra y colores originales')
            ? flash('success', 'Volvieron la letra y los colores originales.')
            : flash('error', 'No se pudo guardar.');
        sitio_admin_go($self);
    }
    if ($action === 'save') {
        $pick = static function (string $key, array $allowed, string $fallback): string {
            $v = (string) ($_POST[$key] ?? '');
            return isset($allowed[$v]) ? $v : $fallback;
        };
        $style = [
            'font_display' => $pick('font_display', $fonts['display'], 'cormorant'),
            'font_body' => $pick('font_body', $fonts['body'], 'outfit'),
            'text_scale' => $pick('text_scale', $scales, 'normal'),
            'title_scale' => $pick('title_scale', $scales, 'normal'),
            'accent' => $pick('accent', $accents, 'fluxus'),
        ];
        $data['style'] = array_diff_assoc($style, sitio_style_defaults());
        sitio_save($data, 'Letra y colores')
            ? flash('success', 'Guardado. Mirá cómo quedó en la vista previa de abajo.')
            : flash('error', 'No se pudo guardar.');
        sitio_admin_go($self);
    }
    sitio_admin_go($self);
}

$style = sitio_style();
$families = [];
foreach (['display', 'body'] as $group) {
    foreach ($fonts[$group] as $f) {
        $families[explode(':', $f[2])[0]] = $f[2];
    }
}
$adminFontsUrl = 'https://fonts.googleapis.com/css2?family=' . implode('&family=', array_values($families)) . '&display=swap';

$pageTitle = 'Letra y colores';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
sitio_admin_header('sitio_estilo.php', 'Letra y colores', 'Elegí la tipografía de los títulos y de los textos, el tamaño de la letra y el color de los botones. Las opciones están pensadas para que el sitio siempre se lea bien.');
?>
<link rel="stylesheet" href="<?= h($adminFontsUrl) ?>">
<form method="post" class="sitio-style" id="sitio-estilo">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">

  <section class="panel">
    <h2>Muestra</h2>
    <div class="sitio-sample" data-sample>
      <p class="sitio-sample-kicker">Medicina china · Kinesiología</p>
      <p class="sitio-sample-title">Conectá con tu bienestar</p>
      <p class="sitio-sample-text">Acompañamos cada proceso con escucha, presencia y tratamientos personalizados para aliviar molestias y recuperar movilidad.</p>
      <span class="sitio-sample-btn">Reservar turno</span>
    </div>
  </section>

  <div class="form-grid two">
    <section class="panel">
      <h2>Letra de los títulos</h2>
      <select name="font_display" data-font="display">
        <?php foreach ($fonts['display'] as $key => $f): ?>
          <option value="<?= h($key) ?>" data-family="<?= h($f[1]) ?>" style="font-family: <?= h($f[1]) ?>"<?= $style['font_display'] === $key ? ' selected' : '' ?>><?= h($f[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <h3 class="sitio-sub">Tamaño de los títulos</h3>
      <div class="sitio-choices">
        <?php foreach ($scales as $key => $sc): ?>
          <label class="sitio-choice"><input type="radio" name="title_scale" value="<?= h($key) ?>" data-scale-title="<?= h((string) $sc[2]) ?>"<?= $style['title_scale'] === $key ? ' checked' : '' ?>><span><?= h($sc[0]) ?></span></label>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="panel">
      <h2>Letra de los textos</h2>
      <select name="font_body" data-font="body">
        <?php foreach ($fonts['body'] as $key => $f): ?>
          <option value="<?= h($key) ?>" data-family="<?= h($f[1]) ?>" style="font-family: <?= h($f[1]) ?>"<?= $style['font_body'] === $key ? ' selected' : '' ?>><?= h($f[0]) ?></option>
        <?php endforeach; ?>
      </select>
      <h3 class="sitio-sub">Tamaño de los textos</h3>
      <div class="sitio-choices">
        <?php foreach ($scales as $key => $sc): ?>
          <label class="sitio-choice"><input type="radio" name="text_scale" value="<?= h($key) ?>" data-scale-text="<?= h((string) $sc[1]) ?>"<?= $style['text_scale'] === $key ? ' checked' : '' ?>><span><?= h($sc[0]) ?></span></label>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <section class="panel">
    <h2>Color de los botones y detalles</h2>
    <div class="sitio-swatches">
      <?php foreach ($accents as $key => $a): ?>
        <label class="sitio-swatch">
          <input type="radio" name="accent" value="<?= h($key) ?>" data-accent="<?= h($a[1]) ?>"<?= $style['accent'] === $key ? ' checked' : '' ?>>
          <span class="sitio-swatch-dot" style="background: <?= h($a[1]) ?>; box-shadow: 0 0 0 4px <?= h($a[2]) ?>"></span>
          <span><?= h($a[0]) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </section>

  <div class="actions sitio-actions">
    <button class="btn primary" type="submit"><?= aca_icon('check') ?> Guardar y aplicar al sitio</button>
  </div>
</form>
<form method="post" class="sitio-reset" onsubmit="return confirm('¿Volver a la letra y colores originales? (Se puede deshacer)');">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="reset">
  <button class="btn ghost small-btn" type="submit"><?= aca_icon('refresh') ?> Volver a la letra y colores originales</button>
</form>

<?php sitio_admin_preview(); ?>

<script>
(function () {
  var form = document.getElementById('sitio-estilo');
  var sample = document.querySelector('[data-sample]');
  function val(sel) { var el = form.querySelector(sel); return el ? el : null; }
  function update() {
    var d = form.querySelector('[data-font="display"]');
    var b = form.querySelector('[data-font="body"]');
    sample.style.setProperty('--s-display', d.options[d.selectedIndex].getAttribute('data-family'));
    sample.style.setProperty('--s-body', b.options[b.selectedIndex].getAttribute('data-family'));
    var t = val('[name=title_scale]:checked'), x = val('[name=text_scale]:checked'), a = val('[name=accent]:checked');
    sample.style.setProperty('--s-title', t ? t.getAttribute('data-scale-title') : 1);
    sample.style.setProperty('--s-text', x ? x.getAttribute('data-scale-text') : 1);
    sample.style.setProperty('--s-accent', a ? a.getAttribute('data-accent') : '#2f8f6b');
  }
  form.addEventListener('change', update);
  update();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
