<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_admin();
require_once __DIR__ . '/../includes/sitio_admin.php';

$self = 'sitio_contacto.php';
sitio_admin_handle_undo($self);
$defaults = sitio_contact_defaults();
$maxSedes = 4;
$errors = [];
$form = sitio_contact();

/** Número de WhatsApp en formato internacional (solo dígitos). Acepta "2932 53-7949", "+54 9 2932…", "02932 15 537949". */
function sitio_normalize_wa(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (!str_starts_with($digits, '54') && strlen($digits) >= 10 && strlen($digits) <= 13) {
        $digits = ltrim($digits, '0');
        if (preg_match('/^(\d{2,4})15(\d{6,8})$/', $digits, $m) && strlen($m[1] . $m[2]) === 10) {
            $digits = $m[1] . $m[2];
        }
        if (strlen($digits) === 10) {
            $digits = '549' . $digits;
        }
    }
    if (str_starts_with($digits, '54') && !str_starts_with($digits, '549') && strlen($digits) === 12) {
        $digits = '549' . substr($digits, 2);
    }
    return preg_match('/^\d{8,15}$/', $digits) ? $digits : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sitio_admin_require_post($self);
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'reset') {
        $data = sitio_load(true);
        $data['contact'] = [];
        sitio_save($data, 'Contacto original')
            ? flash('success', 'Volvieron los datos de contacto originales.')
            : flash('error', 'No se pudo guardar.');
        sitio_admin_go($self);
    }

    if ($action === 'save') {
        $in = static fn (string $k, int $max = 300): string => trim(str_replace("\n", ' ', sitio_admin_clip((string) ($_POST[$k] ?? ''), $max)));
        $form['email'] = $in('email', 120);
        $form['wa_label'] = $in('wa_label', 40);
        $form['wa_message'] = sitio_admin_clip((string) ($_POST['wa_message'] ?? ''), 500);
        $form['wa_message_acu'] = sitio_admin_clip((string) ($_POST['wa_message_acu'] ?? ''), 500);
        $form['phone'] = $in('phone', 40);
        $form['instagram'] = $in('instagram');
        $form['youtube'] = $in('youtube');
        $form['facebook'] = $in('facebook');
        $form['online_note'] = $in('online_note', 200);
        $form['hours'] = sitio_admin_clip((string) ($_POST['hours'] ?? ''), 400);

        $waRaw = $in('wa_number', 40);
        $wa = sitio_normalize_wa($waRaw);
        if ($wa === null) {
            $errors['wa_number'] = 'El número de WhatsApp no parece válido. Escribilo con la característica, por ejemplo 2932 537949.';
            $form['wa_number'] = $waRaw;
        } else {
            $form['wa_number'] = $wa;
        }
        if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'El email no es válido.';
        }
        if ($form['phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{6,25}$/', $form['phone'])) {
            $errors['phone'] = 'El teléfono solo puede tener números, espacios, guiones y paréntesis.';
        }
        $socialHosts = ['instagram' => 'instagram.com', 'youtube' => 'youtube.com', 'facebook' => 'facebook.com'];
        foreach ($socialHosts as $k => $host) {
            $v = $form[$k];
            if ($v === '') {
                continue;
            }
            if (!preg_match('~^https?://~i', $v)) {
                $v = 'https://' . ltrim($v, '/');
            }
            $h = strtolower((string) parse_url($v, PHP_URL_HOST));
            $ok = sitio_valid_url($v) && ($h === $host || str_ends_with($h, '.' . $host) || ($k === 'youtube' && $h === 'youtu.be') || ($k === 'facebook' && $h === 'fb.com'));
            if (!$ok) {
                $errors[$k] = 'El link de ' . ucfirst($k) . ' no es válido. Copiá la dirección completa de tu perfil (por ejemplo https://www.' . $host . '/fluxusterapia).';
            }
            $form[$k] = preg_replace('~^http://~i', 'https://', $v) ?? $v;
        }
        if ($form['wa_label'] === '' && $form['wa_number'] !== '') {
            $form['wa_label'] = $form['wa_number'];
        }

        $sedes = [];
        $postedSedes = is_array($_POST['sedes'] ?? null) ? $_POST['sedes'] : [];
        foreach (array_slice($postedSedes, 0, $maxSedes) as $i => $s) {
            if (!is_array($s)) {
                continue;
            }
            $sede = [
                'name' => trim(str_replace("\n", ' ', sitio_admin_clip((string) ($s['name'] ?? ''), 80))),
                'address' => trim(str_replace("\n", ' ', sitio_admin_clip((string) ($s['address'] ?? ''), 160))),
                'maps' => trim(sitio_admin_clip((string) ($s['maps'] ?? ''), 500)),
                'hours' => sitio_admin_clip((string) ($s['hours'] ?? ''), 300),
            ];
            if ($sede['name'] === '' && $sede['address'] === '') {
                continue;
            }
            if ($sede['maps'] !== '' && !sitio_valid_url($sede['maps'])) {
                $errors['sede' . $i] = 'El link de Google Maps de "' . ($sede['name'] ?: 'una sede') . '" no es válido. Copialo desde Google Maps → Compartir → Copiar vínculo, o dejalo vacío.';
            }
            $sedes[] = $sede;
        }
        $form['sedes'] = $sedes;

        if (!$errors) {
            $data = sitio_load(true);
            $contact = [];
            foreach ($defaults as $k => $def) {
                if ($form[$k] !== $def) {
                    $contact[$k] = $form[$k];
                }
            }
            $data['contact'] = $contact;
            sitio_save($data, 'Contacto y WhatsApp')
                ? flash('success', 'Guardado. El botón de WhatsApp, el pie y la sección Contacto ya usan los datos nuevos.')
                : flash('error', 'No se pudo guardar. Probá de nuevo.');
            sitio_admin_go($self);
        }
    }
}

