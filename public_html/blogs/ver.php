<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

$slug = trim((string) ($_GET['slug'] ?? ''));
$post = find_post($slug);
if (!$post) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $post ? h($post['title']) . ' · ' : '' ?>Blogs · FluxusTerapia</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="../css/style.css?v=20260925p">
  <link rel="stylesheet" href="assets/blogs.css?v=1">
  <link rel="icon" href="../img/logo.png" type="image/png">
</head>
<body>
  <header class="site-header">
    <a class="brand" href="../">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="68" height="68">
    </a>
    <nav class="site-nav" aria-label="Principal">
      <a href="../">Inicio</a>
      <a href="../#servicios">Servicios</a>
      <a href="../galeria/">Galería</a>
      <a href="../#contacto">Contacto</a>
      <a href="./">Blogs</a>
      <a href="../cursos/">Cursos grabados</a>
      <a class="nav-academia" href="../academia/">AcademiaFluxus</a>
    </nav>
    <div class="social" aria-label="Redes">
      <a
        class="social-ig"
        href="https://www.instagram.com/fluxusterapia/"
        target="_blank"
        rel="noopener noreferrer me"
        aria-label="Instagram @fluxusterapia"
        title="Instagram"
      >
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true">
          <defs>
            <radialGradient id="igGrad_ver" cx="30%" cy="107%" r="150%">
              <stop offset="0%" stop-color="#fdf497"/>
              <stop offset="5%" stop-color="#fdf497"/>
              <stop offset="45%" stop-color="#fd5949"/>
              <stop offset="60%" stop-color="#d6249f"/>
              <stop offset="90%" stop-color="#285AEB"/>
            </radialGradient>
          </defs>
          <path fill="url(#igGrad_ver)" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>
        </svg>
      </a>
      <a
        class="social-yt"
        href="https://www.youtube.com/@fluxusterapia"
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
    </div>
  </header>

  <main class="blogs-wrap blogs-article">
    <?php if (!$post): ?>
      <p class="blogs-empty">No encontramos esa publicación.</p>
      <p><a class="cta secondary" href="./">Volver a Blogs</a></p>
    <?php else: ?>
      <p class="blog-date"><?= h(format_date($post['published_at'] ?? '')) ?></p>
      <h1><?= h($post['title']) ?></h1>
      <?php if ($post['excerpt']): ?>
        <p class="blog-lead"><?= h($post['excerpt']) ?></p>
      <?php endif; ?>
      <?php if (!empty($post['cover_path'])): ?>
        <p class="blog-cover"><img src="<?= h($post['cover_path']) ?>" alt="" style="width:100%;max-height:420px;object-fit:cover;border-radius:10px"></p>
      <?php endif; ?>
      <?php if (!empty($post['video_url'])): ?>
        <?php
          $vu = (string) $post['video_url'];
          $embed = '';
          if (preg_match('~(?:youtu\.be/|v=)([A-Za-z0-9_-]{6,})~', $vu, $m)) {
              $embed = 'https://www.youtube.com/embed/' . $m[1];
          }
        ?>
        <?php if ($embed !== ''): ?>
          <div class="video-frame" style="margin:1rem 0;aspect-ratio:16/9">
            <iframe src="<?= h($embed) ?>" title="Video" allowfullscreen loading="lazy" style="width:100%;height:100%;border:0;border-radius:10px"></iframe>
          </div>
        <?php else: ?>
          <p><a class="cta secondary" href="<?= h($vu) ?>" target="_blank" rel="noopener">Ver video</a></p>
        <?php endif; ?>
      <?php endif; ?>
      <div class="blog-body">
        <?php foreach (preg_split("/\n\s*\n/", (string) $post['body']) as $para): ?>
          <?php if (trim($para) !== ''): ?>
            <p><?= nl2br(h(trim($para))) ?></p>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if (!empty($post['pdf_path'])): ?>
        <div class="blog-pdf">
          <p>Documento adjunto<?= $post['pdf_name'] ? ': <strong>' . h($post['pdf_name']) . '</strong>' : '' ?></p>
          <a class="cta primary" href="<?= h($post['pdf_path']) ?>" target="_blank" rel="noopener noreferrer">Abrir / descargar PDF</a>
        </div>
      <?php endif; ?>
      <p class="blog-back"><a href="./">← Volver a Blogs</a></p>
    <?php endif; ?>
  </main>

  <footer class="site-footer">
    <p>© <?= date('Y') ?> FluxusTerapia · Michael Grandon</p>
  </footer>
</body>
</html>
