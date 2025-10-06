import React, { useEffect, useState } from "react";

function KpiCard({ title, value, sub }) {
    return (
        <div className="rounded-2xl shadow p-4 md:p-6 bg-[var(--surface)] text-[var(--text)]">
            <div className="text-sm text-[var(--muted)]">{title}</div>
            <div className="text-2xl font-semibold mt-1">{value ?? "—"}</div>
            {sub && <div className="text-xs text-[var(--muted)] mt-2">{sub}</div>}
        </div>
    );
}

function WineCard({ wine, onAdd, onWant }) {
    const owned = !!(wine?.in_inventory && wine?.bottle_id);
    const Wrapper = owned ? "a" : "div";
    const wrapperProps = owned
        ? { href: `/bottle.php?id=${encodeURIComponent(wine.bottle_id)}` }
        : {};

    return (
        <Wrapper
            {...wrapperProps}
            className="rounded-xl shadow p-4 bg-[var(--surface)] text-[var(--text)] flex gap-4"
        >
            <img
                src={wine.image_url || "/placeholder.png"}
                alt={wine.name}
                className="w-16 h-24 object-contain rounded"
                loading="lazy"
            />
            <div className="flex-1">
                <div className="font-medium">
                    {wine.name} {wine.vintage ? `(${wine.vintage})` : ""}
                </div>
                <div className="text-sm text-[var(--text)]">
                    {wine.region} • {wine.grapes}
                </div>
                <div className="text-sm mt-1">
                    ${Number(wine.price || 0).toFixed(2)}
                </div>
                {wine.reason && (
                    <div className="text-xs text-[var(--text)] mt-1">{wine.reason}</div>
                )}
            </div>

            {/* Show action buttons only if NOT already owned */}
            {!owned && (
                <div className="flex flex-col items-end gap-2">
                    {typeof wine.score !== "undefined" && (
                        <div className="text-sm text-[var(--text)] self-start">
                            Score: {wine.score}
                        </div>
                    )}
                    {onAdd && (
                        <button
                            className="text-xs px-3 py-1 rounded-lg bg-[var(--primary-600)] text-white hover:bg-[var(--primary-700)]"
                            onClick={(e) => {
                                e.preventDefault(); // in case this card ever lives in a link
                                onAdd(wine);
                            }}
                            title="Add to my cellar"
                        >
                            + Add to my Inventory
                        </button>
                    )}
                    {onWant && (
                        <button
                            className="text-xs px-3 py-1 rounded-lg bg-[var(--primary-600)] text-white hover:bg-[var(--primary-700)]"
                            onClick={(e) => {
                                e.preventDefault();
                                onWant(wine);
                            }}
                            title="Add to my wantlist"
                        >
                            ♥ Add to My Wantlist
                        </button>
                    )}
                </div>
            )}
        </Wrapper>
    );
}


