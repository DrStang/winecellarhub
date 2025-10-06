<?php
// inventory.php — adds Past toggle, Status + Type filters, Delete with confirm
require_once __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<?php require __DIR__ . '/head.php'; ?>
<body class="bg-[var(--surface)] text-[var(--text)]">
<?php require __DIR__ . '/partials/header.php';
require __DIR__. '/analytics_track.php';?>

<main class="mx-auto max-w-6xl px-4 py-6">
    <div class="flex items-center justify-between gap-3 mb-4">
        <h1 class="text-2xl font-semibold">Your Inventory</h1>

        <div class="flex items-center gap-2">
            <input id="inv-search"
                   class="w-72 border rounded-xl px-3 py-2 text-sm"
                   placeholder="Search (name, winery, region, grapes, location)" />
            <button id="inv-go" class="px-3 py-2 rounded-lg bg-[var(--primary-600)] text-white text-sm hover:bg-[var(--primary-700)]">Search</button>
        </div>
    </div>

    <!-- Filters row -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
        <label class="text-sm text-gray-600">Status</label>
        <select id="flt-status" class="border rounded-lg px-3 py-2 text-sm">
            <option value="current">Current</option>
            <option value="past">Past</option>
            <option value="all">All</option>
        </select>

        <label class="text-sm text-gray-600 ml-2">Type</label>
        <select id="flt-type" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">All types</option>
        </select>

        <button id="flt-reset" class="px-3 py-2 rounded-lg border text-sm">Reset</button>
    </div>

    <!-- Grid -->
    <div id="inv-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>

    <!-- Pager -->
    <div id="inv-pager" class="flex items-center gap-2 justify-center mt-6"></div>
</main>

