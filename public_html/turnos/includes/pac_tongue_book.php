<?php

declare(strict_types=1);

/**
 * Libro de referencia de la glosodiagnosis: «Diagnóstico por la lengua en medicina china».
 * Cada signo (campo + valor de PAC_TONGUE) tiene su significado y los patrones a los que orienta, con la página del libro.
 * Las reglas se editan en lengua_libro.php (tabla pac_tongue_rules sobre los valores de fábrica de acá).
 * Si una regla no tiene página y el libro ya está en la Biblioteca, se busca la página en su texto (marcada «verificar»);
 * si el libro todavía no está, la cita queda «pendiente de vincular al libro».
 * Requiere includes/pacientes.php.
 */

const PAC_TONGUE_BOOK_TITLE = 'Diagnóstico por la lengua en medicina china';
/** Documento de la Biblioteca que es el libro (título o nombre de archivo, sin tildes). */
const PAC_TONGUE_BOOK_MATCH = '/diagnostico por la lengua|lengua en medicina china|glosodiagnos|tongue diagnosis|\blengua\b/u';
const PAC_TONGUE_PENDING = 'pendiente de vincular al libro';
/** Menos caracteres por página que esto = PDF escaneado sin capa de texto (hace falta OCR). */
const PAC_TONGUE_BOOK_MIN_CHARS = 250;

/** Nombre del libro para valores que ya existían con otra redacción (el valor guardado no cambia). */
const PAC_TONGUE_DISPLAY = ['Rojo oscuro / carmesí' => 'Escarlata / rojo oscuro (carmesí)'];

/**
 * Reglas de fábrica: clave => [campo, valor, significado, patrones (separados por «;»), términos para buscar la página].
 * Marco clásico de glosodiagnosis, el mismo del libro; la página se completa al vincularlo.
 */
