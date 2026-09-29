<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/mtc_plan.php';

$apptId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$appt = $apptId > 0 ? turno_by_id($apptId) : null;
if ($apptId > 0 && !$appt) {
    flash('error', 'No se encontró ese turno.');
    redirect('plan_mtc.php');
}
if ($appt) {
    $visitOf = mtc_visit_of($apptId);
    if ($visitOf) {
        redirect('plan_mtc.php?id=' . (int) $visitOf['appointment_id'] . '#consulta-' . (int) $visitOf['consult_no']);
    }
}
$self = 'plan_mtc.php' . ($appt ? '?id=' . $apptId : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$appt) {
        redirect('plan_mtc.php');
    }
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida. Volvé a intentar.');
        redirect($self);
    }
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';
    $row = mtc_plan_for_appointment($apptId);

    if ($action === 'schedule') {
        if (!$row) {
            flash('error', 'Primero guardá el diagnóstico de la consulta 1.');
            redirect($self);
        }
        $rows = [];
        foreach (is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [] as $r) {
            if (!is_array($r) || ($r['on'] ?? '') !== '1') {
                continue;
            }
            $rows[] = [(int) ($r['n'] ?? 0), is_string($r['date'] ?? null) ? trim($r['date']) : '', is_string($r['time'] ?? null) ? trim($r['time']) : ''];
        }
        if (!$rows) {
            flash('error', 'No marcaste ninguna sesión para agendar.');
            redirect($self . '#agendar');
        }
        [$created, $errors] = mtc_schedule($row, $appt, $rows, max(0, (int) ($_POST['deposit_amount'] ?? 0)));
        $msg = $created ? 'Agendadas ' . count($created) . ' sesiones semanales (consultas ' . implode(', ', array_keys($created)) . ').' : 'No se agendó ninguna sesión.';
        $mailMode = (string) ($_POST['mail_mode'] ?? 'none');
        if ($created && $mailMode === 'summary') {
            $res = mtc_send_schedule_mail($appt, $created);
            $msg .= $res === 'sent' ? ' Se mandó un mail con todas las fechas.' : ($res === 'no_email' ? ' No tiene email: avisale por WhatsApp.' : ' Ojo: no se pudo mandar el mail.');
        } elseif ($created && $mailMode === 'each') {
            $sent = 0;
            foreach ($created as $id) {
                try {
                    $sent += send_turno_confirmation((int) $id) === 'sent' ? 1 : 0;
                } catch (Throwable $e) {
                    error_log('Plan MTC: no se pudo mandar el mail del turno ' . $id . ': ' . $e->getMessage());
                }
            }
            $msg .= ' Mails de confirmación enviados: ' . $sent . ' de ' . count($created) . '.';
        } elseif ($created) {
            $msg .= ' No se mandó mail.';
        }
        if ($errors) {
            $msg .= ' Problemas: ' . implode(' ', $errors);
        }
        flash($errors && !$created ? 'error' : 'success', $msg);
        redirect($self . '#agendar');
    }

    if ($action === 'restore') {
        $version = $row ? mtc_version((int) $row['id'], (int) ($_POST['version'] ?? 0)) : null;
        if (!$version) {
            flash('error', 'No se encontró esa versión.');
            redirect($self . '#versiones');
        }
        $when = date('d/m/Y H:i', strtotime((string) $version['created_at']));
        mtc_save($apptId, mtc_normalize(json_decode((string) $version['data'], true) ?: []), $row, 'Antes de restaurar la versión del ' . $when);
        flash('success', 'Se restauró la versión del ' . $when . '. Lo que estaba guardado quedó en el historial.');
        redirect($self);
    }

    if ($action === 'generate') {
        $formState = mtc_from_post($_POST, $row['plan'] ?? []);
        [$proposal, $protocol] = mtc_propose($formState);
        $mode = 'proposal';
    } elseif (in_array($action, ['apply', 'proposal_pdf', 'back'], true)) {
        $formState = mtc_state_decode($_POST['state'] ?? null);
        if ($formState === null) {
            flash('error', 'No se pudo leer la vista previa. Generá el plan de nuevo.');
            redirect($self);
        }
        if ($action === 'back') {
            $mode = 'back';
        } else {
            $final = mtc_merge_proposal($formState, $_POST);
            if ($action === 'proposal_pdf') {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="Vista-previa-' . mtc_pdf_name($appt) . '"');
                header('Cache-Control: private, no-store');
                echo mtc_plan_pdf($final, $appt, $row ? mtc_visits((int) $row['id']) : []);
                exit;
            }
            mtc_save($apptId, $final, $row, 'Antes de aplicar un plan generado');
            flash('success', $row && $row['plan']['generated']
                ? 'Plan guardado. El anterior quedó en el historial de versiones.'
                : 'Plan guardado.');
            redirect($self . '#bloque');
        }
    } else {
        $plan = mtc_from_post($_POST, $row['plan'] ?? []);
        if ($action === 'preview') {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="Vista-previa-' . mtc_pdf_name($appt) . '"');
            header('Cache-Control: private, no-store');
            echo mtc_plan_pdf($plan, $appt, $row ? mtc_visits((int) $row['id']) : []);
            exit;
        }
        $planId = mtc_save($apptId, $plan, $row);
        $row = mtc_plan_for_appointment($apptId);
        $visits = mtc_visits($planId);
        if ($action === 'send') {
            if (!$row['plan']['generated'] || $row['plan']['patient']['resumen'] === '') {
                flash('error', 'Primero generá y guardá el plan (botón «Generar plan»).');
                redirect($self . '#paciente');
            }
            $res = mtc_send_plan($row, $appt, $visits);
            flash($res === 'sent' ? 'success' : 'error', match ($res) {
                'sent' => 'Plan enviado a ' . $appt['patient_email'] . ' con el PDF adjunto.',
                'no_email' => 'El turno no tiene un email válido: descargá la vista previa y mandala por WhatsApp.',
                default => 'No se pudo mandar el mail. Probá de nuevo más tarde.',
            });
            redirect($self);
        }
        flash('success', 'Guardado.');
        redirect($self);
    }
}

