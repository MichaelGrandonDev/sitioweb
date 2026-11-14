<?php

declare(strict_types=1);

/**
 * Glosodiagnosis asistida por foto: valores SUGERIDOS para los campos de lengua (nunca se guardan solos).
 * Motores: Cursor en la Mac del terapeuta (mira la foto; solo recibe la imagen) o, si no está,
 * una estimación aproximada por color hecha en el servidor. Requiere pac_diagnosis.php y pac_cursor.php.
 */

const PAC_TONGUE_AI_KEEP = 8;
const PAC_TONGUE_CONF = ['baja', 'media', 'alta'];
/** Óvalo guía (igual al de la cámara): centro (50 %, 55 %), radios 36 % y 44 % del lado menor. */
const PAC_GUIDE = ['cx' => 0.5, 'cy' => 0.55, 'rx' => 0.36, 'ry' => 0.44];
const PAC_TONGUE_ESTIMATE_NOTE = 'Estimación aproximada por color: confirmá visualmente cada dato.';

const PAC_TONGUE_AI_SCHEMA = <<<'JSON'
{
  "campos": {
    "<clave del campo>": {"valor": "<una opción de la lista> | [opciones] para campos múltiples | texto corto para campos de texto", "confianza": "baja|media|alta", "porque": "qué se ve en la foto"}
  },
  "calidad_foto": "luz, enfoque, dominante de color, si la lengua se ve entera y relajada",
  "notas": "otras observaciones de la lengua (sin diagnosticar patrones)",
  "citas": [{"documento": "título", "pagina": "12", "archivo": "textos/…", "cita": "criterio usado, textual y corto"}]
}
JSON;

/* ---------- Estimación por color (sin IA) ---------- */

/** Lado menor y óvalo guía en píxeles. */
function pac_guide(int $w, int $h): array
{
    $m = min($w, $h);
    return [PAC_GUIDE['cx'] * $w, PAC_GUIDE['cy'] * $h, PAC_GUIDE['rx'] * $m, PAC_GUIDE['ry'] * $m];
}

/** [tono 0–360, croma 0–1, valor 0–1]. */
function pac_hcv(float $r, float $g, float $b): array
{
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $c = $max - $min;
    if ($c <= 0.0) {
        return [0.0, 0.0, $max];
    }
    $h = match (true) {
        $max === $r => fmod((($g - $b) / $c) + 6, 6),
        $max === $g => (($b - $r) / $c) + 2,
        default => (($r - $g) / $c) + 4,
    } * 60;
    return [$h, $c, $max];
}

/**
 * Colores de la lengua dentro del óvalo guía → sugerencias con confianza baja o media.
 * Si hay un papel blanco en el borde de la foto se usa para corregir el balance de blancos.
 */
