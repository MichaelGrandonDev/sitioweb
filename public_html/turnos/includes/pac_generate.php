<?php

declare(strict_types=1);

/**
 * Borrador de evaluación MTC para un paciente: resumen de historia clínica, patrones probables con justificación,
 * principio, meridianos, las cinco técnicas sin agujas (tuina, chi kung, moxa, ventosas, auriculoterapia) y plan de sesiones,
 * citando la Biblioteca MTC y los protocolos. La glosodiagnosis es la base; el pulso es opcional.
 * Sin IA: reglas (protocolos) + pasajes de la biblioteca. Con IA: caso ANONIMIZADO + esos mismos pasajes.
 * Requiere includes/pacientes.php.
 */

const PAC_TOTAL = 25;
const PAC_BLOCK = 5;
const PAC_AI_TIMEOUT = 50;
const PAC_WEB_UA = 'FluxusTerapia/1.0 (https://fluxusterapia.com)';
const PAC_GEMINI_MODELS = ['gemini-3.8-flash', 'gemini-3.7-flash', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-flash-latest'];
const PAC_OPENAI_MODELS = ['gpt-4.1-mini', 'gpt-5-mini', 'gpt-4o-mini'];

/* ---------- Claves de IA (nunca se muestran) ---------- */

/** Clave de IA en uso: la cargada en Pacientes › Ajustes, la de turnos/config.php o, si no, la de Academia. */
function pac_ai_key(string $provider): array
{
    global $config;
    $k = $provider === 'openai' ? 'openai_api_key' : 'gemini_api_key';
    $own = trim(pac_setting($k));
    if ($own !== '') {
        return [$own, 'pacientes'];
    }
    $cfg = trim((string) ($config[$k] ?? ''));
    if ($cfg !== '') {
        return [$cfg, 'config'];
    }
    $academia = pac_academia_keys();
    return [$academia[$k] ?? '', ($academia[$k] ?? '') !== '' ? 'academia' : ''];
}

/** Lectura opcional (solo lectura) de las claves del generador de clases de Academia, si existe en el servidor. */
function pac_academia_keys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $keys = [];
    $file = __DIR__ . '/../../academia/fluxus/config.php';
    if (!is_file($file)) {
        return $keys;
    }
    try {
        $c = (static fn () => include $file)();
        if (!is_array($c)) {
            return $keys;
        }
        foreach (['gemini_api_key', 'openai_api_key'] as $k) {
            $keys[$k] = trim((string) ($c[$k] ?? ''));
        }
        $db = (string) ($c['db_path'] ?? '');
        if ($db !== '' && is_file($db)) {
            $pdo = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
            foreach (['gemini_api_key', 'openai_api_key'] as $k) {
                $stmt->execute([$k]);
                $v = trim((string) $stmt->fetchColumn());
                if ($v !== '') {
                    $keys[$k] = $v;
                }
            }
        }
    } catch (Throwable) {
        // Sin Academia o sin permiso de lectura: se sigue sin esa clave.
    }
    return $keys;
}

/** Proveedores con clave, en orden de preferencia. */
function pac_ai_providers(): array
{
    $pref = pac_setting('ai_provider', 'auto');
    $order = $pref === 'openai' ? ['openai', 'gemini'] : ['gemini', 'openai'];
    return array_values(array_filter($order, static fn ($p) => pac_ai_key($p)[0] !== ''));
}

function pac_ai_available(): bool
{
    return pac_ai_providers() !== [];
}

function pac_ai_label(string $provider): string
{
    return $provider === 'openai' ? 'ChatGPT (OpenAI)' : 'Gemini (Google)';
}

function pac_key_problem(string $provider, string $key): string
{
    if ($key === '') {
        return '';
    }
    if (preg_match('/\s/', $key)) {
        return 'La clave tiene espacios o saltos de línea: copiala de nuevo, completa.';
    }
    if ($provider === 'gemini' && str_starts_with($key, 'sk-')) {
        return 'Esa clave es de ChatGPT (OpenAI), no de Gemini.';
    }
    if ($provider === 'openai' && (str_starts_with($key, 'AIza') || str_starts_with($key, 'AQ.'))) {
        return 'Esa clave es de Gemini (Google), no de ChatGPT.';
    }
    return strlen($key) < 20 ? 'La clave es demasiado corta: copiala completa.' : '';
}

/**
 * Consulta a la IA pidiendo un objeto JSON. Prueba los proveedores con clave y, en Gemini, los modelos de respaldo.
 * @return array{0: array, 1: string} [json, "Proveedor · modelo"]
 */
function pac_ai_json(string $prompt, int $timeout = PAC_AI_TIMEOUT): array
{
    $errors = [];
    $deadline = time() + $timeout;
    foreach (pac_ai_providers() as $provider) {
        [$key] = pac_ai_key($provider);
        $models = $provider === 'openai' ? PAC_OPENAI_MODELS : PAC_GEMINI_MODELS;
        $custom = trim(pac_setting($provider . '_model'));
        if ($custom !== '' && preg_match('/^[a-z0-9.\-:_]{3,80}$/i', $custom)) {
            array_unshift($models, $custom);
        }
        foreach (array_values(array_unique($models)) as $model) {
            $left = $deadline - time();
            if ($left < 8) {
                break 2;
            }
            try {
                return [pac_ai_call($provider, $key, $model, $prompt, $left), pac_ai_label($provider) . ' · ' . $model];
            } catch (RuntimeException $e) {
                $errors[] = pac_ai_label($provider) . ': ' . $e->getMessage();
                if ($e->getCode() !== 1) {
                    continue 2;
                }
            }
        }
    }
    throw new RuntimeException($errors ? implode(' · ', array_slice(array_unique($errors), -2)) : 'No hay clave de IA configurada.');
}

/** Código de error 1 = modelo no disponible (probar el siguiente). */
function pac_ai_call(string $provider, string $key, string $model, string $prompt, int $timeout): array
{
    if ($provider === 'openai') {
        $url = 'https://api.openai.com/v1/chat/completions';
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Sos un asistente para terapeutas de Medicina Tradicional China. Respondé siempre con un único objeto JSON válido.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];
        if (!preg_match('/^(?:o\d|gpt-[5-9])/i', $model)) {
            $body['temperature'] = 0.2;
        }
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    } else {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $gen = ['responseMimeType' => 'application/json'];
        if (preg_match('/^gemini-(?:1\.|2\.)/i', $model)) {
            $gen['temperature'] = 0.2;
        } else {
            $gen['thinkingConfig'] = ['thinkingLevel' => 'low'];
        }
        $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => $gen];
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $key];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_TIMEOUT => max(5, $timeout),
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        throw new RuntimeException('tardó demasiado en responder');
    }
    if ($status === 0) {
        throw new RuntimeException('no se pudo conectar');
    }
    $data = json_decode($raw, true);
    if ($status !== 200) {
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        if ($status === 404 || ($status === 400 && preg_match('/model|thinking/i', $msg) && !preg_match('/api key/i', $msg))) {
            throw new RuntimeException('el modelo «' . $model . '» no está disponible', 1);
        }
        throw new RuntimeException(match (true) {
            $status === 401, $status === 403, $status === 400 && preg_match('/api key|API_KEY/i', $msg) => 'la clave no es válida o no tiene permiso',
            $status === 429 => 'se alcanzó el límite de uso de la clave (probá en un minuto o revisá la cuota)',
            $status >= 500 => 'problema temporal del servicio (' . $status . ')',
            default => 'respondió ' . $status,
        });
    }
    $text = '';
    if ($provider === 'openai') {
        $text = (string) ($data['choices'][0]['message']['content'] ?? '');
    } else {
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (empty($part['thought'])) {
                $text .= (string) ($part['text'] ?? '');
            }
        }
    }
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)) ?? $text);
    $json = json_decode($text, true);
    if (!is_array($json) && preg_match('/\{.*\}/s', $text, $m)) {
        $json = json_decode($m[0], true);
    }
    if (!is_array($json)) {
        throw new RuntimeException('no devolvió un formato válido');
    }
    return $json;
}

/* ---------- Caso clínico ---------- */

/**
 * Caso clínico (sin datos identificatorios): glosodiagnosis (base), información del paciente, historia y, si se cargó, pulso.
 * $input: lengua, interrogatorio, pulso, indicaciones (+ foto: bool).
 */
function pac_case(array $p, array $history, array $input): array
{
    $recent = array_slice($history, 0, 5);
    $histText = [];
    foreach ($recent as $e) {
        $line = trim(implode(' — ', array_filter([trim((string) $e['motivo']), pac_trim(trim((string) $e['evolucion']), 600)])));
        if ($line !== '') {
            $histText[] = $e['fecha'] . ': ' . $line;
        }
    }
    $antecedentes = array_filter([
        'Enfermedades' => trim((string) $p['enfermedades']),
        'Cirugías' => trim((string) $p['cirugias']),
        'Medicación' => trim((string) $p['medicacion']),
        'Alergias' => trim((string) $p['alergias']),
        'Otros riesgos' => trim((string) $p['otros_riesgos']),
    ]);
    $flags = array_map(static fn ($f) => PAC_FLAGS[$f], pac_flags($p));
    return [
        'edad' => pac_age_range($p),
        'sexo' => PAC_SEXES[(string) $p['sexo']] ?? 'Sin indicar',
        'ocupacion' => trim((string) $p['ocupacion']),
        'antecedentes' => $antecedentes,
        'flags' => $flags,
        'historia' => $histText,
        'lengua' => trim((string) ($input['lengua'] ?? '')),
        'interrogatorio' => trim((string) ($input['interrogatorio'] ?? '')),
        'pulso' => trim((string) ($input['pulso'] ?? '')),
        'indicaciones' => trim((string) ($input['indicaciones'] ?? '')),
        'foto' => !empty($input['foto']),
    ];
}

