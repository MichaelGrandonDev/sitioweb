<?php

declare(strict_types=1);

/**
 * Pacientes · motor Cursor (puente local en la Mac), cola de trabajos, vistas previas sin guardar,
 * traspaso al Plan MTC e importación de la biblioteca extraída (solo texto).
 * El puente nunca recibe nombre, documento, email, teléfono ni dirección.
 */

const PAC_BRIDGE_ALIVE = 60;
const PAC_JOB_WAIT = 240;
const PAC_JOB_KEEP = 86400;
const PAC_PREVIEW_MAX = 6;
const PAC_BRIDGE_RATE = 240;
const PAC_BRIDGE_FAILS = 10;
const PAC_IMPORT_PAGES = 200;
const PAC_CURSOR_TEXT = 6000;

function pac_cursor_schema(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_ai_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        kind TEXT NOT NULL DEFAULT 'diagnostico',
        status TEXT NOT NULL DEFAULT 'pending',
        claim_token TEXT NOT NULL DEFAULT '',
        payload TEXT NOT NULL DEFAULT '{}',
        result TEXT NOT NULL DEFAULT '',
        error TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        claimed_at INTEGER DEFAULT NULL,
        finished_at INTEGER DEFAULT NULL
      )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pac_ai_jobs_status ON pac_ai_jobs(status, created_at)');
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_mtc_handoffs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_id INTEGER NOT NULL,
        eval_id INTEGER NOT NULL,
        appointment_id INTEGER NOT NULL,
        plan_id INTEGER NOT NULL DEFAULT 0,
        before_data TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec('CREATE TABLE IF NOT EXISTS pac_bridge_rl (ip_hash TEXT PRIMARY KEY, window INTEGER NOT NULL, hits INTEGER NOT NULL DEFAULT 0, fails INTEGER NOT NULL DEFAULT 0, fail_window INTEGER NOT NULL DEFAULT 0)');
    if (!in_array('photo_id', array_column($pdo->query('PRAGMA table_info(pac_ai_jobs)')->fetchAll(), 'name'), true)) {
        $pdo->exec('ALTER TABLE pac_ai_jobs ADD COLUMN photo_id INTEGER NOT NULL DEFAULT 0');
    }
    $cols = array_column($pdo->query('PRAGMA table_info(pac_docs)')->fetchAll(), 'name');
    if (!in_array('sha256', $cols, true)) {
        $pdo->exec("ALTER TABLE pac_docs ADD COLUMN sha256 TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('source', $cols, true)) {
        $pdo->exec("ALTER TABLE pac_docs ADD COLUMN source TEXT NOT NULL DEFAULT 'subida'");
    }
}

/* ---------- Puente: token, límites y señal de vida ---------- */

function pac_bridge_token_hash(): string
{
    global $config;
    $fromConfig = trim((string) ($config['pacientes_bridge_token'] ?? ''));
    if (strlen($fromConfig) >= 32) {
        return hash('sha256', $fromConfig);
    }
    return pac_setting('bridge_token_hash');
}

function pac_bridge_configured(): bool
{
    return pac_bridge_token_hash() !== '';
}

function pac_bridge_auth(string $presented): bool
{
    $stored = pac_bridge_token_hash();
    if ($stored === '' || strlen($presented) < 32 || strlen($presented) > 256) {
        return false;
    }
    return hash_equals($stored, hash('sha256', $presented));
}

/** Nuevo token del puente: se guarda solo su hash y se muestra una única vez. */
function pac_bridge_new_token(): string
{
    $token = bin2hex(random_bytes(32));
    pac_set_setting('bridge_token_hash', hash('sha256', $token));
    return $token;
}

