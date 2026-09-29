<?php

declare(strict_types=1);

/** Catálogo MTC editable: «Diagnósticos y tratamientos» (patrones) e «Indicaciones». Se guarda solo después de la vista previa. */

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/mtc_plan.php';
require_once __DIR__ . '/includes/admin_ui.php';

$tab = ($_GET['tab'] ?? $_POST['tab'] ?? '') === 'indicaciones' ? 'indicaciones' : 'patrones';
$self = 'mtc_catalogo.php?tab=' . $tab;
$patterns = mtc_catalog_patterns(true);
$indications = mtc_catalog_indications(true);
$mode = 'list';
$key = null;
$item = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida. Volvé a intentar.');
        redirect($self);
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $key = is_string($_POST['key'] ?? null) && $_POST['key'] !== '' ? $_POST['key'] : null;
    $pool = $tab === 'patrones' ? $patterns : $indications;
    if ($key !== null && !isset($pool[$key])) {
        flash('error', 'No se encontró ese elemento del catálogo.');
        redirect($self);
    }
    $table = $tab === 'patrones' ? 'patterns' : 'indications';

    if ($action === 'toggle' && $key !== null) {
        $on = !$pool[$key]['_active'];
        mtc_catalog_set_active($table, $key, $on);
        flash('success', ($on ? 'Activado: ' : 'Desactivado (los planes que ya lo usan no cambian): ') . ($tab === 'patrones' ? $pool[$key]['label'] : mb_substr($pool[$key]['text'], 0, 60) . '…'));
        redirect($self);
    }
    if ($action === 'reset' && $key !== null) {
        $ok = mtc_catalog_reset($table, $key);
        flash($ok ? 'success' : 'error', $ok ? 'Volvió al texto de fábrica.' : 'Ese elemento no es de fábrica.');
        redirect($self . '&edit=' . rawurlencode($key));
    }
    if (in_array($action, ['preview', 'save'], true)) {
        $item = $tab === 'patrones' ? mtc_pattern_from_post($_POST) : mtc_indication_from_post($_POST);
        if ($tab === 'patrones') {
            if ($item['label'] === '') {
                $errors[] = 'Falta el nombre del patrón.';
            }
            if ($item['plain'] === '') {
                $errors[] = 'Falta la explicación para el paciente.';
            }
            foreach ($patterns as $k => $p) {
                if ($k !== $key && mb_strtolower($p['label']) === mb_strtolower($item['label'])) {
                    $errors[] = 'Ya hay un patrón con ese nombre.';
                }
            }
        } elseif ($item['text'] === '') {
            $errors[] = 'Falta el texto de la indicación.';
        }
        if ($action === 'save' && !$errors) {
            $saved = $tab === 'patrones' ? mtc_catalog_save_pattern($item, $key) : mtc_catalog_save_indication($item, $key);
            flash('success', ($key === null ? 'Agregado al catálogo.' : 'Cambios guardados.') . ' Se usa desde el próximo plan que generes; los planes guardados no cambian.');
            redirect($self . '#item-' . rawurlencode($saved));
        }
        $mode = 'preview';
    }
} elseif (isset($_GET['edit'])) {
    $key = (string) $_GET['edit'];
    $pool = $tab === 'patrones' ? $patterns : $indications;
    if (!isset($pool[$key])) {
        flash('error', 'No se encontró ese elemento del catálogo.');
        redirect($self);
    }
    $item = $pool[$key];
    $mode = 'edit';
} elseif (($_GET['nuevo'] ?? '') === '1') {
    $item = $tab === 'patrones' ? mtc_pattern_normalize(['organ' => 'Hígado']) : mtc_indication_normalize([]);
    if ($tab === 'patrones') {
        $item['meridian'] = $item['moxa_note'] = '';
        $item['tuina']['maniobras'] = $item['qigong']['sesion'] = $item['qigong']['casa'] = '';
        $item['ventosas'] = ['zona' => '', 'modo' => ''];
        $item['oreja'] = [];
    }
    $mode = 'edit';
}

$flash = take_flash();
$csrf = csrf_token();

