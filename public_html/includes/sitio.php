<?php

declare(strict_types=1);

/**
 * Contenido editable del sitio público (textos, contacto, estilo y fotos).
 *
 * Lo que edita el cliente vive en data/sitio/contenido.json y uploads/sitio/,
 * carpetas que deploy.py nunca sube ni pisa. Si no hay nada guardado, todo
 * sale con los valores de abajo, que reproducen la home original.
 */

const SITIO_HISTORY_KEEP = 40;

function sitio_root(): string
{
    return dirname(__DIR__);
}

function sitio_data_dir(): string
{
    return sitio_root() . '/data/sitio';
}

function sitio_content_file(): string
{
    return sitio_data_dir() . '/contenido.json';
}

function sitio_history_dir(): string
{
    return sitio_data_dir() . '/historial';
}

function sitio_uploads_dir(): string
{
    return sitio_root() . '/uploads/sitio';
}

function sitio_uploads_url(): string
{
    return '/uploads/sitio/';
}

function sitio_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------ */
/* Definiciones: textos, contacto, estilo y lugares de fotos           */
/* ------------------------------------------------------------------ */

/**
 * Secciones de textos de la home. Tipos: line (una línea), text (varias líneas),
 * rich (párrafos con **negrita** y [enlaces](https://...)), list (un ítem por línea), url.
 * 'optional' => true permite dejarlo vacío para ocultarlo.
 */