/** Límite por IP: pedidos por minuto y claves erróneas por 15 minutos. */
function pac_bridge_rate(string $ip, bool $failed = false): bool
{
    $pdo = db();
    $key = hash('sha256', 'pac-bridge|' . $ip);
    $now = time();
    $stmt = $pdo->prepare('SELECT * FROM pac_bridge_rl WHERE ip_hash = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch() ?: ['window' => $now, 'hits' => 0, 'fails' => 0, 'fail_window' => $now];
    $window = (int) $row['window'];
    $hits = (int) $row['hits'];
    if ($now - $window >= 60) {
        $window = $now;
        $hits = 0;
    }
    $failWindow = (int) $row['fail_window'];
    $fails = (int) $row['fails'];
    if ($now - $failWindow >= 900) {
        $failWindow = $now;
        $fails = 0;
    }
    $hits++;
    if ($failed) {
        $fails++;
    }
    $pdo->prepare('INSERT OR REPLACE INTO pac_bridge_rl (ip_hash, window, hits, fails, fail_window) VALUES (?, ?, ?, ?, ?)')
        ->execute([$key, $window, $hits, $fails, $failWindow]);
    if (random_int(1, 50) === 1) {
        $pdo->prepare('DELETE FROM pac_bridge_rl WHERE window < ? AND fail_window < ?')->execute([$now - 3600, $now - 3600]);
    }
    return $hits <= PAC_BRIDGE_RATE && $fails <= PAC_BRIDGE_FAILS;
}

function pac_bridge_blocked(string $ip): bool
{
    $stmt = db()->prepare('SELECT fails, fail_window FROM pac_bridge_rl WHERE ip_hash = ?');
    $stmt->execute([hash('sha256', 'pac-bridge|' . $ip)]);
    $row = $stmt->fetch();
    return $row && (int) $row['fails'] >= PAC_BRIDGE_FAILS && time() - (int) $row['fail_window'] < 900;
}

function pac_bridge_heartbeat(string $info): void
{
    pac_set_setting('bridge_seen', (string) time());
    $info = preg_replace('/[^\w .:\/()+-]/u', '', $info) ?? '';
    pac_set_setting('bridge_info', mb_substr($info, 0, 80));
}

function pac_bridge_seen(): int
{
    return (int) pac_setting('bridge_seen', '0');
}

function pac_bridge_alive(): bool
{
    return pac_bridge_configured() && time() - pac_bridge_seen() <= PAC_BRIDGE_ALIVE;
}

/* ---------- Cola de trabajos ---------- */

function pac_jobs_expire(): void
{
    $now = time();
    $pdo = db();
    $pdo->prepare("UPDATE pac_ai_jobs SET status = 'expired', payload = '{}', photo_id = 0, finished_at = ? WHERE status = 'pending' AND created_at < ?")
        ->execute([$now, $now - PAC_JOB_WAIT]);
    $pdo->prepare("UPDATE pac_ai_jobs SET status = 'expired', payload = '{}', photo_id = 0, finished_at = ? WHERE status = 'claimed' AND claimed_at < ?")
        ->execute([$now, $now - PAC_JOB_WAIT - 60]);
    $pdo->prepare('DELETE FROM pac_ai_jobs WHERE created_at < ?')->execute([$now - PAC_JOB_KEEP]);
}

function pac_job_create(int $patientId, array $payload, int $photoId = 0): int
{
    pac_jobs_expire();
    $payload['foto'] = $photoId > 0;
    db()->prepare("INSERT INTO pac_ai_jobs (patient_id, kind, status, payload, created_at, photo_id) VALUES (?, 'diagnostico', 'pending', ?, ?, ?)")
        ->execute([$patientId, json_encode($payload, JSON_UNESCAPED_UNICODE), time(), $photoId]);
    return (int) db()->lastInsertId();
}

/** Foto de lengua del trabajo tomado (solo con su claim, mientras está en curso): [mime, base64] o null. */
function pac_job_photo(int $id, string $claim): ?array
{
    $job = pac_job($id);
    if (!$job || $job['status'] !== 'claimed' || $claim === '' || !hash_equals((string) $job['claim_token'], $claim) || (int) $job['photo_id'] <= 0) {
        return null;
    }
    $f = pac_tongue_photo((int) $job['patient_id'], (int) $job['photo_id']);
    $path = $f && !str_contains((string) $f['stored'], '..') ? pac_storage_path((string) $f['stored']) : '';
    if ($path === '' || !is_file($path) || filesize($path) > PAC_PHOTO_CURSOR_MAX) {
        return null;
    }
    return ['mime' => (string) $f['mime'], 'data' => base64_encode((string) file_get_contents($path))];
}

function pac_job(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_ai_jobs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Toma el trabajo pendiente más viejo (atómico). Devuelve solo lo que necesita el puente. */
function pac_job_claim(): ?array
{
    pac_jobs_expire();
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $row = $pdo->query("SELECT id, kind, payload FROM pac_ai_jobs WHERE status = 'pending' ORDER BY created_at, id LIMIT 1")->fetch();
        if (!$row) {
            $pdo->exec('COMMIT');
            return null;
        }
        $claim = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE pac_ai_jobs SET status = 'claimed', claim_token = ?, claimed_at = ? WHERE id = ? AND status = 'pending'")
            ->execute([$claim, time(), (int) $row['id']]);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return ['id' => (int) $row['id'], 'claim' => $claim, 'kind' => (string) $row['kind'], 'payload' => json_decode((string) $row['payload'], true) ?: []];
}

/** Resultado del puente. Solo vale para el trabajo tomado con ese claim. */
function pac_job_finish(int $id, string $claim, ?array $result, string $error): bool
{
    $job = pac_job($id);
    if (!$job || $job['status'] !== 'claimed' || $claim === '' || !hash_equals((string) $job['claim_token'], $claim)) {
        return false;
    }
    if ($result !== null) {
        $problem = pac_cursor_result_problem($result);
        if ($problem !== '') {
            $result = null;
            $error = 'Respuesta inválida: ' . $problem;
        }
    }
    db()->prepare("UPDATE pac_ai_jobs SET status = ?, result = ?, error = ?, payload = '{}', claim_token = '', photo_id = 0, finished_at = ? WHERE id = ?")
        ->execute([
            $result !== null ? 'done' : 'error',
            $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE) : '',
            mb_substr(preg_replace('/[^\p{L}\p{N} .,:;()\/_-]/u', '', $error) ?? '', 0, 200),
            time(),
            $id,
        ]);
    return true;
}

/* ---------- Caso anonimizado para Cursor y conversión de la respuesta ---------- */

function pac_cursor_payload(array $p, array $gen): array
{
    $case = $gen['case'];
    $protocol = static fn (array $m): array => [
        'nombre' => (string) $m['protocol']['nombre'],
        'lengua_esperada' => (string) $m['protocol']['lengua'],
        'coincidencias_lengua' => array_values($m['hits']['lengua']),
        'coincidencias_paciente' => array_values($m['hits']['signos']),
        'principio' => (string) $m['protocol']['principio'],
        'meridianos' => (string) $m['protocol']['meridianos'],
        'puntos_para_moxar' => (string) $m['protocol']['puntos'],
        'moxibustion' => (string) ($m['protocol']['moxibustion'] ?? ''),
        'tuina' => (string) ($m['protocol']['tuina'] ?? ''),
        'chikung' => (string) ($m['protocol']['chikung'] ?? ''),
        'ventosas' => (string) ($m['protocol']['ventosas'] ?? ''),
        'auriculoterapia' => (string) ($m['protocol']['auriculoterapia'] ?? ''),
        'fuente' => (string) $m['protocol']['fuente'],
    ];
    $passages = [];
    foreach ($gen['sources'] as $s) {
        $passages[] = ['id' => $s['id'], 'cita' => $s['cite'], 'para' => pac_source_label($s['for']), 'texto' => pac_anonymize((string) $s['excerpt'], $p)];
    }
    return [
        'version' => 2,
        'caso' => pac_trim(pac_anonymize(pac_case_text($case), $p), PAC_CURSOR_TEXT),
        'glosodiagnosis' => pac_trim(pac_anonymize($case['lengua'], $p), 2000),
        'pulso_cargado' => $case['pulso'] !== '',
        'edad' => $case['edad'],
        'sexo' => $case['sexo'],
        'contraindicaciones' => array_values($case['flags']),
        'evitar_puntos' => (int) $p['embarazo'] === 1 ? PAC_PREGNANCY_AVOID : [],
        'sin_electro' => (int) $p['marcapasos'] === 1,
        'sin_ventosas_ni_sangria' => (int) $p['anticoagulantes'] === 1,
        'protocolos_del_terapeuta' => array_map($protocol, $gen['matches']),
        'protocolos_considerados' => array_map($protocol, $gen['differentials'] ?? []),
        'otros_protocolos' => array_values(array_map(static fn ($pr) => (string) $pr['nombre'], pac_protocols(true))),
        'borrador_por_reglas' => [
            'diagnostico_mtc' => (string) $gen['data']['patrones'],
            'puntos_para_moxar' => pac_trim((string) $gen['data']['puntos'], 1500),
        ],
        'pasajes_del_servidor' => $passages,
        'reglas' => pac_result_rules($p),
        'esquema' => PAC_RESULT_SCHEMA,
        'sesiones_total' => PAC_TOTAL,
        'bloque_inicial' => PAC_BLOCK,
    ];
}

function pac_cursor_str(mixed $v, int $max = 3000): string
{
    if (is_array($v)) {
        $v = implode(', ', array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v)));
    }
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
}

