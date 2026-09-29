<?php

declare(strict_types=1);

/**
 * Biblioteca MTC: documentos (PDF, PPTX/PPT, DOCX, TXT/MD) con su texto indexado para buscar y citar
 * (documento + página / diapositiva / parte), y protocolos editables (patrón → principio, meridianos, puntos, técnicas).
 * El texto de los PDF lo lee el navegador con pdf.js (assets/vendor); si falla, se usa pac_pdf_text_fallback().
 */

const PAC_DOC_MAX = 150 * 1024 * 1024;
const PAC_DOC_KINDS = [
    'pdf' => ['PDF', 'p.'],
    'pptx' => ['PowerPoint', 'diap.'],
    'ppt' => ['PowerPoint antiguo', 'diap.'],
    'docx' => ['Word', 'parte'],
    'txt' => ['Texto', 'parte'],
    'md' => ['Texto', 'parte'],
];
const PAC_DOC_TAGS = ['protocolo' => 'Protocolo', 'libro' => 'Libro', 'apuntes' => 'Apuntes', 'otro' => 'Otro'];
const PAC_PAGE_CHARS = 60000;
const PAC_PART_CHARS = 1800;

const PAC_POINT_NAMES = [
    'E36' => 'Zusanli', 'E40' => 'Fenglong', 'E25' => 'Tianshu', 'E35' => 'Dubi', 'E44' => 'Neiting',
    'B3' => 'Taibai', 'B6' => 'Sanyinjiao', 'B9' => 'Yinlingquan', 'B10' => 'Xuehai', 'B4' => 'Gongsun',
    'V17' => 'Geshu', 'V18' => 'Ganshu', 'V20' => 'Pishu', 'V21' => 'Weishu', 'V23' => 'Shenshu', 'V25' => 'Dachangshu',
    'V40' => 'Weizhong', 'V60' => 'Kunlun', 'V67' => 'Zhiyin', 'V13' => 'Feishu', 'V15' => 'Xinshu',
    'V31' => 'Shangliao', 'V32' => 'Ciliao', 'V33' => 'Zhongliao', 'V34' => 'Xialiao',
    'RM3' => 'Zhongji', 'RM4' => 'Guanyuan', 'RM5' => 'Shimen', 'RM6' => 'Qihai', 'RM7' => 'Yinjiao', 'RM12' => 'Zhongwan', 'RM17' => 'Shanzhong',
    'DU4' => 'Mingmen', 'DU14' => 'Dazhui', 'DU20' => 'Baihui',
    'H2' => 'Xingjian', 'H3' => 'Taichong', 'H14' => 'Qimen',
    'IG4' => 'Hegu', 'IG11' => 'Quchi', 'IG15' => 'Jianyu',
    'VB20' => 'Fengchi', 'VB21' => 'Jianjing', 'VB30' => 'Huantiao', 'VB34' => 'Yanglingquan', 'VB39' => 'Xuanzhong',
    'PC6' => 'Neiguan', 'C7' => 'Shenmen', 'P7' => 'Lieque', 'P9' => 'Taiyuan',
    'R3' => 'Taixi', 'R6' => 'Zhaohai', 'R7' => 'Fuliu', 'R1' => 'Yongquan',
    'ID3' => 'Houxi', 'SJ5' => 'Waiguan', 'SJ14' => 'Jianliao',
];

function pac_library_schema(PDO $pdo): void
{
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'pdf',
        tag TEXT NOT NULL DEFAULT 'libro',
        original_name TEXT NOT NULL DEFAULT '',
        file_path TEXT NOT NULL DEFAULT '',
        file_size INTEGER NOT NULL DEFAULT 0,
        pages INTEGER NOT NULL DEFAULT 0,
        chars INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'subiendo',
        note TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_doc_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_id INTEGER NOT NULL,
        page_no INTEGER NOT NULL,
        text TEXT NOT NULL,
        folded TEXT NOT NULL DEFAULT ''
      )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pac_doc_pages_doc ON pac_doc_pages(doc_id, page_no)');
    if (!(int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'pac_doc_fts'")->fetchColumn()) {
        foreach (['unicode61 remove_diacritics 2', 'unicode61 remove_diacritics 1', 'unicode61'] as $tokenizer) {
            try {
                $pdo->exec("CREATE VIRTUAL TABLE pac_doc_fts USING fts5(text, tokenize = '$tokenizer')");
                break;
            } catch (Throwable) {
                continue;
            }
        }
    }
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS pac_protocols (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        categoria TEXT NOT NULL DEFAULT 'patron',
        signos TEXT NOT NULL DEFAULT '',
        lengua TEXT NOT NULL DEFAULT '',
        pulso TEXT NOT NULL DEFAULT '',
        principio TEXT NOT NULL DEFAULT '',
        meridianos TEXT NOT NULL DEFAULT '',
        puntos TEXT NOT NULL DEFAULT '',
        tecnicas TEXT NOT NULL DEFAULT '',
        sesiones TEXT NOT NULL DEFAULT '',
        recomendaciones TEXT NOT NULL DEFAULT '',
        fuente TEXT NOT NULL DEFAULT '',
        seed_key TEXT NOT NULL DEFAULT '',
        active INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 100,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      )
    ");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pac_web_cache (url_hash TEXT PRIMARY KEY, body TEXT NOT NULL, fetched_at INTEGER NOT NULL)");
    $have = array_column($pdo->query('PRAGMA table_info(pac_protocols)')->fetchAll(), 'name');
    foreach (PAC_TECHNIQUE_FIELDS as $col) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE pac_protocols ADD COLUMN $col TEXT NOT NULL DEFAULT ''");
        }
    }
    $seeded = $pdo->query("SELECT value FROM pac_settings WHERE key = 'protocols_seeded'")->fetchColumn();
    if ($seeded === false) {
        pac_protocols_seed($pdo, false);
        $pdo->prepare("INSERT OR REPLACE INTO pac_settings (key, value) VALUES ('protocols_seeded', '1')")->execute();
        $pdo->prepare("INSERT OR REPLACE INTO pac_settings (key, value) VALUES ('protocols_techniques', '2')")->execute();
    }
    if ($pdo->query("SELECT value FROM pac_settings WHERE key = 'protocols_techniques'")->fetchColumn() === false) {
        pac_protocols_upgrade_techniques($pdo);
        $pdo->prepare("INSERT OR REPLACE INTO pac_settings (key, value) VALUES ('protocols_techniques', '2')")->execute();
    }
}

/**
 * Protocolos base cargados antes de las cinco técnicas: completa los campos nuevos vacíos y reemplaza
 * puntos/técnicas/lengua solo si siguen con el texto original (lo editado por el terapeuta no se toca).
 */
