/* Pacientes · Biblioteca MTC: subida por partes, lectura del texto de los PDF con pdf.js y avisos de "generando". */
(function () {
  'use strict';

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const mb = (b) => (b / 1048576).toFixed(1).replace('.', ',');
  const PAGE_BATCH = 20;

  /* Botones que tardan (Generar / Regenerar): evita doble envío y avisa. */
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', (ev) => {
      const btn = ev.submitter;
      if (!btn || !['generate', 'eval_regenerate'].includes(btn.value)) return;
      setTimeout(() => {
        form.querySelectorAll('button').forEach((b) => { b.disabled = true; });
        btn.textContent = btn.dataset.busy || 'Generando… (puede tardar hasta un minuto)';
      }, 0);
    });
  });

  /* Cuadros de texto que crecen con el contenido. */
  const grow = (ta) => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight + 4, 900) + 'px'; };
  document.querySelectorAll('textarea[data-autogrow]').forEach((ta) => {
    grow(ta);
    ta.addEventListener('input', () => grow(ta));
  });

  /* Vista previa esperando a Cursor: consulta el estado y recarga cuando hay respuesta (o se usa el borrador por reglas). */
  const poll = document.querySelector('[data-job-poll]');
  if (poll) {
    const elapsedEl = poll.querySelector('[data-job-elapsed]');
    const tick = async () => {
      try {
        const res = await fetch('pacientes_api.php?action=job_status', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
          body: JSON.stringify({ token: poll.dataset.token, pid: parseInt(poll.dataset.pid, 10) }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok || data.state !== 'waiting') {
          window.location.reload();
          return;
        }
        if (elapsedEl) {
          const s = data.elapsed;
          elapsedEl.textContent = ' (' + Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') + (data.job === 'claimed' ? ' · Cursor ya lo está procesando' : ' · esperando a Cursor') + ')';
        }
      } catch (e) { /* reintenta en el próximo ciclo */ }
      setTimeout(tick, 4000);
    };
    setTimeout(tick, 2500);
  }

  const filesInput = document.getElementById('pac-files');
  const uploadBtn = document.getElementById('pac-upload');
  if (!filesInput || !uploadBtn) return;
  const jobs = document.getElementById('pac-jobs');
  const tagSel = document.getElementById('pac-tag');
  const maxBytes = parseInt(document.getElementById('pac-drop').dataset.max, 10) || 150 * 1048576;

  async function parse(res) {
    let data = null;
    try { data = await res.json(); } catch (e) { /* no JSON */ }
    if (res.status === 409 && data && typeof data.received === 'number') return { resync: data.received };
    if (!res.ok || !data || !data.ok) {
      const err = new Error((data && data.error) || 'error ' + res.status);
      err.fatal = res.status >= 400 && res.status < 500;
      throw err;
    }
    return data;
  }

  async function post(action, body) {
    const res = await fetch('pacientes_api.php?action=' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body),
    });
    return parse(res);
  }

  async function retry(job, label, fn) {
    let wait = 1500;
    for (let attempt = 1; ; attempt++) {
      try {
        return await fn();
      } catch (e) {
        if (e.fatal || attempt > 3) throw e;
        job.set(label + ' — se cortó, reintento ' + attempt + ' de 3…');
        await sleep(wait);
        wait *= 2;
      }
    }
  }

  function makeJob(file) {
    const li = document.createElement('li');
    const name = document.createElement('strong');
    name.textContent = file.name;
    const msg = document.createElement('span');
    msg.className = 'muted small';
    const bar = document.createElement('progress');
    bar.max = 100;
    bar.value = 0;
    li.append(name, document.createElement('br'), msg, bar);
    jobs.append(li);
    return {
      set(text, pct) { msg.textContent = text; if (typeof pct === 'number') bar.value = pct; },
      done(text, ok) { msg.textContent = text; bar.value = 100; li.classList.add(ok ? 'is-ok' : 'is-error'); },
    };
  }

  async function uploadFile(job, id, file, chunk) {
    let offset = 0;
    let resyncs = 0;
    while (offset < file.size) {
      const end = Math.min(file.size, offset + chunk);
      const fd = new FormData();
      fd.append('id', id);
      fd.append('offset', offset);
      fd.append('total', file.size);
      fd.append('chunk', file.slice(offset, end), 'parte.bin');
      const label = 'Subiendo ' + mb(offset) + ' de ' + mb(file.size) + ' MB';
      job.set(label, (offset / file.size) * 45);
      const res = await retry(job, label, async () => parse(await fetch('pacientes_api.php?action=doc_chunk', {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: fd,
      })));
      if (res.resync !== undefined) {
        if (++resyncs > 3) throw new Error('la subida se desfasó');
        offset = res.resync;
        continue;
      }
      offset = res.received;
      if (res.done) break;
    }
  }

  function pdfjs() {
    const lib = window.pdfjsLib;
    if (!lib) throw new Error('no se cargó el lector de PDF');
    lib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js';
    return lib;
  }

  /* Texto de una página respetando renglones (igual criterio que el generador de clases de Academia). */
  async function pageText(page) {
    const content = await page.getTextContent();
    const items = content.items.filter((it) => 'str' in it && it.transform);
    const angleOf = (it) => Math.round(Math.atan2(it.transform[1], it.transform[0]) * 180 / Math.PI);
    const weight = new Map();
    items.forEach((it) => weight.set(angleOf(it), (weight.get(angleOf(it)) || 0) + it.str.length));
    let main = 0;
    let best = -1;
    weight.forEach((w, a) => { if (w > best) { best = w; main = a; } });
    const rad = main * Math.PI / 180;
    const cos = Math.cos(rad);
    const sin = Math.sin(rad);
    let out = '';
    let lastX = null;
    let lastY = null;
    for (const item of items) {
      if (Math.abs(angleOf(item) - main) > 2) continue;
      const [a, b, , , px, py] = item.transform;
      const x = px * cos + py * sin;
      const y = -px * sin + py * cos;
      const size = Math.hypot(a, b) || 10;
      if (lastY !== null && Math.abs(y - lastY) > size * 0.5) {
        if (!out.endsWith('\n')) out += '\n';
      } else if (lastX !== null && x - lastX > size * 0.15 && !/\s$/.test(out)) {
        out += ' ';
      }
      out += item.str;
      lastX = x + (item.width || 0);
      lastY = y;
      if (item.hasEOL) { out += '\n'; lastX = null; }
    }
    return out.replace(/[ \t]+\n/g, '\n').trim();
  }

  async function readPdf(job, id, file) {
    const doc = await pdfjs().getDocument({ data: new Uint8Array(await file.arrayBuffer()), useSystemFonts: true }).promise;
    let batch = [];
    for (let n = 1; n <= doc.numPages; n++) {
      const page = await doc.getPage(n);
      batch.push({ n, text: await pageText(page) });
      page.cleanup();
      if (batch.length >= PAGE_BATCH || n === doc.numPages) {
        const pages = batch;
        batch = [];
        await retry(job, 'Guardando texto de la página ' + n, () => post('doc_pages', { id, pages }));
      }
      job.set('Leyendo página ' + n + ' de ' + doc.numPages, 45 + (n / doc.numPages) * 50);
    }
    const total = doc.numPages;
    await doc.destroy();
    return retry(job, 'Cerrando', () => post('doc_finish', { id, pages: total }));
  }

  async function run(file) {
    const job = makeJob(file);
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!['pdf', 'pptx', 'ppt', 'docx', 'txt', 'md'].includes(ext)) {
      job.done('Formato no admitido (PDF, PPTX, PPT, DOCX, TXT o MD).', false);
      return false;
    }
    if (file.size > maxBytes) {
      job.done('Supera el máximo de ' + Math.round(maxBytes / 1048576) + ' MB.', false);
      return false;
    }
    let id = 0;
    try {
      job.set('Preparando…', 1);
      const created = await post('doc_create', { name: file.name, size: file.size, tag: tagSel.value });
      id = created.id;
      await uploadFile(job, id, file, created.chunk);
      let res;
      if (ext === 'pdf') {
        try {
          res = await readPdf(job, id, file);
        } catch (e) {
          job.set('No se pudo leer en el navegador (' + e.message + '); leyendo en el servidor…', 60);
          res = await post('doc_process', { id });
        }
      } else {
        job.set('Leyendo el texto en el servidor…', 60);
        res = await post('doc_process', { id });
      }
      const ok = res.status === 'listo';
      job.done(ok ? 'Listo: ' + res.pages_with_text + (ext === 'pdf' ? ' páginas' : ext.startsWith('ppt') ? ' diapositivas' : ' partes') + ' con texto.'
        : 'Se subió, pero no tiene texto (¿es un escaneo? pasalo por OCR).', ok);
      return ok;
    } catch (e) {
      job.done('No se pudo: ' + e.message, false);
      if (id) { try { await post('doc_fail', { id, error: e.message }); } catch (e2) { /* sin conexión */ } }
      return false;
    }
  }

  uploadBtn.addEventListener('click', async () => {
    const files = Array.from(filesInput.files || []);
    if (!files.length) { filesInput.focus(); return; }
    uploadBtn.disabled = true;
    filesInput.disabled = true;
    for (const f of files) {
      // eslint-disable-next-line no-await-in-loop
      await run(f);
    }
    uploadBtn.textContent = 'Listo · actualizando la lista…';
    setTimeout(() => { window.location.href = 'biblioteca.php#docs'; window.location.reload(); }, 1200);
  });
})();