function pac_tongue_estimate(string $path, string $vista = 'dorso'): array
{
    $out = ['motor' => 'color', 'campos' => [], 'calidad' => '', 'balance' => '', 'no_estimados' => [], 'aviso' => PAC_TONGUE_ESTIMATE_NOTE, 'citas' => []];
    $src = is_file($path) ? @imagecreatefromstring((string) file_get_contents($path)) : false;
    if (!$src) {
        $out['calidad'] = 'No se pudo leer la foto.';
        return $out;
    }
    $w = 200;
    $h = max(1, (int) round(imagesy($src) * $w / max(1, imagesx($src))));
    $img = imagecreatetruecolor($w, $h);
    imagecopyresampled($img, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
    [$cx, $cy, $rx, $ry] = pac_guide($w, $h);

    $px = static function (int $x, int $y) use ($img): array {
        $c = imagecolorat($img, $x, $y);
        return [(($c >> 16) & 255) / 255, (($c >> 8) & 255) / 255, ($c & 255) / 255];
    };
    $inside = static fn (int $x, int $y): float => (($x - $cx) / $rx) ** 2 + (($y - $cy) / $ry) ** 2;

    // Balance de blancos: papel blanco (muy claro y sin color) fuera del óvalo.
    $white = [0.0, 0.0, 0.0];
    $nWhite = 0;
    $nOut = 0;
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            if ($inside($x, $y) < 1.3) {
                continue;
            }
            $nOut++;
            [$r, $g, $b] = $px($x, $y);
            [, $c, $v] = pac_hcv($r, $g, $b);
            if ($v > 0.62 && $c < 0.14 && $v < 0.995) {
                $white[0] += $r;
                $white[1] += $g;
                $white[2] += $b;
                $nWhite++;
            }
        }
    }
    $gain = [1.0, 1.0, 1.0];
    $calibrated = $nOut > 0 && $nWhite / $nOut >= 0.04;
    if ($calibrated) {
        $avg = array_map(static fn ($s) => $s / $nWhite, $white);
        $ref = max($avg);
        $gain = array_map(static fn ($a) => max(0.75, min(1.4, $ref / max(0.05, $a))), $avg);
        $out['balance'] = 'Se detectó un papel blanco: el color se corrigió con esa referencia.';
    } else {
        $out['balance'] = 'Sin papel blanco en la foto: el color puede variar según la luz.';
    }

    // Clasificación de píxeles en una grilla 3×3 (filas: raíz, centro, punta; columnas: izquierda, centro, derecha).
    $cells = array_fill(0, 9, ['body' => 0, 'coat' => 0, 'bodyC' => 0.0]);
    $n = ['all' => 0, 'body' => 0, 'violet' => 0, 'coatW' => 0, 'coatY' => 0, 'coatG' => 0, 'spec' => 0, 'vein' => 0, 'dark' => 0];
    $sumC = 0.0;
    $sumV = 0.0;
    $sumAllV = 0.0;
    for ($y = (int) max(0, $cy - $ry); $y < min($h, $cy + $ry); $y++) {
        for ($x = (int) max(0, $cx - $rx); $x < min($w, $cx + $rx); $x++) {
            if ($inside($x, $y) > 1) {
                continue;
            }
            [$r, $g, $b] = $px($x, $y);
            $r = min(1, $r * $gain[0]);
            $g = min(1, $g * $gain[1]);
            $b = min(1, $b * $gain[2]);
            [$hue, $c, $v] = pac_hcv($r, $g, $b);
            $n['all']++;
            $sumAllV += $v;
            $row = min(2, (int) floor(($y - ($cy - $ry)) / (2 * $ry) * 3));
            $col = min(2, (int) floor(($x - ($cx - $rx)) / (2 * $rx) * 3));
            $cell = &$cells[$row * 3 + $col];
            if ($v < 0.14) {
                $n['dark']++;
            } elseif ($v > 0.96 && $c < 0.12) {
                $n['spec']++;
            } elseif ($c < 0.12 && $v > 0.6) {
                $n['coatW']++;
                $cell['coat']++;
            } elseif ($c < 0.1 && $v >= 0.3) {
                $n['coatG']++;
                $cell['coat']++;
            } elseif ($hue >= 35 && $hue <= 72 && $c >= 0.12 && $v > 0.5) {
                $n['coatY']++;
                $cell['coat']++;
            } elseif (($hue >= 330 || $hue <= 30) && $c >= 0.12) {
                $n['body']++;
                $sumC += $c;
                $sumV += $v;
                $cell['body']++;
                $cell['bodyC'] += $c;
            } elseif ($hue >= 280 && $hue < 330 && $c >= 0.1) {
                $n['violet']++;
                $n['body']++;
                $sumC += $c;
                $sumV += $v;
                $cell['body']++;
                $cell['bodyC'] += $c;
            } elseif ($hue >= 200 && $hue < 280 && $c >= 0.08 && $v < 0.6) {
                $n['vein']++;
            }
            unset($cell);
        }
    }
    $coat = $n['coatW'] + $n['coatY'] + $n['coatG'];
    $tongue = $n['body'] + $coat;
    $quality = [];
    if ($n['all'] > 0 && $sumAllV / $n['all'] < 0.3) {
        $quality[] = 'la foto está oscura (usá luz natural o blanca)';
    }
    if ($n['all'] > 0 && $tongue / $n['all'] < 0.35) {
        $quality[] = 'la lengua ocupa poco del óvalo guía: acercá la cámara y centrala';
    }
    $conf = $calibrated && !$quality ? 'media' : 'baja';
    $set = static function (string $key, mixed $value, string $why, ?string $c = null) use (&$out, $conf): void {
        $out['campos'][$key] = ['valor' => $value, 'confianza' => $c ?? $conf, 'porque' => $why];
    };

    if ($vista === 'sublingual') {
        $share = $n['all'] > 0 ? ($n['vein'] + $n['violet']) / $n['all'] : 0;
        $set('lengua_venas', $share > 0.12 ? 'Dilatadas / oscuras' : ($share < 0.03 ? 'Pálidas / poco visibles' : 'Normales'),
            'tonos azulados o violáceos oscuros en ' . round($share * 100) . ' % del óvalo');
        $out['calidad'] = $quality ? ucfirst(implode('; ', $quality)) . '.' : 'Foto de la cara inferior: solo se estiman las venas sublinguales.';
        return $out;
    }
    if ($tongue < 50) {
        $out['calidad'] = 'No se distingue la lengua dentro del óvalo guía: repetí la foto con la lengua centrada y buena luz.';
        return $out;
    }

    // Cuerpo: croma (intensidad del rojo) y valor.
    if ($n['body'] > 20) {
        $avgC = $sumC / $n['body'];
        $avgV = $sumV / $n['body'];
        $violet = $n['violet'] / $n['body'];
        $rowC = [];
        foreach ([0, 1, 2] as $row) {
            $b = $cells[$row * 3]['body'] + $cells[$row * 3 + 1]['body'] + $cells[$row * 3 + 2]['body'];
            $cc = $cells[$row * 3]['bodyC'] + $cells[$row * 3 + 1]['bodyC'] + $cells[$row * 3 + 2]['bodyC'];
            $rowC[$row] = $b > 10 ? $cc / $b : null;
        }
        $sideB = $cells[3]['body'] + $cells[5]['body'];
        $sideC = $sideB > 10 ? ($cells[3]['bodyC'] + $cells[5]['bodyC']) / $sideB : null;
        $midC = $cells[4]['body'] > 10 ? $cells[4]['bodyC'] / $cells[4]['body'] : $avgC;
        $tipRed = $rowC[2] !== null && $rowC[1] !== null && $rowC[2] - $rowC[1] > 0.1;
        $sideRed = $sideC !== null && $sideC - $midC > 0.1;
        $pct = static fn (float $x) => round($x * 100);
        [$color, $why] = match (true) {
            $violet > 0.3 => ['Violácea', 'tono violáceo en ' . $pct($violet) . ' % del cuerpo'],
            $avgC > 0.42 && $avgV < 0.6 => ['Rojo oscuro / carmesí', 'rojo intenso y oscuro (intensidad ' . $pct($avgC) . ' %)'],
            $avgC >= 0.5 => ['Roja', 'rojo intenso en todo el cuerpo (intensidad ' . $pct($avgC) . ' %)'],
            $tipRed => ['Roja en la punta', 'la punta es más roja que el centro'],
            $sideRed => ['Bordes rojos', 'los bordes son más rojos que el centro'],
            $avgC < 0.3 => ['Pálida', 'color poco intenso (intensidad ' . $pct($avgC) . ' %)'],
            default => ['Rosada (normal)', 'rosado de intensidad media (' . $pct($avgC) . ' %)'],
        };
        $set('lengua_color', $color, $why);
        if ($n['spec'] / max(1, $tongue) > 0.04) {
            $set('lengua_humedad', 'Muy húmeda', 'muchos brillos (reflejos de saliva)', 'baja');
        }
        $zonas = [];
        if ($tipRed) {
            $zonas[] = 'Punta (Corazón / Pulmón)';
        }
        if ($sideRed) {
            $zonas[] = 'Laterales (Hígado / Vesícula)';
        }
    } else {
        $zonas = [];
    }

    // Saburra: proporción, color y distribución.
    $frac = $coat / $tongue;
    $rowFrac = [];
    foreach ([0, 1, 2] as $row) {
        $b = $cells[$row * 3]['body'] + $cells[$row * 3 + 1]['body'] + $cells[$row * 3 + 2]['body'];
        $c = $cells[$row * 3]['coat'] + $cells[$row * 3 + 1]['coat'] + $cells[$row * 3 + 2]['coat'];
        $rowFrac[$row] = $b + $c > 10 ? $c / ($b + $c) : 0;
    }
    if ($frac < 0.08) {
        $set('saburra_color', 'Sin saburra (pelada)', 'casi no se ve capa blanca o amarilla (' . round($frac * 100) . ' % de la lengua)', 'baja');
    } else {
        $set('saburra_color', match (true) {
            $n['coatY'] / $coat > 0.35 => 'Amarilla',
            $n['coatG'] / $coat > 0.4 => 'Gris / oscura',
            default => 'Blanca',
        }, 'color de la capa que cubre ' . round($frac * 100) . ' % de la lengua');
        $set('saburra_espesor', $frac > 0.35 ? 'Gruesa' : 'Fina', $frac > 0.35 ? 'la capa tapa buena parte del cuerpo' : 'se ve el cuerpo a través de la capa');
        $cellFrac = array_map(static fn ($c) => $c['body'] + $c['coat'] > 10 ? $c['coat'] / ($c['body'] + $c['coat']) : null, $cells);
        $known = array_values(array_filter($cellFrac, static fn ($x) => $x !== null));
        $patchy = $known && min($known) < 0.05 && max($known) > 0.45 && count(array_filter($known, static fn ($x) => $x < 0.05)) >= 2;
        [$dist, $why] = match (true) {
            $rowFrac[0] > 1.5 * max($rowFrac[1], $rowFrac[2], 0.01) => ['Más en la raíz', 'la capa se concentra atrás (raíz)'],
            $rowFrac[1] > 1.5 * max($rowFrac[0], $rowFrac[2], 0.01) => ['Más en el centro', 'la capa se concentra en el centro'],
            $patchy => ['A parches (geográfica)', 'zonas con capa y zonas peladas'],
            default => ['Pareja', 'capa repartida de forma pareja'],
        };
        $set('saburra_distribucion', $dist, $why);
        if ($frac > 0.35 && $dist === 'Más en la raíz') {
            $zonas[] = 'Raíz (Riñón)';
        }
        if ($frac > 0.35 && $dist === 'Más en el centro') {
            $zonas[] = 'Centro (Bazo / Estómago)';
        }
    }
    if ($zonas) {
        $set('zonas', array_values(array_unique($zonas)), 'zonas con más rojo o más capa que el resto', 'baja');
    }
    $out['no_estimados'] = ['forma (hinchada, fina, marcas dentales, grietas)', 'tamaño', 'movilidad', 'humedad seca'];
    $out['calidad'] = $quality ? ucfirst(implode('; ', $quality)) . '.' : 'Foto utilizable.';
    return $out;
}