function pac_protocols_upgrade_techniques(PDO $pdo): void
{
    $old = [
        'bazo_qi' => ['puntos' => 'E36, B6, V20, RM12', 'tecnicas' => 'Acupuntura en tonificación; moxa en E36, RM12 y V20.', 'lengua' => 'pálida, hinchada, marcas dentales, saburra blanca fina'],
        'higado_qi' => ['puntos' => 'H3, IG4, VB34, PC6', 'tecnicas' => 'Acupuntura en armonización/dispersión (H3 + IG4: «cuatro puertas»); ventosas en trapecios si hay contractura; auriculoterapia (Shenmen, Hígado).', 'lengua' => 'bordes rojos, color normal o levemente violáceo en los bordes'],
        'rinon_yin' => ['puntos' => 'R3, R6, V23, RM4, B6', 'tecnicas' => 'Acupuntura en tonificación. Sin moxa (hay calor por vacío).', 'lengua' => 'roja, sin saburra, pelada, grietas'],
        'rinon_yang' => ['puntos' => 'R3, V23, RM4, DU4, RM6', 'tecnicas' => 'Acupuntura en tonificación + moxa en V23, DU4, RM4 y RM6.', 'lengua' => 'pálida, húmeda, hinchada'],
        'sangre_estasis' => ['puntos' => 'V17, B10, B6, puntos Ashi', 'tecnicas' => 'Acupuntura en dispersión; ventosas en la zona; puntos Ashi.', 'lengua' => 'violácea, púrpura, puntos oscuros, venas sublinguales dilatadas'],
        'humedad_flema' => ['puntos' => 'E40, B9, RM12', 'tecnicas' => 'Acupuntura; ventosas; moxa si hay frío.'],
        'musculo' => ['puntos' => 'Puntos locales, puntos Ashi y distales según meridiano (cervical: VB20, VB21, ID3, SJ5; lumbar: V23, V25, V40, V60; hombro: IG15, SJ14, IG11; rodilla: E35, VB34, E36)', 'tecnicas' => 'Acupuntura; electroacupuntura; ventosas; moxa si mejora con calor.'],
    ];
    $seeds = pac_protocol_seeds();
    foreach ($pdo->query("SELECT * FROM pac_protocols WHERE seed_key <> ''")->fetchAll() as $row) {
        $seed = $seeds[$row['seed_key']] ?? null;
        if (!$seed) {
            continue;
        }
        $set = [];
        foreach (PAC_TECHNIQUE_FIELDS as $f) {
            if (trim((string) $row[$f]) === '' && ($seed[$f] ?? '') !== '') {
                $set[$f] = $seed[$f];
            }
        }
        foreach ($old[$row['seed_key']] ?? [] as $f => $orig) {
            if (trim((string) $row[$f]) === $orig) {
                $set[$f] = (string) $seed[$f];
            }
        }
        if ($set) {
            $cols = implode(', ', array_map(static fn ($c) => "$c = ?", array_keys($set)));
            $pdo->prepare("UPDATE pac_protocols SET $cols, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([...array_values($set), (int) $row['id']]);
        }
    }
}

function pac_has_fts(): bool
{
    static $has = null;
    if ($has === null) {
        $has = (int) db()->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'pac_doc_fts'")->fetchColumn() > 0;
    }
    return $has;
}

/* ---------- Texto ---------- */

function pac_fold(string $text): string
{
    static $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];
    return strtr(mb_strtolower($text, 'UTF-8'), $map);
}

const PAC_STOPWORDS = [
    'los', 'las', 'del', 'con', 'por', 'para', 'una', 'uno', 'unos', 'unas', 'que', 'como', 'sus', 'sobre',
    'entre', 'desde', 'hasta', 'sin', 'mas', 'muy', 'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'eso',
    'son', 'ser', 'hay', 'tiene', 'pero', 'cual', 'cuales', 'donde', 'cuando', 'tambien', 'otro', 'otra',
    'paciente', 'refiere', 'desde', 'hace', 'veces', 'poco', 'mucho', 'bastante', 'algo', 'dias', 'meses', 'anos',
    'the', 'and', 'for', 'with', 'from', 'that', 'this',
];

/** @return list<string> */
function pac_terms(string $query, int $max = 16): array
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', pac_fold($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = [];
    foreach ($words as $word) {
        if (mb_strlen($word) < 3 || in_array($word, PAC_STOPWORDS, true) || ctype_digit($word)) {
            continue;
        }
        $terms[$word] = true;
        if (count($terms) >= $max) {
            break;
        }
    }
    return array_keys($terms);
}

/** Raíz corta: "dolores" → "dolor", "hinchada" → "hinch". */
function pac_stem(string $term): string
{
    if (mb_strlen($term) > 4 && str_ends_with($term, 's')) {
        $term = mb_substr($term, 0, -1);
    }
    return mb_strlen($term) > 5 ? mb_substr($term, 0, max(5, mb_strlen($term) - 2)) : $term;
}

