<?php
/** Home pública. La renderiza /index.php con los textos y fotos de includes/sitio.php. */
$c = sitio_contact();
$hero = sitio_photo('hero');
$ogImage = sitio_absolute_url($hero['custom'] ? $hero['src'] : 'img/hero.jpg');
$customCss = sitio_style_css() . sitio_services_bg_css();
$igUrl = $c['instagram'];
$ytUrl = $c['youtube'];
$fbUrl = $c['facebook'];
$waUrl = sitio_wa_url();
$handle = sitio_instagram_handle();
$email = trim($c['email']);
$phoneDigits = preg_replace('/[^0-9+]/', '', $c['phone']) ?? '';
$acuDefaultPhoto = !sitio_photo('acu_main')['custom'];
$sedesSimple = true;
foreach ($c['sedes'] as $sede) {
    if (trim((string) ($sede['address'] ?? '')) !== '' || trim((string) ($sede['hours'] ?? '')) !== '' || trim((string) ($sede['maps'] ?? '')) !== '') {
        $sedesSimple = false;
    }
}
$serviceIcons = [
    '<path d="M12 30c4-8 8-12 12-12s8 4 12 12"/><path d="M18 34h12"/><circle cx="24" cy="16" r="3"/>',
    '<path d="M14 34c2-8 6-14 10-18 4 4 8 10 10 18"/><path d="M18 36h12"/><path d="M24 10v6"/>',
    '<path d="M16 34c0-8 3-14 8-18 5 4 8 10 8 18"/><path d="M20 34a4 4 0 0 0 8 0"/><path d="M14 22h20"/>',
    '<circle cx="24" cy="28" r="8"/><path d="M18 20c2-6 4-8 6-8s4 2 6 8"/><path d="M16 36h16"/>',
    '<path d="M8 30c6-10 10-14 16-14s10 4 16 14"/><path d="M12 22c4-7 7-10 12-10s8 3 12 10"/><path d="M16 36c3-5 5-7 8-7s5 2 8 7"/>',
    '<circle cx="24" cy="24" r="14"/><path d="M24 14v10l6 4"/>',
    '<path d="M24 8c-2 6-8 10-8 18a8 8 0 0 0 16 0c0-8-6-12-8-18z"/><path d="M16 28c-4 2-7 6-7 10"/><path d="M32 28c4 2 7 6 7 10"/>',
    '<path d="M24 38c-8-4-13-10-13-17a7 7 0 0 1 13-4 7 7 0 0 1 13 4c0 7-5 13-13 17z"/>',
    '<path d="M10 38c2-14 10-24 28-28-2 18-12 26-28 28z"/><path d="M10 38 26 22"/>',
];
$btn = static function (string $key, string $href, string $class, bool $external = false): string {
    $text = trim(sitio_text($key));
    if ($text === '') {
        return '';
    }
    $ext = $external ? ' target="_blank" rel="noopener noreferrer"' : '';
    return '<a class="' . $class . '" href="' . sitio_e($href) . '"' . $ext . '>' . sitio_inline($text) . '</a>';
};
$link = static function (string $key): string {
    return sitio_safe_link(sitio_text($key)) ?? (string) sitio_text_defaults()[$key];
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= sitio_e(sitio_text('seo.title')) ?></title>
  <meta name="description" content="<?= sitio_e(sitio_text('seo.description')) ?>">
  <meta name="author" content="FluxusTerapia">
  <link rel="canonical" href="https://fluxusterapia.com/">

  <meta property="og:type" content="website">
  <meta property="og:locale" content="es_AR">
  <meta property="og:url" content="https://fluxusterapia.com/">
  <meta property="og:title" content="FluxusTerapia">
  <meta property="og:description" content="Kinesiología, Medicina China y Acupuntura. @fluxusterapia">
  <meta property="og:image" content="<?= sitio_e($ogImage) ?>">
  <meta property="og:see_also" content="<?= sitio_e($igUrl) ?>">

  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="FluxusTerapia">
  <meta name="twitter:description" content="Kinesiología, Medicina China y Acupuntura. Instagram @fluxusterapia">

  <meta name="theme-color" content="#0f3d36">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= sitio_e(sitio_fonts_url()) ?>">
  <link rel="stylesheet" href="css/style.css?v=20261114b">
<?php if ($customCss !== ''): ?>
  <style id="sitio-estilo"><?= $customCss ?></style>
<?php endif; ?>
  <link rel="icon" href="img/favicon-48.png" type="image/png" sizes="48x48">
  <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
  <script type="speculationrules">
  {
    "prerender": [{
      "where": {"and": [
        {"href_matches": "/*"},
        {"not": {"href_matches": "/academia/*"}},
        {"not": {"href_matches": "/turnos/*"}},
        {"not": {"href_matches": "/cursos/*"}},
        {"not": {"href_matches": "/*admin*"}},
        {"not": {"href_matches": "/*install*"}},
        {"not": {"href_matches": "/*logout*"}},
        {"not": {"href_matches": "/*api.php*"}},
        {"not": {"href_matches": "/*/uploads/*"}},
        {"not": {"href_matches": "/*.pdf"}},
        {"not": {"selector_matches": "[target=_blank], [download], [data-lightbox], [rel~=nofollow]"}}
      ]},
      "eagerness": "moderate"
    }]
  }
  </script>
</head>
<body>
  <a class="skip-link" href="#contenido">Saltar al contenido</a>
  <header class="site-header">
    <a class="brand" href="/" title="FluxusTerapia · Inicio">
      <picture>
        <source type="image/webp" srcset="img/logo-circle-136.webp">
        <img
          class="brand-logo"
          src="img/logo-circle.png"
          alt="FluxusTerapia"
          width="68"
          height="68"
          decoding="async"
        >
      </picture>
    </a>
    <nav class="site-nav" aria-label="Principal">
      <a href="#inicio">Inicio</a>
      <a href="#servicios">Servicios</a>
      <a href="#nosotros">Nosotros</a>
      <a href="galeria/">Galería</a>
      <a href="#contacto">Contacto</a>
      <a href="blogs/">Blogs</a>
      <details class="nav-drop">
        <summary class="nav-academia" aria-label="AcademiaFluxus: clases en vivo y cursos grabados">AcademiaFluxus<svg class="nav-caret" viewBox="0 0 12 12" width="10" height="10" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>
        <div class="nav-drop-menu">
          <div class="nav-drop-panel">
            <a href="academia/login.php?as=alumno">Portal del estudiante<small>Clases en vivo, grabadas y consultas</small></a>
            <a href="academia/inscripcion.php">Inscribirme<small>Pedí tu cupo en la academia</small></a>
          </div>
        </div>
      </details>
      <a
        class="nav-admin"
        href="academia/login.php?as=admin"
        aria-label="Panel de administración"
        title="Panel de administración"
      >
        <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
          <rect x="5" y="11" width="14" height="10" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/>
          <path d="M8 11V8a4 4 0 0 1 8 0v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          <circle cx="12" cy="16" r="1.4" fill="currentColor"/>
        </svg>
        <span>Admin</span>
      </a>
    </nav>
    <div class="social" aria-label="Redes">
<?php if ($igUrl !== ''): ?>
      <a
        class="social-ig"
        href="<?= sitio_e($igUrl) ?>"
        target="_blank"
        rel="noopener noreferrer me"
        aria-label="Instagram <?= sitio_e($handle) ?>"
        title="Instagram"
      >
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <defs>
            <radialGradient id="igGrad" cx="30%" cy="107%" r="150%">
              <stop offset="0%" stop-color="#fdf497"/>
              <stop offset="5%" stop-color="#fdf497"/>
              <stop offset="45%" stop-color="#fd5949"/>
              <stop offset="60%" stop-color="#d6249f"/>
              <stop offset="90%" stop-color="#285AEB"/>
            </radialGradient>
          </defs>
          <path fill="url(#igGrad)" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
        </svg>
      </a>
<?php endif; ?>
<?php if ($ytUrl !== ''): ?>
      <a
        class="social-yt"
        href="<?= sitio_e($ytUrl) ?>"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="YouTube FluxusTerapia"
        title="YouTube"
      >
        <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">
          <path fill="#FF0000" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814z"/>
          <path fill="#fff" d="M9.545 15.568V8.432L15.818 12l-6.273 3.568z"/>
        </svg>
      </a>
<?php endif; ?>
<?php if ($fbUrl !== ''): ?>
      <a
        class="social-fb"
        href="<?= sitio_e($fbUrl) ?>"
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Facebook FluxusTerapia"
        title="Facebook"
      >
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <path fill="#1877F2" d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/>
        </svg>
      </a>
<?php endif; ?>
    </div>
  </header>

  <main id="contenido">
    <section id="inicio" class="hero">
      <div class="hero-media" aria-hidden="true">
        <?= sitio_picture('hero', true) ?>
      </div>
      <div class="hero-copy">
        <h1><?= sitio_t('hero.title') ?></h1>
<?php if (trim(sitio_text('hero.subtitle')) !== ''): ?>
        <p class="hero-sub"><?= sitio_t('hero.subtitle') ?></p>
<?php endif; ?>
        <div class="hero-actions">
          <?= $btn('hero.btn1', $link('hero.btn1_url'), 'cta primary') ?>
          <?= $btn('hero.btn2', $link('hero.btn2_url'), 'cta ghost') ?>
        </div>
      </div>
    </section>

    <section id="quienes" class="about">
      <div class="about-copy">
        <h2><?= sitio_t('quienes.title') ?></h2>
        <?= sitio_paragraphs(sitio_text('quienes.body'), 'about-link') ?>
<?php if (trim(sitio_text('quienes.place')) !== ''): ?>
        <p class="about-place"><?= sitio_t('quienes.place') ?></p>
<?php endif; ?>
      </div>
      <div class="about-media">
        <?= sitio_picture('about') ?>
      </div>
    </section>

    <section id="servicios" class="services">
      <div class="services-media" aria-hidden="true"></div>
      <div class="services-inner">
        <h2><?= sitio_t('servicios.title') ?></h2>
        <div class="services-grid">
<?php for ($i = 1; $i <= 9; $i++): ?>
<?php if (trim(sitio_text("servicios.s{$i}_title")) === '') { continue; } ?>
          <article class="service">
            <span class="service-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48" width="42" height="42" fill="none" stroke="currentColor" stroke-width="1.6"><?= $serviceIcons[$i - 1] ?></svg>
            </span>
            <h3><?= sitio_t("servicios.s{$i}_title") ?></h3>
<?php if (trim(sitio_text("servicios.s{$i}_text")) !== ''): ?>
            <p><?= sitio_t("servicios.s{$i}_text") ?></p>
<?php endif; ?>
          </article>
<?php endfor; ?>
        </div>
      </div>
    </section>

    <section id="nosotros" class="team" aria-labelledby="nosotros-title">
      <div class="team-inner">
<?php if (trim(sitio_text('nosotros.kicker')) !== ''): ?>
        <p class="team-kicker"><?= sitio_t('nosotros.kicker') ?></p>
<?php endif; ?>
        <h2 id="nosotros-title"><?= sitio_t('nosotros.title') ?></h2>
<?php if (trim(sitio_text('nosotros.intro')) !== ''): ?>
        <p class="team-intro"><?= sitio_t('nosotros.intro') ?></p>
<?php endif; ?>
        <p class="team-empty" data-team-empty><?= sitio_t('nosotros.empty') ?></p>
        <div class="team-grid" data-team-grid hidden></div>
      </div>
    </section>

    <section id="acupuntura" class="acu" aria-labelledby="acu-title">
      <div class="acu-inner">
        <div class="acu-media">
          <figure class="acu-photo acu-photo--main">
            <?= sitio_picture('acu_main') ?>
          </figure>
          <figure class="acu-photo acu-photo--float">
            <?= sitio_picture('acu_float') ?>
          </figure>
        </div>
        <div class="acu-copy">
<?php if (trim(sitio_text('acupuntura.kicker')) !== ''): ?>
          <p class="acu-kicker"><?= sitio_t('acupuntura.kicker') ?></p>
<?php endif; ?>
          <h2 id="acu-title"><?= sitio_t('acupuntura.title') ?></h2>
          <?= sitio_paragraphs(sitio_text('acupuntura.body')) ?>
<?php $acuItems = sitio_list_items(sitio_text('acupuntura.list')); ?>
<?php if ($acuItems): ?>
          <ul class="acu-list">
<?php foreach ($acuItems as $item): ?>
            <li><?= $item ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
          <div class="acu-actions">
            <?= $btn('acupuntura.btn1', 'turnos/', 'cta primary') ?>
            <?= $btn('acupuntura.btn2', sitio_wa_url($c['wa_message_acu']), 'cta secondary', true) ?>
          </div>
<?php if ($acuDefaultPhoto): ?>
          <p class="acu-credit">
            Foto del modelo de acupuntura:
            <a href="https://commons.wikimedia.org/wiki/File:Male_acupuncture_figure,_Hong_Kong,_1970-1976_Wellcome_L0057959.jpg" target="_blank" rel="noopener noreferrer">Wellcome Collection</a>,
            <a href="https://creativecommons.org/licenses/by/4.0/deed.es" target="_blank" rel="noopener noreferrer">CC BY 4.0</a> (recortada).
          </p>
<?php endif; ?>
        </div>
      </div>
    </section>

    <section id="instagram" class="instagram">
      <div class="instagram-inner">
<?php if (trim(sitio_text('instagram.kicker')) !== ''): ?>
        <p class="instagram-kicker"><?= sitio_t('instagram.kicker') ?></p>
<?php endif; ?>
        <h2><?= sitio_t('instagram.title') ?></h2>
<?php if (trim(sitio_text('instagram.body')) !== ''): ?>
        <p><?= sitio_t('instagram.body') ?></p>
<?php endif; ?>
        <?= $btn('instagram.btn', $igUrl ?: 'https://www.instagram.com/fluxusterapia/', 'cta primary', true) ?>
      </div>
    </section>

    <section id="contacto" class="contact">
      <div class="contact-copy">
        <h2><?= sitio_t('contacto.title') ?></h2>
        <?= sitio_paragraphs(sitio_text('contacto.body')) ?>
        <div class="hero-actions">
          <?= $email !== '' ? $btn('contacto.btn1', 'mailto:' . $email, 'cta primary') : '' ?>
          <?= $btn('contacto.btn2', 'academia/', 'cta secondary') ?>
          <?= $igUrl !== '' ? $btn('contacto.btn3', $igUrl, 'cta secondary', true) : '' ?>
        </div>
      </div>
      <div class="contact-details">
        <div>
          <h3><?= sitio_t('contacto.h_contact') ?></h3>
<?php if ($email !== ''): ?>
          <p><a href="mailto:<?= sitio_e($email) ?>"><?= sitio_e($email) ?></a></p>
<?php endif; ?>
<?php if ($c['wa_number'] !== ''): ?>
          <p>
            <a
              href="<?= sitio_e($waUrl) ?>"
              target="_blank"
              rel="noopener noreferrer"
            >WhatsApp <?= sitio_e($c['wa_label']) ?></a>
          </p>
<?php endif; ?>
<?php if ($phoneDigits !== ''): ?>
          <p><a href="tel:<?= sitio_e($phoneDigits) ?>">Tel. <?= sitio_e($c['phone']) ?></a></p>
<?php endif; ?>
<?php if ($igUrl !== ''): ?>
          <p>
            <a
              href="<?= sitio_e($igUrl) ?>"
              target="_blank"
              rel="noopener noreferrer"
            ><?= sitio_e(sitio_url_label($igUrl)) ?></a>
          </p>
<?php endif; ?>
        </div>
        <div>
          <h3><?= sitio_t('contacto.h_places') ?></h3>
<?php if ($sedesSimple): ?>
<?php
    $lines = [];
    foreach ($c['sedes'] as $sede) {
        if (trim((string) ($sede['name'] ?? '')) !== '') {
            $lines[] = sitio_e(trim((string) $sede['name']));
        }
    }
    if (trim($c['online_note']) !== '') {
        $lines[] = sitio_e(trim($c['online_note']));
    }
?>
          <p><?= implode('<br>', $lines) ?></p>
<?php else: ?>
<?php foreach ($c['sedes'] as $sede): ?>
<?php
    $name = trim((string) ($sede['name'] ?? ''));
    $address = trim((string) ($sede['address'] ?? ''));
    $hours = trim((string) ($sede['hours'] ?? ''));
    $maps = sitio_maps_url($sede);
    if ($name === '' && $address === '') {
        continue;
    }
?>
          <p>
<?php if ($name !== ''): ?>
            <strong><?= sitio_e($name) ?></strong><br>
<?php endif; ?>
<?php if ($address !== ''): ?>
            <?php if ($maps !== ''): ?><a href="<?= sitio_e($maps) ?>" target="_blank" rel="noopener noreferrer"><?= sitio_e($address) ?></a><?php else: ?><?= sitio_e($address) ?><?php endif; ?><br>
<?php endif; ?>
<?php if ($hours !== ''): ?>
            <?= nl2br(sitio_e($hours), false) ?>
<?php endif; ?>
          </p>
<?php endforeach; ?>
<?php if (trim($c['online_note']) !== ''): ?>
          <p><?= sitio_e(trim($c['online_note'])) ?></p>
<?php endif; ?>
<?php endif; ?>
        </div>
<?php if (trim($c['hours']) !== ''): ?>
        <div>
          <h3><?= sitio_t('contacto.h_hours') ?></h3>
          <p><?= nl2br(sitio_e(trim($c['hours'])), false) ?></p>
        </div>
<?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <p>© <span id="year"><?= date('Y') ?></span> FluxusTerapia</p>
    <p>
<?php if ($igUrl !== ''): ?>
      <a
        href="<?= sitio_e($igUrl) ?>"
        target="_blank"
        rel="noopener noreferrer"
      ><?= sitio_e($handle) ?></a>
      ·
<?php endif; ?>
      <a href="https://fluxusterapia.com">fluxusterapia.com</a>
    </p>
  </footer>

<?php if ($c['wa_number'] !== ''): ?>
  <a
    class="whatsapp-float"
    href="<?= sitio_e($waUrl) ?>"
    target="_blank"
    rel="noopener noreferrer"
    aria-label="Escribinos por WhatsApp"
  >
    <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true" fill="currentColor">
      <path d="M16.1 3C9.3 3 3.8 8.5 3.8 15.3c0 2.2.6 4.3 1.7 6.2L3.5 29l7.7-2c1.8 1 3.8 1.5 5.9 1.5 6.8 0 12.3-5.5 12.3-12.3S22.9 3 16.1 3zm0 22.4c-1.8 0-3.5-.5-5-1.3l-.4-.2-4.6 1.2 1.2-4.4-.2-.4c-1-1.6-1.5-3.4-1.5-5.2 0-5.6 4.5-10.1 10.1-10.1s10.1 4.5 10.1 10.1-4.5 10.3-9.7 10.3zm5.6-7.6c-.3-.2-1.8-.9-2.1-1s-.5-.2-.7.2-.8 1-1 1.2-.4.2-.7 0c-.3-.2-1.2-.4-2.3-1.4-.8-.7-1.4-1.6-1.6-1.9-.2-.3 0-.5.1-.6.1-.1.3-.4.4-.5.1-.2.2-.3.3-.5.1-.2 0-.4 0-.5s-.7-1.7-1-2.3c-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4s-1 1-1 2.4 1 2.8 1.2 3c.1.2 2 3.1 4.9 4.3 1.8.8 2.6.8 3.5.7.5-.1 1.8-.7 2-1.4.2-.7.2-1.3.2-1.4 0-.1-.3-.2-.6-.4z"/>
    </svg>
  </a>
<?php endif; ?>

  <script src="js/main.js?v=20261114a" defer></script>
  <script src="js/nosotros.js?v=20260929a" defer></script>
</body>
</html>
