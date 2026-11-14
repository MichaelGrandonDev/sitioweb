<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
require_once __DIR__ . '/../includes/sitio_admin.php';

$schema = sitio_text_schema();
$section = (string) ($_GET['s'] ?? $_POST['section'] ?? 'hero');
if (!isset($schema[$section])) {
    $section = 'hero';
}
$self = 'sitio_textos.php?s=' . rawurlencode($section);
sitio_admin_handle_undo($self);

$limits = ['line' => 200, 'url' => 300, 'text' => 1200, 'list' => 2000, 'rich' => 5000];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sitio_admin_require_post($self);
    $action = (string) ($_POST['action'] ?? '');
    $data = sitio_load(true);
    $fields = $schema[$section]['fields'];

    if ($action === 'reset') {
        foreach (array_keys($fields) as $key) {
            unset($data['texts'][$key]);
        }
        sitio_save($data, 'Textos originales: ' . $schema[$section]['label'])
            ? flash('success', 'Volvieron los textos originales de "' . $schema[$section]['label'] . '".')
            : flash('error', 'No se pudo guardar. Probá de nuevo.');
        sitio_admin_go($self);
    }

    if ($action === 'save') {
        $posted = is_array($_POST['t'] ?? null) ? $_POST['t'] : [];
        $errors = [];
        foreach ($fields as $key => [$label, $type, $default]) {
            $optional = !empty($fields[$key][3]);
            $value = sitio_admin_clip((string) ($posted[$key] ?? ''), $limits[$type] ?? 1000);
            if ($type === 'line' || $type === 'url') {
                $value = trim(str_replace("\n", ' ', $value));
            }
            if ($type === 'url' && $value !== '' && sitio_safe_link($value) === null) {
                $errors[] = '"' . $label . '": ese link no es válido. Usá una dirección que empiece con https:// o una página del sitio (por ejemplo turnos/).';
                continue;
            }
            if ($value === '' && !$optional) {
                unset($data['texts'][$key]);
                continue;
            }
            if ($value === $default) {
                unset($data['texts'][$key]);
            } else {
                $data['texts'][$key] = $value;
            }
        }
        if ($errors) {
            flash('error', implode(' ', $errors));
            sitio_admin_go($self);
        }
        sitio_save($data, 'Textos: ' . $schema[$section]['label'])
            ? flash('success', 'Guardado. Ya se ve en el sitio.')
            : flash('error', 'No se pudo guardar. Probá de nuevo.');
        sitio_admin_go($self);
    }
    sitio_admin_go($self);
}

$sec = $schema[$section];
$saved = sitio_load()['texts'];

$pageTitle = 'Textos del sitio';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
sitio_admin_header('sitio_textos.php', 'Textos de la página', 'Cambiá títulos, párrafos y botones de la página de inicio. Los cambios se ven en el sitio apenas guardás.', $sec['anchor']);
?>
<nav class="rec-filter sitio-sections" aria-label="Partes de la página">
  <?php foreach ($schema as $key => $tab): ?>
    <a class="btn <?= $key === $section ? 'primary' : 'secondary' ?> small-btn" href="sitio_textos.php?s=<?= h($key) ?>"><?= h($tab["label"]) ?></a>
  <?php endforeach; ?>
</nav>

