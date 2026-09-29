<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}
require_admin();
require_once __DIR__ . '/includes/pacientes.php';
require_once __DIR__ . '/includes/pac_generate.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pac_require_post('pacientes.php');
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'create') {
            $id = pac_patient_save(pac_patient_from_input($_POST));
            flash('success', 'Paciente creado.');
            redirect('paciente.php?id=' . $id);
        }
        if ($action === 'import') {
            $id = pac_import_from_appointment((int) ($_POST['appt_id'] ?? 0));
            flash('success', 'Paciente creado desde el turno. Completá sus antecedentes.');
            redirect('paciente.php?id=' . $id . '#datos');
        }
        if ($action === 'ai_save') {
            foreach (['gemini', 'openai'] as $provider) {
                $key = trim(is_string($_POST[$provider . '_key'] ?? null) ? $_POST[$provider . '_key'] : '');
                if ($key === '') {
                    continue;
                }
                $problem = pac_key_problem($provider, $key);
                if ($problem !== '') {
                    throw new RuntimeException($problem);
                }
                pac_set_setting($provider . '_api_key', $key);
            }
            $pref = (string) ($_POST['ai_provider'] ?? 'auto');
            pac_set_setting('ai_provider', in_array($pref, ['gemini', 'openai'], true) ? $pref : 'auto');
            flash('success', 'Ajustes de IA guardados.');
            redirect('pacientes.php#ia');
        }
        if ($action === 'bridge_token') {
            $_SESSION['pac_new_bridge_token'] = pac_bridge_new_token();
            flash('success', 'Token del puente creado. Copialo ahora: no se vuelve a mostrar.');
            redirect('pacientes.php#ia');
        }
        if ($action === 'ai_clear') {
            $provider = ($_POST['provider'] ?? '') === 'openai' ? 'openai' : 'gemini';
            pac_set_setting($provider . '_api_key', '');
            flash('success', 'Clave de ' . pac_ai_label($provider) . ' borrada de Pacientes.');
            redirect('pacientes.php#ia');
        }
        if ($action === 'ai_test') {
            if (!pac_ai_available()) {
                throw new RuntimeException('No hay ninguna clave de IA cargada.');
            }
            [$json, $label] = pac_ai_json('Respondé solo este JSON: {"ok": true}', 25);
            flash(!empty($json['ok']) ? 'success' : 'error', !empty($json['ok']) ? 'La IA responde bien: ' . $label . '.' : 'La IA respondió algo inesperado.');
            redirect('pacientes.php#ia');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        if ($action === 'create') {
            $_SESSION['pac_form'] = array_intersect_key($_POST, PAC_PATIENT_FIELDS + PAC_FLAGS);
            redirect('pacientes.php?nuevo=1#nuevo');
        }
        redirect('pacientes.php' . (str_starts_with($action, 'ai_') ? '#ia' : ''));
    }
    redirect('pacientes.php');
}

$q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 80) : '';
$patients = pac_patients_list($q);
$candidates = pac_import_candidates();
$form = $_SESSION['pac_form'] ?? [];
unset($_SESSION['pac_form']);
$showNew = isset($_GET['nuevo']);
$lib = pac_library_stats();
$newBridgeToken = (string) ($_SESSION['pac_new_bridge_token'] ?? '');
unset($_SESSION['pac_new_bridge_token']);
$bridgeOn = pac_bridge_alive();
$bridgeSet = pac_bridge_configured();
header('Cache-Control: no-store, private');
$keyInfo = [];
foreach (['gemini', 'openai'] as $provider) {
    $keyInfo[$provider] = pac_ai_key($provider)[1];
}
$aiOn = pac_ai_available();