const PAC_TONGUE_BOOK_RULES = [
    'color_rosada' => ['lengua_color', 'Rosada (normal)', 'Color normal: Qi y Sangre suficientes; si hay patología es leve o reciente.', '', 'lengua rosada normal color'],
    'color_palida' => ['lengua_color', 'Pálida', 'Vacío de Qi o de Sangre; pálida y húmeda = vacío de Yang (frío); pálida y seca = vacío de Sangre.', 'Vacío de Qi de Bazo; Vacío de Sangre; Vacío de Yang', 'lengua palida vacio sangre yang'],
    'color_roja' => ['lengua_color', 'Roja', 'Calor: con saburra = calor lleno; sin saburra o con poca = calor por vacío de Yin.', 'Calor lleno; Vacío de Yin', 'lengua roja calor saburra'],
    'color_punta' => ['lengua_color', 'Roja en la punta', 'Calor en el Corazón (fuego de Corazón o vacío de Yin de Corazón); a veces tensión emocional.', 'Fuego de Corazón; Vacío de Yin de Corazón', 'punta roja corazon lengua'],
    'color_bordes' => ['lengua_color', 'Bordes rojos', 'Calor de Hígado y Vesícula Biliar (fuego o ascenso del Yang de Hígado).', 'Fuego de Hígado; Ascenso del Yang de Hígado; Estancamiento de Qi de Hígado', 'bordes rojos lados higado lengua'],
    'color_escarlata' => ['lengua_color', 'Rojo oscuro / carmesí', 'Escarlata / rojo oscuro: calor intenso que llegó al nivel Nutritivo o de la Sangre, o vacío de Yin avanzado.', 'Calor en la Sangre; Vacío de Yin', 'lengua escarlata carmesi rojo oscuro sangre'],
    'color_violacea' => ['lengua_color', 'Violácea', 'Estasis de Sangre: rojizo-violácea con calor, azulado-violácea con frío.', 'Estasis de Sangre', 'lengua purpura violacea estasis sangre'],
    'color_azulada' => ['lengua_color', 'Azulada', 'Frío interno con estasis de Sangre (vacío de Yang).', 'Frío interno; Vacío de Yang; Estasis de Sangre', 'lengua azul azulada frio estasis'],
    'forma_hinchada' => ['lengua_forma', 'Hinchada', 'Retención de líquidos: Humedad o Flema, por vacío de Qi o de Yang de Bazo (y Riñón).', 'Humedad / Flema; Vacío de Qi de Bazo; Vacío de Yang', 'lengua hinchada humedad flema'],
    'forma_fina' => ['lengua_forma', 'Fina', 'Falta de Sangre o de Yin: pálida y fina = vacío de Sangre; roja y fina = vacío de Yin.', 'Vacío de Sangre; Vacío de Yin', 'lengua delgada fina sangre yin'],
    'forma_marcas' => ['lengua_forma', 'Marcas dentales', 'Vacío de Qi de Bazo que no transforma los líquidos.', 'Vacío de Qi de Bazo', 'marcas dentales dientes bazo'],
    'forma_grietas' => ['lengua_forma', 'Grietas', 'Vacío de Yin o calor que seca los líquidos; su ubicación indica el órgano.', 'Vacío de Yin', 'grietas fisuras lengua yin'],
    'forma_puntos' => ['lengua_forma', 'Puntos rojos / petequias', 'Calor en la Sangre (o calor tóxico); la zona indica el órgano.', 'Calor en la Sangre', 'puntos rojos lengua calor'],
    'forma_manchas' => ['lengua_forma', 'Manchas violáceas', 'Estasis de Sangre localizada en la zona donde aparece.', 'Estasis de Sangre', 'manchas purpuras estasis lengua'],
    'forma_corta' => ['lengua_forma', 'Corta / contraída', 'Pálida y húmeda = frío interno por vacío de Yang; roja y seca = vacío de Yin o calor extremo.', 'Frío interno; Vacío de Yang; Vacío de Yin', 'lengua corta contraida retraida'],
    'tamano_grande' => ['lengua_tamano', 'Grande / ancha', 'Igual que la hinchada: Humedad o Flema por vacío de Qi de Bazo.', 'Humedad / Flema; Vacío de Qi de Bazo', 'lengua grande ancha hinchada'],
    'tamano_pequena' => ['lengua_tamano', 'Pequeña / delgada', 'Igual que la fina: vacío de Sangre o de Yin.', 'Vacío de Sangre; Vacío de Yin', 'lengua delgada pequena'],
    'mov_temblorosa' => ['lengua_movilidad', 'Temblorosa', 'Viento interno de Hígado; pálida y temblorosa = vacío de Qi.', 'Viento interno de Hígado; Vacío de Qi', 'lengua temblorosa temblor viento'],
    'mov_desviada' => ['lengua_movilidad', 'Desviada', 'Viento interno (a veces con Flema).', 'Viento interno de Hígado', 'lengua desviada viento'],
    'mov_rigida' => ['lengua_movilidad', 'Rígida', 'Viento interno, Flema o calor que afecta al Pericardio.', 'Viento interno de Hígado; Humedad / Flema', 'lengua rigida viento flema'],
    'mov_flacida' => ['lengua_movilidad', 'Flácida', 'Agotamiento de líquidos: pálida = vacío de Qi y Sangre; roja = vacío de Yin.', 'Vacío de Qi y Sangre; Vacío de Yin', 'lengua flacida blanda'],
    'hum_seca' => ['lengua_humedad', 'Seca', 'Líquidos dañados: calor o vacío de Yin.', 'Calor lleno; Vacío de Yin', 'lengua seca liquidos calor'],
    'hum_muy_humeda' => ['lengua_humedad', 'Muy húmeda', 'Vacío de Yang con acumulación de líquidos (Humedad-frío).', 'Vacío de Yang; Humedad / Flema', 'lengua humeda vacio yang'],
    'venas_dilatadas' => ['lengua_venas', 'Dilatadas / oscuras', 'Estasis de Sangre (venas sublinguales distendidas u oscuras).', 'Estasis de Sangre', 'venas sublinguales estasis'],
    'venas_palidas' => ['lengua_venas', 'Pálidas / poco visibles', 'Vacío de Qi y de Sangre.', 'Vacío de Qi y Sangre', 'venas sublinguales palidas'],
    'grieta_central' => ['grietas_zona', 'Central (línea media)', 'Grieta central: Estómago (vacío de Yin de Estómago).', 'Vacío de Yin de Estómago', 'grieta central estomago'],
    'grieta_punta_larga' => ['grietas_zona', 'Central hasta la punta', 'Grieta central que llega a la punta: Corazón (tendencia constitucional o vacío de Yin de Corazón).', 'Vacío de Yin de Corazón', 'grieta hasta la punta corazon'],
    'grieta_transversal' => ['grietas_zona', 'Transversales a los lados', 'Grietas transversales a los lados: vacío crónico de Qi de Bazo.', 'Vacío de Qi de Bazo', 'grietas transversales bazo'],
    'grieta_punta' => ['grietas_zona', 'En la punta', 'Grietas en la punta: Corazón.', 'Vacío de Yin de Corazón', 'grietas punta corazon'],
    'grieta_dispersas' => ['grietas_zona', 'Dispersas / irregulares', 'Grietas dispersas: vacío de Yin (Estómago, Riñón).', 'Vacío de Yin de Riñón; Vacío de Yin de Estómago', 'grietas irregulares yin rinon'],
    'sab_blanca' => ['saburra_color', 'Blanca', 'Frío (o normal si es fina); blanca y gruesa = frío-humedad.', 'Frío; Invasión de viento-frío', 'saburra blanca frio'],
    'sab_amarilla' => ['saburra_color', 'Amarilla', 'Calor; más amarilla y gruesa = más calor y Humedad.', 'Calor lleno; Humedad-calor', 'saburra amarilla calor'],
    'sab_gris' => ['saburra_color', 'Gris / oscura', 'Extremo: seca = calor extremo; húmeda = frío extremo.', 'Calor extremo; Frío extremo', 'saburra gris negra'],
    'sab_pelada' => ['saburra_color', 'Sin saburra (pelada)', 'Qi y Yin del Estómago agotados: vacío de Yin de Estómago o de Riñón.', 'Vacío de Yin de Estómago; Vacío de Yin de Riñón', 'sin saburra pelada yin estomago'],
    'sab_fina' => ['saburra_espesor', 'Fina', 'Normal, o factor patógeno en el exterior o recién empezando.', '', 'saburra fina normal'],
    'sab_gruesa' => ['saburra_espesor', 'Gruesa', 'Factor patógeno lleno e interno: Humedad, Flema o retención de alimentos.', 'Humedad / Flema; Retención de alimentos', 'saburra gruesa humedad flema alimentos'],
    'sab_escasa' => ['saburra_espesor', 'Escasa (poca saburra)', 'Poca saburra: comienzo del vacío de Yin de Estómago.', 'Vacío de Yin de Estómago', 'poca saburra escasa yin'],
    'sab_seca' => ['saburra_humedad', 'Seca', 'Calor que seca los líquidos o vacío de Yin.', 'Calor lleno; Vacío de Yin', 'saburra seca calor'],
    'sab_humeda' => ['saburra_humedad', 'Húmeda', 'Frío o Humedad (vacío de Yang).', 'Humedad / Flema; Vacío de Yang', 'saburra humeda'],
    'sab_pegajosa' => ['saburra_humedad', 'Pegajosa / grasosa', 'Humedad o Flema (o retención de alimentos).', 'Humedad / Flema', 'saburra pegajosa grasosa flema'],
    'sab_resbaladiza' => ['saburra_humedad', 'Resbaladiza', 'Humedad-frío o Flema.', 'Humedad / Flema', 'saburra resbaladiza humedad'],
    'dist_raiz' => ['saburra_distribucion', 'Más en la raíz', 'Factor patógeno en el Jiao inferior (Intestinos, Vejiga, Riñón).', 'Humedad-calor en el Jiao inferior', 'saburra raiz jiao inferior'],
    'dist_centro' => ['saburra_distribucion', 'Más en el centro', 'Estómago y Bazo: retención de alimentos o Humedad.', 'Retención de alimentos; Humedad / Flema', 'saburra centro estomago'],
    'dist_punta' => ['saburra_distribucion', 'Más en la punta', 'Factor patógeno exterior que empieza, o calor en el Pulmón.', 'Invasión exterior; Calor de Pulmón', 'saburra punta exterior pulmon'],
    'dist_lado' => ['saburra_distribucion', 'De un solo lado', 'Nivel Shao Yang (Hígado y Vesícula Biliar).', 'Patrón Shao Yang; Estancamiento de Qi de Hígado', 'saburra un lado shao yang'],
    'dist_mapa' => ['saburra_distribucion', 'A parches (geográfica)', 'Lengua en mapa: vacío de Yin de Estómago (Qi del Estómago debilitado).', 'Vacío de Yin de Estómago', 'lengua geografica mapa saburra'],
    'raiz_con' => ['saburra_raiz', 'Con raíz (firme)', 'Qi de Estómago conservado: patrón de plenitud.', '', 'saburra con raiz'],
    'raiz_sin' => ['saburra_raiz', 'Sin raíz (se desprende)', 'Qi de Estómago debilitado: vacío (Estómago, Riñón).', 'Vacío de Yin de Estómago; Vacío de Qi', 'saburra sin raiz'],
    'zona_punta' => ['zonas', 'Punta (Corazón / Pulmón)', 'Punta: Corazón; justo detrás, Pulmón (y tórax).', 'Fuego de Corazón; Calor de Pulmón', 'punta corazon pulmon zonas lengua'],
    'zona_centro' => ['zonas', 'Centro (Bazo / Estómago)', 'Centro: Bazo y Estómago.', 'Vacío de Qi de Bazo', 'centro bazo estomago zonas'],
    'zona_laterales' => ['zonas', 'Laterales (Hígado / Vesícula)', 'Laterales: Hígado y Vesícula Biliar.', 'Estancamiento de Qi de Hígado', 'laterales higado vesicula'],
    'zona_raiz' => ['zonas', 'Raíz (Riñón)', 'Raíz: Riñón, Vejiga e Intestinos.', 'Vacío de Yin de Riñón; Vacío de Yang de Riñón', 'raiz rinon vejiga intestinos'],
];