function sitio_text_schema(): array
{
    $services = [
        ['Masoterapia', 'Masaje relax y descontracturante, moxibustión, ventosaterapia y auriculoterapia complementaria.'],
        ['Rehabilitación kinésica', 'Tratamiento personalizado para recuperar movilidad, aliviar molestias y acompañar la rehabilitación.'],
        ['Talleres hipopresivos', 'Práctica postural y respiratoria para tonificar el core, mejorar la postura y cuidar el suelo pélvico.'],
        ['Curso gym pre y post parto', 'Entrenamiento adaptado para embarazadas y recuperación post parto, con foco en fuerza, postura y bienestar.'],
        ['Chi kung Online', 'Movimiento, respiración y conciencia para cultivar energía vital y equilibrio interno. Modalidad online.'],
        ['Taichi Online', 'Secuencias fluidas de movimiento consciente que favorecen la calma, la coordinación y la presencia. Modalidad online.'],
        ['Medicina china', 'Enfoque integral para armonizar el cuerpo desde la tradición de la Medicina China.'],
        ['', ''],
        ['', ''],
    ];
    $serviceFields = [
        'servicios.title' => ['Título de la sección', 'line', 'Servicios'],
    ];
    foreach ($services as $i => [$title, $text]) {
        $n = $i + 1;
        $extra = $n > 7 ? ' (extra, opcional)' : '';
        $serviceFields["servicios.s{$n}_title"] = ["Servicio {$n}{$extra}: nombre", 'line', $title, true];
        $serviceFields["servicios.s{$n}_text"] = ["Servicio {$n}: descripción", 'text', $text, true];
    }

    return [
        'hero' => [
            'label' => 'Portada',
            'anchor' => 'inicio',
            'help' => 'Lo primero que se ve al entrar al sitio.',
            'fields' => [
                'hero.title' => ['Título grande', 'line', 'FluxusTerapia'],
                'hero.subtitle' => ['Frase debajo del título', 'text', 'Kinesiología, Medicina China y Acupuntura', true],
                'hero.btn1' => ['Botón 1: texto', 'line', 'Reservar turno', true],
                'hero.btn1_url' => ['Botón 1: a dónde lleva', 'url', 'turnos/'],
                'hero.btn2' => ['Botón 2: texto', 'line', 'AcademiaFluxus', true],
                'hero.btn2_url' => ['Botón 2: a dónde lleva', 'url', 'academia/'],
            ],
        ],
        'quienes' => [
            'label' => 'Quiénes somos',
            'anchor' => 'quienes',
            'help' => 'Presentación del equipo, al lado de la foto.',
            'fields' => [
                'quienes.title' => ['Título', 'line', 'Quiénes somos'],
                'quienes.body' => ['Texto', 'rich', "Somos un equipo de profesionales dedicados al servicio de la salud: kinesiología, acupuntura, masoterapia, medicina china y técnicas manuales. Nuestro propósito es que cada persona se sienta bien, en su cuerpo y en su día a día.\n\nAcompañamos cada proceso con escucha, presencia y tratamientos personalizados para aliviar molestias, recuperar movilidad y cuidar tu bienestar de forma clara, humana y cercana.\n\nConocé a quienes formamos FluxusTerapia en [Nosotros](#nosotros)."],
                'quienes.place' => ['Línea destacada final', 'line', 'Punta Alta · Coronel Suárez · Online', true],
            ],
        ],
        'servicios' => [
            'label' => 'Servicios',
            'anchor' => 'servicios',
            'help' => 'Las tarjetas de servicios. Si borrás el nombre de un servicio, esa tarjeta no se muestra.',
            'fields' => $serviceFields,
        ],
        'nosotros' => [
            'label' => 'Nosotros',
            'anchor' => 'nosotros',
            'help' => 'Encabezado de la sección del equipo. Las fichas de cada profesional se cargan en el Generador de clases → Equipo.',
            'fields' => [
                'nosotros.kicker' => ['Texto chico arriba del título', 'line', 'Equipo', true],
                'nosotros.title' => ['Título', 'line', 'Nosotros'],
                'nosotros.intro' => ['Texto de presentación', 'text', 'Somos un equipo de profesionales de la salud: kinesiología, masoterapia, medicina china y acupuntura.', true],
                'nosotros.empty' => ['Mensaje si todavía no hay profesionales cargados', 'line', 'Muy pronto vas a conocer acá a cada profesional del equipo.'],
            ],
        ],
        'acupuntura' => [
            'label' => 'Acupuntura',
            'anchor' => 'acupuntura',
            'help' => 'Sección de acupuntura y ventosas, con dos fotos.',
            'fields' => [
                'acupuntura.kicker' => ['Texto chico arriba del título', 'line', 'Medicina china · Kinesiología', true],
                'acupuntura.title' => ['Título', 'line', 'Acupuntura y ventosas'],
                'acupuntura.body' => ['Texto', 'rich', 'Trabajamos sobre los meridianos y puntos de la Medicina China, combinados con kinesiología y técnicas manuales, para aliviar molestias, liberar tensiones y recuperar el equilibrio del cuerpo.', true],
                'acupuntura.list' => ['Lista (un ítem por renglón)', 'list', "**Acupuntura** para dolor, contracturas, estrés y descanso.\n**Ventosas** para soltar la musculatura y activar la circulación.\n**Kinesiología y técnicas manuales** para recuperar movilidad.", true],
                'acupuntura.btn1' => ['Botón de turnos: texto', 'line', 'Reservar turno', true],
                'acupuntura.btn2' => ['Botón de WhatsApp: texto', 'line', 'Consultar por WhatsApp', true],
            ],
        ],
        'instagram' => [
            'label' => 'Instagram',
            'anchor' => 'instagram',
            'help' => 'Banda verde que invita a seguir el Instagram. El link se toma de Contacto y WhatsApp.',
            'fields' => [
                'instagram.kicker' => ['Texto chico arriba del título', 'line', 'Seguinos', true],
                'instagram.title' => ['Título', 'line', '@fluxusterapia'],
                'instagram.body' => ['Texto', 'text', 'Turnos, tips de bienestar y el día a día del consultorio. El sitio y el Instagram son el mismo espacio: FluxusTerapia.', true],
                'instagram.btn' => ['Botón: texto', 'line', 'Abrir Instagram'],
            ],
        ],
        'contacto' => [
            'label' => 'Contacto',
            'anchor' => 'contacto',
            'help' => 'Parte final de la home. El email, WhatsApp, sedes y horarios se cambian en Contacto y WhatsApp.',
            'fields' => [
                'contacto.title' => ['Título', 'line', 'Conectá con tu bienestar'],
                'contacto.body' => ['Texto', 'rich', 'Si querés iniciar o profundizar un proceso terapéutico, escribinos o pedí turno por Instagram. Te acompañamos con presencia y una propuesta clara.', true],
                'contacto.btn1' => ['Botón de email: texto', 'line', 'Escribinos', true],
                'contacto.btn2' => ['Botón de la academia: texto', 'line', 'AcademiaFluxus', true],
                'contacto.btn3' => ['Botón de Instagram: texto', 'line', 'Pedir turno en IG', true],
                'contacto.h_contact' => ['Título del bloque de contacto', 'line', 'Contacto'],
                'contacto.h_places' => ['Título del bloque de sedes', 'line', 'Consultorios'],
                'contacto.h_hours' => ['Título del bloque de horarios', 'line', 'Atención'],
            ],
        ],
        'seo' => [
            'label' => 'Google y redes',
            'anchor' => 'inicio',
            'help' => 'Cómo aparece el sitio en Google y cuando se comparte el link por WhatsApp o redes.',
            'fields' => [
                'seo.title' => ['Título de la pestaña y de Google', 'line', 'FluxusTerapia · Kinesiologia y Terapia Integrativa'],
                'seo.description' => ['Descripción para Google', 'text', 'FluxusTerapia — Masoterapia, Rehabilitación kinésica, Taichi y Chikung, medicina china y movimiento consciente. Un equipo de profesionales de la salud. Seguinos en Instagram @fluxusterapia.'],
            ],
        ],
    ];
}