pac_page_start('Pacientes', 'pacientes');
?>
    <div class="pac-head">
      <div>
        <p class="eyebrow">Admin · datos de salud confidenciales</p>
        <h1>Pacientes</h1>
      </div>
      <div class="pac-actions">
        <a class="btn primary" href="pacientes.php?nuevo=1#nuevo">Nuevo paciente</a>
        <a class="btn ghost" href="biblioteca.php">Biblioteca MTC (<?= (int) $lib['ready'] ?>)</a>
      </div>
    </div>
    <p class="hint"><?= h(PAC_DISCLAIMER) ?> Estos datos solo se ven con la contraseña del admin.</p>

    <?php if ($showNew): ?>
    <section class="panel" id="nuevo">
      <h2>Nuevo paciente</h2>
      <form method="post" class="form-grid">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <?php require __DIR__ . '/includes/pac_patient_form.php'; ?>
        <div class="span-2 pac-actions">
          <button class="btn primary" type="submit">Crear paciente</button>
          <a class="btn ghost" href="pacientes.php">Cancelar</a>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <section class="panel">
      <form method="get" class="pac-search">
        <label>Buscar por nombre, documento, email o teléfono
          <input type="search" name="q" value="<?= h($q) ?>" autocomplete="off">
        </label>
        <button class="btn ghost" type="submit">Buscar</button>
        <?php if ($q !== ''): ?><a class="btn ghost" href="pacientes.php">Ver todos</a><?php endif; ?>
      </form>
      <?php if (!$patients): ?>
        <p class="muted"><?= $q !== '' ? 'No hay pacientes que coincidan.' : 'Todavía no hay pacientes. Creá uno o importalo desde un turno.' ?></p>
      <?php else: ?>
        <div class="pac-list">
          <?php foreach ($patients as $p): ?>
            <a class="pac-row" href="paciente.php?id=<?= (int) $p['id'] ?>">
              <span>
                <strong><?= h($p['nombre']) ?></strong>
                <?php foreach (pac_flags($p) as $f): ?><span class="tag warn"><?= h(PAC_FLAGS[$f]) ?></span><?php endforeach; ?>
                <br><span class="muted small"><?= h(implode(' · ', array_filter([
                    $p['documento'] !== '' ? 'Doc. ' . $p['documento'] : '',
                    pac_age($p) !== null ? pac_age($p) . ' años' : '',
                    $p['telefono'],
                    $p['email'],
                ]))) ?></span>
              </span>
              <span class="muted small"><?= (int) $p['entries'] ?> registros · <?= (int) $p['evals'] ?> evaluaciones<br>Actualizado <?= h(date('d/m/Y', strtotime((string) $p['updated_at']))) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <details class="panel pac-panel" id="importar"<?= $candidates && !$patients ? ' open' : '' ?>>
      <summary><h2>Importar desde turnos</h2> <span class="muted small">(<?= count($candidates) ?> personas con turno que todavía no son pacientes)</span></summary>
      <p class="hint">Crea la ficha con el nombre, email, teléfono y documento (si firmó el consentimiento online). Después completás los antecedentes.</p>
      <?php if (!$candidates): ?>
        <p class="muted">No hay personas nuevas en los turnos.</p>
      <?php endif; ?>
      <?php foreach ($candidates as $c): ?>
        <form method="post" class="pac-row pac-row--form">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="action" value="import">
          <input type="hidden" name="appt_id" value="<?= (int) $c['id'] ?>">
          <span>
            <strong><?= h($c['patient_name']) ?></strong><br>
            <span class="muted small"><?= h(implode(' · ', array_filter([$c['therapy_name'], date('d/m/Y', strtotime((string) $c['date'])), $c['patient_phone'], $c['patient_email']]))) ?></span>
          </span>
          <button class="btn ghost" type="submit">Crear paciente</button>
        </form>
      <?php endforeach; ?>
    </details>

    <details class="panel pac-panel" id="ia"<?= $newBridgeToken !== '' ? ' open' : '' ?>>
      <summary><h2>Ajustes de IA</h2> <span class="tag <?= $bridgeOn ? 'ok' : 'warn' ?>"><?= $bridgeOn ? 'Cursor conectado' : ($aiOn ? 'Cursor desconectado · Gemini/ChatGPT de respaldo' : 'Cursor desconectado · modo protocolos') ?></span></summary>
      <h3 class="pac-sub">Cursor (motor principal)</h3>
      <p class="hint">
        «Generar» usa Cursor en tu Mac: un programa chico (el puente, en <code>tools/cursor_bridge</code>) le pide trabajos a este servidor, Cursor lee tu
        biblioteca extraída en la Mac y devuelve el borrador con citas. Se le manda solo el caso <strong>anonimizado</strong>
        (rango de edad, sexo y datos clínicos; nunca nombre, documento, email, teléfono ni dirección). Si la Mac está apagada o el puente no corre,
        «Generar» usa tus protocolos y la biblioteca del servidor (sin IA) y lo avisa.
      </p>
      <p>
        Estado: <span class="tag <?= $bridgeOn ? 'ok' : 'warn' ?>"><?= $bridgeOn ? 'conectado' : ($bridgeSet ? 'desconectado' : 'sin configurar') ?></span>
        <?php if (pac_bridge_seen() > 0): ?>
          <span class="muted small">· última señal: <?= h(date('d/m/Y H:i:s', pac_bridge_seen())) ?><?= pac_setting('bridge_info') !== '' ? ' · ' . h(pac_setting('bridge_info')) : '' ?></span>
        <?php endif; ?>
      </p>
      <?php if ($newBridgeToken !== ''): ?>
        <div class="alert warn">
          <p><strong>Token nuevo del puente</strong> (se muestra una sola vez). Copialo en el archivo <code>.env</code> de la carpeta Fluxus de tu Mac, en la línea
            <code>PACIENTES_BRIDGE_TOKEN=</code>, y reiniciá el puente. El token anterior dejó de funcionar.</p>
          <p><input readonly value="<?= h($newBridgeToken) ?>" onclick="this.select()" style="width:100%;font-family:monospace"></p>
        </div>
      <?php endif; ?>
      <form method="post" class="pac-actions" onsubmit="return confirm('¿Crear un token nuevo? El puente de la Mac deja de conectarse hasta que pegues el nuevo en su .env.')">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="bridge_token">
        <button class="btn ghost" type="submit"><?= $bridgeSet ? 'Cambiar el token del puente' : 'Crear el token del puente' ?></button>
      </form>

      <h3 class="pac-sub">Respaldo: Gemini o ChatGPT (opcional)</h3>
      <p class="hint">
        Solo se usan si Cursor no está conectado. Sin ninguna clave, se genera con tus protocolos y la biblioteca (sin IA).
      </p>
      <p class="hint">
        Clave gratuita de Gemini: entrá con tu cuenta de Gmail a <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">aistudio.google.com/app/apikey</a>,
        tocá «Create API key», copiala y pegala acá abajo. Queda guardada solo en el servidor y no se vuelve a mostrar.
      </p>
      <ul class="pac-keys">
        <?php foreach (['gemini' => 'Gemini (Google)', 'openai' => 'ChatGPT (OpenAI)'] as $provider => $label): ?>
          <li>
            <strong><?= h($label) ?>:</strong>
            <?= h(match ($keyInfo[$provider]) {
                'pacientes' => 'clave cargada en Pacientes',
                'config' => 'clave cargada en config.php del servidor',
                'academia' => 'usando la clave del generador de clases de Academia',
                default => 'sin clave',
            }) ?>
            <?php if ($keyInfo[$provider] === 'pacientes'): ?>
              <form method="post" class="pac-inline" onsubmit="return confirm('¿Borrar la clave de <?= h($label) ?>?')">
                <?= pac_csrf_field() ?>
                <input type="hidden" name="action" value="ai_clear">
                <input type="hidden" name="provider" value="<?= h($provider) ?>">
                <button class="btn ghost small" type="submit">Borrar</button>
              </form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" class="form-grid" autocomplete="off">
        <?= pac_csrf_field() ?>
        <input type="hidden" name="action" value="ai_save">
        <label>Clave de Gemini (dejá vacío para no cambiarla)
          <input type="password" name="gemini_key" autocomplete="new-password" spellcheck="false" placeholder="AIza…">
        </label>
        <label>Clave de ChatGPT / OpenAI (opcional)
          <input type="password" name="openai_key" autocomplete="new-password" spellcheck="false" placeholder="sk-…">
        </label>
        <label>Usar primero
          <select name="ai_provider">
            <?php $pref = pac_setting('ai_provider', 'auto'); ?>
            <option value="auto"<?= $pref === 'auto' ? ' selected' : '' ?>>Automático (Gemini si hay clave)</option>
            <option value="gemini"<?= $pref === 'gemini' ? ' selected' : '' ?>>Gemini</option>
            <option value="openai"<?= $pref === 'openai' ? ' selected' : '' ?>>ChatGPT</option>
          </select>
        </label>
        <div class="span-2 pac-actions">
          <button class="btn primary" type="submit">Guardar ajustes de IA</button>
        </div>
      </form>
      <?php if ($aiOn): ?>
        <form method="post" style="margin-top:.6rem">
          <?= pac_csrf_field() ?>
          <input type="hidden" name="action" value="ai_test">
          <button class="btn ghost" type="submit">Probar conexión con la IA</button>
        </form>
      <?php endif; ?>
    </details>
<?php
pac_page_end();