function pac_clean_text(string $text, int $max = PAC_PAGE_CHARS): string
{
    $text = str_replace(["\r\n", "\r", "\u{00AD}"], ["\n", "\n", ''], $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/(\p{Ll}) ?- ?\n ?(\p{Ll})/u', '$1$2', $text) ?? $text;
    $text = preg_replace('/ ?\n ?/u', "\n", $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return mb_substr(trim($text), 0, $max, 'UTF-8');
}

function pac_trim(string $text, int $max): string
{
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,;:') . '…';
}

/** Recorte del texto alrededor de la primera coincidencia. */
function pac_excerpt(string $text, array $stems, int $max = 480): string
{
    $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if (mb_strlen($flat) <= $max) {
        return $flat;
    }
    $folded = pac_fold($flat);
    $pos = null;
    foreach ($stems as $stem) {
        $p = mb_strpos($folded, $stem);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    $start = max(0, (int) ($pos ?? 0) - (int) ($max * 0.3));
    if ($start > 0) {
        $space = mb_strpos($flat, ' ', $start);
        $start = $space !== false && $space - $start < 30 ? $space + 1 : $start;
    }
    return ($start > 0 ? '…' : '') . pac_trim(mb_substr($flat, $start, $max + 40), $max);
}

function pac_point_label(string $code): string
{
    $names = PAC_POINT_NAMES + (defined('MTC_POINTS') ? MTC_POINTS : []);
    return isset($names[$code]) ? $names[$code] . ' (' . $code . ')' : $code;
}

function pac_points_text(array $codes): string
{
    return implode(', ', array_map('pac_point_label', array_values(array_unique($codes))));
}

/* ---------- Documentos: archivos ---------- */

function pac_doc(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_docs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function pac_docs(): array
{
    return db()->query('SELECT * FROM pac_docs ORDER BY created_at DESC, id DESC')->fetchAll();
}

function pac_doc_kind(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return isset(PAC_DOC_KINDS[$ext]) ? $ext : '';
}

function pac_doc_title_from(string $filename): string
{
    $t = preg_replace('/\.[a-z0-9]{2,4}$/i', '', basename($filename)) ?? $filename;
    $t = trim(preg_replace('/[_\s]+/u', ' ', $t) ?? $t);
    return mb_substr($t !== '' ? $t : 'Documento', 0, 200);
}

function pac_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $num = (float) $value;
    return (int) match (strtolower(substr($value, -1))) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
}

/** Tamaño de cada parte de la subida: chico para no chocar con límites ni tiempos del hosting. */
function pac_chunk_bytes(): int
{
    $limits = [8 * 1024 * 1024];
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $bytes = pac_ini_bytes((string) ini_get($key));
        if ($bytes > 0) {
            $limits[] = $bytes - 256 * 1024;
        }
    }
    return max(256 * 1024, min($limits));
}

function pac_doc_create(string $title, string $originalName, string $tag, int $size): int
{
    $kind = pac_doc_kind($originalName);
    if ($kind === '') {
        throw new RuntimeException('Formato no admitido. Usá PDF, PPTX, PPT, DOCX, TXT o MD.');
    }
    if ($size < 1 || $size > PAC_DOC_MAX) {
        throw new RuntimeException('Cada documento puede pesar hasta ' . (PAC_DOC_MAX / 1048576) . ' MB.');
    }
    $tag = array_key_exists($tag, PAC_DOC_TAGS) ? $tag : 'libro';
    $title = mb_substr(trim($title) !== '' ? trim($title) : pac_doc_title_from($originalName), 0, 200);
    db()->prepare('INSERT INTO pac_docs (title, kind, tag, original_name, file_size, status) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$title, $kind, $tag, pac_clean_filename($originalName), $size, 'subiendo']);
    return (int) db()->lastInsertId();
}

/**
 * Agrega una parte del archivo; al completar el total lo valida y lo deja en data/pacientes/biblioteca/.
 * @return array{ok:bool, error?:string, received?:int, done?:bool, resync?:bool}
 */
function pac_doc_store_chunk(array $doc, array $file, int $offset, int $total): array
{
    $id = (int) $doc['id'];
    if ($total < 1 || $total > PAC_DOC_MAX || $total !== (int) $doc['file_size']) {
        return ['ok' => false, 'error' => 'Tamaño de archivo inválido.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Una parte del archivo no llegó al servidor. Reintentá.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Archivo inválido.'];
    }
    $size = (int) filesize($tmp);
    $dir = pac_storage_path('biblioteca');
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'No se pudo crear la carpeta de la biblioteca en el servidor.'];
    }
    $part = $dir . '/doc-' . $id . '.part';
    $out = fopen($part, 'cb');
    if ($out === false || !flock($out, LOCK_EX)) {
        return ['ok' => false, 'error' => 'No se pudo escribir el archivo en el servidor.'];
    }
    $received = (int) fstat($out)['size'];
    if ($offset !== $received) {
        fclose($out);
        return ['ok' => false, 'error' => 'La subida se desfasó.', 'received' => $received, 'resync' => true];
    }
    if ($size < 1 || $received + $size > $total) {
        fclose($out);
        return ['ok' => false, 'error' => 'Parte del archivo con tamaño inválido.', 'received' => $received];
    }
    fseek($out, 0, SEEK_END);
    $in = fopen($tmp, 'rb');
    $copied = $in !== false ? (int) stream_copy_to_stream($in, $out) : 0;
    if ($in !== false) {
        fclose($in);
    }
    fflush($out);
    if ($copied !== $size) {
        ftruncate($out, $received);
        fclose($out);
        return ['ok' => false, 'error' => 'No se pudo escribir el archivo (¿espacio en el hosting?).', 'received' => $received];
    }
    $received += $size;
    flock($out, LOCK_UN);
    fclose($out);
    if ($received < $total) {
        return ['ok' => true, 'done' => false, 'received' => $received];
    }
    $problem = pac_doc_validate($part, (string) $doc['kind']);
    if ($problem !== '') {
        @unlink($part);
        db()->prepare("UPDATE pac_docs SET status = 'error', note = ? WHERE id = ?")->execute([$problem, $id]);
        return ['ok' => false, 'error' => $problem];
    }
    $name = 'doc-' . $id . '-' . bin2hex(random_bytes(6)) . '.' . $doc['kind'];
    if (!rename($part, $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'No se pudo guardar el archivo.', 'received' => $received];
    }
    @chmod($dir . '/' . $name, 0640);
    db()->prepare("UPDATE pac_docs SET file_path = ?, status = 'procesando' WHERE id = ?")->execute(['biblioteca/' . $name, $id]);
    return ['ok' => true, 'done' => true, 'received' => $received];
}

/** '' si el archivo coincide con su formato; si no, el problema. */
function pac_doc_validate(string $path, string $kind): string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    $head = (string) file_get_contents($path, false, null, 0, 8);
    return match ($kind) {
        'pdf' => $mime === 'application/pdf' || str_starts_with($head, '%PDF') ? '' : 'El archivo no es un PDF.',
        'pptx', 'docx' => str_starts_with($head, "PK\x03\x04") ? '' : 'El archivo no es un ' . strtoupper($kind) . ' válido.',
        'ppt' => str_starts_with($head, "\xD0\xCF\x11\xE0") ? '' : 'El archivo no es una presentación PPT válida.',
        default => (str_starts_with($mime, 'text/') || $mime === 'application/octet-stream' || $mime === 'inode/x-empty') && !str_contains((string) file_get_contents($path, false, null, 0, 4096), "\0")
            ? '' : 'El archivo no es de texto.',
    };
}

function pac_doc_file(array $doc): string
{
    $rel = (string) $doc['file_path'];
    return $rel !== '' && !str_contains($rel, '..') ? pac_storage_path($rel) : '';
}

function pac_doc_delete(int $id): void
{
    $doc = pac_doc($id);
    if (!$doc) {
        return;
    }
    pac_doc_clear_pages($id);
    db()->prepare('DELETE FROM pac_docs WHERE id = ?')->execute([$id]);
    pac_delete_stored((string) $doc['file_path']);
    pac_delete_stored('biblioteca/doc-' . $id . '.part');
}

/* ---------- Documentos: texto e índice ---------- */

function pac_doc_clear_pages(int $docId): void
{
    if (pac_has_fts()) {
        db()->prepare('DELETE FROM pac_doc_fts WHERE rowid IN (SELECT id FROM pac_doc_pages WHERE doc_id = ?)')->execute([$docId]);
    }
    db()->prepare('DELETE FROM pac_doc_pages WHERE doc_id = ?')->execute([$docId]);
}

function pac_doc_insert_page(int $docId, int $pageNo, string $text): void
{
    $pdo = db();
    $text = pac_clean_text($text);
    $old = $pdo->prepare('SELECT id FROM pac_doc_pages WHERE doc_id = ? AND page_no = ?');
    $old->execute([$docId, $pageNo]);
    foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
        $pdo->prepare('DELETE FROM pac_doc_pages WHERE id = ?')->execute([$oldId]);
        if (pac_has_fts()) {
            $pdo->prepare('DELETE FROM pac_doc_fts WHERE rowid = ?')->execute([$oldId]);
        }
    }
    if (trim($text) === '' || !preg_match('/\p{L}{3}/u', $text)) {
        return;
    }
    $pdo->prepare('INSERT INTO pac_doc_pages (doc_id, page_no, text, folded) VALUES (?, ?, ?, ?)')
        ->execute([$docId, $pageNo, $text, pac_has_fts() ? '' : pac_fold($text)]);
    if (pac_has_fts()) {
        $pdo->prepare('INSERT INTO pac_doc_fts (rowid, text) VALUES (?, ?)')->execute([(int) $pdo->lastInsertId(), $text]);
    }
}

/** Cierra el procesamiento: cuenta páginas con texto y deja el estado. */
function pac_doc_finish(int $docId, int $totalPages = 0, string $note = ''): array
{
    $stats = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(LENGTH(text)), 0) AS chars, COALESCE(MAX(page_no), 0) AS last FROM pac_doc_pages WHERE doc_id = ?');
    $stats->execute([$docId]);
    $s = $stats->fetch();
    $n = (int) $s['n'];
    $status = $n > 0 ? 'listo' : 'sin_texto';
    if ($n === 0 && $note === '') {
        $note = 'No se encontró texto. Si es un escaneo (fotos de páginas), hay que pasarlo por OCR antes de subirlo.';
    }
    db()->prepare('UPDATE pac_docs SET pages = ?, chars = ?, status = ?, note = ? WHERE id = ?')
        ->execute([max($totalPages, (int) $s['last']), (int) $s['chars'], $status, mb_substr($note, 0, 500), $docId]);
    return ['status' => $status, 'pages_with_text' => $n];
}