function SearchInline({ onResults }) {
    const [q, setQ] = useState("");
    const [loading, setLoading] = useState(false);

    async function runSearch(query) {
        const qq = (query ?? q).trim();
        if (!qq) {
            onResults([]);
            return;
        }
        setLoading(true);
        try {
            const res = await fetch("/api/search_nlq.php?q=" + encodeURIComponent(qq), {
                credentials: "include",
            });
            const data = await res.json();
            onResults(data.results || []);
        } catch (e) {
            console.error(e);
            onResults([]);
        } finally {
            setLoading(false);
        }
    }

    return (
        <section className="bg-[var(--surface)] text-[var(--text)] rounded-2xl shadow p-4 md:p-5 mb-8">
            <h2 className="text-lg md:text-xl font-semibold mb-2">
                🔎 AI-Powered Wine Search
            </h2>
            <p className="text-[var(--muted)] text-sm mb-3">
                Try: <em>peppery syrah under $30 to drink this fall</em>
            </p>
            <div className="flex gap-2">
                <input
                    className="flex-1 border rounded-lg px-3 py-2 bg-[var(--surface)] text-[var(--text)]
                               focus:outline-none focus:ring-2 focus:ring-[var(--primary-600)] focus:border-[var(--primary-600)]"
                    placeholder="Describe what you want…"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === "Enter") runSearch();
                    }}
                />
                <button
                    className="px-4 py-2 rounded-lg bg-[var(--primary-600)] text-white hover:bg-[var(--primary-700)]"
                    onClick={() => runSearch()}
                >
                    {loading ? "Searching…" : "Search"}
                </button>
            </div>
        </section>
    );
}
// --- Add this in ForYou.jsx (top-level, above the default export) ---
function HeaderNav() {
    const path = typeof window !== "undefined" ? window.location.pathname : "/";

    const base = "pb-1 text-[var(--text)] hover:text-[var(--primary-600)]";
    const active = "border-b-2 border-[var(--primary-600)] text-[var(--primary-600)]";

    const isActive = (href, { startsWith = false } = {}) => {
        if (startsWith) return path.startsWith(href);
        return path === href;
    };

    const linkCls = (href, opts) => (isActive(href, opts) ? `${base} ${active}` : base);

    return (
        <header className="bg-white border-b sticky top-0 z-40">
            <nav className="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
                <a href="/home.php" className="font-semibold flex items-center gap-2 text-gray-900">
                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M6 2v7a6 6 0 1 0 12 0V2"/>
                        <path d="M8 22h8"/>
                    </svg>
                    WineCellarHub
                </a>
                <div className="flex space-x-8">
                    <a href="/inventory.php" className={linkCls("/inventory.php")}>Inventory</a>
                    <a href="/wantlist.php" className={linkCls("/wantlist.php")}>Wantlist</a>
                    <a href="/add_bottle.php" className={linkCls("/add_bottle.php")}>Add Bottle</a>
                    <a href="/expert_lists.php" className={linkCls("/expert_lists.php")}>Expert Lists</a>
                    <a href="/blog/" className={linkCls("/blog")}>Blog</a>
                    {/* For You (React app) — highlight for any /app* path */}
                    <a href="/app/" className={linkCls("/app", {startsWith: true})}>Wine-AI</a>
                </div>

                {/* RIGHT: account + logout */}
                <div className="flex space-x-6">
                    <a href="/account.php" className={linkCls("/account.php")}>
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"
                                  d="M5.121 17.804A9 9 0 1118.88 17.804M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span>Account</span>
                    </a>
                    <a href="/logout.php" className={linkCls("/logout.php")}>
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"
                                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"/>
                        </svg>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </header>
    );
}

