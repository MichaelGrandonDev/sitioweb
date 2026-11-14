<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
require_once __DIR__ . '/../includes/sitio_admin.php';
require_once __DIR__ . '/../../includes/sitio_img.php';

$self = 'sitio_fotos.php';
sitio_admin_handle_undo($self);
$slots = sitio_photo_slots();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'La foto es demasiado pesada para el servidor. Probá con una más chica.');
        sitio_admin_go($self);
    }
    sitio_admin_require_post($self);
    $action = (string) ($_POST['action'] ?? '');
    $slot = (string) ($_POST['slot'] ?? '');
    if (!isset($slots[$slot])) {
        sitio_admin_go($self);
    }
    $def = $slots[$slot];
    $back = $self . '#foto-' . $slot;
    $data = sitio_load(true);
    $entry = is_array($data['photos'][$slot] ?? null) ? $data['photos'][$slot] : [];

    if ($action === 'upload') {
        $file = $_FILES['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $hasFile = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $alt = $def['alt'] !== false ? sitio_admin_clip((string) ($_POST['alt'] ?? ''), 200) : null;
        $alt = $alt !== null ? trim(str_replace("\n", ' ', $alt)) : null;

        if ($hasFile) {
            $result = sitio_img_process_upload($file, $slot, $def);
            if (!$result['ok']) {
                flash('error', (string) $result['error']);
                sitio_admin_go($back);
            }
            $entry = array_merge($result['photo'], isset($entry['alt']) ? ['alt' => $entry['alt']] : []);
        }
        if ($alt !== null) {
            if ($alt === '' || $alt === $def['alt']) {
                unset($entry['alt']);
            } else {
                $entry['alt'] = $alt;
            }
        }
        if (!$hasFile && $alt === null) {
            flash('error', 'Elegí una foto para subir.');
            sitio_admin_go($back);
        }
        if ($entry) {
            $data['photos'][$slot] = $entry;
        } else {
            unset($data['photos'][$slot]);
        }
        $label = ($hasFile ? 'Foto nueva: ' : 'Texto de la foto: ') . $def['label'];
        if (sitio_save($data, $label)) {
            if ($hasFile) {
                flash('success', 'Foto publicada. Se optimizó en ' . count($entry['webp']) . ' tamaños (' . sitio_admin_format_bytes((int) $entry['bytes']) . ' en total).');
            } else {
                flash('success', 'Guardado.');
            }
        } else {
            flash('error', 'No se pudo guardar. Probá de nuevo.');
        }
        sitio_admin_go($back);
    }

    if ($action === 'reset') {
        unset($data['photos'][$slot]);
        sitio_save($data, 'Foto original: ' . $def['label'])
            ? flash('success', 'Volvió la foto original de "' . $def['label'] . '". Si te arrepentís, tocá "Deshacer último cambio".')
            : flash('error', 'No se pudo guardar. Probá de nuevo.');
        sitio_admin_go($back);
    }
    sitio_admin_go($self);
}

$pageTitle = 'Fotos del sitio';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
sitio_admin_header('sitio_fotos.php', 'Fotos de la página', 'Subí o cambiá las fotos de la página de inicio. Se achican y optimizan solas para que el sitio cargue rápido en el celular.');
?>
<div class="sitio-photo-grid">
  <?php foreach ($slots as $slot => $def): ?>
    <?php $p = sitio_photo($slot); $saved = sitio_load()['photos'][$slot] ?? []; ?>
    <section class="panel sitio-photo" id="foto-<?= h($slot) ?>">
      <div class="sitio-photo-media" style="aspect-ratio: <?= $def['ratio'] ? round($def['ratio'], 4) : '16 / 9' ?>">
        <img src="<?= h(str_starts_with($p['preview'], '/') ? '../..' . $p['preview'] : '../../' . $p['preview']) ?>" alt="" loading="lazy">
        <span class="badge <?= $p['custom'] ? 'done' : 'soft' ?>"><?= $p['custom'] ? 'Foto nueva' : 'Foto original' ?></span>
      </div>
      <div class="sitio-photo-body">
        <h2><?= h($def['label']) ?></h2>
        <p class="muted small"><?= h($def['where']) ?></p>
        <?php if ($p['custom'] && !empty($saved['bytes'])): ?>
          <p class="small sitio-photo-meta"><?= aca_icon('check') ?> Optimizada: <?= count($p['webp']) ?> tamaños, <?= (int) $p['w'] ?>×<?= (int) $p['h'] ?> px, <?= h(sitio_admin_format_bytes((int) $saved['bytes'])) ?> en total</p>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data" class="stack" data-photo-form>
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="slot" value="<?= h($slot) ?>">
          <label class="dropzone" data-dropzone>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif">
            <span class="dz-ico"><?= aca_icon('upload') ?></span>
            <strong data-dz-title><?= $p['custom'] ? 'Cambiar por otra foto' : 'Subir una foto nueva' ?></strong>
            <span data-dz-sub><?= h($def['tip']) ?></span>
          </label>
          <?php if ($def['alt'] !== false): ?>
            <label><span>Descripción de la foto <span class="muted small">(para Google y personas ciegas)</span></span>
              <input type="text" name="alt" maxlength="200" value="<?= h($p['alt']) ?>" placeholder="Ej: Sesión de masaje en el consultorio">
            </label>
          <?php endif; ?>
          <div class="upload-status" hidden data-status>
            <div class="progress"><span style="width:0%"></span></div>
            <div class="upload-line"><strong data-status-text>Preparando la foto…</strong><span data-status-size></span></div>
          </div>
          <div class="actions">
            <button class="btn primary" type="submit"><?= aca_icon('upload') ?> <?= $def['alt'] !== false ? 'Guardar' : 'Subir foto' ?></button>
          </div>
        </form>
        <?php if ($p['custom']): ?>
          <form method="post" onsubmit="return confirm('¿Sacar esta foto y volver a la original? (Se puede deshacer)');">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="slot" value="<?= h($slot) ?>">
            <button class="btn ghost small-btn" type="submit"><?= aca_icon('trash') ?> Sacar esta foto y volver a la original</button>
          </form>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <a class="panel sitio-photo sitio-photo-link" href="../../galeria/admin.php">
    <span class="stat-ico"><?= aca_icon('image') ?></span>
    <span>
      <strong>Galería de fotos y videos</strong>
      <span class="muted small">La página Galería tiene su propio panel: agregar, ordenar y borrar fotos y videos. Las fotos grandes también se achican solas.</span>
    </span>
    <?= aca_icon('arrow-right') ?>
  </a>
