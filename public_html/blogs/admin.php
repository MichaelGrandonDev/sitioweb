<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

global $config;
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
        $title = trim((string) ($_POST['title'] ?? ''));
        $excerpt = trim((string) ($_POST['excerpt'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $slug = slugify($title);

        if ($title === '') {
            flash('error', 'El título es obligatorio.');
            redirect($action === 'update' ? 'admin.php?edit=' . $id : 'admin.php');
        }

        $pdfPath = '';
        $pdfName = '';
        $coverPath = '';

        if ($action === 'update') {
            $stmt = db()->prepare('SELECT * FROM posts WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                flash('error', 'Publicación no encontrada.');
                redirect('admin.php');
            }
            $slug = (string) $existing['slug'];
            $pdfPath = (string) ($existing['pdf_path'] ?? '');
            $pdfName = (string) ($existing['pdf_name'] ?? '');
            $coverPath = (string) ($existing['cover_path'] ?? '');
        } else {
            $base = $slug;
            $i = 2;
            while (true) {
                $check = db()->prepare('SELECT id FROM posts WHERE slug = ? LIMIT 1');
                $check->execute([$slug]);
                if (!$check->fetch()) {
                    break;
                }
                $slug = $base . '-' . $i;
                $i++;
            }
        }

        if (!is_dir($config['uploads_dir'])) {
            mkdir($config['uploads_dir'], 0755, true);
        }

        if (!empty($_FILES['pdf']['name']) && (int) ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) $_FILES['pdf']['tmp_name'];
            $orig = (string) $_FILES['pdf']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                flash('error', 'Solo se aceptan archivos PDF.');
                redirect($action === 'update' ? 'admin.php?edit=' . $id : 'admin.php');
            }
            $safe = $slug . '-' . bin2hex(random_bytes(4)) . '.pdf';
            $dest = rtrim($config['uploads_dir'], '/') . '/' . $safe;
            if (!move_uploaded_file($tmp, $dest)) {
                flash('error', 'No se pudo subir el PDF.');
                redirect($action === 'update' ? 'admin.php?edit=' . $id : 'admin.php');
            }
            if ($pdfPath !== '') {
                $old = __DIR__ . '/' . ltrim($pdfPath, '/');
                if (is_file($old)) {
                    @unlink($old);
                }
            }
            $pdfPath = 'uploads/' . $safe;
            $pdfName = $orig;
        }

        if (!empty($_FILES['cover']['name']) && (int) ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) $_FILES['cover']['tmp_name'];
            $orig = (string) $_FILES['cover']['name'];
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                flash('error', 'La portada debe ser JPG, PNG o WEBP.');
                redirect($action === 'update' ? 'admin.php?edit=' . $id : 'admin.php');
            }
            $safe = $slug . '-cover-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $dest = rtrim($config['uploads_dir'], '/') . '/' . $safe;
            if (!move_uploaded_file($tmp, $dest)) {
                flash('error', 'No se pudo subir la imagen de portada.');
                redirect($action === 'update' ? 'admin.php?edit=' . $id : 'admin.php');
            }
            if ($coverPath !== '') {
                $old = __DIR__ . '/' . ltrim($coverPath, '/');
                if (is_file($old)) {
                    @unlink($old);
                }
            }
            $coverPath = 'uploads/' . $safe;
        }

        if ($action === 'create') {
            $now = date('c');
            db()->prepare(
                'INSERT INTO posts (title, slug, excerpt, body, pdf_path, pdf_name, cover_path, video_url, published_at, active, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            )->execute([$title, $slug, $excerpt, $body, $pdfPath, $pdfName, $coverPath, $videoUrl, $now, $now]);
            flash('success', 'Publicación creada.');
        } else {
            db()->prepare(
                'UPDATE posts SET title=?, excerpt=?, body=?, pdf_path=?, pdf_name=?, cover_path=?, video_url=? WHERE id=?'
            )->execute([$title, $excerpt, $body, $pdfPath, $pdfName, $coverPath, $videoUrl, $id]);
            flash('success', 'Publicación actualizada.');
        }
        redirect('admin.php');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare('UPDATE posts SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$id]);
        flash('success', 'Estado actualizado.');
        redirect('admin.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT pdf_path, cover_path FROM posts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            foreach (['pdf_path', 'cover_path'] as $field) {
                if (!empty($row[$field])) {
                    $file = __DIR__ . '/' . ltrim((string) $row[$field], '/');
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
        db()->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        flash('success', 'Publicación eliminada.');
        redirect('admin.php');
    }
}

$flash = take_flash();
$all = [];
$editing = null;
if (is_admin()) {
    $all = db()->query('SELECT * FROM posts ORDER BY published_at DESC, id DESC')->fetchAll();
    if ($editId > 0) {
        foreach ($all as $p) {
            if ((int) $p['id'] === $editId) {
                $editing = $p;
                break;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Blogs · FluxusTerapia</title>
  <link rel="stylesheet" href="../css/style.css?v=20260925r">
  <link rel="stylesheet" href="assets/blogs.css?v=2">
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
    <h1>Blogs</h1>
    <p>
      <a href="./">← Ver blogs públicos</a>
      · <a href="../galeria/admin.php">Galería</a>
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
        <h2><?= $editing ? 'Editar publicación' : 'Nueva publicación' ?></h2>
        <form method="post" enctype="multipart/form-data" class="blogs-form">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
          <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
          <?php endif; ?>
          <label>Título <input name="title" required value="<?= h((string) ($editing['title'] ?? '')) ?>" placeholder="Ej: Nuevo paper sobre postura"></label>
          <label>Resumen corto <input name="excerpt" value="<?= h((string) ($editing['excerpt'] ?? '')) ?>" placeholder="Una o dos líneas"></label>
          <label>Texto / noticia
            <textarea name="body" rows="10" placeholder="Escribí la nota acá..."><?= h((string) ($editing['body'] ?? '')) ?></textarea>
          </label>
          <label>Imagen de portada (opcional)
            <input type="file" name="cover" accept="image/*,.jpg,.jpeg,.png,.webp,.gif">
          </label>
          <?php if ($editing && !empty($editing['cover_path'])): ?>
            <p class="muted small">Portada actual: <?= h($editing['cover_path']) ?></p>
          <?php endif; ?>
          <label>Video de YouTube (opcional)
            <input name="video_url" value="<?= h((string) ($editing['video_url'] ?? '')) ?>" placeholder="https://youtube.com/watch?v=...">
          </label>
          <label>PDF (paper / revista) — opcional
            <input type="file" name="pdf" accept="application/pdf,.pdf">
          </label>
          <?php if ($editing && !empty($editing['pdf_name'])): ?>
            <p class="muted small">PDF actual: <?= h($editing['pdf_name']) ?></p>
          <?php endif; ?>
          <div class="blog-actions">
            <button class="cta primary" type="submit"><?= $editing ? 'Guardar cambios' : 'Publicar' ?></button>
            <?php if ($editing): ?>
              <a class="cta secondary" href="admin.php">Cancelar</a>
            <?php endif; ?>
          </div>
        </form>
      </section>

      <section class="blogs-panel">
        <h2>Publicaciones</h2>
        <?php if (!$all): ?>
          <p class="muted">Todavía no hay notas.</p>
        <?php else: ?>
          <div class="blogs-admin-list">
            <?php foreach ($all as $p): ?>
              <div class="blogs-admin-row">
                <div>
                  <strong><?= h($p['title']) ?></strong>
                  <p class="muted small">
                    <?= (int) $p['active'] ? 'Publicada' : 'Oculta' ?>
                    · <?= h(format_date($p['published_at'])) ?>
                    <?= !empty($p['pdf_name']) ? ' · PDF: ' . h($p['pdf_name']) : '' ?>
                    <?= !empty($p['cover_path']) ? ' · Portada' : '' ?>
                    <?= !empty($p['video_url']) ? ' · Video' : '' ?>
                  </p>
                </div>
                <div class="blog-actions">
                  <a class="cta secondary" href="ver.php?slug=<?= h(urlencode($p['slug'])) ?>">Ver</a>
                  <a class="cta secondary" href="admin.php?edit=<?= (int) $p['id'] ?>">Editar</a>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                    <button class="cta secondary" type="submit"><?= (int) $p['active'] ? 'Ocultar' : 'Publicar' ?></button>
                  </form>
                  <form method="post" onsubmit="return confirm('¿Eliminar esta nota?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
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