/** Quita del texto clínico nombre, documento, email y teléfono del paciente si aparecen escritos. */
function pac_anonymize(string $text, array $p): string
{
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? $text;
    $text = preg_replace_callback('/\+?\d[\d\s().\-]{6,}\d/u', static fn ($m) => preg_match_all('/\d/', $m[0]) >= 8
        && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $m[0]) ? '[número]' : $m[0], $text) ?? $text;
    foreach (array_filter(preg_split('/\s+/u', trim((string) $p['nombre'])) ?: [], static fn ($w) => mb_strlen($w) >= 3) as $word) {
        $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', '[paciente]', $text) ?? $text;
    }
    foreach (['documento', 'direccion'] as $f) {
        $v = trim((string) $p[$f]);
        if (mb_strlen($v) >= 4) {
            $text = str_ireplace($v, '[dato personal]', $text);
        }
    }
    return $text;
}

function pac_case_text(array $case, bool $withHistory = true): string
{
    $out = "GLOSODIAGNOSIS (base del diagnóstico):\n" . ($case['lengua'] !== '' ? $case['lengua'] : '(sin registrar)') . "\n";
    if (!empty($case['foto'])) {
        $out .= "(Hay una foto de la lengua adjunta.)\n";
    }
    $out .= "\nINFORMACIÓN DEL PACIENTE:\n";
    $out .= 'Edad: ' . $case['edad'] . '. Sexo: ' . $case['sexo'] . ($case['ocupacion'] !== '' ? '. Ocupación: ' . $case['ocupacion'] : '') . ".\n";
    $out .= ($case['interrogatorio'] !== '' ? $case['interrogatorio'] : 'Interrogatorio: (sin datos)') . "\n";
    foreach ($case['antecedentes'] as $k => $v) {
        $out .= $k . ': ' . $v . "\n";
    }
    if ($case['flags']) {
        $out .= 'Contraindicaciones / condiciones de riesgo: ' . implode(', ', $case['flags']) . "\n";
    }
    if ($withHistory && $case['historia']) {
        $out .= "Historia clínica reciente:\n- " . implode("\n- ", $case['historia']) . "\n";
    }
    if ($case['pulso'] !== '') {
        $out .= 'Pulso (opcional, peso menor que la lengua): ' . $case['pulso'] . "\n";
    }
    if (($case['indicaciones'] ?? '') !== '') {
        $out .= 'Indicaciones del terapeuta: ' . $case['indicaciones'] . "\n";
    }
    return $out;
}

/* ---------- Coincidencia con protocolos ---------- */

/** Indicadores de un campo del protocolo ("pálida, marcas dentales") → frases con sus raíces. */
function pac_indicators(string $list): array
{
    $out = [];
    foreach (preg_split('/[,;\n]+/u', $list) ?: [] as $phrase) {
        $phrase = trim($phrase, " .\t");
        if ($phrase === '') {
            continue;
        }
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', pac_fold($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [],
            static fn ($w) => mb_strlen($w) >= 3 && !in_array($w, PAC_STOPWORDS, true) && !in_array($w, ['color', 'normal', 'levemente', 'segun'], true)));
        if ($words) {
            $out[] = ['label' => $phrase, 'stems' => array_map('pac_stem', $words)];
        }
    }
    return $out;
}

function pac_indicator_hit(array $ind, string $folded): bool
{
    foreach ($ind['stems'] as $stem) {
        if (!preg_match('/(?<![\p{L}])' . preg_quote($stem, '/') . '/u', $folded)) {
            return false;
        }
    }
    return true;
}

/**
 * Todos los protocolos con alguna coincidencia. La lengua pesa 3, los datos del paciente 2 y el pulso 1 (solo si se cargó).
 * @return list<array{protocol: array, score: int, hits: array{signos: list<string>, lengua: list<string>, pulso: list<string>}, missing: list<string>}>
 */
/** Signos de lengua opuestos: [regex en el protocolo, regex en la lengua del caso, parte del caso ('cuerpo'|'toda'), texto]. */
const PAC_TONGUE_OPPOSITES = [
    ['/\bpalid/u', '/\broja\b(?!\s+en\s+la\s+punta)|\brojo oscuro|\bcarmesi/u', 'cuerpo', 'lengua roja (el patrón espera pálida)'],
    ['/(?<!bordes )\broja\b(?!\s+en\s+la\s+punta)|\brojo oscuro|\bcarmesi/u', '/\bpalid/u', 'cuerpo', 'lengua pálida (el patrón espera roja)'],
    ['/\bhinchad/u', '/\bfina\b|\bdelgada\b|\bpequena\b/u', 'cuerpo', 'lengua fina (el patrón espera hinchada)'],
    ['/sin saburra|\bpelada/u', '/espesor: gruesa|saburra[^.;\n]*\bgruesa/u', 'toda', 'saburra gruesa (el patrón espera sin saburra)'],
];

/** Zona por órgano («raíz (Riñón)»): ubica el problema pero sola no alcanza para elegir un patrón. */
function pac_is_zone_indicator(string $label): bool
{
    return (bool) preg_match('/^(?:punta|centro|laterales|bordes|raiz)\s*\(/u', pac_fold($label));
}

function pac_rank_protocols(array $case): array
{
    $all = pac_fold($case['interrogatorio'] . "\n" . implode("\n", $case['historia']) . "\n" . implode("\n", $case['antecedentes']));
    $tongue = pac_fold($case['lengua']);
    $body = $tongue !== '' ? (preg_split('/\bsaburra\b/u', $tongue, 2)[0] ?? $tongue) : '';
    $pulse = pac_fold($case['pulso']);
    $out = [];
    foreach (pac_protocols(true) as $proto) {
        $hits = ['signos' => [], 'lengua' => [], 'pulso' => []];
        $missing = [];
        $zones = 0;
        $protoTongue = pac_fold((string) $proto['lengua']);
        foreach (pac_indicators((string) $proto['lengua']) as $ind) {
            $label = pac_fold($ind['label']);
            $hit = in_array($label, ['roja', 'rojo'], true) && $tongue !== ''
                ? (bool) preg_match('/\broja\b(?!\s+en\s+la\s+punta)|\brojo oscuro|\bcarmesi/u', $body)
                : pac_indicator_hit($ind, $tongue !== '' ? $tongue : $all);
            if ($hit) {
                $hits['lengua'][] = $ind['label'];
                $zones += pac_is_zone_indicator($ind['label']) ? 1 : 0;
            } else {
                $missing[] = $ind['label'];
            }
        }
        $against = [];
        if ($tongue !== '') {
            foreach (PAC_TONGUE_OPPOSITES as [$protoRe, $caseRe, $part, $text]) {
                if (preg_match($protoRe, $protoTongue) && preg_match($caseRe, $part === 'cuerpo' ? $body : $tongue)) {
                    $against[] = $text;
                }
            }
        }
        foreach (pac_indicators((string) $proto['signos']) as $ind) {
            if (pac_indicator_hit($ind, $all)) {
                $hits['signos'][] = $ind['label'];
            }
        }
        if ($pulse !== '') {
            foreach (pac_indicators((string) $proto['pulso']) as $ind) {
                if (pac_indicator_hit($ind, $pulse)) {
                    $hits['pulso'][] = $ind['label'];
                }
            }
        }
        $score = (count($hits['lengua']) - $zones) * 3 + $zones + count($hits['signos']) * 2 + count($hits['pulso']) - 4 * count($against);
        if ($hits['lengua'] || $hits['signos'] || $hits['pulso']) {
            $out[] = ['protocol' => $proto, 'score' => $score, 'hits' => $hits, 'missing' => array_merge(array_map(static fn ($a) => 'contradice: ' . $a, $against), $missing)];
        }
    }
    usort($out, static fn ($a, $b) => [$b['score'], count($b['hits']['lengua'])] <=> [$a['score'], count($a['hits']['lengua'])]);
    return $out;
}

/** Patrones probables (hasta 3, con puntaje suficiente). */
function pac_match_protocols(array $case, int $max = 3, ?array $ranked = null): array
{
    $ranked ??= pac_rank_protocols($case);
    return array_slice(array_values(array_filter($ranked, static fn ($m) => $m['score'] >= 3)), 0, $max);
}

/** Patrones considerados y descartados (diagnóstico diferencial). */
function pac_differentials(array $ranked, array $matches, int $max = 4): array
{
    $chosen = array_map(static fn ($m) => (int) $m['protocol']['id'], $matches);
    return array_slice(array_values(array_filter($ranked, static fn ($m) => !in_array((int) $m['protocol']['id'], $chosen, true))), 0, $max);
}

/* ---------- Fuentes ---------- */

/** Búsquedas por técnica en la biblioteca (una cita por técnica si existe). */
const PAC_TECHNIQUE_QUERIES = [
    'tuina' => ['tuina masaje maniobras amasado acupresion', '/tui ?na/'],
    'chikung' => ['qigong kung duan liu respiracion', '/qi ?gong|chi ?kung|ba duan jin|liu zi jue/'],
    'moxibustion' => ['moxibustion moxa jengibre', '/moxa|moxibust/'],
    'ventosas' => ['ventosas ventosaterapia', '/ventosa/'],
    'auriculoterapia' => ['auriculoterapia auricular oreja shenmen', '/auricul|oreja/'],
];

/**
 * Pasajes de la biblioteca: lengua (glosodiagnosis), patrones elegidos, datos del caso y técnicas. Cada fuente tiene id F1, F2…
 * @return list<array{id:string, cite:string, excerpt:string, for:string, page_id:int, link:?string}>
 */
