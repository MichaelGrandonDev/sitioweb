<?php

declare(strict_types=1);

/**
 * Plan de Medicina Tradicional China (MTC) por paciente.
 * Consulta 1: diagnóstico (el único de la etapa) y tonificación general. Consultas 2 a 5: primer bloque de tratamiento.
 * Desde la 6: controles semanales hasta completar la primera etapa de 25 consultas.
 * Requiere bootstrap.php (db, h, turno_*).
 */

const MTC_TOTAL = 25;
const MTC_BLOCK = 5;
const MTC_WEEK_DAYS = 7;
const MTC_TEXT_MAX = 4000;

const MTC_SESSION_TITLES = [
    1 => 'Diagnóstico y tonificación general',
    2 => 'Inicio del tratamiento',
    3 => 'Profundizar',
    4 => 'Consolidar',
    5 => 'Cierre del bloque y evaluación',
];

const MTC_POINTS = [
    'E36' => 'Zusanli', 'RM4' => 'Guanyuan', 'RM6' => 'Qihai', 'R3' => 'Taixi', 'PC6' => 'Neiguan',
    'DU20' => 'Baihui', 'YINTANG' => 'Yintang', 'B6' => 'Sanyinjiao', 'V20' => 'Pishu', 'RM12' => 'Zhongwan',
    'H3' => 'Taichong', 'IG4' => 'Hegu', 'VB34' => 'Yanglingquan', 'V23' => 'Shenshu', 'V17' => 'Geshu',
    'B10' => 'Xuehai', 'E40' => 'Fenglong', 'B9' => 'Yinlingquan', 'VB20' => 'Fengchi', 'VB21' => 'Jianjing',
    'ID3' => 'Houxi', 'SJ5' => 'Waiguan', 'V25' => 'Dachangshu', 'V40' => 'Weizhong', 'V60' => 'Kunlun',
    'IG15' => 'Jianyu', 'SJ14' => 'Jianliao', 'IG11' => 'Quchi', 'E35' => 'Dubi', 'XIYAN' => 'Xiyan',
];

/** En embarazo no se usan: Sanyinjiao y Hegu, más los clásicamente desaconsejados y los del bajo abdomen. */
const MTC_PREGNANCY_AVOID = ['B6', 'IG4', 'VB21', 'V60', 'RM4', 'RM6'];

const MTC_TONIFY = ['E36', 'RM4', 'RM6', 'R3', 'PC6', 'DU20'];

const MTC_CONTRA = [
    'embarazo' => 'Embarazo',
    'anticoagulantes' => 'Anticoagulantes',
    'marcapasos' => 'Marcapasos',
    'piel' => 'Piel lesionada en la zona',
];

const MTC_OPTIONS = [
    'sueno' => ['Bueno', 'Le cuesta dormirse', 'Se despierta seguido', 'Sueño liviano / muchos sueños', 'Somnolencia de día'],
    'digestion' => ['Normal', 'Lenta / pesadez', 'Distensión / gases', 'Acidez / reflujo', 'Heces blandas', 'Constipación'],
    'sed' => ['Normal', 'Mucha sed (bebidas frías)', 'Poca sed', 'Boca seca sin sed'],
    'frio_calor' => ['Equilibrado', 'Friolento / manos y pies fríos', 'Caluroso', 'Calores / sudor nocturno', 'Alterna'],
    'animo' => ['Estable', 'Estrés / irritabilidad', 'Ansiedad / preocupación', 'Tristeza / desgano', 'Cambios de humor'],
    'ciclo' => ['No corresponde', 'Regular', 'Irregular', 'Dolor menstrual', 'Abundante', 'Escaso', 'Menopausia'],
    'lengua_color' => ['Rosada (normal)', 'Pálida', 'Roja', 'Roja en la punta', 'Bordes rojos', 'Violácea'],
    'saburra_color' => ['Blanca', 'Amarilla', 'Gris / oscura', 'Sin saburra (pelada)'],
    'saburra_espesor' => ['Fina', 'Gruesa'],
    'saburra_humedad' => ['Normal', 'Seca', 'Húmeda', 'Pegajosa / grasosa'],
];

const MTC_MULTI = [
    'lengua_forma' => ['Hinchada', 'Fina', 'Marcas dentales', 'Grietas', 'Puntos rojos', 'Temblorosa', 'Desviada'],
    'zonas' => ['Punta (Corazón / Pulmón)', 'Centro (Bazo / Estómago)', 'Laterales (Hígado / Vesícula)', 'Raíz (Riñón)'],
];

const MTC_DIAG_TEXT = ['motivo', 'antecedentes', 'interrog_notas', 'lengua_notas', 'pulso', 'patron_otro', 'sintoma', 'tonificacion', 'notas'];

const MTC_AFTER_BLOCK = 'En la consulta 5 decidimos juntos cómo seguir: controles semanales hasta completar 25 consultas, el alta, o una derivación médica si hiciera falta.';

const MTC_DECISIONS = [
    '' => 'A definir en la consulta 5',
    'continuar' => 'Continuar con controles semanales hasta completar 25 consultas',
    'alta' => 'Alta',
    'derivacion' => 'Derivación médica',
];