/** Extrae el texto en el servidor (PPTX, DOCX, TXT/MD, PPT y el respaldo de PDF) y lo indexa. */
function pac_doc_process(array $doc): array
{
    $path = pac_doc_file($doc);
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('El archivo no terminó de subir.');
    }
    @set_time_limit(120);
    $note = '';
    $pages = match ((string) $doc['kind']) {
        'pptx' => pac_pptx_slides($path),
        'docx' => pac_docx_parts($path),
        'txt', 'md' => pac_split_parts(pac_to_utf8((string) file_get_contents($path))),
        'ppt' => pac_ppt_slides($path),
        'pdf' => pac_pdf_text_fallback($path),
        default => [],
    };
    if ($doc['kind'] === 'ppt') {
        $note = 'PowerPoint antiguo (.ppt): lectura aproximada. Para mejores citas, guardalo como PPTX o PDF y volvé a subirlo.';
    } elseif ($doc['kind'] === 'pdf') {
        $note = 'Texto leído en el servidor (lectura aproximada, por partes). Para citar páginas exactas, volvé a subirlo desde un navegador actualizado.';
    }
    pac_doc_clear_pages((int) $doc['id']);
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($pages as $n => $text) {
        pac_doc_insert_page((int) $doc['id'], (int) $n, (string) $text);
    }
    $pdo->commit();
    return pac_doc_finish((int) $doc['id'], count($pages), $pages ? $note : '');
}

function pac_to_utf8(string $raw): string
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $raw = (string) mb_convert_encoding(substr($raw, 2), 'UTF-8', str_starts_with($raw, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
    }
    return mb_check_encoding($raw, 'UTF-8') ? $raw : (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
}

/**
 * Divide un texto largo en partes de ~PAC_PART_CHARS por párrafos (para citar "parte N").
 * @return array<int,string> número de parte (desde 1) → texto
 */
function pac_split_parts(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $paras = preg_split('/\n\s*\n|\n(?=#{1,6}\s)/u', $text) ?: [];
    $parts = [];
    $cur = '';
    foreach ($paras as $para) {
        $para = trim($para);
        if ($para === '') {
            continue;
        }
        while (mb_strlen($para) > PAC_PART_CHARS * 2) {
            $parts[] = trim($cur . "\n\n" . mb_substr($para, 0, PAC_PART_CHARS));
            $cur = '';
            $para = mb_substr($para, PAC_PART_CHARS);
        }
        if ($cur !== '' && mb_strlen($cur) + mb_strlen($para) > PAC_PART_CHARS) {
            $parts[] = $cur;
            $cur = '';
        }
        $cur = $cur === '' ? $para : $cur . "\n\n" . $para;
    }
    if (trim($cur) !== '') {
        $parts[] = $cur;
    }
    $out = [];
    foreach ($parts as $i => $p) {
        $out[$i + 1] = $p;
    }
    return $out;
}

function pac_zip_xml(ZipArchive $zip, string $name): ?DOMDocument
{
    $xml = $zip->getFromName($name);
    if ($xml === false || $xml === '') {
        return null;
    }
    $dom = new DOMDocument();
    $ok = @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
    return $ok ? $dom : null;
}

/** Texto de cada diapositiva (y sus notas) de un PPTX. @return array<int,string> */
function pac_pptx_slides(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no puede leer archivos PPTX (falta ZipArchive).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el PPTX.');
    }
    $order = pac_pptx_order($zip);
    $out = [];
    $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    foreach ($order as $n => $slidePath) {
        $dom = pac_zip_xml($zip, $slidePath);
        if (!$dom) {
            continue;
        }
        $lines = [];
        foreach ($dom->getElementsByTagNameNS($a, 'p') as $p) {
            $line = '';
            foreach ($p->getElementsByTagNameNS($a, 't') as $t) {
                $line .= $t->textContent;
            }
            if (trim($line) !== '') {
                $lines[] = trim($line);
            }
        }
        $notesPath = pac_pptx_notes_path($zip, $slidePath);
        if ($notesPath !== '' && ($notes = pac_zip_xml($zip, $notesPath))) {
            $noteLines = [];
            foreach ($notes->getElementsByTagNameNS($a, 'p') as $p) {
                $line = '';
                foreach ($p->getElementsByTagNameNS($a, 't') as $t) {
                    $line .= $t->textContent;
                }
                $line = trim($line);
                if ($line !== '' && !ctype_digit($line)) {
                    $noteLines[] = $line;
                }
            }
            if ($noteLines) {
                $lines[] = 'Notas: ' . implode("\n", $noteLines);
            }
        }
        $out[$n] = implode("\n", $lines);
    }
    $zip->close();
    return $out;
}

/** Diapositivas en el orden de la presentación (presentation.xml), numeradas desde 1. @return array<int,string> */
function pac_pptx_order(ZipArchive $zip): array
{
    $rels = [];
    $relDom = pac_zip_xml($zip, 'ppt/_rels/presentation.xml.rels');
    if ($relDom) {
        foreach ($relDom->getElementsByTagName('Relationship') as $r) {
            $rels[$r->getAttribute('Id')] = 'ppt/' . ltrim(str_replace('../', '', $r->getAttribute('Target')), '/');
        }
    }
    $order = [];
    $pres = pac_zip_xml($zip, 'ppt/presentation.xml');
    if ($pres) {
        $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        foreach ($pres->getElementsByTagName('sldId') as $s) {
            $rid = $s->getAttributeNS($rNs, 'id');
            if (isset($rels[$rid]) && $zip->locateName($rels[$rid]) !== false) {
                $order[] = $rels[$rid];
            }
        }
    }
    if (!$order) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                $order[(int) $m[1]] = $name;
            }
        }
        ksort($order);
        $order = array_values($order);
    }
    $out = [];
    foreach ($order as $i => $p) {
        $out[$i + 1] = $p;
    }
    return $out;
}

function pac_pptx_notes_path(ZipArchive $zip, string $slidePath): string
{
    $relPath = dirname($slidePath) . '/_rels/' . basename($slidePath) . '.rels';
    $dom = pac_zip_xml($zip, $relPath);
    if (!$dom) {
        return '';
    }
    foreach ($dom->getElementsByTagName('Relationship') as $r) {
        if (str_ends_with($r->getAttribute('Type'), '/notesSlide')) {
            return 'ppt/' . ltrim(str_replace('../', '', $r->getAttribute('Target')), '/');
        }
    }
    return '';
}

/** Texto de un DOCX: por páginas si el documento marca saltos de página, si no por partes. @return array<int,string> */
function pac_docx_parts(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no puede leer archivos DOCX (falta ZipArchive).');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el DOCX.');
    }
    $dom = pac_zip_xml($zip, 'word/document.xml');
    $zip->close();
    if (!$dom) {
        return [];
    }
    $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $pages = [''];
    $breaks = 0;
    foreach ($dom->getElementsByTagNameNS($w, 'p') as $p) {
        $line = '';
        $newPage = false;
        foreach ($p->getElementsByTagNameNS($w, '*') as $node) {
            if ($node->localName === 't') {
                $line .= $node->textContent;
            } elseif ($node->localName === 'tab') {
                $line .= ' ';
            } elseif (($node->localName === 'br' && $node->getAttributeNS($w, 'type') === 'page') || $node->localName === 'lastRenderedPageBreak') {
                $newPage = true;
            }
        }
        if ($newPage && trim($pages[count($pages) - 1]) !== '') {
            $pages[] = '';
            $breaks++;
        }
        if (trim($line) !== '') {
            $pages[count($pages) - 1] .= trim($line) . "\n\n";
        }
    }
    if ($breaks >= 2) {
        $out = [];
        foreach ($pages as $i => $t) {
            $out[$i + 1] = trim($t);
        }
        return $out;
    }
    return pac_split_parts(implode("\n\n", $pages));
}