function pac_cursor_list(mixed $v, int $max = 40): array
{
    return is_array($v) ? array_slice(array_values($v), 0, $max) : [];
}

/** '' si la respuesta tiene la forma esperada; si no, el motivo (sin contenido clínico). */
function pac_cursor_result_problem(array $r): string
{
    foreach (['resumen', 'principio'] as $k) {
        if (!is_string($r[$k] ?? null) || trim($r[$k]) === '') {
            return 'falta ' . $k;
        }
    }
    $dx = $r['diagnostico_mtc'] ?? null;
    if (!is_array($dx) || pac_cursor_str($dx['texto'] ?? '') === '' || !is_array($dx['patrones'] ?? null) || !$dx['patrones']) {
        return 'falta diagnostico_mtc (texto y patrones)';
    }
    foreach ($dx['patrones'] as $pat) {
        if (!is_array($pat) || pac_cursor_str($pat['nombre'] ?? '') === '') {
            return 'patrón sin nombre';
        }
    }
    if (!is_array($r['tecnicas'] ?? null)) {
        return 'faltan las técnicas';
    }
    foreach (['tuina', 'chikung', 'moxibustion', 'ventosas', 'auriculoterapia'] as $t) {
        if (!array_key_exists($t, $r['tecnicas'])) {
            return 'falta la técnica ' . $t;
        }
    }
    foreach (['sesiones', 'citas'] as $k) {
        if (!is_array($r[$k] ?? null)) {
            return 'falta la lista ' . $k;
        }
    }
    if (strlen((string) json_encode($r)) > 200000) {
        return 'respuesta demasiado larga';
    }
    return '';
}

function pac_cursor_ref(array $item): string
{
    $refs = pac_cursor_str($item['citas'] ?? $item['cita'] ?? $item['fuente'] ?? '', 200);
    if ($refs === '' || preg_match('/sugerencia general/iu', $refs)) {
        return ' (sugerencia general)';
    }
    return ' [' . $refs . ']';
}

/** Objeto de técnica → líneas «Etiqueta: valor» (+ cita). Acepta también texto suelto. */
function pac_cursor_technique(mixed $t, array $labels): string
{
    if (is_string($t)) {
        return trim($t);
    }
    if (!is_array($t)) {
        return '';
    }
    $lines = [];
    foreach ($labels as $k => $label) {
        $v = pac_cursor_str($t[$k] ?? '', 800);
        if ($v !== '') {
            $lines[] = $label . ': ' . $v;
        }
    }
    if (!$lines) {
        return '';
    }
    $lines[count($lines) - 1] .= pac_cursor_ref($t);
    return implode("\n", $lines);
}

