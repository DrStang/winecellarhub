<?php
// scan.php — capture/upload a label or barcode photo, send to AI/search endpoint,
// show a polished confirmation UI, and prefill add_bottle.php.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require __DIR__.'/analytics_track.php'; // <-- add this

$defaultMode = (isset($_GET['mode']) && in_array($_GET['mode'], ['label','barcode'], true)) ? $_GET['mode'] : 'label';
?>
<?php if (file_exists(__DIR__.'/head.php')) require __DIR__ . '/head.php'; ?>
<?php if (file_exists(__DIR__.'/partials/header.php')) require __DIR__ . '/partials/header.php'; ?>

<main class="themed max-w-6xl mx-auto p-4 md:p-8">
    <div class="rounded-2xl shadow p-6 md:p-8 bg-[var(--surface)] text-[var(--text)]">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
            <h1 class="text-2xl md:text-3xl font-semibold">Scan Wine</h1>
            <div class="flex items-center gap-2 text-sm">
                <a href="add_bottle.php" class="px-3 py-2 rounded-xl border border-[var(--border)] text-[var(--text)] hover:bg-[var(--muted-bg)]">Skip → Add Manually</a>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-2 mb-4 flex-wrap">
            <button id="tabLabel" class="px-3 py-1.5 rounded-full border text-sm <?php echo $defaultMode==='label' ? 'bg-[var(--muted-bg)] border-[var(--border)]' : 'border-[var(--border)] hover:bg-[var(--muted-bg)]'; ?>">Label</button>
            <button id="tabBarcode" class="px-3 py-1.5 rounded-full border text-sm <?php echo $defaultMode==='barcode' ? 'bg-[var(--muted-bg)] border-[var(--border)]' : 'border-[var(--border)] hover:bg-[var(--muted-bg)]'; ?>">Barcode</button>
        </div>

        <!-- LABEL PANE -->
        <section id="pane-label" class="<?php echo $defaultMode==='label' ? '' : 'hidden'; ?>">
            <div class="grid gap-3">
                <label class="text-sm text-[var(--muted)]">Upload a clear photo of the front label</label>
                <input id="labelFile" type="file" accept="image/*" class="file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border file:border-[var(--border)] file:bg-[var(--base)] file:text-[var(--text)] file:hover:bg-[var(--muted-bg)] border border-dashed border-[var(--border)] rounded-xl p-3 bg-[var(--base)]" />

                <!-- Image preview (FIX: add missing container so JS doesn’t crash) -->
                <div id="imagePreview" class="hidden">
                    <img id="previewImg" alt="Label preview" class="mt-2 max-h-60 rounded-xl border border-[var(--border)] object-contain" />
                </div>

                <div class="flex items-center gap-3 flex-wrap mt-1">
                    <button id="btnLabelScan" class="px-4 py-2 rounded-xl bg-[var(--accent)] text-white disabled:opacity-60">Analyze Label</button>
                    <span id="lblSpin" class="hidden inline-block w-5 h-5 border-2 border-[var(--muted)] border-t-transparent rounded-full animate-spin" aria-hidden></span>
                    <p class="text-sm text-[var(--muted)]">Tip: Fill the frame with brand + wine name; avoid glare.</p>
                </div>
            </div>
        </section>

        <!-- BARCODE PANE -->
        <section id="pane-barcode" class="<?php echo $defaultMode==='barcode' ? '' : 'hidden'; ?>">
            <div class="grid gap-3">
                <label class="text-sm text-[var(--muted)]">Upload a clear photo of the barcode</label>
                <input id="barcodeFile" type="file" accept="image/*" class="file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border file:border-[var(--border)] file:bg-[var(--base)] file:text-[var(--text)] file:hover:bg-[var(--muted-bg)] border border-dashed border-[var(--border)] rounded-xl p-3 bg-[var(--base)]" />

                <div id="barcodePreview" class="hidden">
                    <img id="barcodePreviewImg" alt="Barcode preview" class="mt-2 max-h-60 rounded-xl border border-[var(--border)] object-contain" />
                </div>

                <div class="flex items-center gap-3 flex-wrap mt-1">
                    <button id="btnBarcodeScan" class="px-4 py-2 rounded-xl bg-[var(--accent)] text-white disabled:opacity-60">Read Barcode</button>
                    <span id="bcSpin" class="hidden inline-block w-5 h-5 border-2 border-[var(--muted)] border-t-transparent rounded-full animate-spin" aria-hidden></span>
                    <p class="text-sm text-[var(--muted)]">If unreadable, try better lighting and hold steady.</p>
                </div>
            </div>
        </section>

        <!-- Results -->
        <div class="mt-6">
            <h2 class="text-lg font-medium mb-2">Matches</h2>
            <div id="results" class="grid gap-3"></div>
        </div>
    </div>

    <!-- Modal -->
    <div id="mb" class="hidden fixed inset-0 bg-black/60"></div>
    <div id="modal" class="hidden fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[min(680px,92vw)] rounded-2xl shadow-xl border border-[var(--border)] bg-[var(--surface)] p-5">
        <h3 class="text-xl font-semibold mb-2">Is this correct?</h3>
        <div id="modalContent" class="text-sm text-[var(--text)]"></div>
        <div class="flex items-center gap-2 mt-4 flex-wrap">
            <button id="confirm" class="px-4 py-2 rounded-xl bg-[var(--accent)] text-white">Confirm</button>
            <button id="back" class="px-4 py-2 rounded-xl border border-[var(--border)]">Back</button>
            <button id="reject" class="px-4 py-2 rounded-xl bg-red-600 text-white">No</button>
        </div>
    </div>