/* ---------- Cursor (puente local): solo la foto ---------- */

function pac_tongue_ai_rules(): string
{
    $book = pac_tongue_book_prompt();
    $alias = implode('; ', array_map(static fn ($v, $l) => '«' . $v . '» = ' . $l, array_keys(PAC_TONGUE_DISPLAY), PAC_TONGUE_DISPLAY));
    return implode("\n", [
        '1. Mirá la foto: la primera imagen es la cara superior de la lengua; si hay una segunda, es la cara inferior (venas sublinguales).',
        '2. Describí solo la lengua. No diagnostiques patrones ni indiques tratamientos.',
        '3. Clasificá ESTRICTAMENTE con las categorías del libro «' . PAC_TONGUE_BOOK_TITLE . '»: color del cuerpo, forma (hinchada, fina, marcas dentales, grietas y su ubicación, corta, puntos y manchas), movilidad (rígida, flácida, desviada, temblorosa), humedad, saburra (presencia, raíz, color, espesor, humedad, pegajosa o resbaladiza, distribución, pelada o en mapa), venas sublinguales y zonas. Las "opciones" son esas categorías: usá SOLO sus textos exactos (' . $alias . '). En campos "multi" devolvé una lista. Si algo no se ve bien, no lo incluyas o poné confianza "baja".',
        '4. Zonas: punta = Corazón / Pulmón; centro = Bazo / Estómago; laterales = Hígado / Vesícula; raíz = Riñón. Con la lengua afuera mirando a cámara, la punta queda abajo en la imagen.',
        '5. Luz: si hay un papel blanco en la foto, usalo como referencia de blanco. Si hay dominante de color (luz cálida o fría), flash fuerte, filtros o desenfoque, bajá la confianza y decilo en "calidad_foto".',
        '6. Tené en cuenta lo que tiñe la lengua o la saburra (café, té, caramelos, colorantes) y el cepillado: mencionalo si lo sospechás.',
        $book['en_biblioteca']
            ? '7. El libro está en la biblioteca local (ver "libro"): buscá con grep en su texto los criterios de cada signo que veas y citá su página en "citas" (documento = título del libro, pagina = número de «[p. N]», archivo = su archivo en textos/). No cites otros libros para la clasificación ni inventes páginas.'
            : '7. El libro todavía no está en la biblioteca: clasificá con las categorías de "opciones" (son las del libro) y dejá "citas" vacío; no inventes páginas.',
        '8. Respondé SOLO con JSON válido según el esquema, sin texto antes ni después.',
    ]);
}

