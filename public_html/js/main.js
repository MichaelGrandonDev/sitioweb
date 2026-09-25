document.documentElement.classList.add("js");

const yearEl = document.getElementById("year");
if (yearEl) {
  yearEl.textContent = String(new Date().getFullYear());
}

(function initWhatsAppFloat() {
  const btn = document.querySelector(".whatsapp-float");
  if (!btn) return;

  const STORAGE_KEY = "fluxus-wa-float-pos";
  const DRAG_THRESHOLD = 8;
  let startX = 0;
  let startY = 0;
  let originLeft = 0;
  let originTop = 0;
  let dragging = false;
  let moved = false;
  let pointerId = null;

  function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
  }

  function savePosition(left, top) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ left, top }));
    } catch (_) {
      /* ignore quota / private mode */
    }
  }

  function restorePosition() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const pos = JSON.parse(raw);
      if (typeof pos.left !== "number" || typeof pos.top !== "number") return;
      place(pos.left, pos.top);
    } catch (_) {
      /* ignore bad data */
    }
  }

  function place(left, top) {
    const maxLeft = window.innerWidth - btn.offsetWidth;
    const maxTop = window.innerHeight - btn.offsetHeight;
    const x = clamp(left, 8, Math.max(8, maxLeft - 8));
    const y = clamp(top, 8, Math.max(8, maxTop - 8));
    btn.style.left = `${x}px`;
    btn.style.top = `${y}px`;
    btn.style.right = "auto";
    btn.style.bottom = "auto";
    btn.classList.add("is-placed");
    return { left: x, top: y };
  }

  function onPointerDown(event) {
    if (event.button != null && event.button !== 0) return;
    const rect = btn.getBoundingClientRect();
    startX = event.clientX;
    startY = event.clientY;
    originLeft = rect.left;
    originTop = rect.top;
    dragging = true;
    moved = false;
    pointerId = event.pointerId;
    btn.setPointerCapture(pointerId);
    btn.classList.add("is-dragging");
  }

  function onPointerMove(event) {
    if (!dragging || event.pointerId !== pointerId) return;
    const dx = event.clientX - startX;
    const dy = event.clientY - startY;
    if (!moved && Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
    moved = true;
    place(originLeft + dx, originTop + dy);
  }

  function onPointerUp(event) {
    if (!dragging || event.pointerId !== pointerId) return;
    dragging = false;
    btn.classList.remove("is-dragging");
    try {
      btn.releasePointerCapture(pointerId);
    } catch (_) {
      /* already released */
    }
    pointerId = null;
    if (moved) {
      const rect = btn.getBoundingClientRect();
      const saved = place(rect.left, rect.top);
      savePosition(saved.left, saved.top);
    }
  }

  btn.addEventListener("pointerdown", onPointerDown);
  btn.addEventListener("pointermove", onPointerMove);
  btn.addEventListener("pointerup", onPointerUp);
  btn.addEventListener("pointercancel", onPointerUp);

  btn.addEventListener("click", (event) => {
    if (moved) {
      event.preventDefault();
      event.stopPropagation();
      moved = false;
    }
  });

  window.addEventListener("resize", () => {
    if (!btn.classList.contains("is-placed")) return;
    const rect = btn.getBoundingClientRect();
    const saved = place(rect.left, rect.top);
    savePosition(saved.left, saved.top);
  });

  restorePosition();
})();

(function initLandingBlogs() {
  const list = document.getElementById("landing-blogs-list");
  if (!list) return;

  fetch("blogs/api.php?limit=3", { credentials: "same-origin" })
    .then((res) => (res.ok ? res.json() : null))
    .then((data) => {
      const posts = data && Array.isArray(data.posts) ? data.posts : [];
      if (!posts.length) return;
      list.innerHTML = posts
        .map((post) => {
          const pdf = post.has_pdf ? " · PDF" : "";
          const excerpt = post.excerpt
            ? `<p>${escapeHtml(post.excerpt)}</p>`
            : "";
          return `<a class="landing-blog-item" href="${escapeAttr(post.url)}">
            <p class="blog-date">${escapeHtml(post.date || "")}${pdf}</p>
            <h3>${escapeHtml(post.title || "")}</h3>
            ${excerpt}
          </a>`;
        })
        .join("");
    })
    .catch(() => {
      /* keep fallback */
    });

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function escapeAttr(value) {
    return escapeHtml(value).replace(/'/g, "&#39;");
  }
})();

(function initGalleryLightbox() {
  const root = document.querySelector("[data-gallery]");
  const lightbox = document.getElementById("lightbox");
  if (!root || !lightbox) return;

  const imgEl = lightbox.querySelector("img");
  const videoEl = lightbox.querySelector("video");
  const captionEl = lightbox.querySelector("figcaption");
  const closeBtn = lightbox.querySelector(".lightbox-close");
  const prevBtn = lightbox.querySelector(".lightbox-prev");
  const nextBtn = lightbox.querySelector(".lightbox-next");
  const items = Array.from(
    root.querySelectorAll("[data-lightbox][data-gallery-src], [data-lightbox][data-gallery-video]")
  );
  let index = 0;

  function show(i) {
    if (!items.length) return;
    index = (i + items.length) % items.length;
    const item = items[index];
    const videoSrc = item.getAttribute("data-gallery-video") || "";
    const imgSrc = item.getAttribute("data-gallery-src") || "";
    captionEl.textContent = item.getAttribute("data-gallery-caption") || "";
    if (videoEl) {
      if (videoSrc) {
        imgEl.hidden = true;
        imgEl.removeAttribute("src");
        videoEl.hidden = false;
        videoEl.src = videoSrc;
        videoEl.play().catch(() => {});
      } else {
        videoEl.pause();
        videoEl.removeAttribute("src");
        videoEl.hidden = true;
        imgEl.hidden = false;
        imgEl.src = imgSrc;
        imgEl.alt = item.getAttribute("data-gallery-alt") || "";
      }
    } else {
      imgEl.src = imgSrc;
      imgEl.alt = item.getAttribute("data-gallery-alt") || "";
    }
    lightbox.hidden = false;
    document.body.classList.add("lightbox-open");
    closeBtn.focus();
  }

  function hide() {
    lightbox.hidden = true;
    document.body.classList.remove("lightbox-open");
    imgEl.removeAttribute("src");
    if (videoEl) {
      videoEl.pause();
      videoEl.removeAttribute("src");
      videoEl.hidden = true;
    }
    imgEl.hidden = false;
  }

  root.addEventListener("click", (event) => {
    const btn = event.target.closest(
      "[data-lightbox][data-gallery-src], [data-lightbox][data-gallery-video]"
    );
    if (!btn || !root.contains(btn)) return;
    event.preventDefault();
    show(items.indexOf(btn));
  });

  if (!items.length) return;

  closeBtn.addEventListener("click", hide);
  prevBtn.addEventListener("click", () => show(index - 1));
  nextBtn.addEventListener("click", () => show(index + 1));

  lightbox.addEventListener("click", (event) => {
    if (event.target === lightbox) hide();
  });

  document.addEventListener("keydown", (event) => {
    if (lightbox.hidden) return;
    if (event.key === "Escape") hide();
    if (event.key === "ArrowLeft") show(index - 1);
    if (event.key === "ArrowRight") show(index + 1);
  });
})();