function pac_tongue_book_schema(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_tongue_rules (
        rule_key TEXT PRIMARY KEY,
        significado TEXT NOT NULL DEFAULT '',
        patrones TEXT NOT NULL DEFAULT '',
        pagina TEXT NOT NULL DEFAULT '',
        buscar TEXT NOT NULL DEFAULT '',
        activo INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
}

function pac_tongue_label(string $value): string
{
    return PAC_TONGUE_DISPLAY[$value] ?? $value;
}

/** Documentos de la Biblioteca que parecen el libro (el elegido en lengua_libro.php va primero). */
function pac_tongue_book_candidates(): array
{
    $out = [];
    foreach (pac_docs() as $d) {
        $name = pac_fold($d['title'] . ' ' . $d['original_name']);
        if ((int) $d['active'] === 1 && preg_match(PAC_TONGUE_BOOK_MATCH, $name)) {
            $d['_exact'] = str_contains($name, 'diagnostico por la lengua') ? 1 : 0;
            $out[] = $d;
        }
    }
    usort($out, static fn ($a, $b) => [$b['_exact'], (int) $b['pages']] <=> [$a['_exact'], (int) $a['pages']]);
    return $out;
}

/** El libro en la Biblioteca (elegido a mano o detectado por el nombre), o null si todavía no se subió. */
function pac_tongue_book_doc(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $chosen = (int) pac_setting('tongue_book_doc');
    $doc = $chosen > 0 ? pac_doc($chosen) : null;
    if ($doc === null || (int) $doc['active'] !== 1) {
        $doc = pac_tongue_book_candidates()[0] ?? null;
    }
    return $cache = $doc;
}

/** Estado del libro: subido, con texto (o escaneado, sin OCR) y reglas vinculadas. */
function pac_tongue_book_status(): array
{
    $doc = pac_tongue_book_doc();
    $rules = pac_tongue_rules(true);
    $withPage = count(array_filter($rules, static fn ($r) => $r['pagina'] !== ''));
    $chars = $doc ? (int) $doc['chars'] : 0;
    $pages = $doc ? (int) $doc['pages'] : 0;
    return [
        'doc' => $doc,
        'titulo' => $doc ? (string) $doc['title'] : PAC_TONGUE_BOOK_TITLE,
        'listo' => $doc !== null && $doc['status'] === 'listo',
        'sin_texto' => $doc !== null && $pages > 0 && $chars / $pages < PAC_TONGUE_BOOK_MIN_CHARS,
        'reglas' => count($rules),
        'con_pagina' => $withPage,
    ];
}

/**
 * Reglas vigentes (fábrica + cambios de la terapeuta).
 * @return array<string, array{key:string, campo:string, valor:string, significado:string, patrones:string, pagina:string, buscar:string, activo:bool, editada:bool}>
 */
function pac_tongue_rules(bool $all = false): array
{
    static $cache = null;
    if ($cache === null) {
        $over = [];
        foreach (db()->query('SELECT * FROM pac_tongue_rules')->fetchAll() as $row) {
            $over[(string) $row['rule_key']] = $row;
        }
        $cache = [];
        foreach (PAC_TONGUE_BOOK_RULES as $key => [$campo, $valor, $sig, $pat, $buscar]) {
            $o = $over[$key] ?? null;
            $cache[$key] = [
                'key' => $key, 'campo' => $campo, 'valor' => $valor,
                'significado' => $o ? (string) $o['significado'] : $sig,
                'patrones' => $o ? (string) $o['patrones'] : $pat,
                'pagina' => $o ? (string) $o['pagina'] : '',
                'buscar' => $o && (string) $o['buscar'] !== '' ? (string) $o['buscar'] : $buscar,
                'activo' => $o ? (int) $o['activo'] === 1 : true,
                'editada' => $o !== null,
            ];
        }
    }
    return $all ? $cache : array_filter($cache, static fn ($r) => $r['activo']);
}

function pac_tongue_rule_save(string $key, array $in): void
{
    if (!isset(PAC_TONGUE_BOOK_RULES[$key])) {
        throw new RuntimeException('Esa regla no existe.');
    }
    $page = trim((string) ($in['pagina'] ?? ''));
    if ($page !== '' && !preg_match('/^\d{1,4}(?:\s*[-–]\s*\d{1,4})?$/u', $page)) {
        throw new RuntimeException('La página tiene que ser un número (o un rango, ej. 45-47).');
    }
    db()->prepare("INSERT INTO pac_tongue_rules (rule_key, significado, patrones, pagina, buscar, activo, updated_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(rule_key) DO UPDATE SET significado = excluded.significado, patrones = excluded.patrones, pagina = excluded.pagina,
        buscar = excluded.buscar, activo = excluded.activo, updated_at = CURRENT_TIMESTAMP")->execute([
        $key,
        mb_substr(trim((string) ($in['significado'] ?? '')), 0, 600),
        mb_substr(trim((string) ($in['patrones'] ?? '')), 0, 300),
        preg_replace('/\s+/u', '', $page),
        mb_substr(trim((string) ($in['buscar'] ?? '')), 0, 160),
        !empty($in['activo']) ? 1 : 0,
    ]);
}