/** Opciones por campo para el puente (mismas que el formulario). */
function pac_tongue_ai_options(): array
{
    $out = [];
    foreach (PAC_TONGUE as $k => [$label, $type, $opts, $group]) {
        if ($k === 'lengua_notas') {
            continue;
        }
        $out[$k] = ['etiqueta' => PAC_TONGUE_GROUPS[$group] . ' · ' . $label, 'tipo' => $type, 'opciones' => $opts];
    }
    return $out;
}

function pac_tongue_ai_problem(array $r): string
{
    if (!is_array($r['campos'] ?? null)) {
        return 'falta «campos»';
    }
    return pac_tongue_ai_clean($r)['campos'] ? '' : 'ningún campo con valores válidos';
}

/** Respuesta de Cursor → misma forma que la estimación por color (solo valores de las listas). */
function pac_tongue_ai_clean(array $r): array
{
    $out = ['motor' => 'cursor', 'campos' => [], 'calidad' => pac_cursor_str($r['calidad_foto'] ?? '', 400),
        'balance' => '', 'no_estimados' => [], 'aviso' => 'Sugerido por Cursor mirando la foto: confirmá cada dato.', 'citas' => [],
        'notas' => pac_cursor_str($r['notas'] ?? '', 600)];
    foreach (is_array($r['campos'] ?? null) ? $r['campos'] : [] as $k => $item) {
        if (!isset(PAC_TONGUE[$k]) || $k === 'lengua_notas' || !is_array($item)) {
            continue;
        }
        [, $type, $opts] = PAC_TONGUE[$k];
        $v = $item['valor'] ?? null;
        if ($type === 'multi') {
            $v = array_values(array_intersect($opts, array_map('strval', is_array($v) ? $v : [$v])));
            if (!$v) {
                continue;
            }
        } elseif ($type === 'one') {
            if (!is_string($v) || !in_array($v, $opts, true)) {
                continue;
            }
        } else {
            $v = pac_cursor_str($v ?? '', 300);
            if ($v === '') {
                continue;
            }
        }
        $c = in_array($item['confianza'] ?? '', PAC_TONGUE_CONF, true) ? $item['confianza'] : 'baja';
        $out['campos'][$k] = ['valor' => $v, 'confianza' => $c, 'porque' => pac_cursor_str($item['porque'] ?? '', 300)];
    }
    foreach (pac_cursor_list($r['citas'] ?? [], 8) as $c) {
        if (is_array($c) && ($doc = pac_cursor_str($c['documento'] ?? '', 160)) !== '') {
            $page = pac_cursor_str($c['pagina'] ?? '', 20);
            $out['citas'][] = ['cite' => '«' . $doc . '»' . ($page !== '' ? ', p. ' . $page : ''), 'excerpt' => pac_cursor_str($c['cita'] ?? '', 240),
                'link' => pac_reader_link(pac_cursor_str($c['sha256'] ?? '', 64), pac_cursor_str($c['source_file'] ?? '', 200), (int) $page)];
        }
    }
    return $out;
}