function pac_gather_sources(array $case, array $matches, int $perPattern = 2, int $general = 2): array
{
    $sources = [];
    $used = [];
    $add = static function (array $hits, string $for, int $max) use (&$sources, &$used): void {
        $n = 0;
        foreach ($hits as $hit) {
            if ($n >= $max || isset($used[$hit['id']])) {
                continue;
            }
            $used[$hit['id']] = true;
            $sources[] = [
                'id' => 'F' . (count($sources) + 1),
                'cite' => pac_cite($hit),
                'excerpt' => pac_excerpt((string) $hit['text'], $hit['stems'], 520),
                'for' => $for,
                'page_id' => $hit['id'],
                'link' => pac_reader_link((string) ($hit['doc_sha'] ?? ''), (string) ($hit['doc_file'] ?? ''), (int) $hit['page_no']),
            ];
            $n++;
        }
    };
    if ($case['lengua'] !== '') {
        $add(pac_search(array_merge(['lengua', 'saburra'], pac_terms($case['lengua'], 12)), 8), 'glosodiagnosis', 2);
    }
    foreach ($matches as $m) {
        $p = $m['protocol'];
        $terms = pac_terms($p['nombre'] . ' ' . implode(' ', array_merge($m['hits']['lengua'], $m['hits']['signos'], $m['hits']['pulso'])), 14);
        $add(pac_search($terms, 6, array_keys($used)), (string) $p['nombre'], $perPattern);
    }
    $caseTerms = pac_terms($case['interrogatorio'], 14);
    if ($caseTerms) {
        $add(pac_search($caseTerms, 8, array_keys($used)), 'caso', $general);
    }
    $main = $matches[0]['protocol']['nombre'] ?? '';
    foreach (PAC_TECHNIQUE_QUERIES as $tech => [$q, $must]) {
        $hits = array_values(array_filter(pac_search(pac_terms($q . ' ' . $main, 10), 6, array_keys($used)),
            static fn ($h) => preg_match($must . 'u', pac_fold((string) $h['text'])) === 1));
        $add($hits, 'tec:' . $tech, 1);
    }
    return $sources;
}

/* ---------- Internet (opcional, sin claves) ---------- */