/** Respuesta de Cursor (o de la IA de respaldo con el mismo esquema) → campos de la evaluación. */
function pac_cursor_to_data(array $r, array $rules): array
{
    $data = $rules;
    $data['resumen'] = pac_cursor_str($r['resumen'], PAC_TEXT_MAX);
    $dx = $r['diagnostico_mtc'];
    $names = [];
    $diag = [];
    $line = pac_cursor_str($dx['texto'] ?? '', 800);
    if ($line !== '') {
        $diag[] = 'Diagnóstico desde la MTC: ' . $line;
    }
    if (($g = pac_cursor_str($r['glosodiagnosis'] ?? '', 3000)) !== '') {
        $diag[] = '';
        $diag[] = "Glosodiagnosis:\n" . $g;
    }
    foreach (pac_cursor_list($dx['patrones'], 8) as $pat) {
        $name = pac_cursor_str($pat['nombre'] ?? '', 160);
        $names[] = $name;
        $diag[] = '';
        $diag[] = $name . ':' . pac_cursor_ref($pat);
        foreach (['justificacion_lengua' => 'Lengua', 'justificacion_paciente' => 'Paciente', 'pulso' => 'Pulso (opcional)', 'justificacion' => 'Por qué'] as $k => $label) {
            if (($v = pac_cursor_str($pat[$k] ?? '', 1500)) !== '') {
                $diag[] = '- ' . $label . ': ' . $v;
            }
        }
    }
    $data['diagnostico'] = implode("\n", $diag);
    $data['patrones'] = $line !== '' ? $line : pac_diagnosis_line($names);
    $dif = [];
    foreach (pac_cursor_list($r['diferenciales'] ?? [], 8) as $d) {
        if (is_array($d) && ($n = pac_cursor_str($d['nombre'] ?? '', 160)) !== '') {
            $dif[] = '- ' . $n . ': ' . pac_cursor_str($d['por_que_no'] ?? $d['motivo'] ?? '', 800) . pac_cursor_ref($d);
        }
    }
    if ($dif) {
        $data['diferencial'] = implode("\n", $dif);
    }
    $data['principio'] = pac_cursor_str($r['principio'], 2000);
    if (($m = pac_cursor_str($r['meridianos'] ?? '', 1500)) !== '') {
        $data['meridianos'] = $m;
    }
    $t = $r['tecnicas'];
    $tuina = pac_cursor_technique($t['tuina'] ?? null, ['maniobras' => 'Maniobras', 'zonas' => 'Zonas y meridianos', 'acupresion' => 'Acupresión', 'duracion' => 'Duración']);
    $chikung = pac_cursor_technique($t['chikung'] ?? null, ['ba_duan_jin' => 'Ba Duan Jin', 'respiracion' => 'Respiración', 'liu_zi_jue' => 'Liu Zi Jue', 'en_sesion' => 'En sesión', 'en_casa' => 'En casa']);
    $ventosas = pac_cursor_technique($t['ventosas'] ?? null, ['zonas' => 'Zonas', 'tipo' => 'Tipo', 'tiempo' => 'Tiempo', 'contraindicaciones' => 'Contraindicaciones']);
    $auriculo = pac_cursor_technique($t['auriculoterapia'] ?? null, ['puntos' => 'Puntos', 'material' => 'Material', 'presion_en_casa' => 'En casa', 'oreja' => 'Oreja']);
    foreach (['tuina' => $tuina, 'chikung' => $chikung, 'ventosas' => $ventosas, 'auriculoterapia' => $auriculo] as $k => $v) {
        if ($v !== '') {
            $data[$k] = $v;
        }
    }
    $mox = $t['moxibustion'] ?? null;
    $moxLines = [];
    if (is_array($mox)) {
        foreach (pac_cursor_list($mox['puntos'] ?? [], 20) as $pt) {
            if (is_array($pt) && ($name = pac_cursor_str($pt['punto'] ?? '', 80)) !== '') {
                $row = [
                    'punto' => $name,
                    'metodo' => pac_cursor_str($pt['metodo'] ?? '', 120),
                    'tiempo' => pac_cursor_str($pt['tiempo'] ?? '', 60),
                    'precaucion' => pac_cursor_str($pt['precaucion'] ?? '', 300),
                    'alternativa' => pac_cursor_str($pt['alternativa'] ?? '', 300),
                ];
                if ($row['metodo'] === '' && $row['alternativa'] === '') {
                    $row['alternativa'] = 'sin método indicado: revisar';
                }
                $moxLines[] = pac_moxa_line($row);
            }
        }
        if (($n = pac_cursor_str($mox['notas'] ?? '', 1000)) !== '') {
            $moxLines[] = $n . pac_cursor_ref($mox);
        }
    } elseif (is_string($mox) && trim($mox) !== '') {
        $moxLines[] = trim($mox);
    }
    if ($moxLines) {
        $data['puntos'] = implode("\n", $moxLines);
    }
    $conflicts = array_filter(array_map(static fn ($x) => pac_cursor_str($x, 500), pac_cursor_list($r['conflictos'] ?? [], 8)));
    if ($conflicts) {
        $data['tecnicas'] = trim($data['tecnicas'] . "\n" . implode("\n", array_map(static fn ($c) => '- Conflicto: ' . $c, $conflicts)));
    }
    $sessions = [];
    foreach (pac_cursor_list($r['sesiones'], PAC_TOTAL) as $s) {
        if (!is_array($s)) {
            continue;
        }
        $parts = [pac_cursor_str($s['objetivo'] ?? '', 400)];
        foreach (['tuina' => 'Tuina', 'chikung' => 'Chi kung', 'moxibustion' => 'Moxa', 'ventosas' => 'Ventosas', 'auriculoterapia' => 'Aurículo'] as $k => $label) {
            if (($v = pac_cursor_str($s[$k] ?? '', 300)) !== '') {
                $parts[] = $label . ': ' . str_replace('·', ',', $v);
            }
        }
        $n = pac_cursor_str($s['sesion'] ?? $s['n'] ?? '', 12);
        $sessions[] = (str_contains($n, '-') ? 'Sesiones ' : 'Sesión ') . $n . ': ' . implode(' · ', array_filter($parts));
    }
    if ($sessions) {
        $data['sesiones'] = implode("\n", $sessions);
    }
    if (($tx = pac_cursor_str($r['texto_paciente'] ?? '', PAC_TEXT_MAX)) !== '') {
        $data['paciente_texto'] = $tx;
    }
    $cites = [];
    foreach (pac_cursor_citas($r) as $c) {
        $cites[] = '[' . $c['id'] . '] ' . $c['cite'] . ($c['excerpt'] !== '' ? ' — "' . $c['excerpt'] . '"' : '') . ($c['link'] ? ' → ' . $c['link'] : '');
    }
    $general = array_map(static fn ($g) => '- ' . pac_cursor_str($g, 400), pac_cursor_list($r['sugerencias_generales'] ?? [], 12));
    $data['fuentes'] = trim(
        ($cites ? "Biblioteca MTC (citada por la IA):\n" . implode("\n", $cites) : 'La IA no citó pasajes de la biblioteca.')
        . ($general ? "\n\nSugerencias generales (no salen de tus fuentes):\n" . implode("\n", $general) : '')
        . (trim((string) $rules['fuentes']) !== '' ? "\n\nProtocolos y pasajes del servidor:\n" . $rules['fuentes'] : '')
    );
    $prec = array_map(static fn ($x) => pac_cursor_str($x, 400), pac_cursor_list($r['precauciones'] ?? [], 12));
    $data['avisos'] = implode("\n", array_filter($prec));
    return $data;
}

