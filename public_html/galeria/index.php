<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$sitioParts = null;
if (is_file(__DIR__ . '/../includes/sitio.php')) {
    try {
        require_once __DIR__ . '/../includes/sitio.php';
        $sitioParts = sitio_public_parts();
    } catch (Throwable $e) {
        $sitioParts = null;
    }
}

if (!headers_sent()) {
    header('Cache-Control: private, no-cache, must-revalidate');
    header_remove('Pragma');
}
if (!db_ready()) {
    redirect('install.php');
}

$items = gallery_items(true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Galería · FluxusTerapia</title>
  <meta name="description" content="Galería de terapias, movimiento y espacio de FluxusTerapia.">
  <link rel="canonical" href="https://fluxusterapia.com/galeria/">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="<?= $sitioParts ? sitio_e($sitioParts['fonts']) : 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&amp;family=Outfit:wght@400;500;600;700&amp;display=swap' ?>">
  <link rel="stylesheet" href="../css/style.css?v=20261114b">
<?php if (!empty($sitioParts['css'])): ?>
  <style id="sitio-estilo"><?= $sitioParts['css'] ?></style>
<?php endif; ?>
  <link rel="icon" href="../img/favicon-48.png" type="image/png" sizes="48x48">
  <link rel="apple-touch-icon" href="../img/apple-touch-icon.png">
  <meta name="theme-color" content="#0f3d36">
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
    <a class="brand" href="../">
      <picture>
        <source type="image/webp" srcset="../img/logo-circle-136.webp">
        <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="68" height="68" decoding="async">
      </picture>
    </a>
    <nav class="site-nav" aria-label="Principal">
      <a href="../">Inicio</a>
      <a href="../#servicios">Servicios</a>
      <a href="./" aria-current="page">Galería</a>
      <a href="../#nosotros">Nosotros</a>
      <a href="../#contacto">Contacto</a>
      <a href="../blogs/">Blogs</a>
      <details class="nav-drop">
        <summary class="nav-academia" aria-label="AcademiaFluxus: clases en vivo y cursos grabados">AcademiaFluxus<svg class="nav-caret" viewBox="0 0 12 12" width="10" height="10" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>
        <div class="nav-drop-menu">
          <div class="nav-drop-panel">
            <a href="../academia/login.php?as=alumno">Portal del estudiante<small>Clases en vivo, grabadas y consultas</small></a>
            <a href="../academia/inscripcion.php">Inscribirme<small>Pedí tu cupo en la academia</small></a>
          </div>
        </div>
      </details>
    </nav>
    <div class="social" aria-label="Redes">
      <a class="social-ig" href="<?= !empty($sitioParts['instagram']) ? sitio_e($sitioParts['instagram']) : 'https://www.instagram.com/fluxusterapia/' ?>" target="_blank" rel="noopener noreferrer me" aria-label="Instagram @fluxusterapia" title="Instagram">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/></svg>
      </a>
      <a class="social-yt" href="https://www.youtube.com/@fluxusterapia" target="_blank" rel="noopener noreferrer" aria-label="YouTube FluxusTerapia" title="YouTube">
        <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="#FF0000" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814z"/><path fill="#fff" d="M9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
      </a>
    </div>
  </header>

  <main id="contenido">
    <section class="gallery gallery-page">
      <div class="gallery-intro">
        <p class="gallery-kicker">FluxusTerapia</p>
        <h1>Galería</h1>
        <p>Fotos y videos del consultorio, las terapias y el movimiento.</p>
      </div>

      <?php if (!$items): ?>
        <p class="muted" style="text-align:center;padding:2rem">Pronto vamos a publicar fotos y videos acá.</p>
      <?php else: ?>
        <div class="gallery-justified" data-gallery>
          <div class="gallery-row">
            <?php foreach ($items as $i => $item): ?>
              <?php
                if ($i > 0 && $i % 3 === 0) {
                    echo '</div><div class="gallery-row">';
                }
                $type = (string) $item['media_type'];
                $src = media_public_url((string) $item['media_path']);
                $videoUrl = trim((string) $item['video_url']);
                $label = (string) $item['label'];
                $alt = (string) ($item['alt_text'] ?: $label);
                $link = trim((string) $item['link_url']);
                $lightbox = (int) $item['use_lightbox'] === 1;
                $w = (float) $item['width_ratio'];
                if ($w <= 0) {
                    $w = 1.2;
                }
                $isExternal = $link !== '' && preg_match('~^https?://~i', $link);
                $href = $link !== '' ? $link : ($lightbox ? $src : '#');
                if ($type === 'video' && $videoUrl !== '' && $link === '') {
                    $href = $videoUrl;
                    $isExternal = true;
                    $lightbox = false;
                }
              ?>
              <a
                class="gallery-item<?= $type === 'video' ? ' is-video' : '' ?>"
                style="--w: <?= h((string) $w) ?>"
                href="<?= h($href) ?>"
                <?php if ($isExternal): ?>target="_blank" rel="noopener noreferrer"<?php endif; ?>
                <?php if ($lightbox && $type === 'image' && $src !== ''): ?>
                  data-lightbox
                  data-gallery-src="<?= h($src) ?>"
                  data-gallery-alt="<?= h($alt) ?>"
                  data-gallery-caption="<?= h($label) ?>"
                <?php endif; ?>
                <?php if ($lightbox && $type === 'video' && $src !== ''): ?>
                  data-lightbox
                  data-gallery-video="<?= h($src) ?>"
                  data-gallery-alt="<?= h($alt) ?>"
                  data-gallery-caption="<?= h($label) ?>"
                <?php endif; ?>
                aria-label="<?= h($label ?: $alt) ?>"
              >
                <?php if ($type === 'video' && $src !== '' && $videoUrl === ''): ?>
                  <video muted playsinline preload="metadata" src="<?= h($src) ?>"></video>
                <?php elseif ($src !== ''): ?>
                  <?php
                    $webp = '';
                    if (preg_match('~^(?!https?://)([^?#]+)\.(jpe?g|png)$~i', $src, $mm) && is_file(__DIR__ . '/' . $mm[1] . '.webp')) {
                        $webp = $mm[1] . '.webp';
                    }
                  ?>
                  <?php if ($webp !== ''): ?><picture><source type="image/webp" srcset="<?= h($webp) ?>"><?php endif; ?>
                  <img src="<?= h($src) ?>" alt="<?= h($alt) ?>" width="800" height="600" loading="<?= $i < 3 ? 'eager' : 'lazy' ?>" decoding="async"><?php if ($webp !== ''): ?></picture><?php endif; ?>
                <?php else: ?>
                  <span class="gallery-placeholder">Video</span>
                <?php endif; ?>
                <?php if ($label !== ''): ?><span class="gallery-label"><?= h($label) ?></span><?php endif; ?>
                <?php if ($type === 'video'): ?><span class="gallery-play" aria-hidden="true">▶</span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="gallery-more">
        <a class="cta primary" href="../turnos/">Reservar turno</a>
        <a class="cta secondary" href="../">Volver al inicio</a>
      </div>
    </section>

    <div class="lightbox" id="lightbox" hidden>
      <button class="lightbox-close" type="button" aria-label="Cerrar">&times;</button>
      <button class="lightbox-prev" type="button" aria-label="Anterior">‹</button>
      <figure class="lightbox-figure">
        <img alt="">
        <video controls playsinline hidden></video>
        <figcaption></figcaption>
      </figure>
      <button class="lightbox-next" type="button" aria-label="Siguiente">›</button>
    </div>
  </main>

  <footer class="site-footer">
    <p>© <?= date('Y') ?> FluxusTerapia</p>
  </footer>
  <script src="../js/main.js?v=20261114a" defer></script>
</body>
</html>