/**
 * PowerPoint 97-2003 (.ppt), lectura aproximada: recorre los registros de texto (TextCharsAtom UTF-16 y
 * TextBytesAtom) y separa diapositivas por SlidePersistAtom. @return array<int,string>
 */
function pac_ppt_slides(string $path): array
{
    $bin = (string) file_get_contents($path);
    $len = strlen($bin);
    $slides = [];
    $cur = [];
    $seen = [];
    for ($i = 0; $i + 8 <= $len; $i++) {
        $type = ord($bin[$i + 2]) | (ord($bin[$i + 3]) << 8);
        if ($type !== 0x0FA0 && $type !== 0x0FA8 && $type !== 0x03F3) {
            continue;
        }
        $verInst = ord($bin[$i]) | (ord($bin[$i + 1]) << 8);
        if (($verInst & 0x0F) !== 0) {
            continue;
        }
        $size = unpack('V', substr($bin, $i + 4, 4))[1];
        if ($type === 0x03F3) {
            if ($size === 20 && $cur) {
                $slides[] = $cur;
                $cur = [];
            }
            continue;
        }
        if ($size < 2 || $size > 65536 || $i + 8 + $size > $len) {
            continue;
        }
        $raw = substr($bin, $i + 8, $size);
        $text = $type === 0x0FA0 ? (string) @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : (string) @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        $text = trim(str_replace(["\r", "\x0B"], "\n", $text));
        if ($text === '' || !preg_match('/\p{L}{2}/u', $text) || preg_match('/[\x{E000}-\x{F8FF}\x{FFFD}]/u', $text)) {
            continue;
        }
        $key = md5($text);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $cur[] = $text;
        }
        $i += 7 + $size;
    }
    if ($cur) {
        $slides[] = $cur;
    }
    $out = [];
    foreach ($slides as $n => $lines) {
        $out[$n + 1] = implode("\n", $lines);
    }
    return $out;
}

/**
 * Respaldo para PDF sin navegador: descomprime los flujos (FlateDecode) y toma el texto de los operadores
 * Tj/TJ/'/". Sirve para PDF simples; los que usan fuentes con codificación propia salen incompletos.
 * @return array<int,string> partes de ~PAC_PART_CHARS
 */
function pac_pdf_text_fallback(string $path): array
{
    $raw = (string) file_get_contents($path);
    $chunks = [];
    if (preg_match_all('/<<(.{0,600}?)>>\s*stream\r?\n/s', $raw, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $k => $hit) {
            $dict = $m[1][$k][0];
            if (preg_match('#/(?:Subtype\s*/Image|Type\s*/XObject\s*/Subtype\s*/Image|Length1|FontFile|Type\s*/(?:XRef|ObjStm|Metadata|EmbeddedFile))#', $dict)) {
                continue;
            }
            $start = $hit[1] + strlen($hit[0]);
            $end = strpos($raw, 'endstream', $start);
            if ($end === false) {
                continue;
            }
            $data = rtrim(substr($raw, $start, $end - $start), "\r\n");
            if (str_contains($dict, '/FlateDecode')) {
                $dec = @gzuncompress($data);
                if ($dec === false) {
                    $dec = @gzinflate(substr($data, 2));
                }
                if ($dec === false) {
                    continue;
                }
                $data = $dec;
            } elseif (preg_match('#/Filter#', $dict)) {
                continue;
            }
            if (!str_contains($data, 'BT')) {
                continue;
            }
            $text = pac_pdf_stream_text($data);
            if (preg_match_all('/\p{L}/u', $text) > 20) {
                $chunks[] = $text;
            }
        }
    }
    return pac_split_parts(implode("\n\n", $chunks));
}

function pac_pdf_stream_text(string $data): string
{
    $out = '';
    if (!preg_match_all('/BT(.*?)ET/s', $data, $blocks)) {
        return '';
    }
    foreach ($blocks[1] as $block) {
        $line = '';
        preg_match_all('/\[(.*?)\]\s*TJ|(\((?:\\\\.|[^\\\\)])*\))\s*(?:Tj|\'|")|(T\*|Td|TD|Tm)/s', $block, $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            if (!empty($op[3])) {
                $line .= "\n";
                continue;
            }
            $src = $op[1] !== '' ? $op[1] : $op[2];
            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|(-?\d+(?:\.\d+)?)/s', $src, $parts, PREG_SET_ORDER)) {
                foreach ($parts as $part) {
                    if (isset($part[1]) && $part[1] !== '' && $part[0][0] !== '(') {
                        if ((float) $part[1] < -200) {
                            $line .= ' ';
                        }
                        continue;
                    }
                    $line .= pac_pdf_literal(substr($part[0], 1, -1));
                }
            }
        }
        $out .= trim(preg_replace('/\n\s*\n+/', "\n", $line) ?? $line) . "\n";
    }
    $out = mb_check_encoding($out, 'UTF-8') ? $out : (string) mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
    return trim($out);
}

function pac_pdf_literal(string $s): string
{
    return (string) preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)/', static function ($m) {
        $c = $m[1];
        return match (true) {
            $c === 'n', $c === 'r' => "\n",
            $c === 't' => ' ',
            $c === 'b', $c === 'f' => '',
            ctype_digit($c) => chr(octdec($c) & 0xFF),
            $c === "\n", $c === "\r\n" => '',
            default => $c,
        };
    }, $s);
}

/* ---------- Búsqueda ---------- */

/** Etiqueta de ubicación dentro del documento: "p. 12", "diap. 3", "parte 2". */
function pac_loc_label(string $kind, int $pageNo, string $note = ''): string
{
    $unit = PAC_DOC_KINDS[$kind][1] ?? 'p.';
    if ($kind === 'pdf' && str_contains($note, 'por partes')) {
        $unit = 'parte';
    }
    return $unit . ' ' . $pageNo;
}

/** Cita corta: «Título», p. 12 */
function pac_cite(array $hit): string
{
    return '«' . pac_trim((string) $hit['doc_title'], 90) . '», ' . pac_loc_label((string) $hit['doc_kind'], (int) $hit['page_no'], (string) ($hit['doc_note'] ?? ''));
}

/**
 * Páginas de los documentos activos más relacionadas con los términos.
 * @param list<string> $terms
 * @return list<array{id:int, doc_id:int, page_no:int, text:string, doc_title:string, doc_kind:string, doc_tag:string, score:float, stems:list<string>}>
 */
