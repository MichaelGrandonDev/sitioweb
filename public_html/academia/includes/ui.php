<?php

declare(strict_types=1);

/** Íconos de trazo (24×24) para la interfaz del campus y del panel. */
function aca_icon(string $name, string $class = 'ico'): string
{
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-6h4v6"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.6 3.4-5.5 6.5-5.5s5.7 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.8c1.9.7 3.1 2.4 3.5 5.2"/>',
        'video' => '<rect x="2.5" y="5.5" width="13" height="13" rx="2.5"/><path d="m15.5 10 6-3.5v11l-6-3.5"/>',
        'play' => '<circle cx="12" cy="12" r="9.5"/><path d="m10 8.5 5.5 3.5-5.5 3.5z"/>',
        'chat' => '<path d="M20.5 12a8 8 0 0 1-11.7 7.1L3.5 20.5l1.4-4.9A8 8 0 1 1 20.5 12Z"/>',
        'book' => '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H20v15H5.5A1.5 1.5 0 0 0 4 19.5z"/><path d="M4 19.5A1.5 1.5 0 0 0 5.5 21H20"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'inbox' => '<path d="M3 13.5 5.5 5h13l2.5 8.5V19a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19z"/><path d="M3 13.5h5l1.5 2.5h5l1.5-2.5h5"/>',
        'key' => '<circle cx="8" cy="15" r="4.5"/><path d="m11.2 11.8 9.3-9.3"/><path d="m17 6 3 3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4.4 4.2-6.5 8-6.5s7 2.1 8 6.5"/>',
        'logout' => '<path d="M15 4h3.5A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5H15"/><path d="M10 16.5 14.5 12 10 7.5"/><path d="M14.5 12H4"/>',
        'calendar' => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
        'pen' => '<path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
        'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"/>',
        'mail' => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'whatsapp' => '<path d="M20.5 12a8.5 8.5 0 0 1-12.6 7.4L3.5 20.5l1.2-4.2A8.5 8.5 0 1 1 20.5 12Z"/><path d="M9 8.5c.3 2.8 3 5.6 6 6l1-1.5-2-1-1 .8a5 5 0 0 1-2.3-2.3l.8-1-1-2z"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'upload' => '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
        'link' => '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3.2-3.2a4.5 4.5 0 0 0-6.4-6.4L12 5.6"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3.2 3.2a4.5 4.5 0 0 0 6.4 6.4l1.2-1.2"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-up' => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'arrow-down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'gift' => '<rect x="3.5" y="8.5" width="17" height="4" rx="1"/><path d="M5 12.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7.5M12 8.5V21"/><path d="M12 8.5C10.5 5 7 4.5 7 6.5S10 8.5 12 8.5ZM12 8.5c1.5-3.5 5-4 5-2s-3 2-5 2Z"/>',
        'award' => '<circle cx="12" cy="9" r="6"/><path d="m9.5 8.8 1.8 1.8 3.3-3.3"/><path d="M8.5 14 7 21.5l5-2.5 5 2.5-1.5-7.5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 19V7.5A1.5 1.5 0 0 1 5.5 6H10"/>',
        'clock' => '<circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3 2"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'type' => '<path d="M4 7V4.5h16V7"/><path d="M12 4.5v15"/><path d="M8.5 19.5h7"/>',
        'globe' => '<circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19"/><path d="M12 2.5c2.6 2.8 3.9 6 3.9 9.5s-1.3 6.7-3.9 9.5c-2.6-2.8-3.9-6-3.9-9.5s1.3-6.7 3.9-9.5Z"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.6-4.5L3 9"/><path d="M3 4v5h5"/><path d="M4 13a8 8 0 0 0 14.6 4.5L21 15"/><path d="M21 20v-5h-5"/>',
    ];
    $d = $paths[$name] ?? $paths['spark'];
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/**
 * Mensaje de felicitación al completar todas las clases de un curso.
 * $bonusCount > 0 invita a ver las bonus (que quedan desbloqueadas).
 */