$sedesForm = array_values($form['sedes']);
while (count($sedesForm) < $maxSedes) {
    $sedesForm[] = ['name' => '', 'address' => '', 'maps' => '', 'hours' => ''];
}
$err = static fn (string $k): string => isset($errors[$k]) ? '<span class="field-error">' . h($errors[$k]) . '</span>' : '';

$pageTitle = 'Contacto y WhatsApp';
$basePath = '../';
$assetPrefix = '../';
require __DIR__ . '/../includes/header.php';
sitio_admin_header('sitio_contacto.php', 'Contacto y WhatsApp', 'Teléfono, WhatsApp con su mensaje automático, email, redes y direcciones. Se usan en todo el sitio: botón flotante, sección Contacto y pie de página.', 'contacto');
?>
<?php if ($errors): ?>
  <div class="alert error" role="alert">Revisá los campos marcados en rojo. No se guardó nada todavía.</div>
<?php endif; ?>

<form method="post" class="sitio-contact" id="sitio-contacto">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">

  <section class="panel">
    <h2><?= aca_icon('whatsapp') ?> WhatsApp</h2>
    <div class="form-grid two">
      <label>Número de WhatsApp
        <input type="text" name="wa_number" value="<?= h($form['wa_number']) ?>" inputmode="tel" autocomplete="off" data-wa-number placeholder="2932 537949" required>
        <span class="hint">Con característica, sin 0 ni 15. Se guarda en formato internacional (54 9 …).</span>
        <?= $err('wa_number') ?>
      </label>
      <label>Cómo se muestra el número
        <input type="text" name="wa_label" value="<?= h($form['wa_label']) ?>" placeholder="2932 537949">
        <span class="hint">Lo que lee la gente en la sección Contacto.</span>
      </label>
    </div>
    <label>Mensaje automático
      <textarea name="wa_message" rows="2" data-wa-message="general" placeholder="Hola FluxusTerapia, quiero consultar por un turno"><?= h($form['wa_message']) ?></textarea>
      <span class="hint">Es el texto que aparece ya escrito cuando alguien toca el botón verde de WhatsApp.</span>
    </label>
    <label>Mensaje automático del botón de Acupuntura
      <textarea name="wa_message_acu" rows="2" data-wa-message="acu"><?= h($form['wa_message_acu']) ?></textarea>
    </label>
    <div class="sitio-wa-test">
      <a class="btn whatsapp small-btn" href="<?= h(sitio_wa_url()) ?>" target="_blank" rel="noopener" data-wa-test="general"><?= aca_icon('whatsapp') ?> Probar el botón</a>
      <a class="btn secondary small-btn" href="<?= h(sitio_wa_url($form['wa_message_acu'])) ?>" target="_blank" rel="noopener" data-wa-test="acu">Probar el de Acupuntura</a>
      <code class="sitio-wa-url" data-wa-url><?= h(sitio_wa_url()) ?></code>
    </div>
  </section>

  <section class="panel">
    <h2><?= aca_icon('mail') ?> Email y teléfono</h2>
    <div class="form-grid two">
      <label>Email
        <input type="email" name="email" value="<?= h($form['email']) ?>" placeholder="hola@fluxusterapia.com">
        <?= $err('email') ?>
      </label>
      <label><span>Teléfono fijo <span class="muted small">(opcional)</span></span>
        <input type="text" name="phone" value="<?= h($form['phone']) ?>" inputmode="tel" placeholder="Ej: 2932 123456">
        <span class="hint">Si lo completás, aparece en Contacto y se puede tocar para llamar.</span>
        <?= $err('phone') ?>
      </label>
    </div>
  </section>

  <section class="panel">
    <h2><?= aca_icon('globe') ?> Redes sociales</h2>
    <p class="muted small">Pegá la dirección completa del perfil. Si dejás una vacía, su ícono no se muestra.</p>
    <div class="form-grid two">
      <label>Instagram <input type="url" name="instagram" value="<?= h($form['instagram']) ?>" placeholder="https://www.instagram.com/fluxusterapia/"><?= $err('instagram') ?></label>
      <label>YouTube <input type="url" name="youtube" value="<?= h($form['youtube']) ?>" placeholder="https://www.youtube.com/@fluxusterapia"><?= $err('youtube') ?></label>
      <label><span>Facebook <span class="muted small">(opcional)</span></span> <input type="url" name="facebook" value="<?= h($form['facebook']) ?>" placeholder="https://www.facebook.com/…"><?= $err('facebook') ?></label>
    </div>
  </section>

  <section class="panel">
    <h2><?= aca_icon('home') ?> Consultorios y horarios</h2>
    <p class="muted small">Hasta <?= $maxSedes ?> sedes. Si ponés la dirección, en el sitio se puede tocar para abrir Google Maps (el link se arma solo; si querés uno exacto, pegalo desde Google Maps → Compartir).</p>
    <div class="sitio-sedes">
      <?php $shownEmpty = false; ?>
      <?php foreach ($sedesForm as $i => $s): ?>
        <?php
          $isEmpty = trim((string) ($s['name'] ?? '')) === '' && trim((string) ($s['address'] ?? '')) === '';
          $hide = $isEmpty && $shownEmpty;
          $shownEmpty = $shownEmpty || $isEmpty;
        ?>
        <fieldset class="check-box sitio-sede"<?= $hide ? ' hidden data-sede-extra' : '' ?>>
          <legend>Sede <?= $i + 1 ?></legend>
          <div class="form-grid two">
            <label>Nombre o ciudad <input type="text" name="sedes[<?= $i ?>][name]" value="<?= h((string) ($s['name'] ?? '')) ?>" placeholder="Ej: Punta Alta"></label>
            <label><span>Dirección <span class="muted small">(opcional)</span></span> <input type="text" name="sedes[<?= $i ?>][address]" value="<?= h((string) ($s['address'] ?? '')) ?>" placeholder="Ej: Rivadavia 123"></label>
            <label><span>Link de Google Maps <span class="muted small">(opcional)</span></span> <input type="url" name="sedes[<?= $i ?>][maps]" value="<?= h((string) ($s['maps'] ?? '')) ?>" placeholder="https://maps.app.goo.gl/…"><?= $err('sede' . $i) ?></label>
            <label><span>Días y horarios <span class="muted small">(opcional)</span></span> <textarea name="sedes[<?= $i ?>][hours]" rows="2" placeholder="Ej: Lunes a viernes de 9 a 13"><?= h((string) ($s['hours'] ?? '')) ?></textarea></label>
          </div>
        </fieldset>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn secondary small-btn" data-add-sede style="margin-top:0.8rem"><?= aca_icon('plus') ?> Agregar otra sede</button>
    <div class="form-grid two" style="margin-top:1rem">
      <label><span>Línea extra debajo de las sedes <span class="muted small">(opcional)</span></span>
        <input type="text" name="online_note" value="<?= h($form['online_note']) ?>">
      </label>
      <label><span>Atención <span class="muted small">(opcional)</span></span>
        <textarea name="hours" rows="2"><?= h($form['hours']) ?></textarea>
      </label>
    </div>
  </section>

  <div class="actions sitio-actions sitio-sticky">
    <button class="btn primary" type="submit"><?= aca_icon('check') ?> Guardar cambios</button>
    <a class="btn secondary" href="../../#contacto" target="_blank" rel="noopener"><?= aca_icon('external') ?> Ver en el sitio</a>
  </div>
