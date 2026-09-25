<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
global $config;

if (!db_ready()) {
    redirect('install.php');
}

$editId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $pass = (string) ($_POST['password'] ?? '');
        if (hash_equals((string) $config['admin_pass'], $pass)) {
            site_admin_grant();
            flash('success', 'Ingreso correcto.');
        } else {
            flash('error', 'Contraseña incorrecta. También podés entrar como Administrador en AcademiaFluxus.');
        }
        redirect('admin.php');
    }

    if ($action === 'logout') {
        site_admin_revoke();
        flash('info', 'Sesión cerrada.');
        redirect('admin.php');
    }

    require_admin();
    if (!verify_csrf($_POST['csrf'] ?? null)) {
        flash('error', 'Sesión inválida.');
        redirect('admin.php');
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $label = trim((string) ($_POST['label'] ?? ''));
        $alt = trim((string) ($_POST['alt_text'] ?? ''));
        $link = trim((string) ($_POST['link_url'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $mediaType = (string) ($_POST['media_type'] ?? 'image');
        if (!in_array($mediaType, ['image', 'video'], true)) {
            $mediaType = 'image';
        }
        $lightbox = !empty($_POST['use_lightbox']) ? 1 : 0;
        $width = (float) ($_POST['width_ratio'] ?? 1.2);
        if ($width < 0.5) {
            $width = 0.5;
        }
        if ($width > 2.5) {
            $width = 2.5;
        }
        $sort = (int) ($_POST['sort_order'] ?? 50);
        $mediaPath = '';

        $upload = store_gallery_upload($_FILES['media'] ?? ['error' => UPLOAD_ERR_NO_FILE]);
        if (!empty($upload['error'])) {
            flash('error', (string) $upload['error']);
            redirect($action === 'update' ? 'admin.php?edit=' . $id : 'admin.php');
        }
        if (!empty($upload['ok'])) {
            $mediaPath = (string) $upload['path'];
            $mediaType = (string) ($upload['type'] ?? $mediaType);
        }

        if ($videoUrl !== '' && $mediaPath === '' && $action === 'create') {
            $mediaType = 'video';
            // poster optional — without file, tile shows placeholder / link out
        }

        if ($action === 'create') {
            if ($mediaPath === '' && $videoUrl === '') {
                flash('error', 'Subí una foto/video o pegá un link de YouTube.');
                redirect('admin.php');
            }
            db()->prepare("
              INSERT INTO gallery_items
                (media_type, media_path, video_url, label, alt_text, link_url, use_lightbox, width_ratio, sort_order, active)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ")->execute([$mediaType, $mediaPath, $videoUrl, $label, $alt ?: $label, $link, $lightbox, $width, $sort]);
            flash('success', 'Ítem agregado a la galería.');
        } else {
            $row = db()->prepare('SELECT * FROM gallery_items WHERE id = ?');
            $row->execute([$id]);
            $existing = $row->fetch();
            if (!$existing) {
                flash('error', 'Ítem no encontrado.');
                redirect('admin.php');
            }
            if ($mediaPath === '') {
                $mediaPath = (string) $existing['media_path'];
            } else {
                delete_gallery_file((string) $existing['media_path']);
            }
            db()->prepare("
              UPDATE gallery_items
              SET media_type=?, media_path=?, video_url=?, label=?, alt_text=?, link_url=?, use_lightbox=?, width_ratio=?, sort_order=?
              WHERE id=?
            ")->execute([$mediaType, $mediaPath, $videoUrl, $label, $alt ?: $label, $link, $lightbox, $width, $sort, $id]);
            flash('success', 'Ítem actualizado.');
        }
        redirect('admin.php');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE gallery_items SET active = CASE WHEN active=1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$id]);
        flash('success', 'Visibilidad actualizada.');
        redirect('admin.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = db()->prepare('SELECT media_path FROM gallery_items WHERE id = ?');
        $row->execute([$id]);
        $existing = $row->fetch();
        if ($existing) {
            delete_gallery_file((string) $existing['media_path']);
        }
        db()->prepare('DELETE FROM gallery_items WHERE id = ?')->execute([$id]);
        flash('success', 'Ítem eliminado.');
        redirect('admin.php');
    }
}

$flash = take_flash();
$all = is_admin() ? gallery_items(false) : [];
$editing = null;
if (is_admin() && $editId > 0) {
    foreach ($all as $it) {
        if ((int) $it['id'] === $editId) {
            $editing = $it;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Galería · FluxusTerapia</title>
  <link rel="stylesheet" href="../css/style.css?v=20260925r">
  <link rel="stylesheet" href="../blogs/assets/blogs.css?v=2">
  <link rel="icon" href="../img/logo.png" type="image/png">
</head>
<body>
  <header class="site-header site-header--compact">
    <a class="brand" href="../" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="56" height="56">
    </a>
  </header>
  <main class="blogs-wrap blogs-admin">
    <p class="gallery-kicker">Admin</p>
    <h1>Galería</h1>
    <p>
      <a href="./">← Ver galería pública</a>
      · <a href="../blogs/admin.php">Blogs</a>
      · <a href="../academia/admin/">Panel Academia</a>
    </p>

    <?php if ($flash): ?>
      <div class="blogs-alert blogs-alert--<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!is_admin()): ?>
      <p class="muted">Entrá como <strong>Administrador</strong> en AcademiaFluxus, o con la contraseña del sitio.</p>
      <p style="margin:.75rem 0"><a class="cta primary" href="../academia/login.php?as=admin">Ir a login Administrador</a></p>
      <form method="post" class="blogs-form">
        <input type="hidden" name="action" value="login">
        <label>Contraseña admin
          <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="cta primary" type="submit">Ingresar</button>
      </form>
    <?php else: ?>
      <form method="post" style="margin-bottom:1.5rem">
        <input type="hidden" name="action" value="logout">
        <button class="cta secondary" type="submit">Cerrar sesión sitio</button>
      </form>

      <section class="blogs-panel">
        <h2><?= $editing ? 'Editar ítem' : 'Agregar foto o video' ?></h2>
        <form method="post" enctype="multipart/form-data" class="blogs-form">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
          <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <?php endif; ?>
          <label>Tipo
            <select name="media_type">
              <option value="image" <?= ($editing['media_type'] ?? '') === 'image' ? 'selected' : '' ?>>Foto</option>
              <option value="video" <?= ($editing['media_type'] ?? '') === 'video' ? 'selected' : '' ?>>Video</option>
            </select>
          </label>
          <label>Archivo (foto JPG/PNG/WEBP o video MP4/WEBM)
            <input type="file" name="media" accept="image/*,video/mp4,video/webm,.jpg,.jpeg,.png,.webp,.gif,.mp4,.webm" <?= $editing ? '' : '' ?>>
          </label>
          <?php if ($editing && !empty($editing['media_path'])): ?>
            <p class="muted small">Actual: <?= h($editing['media_path']) ?> (subí otro para reemplazar)</p>
          <?php endif; ?>
          <label>Link de YouTube (opcional, para videos externos)
            <input name="video_url" value="<?= h((string) ($editing['video_url'] ?? '')) ?>" placeholder="https://youtube.com/...">
          </label>
          <label>Etiqueta <input name="label" value="<?= h((string) ($editing['label'] ?? '')) ?>" placeholder="Masoterapia"></label>
          <label>Texto alternativo <input name="alt_text" value="<?= h((string) ($editing['alt_text'] ?? '')) ?>"></label>
          <label>Al hacer clic abrir (URL o ruta interna)
            <input name="link_url" value="<?= h((string) ($editing['link_url'] ?? '')) ?>" placeholder="../turnos/ o https://instagram.com/...">
          </label>
          <label>Ancho relativo (0.8–2)
            <input type="number" step="0.05" min="0.5" max="2.5" name="width_ratio" value="<?= h((string) ($editing['width_ratio'] ?? '1.2')) ?>">
          </label>
          <label>Orden <input type="number" name="sort_order" value="<?= h((string) ($editing['sort_order'] ?? '50')) ?>"></label>
          <label class="check">
            <input type="checkbox" name="use_lightbox" value="1" <?= !$editing || (int) ($editing['use_lightbox'] ?? 1) ? 'checked' : '' ?>>
            Ampliar en lightbox (fotos / videos subidos)
          </label>
          <div class="blog-actions">
            <button class="cta primary" type="submit"><?= $editing ? 'Guardar cambios' : 'Agregar a la galería' ?></button>
            <?php if ($editing): ?>
              <a class="cta secondary" href="admin.php">Cancelar</a>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <section class="blogs-panel">
        <h2>Ítems de la galería</h2>
        <?php if (!$all): ?>
          <p class="muted">Todavía no hay ítems.</p>
        <?php else: ?>
          <div class="blogs-admin-list">
            <?php foreach ($all as $it): ?>
              <div class="blogs-admin-row">
                <div style="display:flex;gap:.75rem;align-items:center">
                  <?php if ($it['media_type'] === 'image' && $it['media_path']): ?>
                    <img src="<?= h(media_public_url($it['media_path'])) ?>" alt="" width="72" height="54" style="object-fit:cover;border-radius:6px">
                  <?php endif; ?>
                  <div>
                    <strong><?= h($it['label'] ?: '(sin etiqueta)') ?></strong>
                    <p class="muted small">
                      <?= h($it['media_type']) ?>
                      · <?= (int) $it['active'] ? 'Visible' : 'Oculto' ?>
                      · orden <?= (int) $it['sort_order'] ?>
                    </p>
                  </div>
                </div>
                <div class="blog-actions">
                  <a class="cta secondary" href="admin.php?edit=<?= (int) $it['id'] ?>">Editar</a>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                    <button class="cta secondary" type="submit"><?= (int) $it['active'] ? 'Ocultar' : 'Mostrar' ?></button>
                  </form>
                  <form method="post" onsubmit="return confirm('¿Eliminar este ítem?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                    <button class="cta secondary" type="submit">Eliminar</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