function pac_tongue_rule_reset(string $key): void
{
    db()->prepare('DELETE FROM pac_tongue_rules WHERE rule_key = ?')->execute([$key]);
}

/** Página del libro que mejor coincide con los términos de la regla (búsqueda en su texto). */
function pac_tongue_rule_auto_page(array $rule, array $doc): int
{
    static $cache = [];
    $k = (int) $doc['id'] . '|' . $rule['buscar'];
    if (!isset($cache[$k])) {
        $cache[$k] = 0;
        foreach (pac_search(pac_terms($rule['buscar'], 8), 60) as $hit) {
            if ((int) $hit['doc_id'] === (int) $doc['id']) {
                $cache[$k] = (int) $hit['page_no'];
                break;
            }
        }
    }
    return $cache[$k];
}

/**
 * Cita de una regla: página puesta a mano, página buscada en el libro («verificar») o pendiente.
 * @return array{texto:string, link:?string, estado:string, pagina:int}
 */
function pac_tongue_rule_cite(array $rule): array
{
    $doc = pac_tongue_book_doc();
    $title = '«' . ($doc ? pac_trim((string) $doc['title'], 90) : PAC_TONGUE_BOOK_TITLE) . '»';
    if ($doc === null) {
        return ['texto' => $title . ' (' . PAC_TONGUE_PENDING . ')', 'link' => null, 'estado' => 'pendiente', 'pagina' => 0];
    }
    $link = static fn (int $n): ?string => pac_reader_link((string) ($doc['sha256'] ?? ''), (string) $doc['original_name'], $n);
    if ($rule['pagina'] !== '') {
        $first = (int) $rule['pagina'];
        return ['texto' => $title . ', p. ' . $rule['pagina'], 'link' => $link($first), 'estado' => 'libro', 'pagina' => $first];
    }
    $auto = $doc['status'] === 'listo' ? pac_tongue_rule_auto_page($rule, $doc) : 0;
    if ($auto > 0) {
        return ['texto' => $title . ', p. ' . $auto . ' (búsqueda automática: verificar)', 'link' => $link($auto), 'estado' => 'auto', 'pagina' => $auto];
    }
    return ['texto' => $title . ' (página sin vincular)', 'link' => null, 'estado' => 'pendiente', 'pagina' => 0];
}

