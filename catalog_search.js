/*! catalog_search_merged.js — single clean catalog search
 * - Works with your existing search_wine83125.php
 * - GET params: name, winery, vintage, region, grapes, limit=50
 * - POST fallback: search_wine83125.php?mode=manual&limit=50 with JSON {query:"..."}
 * Exposes:
 *   window.__catalogRunSearch()
 *   window.__catalogApplyConfirm()
 *   window.__catalogClear()
 */
(function () {
  if (window.__catalogSearchInitDone) return;
  window.__catalogSearchInitDone = true;

  const log = (...a) => { try { console.log("[catalog search]", ...a); } catch(_){} };
  const warn = (...a) => { try { console.warn("[catalog search]", ...a); } catch(_){} };
  const error = (...a) => { try { console.error("[catalog search]", ...a); } catch(_){} };

  // Refs
  let els = {};
  function ref() {
    els = {
      wrap: document.getElementById("catalog-search"),
      form: document.getElementById("catalogSearchForm"),
      btn: document.getElementById("runCatalogSearch"),
      clearBtn: document.getElementById("clearCatalogResults"),
      status: document.getElementById("catalogSearchStatus"),
      tbody: document.querySelector("#catalogResults tbody"),
      resultsWrap: document.getElementById("catalogResultsWrap"),
      notice: document.getElementById("catalogResultsNotice"),
      confirmWrap: document.getElementById("catalogConfirm"),
      confirmBody: document.getElementById("catalogConfirmBody"),
      backBtn: document.getElementById("catalogBackBtn"),
      confirmBtn: document.getElementById("catalogConfirmBtn"),
      q_name: document.getElementById("q_name"),
      q_winery: document.getElementById("q_winery"),
      q_vintage: document.getElementById("q_vintage"),
      q_region: document.getElementById("q_region"),
      q_grapes: document.getElementById("q_grapes"),
    };
  }

  // Helpers
  function escapeHtml(str) {
    return String(str || "").replace(/[&<>\"']/g, s => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      "\"": "&quot;",
      "'": "&#39;"
    })[s]);
  }
  const val = el => (el && typeof el.value === "string") ? el.value.trim() : "";
  function combinedQuery(){
    return [val(els.q_name), val(els.q_winery), val(els.q_vintage), val(els.q_region), val(els.q_grapes)]
        .filter(Boolean).join(" ").replace(/\s+/g," ").trim();
  }
  function normResults(data){
    if (!data) return [];
    if (Array.isArray(data)) return data;
    if (Array.isArray(data.wines)) return data.wines;
    if (Array.isArray(data.rows)) return data.rows;
    if (Array.isArray(data.candidates)) return data.candidates;
    return [];
  }
  function normalizeWine(w){
    const obj = {
      name: w.name || w.label || "",
      winery: w.winery || "",
      vintage: w.vintage || "",
      grapes: w.grapes || w.varietal || "",
      region: w.region || "",
      country: w.country || "",
      type: w.type || "",
      style: w.style || "",
      rating: w.rating || w.critic_rating || "",
      price: w.price || w.avg_price || "",
      upc: w.upc || w.barcode || w.ean || "",
      image: w.image || w.cover || w.image_url || ""
    };
    // Guard: if grapes looks numeric (really a rating), fall back
    if (typeof obj.grapes === "string" && /^\s*\d+(\.\d+)?\s*$/.test(obj.grapes)) {
      obj.grapes = w.varietal || "";
    }
    return obj;
  }

  function setStatus(txt){ if (els.status) els.status.textContent = txt || ""; }
  function clearResults(){
    if (els.tbody) els.tbody.innerHTML = "";
    if (els.resultsWrap) els.resultsWrap.style.display = "none";
    if (els.notice) els.notice.textContent = "";
    if (els.status) els.status.textContent = "";
    if (els.confirmWrap) els.confirmWrap.style.display = "none";
    window.__catalogSelectedWine = null;
  }
  function showResults(count){
    els.resultsWrap.style.display = "block";
    els.notice.textContent = count ? `Found ${count} result(s).` : "No matches found.";
  }
  function fillResults(wines){
    els.tbody.innerHTML = "";
    for (const raw of wines) {
      const w = normalizeWine(raw);
      const tr = document.createElement("tr");
      const thumb = w.image
          ? `<img src="${escapeHtml(w.image)}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #eee" />`
          : "";
      tr.innerHTML = `
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6; width:56px;">${thumb}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">${escapeHtml(w.name)}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">${escapeHtml(w.winery)}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">${escapeHtml(w.vintage)}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">${escapeHtml(w.region)}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">${escapeHtml(w.type)}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">${escapeHtml(w.grapes)}</td>
        <td style="padding:0.45rem; border-bottom:1px solid #f3f4f6;">
          <button type="button" class="selectWine" style="padding:0.35rem 0.6rem; border:1px solid #ddd; border-radius:8px; cursor:pointer;">Select</button>
        </td>`;
      tr.querySelector(".selectWine").addEventListener("click", () => showConfirm(w));
      els.tbody.appendChild(tr);
    }
  }
  function showConfirm(w){
    els.resultsWrap.style.display = "none";
    els.confirmWrap.style.display = "block";
    els.confirmBody.innerHTML = "";
    if (w.image) {
      const img = document.createElement("img");
      img.src = w.image;
      img.alt = w.name || "bottle";
      img.style.maxWidth = "110px";
      img.style.borderRadius = "8px";
      img.style.border = "1px solid #eee";
      els.confirmBody.appendChild(img);
    }
    const meta = document.createElement("div");
    meta.innerHTML = `
      <div style="font-weight:600; font-size:1.05rem;">${escapeHtml(w.name)}</div>
      <div>${escapeHtml(w.winery)} ${w.vintage ? "(" + escapeHtml(w.vintage) + ")" : ""}</div>
      <div>${escapeHtml(w.region)} ${w.country ? "– " + escapeHtml(w.country) : ""}</div>
      <div>${w.type ? "Type: " + escapeHtml(w.type) : ""} ${w.style ? " | Style: " + escapeHtml(w.style) : ""}</div>
      <div>${w.grapes ? "Grapes: " + escapeHtml(w.grapes) : ""} ${w.price ? " | Price: " + escapeHtml(w.price) : ""}</div>
      <div style="opacity:0.7; margin-top:0.25rem;">UPC: ${escapeHtml(w.upc)}</div>`;
    els.confirmBody.appendChild(meta);
    window.__catalogSelectedWine = w;
  }
  function applyPrefill(w){
    const map = {
      name: "name",
      winery: "winery",
      vintage: "vintage",
      grapes: "grapes",
      region: "region",
      country: "country",
      type: "type",
      style: "style",
      rating: "rating",
      price: "price",
      upc: "upc",
      image: "image_url"
    };
    sessionStorage.setItem("prefill_wine", JSON.stringify(w));
    Object.keys(map).forEach(k => {
      const el = document.querySelector(`[name="${map[k]}"]`);
      if (el && w[k] != null) el.value = w[k];
    });
    if (w.image) {
      const imgUrl = document.getElementById("image_url");
      if (imgUrl) imgUrl.value = w.image;
    }
    const formEl = document.querySelector("form");
    formEl && formEl.scrollIntoView && formEl.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  // Public API
  async function runSearch(){
    try {
      ref();
      if (!els.wrap) { warn("catalog block not found"); return; }
      setStatus("Searching…");
      els.confirmWrap.style.display = "none";
      els.resultsWrap.style.display = "block";
      els.tbody.innerHTML = "";
      els.notice.textContent = "";

      const q = combinedQuery();

      // Strategy A: GET with limit=50
      let wines = [];
      try {
        const params = new URLSearchParams();
        if (els.q_name.value)   params.set("name", els.q_name.value.trim());
        if (els.q_winery.value) params.set("winery", els.q_winery.value.trim());
        if (els.q_vintage.value)params.set("vintage", els.q_vintage.value.trim());
        if (els.q_region.value) params.set("region", els.q_region.value.trim());
        if (els.q_grapes.value) params.set("grapes", els.q_grapes.value.trim());
        params.set("limit", "50");

        const resA = await fetch("search_wine.php?" + params.toString(), { headers: { "Accept": "application/json" } });
        if (!resA.ok) throw new Error("GET HTTP " + resA.status);
        const dataA = await resA.json();
        wines = normResults(dataA);
        log("GET returned", wines.length, "rows");
      } catch (eA) {
        warn("GET failed, trying POST fallback:", eA.message || eA);
      }

      // Strategy B: POST JSON manual with limit=50
      if (!wines.length && q) {
        try {
          const resB = await fetch("search_wine.php?mode=manual&limit=50", {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            body: JSON.stringify({ query: q })
          });
          if (!resB.ok) throw new Error("POST HTTP " + resB.status);
          const dataB = await resB.json();
          wines = normResults(dataB);
          log("POST returned", wines.length, "rows");
        } catch (eB) {
          warn("POST fallback failed:", eB.message || eB);
        }
      }

      if (!wines.length) {
        setStatus("");
        fillResults([]);
        showResults(0);
        return;
      }

      fillResults(wines);
      setStatus("");
      showResults(wines.length);
    } catch (e) {
      error("runSearch error:", e);
      setStatus("Search failed.");
    }
  }
  function applyConfirm(){
    const w = window.__catalogSelectedWine;
    if (!w) { warn("No wine selected"); return; }
    applyPrefill(w);
    els.confirmWrap.style.display = "none";
  }

  // DOM wire
  function domReady(){
    ref();
    if (!els.wrap) { warn("catalog block missing"); return; }
    els.btn && els.btn.addEventListener("click", runSearch);
    els.backBtn && els.backBtn.addEventListener("click", function(){
      els.confirmWrap.style.display = "none";
      els.resultsWrap.style.display = "block";
    });
    els.confirmBtn && els.confirmBtn.addEventListener("click", applyConfirm);
    els.clearBtn && els.clearBtn.addEventListener("click", clearResults);
    els.form && els.form.addEventListener("submit", e => e.preventDefault());
    els.q_name && els.q_name.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); runSearch(); }});

    log("init ok");
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", domReady, { once: true });
  else domReady();

  // Export for console
  window.__catalogRunSearch = runSearch;
  window.__catalogApplyConfirm = applyConfirm;
  window.__catalogClear = clearResults;
})();