function pac_search(array $terms, int $limit = 10, array $skipIds = []): array
{
    $terms = array_values(array_unique(array_filter($terms, static fn ($t) => preg_match('/^[\p{L}\p{N}]{3,}$/u', (string) $t))));
    if (!$terms) {
        return [];
    }
    $pdo = db();
    $candidates = max(80, $limit * 6);
    if (pac_has_fts()) {
        $match = implode(' OR ', array_map(static fn ($t) => '"' . str_replace('"', '', pac_stem($t)) . '"*', $terms));
        $sql = "SELECT p.id, p.doc_id, p.page_no, p.text, d.title AS doc_title, d.kind AS doc_kind, d.tag AS doc_tag, d.note AS doc_note, d.sha256 AS doc_sha, d.original_name AS doc_file
                FROM pac_doc_fts JOIN pac_doc_pages p ON p.id = pac_doc_fts.rowid JOIN pac_docs d ON d.id = p.doc_id
                WHERE pac_doc_fts MATCH ? AND d.active = 1
                ORDER BY bm25(pac_doc_fts) LIMIT $candidates";
        $params = [$match];
    } else {
        $likes = implode(' OR ', array_fill(0, count($terms), 'p.folded LIKE ?'));
        $sql = "SELECT p.id, p.doc_id, p.page_no, p.text, d.title AS doc_title, d.kind AS doc_kind, d.tag AS doc_tag, d.note AS doc_note, d.sha256 AS doc_sha, d.original_name AS doc_file
                FROM pac_doc_pages p JOIN pac_docs d ON d.id = p.doc_id
                WHERE ($likes) AND d.active = 1 LIMIT " . ($candidates * 3);
        $params = array_map(static fn ($t) => '%' . pac_stem($t) . '%', $terms);
    }
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException) {
        return [];
    }
    $stems = array_map('pac_stem', $terms);
    $skip = array_flip($skipIds);
    $out = [];
    foreach ($rows as $row) {
        if (isset($skip[(int) $row['id']])) {
            continue;
        }
        $folded = pac_fold((string) $row['text']);
        $distinct = 0;
        $hits = 0;
        foreach ($stems as $stem) {
            $count = substr_count($folded, $stem);
            if ($count > 0) {
                $distinct++;
                $hits += min($count, 6);
            }
        }
        if ($distinct === 0) {
            continue;
        }
        $title = pac_fold((string) $row['doc_title']);
        $titleHits = count(array_filter($stems, static fn ($s) => str_contains($title, $s)));
        $score = $distinct * 10 + $hits + $titleHits * 6;
        if ($row['doc_tag'] === 'protocolo') {
            $score *= 1.3;
        }
        if (mb_strlen($folded) < 250) {
            $score *= 0.6;
        }
        $row['id'] = (int) $row['id'];
        $row['doc_id'] = (int) $row['doc_id'];
        $row['page_no'] = (int) $row['page_no'];
        $row['score'] = (float) $score;
        $row['stems'] = $stems;
        $out[] = $row;
    }
    usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($out, 0, $limit);
}

function pac_library_stats(): array
{
    $r = db()->query("SELECT COUNT(*) AS docs, COALESCE(SUM(active = 1 AND status = 'listo'), 0) AS ready FROM pac_docs")->fetch();
    return ['docs' => (int) $r['docs'], 'ready' => (int) $r['ready']];
}

/* ---------- Protocolos ---------- */

const PAC_PROTOCOL_FIELDS = [
    'nombre' => ['Patrón o condición', 160],
    'lengua' => ['Signos de lengua (glosodiagnosis, separados por coma)', 1000],
    'signos' => ['Síntomas y datos del paciente que lo indican (separados por coma)', 3000],
    'pulso' => ['Pulso (opcional; solo cuenta si se cargó el pulso)', 1000],
    'principio' => ['Principio de tratamiento', 2000],
    'meridianos' => ['Meridianos', 1000],
    'puntos' => ['Puntos para moxar (códigos: E36, V20…)', 2000],
    'moxibustion' => ['Moxibustión: método, tiempo y precauciones («No moxar…» si hay calor)', 2000],
    'tuina' => ['Tuina: maniobras, zonas/meridianos, acupresión y duración', 2000],
    'chikung' => ['Chi kung: Ba Duan Jin, respiración, sonido Liu Zi Jue, minutos en sesión y en casa', 2000],
    'ventosas' => ['Ventosas: zonas, tipo (fija, deslizante, rápida) y tiempo', 2000],
    'auriculoterapia' => ['Auriculoterapia: puntos con semillas de vaccaria', 2000],
    'tecnicas' => ['Notas para integrar las técnicas (sin agujas)', 2000],
    'sesiones' => ['Sesiones / frecuencia', 2000],
    'recomendaciones' => ['Recomendaciones para el paciente', 3000],
    'fuente' => ['Fuente (libro, página, apunte)', 500],
];
const PAC_TECHNIQUE_FIELDS = ['moxibustion', 'tuina', 'chikung', 'ventosas', 'auriculoterapia'];