/** Agrega a la sugerencia lo que dice el libro de cada valor sugerido (significado, patrones y página). */
function pac_tongue_with_reading(array $res): array
{
    if (is_array($res['sugerencia']['campos'] ?? null)) {
        $values = array_map(static fn ($c) => $c['valor'] ?? '', $res['sugerencia']['campos']);
        $res['sugerencia']['lectura'] = array_map(static fn ($r) => [
            'campo' => $r['campo'], 'signo' => $r['signo'], 'significado' => $r['significado'], 'patrones' => $r['patrones'],
            'cita' => $r['cita']['texto'], 'link' => $r['cita']['link'], 'estado' => $r['cita']['estado'],
        ], pac_tongue_reading($values));
        $res['sugerencia']['libro'] = pac_tongue_book_status()['titulo'];
    }
    return $res;
}

/* ---------- Análisis pedido desde la ficha (en la sesión, nunca en la base) ---------- */

/**
 * Empieza el análisis: estimación por color al instante y, si Cursor está conectado, un trabajo con la(s) foto(s).
 * @return array{token:string, state:string, sugerencia:array}
 */
function pac_tongue_analyze(int $pid, array $photo, ?array $photo2, bool $useAi): array
{
    $path = pac_storage_path((string) $photo['stored']);
    $estimate = pac_tongue_estimate($path, (string) ($photo['vista'] ?: 'dorso'));
    if ($photo2) {
        $sub = pac_tongue_estimate(pac_storage_path((string) $photo2['stored']), 'sublingual');
        $estimate['campos'] += $sub['campos'];
    }
    $job = 0;
    if ($useAi && pac_bridge_alive()) {
        pac_jobs_expire();
        $payload = ['tipo' => 'lengua', 'version' => 1, 'foto' => true, 'foto2' => $photo2 !== null,
            'opciones' => pac_tongue_ai_options(), 'reglas' => pac_tongue_ai_rules(), 'esquema' => PAC_TONGUE_AI_SCHEMA, 'libro' => pac_tongue_book_prompt()];
        db()->prepare("INSERT INTO pac_ai_jobs (patient_id, kind, status, payload, created_at, photo_id, photo2_id) VALUES (?, 'lengua', 'pending', ?, ?, ?, ?)")
            ->execute([$pid, json_encode($payload, JSON_UNESCAPED_UNICODE), time(), (int) $photo['id'], (int) ($photo2['id'] ?? 0)]);
        $job = (int) db()->lastInsertId();
    }
    $all = is_array($_SESSION['pac_tongue'] ?? null) ? $_SESSION['pac_tongue'] : [];
    uasort($all, static fn ($a, $b) => ($a['created'] ?? 0) <=> ($b['created'] ?? 0));
    while (count($all) >= PAC_TONGUE_AI_KEEP) {
        array_shift($all);
    }
    $token = bin2hex(random_bytes(12));
    $all[$token] = ['pid' => $pid, 'job' => $job, 'estimate' => $estimate, 'created' => time(), 'result' => null];
    $_SESSION['pac_tongue'] = $all;
    return ['token' => $token, 'state' => $job ? 'waiting' : 'ready', 'sugerencia' => $estimate];
}