</div>

<script>
(function () {
  var MAX_SIDE = 2800;
  var QUALITY = 0.9;

  function fmt(bytes) {
    return bytes >= 1048576 ? (bytes / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { resolve({ img: img, url: url }); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
      img.src = url;
    });
  }

  /* Achica la foto en el navegador antes de subirla: sube rápido y no choca con el límite del servidor. */
  function shrink(file) {
    if (!/^image\//.test(file.type) && !/\.(heic|heif)$/i.test(file.name)) { return Promise.resolve(file); }
    return loadImage(file).then(function (r) {
      var w = r.img.naturalWidth, h = r.img.naturalHeight;
      var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
      if (scale === 1 && file.size < 3 * 1048576 && /jpe?g$/i.test(file.type)) { URL.revokeObjectURL(r.url); return file; }
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(w * scale);
      canvas.height = Math.round(h * scale);
      var ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(r.img, 0, 0, canvas.width, canvas.height);
      URL.revokeObjectURL(r.url);
      return new Promise(function (resolve) {
        canvas.toBlob(function (blob) {
          if (!blob || blob.size >= file.size) { resolve(file); return; }
          var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
          resolve(new File([blob], name, { type: 'image/jpeg' }));
        }, 'image/jpeg', QUALITY);
      });
    }).catch(function () { return file; });
  }

  document.querySelectorAll('[data-photo-form]').forEach(function (form) {
    var input = form.querySelector('input[type=file]');
    var dz = form.querySelector('[data-dropzone]');
    var title = form.querySelector('[data-dz-title]');
    var sub = form.querySelector('[data-dz-sub]');
    var status = form.querySelector('[data-status]');
    var bar = status.querySelector('.progress span');
    var statusText = form.querySelector('[data-status-text]');
    var statusSize = form.querySelector('[data-status-size]');
    var media = form.closest('.sitio-photo').querySelector('.sitio-photo-media img');
    var ready = null;

    ['dragenter', 'dragover'].forEach(function (n) { dz.addEventListener(n, function () { dz.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (n) { dz.addEventListener(n, function () { dz.classList.remove('is-over'); }); });

    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      ready = null;
      if (!file) { return; }
      dz.classList.add('has-file');
      title.textContent = file.name;
      sub.textContent = 'Preparando… (' + fmt(file.size) + ')';
      ready = shrink(file).then(function (out) {
        sub.textContent = out !== file ? 'Lista para subir: ' + fmt(file.size) + ' → ' + fmt(out.size) : 'Lista para subir (' + fmt(out.size) + ')';
        if (/^image\/(jpeg|png|webp)$/.test(out.type)) { media.src = URL.createObjectURL(out); }
        return out;
      });
    });

    form.addEventListener('submit', function (ev) {
      var file = input.files && input.files[0];
      if (!file) { return; }
      ev.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      btn.disabled = true;
      status.hidden = false;
      statusText.textContent = 'Preparando la foto…';
      (ready || shrink(file)).then(function (out) {
        var fd = new FormData(form);
        fd.set('photo', out, out.name);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action') || window.location.pathname);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        xhr.upload.onprogress = function (e) {
          if (!e.lengthComputable) { return; }
          var pct = Math.round(e.loaded / e.total * 100);
          bar.style.width = pct + '%';
          statusText.textContent = pct < 100 ? 'Subiendo… ' + pct + '%' : 'Optimizando la foto…';
          statusSize.textContent = fmt(e.total);
        };
        xhr.onload = function () {
          var go = null;
          try { go = JSON.parse(xhr.responseText).go; } catch (e) { go = null; }
          if (go) {
            var parts = go.split('#');
            window.location.replace(parts[0] + (parts[0].indexOf('?') === -1 ? '?' : '&') + 'r=' + Date.now() + (parts[1] ? '#' + parts[1] : ''));
            return;
          }
          btn.disabled = false;
          statusText.textContent = xhr.status === 413 ? 'La foto es demasiado pesada para el servidor.' : 'No se pudo subir (error ' + xhr.status + '). Probá de nuevo.';
        };
        xhr.onerror = function () {
          btn.disabled = false;
          statusText.textContent = 'Se cortó la conexión. Probá de nuevo.';
        };
        xhr.send(fd);
      });
    });
  });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