function mtc_patterns(): array
{
    return [
        'bazo' => [
            'label' => 'Vacío de Qi de Bazo',
            'signs' => 'cansancio, digestión lenta, lengua pálida e hinchada',
            'plain' => 'Energía digestiva baja (en MTC, «vacío de Qi de Bazo»): se relaciona con el cansancio, la digestión lenta y la pesadez después de comer.',
            'meridian' => 'Bazo y Estómago',
            'points' => ['E36', 'B6', 'V20', 'RM12'],
            'root' => ['E36', 'V20'],
            'techs' => ['moxa'],
            'recs' => [
                'Comé tibio y cocido (sopas, guisos, arroz, calabaza, zanahoria). Limitá los crudos, las bebidas frías y el exceso de lácteos.',
                'Tratá de comer en horarios regulares, masticando tranquilo y sin pantallas.',
                'Caminatas suaves: moverte ayuda, agotarte no.',
            ],
        ],
        'higado' => [
            'label' => 'Estancamiento de Qi de Hígado',
            'signs' => 'estrés, irritabilidad, bordes de la lengua rojos',
            'plain' => 'Tensión acumulada (en MTC, «estancamiento de Qi de Hígado»): suele acompañar al estrés, la irritabilidad y las contracturas.',
            'meridian' => 'Hígado y Vesícula Biliar',
            'points' => ['H3', 'IG4', 'VB34', 'PC6'],
            'root' => ['H3', 'PC6'],
            'techs' => [],
            'recs' => [
                'Hacé pausas durante el día con respiración lenta (5 minutos, unas 3 veces).',
                'Actividad física moderada: caminar, bici, chi kung o taichi.',
                'Reducí alcohol, café y comidas muy grasas o fritas.',
                'Tratá de acostarte antes de las 23 hs.',
            ],
        ],
        'rinon' => [
            'label' => 'Vacío de Riñón',
            'signs' => 'lumbalgia, cansancio, raíz de la lengua alterada',
            'plain' => 'Reservas de energía bajas (en MTC, «vacío de Riñón»): se relaciona con el cansancio, el dolor lumbar y la sensación de frío.',
            'meridian' => 'Riñón y Vejiga',
            'points' => ['R3', 'V23', 'RM4'],
            'root' => ['R3', 'V23'],
            'techs' => ['moxa'],
            'recs' => [
                'Priorizá el descanso y evitá trasnochar.',
                'Mantené abrigados la zona lumbar y los pies.',
                'Comidas calientes: legumbres, sopas, semillas de sésamo y nueces.',
                'Movimiento suave: chi kung y estiramientos.',
            ],
        ],
        'sangre' => [
            'label' => 'Estasis de Sangre',
            'signs' => 'dolor fijo y punzante, lengua violácea',
            'plain' => 'Circulación enlentecida en la zona (en MTC, «estasis de Sangre»): se relaciona con un dolor fijo o punzante.',
            'meridian' => 'Bazo e Hígado (circulación de la Sangre)',
            'points' => ['V17', 'B10', 'B6'],
            'root' => ['V17', 'B10'],
            'techs' => ['ventosas'],
            'ashi' => true,
            'recs' => [
                'Movimiento diario y suave para activar la circulación.',
                'Calor local en la zona de dolor si te alivia (no si está hinchada o caliente).',
                'Evitá quedarte mucho tiempo en la misma postura.',
            ],
        ],
        'humedad' => [
            'label' => 'Humedad / Flema',
            'signs' => 'pesadez, saburra gruesa y pegajosa',
            'plain' => 'Acumulación de humedad (en MTC, «humedad / flema»): se relaciona con la pesadez, la hinchazón y el cansancio.',
            'meridian' => 'Bazo y Estómago',
            'points' => ['E40', 'B9', 'RM12'],
            'root' => ['E40', 'RM12'],
            'techs' => ['ventosas', 'moxa'],
            'recs' => [
                'Reducí harinas refinadas, azúcar, lácteos, fritos y alcohol.',
                'Preferí verduras cocidas, legumbres, arroz y un poco de jengibre.',
                'Actividad física regular para «mover» la pesadez.',
            ],
        ],
        'musculo' => [
            'label' => 'Dolor musculoesquelético',
            'signs' => 'cervical, lumbar o articular',
            'plain' => 'Dolor musculoesquelético: trabajamos la zona y los canales de energía (meridianos) que la recorren.',
            'meridian' => '',
            'points' => [],
            'root' => [],
            'techs' => ['ventosas', 'electroacupuntura', 'moxa'],
            'ashi' => true,
            'recs' => [
                'Movilidad suave de la zona todos los días, sin forzar el dolor.',
                'Calor local 15 a 20 minutos si te alivia.',
                'Cuidá la postura y hacé pausas activas.',
            ],
        ],
    ];
}

function mtc_pain_zones(): array
{
    return [
        'cervical' => ['label' => 'Cervical / hombros altos', 'local' => ['VB20', 'VB21'], 'distal' => ['ID3', 'SJ5'], 'meridian' => 'Vesícula Biliar, Intestino Delgado y Triple Calentador'],
        'lumbar' => ['label' => 'Lumbar', 'local' => ['V23', 'V25'], 'distal' => ['V40', 'V60'], 'meridian' => 'Vejiga y Riñón'],
        'hombro' => ['label' => 'Hombro', 'local' => ['IG15', 'SJ14'], 'distal' => ['IG11', 'SJ5'], 'meridian' => 'Intestino Grueso y Triple Calentador'],
        'rodilla' => ['label' => 'Rodilla', 'local' => ['XIYAN', 'E35'], 'distal' => ['VB34', 'E36'], 'meridian' => 'Estómago, Bazo y Vesícula Biliar'],
        'otra' => ['label' => 'Otra zona', 'local' => [], 'distal' => [], 'meridian' => ''],
    ];
}

function mtc_is_therapy(string $name): bool
{
    return (bool) preg_match('/china|mtc|acupunt|moxib/i', $name);
}

function mtc_point_label(string $code): string
{
    $name = MTC_POINTS[$code] ?? $code;
    return in_array($code, ['YINTANG', 'XIYAN'], true) ? $name : $name . ' (' . $code . ')';
}

function mtc_points_text(array $codes): string
{
    return implode(', ', array_map('mtc_point_label', array_values(array_unique($codes))));
}

function mtc_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->exec("
      CREATE TABLE IF NOT EXISTS mtc_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_id INTEGER NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'borrador',
        data TEXT NOT NULL DEFAULT '{}',
        sent_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    db()->exec('
      CREATE TABLE IF NOT EXISTS mtc_plan_visits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        plan_id INTEGER NOT NULL,
        appointment_id INTEGER NOT NULL UNIQUE,
        consult_no INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ');
}

/** Estructura vacía del plan. Las claves fijan qué se guarda. */
function mtc_blank(): array
{
    $diag = array_fill_keys(MTC_DIAG_TEXT, '') + array_fill_keys(array_keys(MTC_OPTIONS), '')
        + ['contra' => [], 'lengua_forma' => [], 'zonas' => [], 'patrones' => [], 'zona_dolor' => '', 'escala' => null];
    $sessions = [];
    for ($n = 2; $n <= MTC_BLOCK; $n++) {
        $sessions[$n] = ['objetivo' => '', 'puntos' => '', 'tecnica' => '', 'fecha' => '', 'escala' => null, 'control' => '', 'realizada' => false];
    }
    $controls = [];
    for ($n = MTC_BLOCK + 1; $n <= MTC_TOTAL; $n++) {
        $controls[$n] = ['fecha' => '', 'escala' => null, 'cambios' => '', 'puntos' => '', 'realizada' => false];
    }
    return [
        'diag' => $diag,
        'sessions' => $sessions,
        'controls' => $controls,
        'patient' => ['resumen' => '', 'recomendaciones' => '', 'include_points' => false, 'decision' => ''],
        'generated' => false,
    ];
}

function mtc_clean_text(mixed $v, int $max = MTC_TEXT_MAX): string
{
    if (!is_string($v)) {
        return '';
    }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}

function mtc_clean_scale(mixed $v): ?int
{
    if (!is_string($v) || trim($v) === '' || !ctype_digit(trim($v))) {
        return null;
    }
    return max(0, min(10, (int) $v));
}

function mtc_clean_date(mixed $v): string
{
    if (!is_string($v)) {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));
    return $d && $d->format('Y-m-d') === trim($v) ? trim($v) : '';
}