function sitio_text_defaults(): array
{
    static $defaults = null;
    if ($defaults === null) {
        $defaults = [];
        foreach (sitio_text_schema() as $section) {
            foreach ($section['fields'] as $key => $field) {
                $defaults[$key] = $field[2];
            }
        }
    }
    return $defaults;
}

function sitio_contact_defaults(): array
{
    return [
        'email' => 'hola@fluxusterapia.com',
        'wa_number' => '5492932537949',
        'wa_label' => '2932 537949',
        'wa_message' => 'Hola FluxusTerapia, quiero consultar por un turno',
        'wa_message_acu' => 'Hola FluxusTerapia, quiero consultar por acupuntura y ventosas',
        'phone' => '',
        'instagram' => 'https://www.instagram.com/fluxusterapia/',
        'youtube' => 'https://www.youtube.com/@fluxusterapia',
        'facebook' => '',
        'sedes' => [
            ['name' => 'Punta Alta', 'address' => '', 'maps' => '', 'hours' => ''],
            ['name' => 'Coronel Suárez', 'address' => '', 'maps' => '', 'hours' => ''],
        ],
        'online_note' => 'Academia, Cursos y Talleres Modalidad Online',
        'hours' => 'Con turno previo',
    ];
}

/** Tipografías permitidas (Google Fonts). 'q' es el fragmento de la URL de Google Fonts. */
function sitio_fonts(): array
{
    return [
        'display' => [
            'cormorant' => ['Cormorant Garamond (actual)', '"Cormorant Garamond", Georgia, serif', 'Cormorant+Garamond:wght@600;700'],
            'playfair' => ['Playfair Display', '"Playfair Display", Georgia, serif', 'Playfair+Display:wght@600;700'],
            'lora' => ['Lora', '"Lora", Georgia, serif', 'Lora:wght@600;700'],
            'ebgaramond' => ['EB Garamond', '"EB Garamond", Georgia, serif', 'EB+Garamond:wght@600;700'],
            'libre' => ['Libre Baskerville', '"Libre Baskerville", Georgia, serif', 'Libre+Baskerville:wght@400;700'],
            'cinzel' => ['Cinzel', '"Cinzel", Georgia, serif', 'Cinzel:wght@600;700'],
            'montserrat' => ['Montserrat', '"Montserrat", sans-serif', 'Montserrat:wght@600;700'],
            'josefin' => ['Josefin Sans', '"Josefin Sans", sans-serif', 'Josefin+Sans:wght@600;700'],
            'raleway' => ['Raleway', '"Raleway", sans-serif', 'Raleway:wght@600;700'],
            'outfit' => ['Outfit', '"Outfit", sans-serif', 'Outfit:wght@600;700'],
        ],
        'body' => [
            'outfit' => ['Outfit (actual)', '"Outfit", sans-serif', 'Outfit:wght@400;500;600;700'],
            'inter' => ['Inter', '"Inter", sans-serif', 'Inter:wght@400;500;600;700'],
            'dmsans' => ['DM Sans', '"DM Sans", sans-serif', 'DM+Sans:wght@400;500;600;700'],
            'nunito' => ['Nunito', '"Nunito", sans-serif', 'Nunito:wght@400;500;600;700'],
            'opensans' => ['Open Sans', '"Open Sans", sans-serif', 'Open+Sans:wght@400;500;600;700'],
            'lato' => ['Lato', '"Lato", sans-serif', 'Lato:wght@400;700'],
            'poppins' => ['Poppins', '"Poppins", sans-serif', 'Poppins:wght@400;500;600;700'],
            'worksans' => ['Work Sans', '"Work Sans", sans-serif', 'Work+Sans:wght@400;500;600;700'],
            'mulish' => ['Mulish', '"Mulish", sans-serif', 'Mulish:wght@400;500;600;700'],
            'montserrat' => ['Montserrat', '"Montserrat", sans-serif', 'Montserrat:wght@400;500;600;700'],
            'raleway' => ['Raleway', '"Raleway", sans-serif', 'Raleway:wght@400;500;600;700'],
            'lora' => ['Lora (con serifa)', '"Lora", Georgia, serif', 'Lora:wght@400;500;600;700'],
        ],
    ];
}

function sitio_scales(): array
{
    return [
        'chica' => ['Más chico', 0.9375, 0.88],
        'normal' => ['Normal (actual)', 1.0, 1.0],
        'grande' => ['Más grande', 1.0625, 1.12],
        'muygrande' => ['Bien grande', 1.125, 1.25],
    ];
}

