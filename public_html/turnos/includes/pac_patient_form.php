<?php
/** Campos del formulario del paciente. Espera $form (valores actuales o vacío). */
$v = static fn (string $k): string => h((string) ($form[$k] ?? ''));
?>
        <h3 class="span-2 pac-sub">Datos personales</h3>
        <label>Nombre y apellido
          <input name="nombre" required maxlength="120" autocomplete="off" value="<?= $v('nombre') ?>">
        </label>
        <label>RUT / DNI
          <input name="documento" maxlength="40" autocomplete="off" value="<?= $v('documento') ?>">
        </label>
        <label>Fecha de nacimiento
          <input type="date" name="fecha_nac" max="<?= h(date('Y-m-d')) ?>" value="<?= $v('fecha_nac') ?>">
        </label>
        <label>Sexo
          <select name="sexo">
            <?php foreach (PAC_SEXES as $key => $label): ?>
              <option value="<?= h($key) ?>"<?= (string) ($form['sexo'] ?? '') === $key ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Email
          <input type="email" name="email" maxlength="190" autocomplete="off" value="<?= $v('email') ?>">
        </label>
        <label>Teléfono / WhatsApp
          <input name="telefono" maxlength="40" autocomplete="off" value="<?= $v('telefono') ?>">
        </label>
        <label>Dirección
          <input name="direccion" maxlength="200" autocomplete="off" value="<?= $v('direccion') ?>">
        </label>
        <label>Ocupación
          <input name="ocupacion" maxlength="120" autocomplete="off" value="<?= $v('ocupacion') ?>">
        </label>
        <label class="span-2">Contacto de emergencia (nombre y teléfono)
          <input name="emergencia" maxlength="200" autocomplete="off" value="<?= $v('emergencia') ?>">
        </label>

        <h3 class="span-2 pac-sub">Antecedentes</h3>
        <div class="span-2 pac-flags">
          <?php foreach (PAC_FLAGS as $key => $label): ?>
            <label class="check"><input type="checkbox" name="<?= h($key) ?>" value="1"<?= (int) ($form[$key] ?? 0) === 1 || ($form[$key] ?? '') === '1' ? ' checked' : '' ?>><span><?= h($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <label>Enfermedades
          <textarea name="enfermedades" rows="3" maxlength="4000"><?= $v('enfermedades') ?></textarea>
        </label>
        <label>Cirugías
          <textarea name="cirugias" rows="3" maxlength="4000"><?= $v('cirugias') ?></textarea>
        </label>
        <label>Medicación
          <textarea name="medicacion" rows="3" maxlength="4000"><?= $v('medicacion') ?></textarea>
        </label>
        <label>Alergias
          <textarea name="alergias" rows="3" maxlength="2000"><?= $v('alergias') ?></textarea>
        </label>
        <label class="span-2">Otros riesgos (prótesis, diabetes, hipertensión, epilepsia, piel lesionada…)
          <textarea name="otros_riesgos" rows="2" maxlength="2000"><?= $v('otros_riesgos') ?></textarea>
        </label>
        <label class="span-2">Notas generales (internas)
          <textarea name="notas" rows="3" maxlength="8000"><?= $v('notas') ?></textarea>
        </label>
