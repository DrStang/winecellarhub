(function() {
try {
const raw = sessionStorage.getItem('prefill_wine');
if (!raw) return;
const w = JSON.parse(raw);
// Map catalog fields into the appropriate inputs.
const map = {
name:'name', winery:'winery', vintage:'vintage', grapes:'grapes',
region:'region', country:'country', type:'type', style:'style',
rating:'rating', price:'price', upc:'upc', image:'image_url'
// NOTE: We intentionally do NOT auto-fill my_rating/my_price here.
};
Object.keys(map).forEach(k => {
const el = document.querySelector('[name="'+map[k]+'"]');
if (el && w[k] != null) el.value = w[k];
});
if (w.image) document.getElementById('image_url').value = w.image;
sessionStorage.removeItem('prefill_wine');
} catch(e) { console.warn('No prefill', e); }
})();