function cat_select(string $name, array $opts, string $value, string $empty): string
{
    $html = '<select name="' . h($name) . '">';
    foreach ($opts as $k => $label) {
        $v = is_int($k) ? $label : $k;
        $html .= '<option value="' . h($v) . '"' . ($v === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

function cat_codes(array $codes): string
{
    return implode(', ', $codes);
}

function cat_organ_groups(array $patterns): array
{
    $out = [];
    foreach (MTC_ORGANS as $o) {
        foreach ($patterns as $k => $p) {
            if ($p['organ'] === $o) {
                $out[$o][$k] = $p;
            }
        }
    }
    return $out;
}

/** Cómo queda el patrón: lo que usa el generador del plan. */
function cat_pattern_preview(array $p): string
{
    $ctx = ['pregnant' => false, 'fever' => false, 'sens' => false, 'skin' => false, 'heatish' => false, 'heat' => [], 'hotdx' => []];
    $mp = $p['moxa_mode'] === 'no' || !$p['moxa'] ? ['moxa' => [], 'press' => []] : mtc_moxa_plan($p['moxa'], $ctx + ['brief' => $p['moxa_mode'] === 'cauto']);
    $rows = [
        'Órgano' => $p['organ'] . ($p['meridian'] !== '' ? ' · meridianos: ' . $p['meridian'] : ''),
        'Signos (resumen)' => $p['signs'],
        'Principio de tratamiento' => $p['principle'],
        'Tuina' => $p['tuina']['maniobras'] . ($p['tuina']['acupresion'] ? '. Acupresión: ' . mtc_points_text($p['tuina']['acupresion']) : ''),
        'Chi kung' => 'En la sesión: ' . $p['qigong']['sesion'] . '. En casa: ' . $p['qigong']['casa'] . ' (' . $p['qigong']['min'] . ' min por día).',
        'Moxa' => $p['moxa_mode'] === 'no' ? 'No (patrón con calor o Yang que asciende): acupresión en el tuina.' . ($p['moxa'] ? ' Los puntos cargados no se moxan.' : '')
            : ($mp['moxa'] ? implode('; ', array_map(static fn ($c, $m) => mtc_point_label($c) . ': ' . $m, array_keys($mp['moxa']), $mp['moxa'])) : 'Sin puntos para moxar.')
            . ($mp['press'] ? ' Con acupresión: ' . mtc_points_text(array_keys($mp['press'])) . '.' : '') . ($p['moxa_mode'] === 'cauto' ? ' (con cautela, en picoteo)' : ''),
        'Ventosas' => $p['no_cups'] ? 'No (fragilidad capilar).' : $p['ventosas']['zona'] . ', ' . $p['ventosas']['modo'] . '.',
        'Auriculoterapia' => implode(', ', $p['oreja']),
        'Puntos de referencia' => mtc_points_text($p['points']) . ($p['root'] ? ' · raíz: ' . mtc_points_text($p['root']) : ''),
        'Recomendaciones' => implode(' · ', $p['recs']),
        'Cuidados / contraindicaciones' => $p['contra_notes'],
    ];
    $ev = [];
    foreach ($p['evidence'] as $field => $vals) {
        $ev[] = (MTC_DIAG_LABELS[$field] ?? $field) . ': ' . implode(', ', $vals);
    }
    $rows['Señales que lo sugieren (lengua e interrogatorio)'] = implode(' · ', $ev);
    $html = '<p class="mtc-cat-plain"><strong>Para el paciente:</strong> ' . h($p['plain']) . '</p><dl class="mtc-protocol">';
    foreach ($rows as $t => $v) {
        if (trim($v) !== '') {
            $html .= '<dt>' . h($t) . '</dt><dd>' . h($v) . '</dd>';
        }
    }
    return $html . '</dl>';
}

function cat_checks(string $name, array $opts, array $values): string
{
    $html = '<div class="mtc-checks">';
    foreach ($opts as $v) {
        $html .= '<label class="check"><input type="checkbox" name="' . h($name) . '[]" value="' . h($v) . '"' . (in_array($v, $values, true) ? ' checked' : '') . '><span>' . h($v) . '</span></label>';
    }
    return $html . '</div>';
}

/** Patrones que abarca una regla de indicación, expandidos a claves (para tildar en el formulario). */
function cat_rule_keys(array $rules, array $patterns): array
{
    $keys = [];
    foreach (array_keys($patterns) as $k) {
        if (mtc_indication_matches(['patterns' => array_diff($rules, ['*'])], [$k])) {
            $keys[] = $k;
        }
    }
    return $keys;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <?= admin_head('Diagnósticos e indicaciones · Plan MTC', ['plan_mtc.css']) ?>
</head>
<body class="mtc-page has-tabbar">
  <?= admin_header('plan', 'Plan MTC') ?>
  <main class="wrap">
    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>
    <h1>Diagnósticos e indicaciones</h1>
    <p class="lede">Lo que usa «Generar plan»: los patrones con sus tratamientos (tuina, chi kung, moxa, ventosas y auriculoterapia, sin agujas) y las indicaciones que se proponen al paciente. Los planes ya guardados no cambian.</p>
    <nav class="mtc-tabs">
      <a href="plan_mtc.php">Planes</a>
      <a href="mtc_catalogo.php?tab=patrones" class="<?= $tab === 'patrones' ? 'is-on' : '' ?>">Diagnósticos y tratamientos (<?= count($patterns) ?>)</a>
      <a href="mtc_catalogo.php?tab=indicaciones" class="<?= $tab === 'indicaciones' ? 'is-on' : '' ?>">Indicaciones (<?= count($indications) ?>)</a>
    </nav>

<?php if ($mode === 'list'): ?>
    <div class="admin-search">
      <label class="sr-only" for="cat-q">Buscar en el catálogo</label>
      <input type="search" id="cat-q" placeholder="Buscar <?= $tab === 'patrones' ? 'patrón, signo u órgano' : 'indicación' ?>…" autocomplete="off" enterkeyhint="search" data-filter="#cat-list" data-filter-empty="#cat-none">
      <a class="btn primary" href="mtc_catalogo.php?tab=<?= h($tab) ?>&amp;nuevo=1">Agregar</a>
    </div>
    <p class="muted is-hidden" id="cat-none">Nada coincide con la búsqueda.</p>
<?php endif; ?>

<?php if ($mode === 'list' && $tab === 'patrones'): ?>
    <div id="cat-list">
    <?php foreach (cat_organ_groups($patterns) as $organ => $group): ?>
      <section class="panel" data-group>
        <h2><?= h($organ) ?></h2>
        <?php foreach ($group as $k => $p): ?>
          <article class="mtc-row<?= $p['_active'] ? '' : ' is-off' ?>" id="item-<?= h($k) ?>" data-search="<?= h($organ . ' ' . $p['label'] . ' ' . $p['signs']) ?>">
            <div>
              <strong><?= h($p['label']) ?></strong>
              <?= $p['moxa_mode'] === 'no' ? '<span class="tag warn">sin moxa</span>' : ($p['moxa_mode'] === 'cauto' ? '<span class="tag">moxa con cautela</span>' : '') ?>
              <?= $p['_active'] ? '' : '<span class="tag">desactivado</span>' ?> <?= $p['_seed'] ? '' : '<span class="tag ok">propio</span>' ?><br>
              <span class="muted small"><?= h($p['signs']) ?></span>
            </div>
            <div class="mtc-actions-inline">
              <a class="btn ghost" href="mtc_catalogo.php?tab=patrones&amp;edit=<?= h(rawurlencode($k)) ?>">Editar</a>
              <form method="post" action="mtc_catalogo.php"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="tab" value="patrones">
                <input type="hidden" name="key" value="<?= h($k) ?>"><button class="btn ghost" type="submit" name="action" value="toggle"><?= $p['_active'] ? 'Desactivar' : 'Activar' ?></button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
    </div>

<?php elseif ($mode === 'list'): ?>
    <div id="cat-list">
    <?php foreach (MTC_IND_CATEGORIES as $cat => $catLabel): ?>
      <?php $group = array_filter($indications, static fn ($i) => $i['category'] === $cat); ?>
      <?php if (!$group) continue; ?>
      <section class="panel" data-group>
        <h2><?= h($catLabel) ?></h2>
        <?php foreach ($group as $k => $i): ?>
          <article class="mtc-row<?= $i['_active'] ? '' : ' is-off' ?>" id="item-<?= h($k) ?>" data-search="<?= h($catLabel . ' ' . $i['text']) ?>">
            <div>
              <?= h($i['text']) ?><br>
              <?= $i['therapist'] ? '<span class="tag warn">Requiere indicación del terapeuta</span> ' : '' ?>
              <?= $i['_active'] ? '' : '<span class="tag">desactivada</span> ' ?>
              <span class="muted small">Se propone para: <?= h(in_array('*', $i['patterns'], true) ? 'todos los diagnósticos'
                  : (implode(', ', array_map(static fn ($pk) => $patterns[$pk]['label'] ?? $pk, cat_rule_keys($i['patterns'], $patterns))) ?: ($i['therapist'] ? 'se ofrece a mano' : 'ningún patrón (se ofrece a mano)'))) ?></span>
              <?php if ($i['caution'] !== ''): ?><br><span class="small mtc-caution">Cuidado: <?= h($i['caution']) ?></span><?php endif; ?>
            </div>
            <div class="mtc-actions-inline">
              <a class="btn ghost" href="mtc_catalogo.php?tab=indicaciones&amp;edit=<?= h(rawurlencode($k)) ?>">Editar</a>
              <form method="post" action="mtc_catalogo.php"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="tab" value="indicaciones">
                <input type="hidden" name="key" value="<?= h($k) ?>"><button class="btn ghost" type="submit" name="action" value="toggle"><?= $i['_active'] ? 'Desactivar' : 'Activar' ?></button></form>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
    </div>

<?php else: ?>
    <?php $isSeed = $key !== null && (($tab === 'patrones' ? $patterns : $indications)[$key]['_seed'] ?? false); ?>
    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($mode === 'preview' && !$errors): ?>
      <div class="mtc-preview-banner" role="status"><strong>Vista previa — todavía no se guardó.</strong> Revisá cómo queda y tocá «Guardar en el catálogo».</div>
      <section class="panel">
        <h2><?= h($tab === 'patrones' ? $item['label'] : 'Indicación') ?></h2>
        <?php if ($tab === 'patrones'): ?>
          <?= cat_pattern_preview($item) ?>
        <?php else: ?>
          <p class="mtc-ind-preview"><span aria-hidden="true">➢</span> <?= h($item['text']) ?></p>
          <dl class="mtc-protocol">
            <dt>Categoría</dt><dd><?= h(MTC_IND_CATEGORIES[$item['category']]) ?></dd>
            <dt>Se propone</dt><dd><?= h($item['therapist'] ? 'Nunca sola: se ofrece para sumarla a mano (requiere indicación del terapeuta).'
                : (in_array('*', $item['patterns'], true) ? 'Tildada en todos los planes.' : ($item['patterns'] ? 'Tildada con: ' . implode(', ', array_map(static fn ($pk) => $patterns[$pk]['label'] ?? $pk, $item['patterns'])) . '.' : 'Solo se ofrece para sumarla a mano.'))) ?></dd>
            <?php if ($item['caution'] !== ''): ?><dt>Cuidado</dt><dd><?= h($item['caution']) ?></dd><?php endif; ?>
          </dl>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <form method="post" action="mtc_catalogo.php" class="panel" id="cat-form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <input type="hidden" name="key" value="<?= h((string) $key) ?>">
      <h2><?= $key === null ? ($tab === 'patrones' ? 'Nuevo patrón' : 'Nueva indicación') : 'Editar' ?></h2>
  <?php if ($tab === 'patrones'): ?>
      <?php $p = $item; ?>
      <div class="form-grid">
        <label>Nombre (así aparece en el diagnóstico) <input name="label" maxlength="160" required value="<?= h($p['label']) ?>"></label>
        <label>Órgano <?= cat_select('organ', MTC_ORGANS, $p['organ'], 'General') ?></label>
        <label class="span-2">Signos (resumen para vos) <input name="signs" maxlength="300" value="<?= h($p['signs']) ?>"></label>
        <label class="span-2">Explicación para el paciente (palabras simples)
          <textarea name="plain" rows="3" required><?= h($p['plain']) ?></textarea></label>
        <label>Principio de tratamiento <input name="principle" maxlength="300" value="<?= h($p['principle']) ?>"></label>
        <label>Meridianos <input name="meridian" maxlength="160" value="<?= h($p['meridian']) ?>"></label>
        <label>Puntos de referencia <span class="muted small">(códigos, ej: H3, VB20, R3)</span><input name="points" value="<?= h(cat_codes($p['points'])) ?>"></label>
        <label>Puntos de la raíz <input name="root" value="<?= h(cat_codes($p['root'])) ?>"></label>

        <h3 class="span-2 mtc-sub">Moxibustión <span class="muted small">(en lugar de agujas)</span></h3>
        <label>¿Admite moxa?
          <?= cat_select('moxa_mode', ['si' => 'Sí', 'cauto' => 'Con cautela (breve, en picoteo)', 'no' => 'No (Yin deficiente con calor / Yang que asciende)'], $p['moxa_mode'], 'Sí') ?></label>
        <label>Puntos para moxar, en orden <input name="moxa" value="<?= h(cat_codes($p['moxa'])) ?>"></label>
        <label class="span-2">Nota de moxa <input name="moxa_note" maxlength="400" value="<?= h($p['moxa_note']) ?>"></label>
        <p class="span-2 hint">El método y la duración salen de cada punto (bastón, caja, cono sobre jengibre…). Los puntos que no se moxan (cara, dispersión, várices, planta del pie) pasan solos a acupresión o a su alternativa.</p>

        <h3 class="span-2 mtc-sub">Tuina y chi kung</h3>
        <p class="span-2 hint">Meridianos, nota de moxa, maniobras, chi kung, ventosas y oreja: lo que dejes vacío se completa según el órgano y si admite moxa (lo ves en la vista previa).</p>
        <label class="span-2">Maniobras de tuina <textarea name="tuina_maniobras" rows="2"><?= h($p['tuina']['maniobras']) ?></textarea></label>
        <label class="span-2">Acupresión dentro del tuina <input name="tuina_acupresion" value="<?= h(cat_codes($p['tuina']['acupresion'])) ?>"></label>
        <label class="span-2">Chi kung en la sesión <input name="qigong_sesion" maxlength="400" value="<?= h($p['qigong']['sesion']) ?>"></label>
        <label>Chi kung en casa (incluí el sonido del Liu Zi Jue) <textarea name="qigong_casa" rows="2"><?= h($p['qigong']['casa']) ?></textarea></label>
        <label>Minutos por día en casa <input type="number" name="qigong_min" min="5" max="30" value="<?= (int) $p['qigong']['min'] ?>"></label>

        <h3 class="span-2 mtc-sub">Ventosas y auriculoterapia</h3>
        <label>Zona de ventosas <input name="ventosas_zona" maxlength="300" value="<?= h($p['ventosas']['zona']) ?>"></label>
        <label>Modo y tiempo <input name="ventosas_modo" maxlength="200" value="<?= h($p['ventosas']['modo']) ?>"></label>
        <label class="check span-2"><input type="checkbox" name="no_cups" value="1" <?= $p['no_cups'] ? 'checked' : '' ?>><span>Sin ventosas en este patrón (ej: fragilidad capilar)</span></label>
        <label class="span-2">Puntos de oreja (semillas), separados por coma <input name="oreja" maxlength="400" list="ear-points" value="<?= h(implode(', ', $p['oreja'])) ?>"></label>
        <datalist id="ear-points"><?php foreach (MTC_EAR_POINTS as $e): ?><option value="<?= h($e) ?>"><?php endforeach; ?></datalist>

        <h3 class="span-2 mtc-sub">Para casa y cuidados</h3>
        <label class="span-2">Recomendaciones (una por renglón) <textarea name="recs" rows="3"><?= h(implode("\n", $p['recs'])) ?></textarea></label>
        <label class="span-2">Contraindicaciones y cuidados (aviso en la vista previa del plan) <textarea name="contra_notes" rows="2"><?= h($p['contra_notes']) ?></textarea></label>
        <label class="check span-2"><input type="checkbox" name="ashi" value="1" <?= $p['ashi'] ? 'checked' : '' ?>><span>Sumar puntos Ashi (dolor local)</span></label>

        <details class="span-2 mtc-optional">
          <summary>Señales que lo sugieren (lengua primero; el pulso no se usa)</summary>
          <?php foreach (array_merge(array_keys(MTC_TONGUE_FIELDS), array_keys(MTC_INTERVIEW)) as $field): ?>
            <?php $opts = MTC_OPTIONS[$field] ?? MTC_MULTI[$field] ?? []; if (!$opts) continue; ?>
            <div class="mtc-ev-field"><span class="mtc-label"><?= h(ucfirst(MTC_DIAG_LABELS[$field] ?? $field)) ?></span>
              <?= cat_checks('evidence[' . $field . ']', $opts, $p['evidence'][$field] ?? []) ?></div>
          <?php endforeach; ?>
        </details>
      </div>
  <?php else: ?>
      <?php $i = $item; $checked = in_array('*', $i['patterns'], true) ? [] : cat_rule_keys($i['patterns'], $patterns); ?>
      <div class="form-grid">
        <label>Categoría <?= cat_select('category', MTC_IND_CATEGORIES, $i['category'], 'Hábitos') ?></label>
        <label class="check"><input type="checkbox" name="therapist" value="1" <?= $i['therapist'] ? 'checked' : '' ?>><span>Requiere indicación del terapeuta (dosis, fitoterapia): nunca se tilda sola</span></label>
        <label class="span-2">Texto para el paciente <textarea name="text" rows="3" required><?= h($i['text']) ?></textarea></label>
        <label class="span-2">Cuidado / contraindicación (se suma al texto) <input name="caution" maxlength="400" value="<?= h($i['caution']) ?>"></label>
        <div class="span-2">
          <span class="mtc-label">Se propone para</span>
          <label class="check"><input type="checkbox" name="patterns[]" value="*" <?= in_array('*', $i['patterns'], true) ? 'checked' : '' ?>><span>Todos los diagnósticos</span></label>
          <?php foreach (cat_organ_groups($patterns) as $organ => $group): ?>
            <details class="mtc-organ" <?= array_intersect(array_keys($group), $checked) ? 'open' : '' ?>><summary><?= h($organ) ?></summary>
              <div class="mtc-checks">
                <?php foreach ($group as $pk => $pp): ?>
                  <label class="check"><input type="checkbox" name="patterns[]" value="<?= h($pk) ?>" <?= in_array($pk, $checked, true) ? 'checked' : '' ?>><span><?= h($pp['label']) ?></span></label>
                <?php endforeach; ?>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
      </div>
  <?php endif; ?>
      <div class="mtc-bar">
        <?php if ($mode === 'preview' && !$errors): ?>
          <button class="btn primary" type="submit" name="action" value="save">Guardar en el catálogo</button>
          <button class="btn ghost" type="submit" name="action" value="preview">Actualizar vista previa</button>
        <?php else: ?>
          <button class="btn primary" type="submit" name="action" value="preview">Vista previa</button>
        <?php endif; ?>
        <a class="btn ghost" href="<?= h($self) ?>">Cancelar</a>
        <?php if ($isSeed): ?>
          <button class="btn ghost" type="submit" name="action" value="reset" formnovalidate id="btn-reset">Volver al texto de fábrica</button>
        <?php endif; ?>
        <span class="hint">Nada se guarda sin pasar por la vista previa.</span>
      </div>
    </form>
    <script>
      (function () {
        var reset = document.getElementById('btn-reset');
        if (reset) {
          reset.addEventListener('click', function (ev) {
            if (!confirm('¿Volver al texto de fábrica? Se pierden los cambios que le hiciste a este elemento.')) { ev.preventDefault(); }
          });
        }
      })();
    </script>
<?php endif; ?>
  </main>
</body>
</html>
