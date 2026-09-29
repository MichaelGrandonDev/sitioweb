<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/pacientes.php';
require_once __DIR__ . '/includes/pac_generate.php';
if (is_file(__DIR__ . '/includes/mtc_plan.php')) {
    require_once __DIR__ . '/includes/mtc_plan.php';
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$p = $id > 0 ? pac_patient($id) : null;
if (!$p) {
    flash('error', 'No se encontró ese paciente.');
    redirect('pacientes.php');
}
$self = 'paciente.php?id=' . $id;

/** Datos de la evaluación que vienen del formulario. */
$evalFromPost = static function (): array {
    $out = [];
    foreach (pac_eval_fields() as $f) {
        $out[$f] = pac_post_str($f);
    }
    return $out;
};

/** Genera un borrador y lo muestra en vista previa (nada se guarda hasta «Guardar»). */
$startPreview = static function (array $input, int $targetEval, string $notas) use ($p, $id, $self): never {
    $useAi = ($_POST['use_ai'] ?? '') === '1';
    $cursor = $useAi && pac_bridge_alive();
    $api = $useAi && !$cursor && pac_ai_available();
    $res = pac_generate($p, $input, $api, ($_POST['web'] ?? '') === '1');
    if ($notas !== '') {
        $res['data']['notas'] = $notas;
    }
    $prev = [
        'pid' => $id, 'eval_id' => $targetEval, 'input' => $input, 'data' => $res['data'], 'mode' => $res['mode'],
        'info' => $res['info'], 'applied' => $res['applied'], 'job' => 0, 'state' => 'ready',
    ];
    if ($cursor) {
        $prev['job'] = pac_job_create($id, pac_cursor_payload($p, $res), !empty($input['foto_cursor']) ? (int) $input['foto_id'] : 0);
        $prev['state'] = 'waiting';
    } elseif ($useAi && !$api) {
        $prev['info'][] = 'Cursor no disponible — generado con tus protocolos.';
    }
    $token = pac_preview_put($prev);
    redirect($self . '&preview=' . $token . '#preview');
};

/**
 * Entradas del diagnóstico: glosodiagnosis estructurada (t[…]), motivo + interrogatorio (q[…]), pulso opcional y foto.
 * Desde el editor de una evaluación llegan como texto libre (lengua / interrogatorio).
 */
$genInput = static function () use ($id): array {
    $t = pac_tongue_from($_POST['t'] ?? null);
    $q = pac_interrog_from($_POST['q'] ?? null);
    $motivo = pac_post_str('motivo', 4000);
    $structured = isset($_POST['t']) || isset($_POST['q']) || isset($_POST['motivo']);
    $foto = (int) ($_POST['foto_id'] ?? 0) > 0 ? pac_tongue_photo($id, (int) $_POST['foto_id']) : null;
    return [
        'lengua' => $structured ? pac_tongue_text($t) : pac_post_str('lengua', 4000),
        'interrogatorio' => $structured ? pac_interrog_text($motivo, $q) : pac_post_str('interrogatorio'),
        'pulso' => pac_post_str('pulso', 2000),
        'indicaciones' => pac_post_str('indicaciones', 2000),
        'foto' => $foto !== null,
        'foto_id' => $foto ? (int) $foto['id'] : 0,
        'foto_cursor' => $foto !== null && ($_POST['foto_cursor'] ?? '') === '1',
        'entrada' => $structured ? ['lengua' => $t, 'interrog' => $q, 'motivo' => $motivo, 'foto_id' => $foto ? (int) $foto['id'] : 0] : null,
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pac_require_post($self);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $evalId = (int) ($_POST['eval_id'] ?? 0);
    $back = $self;
    $previewToken = is_string($_POST['preview'] ?? null) ? $_POST['preview'] : '';
    try {
        if ($action === 'preview_pdf_terapeuta' || $action === 'preview_pdf_paciente') {
            $prev = $previewToken !== '' ? pac_preview_get($previewToken, $id) : null;
            $saved = $evalId > 0 ? pac_eval($evalId, $id) : null;
            $fecha = is_string($_POST['fecha'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d');
            $draft = $evalFromPost() + [
                'id' => 0, 'version' => 0, 'status' => 'borrador', 'mode' => (string) ($prev['mode'] ?? $saved['mode'] ?? 'manual'),
                'fecha' => $fecha, 'updated_at' => date('Y-m-d H:i:s'), 'preview' => 1,
            ];
            $audience = $action === 'preview_pdf_paciente' ? 'paciente' : 'terapeuta';
            pac_private_headers();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="vista-previa-' . pac_pdf_name($p, $audience) . '"');
            echo pac_pdf($p, pac_history($id), $draft, $audience);
            exit;
        }
        switch ($action) {
            case 'preview_discard':
                pac_preview_drop($previewToken);
                flash('success', 'Vista previa descartada. No se guardó nada.');
                redirect($self . '#mtc');
            case 'preview_fallback':
                $prev = pac_preview_get($previewToken, $id);
                if ($prev) {
                    pac_preview_resolve($previewToken, $prev, $p, true);
                }
                redirect($self . '&preview=' . rawurlencode($previewToken) . '#preview');
            case 'preview_save':
                $prev = pac_preview_get($previewToken, $id);
                if (!$prev) {
                    throw new RuntimeException('Esa vista previa ya no existe (se descartó o venció la sesión). Generá de nuevo.');
                }
                $back = $self . '&preview=' . rawurlencode($previewToken) . '#preview';
                if ($prev['state'] !== 'ready') {
                    throw new RuntimeException('Todavía se está generando. Esperá o tocá «Usar mis protocolos».');
                }
                $data = $evalFromPost();
                $fecha = is_string($_POST['fecha'] ?? null) ? $_POST['fecha'] : '';
                $prev['data'] = $data;
                pac_preview_set($previewToken, $prev);
                if ((int) $prev['eval_id'] > 0) {
                    $target = pac_eval((int) $prev['eval_id'], $id);
                    if (!$target) {
                        throw new RuntimeException('La evaluación que ibas a reemplazar ya no existe.');
                    }
                    $changes = pac_eval_changes(pac_eval_data($target), $data);
                    if ($changes && ($_POST['confirm_overwrite'] ?? '') !== '1') {
                        throw new RuntimeException('Revisá qué cambia y marcá «Sí, reemplazar» para guardar (la versión anterior queda en el historial).');
                    }
                    if ($changes) {
                        pac_eval_update($target, $data, 'borrador', (string) $prev['mode'], 'Regenerada (guardada desde la vista previa)', $fecha);
                    }
                    $savedId = (int) $target['id'];
                    $msg = $changes ? 'Guardado como versión ' . ((int) $target['version'] + 1) . ' (borrador). La anterior quedó en el historial.' : 'No había cambios: la evaluación quedó igual.';
                } else {
                    $savedId = pac_eval_create($id, $data, (string) $prev['mode'], $fecha);
                    $msg = 'Evaluación guardada (versión 1, borrador).';
                }
                if (is_array($prev['input']['entrada'] ?? null)) {
                    pac_eval_set_entrada($savedId, $prev['input']['entrada']);
                }
                pac_preview_drop($previewToken);
                $alerts = array_filter(pac_safety_warnings($p, $data), static fn ($w) => str_starts_with($w, 'ATENCIÓN'));
                flash($alerts ? 'error' : 'success', $msg . ($alerts ? ' ' . implode(' ', $alerts) : ''));
                redirect($self . '&eval=' . $savedId . '#evaluacion');
            case 'mtc_handoff':
                $eval = pac_eval($evalId, $id);
                if (!$eval || !pac_mtc_ready()) {
                    throw new RuntimeException('No se pudo preparar el traspaso al Plan MTC.');
                }
                $back = $self . '&eval=' . $evalId . '#handoff';
                $apptId = (int) ($_POST['appt_id'] ?? 0);
                if (!in_array($apptId, array_map(static fn ($a) => (int) $a['id'], pac_appointments($p)), true)) {
                    throw new RuntimeException('Elegí uno de los turnos de este paciente.');
                }
                if (function_exists('mtc_visit_of') && ($visit = mtc_visit_of($apptId))) {
                    $apptId = (int) $visit['appointment_id'];
                }
                $existing = mtc_plan_for_appointment($apptId);
                $proposed = pac_mtc_proposal($p, $eval, pac_history($id), $existing);
                $changes = pac_mtc_changes($existing, $proposed);
                if (!$changes) {
                    flash('success', 'El Plan MTC ya tenía estos datos: no hubo cambios.');
                    redirect('plan_mtc.php?id=' . $apptId);
                }
                if (pac_mtc_overwrites($changes) && ($_POST['confirm_overwrite'] ?? '') !== '1') {
                    throw new RuntimeException('Ese Plan MTC ya tiene datos: revisá qué cambia y marcá «Sí, reemplazar».');
                }
                pac_mtc_handoff($id, $evalId, $apptId, $proposed, $existing);
                flash('success', 'Diagnóstico y patrones pasados al Plan MTC (consulta 1 y consultas 2–' . PAC_BLOCK . '). Revisalo y guardalo desde acá; si no te sirve, podés deshacerlo desde la ficha del paciente.');
                redirect('plan_mtc.php?id=' . $apptId);
            case 'mtc_undo':
                flash('success', pac_mtc_undo($id));
                $back = $self . '#turnos';
                break;
            case 'save_patient':
                pac_patient_save(pac_patient_from_input($_POST), $id);
                flash('success', 'Datos del paciente guardados.');
                $back = $self . '#datos';
                break;
            case 'delete_patient':
                if (($_POST['confirm_name'] ?? '') !== 'BORRAR') {
                    throw new RuntimeException('Para borrar la ficha escribí BORRAR en el cuadro de confirmación.');
                }
                pac_patient_delete($id);
                flash('success', 'Ficha del paciente borrada (con su historia, archivos y evaluaciones).');
                redirect('pacientes.php');
            case 'history_add':
            case 'history_update':
                $hid = pac_history_save($id, $_POST, $action === 'history_update' ? (int) ($_POST['history_id'] ?? 0) : 0);
                $n = 0;
                foreach (pac_upload_list($_FILES['files'] ?? null) as $file) {
                    $n += pac_store_file($id, $file, $hid) > 0 ? 1 : 0;
                }
                flash('success', 'Registro de historia clínica guardado' . ($n ? ' con ' . $n . ' adjunto' . ($n > 1 ? 's' : '') : '') . '.');
                $back = $self . '#historia';
                break;
            case 'history_delete':
                pac_history_delete($id, (int) ($_POST['history_id'] ?? 0));
                flash('success', 'Registro borrado (sus adjuntos quedan en Archivos).');
                $back = $self . '#historia';
                break;
            case 'file_upload':
                $n = 0;
                foreach (pac_upload_list($_FILES['files'] ?? null) as $file) {
                    $n += pac_store_file($id, $file) > 0 ? 1 : 0;
                }
                flash($n ? 'success' : 'error', $n ? 'Archivos subidos: ' . $n . '.' : 'No elegiste ningún archivo.');
                $back = $self . '#archivos';
                break;
            case 'tongue_upload':
                $n = 0;
                foreach (pac_upload_list($_FILES['fotos'] ?? null) as $file) {
                    $n += pac_tongue_photo_store($id, $file, (string) ($_POST['fecha'] ?? ''), pac_post_str('caption', 200)) > 0 ? 1 : 0;
                }
                flash($n ? 'success' : 'error', $n ? 'Foto' . ($n > 1 ? 's' : '') . ' de la lengua guardada' . ($n > 1 ? 's' : '') . ' (sin datos de ubicación ni del teléfono).' : 'No elegiste ninguna foto.');
                $back = $self . '#lengua-fotos';
                break;
            case 'tongue_delete':
                pac_tongue_photo_delete($id, (int) ($_POST['file_id'] ?? 0));
                flash('success', 'Foto borrada.');
                $back = $self . '#lengua-fotos';
                break;
            case 'file_delete':
                pac_file_delete($id, (int) ($_POST['file_id'] ?? 0));
                flash('success', 'Archivo borrado.');
                $back = $self . '#archivos';
                break;
            case 'generate':
                $startPreview($genInput(), 0, '');
            case 'eval_blank':
                $data = array_fill_keys(pac_eval_fields(), '');
                $data['avisos'] = implode("\n", array_merge(pac_safety_warnings($p), [PAC_DISCLAIMER]));
                $newId = pac_eval_create($id, $data, 'manual');
                flash('success', 'Evaluación en blanco creada.');
                redirect($self . '&eval=' . $newId . '#evaluacion');
        }
        if (in_array($action, ['eval_save', 'eval_review', 'eval_draft', 'eval_regenerate', 'eval_restore', 'eval_delete', 'eval_mail'], true)) {
            $eval = pac_eval($evalId, $id);
            if (!$eval) {
                throw new RuntimeException('No se encontró esa evaluación.');
            }
            $back = $self . '&eval=' . $evalId . '#evaluacion';
            if ($action === 'eval_delete') {
                pac_eval_delete($id, $evalId);
                flash('success', 'Evaluación borrada.');
                redirect($self . '#mtc');
            }
            if ($action === 'eval_restore') {
                $ver = null;
                foreach (pac_eval_versions($evalId) as $v) {
                    if ((int) $v['version'] === (int) ($_POST['version'] ?? 0)) {
                        $ver = $v;
                    }
                }
                $data = $ver ? json_decode((string) $ver['data'], true) : null;
                if (!is_array($data)) {
                    throw new RuntimeException('No se encontró esa versión.');
                }
                pac_eval_update($eval, $data, 'borrador', (string) $ver['mode'], 'Restaurada desde la versión ' . (int) $ver['version']);
                flash('success', 'Se restauró la versión ' . (int) $ver['version'] . ' como una versión nueva (borrador).');
            } elseif ($action === 'eval_regenerate') {
                $startPreview($genInput(), (int) $eval['id'], pac_post_str('notas'));
            } elseif ($action === 'eval_mail') {
                $pdf = pac_pdf($p, pac_history($id), $eval, 'paciente');
                $result = pac_send_pdf($p, $pdf, pac_pdf_name($p, 'paciente'));
                flash($result === 'sent' ? 'success' : 'error', match ($result) {
                    'sent' => 'Se mandó el plan en PDF (versión para el paciente) a su email.',
                    'no_email' => 'El paciente no tiene un email válido cargado.',
                    default => 'No se pudo mandar el mail. Probá de nuevo o descargá el PDF.',
                });
            } else {
                $status = match ($action) {
                    'eval_review' => 'revisado',
                    'eval_draft' => 'borrador',
                    default => (string) $eval['status'],
                };
                $data = $evalFromPost();
                $warn = pac_safety_warnings($p, $data);
                $fecha = is_string($_POST['fecha'] ?? null) ? $_POST['fecha'] : '';
                pac_eval_update($eval, $data, $status, null, match ($action) {
                    'eval_review' => 'Marcada como revisada',
                    'eval_draft' => 'Vuelta a borrador',
                    default => 'Editada',
                }, $fecha);
                $alerts = array_filter($warn, static fn ($w) => str_starts_with($w, 'ATENCIÓN'));
                flash($alerts ? 'error' : 'success', 'Evaluación guardada (versión ' . ((int) $eval['version'] + 1) . ', ' . PAC_EVAL_STATUS[$status] . ').'
                    . ($alerts ? ' ' . implode(' ', $alerts) : ''));
            }
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$history = pac_history($id);
$evalId = (int) ($_GET['eval'] ?? 0);
$eval = $evalId > 0 ? pac_eval($evalId, $id) : null;
$previewToken = is_string($_GET['preview'] ?? null) ? $_GET['preview'] : '';
$preview = $previewToken !== '' ? pac_preview_get($previewToken, $id) : null;
if ($preview) {
    $preview = pac_preview_resolve($previewToken, $preview, $p);
} elseif ($previewToken !== '') {
    flash('error', 'Esa vista previa ya no existe (se guardó, se descartó o venció la sesión).');
}
$volverToken = is_string($_GET['volver'] ?? null) ? $_GET['volver'] : '';
$volver = $volverToken !== '' ? pac_preview_get($volverToken, $id) : null;

if (isset($_GET['pdf'])) {
    $audience = $_GET['pdf'] === 'paciente' ? 'paciente' : 'terapeuta';
    $pdfEval = $eval;
    if (!$pdfEval) {
        foreach (pac_evals($id) as $row) {
            if ($row['status'] === 'revisado' || $pdfEval === null) {
                $pdfEval = pac_eval((int) $row['id'], $id);
                if ($row['status'] === 'revisado') {
                    break;
                }
            }
        }
    }
    pac_private_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . pac_pdf_name($p, $audience) . '"');
    echo pac_pdf($p, $history, $pdfEval, $audience);
    exit;
}

$files = pac_files($id);
$filesByHistory = [];
foreach ($files as $f) {
    if ($f['history_id'] !== null) {
        $filesByHistory[(int) $f['history_id']][] = $f;
    }
}
$appointments = pac_appointments($p);
$plans = pac_mtc_plans($appointments);
$planAppts = array_flip(array_map(static fn ($pl) => (int) $pl['appointment_id'], $plans));
$evals = pac_evals($id);
$warnings = pac_safety_warnings($p);
$aiOn = pac_ai_available();
$lib = pac_library_stats();
$last = $evals ? pac_eval((int) $evals[0]['id'], $id) : null;
$tonguePhotos = pac_tongue_photos($id);
$flags = pac_flags($p);
$prevIn = $volver ? (array) ($volver['input']['entrada'] ?? []) : [];
$prefill = [
    't' => pac_tongue_from($prevIn['lengua'] ?? []),
    'q' => pac_interrog_from($prevIn['interrog'] ?? []),
    'motivo' => (string) ($prevIn['motivo'] ?? trim(($history[0]['motivo'] ?? '') . "\n" . ($history[0]['evolucion'] ?? ''))),
    'pulso' => (string) ($volver['input']['pulso'] ?? ''),
    'indicaciones' => (string) ($volver['input']['indicaciones'] ?? ''),
    'foto_id' => $volver ? (int) ($volver['input']['foto_id'] ?? 0) : (($tonguePhotos[0]['fecha'] ?? '') === date('Y-m-d') ? (int) $tonguePhotos[0]['id'] : 0),
    'foto_cursor' => $volver ? !empty($volver['input']['foto_cursor']) : false,
];
$photoById = array_column($tonguePhotos, null, 'id');
$cmpIds = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['cmp'] ?? [])), static fn ($x) => isset($photoById[$x])))), 0, 4);
$photoTongue = [];
if ($cmpIds) {
    $stmt = db()->prepare('SELECT fecha, lengua, entrada FROM pac_evals WHERE patient_id = ? ORDER BY fecha DESC, id DESC');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $row) {
        $fid = (int) (json_decode((string) $row['entrada'], true)['foto_id'] ?? 0);
        foreach ($cmpIds as $cid) {
            if (!isset($photoTongue[$cid]) && ($fid === $cid || ($fid === 0 && $row['fecha'] === $photoById[$cid]['fecha'])) && trim((string) $row['lengua']) !== '') {
                $photoTongue[$cid] = (string) $row['lengua'];
            }
        }
    }
}
$bridgeOn = pac_bridge_alive();
$bridgeSet = pac_bridge_configured();
$engineNote = $bridgeOn ? 'Cursor está conectado en tu Mac: lee tu biblioteca local y redacta con el caso anonimizado.'
    : ($aiOn ? 'Cursor no está conectado ahora: se usará ' . pac_ai_label(pac_ai_providers()[0] ?? 'gemini') . ' con tu biblioteca, o tus protocolos si falla.'
    : 'Cursor no está conectado ahora: se genera con tus protocolos y la biblioteca (sin IA).');
