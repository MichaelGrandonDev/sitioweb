/*
 * Ficha del paciente: fotos nuevas de la cámara en el selector, «Analizar foto» y sugerencias campo por campo.
 * Nada se guarda: aplicar solo completa el formulario; «sugerido_foto» registra qué campos vinieron de la foto.
 */
(function () {
  'use strict';
  const box = document.querySelector('[data-tongue-photos]');
  const form = document.getElementById('gen-form');
  const defsEl = document.getElementById('pac-tongue-def');
  if (!box || !form || !defsEl) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const defs = JSON.parse(defsEl.textContent || '{}');
  const labels = defs._labels || {};
  const pid = parseInt(box.dataset.pid, 10);
  const out = box.querySelector('[data-tongue-suggest]');
  const hidden = box.querySelector('[data-sugerido]');
  const pick = box.querySelector('.pac-photo-pick');
  const sel2 = box.querySelector('[data-foto2]');
  const btn = box.querySelector('[data-tongue-analyze]');
  const CONF = { baja: 'baja', media: 'media', alta: 'alta' };
  const marks = {};
  let applying = false;
  let current = null;
  let pollTimer = null;

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const show = (v) => (Array.isArray(v) ? v.map((x) => labels[x] || x).join(', ') : labels[v] || v);

  async function api(action, body) {
    const res = await fetch('pacientes_api.php?action=' + action, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Respuesta inválida del servidor.' }));
    if (!res.ok || !data.ok) throw new Error(data.error || 'Error ' + res.status);
    return data;
  }

  /* ---------- Fotos nuevas ---------- */
  document.addEventListener('tongue-photo:added', (ev) => {
    const d = ev.detail || {};
    const trigger = ev.target;
    if (trigger && trigger.dataset && trigger.dataset.reload) {
      window.location.hash = trigger.dataset.reload;
      window.location.reload();
      return;
    }
    box.querySelectorAll('[hidden]').forEach((n) => { if (!n.matches('[data-no-photos]')) n.hidden = false; });
    const none = box.querySelector('[data-no-photos]');
    if (none) none.hidden = true;
    if (d.vista === 'sublingual') {
      const o = document.createElement('option');
      o.value = d.id;
      o.textContent = d.fecha_txt + ' · recién sacada';
      sel2.insertBefore(o, sel2.options[1] || null);
      sel2.value = String(d.id);
      return;
    }
    const lab = document.createElement('label');
    lab.className = 'pac-photo-opt';
    lab.innerHTML = '<input type="radio" name="foto_id" value="' + esc(d.id) + '"><img src="' + esc(d.url) + '" alt=""><span class="small">' + esc(d.fecha_txt) + '</span>';
    const first = pick.querySelector('.pac-photo-opt');
    pick.insertBefore(lab, first || null);
    lab.querySelector('input').checked = true;
  });

  /* ---------- Formulario: aplicar y marcar ---------- */
  function control(k) {
    const t = defs[k] && defs[k].type;
    if (t === 'multi') return form.querySelector('input[name="t[' + k + '][]"]')?.closest('.pac-checks');
    return form.querySelector('[name="t[' + k + ']"]');
  }

  function valueOf(k) {
    const t = defs[k] && defs[k].type;
    if (t === 'multi') return Array.from(form.querySelectorAll('input[name="t[' + k + '][]"]:checked')).map((c) => c.value);
    const c = control(k);
    return c ? c.value : '';
  }

  const same = (a, b) => (Array.isArray(a) ? JSON.stringify([...a].sort()) === JSON.stringify([...b].sort()) : a === b);

  function badge(k, text) {
    const c = control(k);
    if (!c) return;
    const host = c.classList && c.classList.contains('pac-checks') ? c.querySelector('.pac-checks-label') : c.closest('label');
    if (!host) return;
    let b = host.querySelector('.pac-sug-badge');
    if (!b) {
      b = document.createElement('span');
      b.className = 'pac-sug-badge';
      host.appendChild(b);
    }
    b.textContent = text;
  }

  function save() {
    hidden.value = Object.keys(marks).length ? JSON.stringify(marks) : '';
  }

  function apply(k, item, motor) {
    const t = defs[k] && defs[k].type;
    applying = true;
    if (t === 'multi') {
      const vals = Array.isArray(item.valor) ? item.valor : [item.valor];
      form.querySelectorAll('input[name="t[' + k + '][]"]').forEach((cb) => { cb.checked = vals.includes(cb.value); });
    } else {
      const c = control(k);
      if (c) c.value = item.valor;
    }
    applying = false;
    marks[k] = { motor, editado: false };
    badge(k, motor === 'cursor' ? 'sugerido por la foto (Cursor)' : 'sugerido por la foto (color)');
    save();
  }

  form.addEventListener('change', (ev) => {
    if (applying) return;
    const m = /^t\[([a-z_]+)\]/.exec(ev.target.name || '');
    if (m && marks[m[1]]) {
      marks[m[1]].editado = true;
      badge(m[1], 'sugerido por la foto · editado');
      save();
    }
  });

  /* ---------- Sugerencias ---------- */
  function render(data) {
    const s = data.sugerencia || {};
    current = s;
    const campos = s.campos || {};
    const keys = Object.keys(defs).filter((k) => k !== '_labels' && campos[k]);
    const waiting = data.state === 'waiting';
    const secs = data.elapsed || 0;
    let html = '<div class="pac-suggest">';
    html += '<p class="pac-suggest-head"><strong>Sugerido por la foto</strong> · ' + esc(s.aviso || '') + '</p>';
    if (waiting) {
      html += '<p class="pac-suggest-wait">Cursor está mirando la foto… (' + Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0')
        + '). Mientras tanto, esta es la estimación por color.</p>';
    }
    const meta = [s.calidad, s.balance, s.notas].filter(Boolean);
    if (meta.length) html += '<p class="small muted">' + meta.map(esc).join(' · ') + '</p>';
    if (!keys.length) {
      html += '<p>No se pudo sugerir ningún dato con esta foto. Probá con otra (más luz, la lengua dentro del óvalo).</p>';
    } else {
      html += '<div class="pac-suggest-table"><table><thead><tr><th>Dato</th><th>Sugerido</th><th>Confianza</th><th>Por qué</th><th></th></tr></thead><tbody>';
      keys.forEach((k) => {
        const it = campos[k];
        const motor = it.motor || s.motor || 'color';
        const done = same(valueOf(k), Array.isArray(it.valor) ? it.valor : it.valor);
        html += '<tr data-k="' + esc(k) + '"><td>' + esc(defs[k].group + ' · ' + defs[k].label) + '</td><td><strong>' + esc(show(it.valor)) + '</strong>'
          + (motor !== s.motor ? ' <span class="muted small">(color)</span>' : '') + '</td><td><span class="pac-conf pac-conf--' + esc(CONF[it.confianza] || 'baja') + '">'
          + esc(CONF[it.confianza] || 'baja') + '</span></td><td class="small">' + esc(it.porque || '') + '</td><td>'
          + '<button type="button" class="btn ghost small" data-apply="' + esc(k) + '"' + (done ? ' disabled' : '') + '>' + (done ? 'Aplicado' : 'Aplicar') + '</button></td></tr>';
      });
      html += '</tbody></table></div>';
      html += '<p class="pac-actions"><button type="button" class="btn primary small" data-apply-all="all">Aplicar todas</button>'
        + '<button type="button" class="btn ghost small" data-apply-all="conf">Aplicar solo las de confianza media o alta</button>'
        + '<button type="button" class="btn ghost small" data-discard>Descartar</button></p>';
    }
    if (s.no_estimados && s.no_estimados.length) {
      html += '<p class="small muted">La estimación por color no mira: ' + s.no_estimados.map(esc).join(', ') + '. Cargalos mirando la foto.</p>';
    }
    if (s.lectura && s.lectura.length) {
      html += '<details class="pac-suggest-book" open><summary>Qué dice «' + esc(s.libro || '') + '» de lo sugerido</summary><ul>';
      s.lectura.forEach((r) => {
        html += '<li><strong>' + esc(r.signo) + '</strong>: ' + esc(r.significado)
          + (r.patrones && r.patrones.length ? ' → <em>' + r.patrones.map(esc).join(', ') + '</em>' : '')
          + ' <span class="small ' + (r.estado === 'libro' ? '' : 'muted') + '">(' + (r.link ? '<a href="' + esc(r.link) + '" target="_blank" rel="noopener">' + esc(r.cita) + '</a>' : esc(r.cita)) + ')</span></li>';
      });
      html += '</ul></details>';
    }
    if (s.citas && s.citas.length) {
      html += '<p class="small">Citas de Cursor: ' + s.citas.map((c) => (c.link ? '<a href="' + esc(c.link) + '" target="_blank" rel="noopener">' + esc(c.cite) + '</a>' : esc(c.cite))).join(' · ') + '</p>';
    }
    html += '</div>';
    out.innerHTML = html;
  }

  out.addEventListener('click', (ev) => {
    const b = ev.target.closest('button');
    if (!b || !current) return;
    const campos = current.campos || {};
    const motorOf = (k) => campos[k].motor || current.motor || 'color';
    if (b.dataset.apply) {
      apply(b.dataset.apply, campos[b.dataset.apply], motorOf(b.dataset.apply));
      b.disabled = true;
      b.textContent = 'Aplicado';
    } else if (b.dataset.applyAll) {
      out.querySelectorAll('button[data-apply]').forEach((ab) => {
        const k = ab.dataset.apply;
        if (b.dataset.applyAll === 'conf' && !['media', 'alta'].includes(campos[k].confianza)) return;
        apply(k, campos[k], motorOf(k));
        ab.disabled = true;
        ab.textContent = 'Aplicado';
      });
    } else if (b.hasAttribute('data-discard')) {
      clearTimeout(pollTimer);
      out.innerHTML = '';
      current = null;
    }
  });

  async function poll(token) {
    try {
      const data = await api('tongue_status', { token, pid });
      render(data);
      if (data.state === 'waiting') {
        pollTimer = setTimeout(() => poll(token), 3000);
        return;
      }
    } catch (e) {
      out.insertAdjacentHTML('beforeend', '<p class="alert warn">' + esc(e.message) + '</p>');
    }
    btn.disabled = false;
  }

  btn.addEventListener('click', async () => {
    const r = form.querySelector('input[name="foto_id"]:checked');
    const fotoId = r ? parseInt(r.value, 10) : 0;
    if (!fotoId) {
      out.innerHTML = '<p class="alert warn">Elegí o sacá una foto de la cara superior de la lengua.</p>';
      return;
    }
    clearTimeout(pollTimer);
    btn.disabled = true;
    out.innerHTML = '<p class="muted">Analizando la foto…</p>';
    try {
      const data = await api('tongue_analyze', {
        pid, foto_id: fotoId, foto2_id: parseInt(sel2.value, 10) || 0,
        ai: Boolean(box.querySelector('[data-analyze-ai]')?.checked),
      });
      render(data);
      if (data.state === 'waiting') {
        pollTimer = setTimeout(() => poll(data.token), 3000);
        return;
      }
    } catch (e) {
      out.innerHTML = '<p class="alert warn">' + esc(e.message) + '</p>';
    }
    btn.disabled = false;
  });
})();