export default function ForYou() {
    const [kpis, setKpis] = useState(null);
    const [recs, setRecs] = useState([]);
    const [loading, setLoading] = useState(true);

    // search results state
    const [searchResults, setSearchResults] = useState([]);
    const [mode, setMode] = useState("recs"); // 'recs' | 'search'

    // (Step 5) portfolio forecast KPI
    const [forecast, setForecast] = useState(null);

    useEffect(() => {
        async function fetchAll() {
            try {
                const [pRes, rRes, fRes] = await Promise.all([
                    fetch("/api/portfolio.php", { credentials: "include" }),
                    fetch("/api/recommendations.php", { credentials: "include" }),
                    fetch("/api/forecast_summary.php", { credentials: "include" }).catch(() => null),
                ]);

                const p = await pRes.json();
                const r = await rRes.json();
                setKpis(p.kpis || {});
                setRecs(r.recommendations || []);

                if (fRes && fRes.ok) {
                    const f = await fRes.json();
                    setForecast(f || null);
                }
            } catch (e) {
                console.error(e);
            } finally {
                setLoading(false);
            }
        }
        fetchAll();
    }, []);

    async function addToCellar(w) {
        try {
            const wineId = Number(w?.id ?? w?.wine_id);
            if (!Number.isFinite(wineId) || wineId <= 0) {
                console.error("Invalid wine id on item:", w);
                alert("Sorry—couldn’t determine the wine id for this item.");
                return;
            }

            // build + log payload (so console log works)
            const payload = {
                wine_id: wineId,
                price: (w?.price ?? '') === '' ? null : (isFinite(Number(w?.price)) ? Number(w.price) : null),
                name: (w?.name ?? '').trim() || null,
                winery: (w?.winery ?? '').trim() || null,
                region: (w?.region ?? '').trim() || null,
                grapes: (w?.grapes ?? '').trim() || null,
                image_url: (w?.image_url ?? '').trim() || null,
                type: (w?.type ?? '').trim() || null,
                vintage: /^\d{4}$/.test(String(w?.vintage ?? '')) ? Number(w.vintage) : null,
            };
            console.debug("addToCellar payload", payload);
            const res = await fetch("/api/add_to_cellar.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "include",
                body: JSON.stringify(payload),
                });

            const raw = await res.text();
            let data;
            try { data = JSON.parse(raw); } catch { data = { raw }; }

            if (!res.ok) {
                console.error("add_to_cellar failed", res.status, data);
                alert(`Could not add bottle (${res.status}).`);
                return;
            }

            alert("Added to your cellar!");
        } catch (e) {
            console.error("addToCellar error", e);
            alert("Could not add bottle. Please try again.");
        }
    }

    async function addToWantlist(w) {
        try {
            const body = new URLSearchParams({
                wine_id: String(w.id ?? ""),
                name: w.name ?? "",
                winery: w.winery ?? "",
                vintage: w.vintage ?? "",
            });
            const res = await fetch("/wantlist_api.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    credentials: "include",
                    body,
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data?.error || "Add failed");
            // tiny toast
            alert("Added to your wantlist!");
        } catch (e) {
            console.error(e);
            alert("Could not add bottle. Please try again.");
        }
    }

    if (loading) return <div className="p-6">Loading…</div>;

    return (
        <>
        <HeaderNav />
        <div className="max-w-6xl mx-auto p-4 md:p-8">
        <h1 className="text-3xl font-semibold mb-4">Wine-AI</h1>
        <p className="block text-sm">Don't worry if some fields are empty - Add to your Inventory so we can learn your preferences!</p>

            {/* KPI cards */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <KpiCard
                    title="Portfolio Value"
                    value={
                        kpis?.portfolio_value
                            ? `$${kpis.portfolio_value.toLocaleString()}`
                            : "—"
                    }
                    sub={kpis?.yoy_growth_pct != null ? `YoY: ${kpis.yoy_growth_pct}%` : "YoY: n/a"}
                />
                <KpiCard title="Approaching Peak" value={kpis?.near_peak ?? "—"} sub="Next ~6 months" />
                <KpiCard title="Past Peak" value={kpis?.past_peak ?? "—"} />
                <KpiCard
                    title="Recommendations"
                    value={mode === "recs" ? recs.length : searchResults.length}
                    sub={mode === "recs" ? "Personalized picks" : "Search matches"}
                />
            </div>
            {/* (Step 5) Portfolio forecast KPI (if available) */}
            {forecast && (
                <div className="mb-6">
                    <div className="rounded-2xl shadow p-4 md:p-6 bg-[var(--surface)] text-[var(--text)]">
                        <div className="text-sm text-[var(--muted)]">Projected 12-month Move</div>
                        <div className="text-2xl font-semibold mt-1">
                            {forecast.one_year?.pct != null ? `${forecast.one_year.pct > 0 ? "+" : ""}${forecast.one_year.pct}%` : "—"}
                        </div>
                        {forecast.one_year?.conf != null && (
                            <div className="text-xs text-[var(--muted)] mt-2">
                                Confidence: {Math.round(forecast.one_year.conf * 100)}%
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* (Step 4) AI Search */}
            <SearchInline
                onResults={(rows) => {
                    setSearchResults(rows);
                    setMode("search");
                }}
            />

            {/* Toggle header */}
            <div className="flex items-center justify-between mb-3">
                <h2 className="text-xl font-semibold">
                    {mode === "search" ? "Search results" : "Recommended for you"}
                </h2>
                <div className="flex gap-2">
                    <button
                        onClick={() => setMode("recs")}
                        className={`px-3 py-1 rounded-lg border ${mode === "recs" ? "bg-[var(--primary-600)] text-white border-[var(--primary-600)]" : "bg-[var(--surface)] text-[var(--text)] border-gray-300"}`}
                    >
                        Recommendations
                    </button>
                    <button
                        onClick={() => setMode("search")}
                        className={`px-3 py-1 rounded-lg border ${mode === "search" ? "bg-[var(--primary-600)] text-white border-[var(--primary-600)]" : "bg-[var(--surface)] text-[var(--text)] border-gray-300"}`}
                    >
                        Search
                    </button>
                </div>
            </div>

            {/* Grids */}
            {mode === "search" ? (
                <div className="grid md:grid-cols-2 gap-4">
                    {searchResults.map((w) => (
                        <WineCard key={`s-${w.id}`} wine={w} onAdd={addToCellar} onWant={addToWantlist}/>
                    ))}
                </div>
            ) : (
                <div className="grid md:grid-cols-2 gap-4">
                    {recs.map((w) => (
                        <WineCard key={`r-${w.id}`} wine={w} onAdd={addToCellar} onWant={addToWantlist} />
                    ))}
                </div>
            )}

        </div>
    </>
    );
}