/** Datos del formulario, siempre filtrados contra la estructura de mtc_blank(). */
function mtc_from_post(array $post, array $prev): array
{
    $plan = mtc_blank();
    $d = is_array($post['diag'] ?? null) ? $post['diag'] : [];
    foreach (MTC_DIAG_TEXT as $k) {
        $plan['diag'][$k] = mtc_clean_text($d[$k] ?? '');
    }
    foreach (MTC_OPTIONS as $k => $opts) {
        $v = is_string($d[$k] ?? null) ? $d[$k] : '';
        $plan['diag'][$k] = in_array($v, $opts, true) ? $v : '';
    }
    $multi = MTC_MULTI + ['contra' => array_keys(MTC_CONTRA), 'patrones' => array_keys(mtc_patterns())];
    foreach ($multi as $k => $opts) {
        $vals = is_array($d[$k] ?? null) ? $d[$k] : [];
        $plan['diag'][$k] = array_values(array_intersect($opts, array_filter($vals, 'is_string')));
    }
    $zone = is_string($d['zona_dolor'] ?? null) ? $d['zona_dolor'] : '';
    $plan['diag']['zona_dolor'] = isset(mtc_pain_zones()[$zone]) ? $zone : '';
    $plan['diag']['escala'] = mtc_clean_scale($d['escala'] ?? null);

    $s = is_array($post['s'] ?? null) ? $post['s'] : [];
    foreach ($plan['sessions'] as $n => $blank) {
        $row = is_array($s[$n] ?? null) ? $s[$n] : [];
        $plan['sessions'][$n] = [
            'objetivo' => mtc_clean_text($row['objetivo'] ?? ''),
            'puntos' => mtc_clean_text($row['puntos'] ?? ''),
            'tecnica' => mtc_clean_text($row['tecnica'] ?? ''),
            'fecha' => mtc_clean_date($row['fecha'] ?? ''),
            'escala' => mtc_clean_scale($row['escala'] ?? null),
            'control' => mtc_clean_text($row['control'] ?? ''),
            'realizada' => ($row['realizada'] ?? '') === '1',
        ];
    }
    $c = is_array($post['c'] ?? null) ? $post['c'] : [];
    foreach ($plan['controls'] as $n => $blank) {
        $row = is_array($c[$n] ?? null) ? $c[$n] : [];
        $plan['controls'][$n] = [
            'fecha' => mtc_clean_date($row['fecha'] ?? ''),
            'escala' => mtc_clean_scale($row['escala'] ?? null),
            'cambios' => mtc_clean_text($row['cambios'] ?? '', 1500),
            'puntos' => mtc_clean_text($row['puntos'] ?? '', 1500),
            'realizada' => ($row['realizada'] ?? '') === '1',
        ];
    }
    $p = is_array($post['p'] ?? null) ? $post['p'] : [];
    $decision = is_string($p['decision'] ?? null) ? $p['decision'] : '';
    $plan['patient'] = [
        'resumen' => mtc_clean_text($p['resumen'] ?? ''),
        'recomendaciones' => mtc_clean_text($p['recomendaciones'] ?? ''),
        'include_points' => ($p['include_points'] ?? '') === '1',
        'decision' => array_key_exists($decision, MTC_DECISIONS) ? $decision : '',
    ];
    $plan['generated'] = (bool) ($prev['generated'] ?? false);
    return $plan;
}

/** Mezcla lo guardado con la estructura actual (planes viejos o JSON incompleto). */
function mtc_normalize(array $saved): array
{
    $plan = mtc_blank();
    foreach (['diag', 'patient'] as $k) {
        if (is_array($saved[$k] ?? null)) {
            $plan[$k] = array_intersect_key($saved[$k], $plan[$k]) + $plan[$k];
        }
    }
    foreach (['sessions', 'controls'] as $k) {
        foreach ($plan[$k] as $n => $blank) {
            $row = $saved[$k][$n] ?? $saved[$k][(string) $n] ?? null;
            if (is_array($row)) {
                $plan[$k][$n] = array_intersect_key($row, $blank) + $blank;
            }
        }
    }
    $plan['generated'] = (bool) ($saved['generated'] ?? false);
    return $plan;
}