$mode ??= 'editor';
$row = $appt ? mtc_plan_for_appointment($apptId) : null;
if ($appt && $row && ($_GET['pdf'] ?? '') === '1') {
    $version = isset($_GET['version']) ? mtc_version((int) $row['id'], (int) $_GET['version']) : null;
    if (isset($_GET['version']) && !$version) {
        http_response_code(404);
        exit('Versión no encontrada.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . ($version ? 'Version-' . (int) $version['id'] . '-' : '') . mtc_pdf_name($appt) . '"');
    header('Cache-Control: private, no-store');
    echo mtc_plan_pdf($version ? mtc_normalize(json_decode((string) $version['data'], true) ?: []) : $row['plan'], $appt, mtc_visits((int) $row['id']));
    exit;
}

$flash = take_flash();
$csrf = csrf_token();

function mtc_select(string $name, array $opts, string $value, string $empty = '—'): string
{
    $html = '<select name="' . h($name) . '"><option value="">' . h($empty) . '</option>';
    foreach ($opts as $key => $label) {
        $v = is_int($key) ? $label : $key;
        $html .= '<option value="' . h($v) . '"' . ($v === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

function mtc_checks(string $name, array $opts, array $values): string
{
    $html = '<div class="mtc-checks">';
    foreach ($opts as $key => $label) {
        $v = is_int($key) ? $label : $key;
        $html .= '<label class="check"><input type="checkbox" name="' . h($name) . '[]" value="' . h($v) . '"' . (in_array($v, $values, true) ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    return $html . '</div>';
}

function mtc_scale(string $name, ?int $value): string
{
    return '<input type="number" name="' . h($name) . '" min="0" max="10" step="1" inputmode="numeric" value="' . ($value === null ? '' : $value) . '">';
}

/** Campo de la vista previa: a la izquierda lo guardado (si cambia), a la derecha la propuesta editable. */
function mtc_proposal_field(string $label, string $name, string $value, ?string $before, int $rows = 4): string
{
    $area = '<textarea name="' . h($name) . '" rows="' . $rows . '">' . h($value) . '</textarea>';
    if ($before === null) {
        return '<label class="mtc-field">' . h($label) . $area . '</label>';
    }
    if ($before === $value) {
        return '<label class="mtc-field">' . h($label) . ' <span class="tag">sin cambios</span>' . $area . '</label>';
    }
    return '<div class="mtc-field mtc-compare"><span class="mtc-label">' . h($label) . ' <span class="tag warn">cambia</span></span>'
        . '<div class="mtc-compare-grid"><div><span class="muted small">Guardado ahora</span><div class="mtc-before">'
        . ($before !== '' ? nl2br(h($before)) : '<em class="muted">(vacío)</em>') . '</div></div>'
        . '<label><span class="muted small">Propuesta (podés editarla)</span>' . $area . '</label></div></div>';
}

$plan = match ($mode) {
    'proposal' => $proposal,
    'back' => $formState,
    default => $row['plan'] ?? mtc_blank(),
};
$visits = $row ? mtc_visits((int) $row['id']) : [];
$done = mtc_done($plan);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Plan MTC · Admin turnos · FluxusTerapia</title>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css?v=20260929d">
  <link rel="stylesheet" href="assets/plan_mtc.css?v=20260929c">
</head>
<body class="mtc-page">
  <header class="top">
    <a class="brand brand--home" href="../" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span>Turnos · Plan MTC</span>
    </a>
    <nav><a href="admin.php">Admin turnos</a><a href="plan_mtc.php">Planes MTC</a></nav>
  </header>
  <main class="wrap">
    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

<?php if (!$appt): ?>
    <?php
      $plans = db()->query("
        SELECT p.*, a.patient_name, a.date, a.time, t.name AS therapy_name
        FROM mtc_plans p JOIN appointments a ON a.id = p.appointment_id JOIN therapies t ON t.id = a.therapy_id
        ORDER BY p.updated_at DESC LIMIT 200
      ")->fetchAll();
      $showAll = ($_GET['todas'] ?? '') === '1';
      $candidates = array_filter(db()->query("
        SELECT a.*, t.name AS therapy_name
        FROM appointments a JOIN therapies t ON t.id = a.therapy_id
        WHERE a.status IN ('confirmed', 'pending_deposit') AND a.date >= date('now', '-120 days')
          AND a.id NOT IN (SELECT appointment_id FROM mtc_plans)
          AND a.id NOT IN (SELECT appointment_id FROM mtc_plan_visits)
        ORDER BY a.date DESC, a.time DESC LIMIT 150
      ")->fetchAll(), static fn ($a) => $showAll || mtc_is_therapy((string) $a['therapy_name']));
    ?>
    <h1>Planes de Medicina Tradicional China</h1>
    <p class="lede">La consulta 1 lleva el diagnóstico (solo ahí) y la tonificación general. Con eso se genera el primer bloque de 5 consultas y los controles semanales hasta completar 25 consultas.</p>

    <section class="panel">
      <h2>Planes</h2>
      <?php if (!$plans): ?>
        <p class="muted">Todavía no hay planes. Elegí un turno de abajo para empezar.</p>
      <?php endif; ?>
      <?php foreach ($plans as $p): ?>
        <?php $pp = mtc_normalize(json_decode((string) $p['data'], true) ?: []); $pd = mtc_done($pp); ?>
        <article class="mtc-row">
          <div>
            <strong><?= h($p['patient_name']) ?></strong> · <?= h($p['therapy_name']) ?><br>
            <span class="muted">Consulta 1: <?= h(format_date_es((string) $p['date'])) ?> · Consultas hechas: <?= $pd ?> de <?= MTC_TOTAL ?></span><br>
            <?php if ($p['status'] === 'enviado'): ?>
              <span class="tag ok">Enviado <?= h(date('d/m/Y', strtotime((string) $p['sent_at']))) ?></span>
            <?php else: ?>
              <span class="tag warn">Borrador</span>
            <?php endif; ?>
          </div>
          <div><a class="btn ghost" href="plan_mtc.php?id=<?= (int) $p['appointment_id'] ?>">Abrir</a></div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="panel">
      <h2>Crear un plan desde un turno</h2>
      <p class="hint">Turnos de los últimos 120 días y próximos<?= $showAll ? ', de todas las terapias' : ' de Medicina china / acupuntura' ?>, que todavía no tienen plan.
        <a href="plan_mtc.php<?= $showAll ? '' : '?todas=1' ?>"><?= $showAll ? 'Ver solo Medicina china' : 'Ver todas las terapias' ?></a></p>
      <?php if (!$candidates): ?>
        <p class="muted">No hay turnos para mostrar.</p>
      <?php endif; ?>
      <?php foreach ($candidates as $a): ?>
        <article class="mtc-row">
          <div>
            <strong><?= h($a['patient_name']) ?></strong> · <?= h($a['therapy_name']) ?><br>
            <span class="muted"><?= h(format_date_es((string) $a['date'])) ?> · <?= h(format_time_es((string) $a['time'])) ?> · código <?= h($a['code']) ?></span>
          </div>
          <div><a class="btn primary" href="plan_mtc.php?id=<?= (int) $a['id'] ?>">Plan MTC</a></div>
        </article>
      <?php endforeach; ?>
    </section>

<?php else: ?>
    <?php
      $d = $plan['diag'];
      $hasEmail = filter_var(trim((string) $appt['patient_email']), FILTER_VALIDATE_EMAIL) !== false;
      $warnings = mtc_warnings($plan, $appt);
      $patterns = mtc_patterns();
      $tonify = $d['tonificacion'] !== '' ? $d['tonificacion'] : mtc_default_tonify(mtc_pregnant($plan));
      $lastScale = $d['escala'];
      foreach ($plan['sessions'] + $plan['controls'] as $r) {
          if (!empty($r['realizada']) && $r['escala'] !== null) {
              $lastScale = $r['escala'];
          }
      }
      $changedSinceSent = $row && $row['status'] === 'enviado' && (string) $row['updated_at'] > (string) $row['sent_at'];
    ?>
    <div class="mtc-head">
      <div>
        <p class="eyebrow">Plan de Medicina Tradicional China</p>
        <h1><?= h($appt['patient_name']) ?></h1>
        <p class="muted">
          <?= h($appt['therapy_name']) ?> · consulta 1: <?= h(format_date_es((string) $appt['date'])) ?> <?= h(format_time_es((string) $appt['time'])) ?><br>
          <?= h(implode(' · ', array_filter([trim((string) $appt['patient_email']), trim((string) $appt['patient_phone'])]))) ?: 'Sin email ni teléfono' ?>
        </p>
      </div>
      <div class="mtc-status">
        <?php if (!$row): ?>
          <span class="tag">Sin guardar</span>
        <?php elseif ($row['status'] === 'enviado'): ?>
          <span class="tag ok">Enviado <?= h(date('d/m/Y H:i', strtotime((string) $row['sent_at']))) ?></span>
          <?php if ($changedSinceSent): ?><span class="tag warn">Cambios sin enviar</span><?php endif; ?>
        <?php else: ?>
          <span class="tag warn">Borrador</span>
        <?php endif; ?>
        <strong class="mtc-counter">Consulta <?= min(MTC_TOTAL, max(1, $done)) ?> de <?= MTC_TOTAL ?></strong>
        <div class="mtc-progress" role="img" aria-label="<?= $done ?> de <?= MTC_TOTAL ?> consultas hechas"><span style="width:<?= round($done / MTC_TOTAL * 100) ?>%"></span></div>
        <span class="muted small"><?= $done ?> hechas<?= $d['escala'] !== null ? ' · escala inicial ' . $d['escala'] . '/10' : '' ?><?= $lastScale !== null && $done > 1 ? ' · última ' . $lastScale . '/10' : '' ?></span>
      </div>
    </div>

    <?php if (!mtc_is_therapy((string) $appt['therapy_name'])): ?>
      <div class="alert warn">Este turno es de «<?= h($appt['therapy_name']) ?>», no de Medicina china. Podés armar el plan igual.</div>
    <?php endif; ?>
    <?php if ($warnings): ?>
      <div class="alert warn"><strong>A tener en cuenta</strong><ul><?php foreach ($warnings as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($done >= MTC_TOTAL): ?>
      <div class="alert ok">Primera etapa completa (25 consultas). Si continúa, empezá un plan nuevo desde un turno nuevo: lleva un nuevo diagnóstico.</div>
    <?php endif; ?>

<?php if ($mode === 'proposal'): ?>
    <?php
      $saved = $row && $row['plan']['generated'] ? $row['plan'] : null;
      $diagChanges = $row ? mtc_diag_changes($row['plan'], $proposal) : [];
    ?>
    <div class="mtc-preview-banner" role="status">
      <strong>Vista previa — todavía no se guardó.</strong>
      Revisá el protocolo y los textos, y editá lo que quieras. No cambia nada hasta que toques «Guardar plan».
    </div>

    <section class="panel">
      <h2>Protocolo que se va a aplicar</h2>
      <dl class="mtc-protocol">
        <?php foreach ($protocol as [$term, $text]): ?>
          <dt><?= h($term) ?></dt><dd><?= h($text) ?></dd>
        <?php endforeach; ?>
        <dt>Frecuencia</dt><dd><?= h(mtc_frequency_text()) ?></dd>
      </dl>
    </section>

    <?php if ($row): ?>
      <section class="panel mtc-changes">
        <h2>Qué cambia respecto de lo guardado</h2>
        <?php if ($diagChanges): ?>
          <p>Consulta 1: también se guardan los cambios en <?= h(implode(', ', $diagChanges)) ?>.</p>
        <?php endif; ?>
        <?php if ($saved): ?>
          <p>Ya hay un plan guardado. En cada texto que cambia ves lo guardado al lado de la propuesta. Si guardás, el plan actual queda en el historial de versiones y lo podés restaurar.</p>
        <?php else: ?>
          <p>Todavía no había un plan generado: se crea nuevo<?= $diagChanges ? '' : ' con el diagnóstico guardado' ?>.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <form method="post" id="proposal-form" action="plan_mtc.php?id=<?= (int) $apptId ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int) $apptId ?>">
      <input type="hidden" name="state" value="<?= h(mtc_state_encode($formState)) ?>">

      <section class="panel">
        <h2>Consulta 1 · Tonificación general</h2>
        <?= mtc_proposal_field('Tonificación general aplicada', 'diag[tonificacion]', $proposal['diag']['tonificacion'], $saved['diag']['tonificacion'] ?? null) ?>
      </section>

      <section class="panel">
        <h2>Primer bloque · consultas 2 a 5</h2>
        <p class="hint">En cada consulta: control breve al comenzar (escala 0–10, cambios y reacciones), sin nuevo diagnóstico.</p>
        <?php foreach ($proposal['sessions'] as $n => $s): ?>
          <div class="mtc-proposal-session">
            <h3>Consulta <?= $n ?> de <?= MTC_TOTAL ?> · <?= h(MTC_SESSION_TITLES[$n]) ?></h3>
            <?= mtc_proposal_field('Para el paciente (objetivo y qué hacemos)', 's[' . $n . '][objetivo]', $s['objetivo'], $saved['sessions'][$n]['objetivo'] ?? null, 3) ?>
            <?= mtc_proposal_field('Puntos', 's[' . $n . '][puntos]', $s['puntos'], $saved['sessions'][$n]['puntos'] ?? null) ?>
            <?= mtc_proposal_field('Técnica y notas (solo para vos)', 's[' . $n . '][tecnica]', $s['tecnica'], $saved['sessions'][$n]['tecnica'] ?? null, 3) ?>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="panel">
        <h2>Lo que recibe el paciente</h2>
        <?= mtc_proposal_field('Resumen del diagnóstico en palabras simples', 'p[resumen]', $proposal['patient']['resumen'], $saved['patient']['resumen'] ?? null, 6) ?>
        <?= mtc_proposal_field('Recomendaciones para casa (un renglón por punto, empezando con «-»)', 'p[recomendaciones]', $proposal['patient']['recomendaciones'], $saved['patient']['recomendaciones'] ?? null, 7) ?>
        <p class="hint">El PDF y el mail suman la frecuencia semanal (primera etapa de hasta 25 consultas) y el aviso de que no reemplaza el tratamiento médico. No van el pulso, las notas internas, la técnica ni los controles.</p>
      </section>

      <div class="mtc-bar">
        <button class="btn primary" type="submit" name="action" value="apply" id="btn-apply" data-replace="<?= $saved ? '1' : '0' ?>">Guardar plan</button>
        <button class="btn ghost" type="submit" name="action" value="proposal_pdf" formtarget="_blank">Ver PDF de la propuesta</button>
        <button class="btn ghost" type="submit" name="action" value="back">Volver a la consulta 1</button>
        <a class="btn ghost" href="plan_mtc.php?id=<?= (int) $apptId ?>" id="btn-discard">Descartar</a>
        <span class="hint">Nada se guarda hasta «Guardar plan».</span>
      </div>
    </form>
    <script>
      (function () {
        var apply = document.getElementById('btn-apply');
        apply.addEventListener('click', function (ev) {
          if (apply.dataset.replace === '1' && !confirm('Esto reemplaza el plan guardado. La versión actual queda en el historial y la podés restaurar. ¿Guardar el plan nuevo?')) {
            ev.preventDefault();
          }
        });
        document.getElementById('btn-discard').addEventListener('click', function (ev) {
          if (!confirm('¿Descartar la propuesta? No se guarda nada de lo que cambiaste en esta pantalla ni en la consulta 1.')) {
            ev.preventDefault();
          }
        });
      })();
    </script>
<?php else: ?>
    <?php if ($mode === 'back'): ?>
      <div class="alert warn">Volviste a la consulta 1: estos datos todavía no se guardaron. Cambiá lo que necesites y tocá «Generar plan» de nuevo (o «Guardar» para guardar solo el diagnóstico).</div>
    <?php endif; ?>
    <form method="post" id="plan-form" action="plan_mtc.php?id=<?= (int) $apptId ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int) $apptId ?>">

      <details class="panel mtc-panel" id="consulta-1" <?= $plan['generated'] ? '' : 'open' ?>>
        <summary><h2>Consulta 1 de <?= MTC_TOTAL ?> · Diagnóstico y tonificación general</h2>
          <span class="muted small">El diagnóstico se hace solo en esta consulta.</span></summary>
        <div class="form-grid">
          <div class="span-2">
            <span class="mtc-label">Contraindicaciones y cuidados</span>
            <?= mtc_checks('diag[contra]', MTC_CONTRA, $d['contra']) ?>
            <p class="hint"><?= !empty($appt['consent_accepted_at'])
                ? '<span class="tag ok">Consentimiento firmado online</span> ' . h(date('d/m/Y', strtotime((string) $appt['consent_accepted_at'])))
                : '<span class="tag warn">Consentimiento sin firmar online</span> confirmá que lo firmó en papel.' ?></p>
          </div>
          <label class="span-2">Motivo de consulta
            <textarea name="diag[motivo]" rows="2"><?= h($d['motivo']) ?></textarea>
          </label>
          <label class="span-2">Antecedentes y medicación
            <textarea name="diag[antecedentes]" rows="2"><?= h($d['antecedentes']) ?></textarea>
          </label>

          <h3 class="span-2 mtc-sub">Interrogatorio</h3>
          <label>Sueño <?= mtc_select('diag[sueno]', MTC_OPTIONS['sueno'], $d['sueno']) ?></label>
          <label>Digestión <?= mtc_select('diag[digestion]', MTC_OPTIONS['digestion'], $d['digestion']) ?></label>
          <label>Sed <?= mtc_select('diag[sed]', MTC_OPTIONS['sed'], $d['sed']) ?></label>
          <label>Frío / calor <?= mtc_select('diag[frio_calor]', MTC_OPTIONS['frio_calor'], $d['frio_calor']) ?></label>
          <label>Ánimo <?= mtc_select('diag[animo]', MTC_OPTIONS['animo'], $d['animo']) ?></label>
          <label>Ciclo menstrual <?= mtc_select('diag[ciclo]', MTC_OPTIONS['ciclo'], $d['ciclo']) ?></label>
          <label class="span-2">Notas del interrogatorio
            <textarea name="diag[interrog_notas]" rows="2"><?= h($d['interrog_notas']) ?></textarea>
          </label>

          <h3 class="span-2 mtc-sub">Glosodiagnosis (lengua)</h3>
          <label>Cuerpo · color <?= mtc_select('diag[lengua_color]', MTC_OPTIONS['lengua_color'], $d['lengua_color']) ?></label>
          <div><span class="mtc-label">Cuerpo · forma y marcas</span><?= mtc_checks('diag[lengua_forma]', MTC_MULTI['lengua_forma'], $d['lengua_forma']) ?></div>
          <label>Saburra · color <?= mtc_select('diag[saburra_color]', MTC_OPTIONS['saburra_color'], $d['saburra_color']) ?></label>
          <label>Saburra · espesor <?= mtc_select('diag[saburra_espesor]', MTC_OPTIONS['saburra_espesor'], $d['saburra_espesor']) ?></label>
          <label>Saburra · humedad <?= mtc_select('diag[saburra_humedad]', MTC_OPTIONS['saburra_humedad'], $d['saburra_humedad']) ?></label>
          <div><span class="mtc-label">Zonas alteradas</span><?= mtc_checks('diag[zonas]', MTC_MULTI['zonas'], $d['zonas']) ?></div>
          <label class="span-2">Notas de la lengua
            <textarea name="diag[lengua_notas]" rows="2"><?= h($d['lengua_notas']) ?></textarea>
          </label>
          <label class="span-2">Pulso y palpación <span class="muted small">(solo para vos, no va al paciente)</span>
            <textarea name="diag[pulso]" rows="2"><?= h($d['pulso']) ?></textarea>
          </label>

          <h3 class="span-2 mtc-sub">Patrón diagnosticado</h3>
          <div class="span-2 mtc-checks mtc-patterns">
            <?php foreach ($patterns as $key => $p): ?>
              <label class="check"><input type="checkbox" name="diag[patrones][]" value="<?= h($key) ?>" <?= in_array($key, $d['patrones'], true) ? 'checked' : '' ?>>
                <span><strong><?= h($p['label']) ?></strong><br><span class="muted small"><?= h($p['signs']) ?> · <?= h(mtc_points_text($p['points']) ?: 'locales, Ashi y distales') ?></span></span></label>
            <?php endforeach; ?>
          </div>
          <label>Zona del dolor (si es musculoesquelético)
            <?php $zoneOpts = array_map(static fn ($z) => $z['label'], mtc_pain_zones()); ?>
            <?= mtc_select('diag[zona_dolor]', $zoneOpts, $d['zona_dolor']) ?>
          </label>
          <label>Otro patrón (texto libre)
            <input name="diag[patron_otro]" maxlength="300" value="<?= h($d['patron_otro']) ?>">
          </label>
          <label>Síntoma principal a medir
            <input name="diag[sintoma]" maxlength="200" placeholder="Ej: dolor lumbar, cansancio" value="<?= h($d['sintoma']) ?>">
          </label>
          <label>Escala del síntoma hoy (0 a 10) <?= mtc_scale('diag[escala]', $d['escala']) ?></label>

          <label class="span-2">Tonificación general aplicada
            <textarea name="diag[tonificacion]" rows="4"><?= h($tonify) ?></textarea>
          </label>
          <label class="span-2">Notas internas <span class="muted small">(no van al paciente)</span>
            <textarea name="diag[notas]" rows="2"><?= h($d['notas']) ?></textarea>
          </label>
          <div class="span-2 mtc-actions-inline">
            <button class="btn ghost" type="submit" name="action" value="save">Guardar</button>
            <button class="btn primary" type="submit" name="action" value="generate">Generar plan (vista previa)</button>
            <span class="hint">Te muestra el protocolo y el plan propuesto antes de guardar nada.</span>
          </div>
        </div>
      </details>

      <section class="panel" id="bloque">
        <h2>Primer bloque · consultas 2 a 5</h2>
        <?php if (!$plan['generated']): ?>
          <p class="muted">Completá el diagnóstico y tocá «Generar plan».</p>
        <?php else: ?>
          <p class="hint">Controles semanales: al comenzar cada consulta, escala 0–10, cambios y reacciones. Sin nuevo diagnóstico. El texto «para el paciente» va en el PDF; puntos y técnica solo si marcás «incluir puntos».</p>
          <?php foreach ($plan['sessions'] as $n => $s): ?>
            <?php $date = mtc_consult_date($n, $plan, $appt, $visits); ?>
            <details class="mtc-session" id="consulta-<?= $n ?>" <?= !$s['realizada'] && $n === max(2, $done + 1) ? 'open' : '' ?>>
              <summary>
                <strong>Consulta <?= $n ?> de <?= MTC_TOTAL ?> · <?= h(MTC_SESSION_TITLES[$n]) ?></strong>
                <?php if ($date !== ''): ?><span class="muted small"><?= h(format_date_es($date)) ?><?= isset($visits[$n]) ? ' · ' . h(format_time_es((string) $visits[$n]['time'])) . ' (agendada)' : '' ?></span><?php endif; ?>
                <?php if ($s['realizada']): ?><span class="tag ok">Hecha<?= $s['escala'] !== null ? ' · ' . $s['escala'] . '/10' : '' ?></span><?php endif; ?>
              </summary>
              <div class="form-grid">
                <label class="span-2">Para el paciente (objetivo y qué hacemos)
                  <textarea name="s[<?= $n ?>][objetivo]" rows="3"><?= h($s['objetivo']) ?></textarea>
                </label>
                <label>Puntos
                  <textarea name="s[<?= $n ?>][puntos]" rows="4"><?= h($s['puntos']) ?></textarea>
                </label>
                <label>Técnica y notas <span class="muted small">(solo para vos)</span>
                  <textarea name="s[<?= $n ?>][tecnica]" rows="4"><?= h($s['tecnica']) ?></textarea>
                </label>
                <label>Fecha <input type="date" name="s[<?= $n ?>][fecha]" value="<?= h($s['fecha'] !== '' ? $s['fecha'] : (string) ($visits[$n]['date'] ?? '')) ?>"></label>
                <label>Escala 0 a 10 <?= mtc_scale('s[' . $n . '][escala]', $s['escala']) ?></label>
                <label class="span-2">Control: cambios y reacciones
                  <textarea name="s[<?= $n ?>][control]" rows="2"><?= h($s['control']) ?></textarea>
                </label>
                <?php if ($n === MTC_BLOCK): ?>
                  <label class="span-2">Decisión al cerrar el bloque
                    <?= mtc_select('p[decision]', array_slice(MTC_DECISIONS, 1, null, true), $plan['patient']['decision'], MTC_DECISIONS['']) ?>
                  </label>
                <?php endif; ?>
                <label class="check span-2"><input type="checkbox" name="s[<?= $n ?>][realizada]" value="1" <?= $s['realizada'] ? 'checked' : '' ?>><span>Consulta hecha</span></label>
              </div>
            </details>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="panel" id="controles">
        <h2>Controles semanales · consultas 6 a 25</h2>
        <p class="hint">Primera etapa: hasta 25 consultas, una por semana. Re-evaluación en las consultas 10, 15, 20 y 25 (escala comparada con la consulta 1, sin nuevo diagnóstico). Si hay alta antes, dejá de marcar controles.</p>
        <?php foreach ($plan['controls'] as $n => $c): ?>
          <?php
            $date = mtc_consult_date($n, $plan, $appt, $visits);
            $label = $n === MTC_TOTAL ? 'Cierre de la primera etapa · re-evaluación' : ($n % 5 === 0 ? 'Control semanal · re-evaluación' : 'Control semanal');
          ?>
          <details class="mtc-session" id="consulta-<?= $n ?>" <?= $plan['generated'] && !$c['realizada'] && $n === $done + 1 ? 'open' : '' ?>>
            <summary>
              <strong>Consulta <?= $n ?> de <?= MTC_TOTAL ?> · <?= h($label) ?></strong>
              <?php if ($date !== ''): ?><span class="muted small"><?= h(format_date_es($date)) ?><?= isset($visits[$n]) ? ' · ' . h(format_time_es((string) $visits[$n]['time'])) . ' (agendada)' : '' ?></span><?php endif; ?>
              <?php if ($c['realizada']): ?><span class="tag ok">Hecha<?= $c['escala'] !== null ? ' · ' . $c['escala'] . '/10' : '' ?></span><?php endif; ?>
            </summary>
            <div class="form-grid">
              <label>Fecha <input type="date" name="c[<?= $n ?>][fecha]" value="<?= h($c['fecha'] !== '' ? $c['fecha'] : (string) ($visits[$n]['date'] ?? '')) ?>"></label>
              <label>Escala 0 a 10 <?= mtc_scale('c[' . $n . '][escala]', $c['escala']) ?></label>
              <label>Cambios
                <textarea name="c[<?= $n ?>][cambios]" rows="2"><?= h($c['cambios']) ?></textarea>
              </label>
              <label>Puntos usados
                <textarea name="c[<?= $n ?>][puntos]" rows="2"><?= h($c['puntos']) ?></textarea>
              </label>
              <label class="check span-2"><input type="checkbox" name="c[<?= $n ?>][realizada]" value="1" <?= $c['realizada'] ? 'checked' : '' ?>><span>Consulta hecha</span></label>
            </div>
          </details>
        <?php endforeach; ?>
      </section>

      <section class="panel" id="paciente">
        <h2>Lo que recibe el paciente</h2>
        <p class="hint">Va en el mail y en el PDF, junto con el primer bloque, la frecuencia semanal (primera etapa de hasta 25 consultas) y el aviso de que no reemplaza el tratamiento médico. No van el pulso, las notas internas, la técnica ni los controles.</p>
        <div class="stack">
          <label>Resumen del diagnóstico en palabras simples
            <textarea name="p[resumen]" rows="6"><?= h($plan['patient']['resumen']) ?></textarea>
          </label>
          <label>Recomendaciones para casa (un renglón por punto, empezando con «-»)
            <textarea name="p[recomendaciones]" rows="7"><?= h($plan['patient']['recomendaciones']) ?></textarea>
          </label>
          <label class="check"><input type="checkbox" name="p[include_points]" value="1" <?= $plan['patient']['include_points'] ? 'checked' : '' ?>><span>Incluir los puntos de cada consulta en el PDF del paciente</span></label>
        </div>
      </section>

      <div class="mtc-bar">
        <button class="btn ghost" type="submit" name="action" value="save">Guardar</button>
        <button class="btn ghost" type="submit" name="action" value="preview" formtarget="_blank" <?= $plan['generated'] ? '' : 'disabled' ?>>Vista previa PDF</button>
        <button class="btn primary" type="submit" name="action" value="send" id="btn-send" <?= $plan['generated'] && $hasEmail ? '' : 'disabled' ?>
          data-email="<?= h((string) $appt['patient_email']) ?>"><?= $row && $row['status'] === 'enviado' ? 'Reenviar al paciente' : 'Enviar al paciente' ?></button>
        <span class="hint"><?= !$hasEmail ? 'Sin email válido en el turno: usá la vista previa y mandalo por WhatsApp.' : (!$plan['generated'] ? 'Generá el plan para poder enviarlo.' : 'La vista previa no guarda. «Enviar» guarda y manda el PDF a ' . h((string) $appt['patient_email']) . '.') ?></span>
      </div>
    </form>

    <?php if ($row && $mode === 'editor'): ?>
      <?php
        [$nextNo, $nextDate] = mtc_schedule_start($plan, $appt, $visits);
        $remaining = MTC_TOTAL - $nextNo + 1;
        $howMany = max(1, min($remaining, (int) ($_GET['n'] ?? 4)));
        $time = substr((string) $appt['time'], 0, 5);
      ?>
      <section class="panel" id="agendar">
        <h2>Agendar próximas sesiones semanales</h2>
        <?php if ($visits): ?>
          <p class="muted small">Ya agendadas: <?= h(implode(' · ', array_map(static fn ($n, $v) => 'consulta ' . $n . ' ' . date('d/m', strtotime((string) $v['date'])), array_keys($visits), $visits))) ?></p>
        <?php endif; ?>
        <?php if ($remaining < 1): ?>
          <p class="muted">Ya están agendadas o hechas las 25 consultas de la primera etapa.</p>
        <?php else: ?>
          <form method="get" action="plan_mtc.php" class="mtc-inline">
            <input type="hidden" name="id" value="<?= (int) $apptId ?>">
            <label>Cantidad
              <select name="n" onchange="this.form.submit()">
                <?php foreach (array_unique([1, 2, 3, 4, 6, 8, 10, 12, 16, 20, $remaining]) as $opt): ?>
                  <?php if ($opt <= $remaining): ?><option value="<?= $opt ?>" <?= $opt === $howMany ? 'selected' : '' ?>><?= $opt ?></option><?php endif; ?>
                <?php endforeach; ?>
              </select>
            </label>
            <noscript><button class="btn ghost" type="submit">Mostrar</button></noscript>
            <span class="hint">Cada 7 días, mismo horario y lugar que la consulta 1 (<?= h(turno_location_of($appt)['label']) ?>). Podés cambiar fechas y horarios.</span>
          </form>
          <form method="post" action="plan_mtc.php?id=<?= (int) $apptId ?>" id="schedule-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int) $apptId ?>">
            <input type="hidden" name="action" value="schedule">
            <div class="mtc-schedule">
              <?php for ($i = 0; $i < $howMany; $i++): ?>
                <?php $n = $nextNo + $i; $dt = $nextDate->modify('+' . ($i * MTC_WEEK_DAYS) . ' days'); ?>
                <div class="mtc-schedule-row">
                  <label class="check"><input type="checkbox" name="rows[<?= $i ?>][on]" value="1" checked><span>Consulta <?= $n ?></span></label>
                  <input type="hidden" name="rows[<?= $i ?>][n]" value="<?= $n ?>">
                  <input type="date" name="rows[<?= $i ?>][date]" value="<?= h($dt->format('Y-m-d')) ?>" aria-label="Fecha consulta <?= $n ?>">
                  <input type="time" name="rows[<?= $i ?>][time]" value="<?= h($time) ?>" min="06:00" max="22:00" step="300" aria-label="Hora consulta <?= $n ?>">
                  <span class="muted small"><?= h(weekday_es((int) $dt->format('w'))) ?></span>
                </div>
              <?php endfor; ?>
            </div>
            <div class="form-grid">
              <label>Seña por sesión (ARS, 0 = sin seña)
                <input type="number" name="deposit_amount" min="0" step="100" value="0">
              </label>
              <label>Aviso al paciente
                <select name="mail_mode">
                  <option value="none">No mandar mail</option>
                  <option value="summary" <?= $hasEmail ? '' : 'disabled' ?>>Un solo mail con todas las fechas</option>
                  <option value="each" <?= $hasEmail ? '' : 'disabled' ?>>Mail de confirmación completo por cada turno</option>
                </select>
              </label>
              <div class="span-2"><button class="btn primary" type="submit">Agendar sesiones</button></div>
            </div>
          </form>
        <?php endif; ?>
      </section>

      <?php $versions = mtc_versions((int) $row['id']); ?>
      <details class="panel mtc-panel" id="versiones">
        <summary><h2>Versiones anteriores</h2>
          <span class="muted small"><?= $versions ? count($versions) . ' guardada' . (count($versions) === 1 ? '' : 's') : 'Todavía no hay' ?></span></summary>
        <p class="hint">Cada vez que guardás un cambio, el plan anterior queda acá. Restaurar también guarda el actual antes de reemplazarlo.</p>
        <?php foreach ($versions as $v): ?>
          <?php $vp = mtc_normalize(json_decode((string) $v['data'], true) ?: []); ?>
          <article class="mtc-row">
            <div>
              <strong><?= h(date('d/m/Y H:i', strtotime((string) $v['created_at']))) ?></strong> · <span class="muted"><?= h($v['reason']) ?></span><br>
              <span class="muted small"><?= h(mtc_plan_brief($vp)) ?></span>
            </div>
            <div class="mtc-actions-inline">
              <a class="btn ghost" href="plan_mtc.php?id=<?= (int) $apptId ?>&amp;version=<?= (int) $v['id'] ?>&amp;pdf=1" target="_blank" rel="noopener">Ver PDF</a>
              <form method="post" action="plan_mtc.php?id=<?= (int) $apptId ?>" class="mtc-restore">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int) $apptId ?>">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="version" value="<?= (int) $v['id'] ?>">
                <button class="btn ghost" type="submit">Restaurar</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>

    <script>
      (function () {
        document.querySelectorAll('.mtc-restore').forEach(function (f) {
          f.addEventListener('submit', function (ev) {
            if (!confirm('¿Restaurar esta versión? El plan actual queda guardado en el historial.')) {
              ev.preventDefault();
            }
          });
        });
        var send = document.getElementById('btn-send');
        if (send) {
          send.addEventListener('click', function (ev) {
            if (!confirm('¿Mandar el plan en PDF a ' + send.dataset.email + '?')) {
              ev.preventDefault();
            }
          });
        }
        var sched = document.getElementById('schedule-form');
        if (sched) {
          sched.addEventListener('submit', function (ev) {
            var count = sched.querySelectorAll('input[type=checkbox]:checked').length;
            if (!confirm('¿Crear ' + count + ' turnos semanales para este paciente?')) {
              ev.preventDefault();
            }
          });
        }
      })();
    </script>
<?php endif; ?>
<?php endif; ?>
  </main>
</body>
</html>