/** Estado del análisis: espera a Cursor; si no llega a tiempo queda la estimación por color. */
function pac_tongue_status(string $token, int $pid): ?array
{
    $a = $_SESSION['pac_tongue'][$token] ?? null;
    if (!is_array($a) || (int) $a['pid'] !== $pid) {
        return null;
    }
    if (is_array($a['result'])) {
        return ['state' => 'ready', 'sugerencia' => $a['result']];
    }
    if ((int) $a['job'] <= 0) {
        return ['state' => 'ready', 'sugerencia' => $a['estimate']];
    }
    pac_jobs_expire();
    $job = pac_job((int) $a['job']);
    $elapsed = time() - (int) $a['created'];
    $result = null;
    if ($job && $job['status'] === 'done') {
        $clean = pac_tongue_ai_clean(json_decode((string) $job['result'], true) ?: []);
        if ($clean['campos']) {
            $result = $clean;
        }
    }
    $failed = !$job || in_array($job['status'], ['error', 'expired'], true) || ($job['status'] === 'done' && $result === null)
        || $elapsed > PAC_JOB_WAIT || ($job['status'] === 'pending' && !pac_bridge_alive());
    if ($result === null && !$failed) {
        return ['state' => 'waiting', 'elapsed' => $elapsed, 'limit' => PAC_JOB_WAIT, 'sugerencia' => $a['estimate']];
    }
    if ($result === null) {
        $result = $a['estimate'];
        $result['aviso'] = 'Cursor no disponible — ' . PAC_TONGUE_ESTIMATE_NOTE;
        if ($job && in_array($job['status'], ['pending', 'claimed'], true)) {
            db()->prepare("UPDATE pac_ai_jobs SET status = 'expired', payload = '{}', photo_id = 0, photo2_id = 0, finished_at = ? WHERE id = ?")->execute([time(), (int) $job['id']]);
        }
    } else {
        foreach ($a['estimate']['campos'] as $k => $v) {
            $result['campos'][$k] ??= $v + ['motor' => 'color'];
        }
    }
    $_SESSION['pac_tongue'][$token]['result'] = $result;
    return ['state' => 'ready', 'sugerencia' => $result];
}