$handoffAppt = $eval ? (int) ($_GET['handoff'] ?? 0) : 0;
$activeAppts = array_values(array_filter($appointments, static fn ($a) => $a['status'] !== 'cancelled'));
$form = $p;

pac_page_start($p['nombre'], 'pacientes');
?>
    <p class="pac-back"><a href="pacientes.php">← Pacientes</a></p>
    <div class="pac-head">
      <div>
        <p class="eyebrow">Ficha clínica · confidencial</p>
        <h1><?= h($p['nombre']) ?></h1>
        <p class="muted"><?= h(implode(' · ', array_filter([
            pac_age($p) !== null ? pac_age($p) . ' años' : '',
            $p['documento'] !== '' ? 'Doc. ' . $p['documento'] : '',
            $p['telefono'],
            $p['email'],
        ]))) ?></p>
        <?php foreach (pac_flags($p) as $f): ?><span class="tag warn"><?= h(PAC_FLAGS[$f]) ?></span> <?php endforeach; ?>
      </div>
      <div class="pac-actions">
        <a class="btn ghost" href="<?= h($self) ?>&amp;pdf=terapeuta" target="_blank" rel="noopener">PDF ficha completa</a>
        <a class="btn primary" href="#mtc">Generar diagnóstico y tratamiento</a>
      </div>
    </div>
    <?php if ($warnings): ?>
      <div class="alert warn"><strong>Precauciones:</strong><ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if ($preview && $preview['state'] === 'waiting'): ?>
      <section class="panel pac-eval pac-preview" id="preview">
        <p class="eyebrow">Vista previa — todavía no se guardó</p>
        <h2>Generando con Cursor…</h2>
        <div class="pac-waiting" data-job-poll data-token="<?= h($previewToken) ?>" data-pid="<?= (int) $id ?>">
          <span class="pac-spinner" aria-hidden="true"></span>
          <p>Cursor está leyendo tu biblioteca en la Mac y armando el diagnóstico. Suele tardar entre 30 segundos y 3 minutos.
            <span class="muted small" data-job-elapsed></span></p>
        </div>
        <p class="hint">Si no termina en <?= intdiv(PAC_JOB_WAIT, 60) ?> minutos, se usa automáticamente el borrador armado con tus protocolos.</p>
        <form method="post" class="pac-actions">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="preview" value="<?= h($previewToken) ?>">
          <button class="btn ghost" type="submit" name="action" value="preview_fallback">No esperar: usar mis protocolos</button>
          <button class="btn ghost" type="submit" name="action" value="preview_discard">Descartar</button>
        </form>
      </section>
    <?php elseif ($preview): ?>
      <?php
        $pd = $preview['data'];
        $ap = $preview['applied'];
        $target = (int) $preview['eval_id'] > 0 ? pac_eval((int) $preview['eval_id'], $id) : null;
        $changes = $target ? pac_eval_changes(pac_eval_data($target), $pd) : [];
        $prevAlerts = array_filter(pac_safety_warnings($p, $pd), static fn ($w) => str_starts_with($w, 'ATENCIÓN'));
      ?>
      <section class="panel pac-eval pac-preview" id="preview">
        <div class="pac-head">
          <div>
            <p class="eyebrow">Vista previa — todavía no se guardó</p>
            <h2><?= $target ? 'Regenerar la evaluación del ' . h(date('d/m/Y', strtotime((string) $target['fecha']))) : 'Nuevo diagnóstico y tratamiento' ?></h2>
            <p class="muted small"><?= h(pac_mode_label((string) $preview['mode'])) ?></p>
          </div>
          <span class="tag warn pac-status">Sin guardar</span>
        </div>
        <?php foreach ($preview['info'] as $line): ?>
          <p class="hint"><?= h($line) ?></p>
        <?php endforeach; ?>

        <?php if (trim((string) $pd['patrones']) !== ''): ?>
          <div class="pac-dx">
            <p class="pac-dx-title">Diagnóstico desde la Medicina Tradicional China</p>
            <p class="pac-dx-text"><?= h((string) $pd['patrones']) ?></p>
          </div>
        <?php endif; ?>
        <?php $prevPhoto = $photoById[(int) ($preview['input']['foto_id'] ?? 0)] ?? null; ?>
        <div class="pac-applied">
          <h3 class="pac-sub">Qué se aplicó y por qué</h3>
          <?php if ($prevPhoto): ?>
            <figure class="pac-photo pac-photo--inline">
              <img src="pacientes_archivo.php?id=<?= (int) $prevPhoto['id'] ?>" alt="Foto de la lengua del <?= h(date('d/m/Y', strtotime((string) $prevPhoto['fecha']))) ?>" loading="lazy">
              <figcaption class="small">Foto del <?= h(date('d/m/Y', strtotime((string) $prevPhoto['fecha']))) ?><?= !empty($preview['input']['foto_cursor']) ? ' · enviada a Cursor en tu Mac' : ' · solo como referencia' ?></figcaption>
            </figure>
          <?php endif; ?>
          <?php if ($ap['protocols']): ?>
            <p><strong>Patrones que coinciden</strong> (primero por la lengua, después por los datos del paciente<?= array_filter(array_column($ap['protocols'], 'pulso')) ? '; el pulso suma poco' : '' ?>):</p>
            <ul>
              <?php foreach ($ap['protocols'] as $pr): ?>
                <li><strong><?= h($pr['nombre']) ?></strong> <span class="muted small">(puntaje <?= (int) $pr['score'] ?>)</span><br>
                  <span class="small"><?= h(implode(' · ', array_filter([
                      'Lengua: ' . ($pr['lengua'] ? implode(', ', $pr['lengua']) : 'sin signos esperados'),
                      $pr['signos'] ? 'Paciente: ' . implode(', ', $pr['signos']) : '',
                      !empty($pr['pulso']) ? 'Pulso: ' . implode(', ', $pr['pulso']) : '',
                  ]))) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted">Ningún patrón coincidió claramente: completá la glosodiagnosis y el interrogatorio (tocá «Volver»).</p>
          <?php endif; ?>
          <?php if (!empty($ap['differentials'])): ?>
            <p><strong>Considerados y descartados:</strong></p>
            <ul class="small">
              <?php foreach ($ap['differentials'] as $d): ?>
                <li><?= h($d['nombre']) ?><?= $d['missing'] ? ' — no encaja: ' . h(implode(', ', array_slice($d['missing'], 0, 4))) : '' ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($ap['citas']): ?>
            <details class="admin-details" open>
              <summary>Pasajes que citó la IA (<?= count($ap['citas']) ?>)</summary>
              <?php foreach ($ap['citas'] as $c): ?>
                <p class="small"><strong>[<?= h($c['id']) ?>] <?= h($c['cite']) ?></strong>
                  <?= !empty($c['link']) ? ' · <a href="' . h($c['link']) . '" target="_blank" rel="noopener">abrir en el lector</a>' : '' ?>
                  <?= $c['excerpt'] !== '' ? '<br>«' . h($c['excerpt']) . '»' : '' ?></p>
              <?php endforeach; ?>
            </details>
          <?php endif; ?>
          <?php if ($ap['sources']): ?>
            <details class="admin-details"<?= $ap['citas'] ? '' : ' open' ?>>
              <summary>Pasajes de la Biblioteca MTC (<?= count($ap['sources']) ?>)</summary>
              <?php foreach ($ap['sources'] as $s): ?>
                <p class="small"><strong>[<?= h($s['id']) ?>] <?= h($s['cite']) ?></strong> <span class="muted">· <?= h(pac_source_label((string) $s['for'])) ?></span>
                  <?= !empty($s['link']) ? ' · <a href="' . h($s['link']) . '" target="_blank" rel="noopener">abrir en el lector</a>' : '' ?><br><?= h($s['excerpt']) ?></p>
              <?php endforeach; ?>
            </details>
          <?php endif; ?>
          <?php if ($ap['web']): ?>
            <details class="admin-details">
              <summary>Fuentes de internet (<?= count($ap['web']) ?>, citadas aparte)</summary>
              <?php foreach ($ap['web'] as $w): ?>
                <p class="small">[<?= h($w['id']) ?>] <?= h($w['cite']) ?><?= $w['url'] !== '' ? ' · <a href="' . h($w['url']) . '" target="_blank" rel="noopener noreferrer">abrir</a>' : '' ?></p>
              <?php endforeach; ?>
            </details>
          <?php endif; ?>
          <p><strong>Filtros de contraindicaciones:</strong>
            <?= $ap['filters'] ? h(implode(' ', $ap['filters'])) : (pac_flags($p) ? 'se revisaron las condiciones de riesgo; no hizo falta quitar nada.' : 'el paciente no tiene embarazo, marcapasos ni anticoagulantes registrados.') ?></p>
        </div>

        <?php if ($prevAlerts): ?>
          <div class="alert error"><ul><?php foreach ($prevAlerts as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="alert warn"><?= h(PAC_DISCLAIMER) ?></div>

        <form method="post" class="form-grid" id="preview-form">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="preview" value="<?= h($previewToken) ?>">
          <label>Fecha de la evaluación
            <input type="date" name="fecha" value="<?= h($target ? (string) $target['fecha'] : date('Y-m-d')) ?>">
          </label>
          <div></div>
          <?php foreach (PAC_EVAL_INPUTS as $k => $label): ?>
            <label class="<?= $k === 'interrogatorio' ? 'span-2' : '' ?>"><?= h($label) ?>
              <textarea name="<?= h($k) ?>" rows="<?= $k === 'interrogatorio' ? 3 : 2 ?>" data-autogrow><?= h((string) ($pd[$k] ?? '')) ?></textarea>
            </label>
          <?php endforeach; ?>
          <?php foreach (PAC_EVAL_OUTPUTS as $k => $label): ?>
            <label class="span-2 pac-field pac-field--<?= h($k) ?>"><?= h($label) ?>
              <textarea name="<?= h($k) ?>" rows="3" data-autogrow><?= h((string) ($pd[$k] ?? '')) ?></textarea>
            </label>
          <?php endforeach; ?>

          <?php if ($target): ?>
            <div class="span-2 pac-diff" id="cambios">
              <h3 class="pac-sub">Qué cambiaría en la evaluación guardada (versión <?= (int) $target['version'] ?>)</h3>
              <?php if (!$changes): ?>
                <p class="muted">Nada: es igual a lo guardado.</p>
              <?php else: ?>
                <p class="small">Cambian <?= count($changes) ?> campos: <?= h(implode(', ', array_column($changes, 'label'))) ?>. La versión actual queda en el historial y se puede restaurar.</p>
                <?php foreach ($changes as $c): ?>
                  <details class="pac-version">
                    <summary><?= h($c['label']) ?></summary>
                    <div class="pac-diff-cols">
                      <div><p class="eyebrow">Ahora</p><p class="small"><?= $c['before'] !== '' ? nl2br(h(pac_trim($c['before'], 1500))) : '<em>vacío</em>' ?></p></div>
                      <div><p class="eyebrow">Quedaría</p><p class="small"><?= $c['after'] !== '' ? nl2br(h(pac_trim($c['after'], 1500))) : '<em>vacío</em>' ?></p></div>
                    </div>
                  </details>
                <?php endforeach; ?>
                <label class="check"><input type="checkbox" name="confirm_overwrite" value="1" required><span>Sí, reemplazar lo guardado (la versión anterior queda restaurable)</span></label>
              <?php endif; ?>
              <p class="muted small">Si editás los campos de arriba, lo que se compara al guardar es lo editado.</p>
            </div>
          <?php endif; ?>

          <div class="span-2 pac-bar">
            <button class="btn primary" type="submit" name="action" value="preview_save"><?= $target ? 'Guardar como versión nueva' : 'Guardar' ?></button>
            <button class="btn ghost" type="submit" name="action" value="preview_pdf_terapeuta" formtarget="_blank" formnovalidate>Ver PDF terapeuta</button>
            <button class="btn ghost" type="submit" name="action" value="preview_pdf_paciente" formtarget="_blank" formnovalidate>Ver PDF paciente</button>
            <a class="btn ghost" href="<?= h($self) ?>&amp;volver=<?= h(rawurlencode($previewToken)) ?>#mtc">Volver (ajustar datos y regenerar)</a>
            <button class="btn ghost" type="submit" name="action" value="preview_discard" formnovalidate
              onclick="return confirm('¿Descartar esta vista previa? No se guarda nada.')">Descartar</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($eval && !$preview): ?>
      <?php
        $versions = pac_eval_versions((int) $eval['id']);
        $evalWarn = pac_safety_warnings($p, pac_eval_data($eval));
      ?>
      <section class="panel pac-eval" id="evaluacion">
        <div class="pac-head">
          <div>
            <p class="eyebrow">Evaluación MTC · versión <?= (int) $eval['version'] ?></p>
            <h2>Diagnóstico y tratamiento</h2>
            <p class="muted small"><?= h(pac_mode_label((string) $eval['mode'])) ?> · última modificación <?= h(date('d/m/Y H:i', strtotime((string) $eval['updated_at']))) ?></p>
          </div>
          <span class="tag <?= $eval['status'] === 'revisado' ? 'ok' : 'warn' ?> pac-status"><?= h(PAC_EVAL_STATUS[$eval['status']] ?? $eval['status']) ?></span>
        </div>
        <div class="alert warn"><?= h(PAC_DISCLAIMER) ?> Todo es editable; cada vez que guardás queda una versión nueva y las anteriores se conservan.</div>
        <?php $alerts = array_filter($evalWarn, static fn ($w) => str_starts_with($w, 'ATENCIÓN')); ?>
        <?php if ($alerts): ?>
          <div class="alert error"><ul><?php foreach ($alerts as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php
          $evalIn = pac_eval_entrada($eval);
          $evalPhoto = $photoById[(int) ($evalIn['foto_id'] ?? 0)] ?? null;
          $evalLinks = pac_reader_links_in((string) $eval['fuentes']);
        ?>
        <?php if (trim((string) $eval['patrones']) !== ''): ?>
          <div class="pac-dx">
            <p class="pac-dx-title">Diagnóstico desde la Medicina Tradicional China</p>
            <p class="pac-dx-text"><?= h((string) $eval['patrones']) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($evalPhoto || $evalLinks): ?>
          <div class="pac-applied">
            <?php if ($evalPhoto): ?>
              <figure class="pac-photo pac-photo--inline">
                <img src="pacientes_archivo.php?id=<?= (int) $evalPhoto['id'] ?>" alt="Lengua del <?= h(date('d/m/Y', strtotime((string) $evalPhoto['fecha']))) ?>" loading="lazy">
                <figcaption class="small">Lengua del <?= h(date('d/m/Y', strtotime((string) $evalPhoto['fecha']))) ?></figcaption>
              </figure>
            <?php endif; ?>
            <?php if ($evalLinks): ?>
              <details class="admin-details">
                <summary>Abrir las citas en el lector (<?= count($evalLinks) ?>)</summary>
                <ul class="small">
                  <?php foreach ($evalLinks as [$label, $href]): ?>
                    <li><a href="<?= h($href) ?>" target="_blank" rel="noopener"><?= h($label) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="post" class="form-grid" id="eval-form">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="eval_id" value="<?= (int) $eval['id'] ?>">
          <?php if ($evalPhoto): ?><input type="hidden" name="foto_id" value="<?= (int) $evalPhoto['id'] ?>"><?php endif; ?>
          <label>Fecha de la evaluación
            <input type="date" name="fecha" value="<?= h((string) $eval['fecha']) ?>">
          </label>
          <div></div>
          <?php foreach (PAC_EVAL_INPUTS as $k => $label): ?>
            <label class="<?= $k === 'interrogatorio' ? 'span-2' : '' ?>"><?= h($label) ?>
              <textarea name="<?= h($k) ?>" rows="<?= $k === 'interrogatorio' ? 4 : 2 ?>"><?= h((string) $eval[$k]) ?></textarea>
            </label>
          <?php endforeach; ?>
          <?php foreach (PAC_EVAL_OUTPUTS as $k => $label): ?>
            <label class="span-2 pac-field pac-field--<?= h($k) ?>"><?= h($label) ?>
              <textarea name="<?= h($k) ?>" rows="<?= in_array($k, ['patrones'], true) ? 1 : (in_array($k, ['diagnostico', 'fuentes', 'sesiones', 'resumen', 'puntos'], true) ? 8 : 4) ?>" data-autogrow><?= h((string) $eval[$k]) ?></textarea>
            </label>
          <?php endforeach; ?>
          <div class="span-2 pac-regen">
            <label class="check"><input type="checkbox" name="use_ai" value="1"<?= $bridgeSet || $aiOn ? ' checked' : '' ?>><span>Usar IA (Cursor en tu Mac<?= $bridgeOn ? ', conectado' : ', desconectado' ?>)</span></label>
            <label class="check"><input type="checkbox" name="web" value="1"><span>Incluir fuentes de internet</span></label>
            <label class="span-2">Indicaciones para regenerar (opcional)
              <input name="indicaciones" maxlength="2000" placeholder="Ej: priorizar el insomnio; sin moxa">
            </label>
          </div>
          <div class="span-2 pac-bar">
            <button class="btn primary" type="submit" name="action" value="eval_save">Guardar</button>
            <?php if ($eval['status'] === 'revisado'): ?>
              <button class="btn ghost" type="submit" name="action" value="eval_draft">Guardar y volver a borrador</button>
            <?php else: ?>
              <button class="btn ghost" type="submit" name="action" value="eval_review">Guardar como revisado</button>
            <?php endif; ?>
            <button class="btn ghost" type="submit" name="action" value="eval_regenerate" formnovalidate data-busy="Generando vista previa…">Regenerar (con vista previa)</button>
            <a class="btn ghost" href="<?= h($self) ?>&amp;eval=<?= (int) $eval['id'] ?>&amp;pdf=terapeuta" target="_blank" rel="noopener">PDF terapeuta</a>
            <a class="btn ghost" href="<?= h($self) ?>&amp;eval=<?= (int) $eval['id'] ?>&amp;pdf=paciente" target="_blank" rel="noopener">PDF paciente</a>
            <button class="btn ghost" type="submit" name="action" value="preview_pdf_paciente" formtarget="_blank" formnovalidate>Vista previa PDF de lo editado</button>
          </div>
          <p class="span-2 muted small">«Vista previa PDF de lo editado» muestra lo que está en pantalla aunque no lo hayas guardado. Regenerar arma una vista previa: nada se reemplaza hasta que confirmes.</p>
        </form>
        <div class="pac-actions" style="margin-top:.6rem">
          <?php if ($p['email'] !== ''): ?>
            <form method="post" onsubmit="return confirm('¿Mandar el PDF para el paciente (sin notas internas) a <?= h($p['email']) ?>? Guardá antes los cambios.')">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="eval_mail">
              <input type="hidden" name="eval_id" value="<?= (int) $eval['id'] ?>">
              <button class="btn ghost" type="submit">Enviar PDF al paciente por email</button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('¿Borrar esta evaluación y todas sus versiones?')">
            <?= pac_csrf_field() ?>
            <input type="hidden" name="action" value="eval_delete">
            <input type="hidden" name="eval_id" value="<?= (int) $eval['id'] ?>">
            <button class="btn ghost" type="submit">Borrar evaluación</button>
          </form>
          <a class="btn ghost" href="<?= h($self) ?>#mtc">Cerrar</a>
        </div>
        <details class="admin-details" id="versiones">
          <summary>Versiones anteriores (<?= count($versions) ?>)</summary>
          <?php foreach ($versions as $v): ?>
            <?php $vd = json_decode((string) $v['data'], true) ?: []; ?>
            <details class="pac-version">
              <summary>
                Versión <?= (int) $v['version'] ?> · <?= h(date('d/m/Y H:i', strtotime((string) $v['saved_at']))) ?> · <?= h(PAC_EVAL_STATUS[$v['status']] ?? $v['status']) ?> · <?= h((string) $v['note']) ?>
              </summary>
              <?php foreach (PAC_EVAL_INPUTS + PAC_EVAL_OUTPUTS as $k => $label): ?>
                <?php if (trim((string) ($vd[$k] ?? '')) !== ''): ?>
                  <p class="small"><strong><?= h($label) ?>:</strong><br><?= nl2br(h((string) $vd[$k])) ?></p>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if ((int) $v['version'] !== (int) $eval['version']): ?>
                <form method="post" onsubmit="return confirm('¿Restaurar la versión <?= (int) $v['version'] ?>? Se guarda como una versión nueva.')">
                  <?= pac_csrf_field() ?>
                  <input type="hidden" name="action" value="eval_restore">
                  <input type="hidden" name="eval_id" value="<?= (int) $eval['id'] ?>">
                  <input type="hidden" name="version" value="<?= (int) $v['version'] ?>">
                  <button class="btn ghost" type="submit">Restaurar esta versión</button>
                </form>
              <?php endif; ?>
            </details>
          <?php endforeach; ?>
        </details>

        <?php if (pac_mtc_ready()): ?>
          <div class="pac-handoff" id="handoff">
            <h3 class="pac-sub">Pasar al Plan MTC</h3>
            <p class="hint">El seguimiento de las <?= PAC_TOTAL ?> consultas se hace en el Plan MTC del turno. Esto copia a la consulta 1 el motivo, los antecedentes,
              la glosodiagnosis (color, forma, saburra, zonas), el interrogatorio, el pulso si lo cargaste, las contraindicaciones y este diagnóstico con las cinco técnicas y sus fuentes, marca los patrones y arma las consultas 2–<?= PAC_BLOCK ?>
              con las plantillas del plan. Antes de guardar te muestra qué cambia.</p>
            <?php if (!$activeAppts): ?>
              <p class="muted">Este paciente no tiene turnos: el Plan MTC se crea desde un turno. Agendá uno en el admin de turnos (turno manual) y volvé.</p>
            <?php else: ?>
              <form method="get" class="pac-search" action="paciente.php#handoff">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="eval" value="<?= (int) $eval['id'] ?>">
                <label>Turno del plan
                  <select name="handoff">
                    <?php foreach ($activeAppts as $a): ?>
                      <?php $hasPlan = isset($planAppts[(int) $a['id']]); ?>
                      <option value="<?= (int) $a['id'] ?>"<?= $handoffAppt === (int) $a['id'] || (!$handoffAppt && $hasPlan) ? ' selected' : '' ?>>
                        <?= h(format_date_es((string) $a['date']) . ' · ' . $a['therapy_name'] . ($hasPlan ? ' · ya tiene Plan MTC' : '')) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <button class="btn ghost" type="submit">Ver qué cambiaría</button>
              </form>
              <?php
                if ($handoffAppt > 0 && in_array($handoffAppt, array_map(static fn ($a) => (int) $a['id'], $activeAppts), true)):
                    $planAppt = $handoffAppt;
                    if (function_exists('mtc_visit_of') && ($visit = mtc_visit_of($handoffAppt))) {
                        $planAppt = (int) $visit['appointment_id'];
                    }
                    $existingPlan = mtc_plan_for_appointment($planAppt);
                    $proposedPlan = pac_mtc_proposal($p, $eval, $history, $existingPlan);
                    $planChanges = pac_mtc_changes($existingPlan, $proposedPlan);
                    $overwrites = pac_mtc_overwrites($planChanges);
              ?>
                <div class="pac-diff">
                  <p><strong><?= $existingPlan ? 'Plan MTC existente' : 'Plan MTC nuevo' ?></strong>
                    <?= $planAppt !== $handoffAppt ? '<span class="muted small">(ese turno es una consulta de un plan que empezó en otro turno; se actualiza ese plan)</span>' : '' ?></p>
                  <?php if (!$planChanges): ?>
                    <p class="muted">No cambiaría nada.</p>
                  <?php else: ?>
                    <?php foreach ($planChanges as $c): ?>
                      <details class="pac-version">
                        <summary><?= h($c['label']) ?><?= $c['before'] !== '' ? ' · <span class="tag warn">reemplaza</span>' : '' ?></summary>
                        <div class="pac-diff-cols">
                          <div><p class="eyebrow">Ahora</p><p class="small"><?= $c['before'] !== '' ? nl2br(h(pac_trim($c['before'], 1200))) : '<em>vacío</em>' ?></p></div>
                          <div><p class="eyebrow">Quedaría</p><p class="small"><?= $c['after'] !== '' ? nl2br(h(pac_trim($c['after'], 1200))) : '<em>vacío</em>' ?></p></div>
                        </div>
                      </details>
                    <?php endforeach; ?>
                    <form method="post" class="pac-actions">
                      <?= pac_csrf_field() ?>
                      <input type="hidden" name="action" value="mtc_handoff">
                      <input type="hidden" name="eval_id" value="<?= (int) $eval['id'] ?>">
                      <input type="hidden" name="appt_id" value="<?= (int) $handoffAppt ?>">
                      <?php if ($overwrites): ?>
                        <label class="check"><input type="checkbox" name="confirm_overwrite" value="1" required><span>Sí, reemplazar lo que ya tiene el plan (se puede deshacer)</span></label>
                      <?php endif; ?>
                      <button class="btn primary" type="submit"><?= $existingPlan ? 'Actualizar el Plan MTC' : 'Crear el Plan MTC' ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="panel" id="mtc">
      <h2>Diagnóstico y tratamiento MTC</h2>
      <p class="hint">
        Cargá la lengua y lo que cuenta el paciente hoy, y tocá <strong>Generar</strong>: se arma el diagnóstico desde la MTC (uno o varios patrones
        en una frase, justificados primero por la lengua y después por los datos del paciente, con libro y página), los diagnósticos descartados,
        el principio de tratamiento, meridianos y el plan con <strong>tuina, chi kung, moxibustión, ventosas y auriculoterapia</strong> (sin agujas:
        los puntos se indican para moxar, con acupresión como alternativa), sesión por sesión (semanal, diagnóstico solo en la sesión 1,
        hasta <?= PAC_TOTAL ?> consultas). Primero ves una <strong>vista previa</strong>: nada se guarda hasta que toques «Guardar».
        Después podés pasarlo al Plan MTC del turno.
      </p>
      <form method="post" class="pac-gen" id="gen-form">
        <?= pac_csrf_field() ?>
        <fieldset class="pac-block pac-block--main">
          <legend>1. Glosodiagnosis <span class="muted small">— la base del diagnóstico</span></legend>
          <?php foreach (PAC_TONGUE_GROUPS as $group => $gTitle): ?>
            <div class="pac-tongue-group">
              <h3 class="pac-sub"><?= h($gTitle) ?></h3>
              <div class="pac-tongue-grid">
                <?php foreach (PAC_TONGUE as $k => [$label, $type, $opts, $g]): ?>
                  <?php if ($g !== $group) continue; ?>
                  <?php if ($type === 'one'): ?>
                    <label><?= h($label) ?>
                      <select name="t[<?= h($k) ?>]">
                        <option value="">—</option>
                        <?php foreach ($opts as $o): ?><option<?= $prefill['t'][$k] === $o ? ' selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                      </select>
                    </label>
                  <?php elseif ($type === 'multi'): ?>
                    <div class="pac-checks span-2"><span class="pac-checks-label"><?= h($label) ?></span>
                      <?php foreach ($opts as $o): ?>
                        <label class="check"><input type="checkbox" name="t[<?= h($k) ?>][]" value="<?= h($o) ?>"<?= in_array($o, $prefill['t'][$k], true) ? ' checked' : '' ?>><span><?= h($o) ?></span></label>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <label class="span-2"><?= h($label) ?>
                      <input name="t[<?= h($k) ?>]" maxlength="800" value="<?= h((string) $prefill['t'][$k]) ?>"
                        placeholder="<?= h(['grietas' => 'Ej: grieta central profunda hasta la punta; grietas transversales a los lados', 'zonas_detalle' => 'Ej: laterales rojos e hinchados; raíz sin saburra', 'lengua_notas' => 'Ej: más pálida que en la consulta anterior'][$k] ?? '') ?>">
                    </label>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="pac-tongue-photo">
            <span class="pac-checks-label">Foto de la lengua para esta evaluación (opcional)</span>
            <?php if (!$tonguePhotos): ?>
              <p class="muted small">Todavía no hay fotos. Podés subirlas en <a href="#lengua-fotos">Fotos de la lengua</a>.</p>
            <?php else: ?>
              <div class="pac-photo-pick">
                <label class="check"><input type="radio" name="foto_id" value="0"<?= $prefill['foto_id'] === 0 ? ' checked' : '' ?>><span>Sin foto</span></label>
                <?php foreach (array_slice($tonguePhotos, 0, 8) as $ph): ?>
                  <label class="pac-photo-opt">
                    <input type="radio" name="foto_id" value="<?= (int) $ph['id'] ?>"<?= $prefill['foto_id'] === (int) $ph['id'] ? ' checked' : '' ?>>
                    <img src="pacientes_archivo.php?id=<?= (int) $ph['id'] ?>" alt="" loading="lazy">
                    <span class="small"><?= h(date('d/m/Y', strtotime((string) $ph['fecha']))) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <label class="check"><input type="checkbox" name="foto_cursor" value="1"<?= $prefill['foto_cursor'] ? ' checked' : '' ?>>
                <span>Mandar la foto a Cursor en tu Mac para que la mire (solo la imagen, sin nombre ni datos; usá una foto donde se vea solo la lengua)</span></label>
            <?php endif; ?>
          </div>
        </fieldset>

        <fieldset class="pac-block">
          <legend>2. Información del paciente</legend>
          <p class="hint">Se toman de la ficha: edad, sexo, antecedentes, medicación, alergias y contraindicaciones<?= $flags ? ' (' . h(implode(', ', array_map(static fn ($f) => PAC_FLAGS[$f] ?? $f, $flags))) . ')' : '' ?>,
            y la historia clínica (<?= count($history) ?> registro<?= count($history) === 1 ? '' : 's' ?>). Acá va lo de hoy.</p>
          <div class="form-grid">
            <label class="span-2">Motivo de consulta y evolución
              <textarea name="motivo" rows="3" maxlength="4000"><?= h($prefill['motivo']) ?></textarea>
            </label>
            <?php foreach (PAC_INTERROG as $k => $label): ?>
              <label><?= h($label) ?>
                <input name="q[<?= h($k) ?>]" maxlength="600" list="dl-<?= h($k) ?>" value="<?= h($prefill['q'][$k]) ?>" autocomplete="off">
                <datalist id="dl-<?= h($k) ?>"><?php foreach (pac_interrog_options($k) as $o): ?><option value="<?= h($o) ?>"><?php endforeach; ?></datalist>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>

        <fieldset class="pac-block">
          <legend>3. Teoría de tus textos</legend>
          <p class="hint">Se buscan en la Biblioteca MTC (<?= (int) $lib['ready'] ?> documentos listos) los signos de la lengua, los patrones candidatos y cada técnica;
            cada patrón queda justificado con libro y página. <?= h($engineNote) ?></p>
          <details class="admin-details"<?= $prefill['pulso'] !== '' ? ' open' : '' ?>>
            <summary>Pulso (opcional: solo se tiene en cuenta si lo cargás, y pesa menos que la lengua)</summary>
            <textarea name="pulso" rows="2" maxlength="2000" placeholder="Ej: débil, profundo en la raíz; de cuerda en el lado izquierdo"><?= h($prefill['pulso']) ?></textarea>
          </details>
          <label>Indicaciones para la IA (opcional, sin nombres ni datos de contacto)
            <textarea name="indicaciones" rows="2" maxlength="2000" placeholder="Ej: priorizar el dolor lumbar; la paciente no tolera ventosas"><?= h($prefill['indicaciones']) ?></textarea>
          </label>
        </fieldset>

        <div class="pac-regen">
          <label class="check"><input type="checkbox" name="use_ai" value="1"<?= $bridgeSet || $aiOn ? ' checked' : '' ?>><span>Usar IA: Cursor en tu Mac
            <span class="tag <?= $bridgeOn ? 'ok' : 'warn' ?>"><?= $bridgeOn ? 'conectado' : ($bridgeSet ? 'desconectado' : 'sin configurar') ?></span>
            <?= !$bridgeSet ? '<a href="pacientes.php#ia">cómo conectarlo</a>' : '' ?></span></label>
          <label class="check"><input type="checkbox" name="web" value="1"><span>Incluir fuentes de internet (Wikipedia y artículos abiertos, citadas aparte)</span></label>
        </div>
        <div class="pac-actions">
          <button class="btn primary" type="submit" name="action" value="generate" data-busy="Preparando la vista previa…">Generar (vista previa)</button>
          <button class="btn ghost" type="submit" name="action" value="eval_blank" formnovalidate>Nueva evaluación en blanco</button>
        </div>
      </form>
      <?php if ($evals): ?>
        <h3 class="pac-sub">Evaluaciones</h3>
        <?php foreach ($evals as $e): ?>
          <a class="pac-row<?= $eval && (int) $eval['id'] === (int) $e['id'] ? ' is-current' : '' ?>" href="<?= h($self) ?>&amp;eval=<?= (int) $e['id'] ?>#evaluacion">
            <span><strong><?= h(date('d/m/Y', strtotime((string) $e['fecha']))) ?></strong> · <?= h($e['patrones'] !== '' ? pac_trim((string) $e['patrones'], 90) : 'Sin patrón escrito') ?><br>
              <span class="muted small"><?= h(pac_mode_label((string) $e['mode'])) ?> · versión <?= (int) $e['version'] ?></span></span>
            <span class="tag <?= $e['status'] === 'revisado' ? 'ok' : 'warn' ?>"><?= h(PAC_EVAL_STATUS[$e['status']] ?? $e['status']) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="panel" id="lengua-fotos">
      <h2>Fotos de la lengua</h2>
      <p class="hint">Una o más por consulta, para comparar la evolución. Se guardan privadas (solo con tu sesión de admin) y al subirlas se les quitan
        los datos del teléfono y la ubicación. JPG, PNG o WEBP, hasta <?= PAC_PHOTO_MAX / 1048576 ?> MB (se achican a <?= PAC_PHOTO_SIDE ?> px).
        Consejo: luz natural, lengua afuera relajada, sin comer ni cepillar la lengua antes.</p>
      <form method="post" enctype="multipart/form-data" class="pac-search">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="tongue_upload">
        <label>Fotos <input type="file" name="fotos[]" multiple accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" required></label>
        <label>Fecha <input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>"></label>
        <label>Nota (opcional) <input name="caption" maxlength="200" placeholder="Ej: antes de la sesión"></label>
        <button class="btn primary" type="submit">Subir</button>
      </form>
      <?php if (count($cmpIds) >= 2): ?>
        <div class="pac-compare" id="comparar">
          <?php foreach ($cmpIds as $cid): $ph = $photoById[$cid]; ?>
            <figure class="pac-photo">
              <img src="pacientes_archivo.php?id=<?= (int) $cid ?>" alt="Lengua del <?= h(date('d/m/Y', strtotime((string) $ph['fecha']))) ?>">
              <figcaption><strong><?= h(format_date_es((string) $ph['fecha'])) ?></strong><?= (string) $ph['caption'] !== '' ? ' · ' . h((string) $ph['caption']) : '' ?>
                <?php if (isset($photoTongue[$cid])): ?><br><span class="small"><?= nl2br(h($photoTongue[$cid])) ?></span><?php endif; ?></figcaption>
            </figure>
          <?php endforeach; ?>
          <p class="span-all"><a class="btn ghost" href="<?= h($self) ?>#lengua-fotos">Cerrar la comparación</a></p>
        </div>
      <?php endif; ?>
      <?php if ($tonguePhotos): ?>
        <form method="get" action="paciente.php#comparar" class="pac-photos">
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <?php foreach ($tonguePhotos as $ph): ?>
            <figure class="pac-photo">
              <a href="pacientes_archivo.php?id=<?= (int) $ph['id'] ?>" target="_blank" rel="noopener"><img src="pacientes_archivo.php?id=<?= (int) $ph['id'] ?>" alt="Lengua del <?= h(date('d/m/Y', strtotime((string) $ph['fecha']))) ?>" loading="lazy"></a>
              <figcaption>
                <label class="check"><input type="checkbox" name="cmp[]" value="<?= (int) $ph['id'] ?>"<?= in_array((int) $ph['id'], $cmpIds, true) ? ' checked' : '' ?>>
                  <span><?= h(date('d/m/Y', strtotime((string) $ph['fecha']))) ?><?= (string) $ph['caption'] !== '' ? ' · ' . h((string) $ph['caption']) : '' ?></span></label>
                <button class="pac-link-btn" type="submit" form="del-photo-<?= (int) $ph['id'] ?>">Borrar</button>
              </figcaption>
            </figure>
          <?php endforeach; ?>
          <p class="span-all"><button class="btn ghost" type="submit">Comparar las marcadas (2 a 4)</button></p>
        </form>
        <?php foreach ($tonguePhotos as $ph): ?>
          <form method="post" id="del-photo-<?= (int) $ph['id'] ?>" hidden onsubmit="return confirm('¿Borrar esta foto de la lengua?')">
            <?= pac_csrf_field() ?>
            <input type="hidden" name="action" value="tongue_delete">
            <input type="hidden" name="file_id" value="<?= (int) $ph['id'] ?>">
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="panel" id="historia">
      <h2>Historia clínica</h2>
      <details class="admin-details"<?= !$history ? ' open' : '' ?>>
        <summary>Agregar registro</summary>
        <form method="post" class="form-grid" enctype="multipart/form-data">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="action" value="history_add">
          <label>Fecha <input type="date" name="fecha" value="<?= h(date('Y-m-d')) ?>"></label>
          <label>Motivo de consulta <input name="motivo" maxlength="2000"></label>
          <label class="span-2">Evolución <textarea name="evolucion" rows="4"></textarea></label>
          <label class="span-2">Notas <textarea name="notas" rows="2"></textarea></label>
          <label class="span-2">Adjuntos (PDF, JPG, PNG, WEBP, HEIC o DOCX; hasta <?= PAC_FILE_MAX / 1048576 ?> MB cada uno)
            <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.docx">
          </label>
          <div class="span-2"><button class="btn primary" type="submit">Guardar registro</button></div>
        </form>
      </details>
      <?php foreach ($history as $e): ?>
        <article class="pac-entry">
          <div class="pac-entry-head">
            <strong><?= h(format_date_es((string) $e['fecha'])) ?></strong>
            <?php if ($e['motivo'] !== ''): ?><span> · <?= h($e['motivo']) ?></span><?php endif; ?>
          </div>
          <?php if ($e['evolucion'] !== ''): ?><p><?= nl2br(h($e['evolucion'])) ?></p><?php endif; ?>
          <?php if ($e['notas'] !== ''): ?><p class="muted small"><strong>Notas:</strong> <?= nl2br(h($e['notas'])) ?></p><?php endif; ?>
          <?php foreach ($filesByHistory[(int) $e['id']] ?? [] as $f): ?>
            <a class="tag" href="pacientes_archivo.php?id=<?= (int) $f['id'] ?>" target="_blank" rel="noopener">📎 <?= h($f['original_name']) ?></a>
          <?php endforeach; ?>
          <details class="admin-details">
            <summary>Editar</summary>
            <form method="post" class="form-grid" enctype="multipart/form-data">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="history_update">
              <input type="hidden" name="history_id" value="<?= (int) $e['id'] ?>">
              <label>Fecha <input type="date" name="fecha" value="<?= h((string) $e['fecha']) ?>"></label>
              <label>Motivo de consulta <input name="motivo" maxlength="2000" value="<?= h((string) $e['motivo']) ?>"></label>
              <label class="span-2">Evolución <textarea name="evolucion" rows="4"><?= h((string) $e['evolucion']) ?></textarea></label>
              <label class="span-2">Notas <textarea name="notas" rows="2"><?= h((string) $e['notas']) ?></textarea></label>
              <label class="span-2">Agregar adjuntos <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.docx"></label>
              <div class="span-2"><button class="btn primary" type="submit">Guardar cambios</button></div>
            </form>
            <form method="post" onsubmit="return confirm('¿Borrar este registro de la historia clínica?')">
              <?= pac_csrf_field() ?>
              <input type="hidden" name="action" value="history_delete">
              <input type="hidden" name="history_id" value="<?= (int) $e['id'] ?>">
              <button class="btn ghost" type="submit">Borrar registro</button>
            </form>
          </details>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="panel" id="archivos">
      <h2>Archivos</h2>
      <p class="hint">Estudios, informes, fotos o historias clínicas escaneadas. Se guardan fuera del acceso público y solo se abren con la sesión del admin.</p>
      <form method="post" enctype="multipart/form-data" class="pac-search">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="file_upload">
        <label>Subir archivos (PDF, imágenes o DOCX; hasta <?= PAC_FILE_MAX / 1048576 ?> MB cada uno)
          <input type="file" name="files[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.docx">
        </label>
        <button class="btn primary" type="submit">Subir</button>
      </form>
      <?php foreach ($files as $f): ?>
        <div class="pac-row">
          <span><a href="pacientes_archivo.php?id=<?= (int) $f['id'] ?>" target="_blank" rel="noopener"><strong><?= h($f['original_name']) ?></strong></a><br>
            <span class="muted small"><?= h(pac_size_label((int) $f['size'])) ?> · <?= h(date('d/m/Y', strtotime((string) $f['created_at']))) ?><?= $f['history_id'] !== null ? ' · adjunto de la historia clínica' : '' ?></span></span>
          <form method="post" onsubmit="return confirm('¿Borrar este archivo?')">
            <?= pac_csrf_field() ?>
            <input type="hidden" name="action" value="file_delete">
            <input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>">
            <button class="btn ghost" type="submit">Borrar</button>
          </form>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="panel" id="turnos">
      <h2>Turnos, consentimientos y Planes MTC</h2>
      <p class="hint">Turnos que coinciden por email, teléfono o nombre. Desde un turno de MTC podés abrir o crear su Plan MTC de 5 sesiones.</p>
      <?php if ($plans): ?>
        <h3 class="pac-sub">Planes MTC</h3>
        <?php foreach ($plans as $plan): ?>
          <div class="pac-row">
            <span>Plan desde el turno del <?= h($plan['appt'] ? format_date_es((string) $plan['appt']['date']) : '—') ?><br>
              <span class="muted small">Estado: <?= h((string) $plan['status']) ?> · actualizado <?= h(date('d/m/Y', strtotime((string) $plan['updated_at']))) ?></span></span>
            <a class="btn ghost" href="plan_mtc.php?id=<?= (int) $plan['appointment_id'] ?>">Abrir Plan MTC</a>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php $lastHandoff = pac_mtc_last_handoff($id); ?>
      <?php if ($lastHandoff && pac_mtc_ready()): ?>
        <form method="post" class="pac-actions" onsubmit="return confirm('¿Deshacer el último traspaso al Plan MTC? El plan vuelve a como estaba antes.')">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="action" value="mtc_undo">
          <span class="muted small">Último traspaso desde Pacientes: <?= h(date('d/m/Y H:i', strtotime((string) $lastHandoff['created_at']))) ?></span>
          <button class="btn ghost" type="submit">Deshacer ese traspaso</button>
        </form>
      <?php endif; ?>
      <?php if (!$appointments): ?>
        <p class="muted">No hay turnos con este email, teléfono o nombre.</p>
      <?php else: ?>
        <h3 class="pac-sub">Turnos</h3>
        <?php foreach ($appointments as $a): ?>
          <div class="pac-row">
            <span>
              <strong><?= h(format_date_es((string) $a['date'])) ?> · <?= h(format_time_es((string) $a['time'])) ?></strong> · <?= h($a['therapy_name']) ?>
              <span class="tag"><?= h(pac_appt_status($a)) ?></span><br>
              <?php if (!empty($a['consent_accepted_at'])): ?>
                <span class="tag ok">Consentimiento firmado</span>
                <span class="muted small"><?= h(date('d/m/Y H:i', strtotime((string) $a['consent_accepted_at']))) ?></span>
              <?php elseif ($a['status'] !== 'cancelled'): ?>
                <span class="tag warn">Consentimiento pendiente</span>
              <?php endif; ?>
            </span>
            <span class="pac-actions">
              <?php if ($a['status'] === 'confirmed'): ?>
                <a class="btn ghost" href="pdf.php?token=<?= h(urlencode((string) $a['token'])) ?>&amp;doc=consentimiento" target="_blank" rel="noopener">Consentimiento PDF</a>
              <?php endif; ?>
              <?= $a['status'] !== 'cancelled' && function_exists('mtc_admin_link') ? mtc_admin_link($a) : '' ?>
            </span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <details class="panel pac-panel" id="datos"<?= isset($_GET['editar']) ? ' open' : '' ?>>
      <summary><h2>Datos personales y antecedentes</h2></summary>
      <form method="post" class="form-grid">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="save_patient">
        <?php require __DIR__ . '/includes/pac_patient_form.php'; ?>
        <div class="span-2"><button class="btn primary" type="submit">Guardar datos</button></div>
      </form>
      <details class="admin-details pac-danger">
        <summary>Borrar ficha del paciente</summary>
        <form method="post" class="pac-search" onsubmit="return confirm('¿Borrar definitivamente la ficha, la historia clínica, los archivos y las evaluaciones de este paciente?')">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="action" value="delete_patient">
          <label>Escribí BORRAR para confirmar <input name="confirm_name" autocomplete="off" required pattern="BORRAR"></label>
          <button class="btn ghost" type="submit">Borrar ficha</button>
        </form>
      </details>
    </details>
<?php
pac_page_end(['assets/pacientes.js?v=20260929c']);