</form>
<form method="post" class="sitio-reset" onsubmit="return confirm('¿Volver a los datos de contacto originales? (Se puede deshacer)');">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="reset">
  <button class="btn ghost small-btn" type="submit"><?= aca_icon('refresh') ?> Volver a los datos originales</button>
</form>

<script>
(function () {
  var num = document.querySelector('[data-wa-number]');
  var msgs = document.querySelectorAll('[data-wa-message]');
  var out = document.querySelector('[data-wa-url]');
  function digits(v) {
    var d = v.replace(/\D+/g, '');
    if (d.indexOf('00') === 0) { d = d.slice(2); }
    if (d.indexOf('54') !== 0 && d.length >= 10 && d.length <= 13) {
      d = d.replace(/^0+/, '');
      var m = d.match(/^(\d{2,4})15(\d{6,8})$/);
      if (m && (m[1] + m[2]).length === 10) { d = m[1] + m[2]; }
      if (d.length === 10) { d = '549' + d; }
    }
    return d;
  }
  function update() {
    var n = digits(num.value);
    msgs.forEach(function (ta) {
      var key = ta.getAttribute('data-wa-message');
      var url = 'https://wa.me/' + n + (ta.value.trim() ? '?text=' + encodeURIComponent(ta.value) : '');
      var a = document.querySelector('[data-wa-test="' + key + '"]');
      if (a) { a.href = url; }
      if (key === 'general') { out.textContent = url; }
    });
  }
  var add = document.querySelector('[data-add-sede]');
  function syncAdd() { add.hidden = !document.querySelector('[data-sede-extra][hidden]'); }
  add.addEventListener('click', function () {
    var next = document.querySelector('[data-sede-extra][hidden]');
    if (next) { next.hidden = false; next.querySelector('input').focus(); }
    syncAdd();
  });
  syncAdd();
  num.addEventListener('input', update);
  msgs.forEach(function (ta) { ta.addEventListener('input', update); });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
