(function initLandingTeam() {
  const grid = document.querySelector("[data-team-grid]");
  if (!grid || !window.fetch) return;
  const empty = document.querySelector("[data-team-empty]");

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function initials(name) {
    const parts = String(name || "").trim().split(/\s+/).filter(Boolean);
    const first = parts[0] ? parts[0].charAt(0) : "";
    const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : "";
    return (first + last).toUpperCase() || "·";
  }

  function safePhoto(url) {
    return typeof url === "string" && url.indexOf("/academia/fluxus/equipo.php?photo=") === 0 ? url : "";
  }

  function card(p) {
    const article = el("article", "team-card");
    const photo = el("div", "team-photo");
    const src = safePhoto(p.photo);
    if (src) {
      const img = document.createElement("img");
      img.src = src;
      img.alt = p.name ? "Foto de " + p.name : "";
      img.loading = "lazy";
      img.decoding = "async";
      img.width = 128;
      img.height = 128;
      photo.appendChild(img);
    } else {
      photo.appendChild(el("span", "", initials(p.name)));
    }
    article.appendChild(photo);
    if (p.sector) article.appendChild(el("p", "team-sector", p.sector));
    article.appendChild(el("h3", "", p.name || ""));
    if (p.role && p.role !== p.sector) article.appendChild(el("p", "team-role", p.role));
    if (p.bio) article.appendChild(el("p", "team-bio", p.bio));
    const highlights = Array.isArray(p.highlights) ? p.highlights.filter(Boolean).slice(0, 4) : [];
    if (highlights.length) {
      const list = el("ul", "team-chips");
      highlights.forEach((h) => list.appendChild(el("li", "", String(h))));
      article.appendChild(list);
    }
    [["formacion", "Formación"], ["experiencia", "Experiencia"], ["matricula", "Matrícula"]].forEach(([key, label]) => {
      if (!p[key]) return;
      const meta = el("p", "team-meta");
      meta.appendChild(el("strong", "", label));
      meta.appendChild(document.createTextNode(String(p[key])));
      article.appendChild(meta);
    });
    return article;
  }

  fetch("/academia/fluxus/equipo.php?format=json", { credentials: "omit" })
    .then((res) => (res.ok ? res.json() : null))
    .then((data) => {
      const team = data && Array.isArray(data.team) ? data.team.filter((p) => p && p.name) : [];
      if (!team.length) return;
      const frag = document.createDocumentFragment();
      team.forEach((p) => frag.appendChild(card(p)));
      grid.replaceChildren(frag);
      grid.classList.toggle("team-grid--few", team.length < 3);
      grid.hidden = false;
      if (empty) empty.hidden = true;
      if (location.hash === "#nosotros") {
        document.getElementById("nosotros").scrollIntoView();
      }
    })
    .catch(() => {
      /* sin equipo publicado: queda el texto de presentación */
    });
})();
