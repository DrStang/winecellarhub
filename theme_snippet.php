<?php /* theme_snippet.php — include in <head> (or near top of body if you prefer) */ ?>
<style>
    /* ===== Base & Existing Themes ===== */
    :root, [data-theme="minimal"]{
        --primary:#0D9488; --primary-600:#0D9488; --primary-700:#0B7F75;
        --accent:#14B8A6;  --surface:#FFFFFF;     --text:#1F2937;  --muted:#6B7280;
    }
    [data-theme="burgundy"]{
        --primary:#7B2D26; --primary-600:#7B2D26; --primary-700:#65241F;
        --accent:#C44536;  --surface:#F5F3F4;     --text:#2B2D42;  --muted:#6B7280;
    }
    [data-theme="earth"]{
        --primary:#606C38; --primary-600:#606C38; --primary-700:#4F592F;
        --accent:#DDA15E;  --surface:#FEFAE0;     --text:#283618;  --muted:#6B7280;
    }
    [data-theme="luxury"]{
        --primary:#1C1C1E; --primary-600:#1C1C1E; --primary-700:#171719;
        --accent:#D4AF37;  --surface:#2C2C2E;     --text:#F5F5F7;  --muted:#A1A1AA;
    }
    [data-theme="pastel"]{
        --primary:#E9D5FF; --primary-600:#E9D5FF; --primary-700:#C4A8F2;
        --accent:#F9A8D4;  --surface:#FFFFFF;     --text:#374151;  --muted:#6B7280;
    }

    /* ===== New Blue-Based Themes ===== */
    /* 1) Ocean Breeze */
    [data-theme="ocean"]{
        --primary:#2563EB; --primary-600:#2563EB; --primary-700:#1D4ED8;
        --accent:#06B6D4;  --surface:#F0F9FF;     --text:#0F172A;  --muted:#64748B;
    }
    /* 2) Navy & Gold */
    [data-theme="navy"]{
        --primary:#1D4ED8; --primary-600:#1D4ED8; --primary-700:#1E40AF;
        --accent:#FBBF24;  --surface:#111827;     --text:#F9FAFB;  --muted:#9CA3AF;
    }
    /* 3) Cerulean & Coral */
    [data-theme="cerulean"]{
        --primary:#3B82F6; --primary-600:#3B82F6; --primary-700:#2563EB;
        --accent:#F87171;  --surface:#FFFFFF;     --text:#1E293B;  --muted:#6B7280;
    }
    /* 4) Stormy Gray */
    [data-theme="storm"]{
        --primary:#1E40AF; --primary-600:#1E40AF; --primary-700:#1D4ED8;
        --accent:#64748B;  --surface:#F9FAFB;     --text:#111827;  --muted:#6B7280;
    }
    /* 5) Ice & Mint */
    [data-theme="ice"]{
        --primary:#2563EB; --primary-600:#2563EB; --primary-700:#1D4ED8;
        --accent:#10B981;  --surface:#ECFDF5;     --text:#064E3B;  --muted:#6EE7B7;
    }

    /* Minimal helpers for pages without Tailwind */
    .btn{
        display:inline-flex;align-items:center;justify-content:center;
        padding:0.5rem 0.875rem;border-radius:0.5rem;font-weight:600;
        background:var(--primary-600);color:#fff;border:1px solid transparent;
    }
    .btn:hover{ background:var(--primary-700); }
    .input{
        width:100%;padding:0.5rem 0.75rem;border-radius:0.5rem;
        border:1px solid #D1D5DB;color:var(--text);background:var(--surface);
    }
    .input:focus{
        outline:none;border-color:var(--primary-600);
        box-shadow:0 0 0 3px color-mix(in oklab, var(--primary-600) 30%, transparent);
    }
    .card{
        background:var(--surface);color:var(--text);border-radius:1rem;padding:1rem;
        box-shadow:0 1px 2px rgba(0,0,0,0.06),0 4px 12px rgba(0,0,0,0.06);
    }
    .muted{ color:var(--muted);font-size:0.875rem; }

    /* Optional: scoped auto-theming wrapper for zero per-field edits */
    .themed { background:var(--surface); color:var(--text); }
    .themed a { color:var(--primary-600); }
    .themed input,.themed select,.themed textarea{
        background:var(--surface); color:var(--text); border:1px solid #D1D5DB;
    }
    .themed input:focus,.themed select:focus,.themed textarea:focus{
        outline:none; border-color:var(--primary-600);
        box-shadow:0 0 0 3px color-mix(in oklab, var(--primary-600) 30%, transparent);
    }
    .themed button:not(.unstyled), .themed .btn{
        background:var(--primary-600); color:#fff; border:1px solid transparent;
        border-radius:.5rem; padding:.5rem .875rem; font-weight:600;
    }
    .themed button:not(.unstyled):hover, .themed .btn:hover{ background:var(--primary-700); }
</style>
<script>
    (function () {
        // Default to 'burgundy' unless user previously chose another theme.
        var saved = localStorage.getItem('theme') || 'burgundy';
        document.documentElement.setAttribute('data-theme', saved);
        window.setTheme = function(name){
            document.documentElement.setAttribute('data-theme', name);
            localStorage.setItem('theme', name);
        };
    })();
</script>