/** Citas de la respuesta, con enlace al lector PDF en esa página si el libro está cargado. */
function pac_cursor_citas(array $r): array
{
    $out = [];
    foreach (pac_cursor_list($r['citas'] ?? [], 40) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $page = pac_cursor_str($c['pagina'] ?? '', 20);
        $doc = pac_cursor_str($c['documento'] ?? $c['doc'] ?? '', 160);
        $out[] = [
            'id' => pac_cursor_str($c['id'] ?? '', 12),
            'cite' => '«' . $doc . '»' . ($page !== '' ? ', p. ' . $page : ''),
            'excerpt' => pac_cursor_str($c['cita'] ?? '', 300),
            'link' => pac_reader_link(pac_cursor_str($c['sha256'] ?? '', 64), pac_cursor_str($c['source_file'] ?? '', 200), (int) $page),
        ];
    }
    return $out;
}

/* ---------- Vistas previas (en la sesión: nada se guarda hasta «Guardar») ---------- */

function pac_preview_put(array $prev): string
{
    $all = is_array($_SESSION['pac_prev'] ?? null) ? $_SESSION['pac_prev'] : [];
    uasort($all, static fn ($a, $b) => ($a['created'] ?? 0) <=> ($b['created'] ?? 0));
    while (count($all) >= PAC_PREVIEW_MAX) {
        array_shift($all);
    }
    $token = bin2hex(random_bytes(8));
    $prev['created'] = time();
    $all[$token] = $prev;
    $_SESSION['pac_prev'] = $all;
    return $token;
}

function pac_preview_get(string $token, int $patientId): ?array
{
    $prev = $_SESSION['pac_prev'][$token] ?? null;
    return is_array($prev) && (int) ($prev['pid'] ?? 0) === $patientId ? $prev : null;
}

function pac_preview_set(string $token, array $prev): void
{
    $_SESSION['pac_prev'][$token] = $prev;
}

function pac_preview_drop(string $token): void
{
    unset($_SESSION['pac_prev'][$token]);
}

/** Si la vista previa espera a Cursor, mira el trabajo: listo → usa la respuesta; error o demora → reglas. */
function pac_preview_resolve(string $token, array $prev, array $p, bool $giveUp = false): array
{
    if (($prev['state'] ?? '') !== 'waiting') {
        return $prev;
    }
    pac_jobs_expire();
    $job = pac_job((int) $prev['job']);
    $late = time() - (int) $prev['created'] > PAC_JOB_WAIT;
    $fallback = static function (string $why) use (&$prev): void {
        $prev['state'] = 'ready';
        $prev['info'][] = 'Cursor no disponible — generado con tus protocolos' . ($why !== '' ? ' (' . $why . ')' : '') . '.';
    };
    if ($job && $job['status'] === 'done') {
        $r = json_decode((string) $job['result'], true);
        if (is_array($r) && pac_cursor_result_problem($r) === '') {
            $data = pac_cursor_to_data($r, $prev['data']);
            $filters = [];
            $data = pac_apply_safety($p, $data, $filters);
            foreach (['interrogatorio', 'lengua', 'pulso'] as $k) {
                $data[$k] = $prev['data'][$k];
            }
            $prev['data'] = $data;
            $prev['mode'] = 'cursor';
            $prev['state'] = 'ready';
            $prev['applied']['filters'] = array_values(array_unique(array_merge($prev['applied']['filters'], $filters)));
            $prev['applied']['citas'] = pac_cursor_citas($r);
            $prev['info'][] = 'Generado con Cursor en tu Mac, leyendo tu biblioteca local (caso enviado sin nombre, documento ni contacto).';
        } else {
            $fallback('respuesta inválida');
        }
    } elseif (!$job || in_array($job['status'], ['error', 'expired'], true)) {
        $fallback($job && $job['status'] === 'error' ? 'no pudo responder' : 'no respondió a tiempo');
    } elseif ($late || $giveUp) {
        db()->prepare("UPDATE pac_ai_jobs SET status = 'expired', payload = '{}', photo_id = 0, finished_at = ? WHERE id = ? AND status IN ('pending', 'claimed')")
            ->execute([time(), (int) $prev['job']]);
        $fallback($giveUp ? 'elegiste no esperar' : 'tardó más de ' . intdiv(PAC_JOB_WAIT, 60) . ' minutos');
    }
    pac_preview_set($token, $prev);
    return $prev;
}