</main>

<script>
    // ---- CONFIG ----
    // NOTE: Your uploaded endpoint is search_wine83125.php.
    // If you prefer the canonical name, rename the PHP file to search_wine.php and keep the URL below.
    const SEARCH_ENDPOINT = 'search_wine_scan.php';

    // ---- Tabs ----
    const tabLabel = document.getElementById('tabLabel');
    const tabBarcode = document.getElementById('tabBarcode');
    const paneLabel = document.getElementById('pane-label');
    const paneBarcode = document.getElementById('pane-barcode');
    if (tabLabel && tabBarcode) {
        tabLabel.addEventListener('click', () => { paneLabel.classList.remove('hidden'); paneBarcode.classList.add('hidden'); });
        tabBarcode.addEventListener('click', () => { paneBarcode.classList.remove('hidden'); paneLabel.classList.add('hidden'); });
    }

    // ---- Preview handlers (FIX: prevent null refs when preview nodes are missing) ----
    const imagePreview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    const barcodePreview = document.getElementById('barcodePreview');
    const barcodePreviewImg = document.getElementById('barcodePreviewImg');

    document.getElementById('labelFile')?.addEventListener('change', (e) => {
        const file = e.target.files?.[0];
        if (!file || !imagePreview || !previewImg) return;
        const reader = new FileReader();
        reader.onload = (ev) => { previewImg.src = ev.target.result; imagePreview.classList.remove('hidden'); };
        reader.readAsDataURL(file);
    });

    document.getElementById('barcodeFile')?.addEventListener('change', (e) => {
        const file = e.target.files?.[0];
        if (!file || !barcodePreview || !barcodePreviewImg) return;
        const reader = new FileReader();
        reader.onload = (ev) => { barcodePreviewImg.src = ev.target.result; barcodePreview.classList.remove('hidden'); };
        reader.readAsDataURL(file);
    });

    // ---- Helpers ----
    async function downscaleToDataURL(file, maxSize = 1600) {
        function loadFromFile(f) {
            return new Promise((resolve, reject) => {
                const fr = new FileReader();
                fr.onload = () => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = fr.result; };
                fr.onerror = reject; fr.readAsDataURL(f);
            });
        }

        let src, w, h;
        if ('createImageBitmap' in window) {
            try { const bmp = await createImageBitmap(file); src = bmp; w = bmp.width; h = bmp.height; }
            catch { const img = await loadFromFile(file); src = img; w = img.width; h = img.height; }
        } else {
            const img = await loadFromFile(file); src = img; w = img.width; h = img.height;
        }

        const scale = Math.min(1, maxSize / Math.max(w, h));
        const W = Math.round(w * scale), H = Math.round(h * scale);
        const canvas = document.createElement('canvas');
        canvas.width = W; canvas.height = H;
        canvas.getContext('2d').drawImage(src, 0, 0, W, H);
        return canvas.toDataURL('image/jpeg', 0.85);
    }

    async function callAI(fileB64, mode) {
        const resp = await fetch(SEARCH_ENDPOINT, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode, image_b64: fileB64 })
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        return data;
    }

    function toggleSpin(btn, spinner, on) {
        if (!btn || !spinner) return;
        btn.disabled = on; spinner.classList.toggle('hidden', !on);
    }

    // ---- Renderers ----
    const resultsEl = document.getElementById('results');
    let selectedPayload = null;

    function renderWineCard(info, source) {
        const name = info.name || '';
        const winery = info.winery || '';
        const grapes = info.grapes || '';
        const vintage = info.vintage || '';
        const region = info.region || '';
        const country = info.country || '';
        const type = info.type || '';
        const style = info.style || '';
        const upc = info.upc || '';
        const imageUrl = info.image_url || '';
        const price = (info.price ?? '') !== '' ? info.price : null;
        const rating = (info.rating ?? '') !== '' ? info.rating : null;
        const confPct = Math.round((Number(info.confidence ?? 0)) * 100);

        const el = document.createElement('div');
        el.className = 'grid grid-cols-[100px_1fr_auto] gap-3 p-3 rounded-xl border border-[var(--border)] bg-[var(--base)]';
        el.innerHTML = `
      ${imageUrl ? `<img class="w-[100px] h-[120px] rounded-lg border border-[var(--border)] object-cover" src="${imageUrl}" onerror="this.src='covers/placeholder.png'">`
            : `<div class='w-[100px] h-[120px] grid place-items-center text-xs text-[var(--muted)] rounded-lg border border-[var(--border)]'>No Image</div>`}
      <div>
        <h3 class="font-semibold">${name || 'Unknown Wine'}</h3>
        <div class="text-sm mt-1"><b>Winery:</b> ${winery || '—'}</div>
        <div class="text-sm"><b>Grapes:</b> ${grapes || '—'}</div>
        <div class="text-sm"><b>Vintage:</b> ${vintage || '—'}</div>
        <div class="text-sm"><b>Region:</b> ${[region, country].filter(Boolean).join(', ') || '—'}</div>
        <div class="text-sm"><b>Type:</b> ${type || '—'} &nbsp; <b>Style:</b> ${style || '—'}</div>
        ${upc ? `<div class="text-sm"><b>UPC:</b> ${upc}</div>` : ''}
        ${price !== null ? `<div class="text-sm"><b>Price:</b> $${price}</div>` : ''}
        ${rating !== null ? `<div class="text-sm"><b>Rating:</b> ${rating}</div>` : ''}
        ${source === 'extracted' ? `<div class="mt-2">
          <div class="text-xs text-[var(--muted)]">AI confidence: ${confPct}%</div>
          <div class="h-1.5 rounded bg-[var(--muted-bg)] overflow-hidden mt-1"><div style="width:${confPct}%" class="h-full bg-[var(--accent)]"></div></div>
        </div>` : ''}
      </div>
      <div class="flex items-center"><button class="px-3 py-1.5 rounded-lg bg-[var(--accent)] text-white">Use</button></div>
    `;

        el.querySelector('button').addEventListener('click', () => {
            selectedPayload = { mode: source, data: info };
            openModal(info, source);
        });
        return el;
    }

    function showCandidates(list, extracted = null) {
        resultsEl.innerHTML = '';
        if ((!list || list.length === 0) && !extracted) {
            resultsEl.innerHTML = '<p class="text-sm text-[var(--muted)]">No matches found. You can still use the extracted data or add manually.</p>';
        }
        if (extracted) resultsEl.appendChild(renderWineCard(extracted, 'extracted'));
        (list || []).forEach(item => resultsEl.appendChild(renderWineCard(item, 'db')));
    }

    function openModal(info, source) {
        const mc = document.getElementById('modalContent');
        const mb = document.getElementById('mb');
        const modal = document.getElementById('modal');

        const lines = [info.winery, info.grapes, info.vintage, info.region, info.country, info.type].filter(Boolean).join(' · ');
        const conf = (source === 'extracted' && typeof info.confidence !== 'undefined') ? `<div class="text-xs text-[var(--muted)]">AI confidence: ${Math.round(Number(info.confidence)*100)}%</div>` : '';

        mc.innerHTML = `
      <div class="grid grid-cols-2 gap-3">
        <div>
          <div class="font-medium">${info.name || ''}</div>
          <div class="text-[var(--muted)]">${lines}</div>
          ${info.price ? `<div class='text-[var(--muted)]'>Price: $${info.price}</div>` : ''}
          ${info.rating ? `<div class='text-[var(--muted)]'>Rating: ${info.rating}</div>` : ''}
          ${info.upc ? `<div class='text-[var(--muted)]'>UPC: ${info.upc}</div>` : ''}
          ${conf}
        </div>
        <div class="justify-self-end">
          ${info.image_url ? `<img src="${info.image_url}" class="max-h-40 rounded-xl border border-[var(--border)] object-contain" onerror="this.src='covers/placeholder.png'">` : ''}
        </div>
      </div>`;

        mb.classList.remove('hidden');
        modal.classList.remove('hidden');
    }

    function closeModal() {
        document.getElementById('mb').classList.add('hidden');
        document.getElementById('modal').classList.add('hidden');
    }

    document.getElementById('mb').addEventListener('click', closeModal);
    document.getElementById('back').addEventListener('click', closeModal);
    document.getElementById('reject').addEventListener('click', closeModal);

    document.getElementById('confirm').addEventListener('click', () => {
        if (!selectedPayload) return;
        sessionStorage.setItem('prefill_wine', JSON.stringify(selectedPayload.data));
        window.location.href = 'add_bottle.php?prefill=1';
    });

    // ---- Actions ----
    document.getElementById('btnLabelScan')?.addEventListener('click', async () => {
        const f = document.getElementById('labelFile').files?.[0];
        if (!f) return alert('Choose a label photo first.');
        const b64 = await downscaleToDataURL(f, 1600);
        const btn = document.getElementById('btnLabelScan');
        const spin = document.getElementById('lblSpin');
        toggleSpin(btn, spin, true);
        try {
            const data = await callAI(b64, 'label');
            showCandidates(data.candidates, data.extracted);
        } catch (e) { alert('Label analysis failed: ' + (e.message || e)); console.error(e); }
        finally { toggleSpin(btn, spin, false); }
    });

    document.getElementById('btnBarcodeScan')?.addEventListener('click', async () => {
        const f = document.getElementById('barcodeFile').files?.[0];
        if (!f) return alert('Choose a barcode photo first.');
        const b64 = await downscaleToDataURL(f, 1600);
        const btn = document.getElementById('btnBarcodeScan');
        const spin = document.getElementById('bcSpin');
        toggleSpin(btn, spin, true);
        try {
            const data = await callAI(b64, 'barcode');
            if (!data || (!data.upc && (!data.candidates || data.candidates.length === 0))) {
                resultsEl.innerHTML = "<p class='text-sm text-[var(--muted)]'>Couldn't read a barcode. Try again or add manually.</p>";
                return;
            }
            // Show UPC in the extracted slot if present
            const extracted = data.extracted || (data.upc ? { upc: data.upc, confidence: data.confidence } : null);
            showCandidates(data.candidates, extracted);
        } catch (e) { alert('Barcode read failed: ' + (e.message || e)); console.error(e); }
        finally { toggleSpin(btn, spin, false); }
    });
</script>