/** Paletas de color de acento con buen contraste sobre blanco. [nombre, acento, acento suave] */
function sitio_accents(): array
{
    return [
        'fluxus' => ['Verde Fluxus (actual)', '#2f8f6b', '#a8d4c0'],
        'bosque' => ['Verde bosque', '#3b7349', '#b9d8b8'],
        'mar' => ['Azul mar', '#25707e', '#a9d6dd'],
        'terracota' => ['Terracota', '#a5532f', '#ecc7b2'],
        'ciruela' => ['Ciruela', '#844a77', '#dcc0d6'],
        'dorado' => ['Dorado', '#8a6a1f', '#e6d6a8'],
    ];
}

function sitio_style_defaults(): array
{
    return ['font_display' => 'cormorant', 'font_body' => 'outfit', 'text_scale' => 'normal', 'title_scale' => 'normal', 'accent' => 'fluxus'];
}

/** Lugares de fotos de la home y cómo se optimiza cada uno. */
function sitio_photo_slots(): array
{
    return [
        'hero' => [
            'label' => 'Portada',
            'where' => 'Fondo grande de la portada, arriba de todo.',
            'ratio' => null,
            'widths' => [800, 1280, 1920],
            'jpg' => 1600,
            'sizes' => '100vw',
            'alt' => false,
            'img_attrs' => 'fetchpriority="high"',
            'default' => ['srcset' => 'img/hero-800.webp 800w, img/hero-1280.webp 1280w, img/hero-1920.webp 1920w', 'src' => 'img/hero.jpg?v=2', 'w' => 2000, 'h' => 1334, 'preview' => 'img/hero-800.webp'],
            'tip' => 'Horizontal, con espacio libre al centro para el título. Se ve oscurecida para que el texto se lea.',
        ],
        'about' => [
            'label' => 'Quiénes somos',
            'where' => 'Foto al lado del texto "Quiénes somos".',
            'ratio' => 1200 / 777,
            'widths' => [640, 1200],
            'jpg' => 1200,
            'sizes' => '(min-width: 800px) 45vw, 100vw',
            'alt' => 'Sesión de acupuntura en FluxusTerapia',
            'img_attrs' => 'loading="lazy" decoding="async"',
            'default' => ['srcset' => 'img/about-640.webp 640w, img/about-1200.webp 1200w', 'src' => 'img/about.jpg?v=2', 'w' => 1200, 'h' => 777, 'preview' => 'img/about-640.webp'],
            'tip' => 'Horizontal. Se recorta automáticamente a formato apaisado (3:2).',
        ],
        'servicios' => [
            'label' => 'Fondo de Servicios',
            'where' => 'Fondo oscurecido detrás de las tarjetas de servicios.',
            'ratio' => null,
            'widths' => [900, 1600],
            'jpg' => 1600,
            'sizes' => '100vw',
            'alt' => false,
            'img_attrs' => '',
            'default' => ['srcset' => 'img/servicios-900.webp 900w, img/servicios-1600.webp 1600w', 'src' => 'img/servicios.jpg?v=2', 'w' => 1600, 'h' => 1067, 'preview' => 'img/servicios-900.webp'],
            'tip' => 'Horizontal. Se ve muy oscurecida, ideal una foto con poco detalle.',
        ],
        'acu_main' => [
            'label' => 'Acupuntura (foto principal)',
            'where' => 'Foto grande de la sección Acupuntura.',
            'ratio' => 660 / 585,
            'widths' => [660, 1000],
            'jpg' => 1000,
            'sizes' => '(min-width: 900px) 480px, 85vw',
            'alt' => 'Modelo de acupuntura con los meridianos y puntos marcados',
            'img_attrs' => 'loading="lazy" decoding="async"',
            'default' => ['srcset' => 'img/acupuntura-modelo-660.webp', 'src' => 'img/acupuntura-modelo.jpg?v=1', 'w' => 660, 'h' => 585, 'preview' => 'img/acupuntura-modelo-660.webp'],
            'tip' => 'Casi cuadrada. Se recorta automáticamente.',
        ],
        'acu_float' => [
            'label' => 'Acupuntura (foto chica)',
            'where' => 'Foto chica superpuesta, abajo a la derecha de la principal.',
            'ratio' => 4 / 3,
            'widths' => [480, 800],
            'jpg' => 800,
            'sizes' => '(min-width: 900px) 260px, 45vw',
            'alt' => 'Terapia con ventosas sobre la espalda',
            'img_attrs' => 'loading="lazy" decoding="async"',
            'default' => ['srcset' => 'img/ventosas-640.webp', 'src' => 'img/ventosas.jpg?v=1', 'w' => 1200, 'h' => 777, 'preview' => 'img/ventosas-640.webp'],
            'tip' => 'Horizontal. Se recorta a 4:3.',
        ],
    ];
}

/* ------------------------------------------------------------------ */
/* Lectura y guardado                                                  */
/* ------------------------------------------------------------------ */