function pac_web_get(array $urls, int $timeout = 10): array
{
    $pdo = db();
    $out = [];
    $pending = [];
    $get = $pdo->prepare('SELECT body FROM pac_web_cache WHERE url_hash = ? AND fetched_at >= ?');
    foreach ($urls as $k => $url) {
        $get->execute([sha1($url), time() - 7 * 86400]);
        $body = $get->fetchColumn();
        if ($body !== false) {
            $out[$k] = (string) $body;
        } else {
            $pending[$k] = $url;
        }
    }
    if ($pending) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($pending as $k => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_USERAGENT => PAC_WEB_UA,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_ENCODING => '',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$k] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        $put = $pdo->prepare('INSERT INTO pac_web_cache (url_hash, body, fetched_at) VALUES (?, ?, ?) ON CONFLICT(url_hash) DO UPDATE SET body = excluded.body, fetched_at = excluded.fetched_at');
        foreach ($handles as $k => $ch) {
            $body = (string) curl_multi_getcontent($ch);
            if ((int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $body !== '' && strlen($body) < 2_000_000) {
                $out[$k] = $body;
                $put->execute([sha1($pending[$k]), $body, time()]);
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
    }
    return $out;
}

/** Términos en inglés para buscar artículos (OpenAlex) a partir de palabras frecuentes del caso. */
function pac_english_terms(string $text): array
{
    $map = [
        'lumbar' => 'low back pain', 'lumbalgia' => 'low back pain', 'cervical' => 'neck pain', 'cervicalgia' => 'neck pain',
        'cefalea' => 'headache', 'migra' => 'migraine', 'insomnio' => 'insomnia', 'ansiedad' => 'anxiety', 'estres' => 'stress',
        'menopausia' => 'menopause hot flashes', 'sofoco' => 'hot flashes', 'dismenorrea' => 'dysmenorrhea', 'menstrual' => 'dysmenorrhea',
        'nausea' => 'nausea', 'rodilla' => 'knee osteoarthritis', 'hombro' => 'shoulder pain', 'fatiga' => 'fatigue', 'cansancio' => 'fatigue',
        'dispepsia' => 'dyspepsia', 'digesti' => 'functional dyspepsia', 'constipa' => 'constipation', 'estreñ' => 'constipation',
        'depresi' => 'depression', 'sobrepeso' => 'obesity', 'obesidad' => 'obesity', 'ciatica' => 'sciatica', 'fibromialgia' => 'fibromyalgia',
        'artrosis' => 'osteoarthritis', 'zumbido' => 'tinnitus', 'tinnitus' => 'tinnitus', 'infertil' => 'infertility', 'alergi' => 'allergic rhinitis',
    ];
    $folded = pac_fold($text);
    $out = [];
    foreach ($map as $es => $en) {
        if (str_contains($folded, $es)) {
            $out[$en] = true;
        }
    }
    return array_slice(array_keys($out), 0, 2);
}

/**
 * Wikipedia en español (patrones) y artículos abiertos de OpenAlex (condición + acupuntura).
 * @return list<array{id:string, cite:string, excerpt:string, url:string}>
 */
function pac_web_sources(array $case, array $matches): array
{
    $urls = [];
    $queries = array_slice(array_map(static fn ($m) => $m['protocol']['nombre'] . ' medicina tradicional china', $matches), 0, 2);
    if (!$queries) {
        $queries[] = 'medicina tradicional china acupuntura';
    }
    foreach ($queries as $i => $q) {
        $urls['w' . $i] = 'https://es.wikipedia.org/w/api.php?' . http_build_query([
            'action' => 'query', 'list' => 'search', 'srsearch' => $q, 'srlimit' => 2, 'format' => 'json', 'utf8' => 1,
        ], '', '&', PHP_QUERY_RFC3986);
    }
    foreach (pac_english_terms($case['interrogatorio'] . ' ' . implode(' ', $case['historia'])) as $i => $en) {
        $urls['o' . $i] = 'https://api.openalex.org/works?' . http_build_query([
            'search' => 'acupuncture ' . $en,
            'filter' => 'is_oa:true,has_abstract:true,type:article,from_publication_date:2012-01-01',
            'per-page' => 3,
            'select' => 'id,doi,display_name,publication_year,primary_location,abstract_inverted_index',
            'mailto' => 'hola@fluxusterapia.com',
        ], '', '&', PHP_QUERY_RFC3986);
    }
    $res = pac_web_get($urls, 10);
    $titles = [];
    $out = [];
    foreach ($res as $k => $body) {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            continue;
        }
        if ($k[0] === 'w') {
            foreach ((array) ($json['query']['search'] ?? []) as $r) {
                $t = (string) ($r['title'] ?? '');
                if ($t !== '' && !in_array($t, $titles, true) && count($titles) < 3) {
                    $titles[] = $t;
                }
            }
            continue;
        }
        foreach ((array) ($json['results'] ?? []) as $w) {
            $abstract = pac_openalex_abstract($w['abstract_inverted_index'] ?? null);
            $title = trim(strip_tags((string) ($w['display_name'] ?? '')));
            if ($title === '' || mb_strlen($abstract) < 150) {
                continue;
            }
            $doi = preg_replace('#^https?://(?:dx\.)?doi\.org/#i', '', trim((string) ($w['doi'] ?? ''))) ?? '';
            $journal = (string) ($w['primary_location']['source']['display_name'] ?? '');
            $out[] = [
                'cite' => '«' . pac_trim($title, 120) . '»' . ($journal !== '' ? ', ' . $journal : '') . ' (' . (int) ($w['publication_year'] ?? 0) . ')',
                'excerpt' => pac_trim($abstract, 520),
                'url' => $doi !== '' ? 'https://doi.org/' . $doi : 'https://openalex.org/' . basename((string) ($w['id'] ?? '')),
            ];
        }
    }
    if ($titles) {
        $sum = [];
        foreach ($titles as $i => $t) {
            $sum['s' . $i] = 'https://es.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $t));
        }
        foreach (pac_web_get($sum, 10) as $body) {
            $s = json_decode($body, true);
            if (!is_array($s) || ($s['type'] ?? '') !== 'standard' || mb_strlen((string) ($s['extract'] ?? '')) < 80) {
                continue;
            }
            array_unshift($out, [
                'cite' => '«' . (string) $s['title'] . '», Wikipedia en español',
                'excerpt' => pac_trim(trim((string) $s['extract']), 520),
                'url' => (string) ($s['content_urls']['desktop']['page'] ?? ''),
            ]);
        }
    }
    $out = array_slice($out, 0, 5);
    foreach ($out as $i => &$w) {
        $w['id'] = 'W' . ($i + 1);
    }
    unset($w);
    return $out;
}

function pac_openalex_abstract(mixed $index): string
{
    if (!is_array($index)) {
        return '';
    }
    $words = [];
    foreach ($index as $word => $positions) {
        foreach ((array) $positions as $p) {
            if (is_int($p) && $p >= 0 && $p < 5000) {
                $words[$p] = (string) $word;
            }
        }
    }
    ksort($words);
    return trim(html_entity_decode(strip_tags(implode(' ', $words)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/* ---------- Generación ---------- */

/**
 * Genera el borrador completo (no guarda nada).
 * @param array{lengua:string, interrogatorio:string, pulso?:string, indicaciones?:string, foto?:bool} $input
 */
function pac_generate(array $p, array $input, bool $useAi, bool $withWeb): array
{
    @set_time_limit(120);
    $history = pac_history((int) $p['id']);
    $case = pac_case($p, $history, $input);
    $ranked = pac_rank_protocols($case);
    $matches = pac_match_protocols($case, 4, $ranked);
    $differentials = pac_differentials($ranked, $matches);
    $sources = pac_gather_sources($case, $matches);
    $info = [];
    $web = [];
    if ($withWeb) {
        try {
            $web = pac_web_sources($case, $matches);
            $info[] = $web ? 'Se sumaron ' . count($web) . ' fuentes de internet (citadas aparte).' : 'No se encontraron fuentes de internet útiles para este caso.';
        } catch (Throwable) {
            $info[] = 'No se pudo consultar internet en este momento.';
        }
    }
    $data = pac_generate_rules($p, $case, $matches, $sources, $web, $differentials);
    $mode = 'reglas';
    if ($useAi && pac_ai_available()) {
        try {
            [$ai, $label] = pac_ai_json(pac_ai_prompt($p, $case, $matches, $sources, $web, $differentials));
            $data = pac_cursor_result_problem($ai) === '' ? pac_cursor_to_data($ai, $data) : pac_merge_ai($data, $ai);
            $mode = 'ia';
            $info[] = 'Redactado con ' . $label . ' a partir de la biblioteca y los protocolos (caso enviado sin nombre, documento ni contacto).';
        } catch (RuntimeException $e) {
            $info[] = 'La IA no respondió (' . $e->getMessage() . '). Se generó sin IA, con protocolos y biblioteca.';
        }
    } elseif ($useAi) {
        $info[] = 'No hay clave de IA configurada: se generó sin IA, con protocolos y biblioteca.';
    }
    $data['lengua'] = $case['lengua'];
    $data['interrogatorio'] = $case['interrogatorio'];
    $data['pulso'] = $case['pulso'];
    $filters = [];
    $data = pac_apply_safety($p, $data, $filters);
    if ($case['lengua'] === '') {
        $info[] = 'Falta la glosodiagnosis: es la base del diagnóstico. Completá cuerpo, saburra y zonas (tocá «Volver»).';
    }
    if (!$matches) {
        $info[] = 'Ningún protocolo coincidió claramente con la lengua y los datos del paciente: completalos o escribí el diagnóstico a mano.';
    }
    if (!$sources) {
        $info[] = pac_library_stats()['ready'] > 0 ? 'La biblioteca no tiene pasajes que coincidan con este caso.' : 'La Biblioteca MTC está vacía: importá o subí tus textos para que el borrador los cite.';
    }
    return [
        'data' => $data,
        'mode' => $mode,
        'info' => $info,
        'applied' => pac_applied($matches, $sources, $web, $filters, $differentials),
        'case' => $case,
        'matches' => $matches,
        'differentials' => $differentials,
        'sources' => $sources,
    ];
}

/** Resumen de lo que se usó para armar el borrador (se muestra en la vista previa). */
function pac_applied(array $matches, array $sources, array $web, array $filters, array $differentials = []): array
{
    $out = ['protocols' => [], 'differentials' => [], 'sources' => [], 'web' => [], 'filters' => $filters, 'citas' => []];
    foreach ($matches as $m) {
        $out['protocols'][] = [
            'nombre' => (string) $m['protocol']['nombre'],
            'score' => (int) $m['score'],
            'lengua' => array_values($m['hits']['lengua']),
            'signos' => array_values($m['hits']['signos']),
            'pulso' => array_values($m['hits']['pulso']),
            'puntos' => (string) $m['protocol']['puntos'],
            'fuente' => (string) $m['protocol']['fuente'],
        ];
    }
    foreach ($differentials as $m) {
        $out['differentials'][] = [
            'nombre' => (string) $m['protocol']['nombre'],
            'score' => (int) $m['score'],
            'lengua' => array_values($m['hits']['lengua']),
            'signos' => array_values($m['hits']['signos']),
            'missing' => array_values($m['missing']),
        ];
    }
    foreach ($sources as $s) {
        $out['sources'][] = ['id' => $s['id'], 'cite' => $s['cite'], 'for' => $s['for'], 'excerpt' => pac_trim((string) $s['excerpt'], 360), 'link' => $s['link'] ?? null];
    }
    foreach ($web as $w) {
        $out['web'][] = ['id' => (string) ($w['id'] ?? ''), 'cite' => (string) ($w['cite'] ?? $w['title'] ?? ''), 'url' => (string) ($w['url'] ?? '')];
    }
    return $out;
}

function pac_sources_for(array $sources, string $for): array
{
    return array_values(array_filter($sources, static fn ($s) => $s['for'] === $for));
}

function pac_refs(array $list): string
{
    return $list ? ' [' . implode(', ', array_map(static fn ($s) => $s['id'], $list)) . ']' : '';
}

function pac_source_label(string $for): string
{
    $tech = ['tuina' => 'tuina', 'chikung' => 'chi kung', 'moxibustion' => 'moxibustión', 'ventosas' => 'ventosas', 'auriculoterapia' => 'auriculoterapia'];
    return match (true) {
        $for === 'caso' => 'por los datos del paciente',
        $for === 'glosodiagnosis' => 'por la lengua (glosodiagnosis)',
        str_starts_with($for, 'tec:') => 'técnica: ' . ($tech[substr($for, 4)] ?? substr($for, 4)),
        default => 'para ' . $for,
    };
}

/**
 * Diagnóstico combinado en una frase, como lo escribe el terapeuta:
 * «Exacerbación del Yang de Hígado, deficiencia de Yin de Bazo, deficiencia de Yin de Corazón.»
 */
function pac_diagnosis_line(array $names): string
{
    $names = array_values(array_filter(array_map(static fn ($n) => trim((string) $n, " \t\n.,;"), $names), static fn ($n) => $n !== ''));
    if (!$names) {
        return '';
    }
    foreach ($names as $i => &$n) {
        if ($i > 0 && preg_match('/^(\p{Lu})(\p{Ll})/u', $n)) {
            $n = mb_strtolower(mb_substr($n, 0, 1)) . mb_substr($n, 1);
        }
    }
    unset($n);
    return implode(', ', array_unique($names)) . '.';
}

/** Patrones que contraindican la moxa (vacío de Yin, ascenso del Yang, calor o fuego). */
function pac_pattern_no_moxa(string $name): bool
{
    return preg_match('/(?:vac[ií]o|deficiencia|insuficiencia) de yin|yang (?:de h[ií]gado )?(?:ascendente|en ascenso)|(?:ascenso|exacerbaci[oó]n|hiperactividad) del? yang|calor|fuego/iu', $name) === 1;
}

/** Primera frase (para resumir una técnica en el plan de sesiones). */
function pac_first_sentence(string $text, int $max = 150): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (preg_match('/^(.+?[.;])(?:\s|$)/u', $text, $m)) {
        $text = rtrim($m[1], '.;');
    }
    return pac_trim($text, $max);
}

/** Texto de una técnica desde los protocolos, con la cita del protocolo y de la biblioteca. */
function pac_rules_technique(array $matches, string $field, array $sources, string $default): string
{
    $lines = [];
    foreach ($matches as $m) {
        $proto = $m['protocol'];
        $t = trim((string) ($proto[$field] ?? ''));
        if ($t !== '') {
            $lines[] = (count($matches) > 1 ? $proto['nombre'] . ': ' : '') . $t . ' (Protocolo «' . $proto['nombre'] . '»)';
        }
    }
    if (!$lines) {
        $lines[] = $default . ' (sugerencia general)';
    }
    if ($refs = pac_sources_for($sources, 'tec:' . $field)) {
        $lines[] = 'Ver en tu biblioteca' . pac_refs($refs) . '.';
    }
    return implode("\n", count($lines) > 1 ? array_map(static fn ($l) => '- ' . $l, $lines) : $lines);
}

/** Borrador sin IA: glosodiagnosis + datos del paciente → patrones combinados → cinco técnicas sin agujas, con citas. */
function pac_generate_rules(array $p, array $case, array $matches, array $sources, array $web, array $differentials = []): array
{
    $protoRef = static fn (array $proto): string => 'Protocolo «' . $proto['nombre'] . '»';
    $protos = array_map(static fn ($m) => $m['protocol'], $matches);
    $main = $protos[0] ?? null;
    $ctx = pac_technique_ctx($p, $case, $protos);
    $ctx['move'] = $main !== null && (bool) preg_match('/picoteo|estancamiento|estasis|mover/iu', $main['nombre'] . ' ' . ($main['moxibustion'] ?? ''));
    $patrones = array_map(static fn ($pr) => (string) $pr['nombre'], $protos);
    $line = pac_diagnosis_line($patrones);

    // Resumen
    $res = [];
    $res[] = ucfirst(PAC_SEXES[(string) $p['sexo']] !== 'Sin indicar' ? mb_strtolower(PAC_SEXES[(string) $p['sexo']]) . ', ' : '') . $case['edad']
        . ($case['ocupacion'] !== '' ? ', ' . $case['ocupacion'] : '') . '.';
    if ($case['interrogatorio'] !== '') {
        $res[] = $case['interrogatorio'];
    }
    foreach ($case['antecedentes'] as $k => $v) {
        $res[] = $k . ': ' . $v;
    }
    if ($case['flags']) {
        $res[] = 'Contraindicaciones a tener en cuenta: ' . implode(', ', $case['flags']) . '.';
    }
    if ($case['historia']) {
        $res[] = "Historia clínica registrada:\n- " . implode("\n- ", $case['historia']);
    }

    // Diagnóstico: frase combinada, lengua, y cada patrón con su justificación
    $diag = [];
    if ($line !== '') {
        $diag[] = 'Diagnóstico desde la MTC: ' . $line;
        $diag[] = '';
    }
    $diag[] = $case['lengua'] !== ''
        ? "Glosodiagnosis (base del diagnóstico):\n" . $case['lengua'] . pac_refs(pac_sources_for($sources, 'glosodiagnosis'))
        : 'Falta la glosodiagnosis (cuerpo, saburra y zonas): el diagnóstico queda sin su base. Completala y volvé a generar.';
    foreach ($matches as $m) {
        $proto = $m['protocol'];
        $diag[] = '';
        $diag[] = $proto['nombre'] . ':';
        $diag[] = $m['hits']['lengua']
            ? '- Lengua: ' . implode(', ', $m['hits']['lengua']) . '.'
            : '- Lengua: no aparece lo esperado (' . $proto['lengua'] . '); se apoya solo en los datos del paciente.';
        if ($m['hits']['signos']) {
            $diag[] = '- Paciente: ' . implode(', ', $m['hits']['signos']) . '.';
        }
        if ($m['hits']['pulso']) {
            $diag[] = '- Pulso (opcional, peso menor): ' . implode(', ', $m['hits']['pulso']) . '.';
        }
        $diag[] = '- Fuente: ' . $protoRef($proto) . ($proto['fuente'] !== '' ? ' (' . $proto['fuente'] . ')' : '') . pac_refs(pac_sources_for($sources, (string) $proto['nombre'])) . '.';
    }
    if (!$matches) {
        $diag[] = '';
        $diag[] = 'Sin patrón claro con la lengua y los datos cargados: completar la glosodiagnosis (color, forma, saburra, zonas) y el interrogatorio (frío/calor, sudoración, digestión, heces y orina, sed, sueño, ánimo, ciclo). (sugerencia general)';
    }
    if (($caseRefs = pac_refs(pac_sources_for($sources, 'caso'))) !== '') {
        $diag[] = '';
        $diag[] = 'Pasajes de la biblioteca relacionados con los síntomas' . $caseRefs . '.';
    }

    // Diferencial
    $dif = [];
    foreach ($differentials as $d) {
        $why = [];
        if ($d['hits']['lengua'] || $d['hits']['signos']) {
            $why[] = 'coincide en ' . implode(', ', array_merge($d['hits']['lengua'], $d['hits']['signos']));
        }
        $contra = array_values(array_filter($d['missing'], static fn ($x) => str_starts_with($x, 'contradice: ')));
        $lack = array_values(array_diff($d['missing'], $contra));
        if ($contra) {
            $why[] = 'pero la lengua lo contradice (' . implode('; ', array_map(static fn ($x) => substr($x, 12), $contra)) . ')';
        }
        if ($lack) {
            $why[] = ($contra ? 'y faltan' : 'pero faltan') . ' los signos de lengua esperados: ' . implode(', ', array_slice($lack, 0, 5));
        }
        if (!$d['missing']) {
            $why[] = 'pero tiene menos coincidencias que los patrones elegidos';
        }
        $dif[] = '- ' . $d['protocol']['nombre'] . ': ' . implode(', ', $why) . ' (' . $protoRef($d['protocol']) . ').';
    }
    if (!$dif) {
        $dif[] = $matches ? 'No hubo otros patrones de tus protocolos con coincidencias en la lengua o los datos del paciente.' : 'Sin datos suficientes para comparar patrones.';
    }

    // Principio, meridianos, recomendaciones, notas
    $principio = $meridianos = $recs = $notes = [];
    foreach ($protos as $proto) {
        $ref = pac_refs(pac_sources_for($sources, (string) $proto['nombre']));
        if ($proto['principio'] !== '') {
            $principio[] = '- ' . $proto['principio'] . ' (' . $protoRef($proto) . $ref . ')';
        }
        if ($proto['meridianos'] !== '') {
            $meridianos[] = '- ' . $proto['meridianos'] . ' (' . $protoRef($proto) . ')';
        }
        if ($proto['recomendaciones'] !== '') {
            $recs[] = '- ' . $proto['recomendaciones'];
        }
        if (trim((string) $proto['tecnicas']) !== '') {
            $notes[] = '- ' . $proto['tecnicas'] . ' (' . $protoRef($proto) . ')';
        }
    }
    if (!$protos) {
        $principio[] = '- Tonificación general y equilibrio hasta completar el diagnóstico. (sugerencia general)';
    }

    // Conflictos entre patrones combinados (p. ej. uno pide moxa y otro la contraindica)
    $noMoxa = array_values(array_filter($patrones, 'pac_pattern_no_moxa'));
    $wantsMoxa = array_values(array_filter($protos, static fn ($pr) => !pac_pattern_no_moxa((string) $pr['nombre'])
        && preg_match('/central|calentar|tonificante/iu', (string) ($pr['moxibustion'] ?? ''))));
    if ($noMoxa && !$ctx['heat']) {
        $ctx['heat'] = 'patrón ' . implode(' y ', $noMoxa);
    }

    // Moxibustión: puntos para moxar
    $codes = [];
    $extras = [];
    foreach ($protos as $proto) {
        foreach (pac_point_codes((string) $proto['puntos']) as $c) {
            $codes[$c] ??= $proto['nombre'];
        }
        if (preg_match('/ashi|zona del dolor/iu', (string) $proto['puntos'])) {
            $extras[] = $proto;
        }
    }
    if (!$protos) {
        $codes = ['E36' => 'tonificación general', 'RM6' => 'tonificación general'];
    }
    $codes = array_slice($codes, 0, 9, true);
    $moxa = [];
    if ($ctx['heat'] !== '') {
        $moxa[] = 'No moxar por ahora (' . $ctx['heat'] . '). Los puntos se trabajan con acupresión dentro del tuina:';
    }
    $moxable = [];
    foreach ($codes as $code => $from) {
        $row = pac_moxa_point($code, $ctx);
        $moxa[] = pac_moxa_line($row);
        if ($row['metodo'] !== '') {
            $moxable[] = $code;
        }
    }
    foreach ($extras as $proto) {
        $moxa[] = '- Puntos Ashi / zona del dolor: ' . ($ctx['heat'] !== '' ? 'acupresión' : 'bastón en picoteo 5–10 min si el dolor mejora con calor; no sobre inflamación aguda') . ' (' . $protoRef($proto) . ')';
    }
    foreach ($protos as $proto) {
        if (trim((string) ($proto['moxibustion'] ?? '')) !== '') {
            $moxa[] = 'Según el protocolo «' . $proto['nombre'] . '»: ' . $proto['moxibustion'];
        }
    }
    if ($refs = pac_sources_for($sources, 'tec:moxibustion')) {
        $moxa[] = 'Ver en tu biblioteca' . pac_refs($refs) . '.';
    }
    $moxa[] = PAC_MOXA_CAUTIONS;

    $tuina = pac_rules_technique($matches, 'tuina', $sources, 'Tuina general suave: amasado de espalda y trapecios, frotación abdominal en sentido horario, acupresión en E36 y PC6 (1 min cada uno). 20 min.');
    $chikung = pac_rules_technique($matches, 'chikung', $sources, 'Ba Duan Jin: 1.ª pieza «Sostener el cielo» y respiración abdominal lenta. En sesión 10 min; en casa 10 min por día.');
    $ventosas = pac_rules_technique($matches, 'ventosas', $sources, 'Fijas y suaves en la espalda (V13 a V20) 5 min si hay tensión.')
        . "\nContraindicaciones: piel lesionada, anticoagulantes, fragilidad capilar, fiebre; embarazo: nada en abdomen ni zona lumbar.";
    $auriculo = pac_rules_technique($matches, 'auriculoterapia', $sources, 'Semillas de vaccaria en Shenmen y Punto Cero; alternar oreja cada semana; presionar 3 veces por día.')
        . "\nSin agujas: semillas de vaccaria o balines con cinta; cambiar cada 5–7 días y alternar oreja; retirar si irrita la piel.";

    $tecnicas = array_merge([
        '- Sin agujas: los puntos se trabajan con moxa o con acupresión dentro del tuina; la auriculoterapia es con semillas.',
        '- Orden sugerido en cada sesión: tuina (20–25 min) → moxa → ventosas cuando corresponde → semillas en la oreja → chi kung guiado (10 min).',
    ], $notes);
    if ($noMoxa && $wantsMoxa) {
        $tecnicas[] = '- Conflicto entre patrones: ' . implode(' y ', array_map(static fn ($pr) => $pr['nombre'], $wantsMoxa)) . ' pide moxa, pero '
            . implode(' y ', $noMoxa) . ' la contraindica. Priorizar tuina y acupresión; si se moxa, solo breve, a distancia y lejos de los signos de calor.';
    }
    if ($ctx['heat'] !== '') {
        $tecnicas[] = '- Sin moxa mientras haya signos de calor (' . $ctx['heat'] . '): priorizar tuina, chi kung y aurículo.';
    }
    if ($ctx['diabetes']) {
        $tecnicas[] = '- Diabetes o neuropatía: moxa con más distancia y menos tiempo, revisar la piel; ventosas suaves.';
    }

    // Plan de sesiones (técnicas por sesión)
    $mainName = $line !== '' ? rtrim($line, '.') : 'patrón a definir';
    $mainTuina = $main && trim((string) $main['tuina']) !== '' ? pac_first_sentence((string) $main['tuina']) : 'tuina del patrón';
    $mainCups = $main && trim((string) $main['ventosas']) !== '' ? pac_first_sentence((string) $main['ventosas'], 110) : 'suaves en la espalda';
    $ear = $main && preg_match('/semillas(?: de vaccaria)? en ([^;.]+)/iu', (string) $main['auriculoterapia'], $em) ? trim($em[1]) : 'Shenmen y Punto Cero';
    $piece = $main && preg_match('/«([^»]+)»/u', (string) $main['chikung'], $cm) ? '«' . $cm[1] . '»' : '«Sostener el cielo»';
    $home = $main && preg_match('/en casa ([^.;]+)/iu', (string) $main['chikung'], $hm) ? trim($hm[1]) : '10–15 min por día';
    $moxShort = $moxable ? pac_points_text(array_slice($moxable, 0, 4)) . ' (según «Puntos para moxar»)' : 'no (acupresión en su lugar)';
    $firstMox = in_array('E36', $moxable, true) ? 'E36 (Zusanli), bastón indirecta 10 min' : ($moxable ? pac_point_label($moxable[0]) . ', según «Puntos para moxar»' : 'no (acupresión en su lugar)');
    $sesiones = [
        'Frecuencia: 1 sesión por semana. Primera etapa: hasta ' . PAC_TOTAL . ' consultas. El diagnóstico se hace solo en la sesión 1.',
        'Sesión 1: Diagnóstico (glosodiagnosis con foto si hay, información del paciente e historia clínica) y tonificación general · Tuina: general suave, 20 min · Chi kung: enseñar ' . $piece . ' y la respiración · Moxa: ' . $firstMox . ' · Ventosas: no en la primera sesión · Aurículo: semillas en Shenmen y Punto Cero, oreja derecha',
        'Sesión 2: Tratamiento de ' . $mainName . ' · Tuina: ' . $mainTuina . ' · Chi kung: revisar la práctica en casa · Moxa: ' . $moxShort . ' · Ventosas: ' . $mainCups . ' · Aurículo: ' . $ear . ', oreja izquierda',
        'Sesión 3: Tratamiento de ' . $mainName . ' · Tuina: ' . $mainTuina . ' · Chi kung: sumar el sonido Liu Zi Jue del órgano · Moxa: ' . $moxShort . ' · Ventosas: no (descanso de la piel) · Aurículo: renovar, oreja derecha',
        'Sesión 4: Tratamiento de ' . $mainName . ' · Tuina: ' . $mainTuina . ' · Chi kung: rutina completa · Moxa: ' . $moxShort . ' · Ventosas: ' . $mainCups . ' · Aurículo: renovar, oreja izquierda',
        'Sesión ' . PAC_BLOCK . ': Evaluación del bloque (continuar, alta o derivación médica) · Tuina: ' . $mainTuina . ' · Chi kung: ajustar la rutina de casa · Moxa: ' . $moxShort . ' · Ventosas: solo si hace falta · Aurículo: renovar, oreja derecha',
        'Sesiones ' . (PAC_BLOCK + 1) . ' a ' . PAC_TOTAL . ': Controles semanales sin nuevo diagnóstico · Tuina y moxa según la evolución · Ventosas cada 2 semanas si hace falta · Aurículo semanal alternando oreja · Chi kung en casa',
        'En casa: chi kung ' . $home . '; presionar las semillas 3–5 veces por día, 1 minuto por punto.',
    ];
    if ($main && trim((string) $main['sesiones']) !== '') {
        $sesiones[] = 'Según el protocolo: ' . $main['sesiones'] . ' (' . $protoRef($main) . ')';
    }

    $pacTxt = [];
    if ($line !== '') {
        $pacTxt[] = 'Según la evaluación desde la Medicina Tradicional China (sobre todo lo que muestra tu lengua y lo que nos contaste), trabajaremos sobre: ' . $line;
    }
    $pacTxt[] = 'El tratamiento es de 1 sesión por semana, sin agujas: masaje tuina, moxa (calor), ventosas cuando hace falta, semillas en la oreja y ejercicios de chi kung.';
    $pacTxt[] = 'Para practicar en casa: chi kung ' . $home . ', y presionar las semillas de la oreja 3 a 5 veces por día.';
    if ($recs) {
        $pacTxt[] = "Recomendaciones para casa:\n" . implode("\n", $recs);
    }

    return [
        'resumen' => implode("\n", $res),
        'diagnostico' => implode("\n", $diag),
        'diferencial' => implode("\n", $dif),
        'patrones' => $line,
        'principio' => implode("\n", $principio),
        'meridianos' => implode("\n", $meridianos),
        'tuina' => $tuina,
        'chikung' => $chikung,
        'puntos' => implode("\n", $moxa),
        'ventosas' => $ventosas,
        'auriculoterapia' => $auriculo,
        'tecnicas' => implode("\n", $tecnicas),
        'sesiones' => implode("\n", $sesiones),
        'paciente_texto' => implode("\n\n", $pacTxt),
        'fuentes' => pac_sources_text($matches, $sources, $web),
        'avisos' => '',
        'notas' => '',
    ];
}

function pac_sources_text(array $matches, array $sources, array $web): string
{
    $lines = [];
    foreach ($matches as $m) {
        $lines[] = 'Protocolo «' . $m['protocol']['nombre'] . '» — Biblioteca MTC › Protocolos' . ($m['protocol']['fuente'] !== '' ? ' (' . $m['protocol']['fuente'] . ')' : '');
    }
    foreach ($sources as $s) {
        $lines[] = '[' . $s['id'] . '] ' . $s['cite'] . ' (' . pac_source_label($s['for']) . ') — «' . $s['excerpt'] . '»' . (!empty($s['link']) ? ' → ' . $s['link'] : '');
    }
    if ($web) {
        $lines[] = '';
        $lines[] = 'Fuentes de internet (no revisadas por el terapeuta; solo referencia):';
        foreach ($web as $w) {
            $lines[] = '[' . $w['id'] . '] ' . $w['cite'] . ($w['url'] !== '' ? ' — ' . $w['url'] : '') . ' — «' . $w['excerpt'] . '»';
        }
    }
    return implode("\n", $lines);
}

/* ---------- Reglas comunes para Cursor y para la IA de respaldo ---------- */

/** Esquema JSON de la respuesta (el mismo que usa el puente de Cursor, tools/cursor_bridge/bridge.py). */
const PAC_RESULT_SCHEMA = <<<'JSON'
{
  "resumen": "resumen de la historia clínica (3-6 frases)",
  "glosodiagnosis": "lectura de la lengua signo por signo y por zonas, con citas [C1]",
  "diagnostico_mtc": {
    "texto": "una sola frase con todos los patrones combinados, p. ej.: Exacerbación del Yang de Hígado, deficiencia de Yin de Bazo, deficiencia de Yin de Corazón.",
    "patrones": [{"nombre": "patrón MTC", "justificacion_lengua": "signos concretos de la lengua de este caso que lo sostienen", "justificacion_paciente": "datos concretos del interrogatorio, antecedentes o historia", "pulso": "solo si se cargó; si no, vacío", "citas": ["C1"]}]
  },
  "diferenciales": [{"nombre": "patrón considerado", "por_que_no": "qué signo de lengua o dato falta o contradice", "citas": ["C2"]}],
  "principio": "principio de tratamiento (si hay varios patrones, cómo se combinan y qué se prioriza)",
  "meridianos": ["meridiano"],
  "tecnicas": {
    "tuina": {"maniobras": "…", "zonas": "zonas y meridianos", "acupresion": ["E36 (Zusanli)"], "duracion": "20-25 min", "citas": ["C3"]},
    "chikung": {"ba_duan_jin": "pieza(s) y repeticiones", "respiracion": "…", "liu_zi_jue": "sonido del órgano", "en_sesion": "10 min", "en_casa": "minutos por día", "citas": []},
    "moxibustion": {"puntos": [{"punto": "E36 (Zusanli)", "metodo": "bastón indirecta / picoteo / caja / cono sobre jengibre", "tiempo": "10-15 min", "precaucion": "…", "alternativa": "si no conviene moxar: alternativa o acupresión en el tuina"}], "notas": "…", "citas": []},
    "ventosas": {"zonas": "…", "tipo": "fija / deslizante / rápida", "tiempo": "…", "contraindicaciones": "…", "citas": []},
    "auriculoterapia": {"puntos": ["Shenmen", "Punto Cero"], "material": "semillas de vaccaria o balines (sin agujas)", "presion_en_casa": "veces por día", "oreja": "alternar cada semana", "citas": []}
  },
  "conflictos": ["p. ej.: un patrón pide moxa y otro (vacío de Yin / ascenso del Yang) la contraindica: qué se hace"],
  "sesiones": [
    {"sesion": "1", "objetivo": "diagnóstico + tonificación general", "tuina": "…", "chikung": "…", "moxibustion": "…", "ventosas": "…", "auriculoterapia": "…"},
    {"sesion": "2", "objetivo": "…", "tuina": "…", "chikung": "…", "moxibustion": "…", "ventosas": "…", "auriculoterapia": "…"},
    {"sesion": "5", "objetivo": "evaluación de la primera etapa", "tuina": "…", "chikung": "…", "moxibustion": "…", "ventosas": "…", "auriculoterapia": "…"},
    {"sesion": "6-25", "objetivo": "controles semanales", "tuina": "…", "chikung": "…", "moxibustion": "…", "ventosas": "…", "auriculoterapia": "…"}
  ],
  "precauciones": ["contraindicaciones y cuidados de este caso"],
  "texto_paciente": "explicación sencilla, práctica de chi kung en casa y cómo presionar las semillas, sin códigos de puntos",
  "citas": [{"id": "C1", "documento": "título", "archivo": "textos/xxx.txt", "pagina": "123", "cita": "frase textual corta"}],
  "sugerencias_generales": ["lo que no sale de las fuentes"]
}
JSON;

/** Reglas clínicas del pedido (las mismas en el puente de Cursor). */
function pac_result_rules(array $p): string
{
    $rules = [
        'La GLOSODIAGNOSIS es la base: justificá cada patrón primero con signos concretos de la lengua de este caso (cuerpo, saburra, zonas) y después con los datos del paciente. El pulso es opcional: usalo solo si está cargado y con menos peso.',
        'El diagnóstico suele combinar varios patrones: escribilos juntos en una sola frase («diagnostico_mtc.texto») y justificá cada uno por separado.',
        'Incluí el diagnóstico diferencial: qué otros patrones consideraste y qué signo de lengua o dato falta o los contradice.',
        'Citá cada afirmación con la fuente: documento + página. Si algo no surge de las fuentes, marcalo «(sugerencia general)».',
        'SIN AGUJAS: no prescribas acupuntura, electroacupuntura ni punturas. Los puntos van como «puntos para moxar» (método: bastón indirecta o picoteo, caja, o cono sobre jengibre; tiempo; precaución) o, si no conviene moxar, con alternativa o acupresión en el tuina.',
        'Todo plan integra las cinco técnicas con contenido concreto para los patrones: tuina (maniobras, zonas/meridianos, acupresión, duración), chi kung (pieza del Ba Duan Jin, respiración, sonido Liu Zi Jue del órgano, minutos en sesión y en casa), moxibustión, ventosas (zonas, fija/deslizante/rápida, tiempo, contraindicaciones) y auriculoterapia (Shenmen, Simpático, Punto Cero, órganos, Endocrino, Subcórtex o zona del dolor; semillas de vaccaria o balines, sin agujas; frecuencia de presión en casa; alternar oreja).',
        'Moxa: no con fiebre, calor, vacío de Yin o ascenso del Yang; evitar cara y ojos, mucosas, heridas, várices y zonas sin sensibilidad; diabetes o neuropatía con precaución. Si los patrones combinados se contradicen (uno pide moxa, otro la contraindica), explicalo en «conflictos». Ventosas: no sobre piel lesionada, con anticoagulantes, fragilidad capilar o fiebre.',
        'Plan de sesiones: 1 por semana; sesión 1 = diagnóstico (único de la etapa) + tonificación general; sesiones 2 a 5 = tratamiento; sesión 5 = evaluación (continuar, alta o derivación); sesiones 6 a 25 = controles semanales. Distribuí las cinco técnicas por sesión.',
    ];
    if ((int) $p['embarazo'] === 1) {
        $rules[] = 'EMBARAZO: nada de moxa ni ventosas en abdomen o zona lumbosacra; no usar ' . implode(', ', PAC_PREGNANCY_AVOID) . ' (ni en moxa ni en acupresión).';
    }
    if ((int) $p['marcapasos'] === 1) {
        $rules[] = 'MARCAPASOS: sin equipos eléctricos.';
    }
    if ((int) $p['anticoagulantes'] === 1) {
        $rules[] = 'ANTICOAGULANTES: sin ventosas ni sangría; tuina y acupresión suaves.';
    }
    return implode("\n", array_map(static fn ($r, $i) => ($i + 1) . '. ' . $r, $rules, array_keys($rules)));
}

function pac_ai_prompt(array $p, array $case, array $matches, array $sources, array $web, array $differentials = []): string
{
    $caseText = pac_anonymize(pac_case_text($case), $p);
    $protos = [];
    foreach (array_merge($matches, $differentials) as $m) {
        $pr = $m['protocol'];
        $protos[] = '- Protocolo «' . $pr['nombre'] . '»: lengua: ' . $pr['lengua'] . ' | síntomas: ' . pac_trim((string) $pr['signos'], 300)
            . ' | principio: ' . $pr['principio'] . ' | puntos para moxar: ' . $pr['puntos'] . ' | moxa: ' . $pr['moxibustion']
            . ' | tuina: ' . $pr['tuina'] . ' | chi kung: ' . $pr['chikung'] . ' | ventosas: ' . $pr['ventosas'] . ' | aurículo: ' . $pr['auriculoterapia'];
    }
    if (!$protos) {
        foreach (pac_protocols(true) as $pr) {
            $protos[] = '- Protocolo «' . $pr['nombre'] . '»: lengua: ' . $pr['lengua'] . ' | síntomas: ' . pac_trim((string) $pr['signos'], 200) . ' | puntos para moxar: ' . $pr['puntos'];
        }
    }
    $src = [];
    foreach ($sources as $s) {
        $src[] = '[' . $s['id'] . '] ' . $s['cite'] . ' (' . pac_source_label($s['for']) . '): ' . $s['excerpt'];
    }
    foreach ($web as $w) {
        $src[] = '[' . $w['id'] . '] (internet, no revisado) ' . $w['cite'] . ': ' . $w['excerpt'];
    }
    return "Sos un asistente para un terapeuta de Medicina Tradicional China (MTC) en Argentina. Escribí en español rioplatense, claro y profesional.\n"
        . "Armá un BORRADOR de diagnóstico y plan terapéutico para que el terapeuta lo revise. La decisión clínica es del terapeuta.\n\n"
        . "REGLAS:\n" . pac_result_rules($p) . "\n"
        . "En «citas» usá los ids de las fuentes de abajo (F1, F2…) como id; no inventes páginas.\n"
        . "\nCASO (anonimizado):\n" . $caseText
        . "\nPROTOCOLOS DEL TERAPEUTA:\n" . ($protos ? implode("\n", $protos) : '(ninguno)') . "\n"
        . "\nFUENTES DE LA BIBLIOTECA" . ($web ? ' E INTERNET' : '') . ":\n" . ($src ? implode("\n", $src) : '(no hay pasajes para este caso)') . "\n"
        . "\nDevolvé SOLO un objeto JSON con esta forma:\n" . PAC_RESULT_SCHEMA;
}

/** Respuesta de IA con otra forma: se usan los campos de texto que traiga. */
function pac_merge_ai(array $data, array $ai): array
{
    foreach (['resumen', 'diagnostico', 'diferencial', 'patrones', 'principio', 'meridianos', 'tuina', 'chikung', 'puntos', 'ventosas', 'auriculoterapia', 'sesiones', 'paciente_texto'] as $k) {
        $v = $ai[$k] ?? null;
        if (is_array($v)) {
            $v = implode("\n", array_map(static fn ($x) => is_scalar($x) ? '- ' . $x : json_encode($x, JSON_UNESCAPED_UNICODE), $v));
        }
        if (is_string($v) && trim($v) !== '') {
            $data[$k] = mb_substr(trim($v), 0, PAC_TEXT_MAX);
        }
    }
    return $data;
}

/* ---------- Seguridad ---------- */

/** Quita frases que indican agujas o punturas. */
function pac_strip_needles(string $text): string
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (!pac_mentions_needles($line)) {
            $out[] = $line;
            continue;
        }
        $parts = preg_split('/(?<=[.;])\s+|\s+·\s+/u', $line) ?: [];
        $keep = array_filter($parts, static fn ($s) => !pac_mentions_needles($s));
        if ($keep) {
            $out[] = implode(' ', $keep);
        }
    }
    return trim(implode("\n", $out));
}

/**
 * Contraindicaciones y reglas fijas sobre cualquier borrador (reglas, Cursor o IA):
 * sin agujas, embarazo, marcapasos, anticoagulantes, calor/vacío de Yin (moxa) y diabetes. Deja avisos visibles.
 */
function pac_apply_safety(array $p, array $data, ?array &$filters = null): array
{
    $notes = [];
    foreach (array_keys(PAC_EVAL_INPUTS + PAC_EVAL_OUTPUTS) as $k) {
        $data[$k] = (string) ($data[$k] ?? '');
    }
    $needles = false;
    foreach (PAC_PLAN_FIELDS as $k) {
        if (pac_mentions_needles($data[$k])) {
            $data[$k] = pac_strip_needles($data[$k]);
            $needles = true;
        }
    }
    if ($needles) {
        $notes[] = 'Se quitaron indicaciones de agujas o punturas: la propuesta es sin agujas (moxa, acupresión, tuina, ventosas y semillas).';
    }
    if ((int) $p['embarazo'] === 1) {
        $removed = [];
        foreach (PAC_PLAN_FIELDS as $k) {
            [$data[$k], $r] = pac_strip_points($data[$k], PAC_PREGNANCY_AVOID);
            $removed = array_merge($removed, $r);
        }
        if ($removed) {
            $notes[] = 'Embarazo: se quitaron del borrador ' . pac_points_text(array_unique($removed)) . '.';
        }
        $data['puntos'] = trim($data['puntos'] . "\nEmbarazo: no moxar abdomen ni zona lumbosacra.");
        $data['ventosas'] = trim($data['ventosas'] . "\nEmbarazo: nada de ventosas en abdomen ni zona lumbosacra.");
    }
    if ((int) $p['marcapasos'] === 1) {
        $hit = false;
        foreach (PAC_PLAN_FIELDS as $k) {
            if (preg_match('/electro|\btens\b/iu', $data[$k])) {
                $data[$k] = trim(preg_replace('/[^\n]*(?:electro|\btens\b)[^\n]*/iu', '', $data[$k]) ?? $data[$k]);
                $hit = true;
            }
        }
        if ($hit) {
            $notes[] = 'Marcapasos: se quitó la estimulación eléctrica del borrador.';
        }
    }
    if ((int) $p['anticoagulantes'] === 1) {
        $data['ventosas'] = 'No aplicar ventosas: el paciente toma anticoagulantes (riesgo de hematomas). En su lugar, tuina suave.';
        $data['sesiones'] = preg_replace('/·\s*Ventosas cada[^·\n]*/u', '', $data['sesiones']) ?? $data['sesiones'];
        $data['sesiones'] = preg_replace('/Ventosas?:\s*[^·\n]*/u', 'Ventosas: no (anticoagulantes)', $data['sesiones']) ?? $data['sesiones'];
        foreach (['tecnicas', 'principio', 'paciente_texto', 'tuina'] as $k) {
            $data[$k] = trim(preg_replace('/[^\n]*sangr[ií]a[^\n]*/iu', '', $data[$k]) ?? $data[$k]);
        }
        $data['paciente_texto'] = str_replace(', ventosas cuando hace falta', '', $data['paciente_texto']);
        $notes[] = 'Anticoagulantes: sin ventosas ni sangría.';
    }
    $heat = pac_heat_reason($data['lengua'], $data['interrogatorio']);
    $noMoxa = array_values(array_filter(preg_split('/\s*,\s*/u', rtrim($data['patrones'], '. ')) ?: [], 'pac_pattern_no_moxa'));
    if ($noMoxa) {
        $heat = trim($heat . ($heat !== '' ? ', ' : '') . 'patrón ' . implode(' y ', $noMoxa));
    }
    if ($heat !== '' && !preg_match('/no moxar/iu', $data['puntos'])) {
        $data['puntos'] = 'Hay signos de calor o de vacío de Yin (' . $heat . '): no moxar mientras persistan; usar acupresión en esos puntos.' . "\n" . $data['puntos'];
        $notes[] = 'Moxa marcada como no indicada (' . $heat . ').';
    }
    if (preg_match('/diabet|neuropat/u', pac_fold($p['enfermedades'] . ' ' . $p['otros_riesgos'] . ' ' . $data['interrogatorio'])) && !preg_match('/diabetes/iu', $data['puntos'])) {
        $data['puntos'] = trim($data['puntos'] . "\nDiabetes o neuropatía: más distancia y menos tiempo, revisar la piel; en pies, mejor acupresión.");
    }
    if (trim($data['puntos']) !== '' && !str_contains($data['puntos'], 'Precauciones de moxa')) {
        $data['puntos'] = trim($data['puntos'] . "\n" . PAC_MOXA_CAUTIONS);
    }
    foreach (PAC_PLAN_FIELDS as $k) {
        $data[$k] = trim(preg_replace("/\n{3,}/", "\n\n", $data[$k]) ?? $data[$k]);
    }
    $extra = trim($data['avisos']);
    $extra = $extra !== '' ? array_values(array_filter(array_map('trim', explode("\n", $extra)), static fn ($l) => $l !== '' && $l !== PAC_DISCLAIMER)) : [];
    $data['avisos'] = implode("\n", array_values(array_unique(array_merge($notes, pac_safety_warnings($p, $data), $extra, [PAC_DISCLAIMER]))));
    $filters = $notes;
    return $data;
}

/* ---------- PDF ---------- */

function pac_pdf_text(FluxusPdf $pdf, string $text, float $size = 10): void
{
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^[-•*]\s*(.+)$/u', $line, $m)) {
            $pdf->richParagraph([[$m[1], false]], '•', 6, 12, $size);
        } else {
            $pdf->richParagraph([[$line, false]], '', 0, 0, $size);
        }
    }
}