function pac_protocol_seeds(): array
{
    $sesiones = 'Semanal. Sesión 1: diagnóstico y tonificación general. Sesiones 2 a 5: tratamiento del patrón. Luego controles semanales hasta 25 consultas.';
    return [
        'bazo_qi' => [
            'nombre' => 'Vacío de Qi de Bazo',
            'signos' => 'cansancio, fatiga, digestión lenta, pesadez después de comer, distensión abdominal, heces blandas, poco apetito, preocupación, rumiación, debilidad muscular',
            'lengua' => 'pálida, hinchada, marcas dentales, saburra blanca fina, centro (Bazo / Estómago)',
            'pulso' => 'débil, vacío, blando',
            'principio' => 'Tonificar el Qi de Bazo y Estómago; fortalecer la transformación y el transporte.',
            'meridianos' => 'Bazo (B), Estómago (E), Vejiga (puntos Shu del dorso), Ren Mai (RM)',
            'puntos' => 'E36, RM12, V20, V21, B6',
            'moxibustion' => 'Tonificante y central en este patrón: bastón indirecta en E36 y B6 (10–15 min); caja de moxa sobre RM12 (15–20 min) o 3–5 conos sobre jengibre; V20–V21 con caja o bastón (10 min).',
            'tuina' => 'Maniobras suaves y tonificantes: frotación circular del abdomen (mo) en sentido horario 5 min; amasado (rou) y empuje (tui) por el meridiano de Estómago en las piernas; acupresión sostenida en E36 y B6 (1 min cada uno); fricción del dorso sobre V20–V21. 20–25 min.',
            'chikung' => 'Ba Duan Jin: 3.ª pieza «Separar el cielo y la tierra» (Bazo‑Estómago), 8 repeticiones. Respiración abdominal lenta. Liu Zi Jue: sonido «HU» (Bazo), 6 repeticiones. En sesión 10 min; en casa 10–15 min por día.',
            'ventosas' => 'Fijas y suaves en V20–V21 (5–8 min) o deslizantes suaves por la Vejiga dorsal (2–3 pasadas). Evitar si hay mucha debilidad.',
            'auriculoterapia' => 'Semillas de vaccaria en Bazo, Estómago, Shenmen y Punto Cero; alternar oreja cada semana; presionar 3–5 veces por día, 1 minuto cada punto.',
            'tecnicas' => 'Priorizar calor y tonificación: moxa y tuina suave; ventosas solo suaves.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Comidas tibias y cocidas, horarios regulares, masticar tranquilo; limitar crudos, bebidas frías y exceso de lácteos. Caminatas suaves.',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
        'higado_qi' => [
            'nombre' => 'Estancamiento de Qi de Hígado',
            'signos' => 'estrés, irritabilidad, enojo, cambios de humor, suspiros, tensión, contracturas, dolor en hipocondrio, distensión, nudo en la garganta, dolor menstrual, síndrome premenstrual, cefalea tensional',
            'lengua' => 'bordes rojos, laterales (Hígado / Vesícula), color normal o levemente violáceo en los bordes',
            'pulso' => 'de cuerda, tenso',
            'principio' => 'Mover el Qi de Hígado y armonizar; calmar la mente (Shen).',
            'meridianos' => 'Hígado (H), Vesícula Biliar (VB), Pericardio (PC), Intestino Grueso (IG)',
            'puntos' => 'VB34, V18, PC6',
            'moxibustion' => 'Moxa breve en picoteo para mover el Qi (5–8 min) en VB34 y V18. No moxar si hay signos de calor (cara roja, boca amarga, lengua roja). H3 e IG4 van con acupresión en el tuina («cuatro puertas»).',
            'tuina' => 'Maniobras de dispersión: rodamiento (gun) y amasado de trapecios y cervicales; pinzado (na) de trapecios; frotación (ca) de los costados siguiendo Vesícula Biliar; acupresión en H3 + IG4 («cuatro puertas») y PC6, 1 min cada uno. 20–30 min.',
            'chikung' => 'Ba Duan Jin: 2.ª pieza «Tensar el arco» y 5.ª «Mover la cabeza y la cola». Respiración con exhalación larga. Liu Zi Jue: sonido «XU» (Hígado), 6 repeticiones. En sesión 10 min; en casa 15 min por día.',
            'ventosas' => 'Deslizantes en trapecios y dorsal alto (3–5 pasadas) y fijas 5–10 min en V18 si hay contractura.',
            'auriculoterapia' => 'Semillas de vaccaria en Shenmen, Hígado, Simpático y Punto Cero (zona del dolor si hay cefalea o contractura); alternar oreja cada semana; presionar 3–5 veces por día y en momentos de tensión.',
            'tecnicas' => 'Técnicas para mover y armonizar; poco calor si hay irritabilidad o signos de calor.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Pausas con respiración lenta durante el día, actividad física moderada, menos alcohol, café y fritos, acostarse antes de las 23 hs.',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
        'rinon_yin' => [
            'nombre' => 'Vacío de Yin de Riñón',
            'signos' => 'sudor nocturno, calores, sofocos, boca seca, garganta seca, insomnio, lumbalgia, zumbidos, mareos, menopausia, calor en palmas y plantas',
            'lengua' => 'roja, sin saburra, pelada, grietas, raíz (Riñón)',
            'pulso' => 'fino, rápido, vacío en la raíz',
            'principio' => 'Nutrir el Yin de Riñón y eliminar el calor por vacío.',
            'meridianos' => 'Riñón (R), Vejiga (V), Ren Mai (RM), Bazo (B)',
            'puntos' => 'R3, R6, B6, RM4',
            'moxibustion' => 'No moxar: hay calor por vacío de Yin. R3, R6, B6 y RM4 se trabajan con acupresión suave dentro del tuina.',
            'tuina' => 'Maniobras suaves y nutritivas, ritmo lento: amasado suave lumbar; frotación de plantas (R1) sin calentar en exceso; acupresión sostenida en R3, R6 y B6 (1 min cada uno). 20 min.',
            'chikung' => 'Ba Duan Jin: 6.ª pieza «Tocarse los pies para fortalecer los riñones», suave, 6 repeticiones. Respiración lenta y larga. Liu Zi Jue: sonido «CHUI» (Riñón), 6 repeticiones. En sesión 10 min; en casa 10–15 min, mejor antes de dormir.',
            'ventosas' => 'Solo rápidas o muy suaves (fijas 3–5 min) en zona lumbar si hay contractura; evitar si hay mucha sequedad o debilidad.',
            'auriculoterapia' => 'Semillas de vaccaria en Riñón, Endocrino, Shenmen y Subcórtex (sueño); alternar oreja cada semana; presionar 3 veces por día y antes de dormir.',
            'tecnicas' => 'Nutrir y calmar: sin moxa; tuina suave y chi kung.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Descanso suficiente, evitar trasnochar, menos picantes, alcohol y café; comidas húmedas y nutritivas (sopas, legumbres, sésamo).',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
        'rinon_yang' => [
            'nombre' => 'Vacío de Yang de Riñón',
            'signos' => 'frío, friolento, manos y pies fríos, lumbalgia, dolor lumbar, rodillas débiles, orina frecuente, orina clara, nicturia, cansancio, libido baja, edemas en piernas',
            'lengua' => 'pálida, húmeda, hinchada, raíz (Riñón)',
            'pulso' => 'profundo, débil, lento',
            'principio' => 'Tonificar y calentar el Yang de Riñón.',
            'meridianos' => 'Riñón (R), Vejiga (V), Du Mai (DU), Ren Mai (RM)',
            'puntos' => 'V23, DU4, RM4, RM6, R3',
            'moxibustion' => 'Moxa como técnica central: caja de moxa en zona lumbar (V23–DU4) 15–20 min; conos sobre jengibre en RM4 y RM6 (3–5 conos); bastón indirecta en R3 (10 min).',
            'tuina' => 'Frotación (ca) transversal de la zona lumbar hasta sentir calor; amasado de lumbares y glúteos; acupresión en R3 y V23; frotación de plantas (R1). 20–30 min.',
            'chikung' => 'Ba Duan Jin: 6.ª pieza «Tocarse los pies para fortalecer los riñones» y 8.ª «Sacudir el cuerpo (elevar los talones)». Respiración abdominal. Liu Zi Jue: sonido «CHUI» (Riñón). En sesión 10 min; en casa 15 min por día, por la mañana.',
            'ventosas' => 'Fijas 5–10 min en zona lumbar (V23), antes de la moxa.',
            'auriculoterapia' => 'Semillas de vaccaria en Riñón, Endocrino, Subcórtex y Punto Cero; alternar oreja cada semana; presionar 3–5 veces por día.',
            'tecnicas' => 'Calentar y tonificar: moxa y tuina de frotación.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Abrigar la zona lumbar y los pies, comidas calientes (legumbres, sopas, nueces), movimiento suave (chi kung), evitar el frío.',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
        'sangre_estasis' => [
            'nombre' => 'Estasis de Sangre',
            'signos' => 'dolor fijo, dolor punzante, dolor que empeora de noche, hematomas, coágulos menstruales, dolor menstrual intenso, várices, masas',
            'lengua' => 'violácea, púrpura, petequias, manchas violáceas, venas sublinguales dilatadas',
            'pulso' => 'rugoso, áspero, de cuerda',
            'principio' => 'Mover la Sangre, eliminar la estasis y aliviar el dolor.',
            'meridianos' => 'Bazo (B), Hígado (H), Vejiga (V)',
            'puntos' => 'V17, B10, puntos Ashi',
            'moxibustion' => 'Bastón en picoteo sobre la zona del dolor y V17 (5–10 min), solo si mejora con calor; no moxar si la zona está inflamada o caliente, ni sobre várices.',
            'tuina' => 'Maniobras para mover Sangre: amasado profundo y fricción alrededor (no encima) de la zona dolorosa; acupresión en B10 y V17; estiramientos suaves. 20–30 min. No trabajar sobre várices ni hematomas.',
            'chikung' => 'Ba Duan Jin completo y suave (circulación general). Respiración abdominal. Liu Zi Jue: sonido «XU» (Hígado). En sesión 10 min; en casa 15 min por día.',
            'ventosas' => 'Fijas 5–10 min alrededor de la zona dolorosa y en V17; deslizantes en la espalda si la piel lo permite. Nunca sobre várices, hematomas ni piel lesionada.',
            'auriculoterapia' => 'Semillas de vaccaria en la zona del dolor, Shenmen, Simpático e Hígado; alternar oreja cada semana; presionar 3–5 veces por día.',
            'tecnicas' => 'Mover Sangre con ventosas y tuina; moxa solo si el dolor mejora con calor.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Movimiento diario suave, calor local si alivia (no si está hinchado o caliente), evitar posturas fijas prolongadas.',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
        'humedad_flema' => [
            'nombre' => 'Humedad / Flema',
            'signos' => 'pesadez, cuerpo pesado, hinchazón, edemas, retención de líquidos, flemas, mucosidad, náuseas, mente nublada, sobrepeso, heces pastosas, somnolencia',
            'lengua' => 'saburra gruesa, saburra pegajosa, grasosa, hinchada',
            'pulso' => 'resbaladizo, deslizante, blando',
            'principio' => 'Transformar la Humedad y la Flema; tonificar el Bazo.',
            'meridianos' => 'Estómago (E), Bazo (B), Ren Mai (RM)',
            'puntos' => 'RM12, E36, B9, E40, V20',
            'moxibustion' => 'Caja de moxa sobre RM12 (15 min) o conos sobre jengibre; bastón indirecta en E36 y B9 (10 min cada uno). Si la saburra es amarilla y gruesa (Humedad-Calor), no moxar: acupresión.',
            'tuina' => 'Frotación abdominal circular en sentido horario 5–8 min; amasado de piernas por el meridiano de Estómago; acupresión en E40 y B9; fricción del dorso en V20. 20–25 min.',
            'chikung' => 'Ba Duan Jin: 1.ª pieza «Sostener el cielo» y 3.ª «Separar el cielo y la tierra». Respiración con énfasis en la exhalación. Liu Zi Jue: sonido «HU» (Bazo). En sesión 10 min; en casa 15–20 min por día.',
            'ventosas' => 'Deslizantes en el dorso por la Vejiga (3–5 pasadas) y fijas 5–10 min en V20; en abdomen solo rápidas y suaves.',
            'auriculoterapia' => 'Semillas de vaccaria en Bazo, Estómago, Endocrino y Punto Cero (Shenmen si hay ansiedad por comer); alternar oreja cada semana; presionar antes de cada comida.',
            'tecnicas' => 'Transformar Humedad: ventosas deslizantes, moxa si hay frío, tuina abdominal.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Menos harinas refinadas, azúcar, lácteos, fritos y alcohol; verduras cocidas, legumbres, arroz, algo de jengibre; actividad física regular.',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
        'musculo' => [
            'nombre' => 'Dolor musculoesquelético',
            'categoria' => 'condicion',
            'signos' => 'dolor, contractura, cervicalgia, dolor cervical, lumbalgia, dolor lumbar, dolor de hombro, dolor de rodilla, ciática, tortícolis, tendinitis, artrosis, rigidez, esguince',
            'lengua' => '',
            'pulso' => '',
            'principio' => 'Mover Qi y Sangre en los canales de la zona y aliviar el dolor; tratar además el patrón de base.',
            'meridianos' => 'Los que recorren la zona (cervical: VB, ID, SJ; lumbar: V, R; hombro: IG, SJ; rodilla: E, B, VB)',
            'puntos' => 'Zona del dolor y puntos Ashi (cervical: VB20, VB21; lumbar: V23, V25, V40; hombro: IG15, SJ14; rodilla: E35, VB34, E36)',
            'moxibustion' => 'Bastón indirecta o en picoteo 5–10 min sobre la zona y los puntos si el dolor mejora con calor (dolor por frío o crónico); caja de moxa en zona lumbar. No moxar en inflamación aguda o zona caliente.',
            'tuina' => 'Rodamiento (gun), amasado y pinzado de la musculatura contracturada; movilizaciones y estiramientos pasivos suaves; acupresión en puntos Ashi y distales (ID3, SJ5, V40). 20–30 min.',
            'chikung' => 'Ba Duan Jin con amplitud adaptada al dolor (2.ª «Tensar el arco» para cervical y hombro; 6.ª para lumbar). Respiración lenta. En sesión 10 min; en casa 10 min por día, sin dolor.',
            'ventosas' => 'Fijas 5–10 min sobre la musculatura contracturada o deslizantes con aceite (3–5 pasadas).',
            'auriculoterapia' => 'Semillas de vaccaria en la zona del dolor (cervical, lumbar, hombro o rodilla), Shenmen y Subcórtex; alternar oreja cada semana; presionar al sentir dolor y 3 veces por día.',
            'tecnicas' => 'Tuina y ventosas locales; moxa si mejora con calor; tratar además el patrón de base.',
            'sesiones' => $sesiones,
            'recomendaciones' => 'Movilidad suave diaria sin forzar el dolor, calor local 15–20 minutos si alivia, cuidar la postura y hacer pausas activas.',
            'fuente' => 'Protocolo base FluxusTerapia (editable)',
        ],
    ];
}

/** Carga los protocolos base. $onlyMissing: solo los que no existen (por seed_key). */
function pac_protocols_seed(PDO $pdo, bool $onlyMissing = true): int
{
    $existing = array_flip($pdo->query("SELECT seed_key FROM pac_protocols WHERE seed_key <> ''")->fetchAll(PDO::FETCH_COLUMN));
    $n = 0;
    $sort = 10;
    foreach (pac_protocol_seeds() as $key => $p) {
        $sort += 10;
        if ($onlyMissing && isset($existing[$key])) {
            continue;
        }
        $cols = array_keys(PAC_PROTOCOL_FIELDS);
        $pdo->prepare('INSERT INTO pac_protocols (' . implode(', ', $cols) . ', categoria, seed_key, sort_order) VALUES (' . implode(', ', array_fill(0, count($cols) + 3, '?')) . ')')
            ->execute([...array_map(static fn ($c) => (string) ($p[$c] ?? ''), $cols), (string) ($p['categoria'] ?? 'patron'), $key, $sort]);
        $n++;
    }
    return $n;
}

function pac_protocols(bool $activeOnly = false): array
{
    return db()->query('SELECT * FROM pac_protocols' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_order, nombre')->fetchAll();
}

function pac_protocol(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM pac_protocols WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function pac_protocol_save(array $in, int $id = 0): int
{
    $vals = [];
    foreach (PAC_PROTOCOL_FIELDS as $key => [$label, $max]) {
        $v = trim(str_replace(["\r\n", "\r"], "\n", is_string($in[$key] ?? null) ? $in[$key] : ''));
        if (mb_strlen($v) > $max) {
            throw new RuntimeException('«' . $label . '» es demasiado largo.');
        }
        $vals[$key] = $v;
    }
    if ($vals['nombre'] === '') {
        throw new RuntimeException('Escribí el nombre del patrón o condición.');
    }
    $categoria = ($in['categoria'] ?? '') === 'condicion' ? 'condicion' : 'patron';
    $active = ($in['active'] ?? '1') === '1' ? 1 : 0;
    $cols = array_keys($vals);
    if ($id > 0) {
        $set = implode(', ', array_map(static fn ($c) => "$c = ?", $cols));
        db()->prepare("UPDATE pac_protocols SET $set, categoria = ?, active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([...array_values($vals), $categoria, $active, $id]);
        return $id;
    }
    db()->prepare('INSERT INTO pac_protocols (' . implode(', ', $cols) . ', categoria, active) VALUES (' . implode(', ', array_fill(0, count($cols) + 2, '?')) . ')')
        ->execute([...array_values($vals), $categoria, $active]);
    return (int) db()->lastInsertId();
}

function pac_protocol_delete(int $id): void
{
    db()->prepare('DELETE FROM pac_protocols WHERE id = ?')->execute([$id]);
}

/** Códigos de puntos escritos en un texto ("E36, B6 y V20" → [E36, B6, V20]). */
function pac_point_codes(string $text): array
{
    preg_match_all('/\b(PC|IG|ID|SJ|VB|RM|DU|TR|E|B|V|R|H|C|P)\s?-?\s?(\d{1,2})\b/u', $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $out[$x[1] . $x[2]] = true;
    }
    return array_keys($out);
}