function sitio_empty_data(): array
{
    return ['v' => 1, 'texts' => [], 'contact' => [], 'style' => [], 'photos' => []];
}

function sitio_normalize(mixed $data): array
{
    $base = sitio_empty_data();
    if (!is_array($data)) {
        return $base;
    }
    foreach (['texts', 'contact', 'style', 'photos'] as $k) {
        $base[$k] = isset($data[$k]) && is_array($data[$k]) ? $data[$k] : [];
    }
    if (isset($data['updated_at'])) {
        $base['updated_at'] = (string) $data['updated_at'];
    }
    return $base;
}

function sitio_load(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $file = sitio_content_file();
    $data = null;
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
    }
    $cache = sitio_normalize($data);
    return $cache;
}

function sitio_ensure_dirs(): void
{
    foreach ([sitio_root() . '/data', sitio_data_dir(), sitio_history_dir(), sitio_root() . '/uploads', sitio_uploads_dir()] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }
    $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\nOptions -Indexes\n";
    foreach ([sitio_root() . '/data/.htaccess', sitio_data_dir() . '/.htaccess'] as $ht) {
        if (!is_file($ht)) {
            @file_put_contents($ht, $deny);
        }
    }
    $noExec = sitio_root() . '/uploads/.htaccess';
    if (!is_file($noExec)) {
        @file_put_contents($noExec, sitio_uploads_htaccess());
    }
}

function sitio_uploads_htaccess(): string
{
    return "# Solo fotos: nunca se ejecuta ni se sirve código desde acá.\n"
        . "Options -Indexes\n"
        . "<FilesMatch \"(?i)\\.(php\\d?|phtml|phar|pl|py|cgi|sh|shtml|html?|svg|js|htaccess|user\\.ini)$\">\n"
        . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
        . "  <IfModule !mod_authz_core.c>\n    Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n";
}