function pac_pdf_section(FluxusPdf $pdf, string $title, string $text): void
{
    if (trim($text) === '') {
        return;
    }
    $pdf->keepTogether(70);
    $pdf->heading($title);
    pac_pdf_text($pdf, $text);
}

/**
 * Bloque «Diagnóstico desde la Medicina Tradicional China» con la frase combinada.
 * Usa el bloque del Plan MTC si existe, para que los dos PDF se vean igual.
 */
function pac_pdf_diagnosis_block(FluxusPdf $pdf, string $line): void
{
    $line = trim($line);
    if ($line === '') {
        return;
    }
    foreach (['mtc_pdf_diagnosis_block', 'mtc_pdf_diagnostico'] as $fn) {
        if (function_exists($fn)) {
            $fn($pdf, $line);
            return;
        }
    }
    $pdf->keepTogether(80);
    $pdf->rule();
    $pdf->centered('DIAGNÓSTICO DESDE LA MEDICINA TRADICIONAL CHINA', 12, true);
    $pdf->richParagraph([[$line, false]], '', 0, 0, 11.5);
    $pdf->rule();
}

/** Qué técnicas se usan, explicado para el paciente (sin códigos de puntos). */
function pac_patient_techniques(array $eval): string
{
    $lines = ['- Tuina: masaje terapéutico chino con presión sobre puntos, para mover la energía y relajar.'];
    if (!preg_match('/^\s*(?:Hay signos de calor|No moxar)/iu', (string) $eval['puntos'])) {
        $lines[] = '- Moxibustión: calor suave con moxa (artemisa) cerca de la piel sobre puntos elegidos, sin quemar.';
    }
    if (!preg_match('/^\s*No aplicar ventosas/iu', (string) $eval['ventosas'])) {
        $lines[] = '- Ventosas: copas que hacen una succión suave; pueden dejar marcas redondas que se van en unos días.';
    }
    $lines[] = '- Auriculoterapia: semillas pequeñas pegadas con cinta en puntos de la oreja, sin agujas.';
    $lines[] = '- Chi kung: movimientos lentos con respiración que vas a practicar también en casa.';
    return implode("\n", $lines);
}