/** Campos que cambiarían respecto de lo guardado. */
function pac_eval_changes(array $old, array $new): array
{
    $out = [];
    foreach (PAC_EVAL_INPUTS + PAC_EVAL_OUTPUTS as $k => $label) {
        $a = trim(str_replace("\r\n", "\n", (string) ($old[$k] ?? '')));
        $b = trim(str_replace("\r\n", "\n", (string) ($new[$k] ?? '')));
        if ($a !== $b) {
            $out[] = ['field' => $k, 'label' => $label, 'before' => $a, 'after' => $b];
        }
    }
    return $out;
}

/* ---------- Traspaso al Plan MTC (plan_mtc.php) ---------- */

function pac_mtc_ready(): bool
{
    return function_exists('mtc_blank') && function_exists('mtc_normalize') && function_exists('mtc_save') && function_exists('mtc_plan_for_appointment') && function_exists('mtc_apply_generated');
}

/** Patrones del Plan MTC que aparecen en la evaluación. */
function pac_mtc_pattern_keys(string $patrones): array
{
    $text = pac_fold($patrones);
    $synonyms = ['bazo' => ['bazo'], 'higado' => ['higado'], 'rinon' => ['rinon'], 'sangre' => ['estasis de sangre', 'estasis sangre', 'sangre'], 'humedad' => ['humedad', 'flema']];
    $out = [];
    foreach (mtc_patterns() as $key => $pat) {
        $words = $synonyms[$key] ?? [pac_fold((string) $pat['label'])];
        $words[] = pac_fold((string) $pat['label']);
        foreach ($words as $w) {
            if ($w !== '' && str_contains($text, $w)) {
                $out[] = $key;
                break;
            }
        }
    }
    return $out;
}

/** Plan MTC propuesto a partir de la evaluación (sin guardar). */
function pac_mtc_proposal(array $p, array $eval, array $history, ?array $existing): array
{
    $plan = $existing ? $existing['plan'] : mtc_normalize([]);
    $max = defined('MTC_TEXT_MAX') ? MTC_TEXT_MAX : 4000;
    $antecedentes = implode("\n", array_filter([
        trim((string) $p['enfermedades']) !== '' ? 'Enfermedades: ' . $p['enfermedades'] : '',
        trim((string) $p['cirugias']) !== '' ? 'Cirugías: ' . $p['cirugias'] : '',
        trim((string) $p['medicacion']) !== '' ? 'Medicación: ' . $p['medicacion'] : '',
        trim((string) $p['alergias']) !== '' ? 'Alergias: ' . $p['alergias'] : '',
    ]));
    $notas = implode("\n\n", array_filter([
        'Desde Pacientes (evaluación del ' . date('d/m/Y', strtotime((string) $eval['fecha'])) . ', versión ' . (int) $eval['version'] . '):',
        trim((string) $eval['patrones']) !== '' ? 'Diagnóstico desde la MTC: ' . $eval['patrones'] : '',
        trim((string) $eval['diagnostico']) !== '' ? "Justificación:\n" . $eval['diagnostico'] : '',
        trim((string) ($eval['diferencial'] ?? '')) !== '' ? "Diferencial:\n" . $eval['diferencial'] : '',
        trim((string) $eval['principio']) !== '' ? 'Principio: ' . $eval['principio'] : '',
        trim((string) $eval['meridianos']) !== '' ? 'Meridianos: ' . $eval['meridianos'] : '',
        trim((string) ($eval['tuina'] ?? '')) !== '' ? "Tuina:\n" . $eval['tuina'] : '',
        trim((string) ($eval['chikung'] ?? '')) !== '' ? "Chi kung:\n" . $eval['chikung'] : '',
        trim((string) $eval['puntos']) !== '' ? "Moxibustión (puntos para moxar):\n" . $eval['puntos'] : '',
        trim((string) ($eval['ventosas'] ?? '')) !== '' ? "Ventosas:\n" . $eval['ventosas'] : '',
        trim((string) ($eval['auriculoterapia'] ?? '')) !== '' ? "Auriculoterapia:\n" . $eval['auriculoterapia'] : '',
        trim((string) $eval['fuentes']) !== '' ? "Fuentes:\n" . $eval['fuentes'] : '',
    ]));
    $in = pac_eval_entrada($eval);
    $set = [
        'motivo' => trim((string) ($in['motivo'] ?? '')) !== '' ? (string) $in['motivo'] : (string) ($history[0]['motivo'] ?? ''),
        'antecedentes' => $antecedentes,
        'interrog_notas' => (string) $eval['interrogatorio'],
        'lengua_notas' => (string) $eval['lengua'],
        'pulso' => (string) ($eval['pulso'] ?? ''),
        'notas' => $notas,
    ];
    foreach ($set as $k => $v) {
        if (array_key_exists($k, $plan['diag']) && trim($v) !== '') {
            $plan['diag'][$k] = mb_substr(trim($v), 0, $max);
        }
    }
    // Lengua e interrogatorio estructurados: solo valores que existen en las opciones actuales del Plan MTC.
    $values = pac_tongue_from($in['lengua'] ?? []) + pac_interrog_from($in['interrog'] ?? []);
    foreach (defined('MTC_OPTIONS') ? MTC_OPTIONS : [] as $k => $opts) {
        $v = $values[$k] ?? '';
        if (is_string($v) && $v !== '' && in_array($v, $opts, true) && array_key_exists($k, $plan['diag'])) {
            $plan['diag'][$k] = $v;
        }
    }
    foreach (defined('MTC_MULTI') ? MTC_MULTI : [] as $k => $opts) {
        $v = array_values(array_intersect($opts, (array) ($values[$k] ?? [])));
        if ($v && array_key_exists($k, $plan['diag'])) {
            $plan['diag'][$k] = $v;
        }
    }
    if (array_key_exists('contra', $plan['diag'])) {
        $plan['diag']['contra'] = array_values(array_unique(array_merge($plan['diag']['contra'], pac_flags($p))));
    }
    $keys = pac_mtc_pattern_keys((string) $eval['patrones'] . "\n" . strtok((string) $eval['diagnostico'], "\n"));
    if ($keys && array_key_exists('patrones', $plan['diag'])) {
        $plan['diag']['patrones'] = $keys;
        $plan = mtc_apply_generated($plan);
    }
    return $plan;
}