<section class="panel">
  <h2><?= h($sec['label']) ?></h2>
  <p class="muted"><?= h($sec['help']) ?></p>
  <details class="sitio-help">
    <summary>¿Cómo pongo negrita, un enlace o separo párrafos?</summary>
    <ul>
      <li><strong>Negrita:</strong> poné el texto entre dos asteriscos: <code>**así**</code>. O seleccioná el texto y tocá el botón <b>N</b>.</li>
      <li><strong>Enlace:</strong> <code>[texto que se ve](https://direccion.com)</code>. O seleccioná el texto y tocá <b>Enlace</b>.</li>
      <li><strong>Párrafos:</strong> dejá un renglón en blanco entre uno y otro.</li>
      <li>Si dejás vacío un campo marcado como <em>opcional</em>, esa parte no se muestra. Si vaciás un título, vuelve el original.</li>
    </ul>
  </details>

  <form method="post" class="stack sitio-form" id="sitio-textos">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="section" value="<?= h($section) ?>">
    <input type="hidden" name="action" value="save">
    <?php foreach ($sec['fields'] as $key => $field): ?>
      <?php
        [$label, $type, $default] = $field;
        $optional = !empty($field[3]);
        $value = array_key_exists($key, $saved) && is_string($saved[$key]) ? $saved[$key] : $default;
        $changed = array_key_exists($key, $saved);
        $id = 'f-' . preg_replace('/[^a-z0-9]/i', '-', $key);
        $isGroupStart = $section === 'servicios' && str_ends_with($key, '_title');
      ?>
      <?php if ($isGroupStart): ?><div class="sitio-group"><?php endif; ?>
      <div class="sitio-field">
        <label for="<?= h($id) ?>">
          <span class="sitio-label"><?= h($label) ?><?php if ($optional): ?> <em>opcional</em><?php endif; ?><?php if ($changed): ?> <b class="badge soft">modificado</b><?php endif; ?></span>
        </label>
        <?php if (in_array($type, ['text', 'rich', 'list'], true)): ?>
          <div class="sitio-tools" data-tools-for="<?= h($id) ?>">
            <button type="button" class="btn ghost small-btn" data-wrap="bold" title="Negrita"><b>N</b></button>
            <button type="button" class="btn ghost small-btn" data-wrap="link" title="Enlace"><?= aca_icon('link') ?> Enlace</button>
          </div>
          <textarea id="<?= h($id) ?>" name="t[<?= h($key) ?>]" rows="<?= $type === 'rich' ? 7 : ($type === 'list' ? 4 : 3) ?>" maxlength="<?= (int) $limits[$type] ?>"><?= h($value) ?></textarea>
        <?php else: ?>
          <input id="<?= h($id) ?>" type="text" name="t[<?= h($key) ?>]" value="<?= h($value) ?>" maxlength="<?= (int) $limits[$type] ?>"<?= $type === 'url' ? ' inputmode="url" spellcheck="false"' : '' ?>>
        <?php endif; ?>
        <?php if ($type === 'url'): ?>
          <span class="hint">Una página del sitio (por ejemplo <code>turnos/</code>, <code>academia/</code>, <code>#contacto</code>) o una dirección completa que empiece con https://</span>
        <?php endif; ?>
        <?php if ($changed && $default !== ''): ?>
          <details class="sitio-orig"><summary>Ver el texto original</summary><p><?= nl2br(h($default), false) ?></p></details>
        <?php endif; ?>
      </div>
      <?php if ($section === 'servicios' && str_ends_with($key, '_text')): ?></div><?php endif; ?>
    <?php endforeach; ?>
    <div class="actions sitio-actions">
      <button class="btn primary" type="submit"><?= aca_icon('check') ?> Guardar cambios</button>
      <a class="btn secondary" href="../../#<?= h($sec['anchor']) ?>" target="_blank" rel="noopener"><?= aca_icon('external') ?> Ver en el sitio</a>
    </div>
  </form>
  <form method="post" class="sitio-reset" onsubmit="return confirm('¿Volver a los textos originales de esta parte? (Se puede deshacer)');">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="section" value="<?= h($section) ?>">
    <input type="hidden" name="action" value="reset">
    <button class="btn ghost small-btn" type="submit"><?= aca_icon('refresh') ?> Volver a los textos originales de "<?= h($sec['label']) ?>"</button>
  </form>
</section>

<?php sitio_admin_preview($sec['anchor']); ?>

<script>
(function () {
  document.querySelectorAll('[data-tools-for]').forEach(function (bar) {
    var ta = document.getElementById(bar.getAttribute('data-tools-for'));
    if (!ta) { return; }
    bar.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-wrap]');
      if (!b) { return; }
      var start = ta.selectionStart, end = ta.selectionEnd;
      var sel = ta.value.slice(start, end);
      var out;
      if (b.getAttribute('data-wrap') === 'bold') {
        out = '**' + (sel || 'texto en negrita') + '**';
      } else {
        var url = window.prompt('Pegá la dirección del enlace (https://...)', 'https://');
        if (!url || url === 'https://') { return; }
        out = '[' + (sel || 'texto del enlace') + '](' + url.trim() + ')';
      }
      ta.setRangeText(out, start, end, 'end');
      ta.focus();
      ta.dispatchEvent(new Event('input'));
    });
  });
  var form = document.getElementById('sitio-textos');
  var dirty = false;
  form.addEventListener('input', function () { dirty = true; });
  form.addEventListener('submit', function () { dirty = false; });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