function aca_course_done_banner(string $courseTitle, int $courseId, int $bonusCount, bool $hidden = false, string $extraClass = ''): string
{
    $invite = $bonusCount > 0
        ? '<p>Como regalo, se desbloquearon ' . ($bonusCount === 1 ? 'una clase bonus' : $bonusCount . ' clases bonus') . ' para que sigas practicando.</p>'
          . '<a class="btn primary small-btn" href="clases.php?course_id=' . $courseId . '#bonus-' . $courseId . '">' . aca_icon('gift') . ' Ver clases bonus</a>'
        : '<p>Podés volver a ver cualquier clase cuando quieras.</p>';
    return '<div class="done-banner' . ($extraClass !== '' ? ' ' . h($extraClass) : '') . '" role="status"' . ($hidden ? ' hidden' : '') . '>'
        . '<span class="done-banner-ico">' . aca_icon('award') . '</span>'
        . '<div><strong>¡Buen trabajo!</strong>'
        . '<p class="done-banner-lead">Completaste todas las clases de ' . h($courseTitle) . '.</p>'
        . $invite . '</div></div>';
}

function aca_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '?';
    }
    return function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper($name[0]);
}

/**
 * Bloque "Tu profesor" del curso. $variant: full (página del curso) o compact (tarjeta / clases).
 * No imprime nada si el curso no tiene profesor cargado.
 */
function aca_teacher_block(array $course, string $variant = 'full'): string
{
    if (!course_has_teacher($course)) {
        return '';
    }
    $name = trim((string) ($course['teacher_name'] ?? ''));
    $bio = trim((string) ($course['teacher_bio'] ?? ''));
    $photoUrl = course_teacher_url($course);
    $displayName = $name !== '' ? $name : 'Profesor/a';
    $isCompact = $variant === 'compact';
    $bioLimit = $isCompact ? 90 : 280;
    $bioShown = $bio !== '' ? ($isCompact ? short_text($bio, $bioLimit) : $bio) : '';

    $photo = $photoUrl !== ''
        ? '<img class="teacher-photo" src="' . h($photoUrl) . '" alt="' . h($displayName) . '" width="96" height="96" loading="lazy" decoding="async">'
        : '<span class="teacher-avatar" aria-hidden="true">' . h(aca_initial($displayName)) . '</span>';

    $html = '<aside class="teacher-block' . ($isCompact ? ' is-compact' : '') . '">';
    $html .= $photo;
    $html .= '<div class="teacher-meta">';
    if (!$isCompact) {
        $html .= '<p class="teacher-label">Tu profesor</p>';
    }
    $html .= '<strong class="teacher-name">' . h($displayName) . '</strong>';
    if ($bioShown !== '') {
        $html .= $isCompact
            ? '<span class="teacher-bio">' . h($bioShown) . '</span>'
            : '<p class="teacher-bio">' . nl2br(h($bioShown)) . '</p>';
    }
    $html .= '</div></aside>';
    return $html;
}

function aca_first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return (string) ($parts[0] ?? $name);
}

/** "hace 3 días" a partir de una fecha UTC de SQLite. */
function aca_time_ago(?string $utc): string
{
    if ($utc === null || trim($utc) === '') {
        return 'Nunca';
    }
    $ts = strtotime($utc . ' UTC');
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Recién';
    }
    if ($diff < 3600) {
        $m = (int) floor($diff / 60);
        return 'Hace ' . $m . ' min';
    }
    if ($diff < 86400) {
        $hrs = (int) floor($diff / 3600);
        return 'Hace ' . $hrs . ($hrs === 1 ? ' hora' : ' horas');
    }
    $days = (int) floor($diff / 86400);
    if ($days < 30) {
        return $days === 1 ? 'Ayer' : 'Hace ' . $days . ' días';
    }
    return date('d/m/Y', $ts);
}

/** Contraseña legible para dar de alta alumnos (sin caracteres que se confunden). */
function aca_generate_password(int $length = 10): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

function aca_login_url(): string
{
    global $config;
    return rtrim((string) ($config['site_url'] ?? 'https://fluxusterapia.com'), '/') . '/academia/login.php?as=alumno';
}

/** URL del sitio público FluxusTerapia (home), no del campus. */
function aca_site_home_url(): string
{
    global $config;
    $url = rtrim((string) ($config['site_url'] ?? 'https://www.fluxusterapia.com'), '/');
    return $url !== '' ? $url . '/' : 'https://www.fluxusterapia.com/';
}

/** Texto listo para mandar por WhatsApp con los datos de acceso. */
function aca_access_message(string $name, string $email, string $password): string
{
    return 'Hola ' . aca_first_name($name) . '! Ya tenés acceso al Campus de AcademiaFluxus para ver las clases grabadas.' . "\n\n"
        . 'Ingresá en: ' . aca_login_url() . "\n"
        . 'Email: ' . $email . "\n"
        . 'Contraseña: ' . $password;
}
