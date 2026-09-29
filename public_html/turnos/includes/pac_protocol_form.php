<?php
/** Campos del formulario de protocolo. Espera $proto (fila o valores por defecto). */
?>
            <?php foreach (PAC_PROTOCOL_FIELDS as $key => [$label, $max]): ?>
              <label class="<?= in_array($key, ['nombre', 'fuente', 'pulso', 'meridianos'], true) ? '' : 'span-2' ?>"><?= h($label) ?>
                <?php if (in_array($key, ['nombre', 'fuente'], true)): ?>
                  <input name="<?= h($key) ?>" maxlength="<?= (int) $max ?>" value="<?= h((string) ($proto[$key] ?? '')) ?>"<?= $key === 'nombre' ? ' required' : '' ?>>
                <?php else: ?>
                  <textarea name="<?= h($key) ?>" rows="<?= in_array($key, ['lengua', 'signos', 'recomendaciones', 'moxibustion', 'tuina', 'chikung', 'ventosas', 'auriculoterapia', 'sesiones'], true) ? 3 : 2 ?>" maxlength="<?= (int) $max ?>"><?= h((string) ($proto[$key] ?? '')) ?></textarea>
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
            <label>Categoría
              <select name="categoria">
                <option value="patron"<?= ($proto['categoria'] ?? '') !== 'condicion' ? ' selected' : '' ?>>Patrón MTC</option>
                <option value="condicion"<?= ($proto['categoria'] ?? '') === 'condicion' ? ' selected' : '' ?>>Condición / dolor</option>
              </select>
            </label>
            <label class="check"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1"<?= (int) ($proto['active'] ?? 1) === 1 ? ' checked' : '' ?>><span>Activo (se usa al generar)</span></label>
