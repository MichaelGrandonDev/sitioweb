(() => {
  const state = {
    year: window.FLUXUS_TURNOS.year,
    month: window.FLUXUS_TURNOS.month,
    therapy: null,
    date: null,
    time: null,
    availableDays: [],
  };

  const els = {
    therapyList: document.getElementById("therapy-list"),
    calendar: document.getElementById("calendar"),
    monthLabel: document.getElementById("month-label"),
    slotsList: document.getElementById("slots-list"),
    slotsDate: document.getElementById("slots-date-label"),
    summary: document.getElementById("summary"),
    form: document.getElementById("book-form"),
    error: document.getElementById("error-box"),
    success: document.getElementById("success-box"),
    successText: document.getElementById("success-text"),
    pdfLink: document.getElementById("pdf-link"),
    payLink: document.getElementById("pay-link"),
    confirmBtn: document.getElementById("confirm-btn"),
    steps: [...document.querySelectorAll(".steps li")],
    stepTherapy: document.getElementById("step-therapy"),
    stepCalendar: document.getElementById("step-calendar"),
    stepSlots: document.getElementById("step-slots"),
    stepConfirm: document.getElementById("step-confirm"),
  };

  function showError(msg) {
    els.error.textContent = msg || "";
    els.error.classList.toggle("is-hidden", !msg);
  }

  function setStep(n) {
    els.steps.forEach((el, i) => el.classList.toggle("is-active", i < n));
  }

  async function api(action, params = {}, options = {}) {
    const url = new URL("api.php", window.location.href);
    url.searchParams.set("action", action);
    Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, String(v)));
    const res = await fetch(url, options);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || "Error de servidor");
    return data;
  }

  function renderTherapies(list) {
    els.therapyList.innerHTML = "";
    list.forEach((t) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "therapy";
      btn.innerHTML = `<strong>${escapeHtml(t.name)}</strong><span>${escapeHtml(t.description || "")}</span>`;
      btn.addEventListener("click", () => {
        state.therapy = t;
        [...els.therapyList.children].forEach((c) => c.classList.remove("is-selected"));
        btn.classList.add("is-selected");
        els.stepCalendar.classList.remove("is-hidden");
        setStep(2);
        loadMonth();
        window.scrollTo({ top: els.stepCalendar.offsetTop - 20, behavior: "smooth" });
      });
      els.therapyList.appendChild(btn);
    });
  }

  async function loadMonth() {
    const data = await api("month", { year: state.year, month: state.month });
    state.availableDays = data.available_days || [];
    els.monthLabel.textContent = data.month_label;
    renderCalendar();
  }

  function renderCalendar() {
    const first = new Date(state.year, state.month - 1, 1);
    // Monday-first offset
    let startPad = (first.getDay() + 6) % 7;
    const daysInMonth = new Date(state.year, state.month, 0).getDate();
    els.calendar.innerHTML = "";

    for (let i = 0; i < startPad; i++) {
      const empty = document.createElement("div");
      empty.className = "day is-muted";
      empty.textContent = "";
      els.calendar.appendChild(empty);
    }

    for (let d = 1; d <= daysInMonth; d++) {
      const ymd = `${state.year}-${String(state.month).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "day";
      btn.textContent = String(d);
      const available = state.availableDays.includes(ymd);
      if (available) {
        btn.classList.add("is-available");
        if (state.date === ymd) btn.classList.add("is-selected");
        btn.addEventListener("click", () => selectDay(ymd));
      } else {
        btn.classList.add("is-muted");
        btn.disabled = true;
      }
      els.calendar.appendChild(btn);
    }
  }

  async function selectDay(ymd) {
    state.date = ymd;
    state.time = null;
    renderCalendar();
    const data = await api("slots", { date: ymd });
    els.stepSlots.classList.remove("is-hidden");
    els.slotsDate.textContent = data.date_label;
    els.slotsList.innerHTML = "";
    if (!data.slots.length) {
      els.slotsList.innerHTML = '<p class="muted">Sin horarios libres ese día.</p>';
      return;
    }
    data.slots.forEach((slot) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "slot";
      btn.textContent = slot + " hs";
      btn.addEventListener("click", () => {
        state.time = slot;
        [...els.slotsList.children].forEach((c) => c.classList.remove("is-selected"));
        btn.classList.add("is-selected");
        showConfirm();
      });
      els.slotsList.appendChild(btn);
    });
    setStep(3);
    window.scrollTo({ top: els.stepSlots.offsetTop - 20, behavior: "smooth" });
  }

  function showConfirm() {
    els.stepConfirm.classList.remove("is-hidden");
    els.form.classList.remove("is-hidden");
    els.success.classList.add("is-hidden");
    els.summary.innerHTML = `
      <strong>${escapeHtml(state.therapy.name)}</strong><br>
      ${escapeHtml(formatDateLocal(state.date))}<br>
      Horario: <strong>${escapeHtml(state.time)} hs</strong>
    `;
    setStep(4);
    window.scrollTo({ top: els.stepConfirm.offsetTop - 20, behavior: "smooth" });
  }

  function formatDateLocal(ymd) {
    const [y, m, d] = ymd.split("-").map(Number);
    const dt = new Date(y, m - 1, d);
    return dt.toLocaleDateString("es-AR", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
    });
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  els.form.addEventListener("submit", async (e) => {
    e.preventDefault();
    showError("");
    if (!state.therapy || !state.date || !state.time) {
      showError("Elegí terapia, día y horario.");
      return;
    }
    const fd = new FormData(els.form);
    els.confirmBtn.disabled = true;
    els.confirmBtn.textContent = "Reservando…";
    try {
      const data = await api("book", {}, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          csrf: fd.get("csrf"),
          therapy_id: state.therapy.id,
          date: state.date,
          time: state.time,
          name: fd.get("name"),
          phone: fd.get("phone"),
          email: fd.get("email"),
          notes: fd.get("notes"),
        }),
      });
      els.form.classList.add("is-hidden");
      els.success.classList.remove("is-hidden");
      const deposit = data.deposit_amount || data.appointment.deposit_amount || 15000;
      els.successText.textContent =
        `Reservamos ${data.appointment.therapy} el ${formatDateLocal(data.appointment.date)} a las ${data.appointment.time} hs (código ${data.appointment.code}). Para confirmarlo, pagá la seña de $${Number(deposit).toLocaleString("es-AR")}.`;
      const payUrl = data.pay_url || data.appointment.pay_url;
      if (els.payLink && payUrl) {
        els.payLink.href = payUrl;
        // Ir directo a pagar seña
        window.location.href = payUrl;
        return;
      }
    } catch (err) {
      showError(err.message || "No se pudo reservar.");
    } finally {
      els.confirmBtn.disabled = false;
      els.confirmBtn.textContent = "Reservar y pagar seña";
    }
  });

  document.getElementById("prev-month").addEventListener("click", () => {
    state.month -= 1;
    if (state.month < 1) {
      state.month = 12;
      state.year -= 1;
    }
    loadMonth().catch((e) => showError(e.message));
  });
  document.getElementById("next-month").addEventListener("click", () => {
    state.month += 1;
    if (state.month > 12) {
      state.month = 1;
      state.year += 1;
    }
    loadMonth().catch((e) => showError(e.message));
  });

  api("therapies")
    .then((data) => renderTherapies(data.therapies))
    .catch((e) => showError(e.message));
})();