/** Diferencias legibles entre el plan guardado y el propuesto. */
function pac_mtc_changes(?array $existing, array $proposed): array
{
    $old = $existing ? $existing['plan'] : mtc_normalize([]);
    $labels = [
        'motivo' => 'Motivo de consulta', 'antecedentes' => 'Antecedentes', 'interrog_notas' => 'Interrogatorio',
        'lengua_notas' => 'Lengua', 'pulso' => 'Pulso', 'notas' => 'Notas del diagnóstico', 'patrones' => 'Patrones', 'contra' => 'Contraindicaciones',
    ];
    foreach (array_merge(array_keys(defined('MTC_OPTIONS') ? MTC_OPTIONS : []), array_keys(defined('MTC_MULTI') ? MTC_MULTI : [])) as $k) {
        $labels[$k] ??= isset(PAC_TONGUE[$k]) ? 'Lengua · ' . PAC_TONGUE_GROUPS[PAC_TONGUE[$k][3]] . ': ' . mb_strtolower(PAC_TONGUE[$k][0])
            : (PAC_INTERROG[$k] ?? ucfirst(str_replace('_', ' ', $k)));
    }
    $patterns = function_exists('mtc_patterns') ? mtc_patterns() : [];
    $fmt = static function (mixed $v) use ($patterns): string {
        if (is_array($v)) {
            return implode(', ', array_map(static fn ($x) => (string) ($patterns[$x]['label'] ?? (PAC_FLAGS[$x] ?? $x)), $v));
        }
        return trim((string) $v);
    };
    $out = [];
    foreach ($labels as $k => $label) {
        $a = $fmt($old['diag'][$k] ?? '');
        $b = $fmt($proposed['diag'][$k] ?? '');
        if ($a !== $b) {
            $out[] = ['label' => $label, 'before' => $a, 'after' => $b];
        }
    }
    foreach (['resumen' => 'Resumen para el paciente', 'recomendaciones' => 'Recomendaciones para el paciente'] as $k => $label) {
        $a = trim((string) ($old['patient'][$k] ?? ''));
        $b = trim((string) ($proposed['patient'][$k] ?? ''));
        if ($a !== $b) {
            $out[] = ['label' => $label, 'before' => $a, 'after' => $b];
        }
    }
    foreach ($proposed['sessions'] as $n => $s) {
        $before = $old['sessions'][$n] ?? [];
        $a = trim(implode(' — ', array_filter([(string) ($before['objetivo'] ?? ''), (string) ($before['puntos'] ?? ''), (string) ($before['tecnica'] ?? '')])));
        $b = trim(implode(' — ', array_filter([(string) ($s['objetivo'] ?? ''), (string) ($s['puntos'] ?? ''), (string) ($s['tecnica'] ?? '')])));
        if ($a !== $b) {
            $out[] = ['label' => 'Consulta ' . $n, 'before' => $a, 'after' => $b];
        }
    }
    return $out;
}

/** Hay algo escrito en el plan que se perdería. */
function pac_mtc_overwrites(array $changes): bool
{
    foreach ($changes as $c) {
        if ($c['before'] !== '') {
            return true;
        }
    }
    return false;
}

function pac_mtc_handoff(int $patientId, int $evalId, int $apptId, array $proposed, ?array $existing): int
{
    db()->prepare('INSERT INTO pac_mtc_handoffs (patient_id, eval_id, appointment_id, plan_id, before_data) VALUES (?, ?, ?, ?, ?)')
        ->execute([$patientId, $evalId, $apptId, $existing ? (int) $existing['id'] : 0, $existing ? (string) $existing['data'] : null]);
    $handoffId = (int) db()->lastInsertId();
    $planId = mtc_save($apptId, $proposed, $existing, 'Antes del traspaso desde Pacientes');
    db()->prepare('UPDATE pac_mtc_handoffs SET plan_id = ? WHERE id = ?')->execute([$planId, $handoffId]);
    return $planId;
}