function mtc_plan_for_appointment(int $apptId): ?array
{
    mtc_schema();
    $stmt = db()->prepare('SELECT * FROM mtc_plans WHERE appointment_id = ? LIMIT 1');
    $stmt->execute([$apptId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $row['plan'] = mtc_normalize(json_decode((string) $row['data'], true) ?: []);
    return $row;
}

/** Turno de un plan (consulta 2 en adelante) → [plan_id, appointment_id del plan, consult_no]. */
function mtc_visit_of(int $apptId): ?array
{
    mtc_schema();
    $stmt = db()->prepare('SELECT v.plan_id, v.consult_no, p.appointment_id FROM mtc_plan_visits v JOIN mtc_plans p ON p.id = v.plan_id WHERE v.appointment_id = ? LIMIT 1');
    $stmt->execute([$apptId]);
    return $stmt->fetch() ?: null;
}

function mtc_save(int $apptId, array $plan, ?array $existing): int
{
    $json = json_encode($plan, JSON_UNESCAPED_UNICODE);
    $now = date('Y-m-d H:i:s');
    if ($existing) {
        if ($json !== json_encode(mtc_normalize(json_decode((string) $existing['data'], true) ?: []), JSON_UNESCAPED_UNICODE)) {
            db()->prepare('UPDATE mtc_plans SET data = ?, updated_at = ? WHERE id = ?')->execute([$json, $now, (int) $existing['id']]);
        }
        return (int) $existing['id'];
    }
    db()->prepare('INSERT INTO mtc_plans (appointment_id, data, created_at, updated_at) VALUES (?, ?, ?, ?)')->execute([$apptId, $json, $now, $now]);
    return (int) db()->lastInsertId();
}

/** Turnos agendados del plan (sin cancelados), por número de consulta. */
function mtc_visits(int $planId): array
{
    $stmt = db()->prepare("
      SELECT v.consult_no, a.id, a.date, a.time, a.status, a.code
      FROM mtc_plan_visits v JOIN appointments a ON a.id = v.appointment_id
      WHERE v.plan_id = ? AND a.status IN ('confirmed', 'pending_deposit')
      ORDER BY v.consult_no
    ");
    $stmt->execute([$planId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['consult_no']] = $row;
    }
    return $out;
}

function mtc_diagnosed(array $plan): bool
{
    return $plan['diag']['patrones'] !== [] || $plan['diag']['motivo'] !== '' || $plan['diag']['patron_otro'] !== '';
}

/** Última consulta registrada como hecha (1 = solo el diagnóstico). */
function mtc_done(array $plan): int
{
    $last = mtc_diagnosed($plan) ? 1 : 0;
    foreach ($plan['sessions'] + $plan['controls'] as $n => $row) {
        if (!empty($row['realizada'])) {
            $last = max($last, (int) $n);
        }
    }
    return $last;
}

function mtc_pregnant(array $plan): bool
{
    return in_array('embarazo', $plan['diag']['contra'], true);
}

function mtc_filter_points(array $codes, bool $pregnant): array
{
    return array_values(array_unique($pregnant ? array_diff($codes, MTC_PREGNANCY_AVOID) : $codes));
}

function mtc_default_tonify(bool $pregnant): string
{
    $codes = mtc_filter_points(MTC_TONIFY, $pregnant);
    $first = mtc_point_label(array_shift($codes)) . ' bilateral';
    $text = implode(', ', array_merge([$first], array_map('mtc_point_label', $codes))) . ' o Yintang' . ".\n"
        . ($pregnant ? "Moxa suave en Zusanli (E36) si hay frío o cansancio.\n" : "Moxa en Zusanli (E36) / Qihai (RM6) si hay frío o cansancio.\n")
        . 'Retención 20–30 min.';
    if ($pregnant) {
        $text .= "\nEmbarazo: sin Sanyinjiao (B6), Hegu (IG4) ni puntos del bajo abdomen.";
    }
    return $text;
}

/** Avisos por contraindicaciones o puntos no aconsejados que quedaron escritos a mano. */
function mtc_warnings(array $plan, ?array $appt = null): array
{
    $w = [];
    $contra = $plan['diag']['contra'];
    if (mtc_pregnant($plan)) {
        $w[] = 'Embarazo: el plan generado no incluye ' . mtc_points_text(MTC_PREGNANCY_AVOID) . '.';
        $texts = $plan['diag']['tonificacion'];
        foreach ($plan['sessions'] as $s) {
            $texts .= "\n" . $s['puntos'] . "\n" . $s['tecnica'];
        }
        foreach ($plan['controls'] as $c) {
            $texts .= "\n" . $c['puntos'];
        }
        $texts = preg_replace('/^\s*Embarazo:.*$/mu', '', $texts) ?? $texts;
        $found = [];
        foreach (MTC_PREGNANCY_AVOID as $code) {
            if (preg_match('/\b' . $code . '\b|' . preg_quote(MTC_POINTS[$code], '/') . '/i', $texts)) {
                $found[] = mtc_point_label($code);
            }
        }
        if ($found) {
            $w[] = 'Atención: en los textos del plan todavía aparece ' . implode(', ', $found) . '. Revisalo (embarazo).';
        }
    }
    if (in_array('marcapasos', $contra, true)) {
        $w[] = 'Marcapasos: sin electroacupuntura.';
    }
    if (in_array('anticoagulantes', $contra, true)) {
        $w[] = 'Anticoagulantes: sin ventosas, agujas finas y presión suave al retirar.';
    }
    if (in_array('piel', $contra, true)) {
        $w[] = 'Piel lesionada: no punturar ni aplicar ventosas o moxa sobre la zona.';
    }
    if ($appt !== null && empty($appt['consent_accepted_at'])) {
        $w[] = 'El consentimiento informado no está firmado online: revisá que lo haya firmado en papel.';
    }
    return $w;
}

/**
 * Arma los textos de las consultas 2 a 5, el resumen para el paciente y las recomendaciones
 * a partir de los patrones elegidos en la consulta 1.
 *
 * @return array{sessions: array<int, array{objetivo: string, puntos: string, tecnica: string}>, resumen: string, recomendaciones: string}
 */
function mtc_generate(array $plan): array
{
    $diag = $plan['diag'];
    $all = mtc_patterns();
    $pregnant = mtc_pregnant($plan);
    $contra = $diag['contra'];
    $chosen = array_values(array_intersect_key($all, array_flip($diag['patrones'])));
    $zones = mtc_pain_zones();
    $zone = $zones[$diag['zona_dolor']] ?? null;

    $points = [];
    $root = [];
    $techs = [];
    $meridians = [];
    $ashi = false;
    foreach ($chosen as $p) {
        $points = array_merge($points, $p['points']);
        $root = array_merge($root, $p['root']);
        $techs = array_merge($techs, $p['techs']);
        if ($p['meridian'] !== '') {
            $meridians[] = $p['meridian'];
        }
        $ashi = $ashi || !empty($p['ashi']);
    }
    $local = $zone['local'] ?? [];
    $distal = $zone['distal'] ?? [];
    if ($zone && $zone['meridian'] !== '') {
        $meridians[] = $zone['meridian'];
    }
    if (in_array('marcapasos', $contra, true)) {
        $techs = array_diff($techs, ['electroacupuntura']);
    }
    if (in_array('anticoagulantes', $contra, true)) {
        $techs = array_diff($techs, ['ventosas']);
    }
    $techs = array_values(array_unique($techs));
    $points = mtc_filter_points($points, $pregnant);
    $root = mtc_filter_points($root ?: $points, $pregnant);
    $local = mtc_filter_points($local, $pregnant);
    $distal = mtc_filter_points($distal, $pregnant);
    $meridianText = $meridians ? implode('; ', array_values(array_unique($meridians))) : 'los meridianos relacionados con tu consulta';
    $otro = $diag['patron_otro'];
    $zoneLabel = $zone ? mb_strtolower($zone['label']) : '';

    $line = static fn (string $label, array $codes, string $extra = ''): string => $codes || $extra !== ''
        ? $label . ': ' . trim(mtc_points_text($codes) . ($codes && $extra !== '' ? ', ' : '') . $extra) . ".\n"
        : '';
    $ashiText = $ashi ? 'puntos Ashi (dolorosos a la palpación)' : '';
    $control = 'Control breve: escala 0–10, cambios en la semana, reacciones a la sesión anterior (sin nuevo diagnóstico).';
    $techText = $techs ? implode(', ', $techs) : 'moxa';
    $closing = mtc_filter_points(['E36', 'RM6'], $pregnant);

    $s2Points = $line('Puntos del patrón', $points, $otro !== '' && !$points ? 'según el patrón (' . $otro . ')' : '')
        . $line('Locales', $local, $ashiText)
        . $line('Distales del meridiano afectado', $distal, $zone && !$distal ? 'del meridiano que recorre la zona' : '');
    $s2Points = trim($s2Points) !== '' ? trim($s2Points) : 'Puntos del patrón diagnosticado, locales y distales del meridiano afectado.';

    $sessions = [
        2 => [
            'objetivo' => 'Empezamos el tratamiento de lo que vimos en la primera consulta. Trabajamos puntos de los canales de energía (meridianos) de '
                . $meridianText . ($zoneLabel !== '' ? ', junto con puntos cercanos a la zona ' . $zoneLabel : '')
                . '. Al comenzar hacemos un control breve: cómo pasaste la semana y cómo está tu molestia de 0 a 10.',
            'puntos' => $s2Points,
            'tecnica' => $control . "\nRetención 20–30 min.",
        ],
        3 => [
            'objetivo' => 'Mantenemos la base de la consulta anterior y la ajustamos según cómo respondiste. Si hace falta, sumamos técnicas complementarias como '
                . mtc_techs_plain($techs) . '.',
            'puntos' => 'Base de la consulta 2, ajustada según la respuesta.' . "\n" . 'Técnicas asociadas si corresponde: ' . $techText . '.',
            'tecnica' => $control,
        ],
        4 => [
            'objetivo' => 'Usamos menos puntos y más precisos, para afianzar lo logrado y fortalecer la raíz del desequilibrio.',
            'puntos' => 'Reforzar la raíz: ' . ($root ? mtc_points_text($root) : 'puntos principales del patrón') . '.'
                . ($local ? "\nLocales solo si persiste la molestia: " . mtc_points_text($local) . '.' : ''),
            'tecnica' => $control,
        ],
        5 => [
            'objetivo' => 'Hacemos el tratamiento final de este primer bloque junto con una tonificación general, comparamos cómo estás con la primera consulta y te dejamos indicaciones para casa. '
                . 'Decidimos juntos cómo seguir: continuar con controles semanales hasta completar 25 consultas, alta, o derivación médica si hiciera falta.',
            'puntos' => 'Tratamiento final: ' . ($root ? mtc_points_text($root) : 'puntos principales del patrón') . ".\n"
                . 'Tonificación general: ' . mtc_points_text($closing) . '.',
            'tecnica' => $control . "\nComparar con la escala de la consulta 1"
                . ($diag['escala'] !== null ? ' (' . $diag['escala'] . '/10)' : '') . '. Indicaciones para casa. Registrar la decisión.',
        ],
    ];

    $resumen = [];
    $motivo = $diag['motivo'];
    $resumen[] = 'En la primera consulta hablamos de tu motivo de consulta' . ($motivo !== '' ? ' (' . rtrim(mb_strtolower(mb_substr($motivo, 0, 1)) . mb_substr($motivo, 1), '. ') . ')' : '')
        . ', observamos tu lengua, tomamos el pulso y realizamos una tonificación general.';
    $resumen[] = 'Lo que encontramos, en palabras simples:';
    foreach ($chosen as $key => $p) {
        $resumen[] = '- ' . ($p['label'] === 'Dolor musculoesquelético' && $zoneLabel !== ''
            ? 'Dolor musculoesquelético (zona ' . $zoneLabel . '): trabajamos la zona y los canales de energía (meridianos) que la recorren.'
            : $p['plain']);
    }
    if ($otro !== '') {
        $resumen[] = '- ' . $otro;
    }
    if (!$chosen && $otro === '') {
        $resumen[] = '- (completar)';
    }
    if ($diag['escala'] !== null) {
        $resumen[] = 'Hoy tu molestia principal' . ($diag['sintoma'] !== '' ? ' (' . $diag['sintoma'] . ')' : '') . ' está en ' . $diag['escala']
            . ' de 10. La vamos a volver a medir en cada control semanal para ver cómo avanzás.';
    }

    $recs = [];
    foreach ($chosen as $p) {
        $recs = array_merge($recs, $p['recs']);
    }
    $recs[] = 'Tomá agua durante el día y evitá comidas pesadas justo antes y después de cada sesión.';
    $recs[] = 'Anotá cómo te sentís durante la semana para contarlo en el control.';

    return [
        'sessions' => $sessions,
        'resumen' => implode("\n", $resumen),
        'recomendaciones' => implode("\n", array_map(static fn ($r) => '- ' . $r, array_values(array_unique($recs)))),
    ];
}

function mtc_techs_plain(array $techs): string
{
    $plain = ['moxa' => 'moxa (calor con artemisa)', 'ventosas' => 'ventosas', 'electroacupuntura' => 'electroacupuntura suave'];
    $list = array_values(array_map(static fn ($t) => $plain[$t] ?? $t, $techs ?: ['moxa']));
    if (count($list) === 1) {
        return $list[0];
    }
    return implode(', ', array_slice($list, 0, -1)) . ' o ' . end($list);
}

function mtc_apply_generated(array $plan): array
{
    if ($plan['diag']['tonificacion'] === '' || $plan['diag']['tonificacion'] === mtc_default_tonify(false) || $plan['diag']['tonificacion'] === mtc_default_tonify(true)) {
        $plan['diag']['tonificacion'] = mtc_default_tonify(mtc_pregnant($plan));
    }
    $gen = mtc_generate($plan);
    foreach ($gen['sessions'] as $n => $texts) {
        $plan['sessions'][$n] = $texts + $plan['sessions'][$n];
    }
    $plan['patient']['resumen'] = $gen['resumen'];
    $plan['patient']['recomendaciones'] = $gen['recomendaciones'];
    $plan['generated'] = true;
    return $plan;
}

/** Fecha de una consulta: la del turno agendado, o la anotada a mano. */
function mtc_consult_date(int $n, array $plan, array $appt, array $visits): string
{
    if ($n === 1) {
        return (string) $appt['date'];
    }
    if (isset($visits[$n])) {
        return (string) $visits[$n]['date'];
    }
    return (string) ($n <= MTC_BLOCK ? $plan['sessions'][$n]['fecha'] : $plan['controls'][$n]['fecha']);
}

function mtc_frequency_text(): string
{
    return 'Las sesiones son semanales, de 50 a 60 minutos (las agujas quedan colocadas entre 20 y 30 minutos). '
        . 'La primera etapa del tratamiento es de hasta 25 consultas semanales: las consultas 1 a 5 forman el primer bloque que te detallamos abajo, '
        . 'y desde la consulta 6 seguimos con controles y tratamiento cada semana. Re-evaluamos cómo vas en la consulta 5 y después cada 5 consultas '
        . '(10, 15, 20 y 25). Si alcanzamos los objetivos antes, podés recibir el alta antes de completar las 25.';
}

function mtc_disclaimer(): string
{
    return 'Este plan es un acompañamiento desde la Medicina Tradicional China y no reemplaza el diagnóstico ni el tratamiento médico. '
        . 'No suspendas ni cambies tu medicación sin hablar con tu médico. Si aparecen síntomas nuevos o empeoran, consultá a tu médico y avisanos.';
}

/** Renglones "- algo" → lista; el resto, párrafos. */
function mtc_lines(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $out[] = preg_match('/^[-•*]\s*(.+)$/u', $line, $m) ? ['item', $m[1]] : ['p', $line];
        }
    }
    return $out;
}

function mtc_pdf_text(FluxusPdf $pdf, string $text): void
{
    foreach (mtc_lines($text) as [$type, $line]) {
        if ($type === 'item') {
            $pdf->richParagraph([[$line, false]], '•', 6, 12, 10.5);
        } else {
            $pdf->richParagraph([[$line, false]], '', 0, 0, 10.5);
        }
    }
}

function mtc_pdf_heading(FluxusPdf $pdf, string $text): void
{
    $pdf->keepTogether(80);
    $pdf->heading($text);
}

function mtc_date_label(string $ymd): string
{
    return $ymd !== '' ? format_date_es($ymd) : '';
}

/** PDF para el paciente: sin pulso, notas internas, técnica ni controles del terapeuta. */
function mtc_plan_pdf(array $plan, array $appt, array $visits): string
{
    global $config;
    $pdf = new FluxusPdf();
    $pdf->setFooter('FluxusTerapia · Plan de Medicina Tradicional China · ' . $appt['patient_name']);
    turno_pdf_header($pdf, 'Tu plan de tratamiento · Medicina Tradicional China');
    $pdf->richParagraph([['Hola, ' . $appt['patient_name'] . ':', true]], '', 0, 0, 11);
    $first = format_date_es((string) $appt['date']);
    $pdf->richParagraph([['Te compartimos el plan que armamos a partir de tu primera consulta del ' . mb_strtolower(mb_substr($first, 0, 1)) . mb_substr($first, 1)
        . '. Lo vamos ajustando semana a semana según cómo te sientas.', false]], '', 0, 0, 10.5);

    mtc_pdf_heading($pdf, 'Lo que vimos en tu primera consulta');
    mtc_pdf_text($pdf, $plan['patient']['resumen']);

    mtc_pdf_heading($pdf, 'Cómo es el tratamiento');
    mtc_pdf_text($pdf, mtc_frequency_text());

    mtc_pdf_heading($pdf, 'Primer bloque: consultas 1 a 5');
    for ($n = 1; $n <= MTC_BLOCK; $n++) {
        $date = mtc_date_label(mtc_consult_date($n, $plan, $appt, $visits));
        $pdf->keepTogether(60);
        $pdf->richParagraph([
            ['Consulta ' . $n . ' de ' . MTC_TOTAL . ' · ' . MTC_SESSION_TITLES[$n] . ($date !== '' ? ' (' . $date . ')' : ''), true],
        ], '', 0, 0, 10.5);
        $text = $n === 1
            ? 'Evaluamos tu estado general (preguntas, observación de la lengua y pulso) y hacemos una tonificación general para equilibrar tu energía. '
                . 'El diagnóstico se hace solo en esta consulta; en las siguientes hacemos un control breve al comenzar.'
            : $plan['sessions'][$n]['objetivo'];
        mtc_pdf_text($pdf, $text);
        if ($plan['patient']['include_points'] && $n > 1 && $plan['sessions'][$n]['puntos'] !== '') {
            $pdf->richParagraph([['Puntos:', true], [str_replace("\n", ' ', $plan['sessions'][$n]['puntos']), false]], '', 12, 0, 9.5);
        }
    }

    mtc_pdf_heading($pdf, 'Después de la consulta 5: controles semanales');
    $decision = $plan['patient']['decision'];
    mtc_pdf_text($pdf, 'En la consulta 5 decidimos juntos cómo seguir:');
    foreach (['continuar', 'alta', 'derivacion'] as $k) {
        $pdf->richParagraph([[MTC_DECISIONS[$k] . ($k === 'derivacion' ? ', si hiciera falta.' : '.'), $decision === $k]], '•', 6, 12, 10.5);
    }
    if ($decision !== '') {
        $pdf->richParagraph([['Lo que decidimos:', true], [MTC_DECISIONS[$decision] . '.', false]], '', 0, 0, 10.5);
    }
    mtc_pdf_text($pdf, 'Si seguimos, cada semana hacemos un control breve (cómo estás de 0 a 10, cambios y reacciones) y el tratamiento de ese día, hasta completar 25 consultas. '
        . 'Si al terminar esta etapa hace falta continuar, empezamos una nueva con un nuevo diagnóstico.');
    $upcoming = [];
    $today = date('Y-m-d');
    foreach ($visits as $n => $v) {
        if ((string) $v['date'] >= $today) {
            $upcoming[] = 'Consulta ' . $n . ': ' . format_date_es((string) $v['date']) . ' · ' . format_time_es((string) $v['time']);
        }
    }
    if ($upcoming) {
        $pdf->richParagraph([['Tus próximos turnos:', true]], '', 0, 0, 10.5);
        foreach ($upcoming as $u) {
            $pdf->richParagraph([[$u, false]], '•', 6, 12, 10.5);
        }
        $pdf->richParagraph([['Lugar: ' . turno_location_of($appt)['label'], false]], '', 0, 0, 10.5);
    }

    if ($plan['patient']['recomendaciones'] !== '') {
        mtc_pdf_heading($pdf, 'Recomendaciones para casa');
        mtc_pdf_text($pdf, $plan['patient']['recomendaciones']);
    }

    $pdf->keepTogether(150);
    $pdf->heading('Importante');
    mtc_pdf_text($pdf, mtc_disclaimer());
    $pdf->richParagraph([['WhatsApp: +' . $config['whatsapp'] . ' · Web: ' . $config['site_url'] . ' · Gracias por confiar en FluxusTerapia.', false]], '', 0, 0, 9.5);
    return $pdf->render();
}

function mtc_pdf_name(array $appt): string
{
    return 'Plan-MTC-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) $appt['code']) . '.pdf';
}

/** Manda el plan al mail del turno. Devuelve 'sent', 'no_email' o 'failed'. */
function mtc_send_plan(array $row, array $appt, array $visits): string
{
    global $config;
    $to = trim((string) $appt['patient_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no_email';
    }
    $plan = $row['plan'];
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $config['whatsapp']);
    $h2 = static fn (string $t): string => '<h2 style="margin:22px 0 8px;font-family:Georgia,serif;font-size:19px;color:#0f3d36">' . $t . '</h2>';
    $htmlText = static function (string $text) use ($e): string {
        $out = '';
        $list = false;
        foreach (mtc_lines($text) as [$type, $line]) {
            if ($type === 'item' && !$list) {
                $out .= '<ul style="margin:0 0 10px;padding-left:20px">';
                $list = true;
            } elseif ($type !== 'item' && $list) {
                $out .= '</ul>';
                $list = false;
            }
            $out .= $type === 'item' ? '<li style="margin:0 0 4px">' . $e($line) . '</li>' : '<p style="margin:0 0 10px">' . $e($line) . '</p>';
        }
        return $out . ($list ? '</ul>' : '');
    };

    $sessionsHtml = '<ol style="margin:0;padding-left:20px">';
    $sessionsText = '';
    for ($n = 1; $n <= MTC_BLOCK; $n++) {
        $date = mtc_date_label(mtc_consult_date($n, $plan, $appt, $visits));
        $sessionsHtml .= '<li style="margin:0 0 4px"><strong>' . $e(MTC_SESSION_TITLES[$n]) . '</strong>' . ($date !== '' ? ' · ' . $e($date) : '') . '</li>';
        $sessionsText .= $n . '. ' . MTC_SESSION_TITLES[$n] . ($date !== '' ? ' · ' . $date : '') . "\n";
    }
    $sessionsHtml .= '</ol>';

    $subject = 'Tu plan de tratamiento · Medicina Tradicional China · FluxusTerapia';
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid rgba(15,61,54,.12)">'
        . '<tr><td style="background:#0f3d36;color:#fff;padding:20px 26px;font-family:Georgia,serif;font-size:24px">FluxusTerapia</td></tr>'
        . '<tr><td style="padding:26px;font-size:15px;line-height:1.6">'
        . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#2f8f6b;font-weight:700">Medicina Tradicional China</p>'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px">Hola, ' . $e($appt['patient_name']) . '</h1>'
        . '<p style="margin:0 0 12px;color:#4f635a">Te mandamos tu plan de tratamiento, armado a partir de tu primera consulta. Lo tenés completo en el PDF adjunto.</p>'
        . $h2('Lo que vimos') . $htmlText($plan['patient']['resumen'])
        . $h2('Cómo sigue') . '<p style="margin:0 0 10px">' . $e(mtc_frequency_text()) . '</p>'
        . '<p style="margin:0 0 6px"><strong>Primer bloque (consultas 1 a 5):</strong></p>' . $sessionsHtml
        . '<p style="margin:10px 0 0">' . $e(MTC_AFTER_BLOCK) . '</p>'
        . ($plan['patient']['recomendaciones'] !== '' ? $h2('Para casa') . $htmlText($plan['patient']['recomendaciones']) : '')
        . '<p style="margin:18px 0;padding:12px 14px;background:#eef3ef;border-radius:8px;font-size:13px;color:#4f635a">' . $e(mtc_disclaimer()) . '</p>'
        . '<p style="margin:0 0 18px"><a href="' . $e($wa) . '" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">Consultas por WhatsApp</a></p>'
        . '<p style="margin:0;color:#4f635a">Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';

    $text = 'Hola, ' . $appt['patient_name'] . "\n\n"
        . "Te mandamos tu plan de tratamiento de Medicina Tradicional China (completo en el PDF adjunto).\n\n"
        . "LO QUE VIMOS\n" . $plan['patient']['resumen'] . "\n\n"
        . "CÓMO SIGUE\n" . mtc_frequency_text() . "\n\nPrimer bloque (consultas 1 a 5):\n" . $sessionsText
        . MTC_AFTER_BLOCK . "\n\n"
        . ($plan['patient']['recomendaciones'] !== '' ? "PARA CASA\n" . $plan['patient']['recomendaciones'] . "\n\n" : '')
        . mtc_disclaimer() . "\n\nConsultas por WhatsApp: " . $wa . "\n\nFluxusTerapia\n";

    $ok = turno_send_mail($to, $subject, $html, $text, [mtc_pdf_name($appt) => mtc_plan_pdf($plan, $appt, $visits)]);
    if (!$ok) {
        return 'failed';
    }
    db()->prepare("UPDATE mtc_plans SET status = 'enviado', sent_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int) $row['id']]);
    return 'sent';
}

/** Primera consulta sin agendar y fecha sugerida (7 días después del último turno del plan, nunca en el pasado). */
function mtc_schedule_start(array $plan, array $appt, array $visits): array
{
    $next = max(2, mtc_done($plan) + 1, ($visits ? max(array_keys($visits)) : 0) + 1);
    $base = $visits ? (string) $visits[max(array_keys($visits))]['date'] : (string) $appt['date'];
    $date = (new DateTimeImmutable($base))->modify('+' . MTC_WEEK_DAYS . ' days');
    $tomorrow = new DateTimeImmutable('tomorrow');
    while ($date < $tomorrow) {
        $date = $date->modify('+' . MTC_WEEK_DAYS . ' days');
    }
    return [$next, $date];
}

/**
 * Crea los turnos semanales elegidos con la carga manual del admin, en el mismo lugar y terapia de la consulta 1.
 * $rows = [[consult_no, date, time], …]. Devuelve [creados, errores].
 */
function mtc_schedule(array $row, array $appt, array $rows, int $deposit): array
{
    $loc = turno_location_of($appt);
    $taken = array_keys(mtc_visits((int) $row['id']));
    $created = [];
    $errors = [];
    foreach ($rows as [$n, $date, $time]) {
        if ($n < 2 || $n > MTC_TOTAL || in_array($n, $taken, true)) {
            $errors[] = 'Consulta ' . $n . ': ya está agendada o fuera de las 25.';
            continue;
        }
        try {
            $new = create_manual_appointment([
                'name' => (string) $appt['patient_name'],
                'email' => (string) $appt['patient_email'],
                'phone' => (string) $appt['patient_phone'],
                'therapy_id' => (int) $appt['therapy_id'],
                'date' => $date,
                'time' => $time,
                'notes' => 'Plan MTC · consulta ' . $n . ' de ' . MTC_TOTAL,
                'deposit_amount' => $deposit,
                'location' => ['name' => $loc['name'], 'address' => $loc['address'], 'notes' => $loc['notes'], 'maps' => $loc['maps']],
            ]);
        } catch (RuntimeException $e) {
            $errors[] = 'Consulta ' . $n . ': ' . $e->getMessage();
            continue;
        }
        db()->prepare('INSERT INTO mtc_plan_visits (plan_id, appointment_id, consult_no) VALUES (?, ?, ?)')
            ->execute([(int) $row['id'], $new['id'], $n]);
        $taken[] = $n;
        $created[$n] = $new['id'];
    }
    return [$created, $errors];
}

/** Un solo mail con todas las fechas nuevas y un calendario con todos los turnos. */
function mtc_send_schedule_mail(array $appt, array $created): string
{
    global $config;
    $to = trim((string) $appt['patient_email']);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'no_email';
    }
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $loc = turno_location_of($appt);
    $events = '';
    $itemsHtml = '';
    $itemsText = '';
    foreach ($created as $n => $id) {
        $a = turno_by_id((int) $id);
        if (!$a) {
            continue;
        }
        $when = format_date_es((string) $a['date']) . ' · ' . format_time_es((string) $a['time']);
        $itemsHtml .= '<li style="margin:0 0 4px">Consulta ' . (int) $n . ' de ' . MTC_TOTAL . ': <strong>' . $e($when) . '</strong></li>';
        $itemsText .= '- Consulta ' . $n . ' de ' . MTC_TOTAL . ': ' . $when . "\n";
        if (preg_match('/BEGIN:VEVENT.*END:VEVENT\r\n/s', turno_ics($a), $m)) {
            $events .= $m[0];
        }
    }
    if ($itemsText === '') {
        return 'failed';
    }
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//FluxusTerapia//Turnos//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n" . $events . "END:VCALENDAR\r\n";
    $wa = 'https://wa.me/' . preg_replace('/\D+/', '', (string) $config['whatsapp']);
    $subject = 'Tus próximos turnos semanales · Medicina Tradicional China · FluxusTerapia';
    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#eef3ef;font-family:Arial,sans-serif;color:#12201c">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid rgba(15,61,54,.12)">'
        . '<tr><td style="background:#0f3d36;color:#fff;padding:20px 26px;font-family:Georgia,serif;font-size:24px">FluxusTerapia</td></tr>'
        . '<tr><td style="padding:26px;font-size:15px;line-height:1.6">'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,serif;font-size:26px">Hola, ' . $e($appt['patient_name']) . '</h1>'
        . '<p style="margin:0 0 10px">Te dejamos agendadas tus próximas sesiones semanales de ' . $e($appt['therapy_name']) . ':</p>'
        . '<ul style="margin:0 0 12px;padding-left:20px">' . $itemsHtml . '</ul>'
        . '<p style="margin:0 0 10px">Lugar: <strong>' . $e($loc['label']) . '</strong>'
        . ($loc['map_link'] !== '' ? ' · <a href="' . $e($loc['map_link']) . '" style="color:#0f3d36;font-weight:700">Cómo llegar</a>' : '') . '</p>'
        . '<p style="margin:0 0 18px">Adjuntamos un archivo para agregarlas todas a tu calendario. Si no podés venir, avisanos con al menos 24 horas de anticipación.</p>'
        . '<p style="margin:0 0 18px"><a href="' . $e($wa) . '" style="display:inline-block;background:#2f8f6b;color:#fff;text-decoration:none;padding:11px 18px;border-radius:6px;font-weight:700">Consultas por WhatsApp</a></p>'
        . '<p style="margin:0;color:#4f635a">Con cariño,<br><strong>FluxusTerapia</strong></p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $text = 'Hola, ' . $appt['patient_name'] . "\n\nTe dejamos agendadas tus próximas sesiones semanales de " . $appt['therapy_name'] . ":\n"
        . $itemsText . "\nLugar: " . $loc['label'] . "\n" . ($loc['map_link'] !== '' ? 'Cómo llegar: ' . $loc['map_link'] . "\n" : '')
        . "\nAdjuntamos un archivo para agregarlas a tu calendario. Si no podés venir, avisanos con al menos 24 horas de anticipación.\n\n"
        . 'Consultas por WhatsApp: ' . $wa . "\n\nFluxusTerapia\n";
    return turno_send_mail($to, $subject, $html, $text, ['Turnos-MTC-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) $appt['code']) . '.ics' => $ics]) ? 'sent' : 'failed';
}

/** Botón para la lista de turnos del admin: plan de la consulta 1 o turno agendado desde un plan. */
function mtc_admin_link(array $appt): string
{
    static $plans = null;
    static $visits = null;
    if ($plans === null) {
        mtc_schema();
        $plans = array_flip(array_map('intval', db()->query('SELECT appointment_id FROM mtc_plans')->fetchAll(PDO::FETCH_COLUMN)));
        $visits = [];
        foreach (db()->query('SELECT v.appointment_id, v.consult_no, p.appointment_id AS first_id FROM mtc_plan_visits v JOIN mtc_plans p ON p.id = v.plan_id')->fetchAll() as $v) {
            $visits[(int) $v['appointment_id']] = $v;
        }
    }
    $id = (int) $appt['id'];
    if (isset($visits[$id])) {
        return '<a class="btn ghost" href="plan_mtc.php?id=' . (int) $visits[$id]['first_id'] . '#consulta-' . (int) $visits[$id]['consult_no'] . '">Plan MTC · consulta '
            . (int) $visits[$id]['consult_no'] . ' de ' . MTC_TOTAL . '</a>';
    }
    if (isset($plans[$id])) {
        return '<a class="btn ghost" href="plan_mtc.php?id=' . $id . '">Plan MTC · consulta 1 de ' . MTC_TOTAL . '</a>';
    }
    if (mtc_is_therapy((string) ($appt['therapy_name'] ?? ''))) {
        return '<a class="btn ghost" href="plan_mtc.php?id=' . $id . '">Plan MTC (5 sesiones)</a>';
    }
    return '';
}

if (db_ready()) {
    mtc_schema();
}