/**
 * PDF de la ficha. $audience 'terapeuta' = todo; 'paciente' = diagnóstico combinado, explicación, técnicas,
 * chi kung y semillas en casa (sin notas, lengua ni fuentes).
 */
function pac_pdf(array $p, array $history, ?array $eval, string $audience): string
{
    global $config;
    require_once __DIR__ . '/../pdf_lib.php';
    $pdf = new FluxusPdf();
    $forPatient = $audience === 'paciente';
    $pdf->setFooter('FluxusTerapia · ' . ($forPatient ? 'Plan de tratamiento' : 'Ficha clínica confidencial') . ' · ' . $p['nombre']);
    $pdf->addPage();
    $pdf->title('FluxusTerapia');
    $pdf->subtitle($forPatient ? 'Tu plan de tratamiento · Medicina Tradicional China' : 'Ficha clínica · Medicina Tradicional China (uso interno)');
    if (!empty($eval['preview'])) {
        $pdf->subtitle('VISTA PREVIA - todavía no se guardó');
    }
    $pdf->rule();

    if ($forPatient) {
        $pdf->richParagraph([['Hola, ' . $p['nombre'] . ':', true]], '', 0, 0, 11);
        $pdf->richParagraph([['Te compartimos el plan que armamos a partir de tu evaluación' . ($eval ? ' del ' . format_date_es((string) $eval['fecha']) : '') . '. Lo vamos ajustando semana a semana según cómo te sientas.', false]], '', 0, 0, 10.5);
        if ($eval) {
            pac_pdf_diagnosis_block($pdf, (string) $eval['patrones']);
            pac_pdf_section($pdf, 'Lo que vimos', pac_patient_plain((string) $eval['paciente_texto']));
            pac_pdf_section($pdf, 'Cómo es el tratamiento', '1 sesión por semana, sin agujas. En la primera consulta hacemos la evaluación completa; en las siguientes, un control breve al comenzar y el tratamiento del día. La primera etapa es de hasta ' . PAC_TOTAL . ' consultas; en la consulta ' . PAC_BLOCK . ' vemos juntos cómo seguir.');
            pac_pdf_section($pdf, 'Técnicas que usamos en la consulta', pac_patient_techniques($eval));
            pac_pdf_section($pdf, 'Chi kung para practicar en casa', pac_patient_plain((string) $eval['chikung']));
            pac_pdf_section($pdf, 'Semillas en la oreja', "Presioná cada semilla 1 minuto, 3 a 5 veces por día (hasta sentir una molestia leve, sin lastimar).\nSacalas a los 5 a 7 días, o antes si pica o irrita la piel. En la próxima sesión las cambiamos a la otra oreja.");
        }
        $pdf->keepTogether(120);
        $pdf->heading('Importante');
        pac_pdf_text($pdf, 'Este plan es un acompañamiento desde la Medicina Tradicional China y no reemplaza el diagnóstico ni el tratamiento médico. No suspendas ni cambies tu medicación sin hablar con tu médico. Si aparecen síntomas nuevos o empeoran, consultá a tu médico y avisanos.');
        $pdf->richParagraph([['WhatsApp: +' . ($config['whatsapp'] ?? '') . ' · Web: ' . ($config['site_url'] ?? '') . ' · Gracias por confiar en FluxusTerapia.', false]], '', 0, 0, 9.5);
        return $pdf->render();
    }

    $pdf->heading('Datos personales');
    $age = pac_age($p);
    foreach ([
        'Nombre' => $p['nombre'],
        'RUT / DNI' => $p['documento'],
        'Fecha de nacimiento' => $p['fecha_nac'] !== '' ? $p['fecha_nac'] . ($age !== null ? ' (' . $age . ' años)' : '') : '',
        'Sexo' => $p['sexo'] !== '' ? PAC_SEXES[$p['sexo']] ?? '' : '',
        'Email' => $p['email'],
        'Teléfono' => $p['telefono'],
        'Dirección' => $p['direccion'],
        'Ocupación' => $p['ocupacion'],
        'Contacto de emergencia' => $p['emergencia'],
    ] as $label => $value) {
        if (trim((string) $value) !== '') {
            $pdf->richParagraph([[$label . ': ', true], [(string) $value, false]], '', 0, 0, 10);
        }
    }
    $pdf->heading('Antecedentes');
    $flags = array_map(static fn ($f) => PAC_FLAGS[$f], pac_flags($p));
    $pdf->richParagraph([['Condiciones de riesgo: ', true], [$flags ? implode(', ', $flags) : 'ninguna registrada', false]], '', 0, 0, 10);
    foreach (['enfermedades' => 'Enfermedades', 'cirugias' => 'Cirugías', 'medicacion' => 'Medicación', 'alergias' => 'Alergias', 'otros_riesgos' => 'Otros'] as $k => $label) {
        if (trim((string) $p[$k]) !== '') {
            $pdf->richParagraph([[$label . ': ', true], [(string) $p[$k], false]], '', 0, 0, 10);
        }
    }
    if ($history) {
        $pdf->keepTogether(70);
        $pdf->heading('Historia clínica');
        foreach ($history as $e) {
            $pdf->keepTogether(50);
            $pdf->richParagraph([[format_date_es((string) $e['fecha']) . ($e['motivo'] !== '' ? ' · ' . $e['motivo'] : ''), true]], '', 0, 0, 10);
            pac_pdf_text($pdf, (string) $e['evolucion'], 9.5);
            if (trim((string) $e['notas']) !== '') {
                pac_pdf_text($pdf, 'Notas: ' . $e['notas'], 9.5);
            }
        }
    }
    if ($eval) {
        $pdf->keepTogether(90);
        $pdf->heading('Evaluación MTC · ' . date('d/m/Y', strtotime((string) $eval['fecha'])) . ' · ' . (!empty($eval['preview']) ? 'sin guardar' : (PAC_EVAL_STATUS[$eval['status']] ?? $eval['status']) . ' · versión ' . (int) $eval['version']));
        pac_pdf_diagnosis_block($pdf, (string) $eval['patrones']);
        foreach (PAC_EVAL_INPUTS + PAC_EVAL_OUTPUTS as $k => $label) {
            if ($k !== 'patrones') {
                pac_pdf_section($pdf, $label, (string) ($eval[$k] ?? ''));
            }
        }
    }
    $pdf->rule();
    pac_pdf_text($pdf, PAC_DISCLAIMER, 9);
    if (trim((string) $p['notas']) !== '') {
        pac_pdf_section($pdf, 'Notas generales', (string) $p['notas']);
    }
    return $pdf->render();
}

