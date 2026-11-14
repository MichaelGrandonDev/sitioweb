/*
 * Foto de la lengua (reutilizable): consejos, cara superior o inferior, achicar a JPEG ≤ 1600 px y revisar antes de usarla.
 * Celulares y tablets: <input capture> abre la cámara del sistema (trasera o frontal). Computadoras: elegir o arrastrar un archivo.
 *
 * <button type="button" data-tongue-camera
 *         data-upload="pacientes_api.php?action=tongue_photo" data-fields='{"pid":"7"}'>Subir foto de la lengua</button>
 *   → sube la foto con el token CSRF de <meta name="csrf-token"> y dispara «tongue-photo:added» (detail = respuesta JSON).
 * <button type="button" data-tongue-camera data-target-input="#foto" [data-done-text="…"]>…</button>
 *   → pone la foto en ese <input type="file"> (y dispara su «change»).
 * En celulares el texto del botón pasa a data-camera-label (por defecto «Sacar foto de la lengua»).
 */
(function () {
  'use strict';
  const MAX_SIDE = 1600;
  const QUALITY = 0.88;
  const TIPS = [
    'Luz natural o blanca, sin filtros ni flash fuerte.',
    'Lengua afuera y relajada, sin forzar, 3 a 5 segundos.',
    'Antes: no comer ni tomar café, té, caramelos o colorantes, y no cepillarse la lengua.',
    'Foto de frente, con la lengua entera y centrada. Opcional: otra de la cara inferior (venas sublinguales).',
    'Opcional: un papel blanco al costado, dentro de la foto, para calibrar el color.',
  ];
  const mq = (q) => Boolean(window.matchMedia && window.matchMedia(q).matches);
  const canCapture = mq('(pointer: coarse)') || mq('(max-width: 820px)');

  let ui = null;
  let state = null;

  function el(tag, attrs, children) {
    const n = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
      if (k === 'text') n.textContent = v;
      else if (v === true) n.setAttribute(k, '');
      else if (v !== false && v != null) n.setAttribute(k, v);
    });
    (children || []).forEach((c) => n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
    return n;
  }

  function build() {
    const dlg = el('dialog', { class: 'tcam', 'aria-labelledby': 'tcam-title' });
    const stepTips = el('section', { class: 'tcam-step', 'data-step': 'tips' }, [
      el('h2', { id: 'tcam-title', text: 'Foto de la lengua' }),
      el('ul', { class: 'tcam-tips' }, TIPS.map((t) => el('li', { text: t }))),
      el('fieldset', { class: 'tcam-vista' }, [
        el('legend', { text: '¿Qué cara es?' }),
        el('label', {}, [el('input', { type: 'radio', name: 'tcam-vista', value: 'dorso', checked: true }), ' De frente (cara superior)']),
        el('label', {}, [el('input', { type: 'radio', name: 'tcam-vista', value: 'sublingual' }), ' Cara inferior (venas sublinguales)']),
      ]),
      canCapture ? null : el('div', { class: 'tcam-drop', 'data-drop': true, text: 'Arrastrá la foto acá o tocá «Elegir una foto».' }),
      el('div', { class: 'tcam-actions' }, [
        canCapture ? el('button', { type: 'button', class: 'btn primary', 'data-act': 'camera', text: 'Abrir la cámara' }) : null,
        canCapture ? el('button', { type: 'button', class: 'btn ghost', 'data-act': 'front', text: 'Cámara frontal' }) : null,
        el('button', { type: 'button', class: canCapture ? 'btn ghost' : 'btn primary', 'data-act': 'file', text: 'Elegir una foto' }),
        el('button', { type: 'button', class: 'btn ghost', 'data-act': 'close', text: 'Cancelar' }),
      ].filter(Boolean)),
    ].filter(Boolean));
    const stepReview = el('section', { class: 'tcam-step', 'data-step': 'review', hidden: true }, [
      el('img', { class: 'tcam-preview', alt: 'Foto de la lengua elegida' }),
      el('p', { class: 'tcam-status', role: 'status' }),
      el('div', { class: 'tcam-actions' }, [
        el('button', { type: 'button', class: 'btn primary', 'data-act': 'confirm', text: 'Usar esta foto' }),
        el('button', { type: 'button', class: 'btn ghost', 'data-act': 'retake', text: canCapture ? 'Repetir' : 'Elegir otra' }),
        el('button', { type: 'button', class: 'btn ghost', 'data-act': 'close', text: 'Cancelar' }),
      ]),
    ]);
    const pick = el('input', { type: 'file', accept: 'image/*', hidden: true });
    const camBack = el('input', { type: 'file', accept: 'image/*', capture: 'environment', hidden: true });
    const camFront = el('input', { type: 'file', accept: 'image/*', capture: 'user', hidden: true });
    [stepTips, stepReview, pick, camBack, camFront].forEach((n) => dlg.appendChild(n));
    document.body.appendChild(dlg);
    if (typeof dlg.showModal !== 'function') dlg.classList.add('tcam--fallback');

    dlg.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-act]');
      if (!b) return;
      const act = b.getAttribute('data-act');
      if (act === 'close') close();
      else if (act === 'camera') camBack.click();
      else if (act === 'front') camFront.click();
      else if (act === 'file') pick.click();
      else if (act === 'retake') show('tips');
      else if (act === 'confirm') confirm();
    });
    dlg.addEventListener('cancel', (ev) => { ev.preventDefault(); close(); });
    [pick, camBack, camFront].forEach((inp) => inp.addEventListener('change', () => {
      const f = inp.files && inp.files[0];
      inp.value = '';
      if (f) fromFile(f);
    }));
    const drop = stepTips.querySelector('[data-drop]');
    if (drop) {
      ['dragenter', 'dragover'].forEach((t) => drop.addEventListener(t, (ev) => { ev.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((t) => drop.addEventListener(t, () => drop.classList.remove('is-over')));
      drop.addEventListener('drop', (ev) => {
        ev.preventDefault();
        const f = ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files[0];
        if (f) fromFile(f);
      });
      drop.addEventListener('click', () => pick.click());
    }
    return { dlg, stepTips, stepReview, img: stepReview.querySelector('img'), status: stepReview.querySelector('.tcam-status') };
  }

  function show(step) {
    ui.stepTips.hidden = step !== 'tips';
    ui.stepReview.hidden = step !== 'review';
  }

  function open(trigger) {
    ui = ui || build();
    state = { trigger, blob: null, url: null };
    ui.dlg.querySelector('input[value="dorso"]').checked = true;
    ui.status.textContent = '';
    show('tips');
    if (ui.dlg.showModal && !ui.dlg.open) ui.dlg.showModal();
    else ui.dlg.setAttribute('open', '');
  }

  function close() {
    if (state && state.url) URL.revokeObjectURL(state.url);
    state = null;
    if (ui.dlg.close && ui.dlg.open) ui.dlg.close();
    else ui.dlg.removeAttribute('open');
  }

  function toBlob(source, w, h) {
    const scale = Math.min(1, MAX_SIDE / Math.max(w, h));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(w * scale);
    canvas.height = Math.round(h * scale);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    return new Promise((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('canvas'))), 'image/jpeg', QUALITY));
  }

  async function fromFile(file) {
    if (file.type && !/^image\//.test(file.type)) {
      alert('Elegí una imagen.');
      return;
    }
    try {
      let src;
      let w;
      let h;
      if (window.createImageBitmap) {
        src = await createImageBitmap(file, { imageOrientation: 'from-image' });
        w = src.width;
        h = src.height;
      } else {
        src = await new Promise((resolve, reject) => {
          const i = new Image();
          i.onload = () => resolve(i);
          i.onerror = reject;
          i.src = URL.createObjectURL(file);
        });
        w = src.naturalWidth;
        h = src.naturalHeight;
      }
      review(await toBlob(src, w, h));
    } catch (e) {
      alert('No se pudo leer la imagen. Si es HEIC, compartila como JPG.');
    }
  }

  function review(blob) {
    if (state.url) URL.revokeObjectURL(state.url);
    state.blob = blob;
    state.url = URL.createObjectURL(blob);
    ui.img.src = state.url;
    ui.status.textContent = 'Revisá que se vea la lengua entera, nítida y con luz pareja.';
    show('review');
  }

  function vista() {
    const r = ui.dlg.querySelector('input[name="tcam-vista"]:checked');
    return r ? r.value : 'dorso';
  }

  function today() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  async function confirm() {
    const t = state.trigger;
    const file = new File([state.blob], 'lengua-' + today() + '.jpg', { type: 'image/jpeg' });
    const target = t.getAttribute('data-target-input');
    if (target) {
      const input = document.querySelector(target);
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
      } catch (e) {
        ui.status.textContent = 'Este navegador no permite pasar la foto al formulario: elegila con el botón de archivo.';
        return;
      }
      input.dispatchEvent(new Event('change', { bubbles: true }));
      if (t.dataset.doneText) t.textContent = t.dataset.doneText;
      close();
      return;
    }
    const btn = ui.stepReview.querySelector('[data-act="confirm"]');
    const meta = document.querySelector('meta[name="csrf-token"]');
    const fd = new FormData();
    let fields = {};
    try { fields = JSON.parse(t.getAttribute('data-fields') || '{}'); } catch (e) { fields = {}; }
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    fd.append('vista', vista());
    fd.append('fecha', today());
    fd.append('foto', file);
    btn.disabled = true;
    ui.status.textContent = 'Guardando la foto…';
    try {
      const res = await fetch(t.getAttribute('data-upload'), {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-CSRF-Token': meta ? meta.content : '' },
      });
      const data = await res.json().catch(() => ({ ok: false, error: 'Respuesta inválida del servidor.' }));
      if (!res.ok || !data.ok) throw new Error(data.error || 'No se pudo guardar la foto.');
      ui.status.textContent = 'Foto guardada.';
      t.dispatchEvent(new CustomEvent('tongue-photo:added', { bubbles: true, detail: data }));
      setTimeout(close, 400);
    } catch (e) {
      ui.status.textContent = e.message + ' Probá de nuevo.';
    } finally {
      btn.disabled = false;
    }
  }

  if (canCapture) {
    document.querySelectorAll('[data-tongue-camera]').forEach((b) => { b.textContent = b.dataset.cameraLabel || 'Sacar foto de la lengua'; });
  }
  document.addEventListener('click', (ev) => {
    const t = ev.target.closest('[data-tongue-camera]');
    if (t) {
      ev.preventDefault();
      open(t);
    }
  });
})();
