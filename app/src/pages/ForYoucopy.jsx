import React, { useEffect, useState } from "react";

function KpiCard({ title, value, sub }) {
    return (
        <div className="rounded-2xl shadow p-4 md:p-6 bg-white">
            <div className="text-sm text-gray-500">{title}</div>
            <div className="text-2xl font-semibold mt-1">{value ?? "—"}</div>
            {sub && <div className="text-xs text-gray-500 mt-2">{sub}</div>}
        </div>
    );
}

function WineCard({ wine, onAdd }) {
    return (
        <div className="rounded-xl shadow p-4 bg-white flex gap-4">
            <img
                src={wine.image_url || "/placeholder.png"}
                alt={wine.name}
                className="w-16 h-24 object-cover rounded"
                loading="lazy"
            />
            <div className="flex-1">
                <div className="font-medium">
                    {wine.name} {wine.vintage ? `(${wine.vintage})` : ""}
                </div>
                <div className="text-sm text-gray-500">
                    {wine.region} • {wine.grapes}
                </div>
                <div className="text-sm mt-1">
                    ${Number(wine.price || 0).toFixed(2)}
                </div>
                {wine.reason && (
                    <div className="text-xs text-gray-500 mt-1">{wine.reason}</div>
                )}
            </div>
            <div className="flex flex-col items-end gap-2">
                {typeof wine.score !== "undefined" && (
                    <div className="text-sm text-gray-500 self-start">
                        Score: {wine.score}
                    </div>
                )}
                {onAdd && (
                    <button
                        className="text-xs px-3 py-1 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700"
                        onClick={() => onAdd(wine)}
                        title="Add to my cellar"
                    >
                        + Add
                    </button>
                )}
            </div>
        </div>
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
        <section className="bg-white rounded-2xl shadow p-4 md:p-5 mb-8">
            <h2 className="text-lg md:text-xl font-semibold mb-2">
                🔎 Natural-language search
            </h2>
            <p className="text-gray-600 text-sm mb-3">
                Try: <em>peppery syrah under $30 to drink this fall</em>
            </p>
            <div className="flex gap-2">
                <input
                    className="flex-1 border rounded-lg px-3 py-2 focus:outline-none focus:ring focus:border-indigo-400"
                    placeholder="Describe what you want…"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === "Enter") runSearch();
                    }}
                />
                <button
                    className="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700"
                    onClick={() => runSearch()}
                >
                    {loading ? "Searching…" : "Search"}
                </button>
            </div>
        </section>
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
            const res = await fetch("/api/add_to_cellar.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "include",
                body: JSON.stringify({
                    wine_id: w.id,
                    price: w.price ?? null,
                    name: w.name ?? null,
                    winery: w.winery ?? null,
                    region: w.region ?? null,
                    grapes: w.grapes ?? null,
                    image_url: w.image_url ?? null,
                    type: w.type ?? null,
                    vintage: w.vintage ?? null,
                }),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data?.error || "Add failed");
            // tiny toast
            alert("Added to your cellar!");
        } catch (e) {
            console.error(e);
            alert("Could not add bottle. Please try again.");
        }
    }

    if (loading) return <div className="p-6">Loading…</div>;

    return (

        <div className="max-w-6xl mx-auto p-4 md:p-8">
            <h1 className="text-3xl font-semibold mb-4">For You</h1>

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

            {/* (Step 4) AI Search */}
            <SearchInline
                onResults={(rows) => {
                    setSearchResults(rows);
                    setMode("search");
                }}
            />

            {/* (Step 5) Portfolio forecast KPI (if available) */}
            {forecast && (
                <div className="mb-6">
                    <div className="rounded-2xl shadow p-4 md:p-6 bg-white">
                        <div className="text-sm text-gray-500">Projected 12-month Move</div>
                        <div className="text-2xl font-semibold mt-1">
                            {forecast.one_year?.pct != null ? `${forecast.one_year.pct > 0 ? "+" : ""}${forecast.one_year.pct}%` : "—"}
                        </div>
                        {forecast.one_year?.conf != null && (
                            <div className="text-xs text-gray-500 mt-2">
                                Confidence: {Math.round(forecast.one_year.conf * 100)}%
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Toggle header */}
            <div className="flex items-center justify-between mb-3">
                <h2 className="text-xl font-semibold">
                    {mode === "search" ? "Search results" : "Recommended for you"}
                </h2>
                <div className="flex gap-2">
                    <button
                        onClick={() => setMode("recs")}
                        className={`px-3 py-1 rounded-lg border ${mode === "recs" ? "bg-indigo-600 text-white border-indigo-600" : "bg-white text-gray-700 border-gray-300"}`}
                    >
                        Recommendations
                    </button>
                    <button
                        onClick={() => setMode("search")}
                        className={`px-3 py-1 rounded-lg border ${mode === "search" ? "bg-indigo-600 text-white border-indigo-600" : "bg-white text-gray-700 border-gray-300"}`}
                    >
                        Search
                    </button>
                </div>
            </div>

            {/* Grids */}
            {mode === "search" ? (
                <div className="grid md:grid-cols-2 gap-4">
                    {searchResults.map((w) => (
                        <WineCard key={`s-${w.id}`} wine={w} onAdd={addToCellar} />
                    ))}
                </div>
            ) : (
                <div className="grid md:grid-cols-2 gap-4">
                    {recs.map((w) => (
                        <WineCard key={`r-${w.id}`} wine={w} onAdd={addToCellar} />
                    ))}
                </div>
            )}
        </div>
    );
}