/**
 * Lectura de la lengua según el libro: por cada signo cargado, qué significa, a qué patrones orienta y dónde lo dice.
 * @param array $t lengua normalizada (pac_tongue_from) o valores sueltos por campo
 * @return list<array{key:string, campo:string, valor:string, signo:string, significado:string, patrones:list<string>, cita:array}>
 */
function pac_tongue_reading(array $t): array
{
    $out = [];
    foreach (pac_tongue_rules() as $rule) {
        $v = $t[$rule['campo']] ?? null;
        $has = is_array($v) ? in_array($rule['valor'], $v, true) : $v === $rule['valor'];
        if (!$has || ($rule['significado'] === '' && $rule['patrones'] === '')) {
            continue;
        }
        $label = PAC_TONGUE[$rule['campo']][0] ?? $rule['campo'];
        $group = PAC_TONGUE_GROUPS[PAC_TONGUE[$rule['campo']][3] ?? ''] ?? '';
        $out[] = [
            'key' => $rule['key'],
            'campo' => $rule['campo'],
            'valor' => $rule['valor'],
            'signo' => ($group !== '' && $group !== 'Cuerpo' ? $group . ' · ' : '') . $label . ': ' . mb_strtolower(pac_tongue_label($rule['valor'])),
            'significado' => $rule['significado'],
            'patrones' => pac_tongue_pattern_list($rule['patrones']),
            'cita' => pac_tongue_rule_cite($rule),
        ];
    }
    return $out;
}

