<?php
require_once __DIR__ . '/../auth.php';
$page_title = 'Wine Pairing Concierge (Free Beta) · WineCellarHub';
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="max-w-3xl mx-auto p-6">
    <header class="mb-6">
        <h1 class="text-3xl font-bold">Wine Pairing Concierge (Free Beta)</h1>
        <p class="text-gray-600">Tell us your dish and budget; get curated wines that won’t start a fight with your menu.</p>
    </header>

    <form method="POST" action="/services/pairing-free_result.php?debug=1" class="space-y-5">
        <input type="hidden" name="demo" value="0">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="block text-sm font-medium mb-1">
                    Dish / menu <span class="text-red-600">*</span>
                </label>
                <textarea name="dish" rows="3" required
                          class="w-full rounded-md border px-3 py-2"
                          placeholder="e.g., ribeye with herb butter, roasted potatoes, arugula salad"></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">
                    Total Budget (USD) <span class="text-red-600">*</span>
                </label>
                <input type="number" name="budget" min="10" step="1" required
                       class="w-full rounded-md border px-3 py-2" placeholder="e.g., 100">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Guests</label>
                <input type="number" name="guests" min="1" value="2"
                       class="w-full rounded-md border px-3 py-2">
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-medium mb-1">Preferred Regions (optional)</label>
                <input type="text" name="regions"
                       class="w-full rounded-md border px-3 py-2"
                       placeholder="e.g., Rioja, Napa, Loire">
            </div>

            <!-- NEW: Spice (heat level) -->
            <div>
                <label class="block text-sm font-medium mb-1">Spice level (optional)</label>
                <select name="spice" class="w-full rounded-md border px-3 py-2">
                    <option value="">No preference</option>
                    <option value="mild">Mild</option>
                    <option value="medium">Medium</option>
                    <option value="hot">Hot</option>
                </select>
            </div>

            <!-- NEW: Occasion -->
            <div>
                <label class="block text-sm font-medium mb-1">Occasion (optional)</label>
                <select name="occasion" class="w-full rounded-md border px-3 py-2">
                    <option value="">No preference</option>
                    <option value="weeknight">Weeknight</option>
                    <option value="date night">Date night</option>
                    <option value="friends over">Friends over</option>
                    <option value="celebration">Celebration</option>
                    <option value="holiday">Holiday</option>
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="block text-sm font-medium mb-1">Email (optional, to send results)</label>
                <input type="email" name="email"
                       class="w-full rounded-md border px-3 py-2"
                       placeholder="you@example.com">
            </div>
        </div>

        <div class="flex gap-3">
            <button class="px-4 py-2 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                Get My Pairings
            </button>
            <span class="text-sm text-gray-600 self-center">
            Free for now — this will be a paid service soon.
        </span>
        </div>
    </form>

    <aside class="mt-8 p-4 rounded-xl border bg-white/70">
        Pro tip: keep track of what you loved. Download the
        <a class="underline" href="/store/journal-free.php">Wine Tasting Journal (free)</a>.
    </aside>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