<script>
    let INV_PAGE = 1;
    let INV_SIZE = 24;
    let INV_Q    = '';
    let INV_STATUS = 'current';  // current | past | all
    let INV_TYPE   = '';         // exact match on catalog "type"

    async function fetchJSON(url, opts={}) {
        const res = await fetch(url, opts);
        const data = await res.json().catch(()=>({ok:false,error:'Invalid JSON'}));
        return data;
    }

    function renderPager(total) {
        const pager = document.getElementById('inv-pager');
        pager.innerHTML = '';
        const pages = Math.max(1, Math.ceil(total / INV_SIZE));
        if (pages <= 1) return;
        const mk = (p, label=String(p)) => {
            const b = document.createElement('button');
            b.className = 'px-3 py-1 rounded border text-sm ' + (p===INV_PAGE?'bg-gray-900 text-white':'bg-white');
            b.textContent = label;
            b.onclick = ()=>{ INV_PAGE = p; loadInv(); };
            return b;
        };
        pager.appendChild(mk(Math.max(1, INV_PAGE-1), 'Prev'));
        for (let p=1;p<=pages && p<=10;p++) pager.appendChild(mk(p));
        pager.appendChild(mk(Math.min(pages, INV_PAGE+1), 'Next'));
    }

    function cardHTML(item){
        const badge = item.past == 1
            ? '<span class="inline-block text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">Past</span>'
            : '';
        const type  = item.type ? `<span class="text-xs text-gray-500 ml-2">${item.type}</span>` : '';
        const thumb = item.thumb ? item.thumb : '';
        const vint  = (item.vintage && item.vintage!=='' && item.vintage!=='0') ? item.vintage : 'NV';

        return `
    <div class="group rounded-2xl bg-[var(--surface)] text-[var(--text)] shadow
         transition-all duration-200 transform-gpu will-change-transform
         hover:shadow-2xl hover:-translate-y-1 hover:scale-[1.01]
         focus-within:ring-2 focus-within:ring-indigo-500/50">
      <div class="relative">
        ${thumb ? `<a href="/bottle.php?id=${item.bottle_id}" aria-label="Open ${item.name}">
                    <img src="${thumb}"
                        class="w-full h-40 md:h-48 object-contain bg-gray-100 p-2 rounded"
                        loading="lazy" />
                    </a>`
            : `<div class="w-full h-48 bg-gray-100"></div>`}
        <div class="absolute top-2 left-2 space-x-2">${badge}</div>
      </div>
      <div class="p-3">
        <div class="font-medium">${item.name ?? 'Untitled'} <span class="text-gray-500">(${vint})</span>${type}</div>
        <div class="text-sm text-gray-600">${item.winery ?? ''}</div>
        <div class="text-xs text-gray-500">${item.region ?? ''}</div>

        <div class="flex gap-2 mt-3">
          <button data-id="${item.bottle_id}"
                  class="btn-toggle-past px-2 py-1 rounded border text-xs text-[var(--text)]">
            ${item.past == 1 ? 'Mark Current' : 'Mark Past'}
          </button>
          <button data-id="${item.bottle_id}"
                  class="btn-delete px-2 py-1 rounded border text-xs text-red-600 border-red-300">
            Delete
          </button>
          <a href="/bottle.php?id=${item.bottle_id}"
             class="ml-auto px-2 py-1 rounded border text-xs text-[var(--text)]">Details</a>
        </div>
      </div>
    </div>
  `;
    }

    async function loadTypes(){
        // populate Type filter once
        const j = await fetchJSON('/api/inventory_list.php?meta=types');
        const sel = document.getElementById('flt-type');
        if (j.ok && Array.isArray(j.types)) {
            j.types.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t; opt.textContent = t;
                sel.appendChild(opt);
            });
        }
    }

    async function loadInv(){
        const grid = document.getElementById('inv-grid');
        const pager = document.getElementById('inv-pager');

        // skeleton
        grid.innerHTML = '';
        for (let i=0;i<8;i++){
            const sk = document.createElement('div');
            sk.className = 'bg-white rounded-xl border p-3 animate-pulse';
            sk.innerHTML = '<div class="w-full h-48 bg-gray-200 rounded"></div><div class="mt-3 h-4 bg-gray-200 rounded w-3/4"></div>';
            grid.appendChild(sk);
        }
        pager.innerHTML = '';

        // fetch
        const url = `/api/inventory_list.php?page=${INV_PAGE}&pageSize=${INV_SIZE}&search=${encodeURIComponent(INV_Q)}&status=${encodeURIComponent(INV_STATUS)}&type=${encodeURIComponent(INV_TYPE)}`;
        const j = await fetchJSON(url);
        if (!j.ok) {
            grid.innerHTML = `<div class="col-span-full text-center text-red-600">${j.error ?? 'Failed to load'}</div>`;
            return;
        }

        // render
        grid.innerHTML = '';
        if (!j.items || j.items.length === 0) {
            grid.innerHTML = '<div class="col-span-full text-center text-gray-500">No bottles found.</div>';
        } else {
            for (const it of j.items) {
                const wrap = document.createElement('div');
                wrap.innerHTML = cardHTML(it);
                grid.appendChild(wrap.firstElementChild);
            }
        }
        renderPager(j.total);

        // wire buttons
        grid.querySelectorAll('.btn-toggle-past').forEach(btn=>{
            btn.addEventListener('click', async (e)=>{
                const id = e.currentTarget.getAttribute('data-id');
                const r = await fetch('/api/inventory_list.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({ action: 'toggle_past', bottle_id: id })
                }).then(r=>r.json()).catch(()=>({ok:false}));
                if (r.ok) loadInv();
                else alert(r.error || 'Failed to update');
            });
        });

        grid.querySelectorAll('.btn-delete').forEach(btn=>{
            btn.addEventListener('click', async (e)=>{
                const id = e.currentTarget.getAttribute('data-id');
                if (!confirm('Delete this bottle from your inventory? This cannot be undone.')) return;
                const r = await fetch('/api/inventory_list.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({ action: 'delete', bottle_id: id })
                }).then(r=>r.json()).catch(()=>({ok:false}));
                if (r.ok) loadInv();
                else alert(r.error || 'Delete failed');
            });
        });
    }

    // search + filters
    document.getElementById('inv-go').onclick = () => {
        INV_Q = document.getElementById('inv-search').value.trim();
        INV_PAGE = 1; loadInv();
    };
    document.getElementById('inv-search').addEventListener('keydown', e=>{
        if (e.key === 'Enter') document.getElementById('inv-go').click();
    });
    document.getElementById('flt-status').addEventListener('change', e=>{
        INV_STATUS = e.target.value; INV_PAGE=1; loadInv();
    });
    document.getElementById('flt-type').addEventListener('change', e=>{
        INV_TYPE = e.target.value; INV_PAGE=1; loadInv();
    });
    document.getElementById('flt-reset').addEventListener('click', ()=>{
        INV_Q=''; INV_STATUS='current'; INV_TYPE=''; INV_PAGE=1;
        document.getElementById('inv-search').value='';
        document.getElementById('flt-status').value='current';
        document.getElementById('flt-type').value='';
        loadInv();
    });

    // boot
    loadTypes().then(loadInv);
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>

</body>

</html>