function pac_mtc_last_handoff(int $patientId): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_mtc_handoffs WHERE patient_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$patientId]);
    return $stmt->fetch() ?: null;
}

/** Deshace el último traspaso: vuelve el plan a como estaba (o lo borra si lo creó el traspaso y nadie lo tocó). */
function pac_mtc_undo(int $patientId): string
{
    $h = pac_mtc_last_handoff($patientId);
    if (!$h || !pac_mtc_ready()) {
        throw new RuntimeException('No hay ningún traspaso para deshacer.');
    }
    $existing = mtc_plan_for_appointment((int) $h['appointment_id']);
    if ($h['before_data'] !== null) {
        $before = mtc_normalize(json_decode((string) $h['before_data'], true) ?: []);
        mtc_save((int) $h['appointment_id'], $before, $existing, 'Antes de deshacer el traspaso desde Pacientes');
        $msg = 'Se deshizo el traspaso: el Plan MTC volvió a como estaba.';
    } elseif ($existing) {
        $visits = db()->prepare('SELECT COUNT(*) FROM mtc_plan_visits WHERE plan_id = ?');
        $visits->execute([(int) $existing['id']]);
        if ((int) $visits->fetchColumn() > 0 || !empty($existing['sent_at'])) {
            throw new RuntimeException('Ese Plan MTC ya tiene consultas agendadas o se envió: editalo desde el plan.');
        }
        db()->prepare('DELETE FROM mtc_plans WHERE id = ?')->execute([(int) $existing['id']]);
        $msg = 'Se deshizo el traspaso: se borró el Plan MTC que había creado.';
    } else {
        $msg = 'El Plan MTC ya no existía.';
    }
    db()->prepare('DELETE FROM pac_mtc_handoffs WHERE id = ?')->execute([(int) $h['id']]);
    return $msg;
}

/* ---------- Importación de la biblioteca extraída (solo texto) ---------- */

function pac_import_begin(array $meta): array
{
    $sha = strtolower(pac_cursor_str($meta['sha256'] ?? '', 64));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
        throw new RuntimeException('sha256 inválido.');
    }
    $title = pac_cursor_str($meta['title'] ?? '', 200);
    $source = basename(pac_cursor_str($meta['source_file'] ?? '', 250));
    if ($title === '') {
        $title = $source !== '' ? pac_doc_title_from($source) : 'Documento importado';
    }
    $kind = strtolower(pac_cursor_str($meta['kind'] ?? 'pdf', 10));
    if (!isset(PAC_DOC_KINDS[$kind])) {
        $kind = 'pdf';
    }
    $tag = 'libro';
    foreach (pac_cursor_list($meta['tags'] ?? [], 10) as $t) {
        $t = pac_fold(pac_cursor_str($t, 30));
        if (isset(PAC_DOC_TAGS[$t])) {
            $tag = $t;
            break;
        }
        if (in_array($t, ['diapositivas', 'clase', 'curso', 'slides'], true)) {
            $tag = 'apuntes';
        }
    }
    $pages = max(0, min(20000, (int) ($meta['pages'] ?? 0)));
    $stmt = db()->prepare('SELECT * FROM pac_docs WHERE sha256 = ? ORDER BY id LIMIT 1');
    $stmt->execute([$sha]);
    $doc = $stmt->fetch();
    if ($doc && $doc['status'] === 'listo' && empty($meta['force'])) {
        return ['doc_id' => (int) $doc['id'], 'skip' => true];
    }
    if ($doc) {
        pac_doc_clear_pages((int) $doc['id']);
        db()->prepare("UPDATE pac_docs SET title = ?, kind = ?, status = 'procesando', pages = ?, note = '' WHERE id = ?")->execute([$title, $kind, $pages, (int) $doc['id']]);
        return ['doc_id' => (int) $doc['id'], 'skip' => false];
    }
    db()->prepare("INSERT INTO pac_docs (title, kind, tag, original_name, file_path, file_size, pages, status, note, sha256, source) VALUES (?, ?, ?, ?, '', ?, ?, 'procesando', '', ?, 'importado')")
        ->execute([$title, $kind, $tag, $source, max(0, (int) ($meta['size'] ?? 0)), $pages, $sha]);
    return ['doc_id' => (int) db()->lastInsertId(), 'skip' => false];
}

function pac_import_pages(int $docId, array $pages): int
{
    $doc = pac_doc($docId);
    if (!$doc || $doc['status'] !== 'procesando' || ($doc['source'] ?? '') !== 'importado') {
        throw new RuntimeException('Documento no disponible para importar.');
    }
    if (count($pages) > PAC_IMPORT_PAGES) {
        throw new RuntimeException('Demasiadas páginas en una tanda.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    $n = 0;
    foreach ($pages as $pg) {
        if (!is_array($pg)) {
            continue;
        }
        $no = (int) ($pg['page'] ?? $pg['n'] ?? 0);
        if ($no >= 1 && $no <= 20000 && is_string($pg['text'] ?? null)) {
            pac_doc_insert_page($docId, $no, $pg['text']);
            $n++;
        }
    }
    $pdo->commit();
    return $n;
}

function pac_import_finish(int $docId, int $pages): array
{
    $doc = pac_doc($docId);
    if (!$doc || ($doc['source'] ?? '') !== 'importado') {
        throw new RuntimeException('Documento no disponible para importar.');
    }
    return pac_doc_finish($docId, $pages, 'Importado desde la biblioteca extraída en tu Mac (solo texto, citas por página del original).');
}