/** Versión para el paciente: sin citas, sin «(Protocolo …)», sin códigos de puntos ni marcas internas. */
function pac_patient_plain(string $text): string
{
    $text = preg_replace('/\s*\[(?:[FWC]\d+(?:,\s*)?)+\]/u', '', $text) ?? $text;
    $text = preg_replace('/\s*\((?:Protocolo|según el protocolo)[^)]*\)/iu', '', $text) ?? $text;
    $text = preg_replace('/\s*\(sugerencia general\)/iu', '', $text) ?? $text;
    $text = preg_replace('/^(?:Según el protocolo|Ver en tu biblioteca).*$/mu', '', $text) ?? $text;
    $text = preg_replace('/\s*→\s*\S+/u', '', $text) ?? $text;
    $text = preg_replace('/\s*\(?\b(?:PC|IG|ID|SJ|VB|RM|DU|TR|E|B|V|R|H|C|P)\s?-?\d{1,2}\b\)?/u', '', $text) ?? $text;
    $text = preg_replace('/\(\s*\)|\s+(?=[,.;])/u', '', $text) ?? $text;
    return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
}

function pac_pdf_name(array $p, string $audience): string
{
    $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $p['nombre']) ?: 'paciente') ?? 'paciente', '-');
    return ($audience === 'paciente' ? 'Plan-MTC-' : 'Ficha-') . ($slug !== '' ? $slug : 'paciente') . '.pdf';
}