function pac_tongue_pattern_list(string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[;\n]+/u', $text) ?: [])));
}

/**
 * Texto de la lectura para el caso clínico y el borrador.
 * $links: agrega «→ biblioteca_ver.php…» (se muestra como enlace al lector).
 */
function pac_tongue_reading_text(array $reading, bool $links = false): string
{
    if (!$reading) {
        return '';
    }
    $lines = ['Lectura según «' . pac_tongue_book_status()['titulo'] . '»:'];
    foreach ($reading as $r) {
        $line = '- ' . $r['signo'] . ': ' . rtrim($r['significado'], '.') . ($r['patrones'] ? ' → ' . implode(', ', $r['patrones']) : '')
            . ' (' . $r['cita']['texto'] . ')';
        if ($links && $r['cita']['link']) {
            $line .= ' → ' . $r['cita']['link'];
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

/** Nombre de patrón comparable («Deficiencia de…» = «Vacío de…», «Estancamiento de Sangre» = «Estasis de Sangre»). */
function pac_tongue_pattern_stems(string $name): array
{
    $f = pac_fold($name);
    $f = preg_replace(['/\bdeficiencia\b|\binsuficiencia\b/u', '/\bestancamiento de (la )?sangre\b/u', '/\bexceso\b|\bplenitud\b/u'], ['vacio', 'estasis de sangre', 'lleno'], $f) ?? $f;
    $words = preg_split('/[^\p{L}\p{N}]+/u', $f, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique(array_map('pac_stem', array_filter($words, static fn ($w) => mb_strlen($w) >= 3 && !in_array($w, PAC_STOPWORDS, true)))));
}

/** Signos de la lectura que orientan a este protocolo (por el nombre de sus patrones). */
function pac_tongue_reading_supports(array $reading, string $protocolName): array
{
    $proto = pac_tongue_pattern_stems($protocolName);
    if (!$proto) {
        return [];
    }
    $out = [];
    foreach ($reading as $r) {
        foreach ($r['patrones'] as $pat) {
            $stems = pac_tongue_pattern_stems($pat);
            if ($stems && (!array_diff($stems, $proto) || !array_diff($proto, $stems))) {
                $out[] = $r['signo'];
                break;
            }
        }
    }
    return $out;
}

/** Instrucciones del libro para Cursor (análisis de la foto y diagnóstico). */
function pac_tongue_book_prompt(): array
{
    $st = pac_tongue_book_status();
    $doc = $st['doc'];
    return [
        'titulo' => $st['titulo'],
        'sha256' => $doc ? (string) ($doc['sha256'] ?? '') : '',
        'archivo' => $doc ? (string) $doc['original_name'] : '',
        'en_biblioteca' => $doc !== null,
    ];
}
