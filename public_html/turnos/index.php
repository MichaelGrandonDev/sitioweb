<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!db_ready()) {
    redirect('install.php');
}

$csrf = csrf_token();
$now = new DateTimeImmutable('now');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reservar turno · FluxusTerapia</title>
  <meta name="description" content="Reservá tu turno de terapia en FluxusTerapia. Elegí día, horario y terapia.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/turnos.css">
  <link rel="icon" href="../img/logo.png" type="image/png">
</head>
<body>
  <header class="top">
    <a class="brand brand--home" href="../" title="Volver a FluxusTerapia">
      <img class="brand-logo" src="../img/logo-circle.png" alt="FluxusTerapia" width="44" height="44">
      <span>Turnos</span>
    </a>
    <nav>
      <a href="../">Inicio</a>
      <a href="../academia/" class="nav-academia">AcademiaFluxus</a>
    </nav>
  </header>

  <main class="wrap">
    <p class="eyebrow">Agenda</p>
    <h1>Reservar turno</h1>
    <p class="lede">Elegí la terapia, un día disponible y un horario. Al confirmar se descarga tu comprobante PDF oficial.</p>

    <ol class="steps" aria-label="Pasos">
      <li class="is-active" data-step-label="1">Terapia</li>
      <li data-step-label="2">Día</li>
      <li data-step-label="3">Horario</li>
      <li data-step-label="4">Confirmá</li>
    </ol>

    <section class="panel" id="step-therapy">
      <h2>1. ¿Qué terapia querés?</h2>
      <div class="therapy-grid" id="therapy-list"></div>
    </section>

    <section class="panel is-hidden" id="step-calendar">
      <h2>2. Elegí un día disponible</h2>
      <div class="cal-nav">
        <button type="button" class="btn ghost" id="prev-month" aria-label="Mes anterior">←</button>
        <h3 id="month-label"></h3>
        <button type="button" class="btn ghost" id="next-month" aria-label="Mes siguiente">→</button>
      </div>
      <div class="cal-grid head" aria-hidden="true">
        <span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>
      </div>
      <div class="cal-grid" id="calendar" role="grid" aria-label="Calendario de turnos"></div>
      <p class="hint" id="day-hint">Los días con punto verde tienen horarios libres.</p>
    </section>

    <section class="panel is-hidden" id="step-slots">
      <h2>3. Horarios disponibles</h2>
      <p class="muted" id="slots-date-label"></p>
      <div class="slots" id="slots-list"></div>
    </section>

    <section class="panel is-hidden" id="step-confirm">
      <h2>4. Tus datos y confirmación</h2>
      <div class="summary" id="summary"></div>
      <form id="book-form" class="stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Nombre completo <input name="name" required autocomplete="name" placeholder="Cómo figurarás en el comprobante"></label>
        <label>Teléfono / WhatsApp <input name="phone" required autocomplete="tel" placeholder="Ej: 2932 537949"></label>
        <label>Email (opcional) <input type="email" name="email" autocomplete="email" placeholder="para recordatorio"></label>
        <label>Comentario (opcional) <textarea name="notes" rows="2" placeholder="Alguna molestia o preferencia"></textarea></label>
        <button class="btn primary" type="submit" id="confirm-btn">Reservar y pagar seña</button>
        <p class="hint">Seña de <?= h(money_ars(deposit_amount())) ?>: transferencia, QR o tarjeta. El horario queda reservado hasta acreditar el pago.</p>
      </form>
      <div class="success is-hidden" id="success-box">
        <h3>¡Turno reservado!</h3>
        <p id="success-text"></p>
        <a class="btn primary" id="pay-link" href="#">Pagar seña ahora</a>
        <a class="btn ghost" href="../">Volver al inicio</a>
      </div>
    </section>

    <p class="alert error is-hidden" id="error-box" role="alert"></p>
  </main>

  <footer class="foot">
    <p>Punta Alta · Coronel Suárez · Online · <a href="../">fluxusterapia.com</a></p>
  </footer>

  <script>
    window.FLUXUS_TURNOS = {
      csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
      year: <?= (int) $now->format('Y') ?>,
      month: <?= (int) $now->format('n') ?>
    };
  </script>
  <script src="assets/turnos.js"></script>
</body>
</html>