function sitio_write_atomic(string $file, string $contents): bool
{
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/** Guarda una copia del contenido actual en el historial antes de cambiarlo. */
function sitio_backup_current(string $label): void
{
    $file = sitio_content_file();
    $current = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    $entry = [
        'at' => date('Y-m-d H:i:s'),
        'label' => $label,
        'data' => sitio_normalize($current),
    ];
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
    sitio_write_atomic(sitio_history_dir() . '/' . $name, (string) json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $all = sitio_history_files();
    foreach (array_slice($all, SITIO_HISTORY_KEEP) as $old) {
        @unlink($old);
    }
}

/** Archivos del historial, del más nuevo al más viejo. */
function sitio_history_files(): array
{
    $files = glob(sitio_history_dir() . '/*.json') ?: [];
    rsort($files, SORT_STRING);
    return $files;
}

function sitio_history_list(): array
{
    $out = [];
    foreach (sitio_history_files() as $file) {
        $entry = json_decode((string) @file_get_contents($file), true);
        if (!is_array($entry)) {
            continue;
        }
        $out[] = ['id' => basename($file, '.json'), 'at' => (string) ($entry['at'] ?? ''), 'label' => (string) ($entry['label'] ?? 'Cambio')];
    }
    return $out;
}

/**
 * Guarda el contenido nuevo. $label describe el cambio ("Textos: Portada")
 * y queda en el historial para poder deshacerlo.
 */
function sitio_save(array $data, string $label): bool
{
    sitio_ensure_dirs();
    $lock = @fopen(sitio_data_dir() . '/.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        $data = sitio_normalize($data);
        $data['updated_at'] = date('Y-m-d H:i:s');
        sitio_backup_current($label);
        $ok = sitio_write_atomic(sitio_content_file(), (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if ($ok) {
            sitio_load(true);
            sitio_prune_photos();
        }
        return $ok;
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Deshace el último cambio: vuelve a la copia más reciente y la saca del historial. */
function sitio_undo(): ?string
{
    $files = sitio_history_files();
    if (!$files) {
        return null;
    }
    $entry = json_decode((string) @file_get_contents($files[0]), true);
    if (!is_array($entry)) {
        @unlink($files[0]);
        return null;
    }
    sitio_ensure_dirs();
    $data = sitio_normalize($entry['data'] ?? null);
    $data['updated_at'] = date('Y-m-d H:i:s');
    if (!sitio_write_atomic(sitio_content_file(), (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))) {
        return null;
    }
    @unlink($files[0]);
    sitio_load(true);
    sitio_prune_photos();
    return (string) ($entry['label'] ?? 'Cambio');
}

/** Vuelve a una versión puntual del historial (el estado actual queda guardado, así se puede volver). */
function sitio_restore(string $id): bool
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{6}$/', $id)) {
        return false;
    }
    $file = sitio_history_dir() . '/' . $id . '.json';
    if (!is_file($file)) {
        return false;
    }
    $entry = json_decode((string) @file_get_contents($file), true);
    if (!is_array($entry)) {
        return false;
    }
    return sitio_save(sitio_normalize($entry['data'] ?? null), 'Antes de volver a la versión del ' . (string) ($entry['at'] ?? ''));
}

/** Borra fotos subidas que ya no usa ni el contenido actual ni ninguna copia del historial. */
function sitio_prune_photos(): void
{
    $dir = sitio_uploads_dir();
    if (!is_dir($dir)) {
        return;
    }
    $used = [];
    $collect = static function (array $data) use (&$used): void {
        foreach ($data['photos'] ?? [] as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            foreach ($photo['webp'] ?? [] as $f) {
                $used[basename((string) $f)] = true;
            }
            if (!empty($photo['jpg'])) {
                $used[basename((string) $photo['jpg'])] = true;
            }
        }
    };
    $collect(sitio_load(true));
    foreach (sitio_history_files() as $file) {
        $entry = json_decode((string) @file_get_contents($file), true);
        if (is_array($entry) && is_array($entry['data'] ?? null)) {
            $collect($entry['data']);
        }
    }
    foreach (glob($dir . '/*.{webp,jpg}', GLOB_BRACE) ?: [] as $file) {
        if (!isset($used[basename($file)]) && filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Valores efectivos                                                   */
/* ------------------------------------------------------------------ */

function sitio_text(string $key): string
{
    $data = sitio_load();
    $defaults = sitio_text_defaults();
    if (array_key_exists($key, $data['texts']) && is_string($data['texts'][$key])) {
        return $data['texts'][$key];
    }
    return (string) ($defaults[$key] ?? '');
}

function sitio_contact(): array
{
    $c = sitio_contact_defaults();
    foreach (sitio_load()['contact'] as $k => $v) {
        if (!array_key_exists($k, $c)) {
            continue;
        }
        if ($k === 'sedes') {
            if (is_array($v)) {
                $c['sedes'] = array_values(array_filter($v, 'is_array'));
            }
        } elseif (is_string($v)) {
            $c[$k] = $v;
        }
    }
    return $c;
}

function sitio_style(): array
{
    $s = sitio_style_defaults();
    $fonts = sitio_fonts();
    foreach (sitio_load()['style'] as $k => $v) {
        if (is_string($v) && array_key_exists($k, $s)) {
            $s[$k] = $v;
        }
    }
    if (!isset($fonts['display'][$s['font_display']])) {
        $s['font_display'] = 'cormorant';
    }
    if (!isset($fonts['body'][$s['font_body']])) {
        $s['font_body'] = 'outfit';
    }
    foreach (['text_scale', 'title_scale'] as $k) {
        if (!isset(sitio_scales()[$s[$k]])) {
            $s[$k] = 'normal';
        }
    }
    if (!isset(sitio_accents()[$s['accent']])) {
        $s['accent'] = 'fluxus';
    }
    return $s;
}

function sitio_wa_url(?string $message = null): string
{
    $c = sitio_contact();
    $number = preg_replace('/\D+/', '', $c['wa_number']) ?: '';
    $msg = $message ?? $c['wa_message'];
    $url = 'https://wa.me/' . $number;
    return trim($msg) !== '' ? $url . '?text=' . rawurlencode($msg) : $url;
}

function sitio_maps_url(array $sede): string
{
    $maps = trim((string) ($sede['maps'] ?? ''));
    if ($maps !== '' && sitio_valid_url($maps)) {
        return $maps;
    }
    $address = trim((string) ($sede['address'] ?? ''));
    if ($address === '') {
        return '';
    }
    $query = $address;
    $name = trim((string) ($sede['name'] ?? ''));
    if ($name !== '' && stripos($address, $name) === false) {
        $query .= ', ' . $name;
    }
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}

/** "instagram.com/fluxusterapia" a partir de la URL completa. */
function sitio_url_label(string $url): string
{
    $label = preg_replace('~^https?://(www\.)?~i', '', trim($url)) ?? $url;
    return rtrim($label, '/');
}

function sitio_instagram_handle(): string
{
    $path = trim((string) parse_url(sitio_contact()['instagram'], PHP_URL_PATH), '/');
    $handle = explode('/', $path)[0] ?? '';
    return $handle !== '' ? '@' . $handle : '@fluxusterapia';
}

/* ------------------------------------------------------------------ */
/* Formato seguro de textos                                            */
/* ------------------------------------------------------------------ */

function sitio_valid_url(string $url): bool
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\s<>"\'`]/', $url)) {
        return false;
    }
    if (preg_match('~^https?://~i', $url)) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    return false;
}

/** Links permitidos dentro de los textos y botones: web, email, teléfono, anclas o páginas del sitio. */
function sitio_safe_link(string $url): ?string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\s<>"\'`\\\\]/', $url)) {
        return null;
    }
    if (sitio_valid_url($url)) {
        return $url;
    }
    if (preg_match('/^mailto:[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $url) || preg_match('/^tel:\+?[0-9-]{6,20}$/', $url)) {
        return $url;
    }
    if (preg_match('~^(#[A-Za-z0-9_-]+|/(?!/)[A-Za-z0-9_\-./?=&%#]*|[A-Za-z0-9_-][A-Za-z0-9_\-./?=&%#]*)$~', $url) && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
        return $url;
    }
    return null;
}

function sitio_is_external(string $url): bool
{
    return (bool) preg_match('~^https?://~i', $url) && !preg_match('~^https?://(www\.)?fluxusterapia\.com(/|$)~i', $url);
}

/** Escapa todo y después aplica **negrita**, [texto](link) y saltos de línea. */
function sitio_inline(string $text, string $linkClass = ''): string
{
    $html = sitio_e(trim($text));
    $html = preg_replace_callback('/\[([^\]\n]{1,200})\]\(([^)\s]{1,500})\)/u', static function (array $m) use ($linkClass): string {
        $url = sitio_safe_link(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if ($url === null) {
            return $m[1];
        }
        $attrs = $linkClass !== '' ? ' class="' . sitio_e($linkClass) . '"' : '';
        if (sitio_is_external($url)) {
            $attrs .= ' target="_blank" rel="noopener noreferrer"';
        }
        return '<a' . $attrs . ' href="' . sitio_e($url) . '">' . $m[1] . '</a>';
    }, $html) ?? $html;
    $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
    return nl2br($html, false);
}

/** Párrafos separados por una línea en blanco. */
function sitio_paragraphs(string $text, string $linkClass = '', string $pAttrs = ''): string
{
    $text = str_replace("\r\n", "\n", trim($text));
    if ($text === '') {
        return '';
    }
    $out = [];
    foreach (preg_split('/\n\s*\n/', $text) ?: [] as $para) {
        if (trim($para) !== '') {
            $out[] = '<p' . $pAttrs . '>' . sitio_inline($para, $linkClass) . '</p>';
        }
    }
    return implode("\n", $out);
}

function sitio_list_items(string $text): array
{
    $items = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            $items[] = sitio_inline($line);
        }
    }
    return $items;
}

/** Texto de una línea, escapado (para títulos y botones). */
function sitio_t(string $key): string
{
    return sitio_inline(sitio_text($key));
}

/* ------------------------------------------------------------------ */
/* Fotos y estilo                                                      */
/* ------------------------------------------------------------------ */

/** Foto vigente de un lugar (subida por el cliente o la original). */
function sitio_photo(string $slot): array
{
    $slots = sitio_photo_slots();
    $def = $slots[$slot];
    $saved = sitio_load()['photos'][$slot] ?? null;
    $alt = $def['alt'] === false ? '' : (string) $def['alt'];
    if (is_array($saved) && isset($saved['alt']) && is_string($saved['alt']) && $def['alt'] !== false) {
        $alt = $saved['alt'];
    }
    if (is_array($saved) && !empty($saved['webp']) && !empty($saved['jpg'])) {
        $base = sitio_uploads_url();
        $files = [];
        foreach ((array) $saved['webp'] as $w => $file) {
            $name = basename((string) $file);
            if (preg_match('/^[a-z0-9_-]+\.webp$/', $name) && is_file(sitio_uploads_dir() . '/' . $name)) {
                $files[(int) $w] = $base . $name;
            }
        }
        $jpg = basename((string) $saved['jpg']);
        if ($files && preg_match('/^[a-z0-9_-]+\.jpg$/', $jpg) && is_file(sitio_uploads_dir() . '/' . $jpg)) {
            ksort($files);
            $srcset = [];
            foreach ($files as $w => $url) {
                $srcset[] = $url . ' ' . $w . 'w';
            }
            return [
                'custom' => true,
                'srcset' => implode(', ', $srcset),
                'webp' => $files,
                'src' => $base . $jpg,
                'w' => (int) ($saved['w'] ?? 0),
                'h' => (int) ($saved['h'] ?? 0),
                'alt' => $alt,
                'preview' => reset($files),
            ];
        }
    }
    return [
        'custom' => false,
        'srcset' => $def['default']['srcset'],
        'webp' => [],
        'src' => $def['default']['src'],
        'w' => $def['default']['w'],
        'h' => $def['default']['h'],
        'alt' => $alt,
        'preview' => $def['default']['preview'],
    ];
}

/** <picture> listo para la home, igual al HTML original si no hay foto nueva. */
function sitio_picture(string $slot, bool $decorative = false): string
{
    $def = sitio_photo_slots()[$slot];
    $p = sitio_photo($slot);
    $sizes = '';
    if ($p['custom'] || str_contains($def['default']['srcset'], ' ')) {
        $sizes = ' sizes="' . sitio_e($def['sizes']) . '"';
    }
    $attrs = $def['img_attrs'] !== '' ? ' ' . $def['img_attrs'] : '';
    $src = $p['src'];
    return '<picture>'
        . '<source type="image/webp" srcset="' . sitio_e($p['srcset']) . '"' . $sizes . '>'
        . '<img src="' . sitio_e($src) . '" alt="' . ($decorative ? '' : sitio_e($p['alt'])) . '" width="' . (int) $p['w'] . '" height="' . (int) $p['h'] . '"' . $attrs . '>'
        . '</picture>';
}

function sitio_absolute_url(string $path): string
{
    if (preg_match('~^https?://~', $path)) {
        return $path;
    }
    return 'https://fluxusterapia.com/' . ltrim(preg_replace('/\?.*$/', '', $path) ?? $path, '/');
}

function sitio_fonts_url(): string
{
    $s = sitio_style();
    $fonts = sitio_fonts();
    $families = [];
    if ($s['font_display'] !== $s['font_body']) {
        $families[] = $fonts['display'][$s['font_display']][2];
    }
    $families[] = $fonts['body'][$s['font_body']][2];
    return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', $families) . '&display=swap';
}

/** Variables CSS que pisan el estilo base solo si el cliente cambió algo. */
function sitio_style_css(): string
{
    $s = sitio_style();
    $d = sitio_style_defaults();
    $fonts = sitio_fonts();
    $scales = sitio_scales();
    $root = [];
    if ($s['font_display'] !== $d['font_display']) {
        $root[] = '--font-display:' . $fonts['display'][$s['font_display']][1];
    }
    if ($s['font_body'] !== $d['font_body']) {
        $root[] = '--font-body:' . $fonts['body'][$s['font_body']][1];
    }
    if ($s['accent'] !== $d['accent']) {
        $a = sitio_accents()[$s['accent']];
        $root[] = '--accent:' . $a[1];
        $root[] = '--accent-soft:' . $a[2];
    }
    $css = '';
    if ($root) {
        $css .= ':root{' . implode(';', $root) . '}';
    }
    if ($s['text_scale'] !== 'normal') {
        $css .= 'html{font-size:' . round($scales[$s['text_scale']][1] * 100, 2) . '%}';
    }
    if ($s['title_scale'] !== 'normal') {
        $k = $scales[$s['title_scale']][2];
        $titles = [
            '.hero h1' => 'clamp(3.2rem, 10vw, 5.6rem)',
            '.about h2, .acu-copy h2, .team h2' => 'clamp(2.2rem, 4vw, 3rem)',
            '.services h2' => 'clamp(2rem, 4vw, 2.6rem)',
            '.instagram h2' => 'clamp(2.4rem, 6vw, 3.4rem)',
            '.contact h2' => 'clamp(2rem, 4vw, 2.8rem)',
            '.gallery-intro h1, .gallery-intro h2' => 'clamp(2.2rem, 5vw, 3.2rem)',
        ];
        foreach ($titles as $sel => $size) {
            $css .= $sel . '{font-size:calc(' . $size . ' * ' . $k . ')}';
        }
    }
    return $css;
}

/** Fondo de la sección Servicios cuando hay una foto nueva (va en un <style>). */
function sitio_services_bg_css(): string
{
    $p = sitio_photo('servicios');
    if (!$p['custom']) {
        return '';
    }
    $small = sitio_e(reset($p['webp']));
    $large = sitio_e(end($p['webp']));
    $jpg = sitio_e($p['src']);
    return '.services-media{background:var(--services-shade),url("' . $jpg . '") center 35% / cover no-repeat;'
        . 'background:var(--services-shade),image-set(url("' . $large . '") type("image/webp"),url("' . $jpg . '") type("image/jpeg")) center 35% / cover no-repeat}'
        . '@media (max-width: 800px){.services-media{background:var(--services-shade),image-set(url("' . $small . '") type("image/webp"),url("' . $jpg . '") type("image/jpeg")) center 35% / cover no-repeat}}';
}

/** Para galería y blogs: fuentes, estilo personalizado e Instagram desde la misma fuente que la home. */
function sitio_public_parts(): array
{
    return [
        'fonts' => sitio_fonts_url(),
        'css' => sitio_style_css(),
        'instagram' => sitio_contact()['instagram'],
    ];
}

/** Link de fuentes + estilo personalizado (en una sola pieza). */
function sitio_head_html(): string
{
    $html = '<link rel="stylesheet" href="' . sitio_e(sitio_fonts_url()) . '">';
    $css = sitio_style_css();
    if ($css !== '') {
        $html .= "\n  <style id=\"sitio-estilo\">" . $css . '</style>';
    }
    return $html;
}
