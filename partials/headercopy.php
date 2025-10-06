<?php // partials/header.php ?>
<header class="sticky top-0 z-50 bg-white/80 backdrop-blur border-b">
    <nav class="mx-auto max-w-6xl px-4 h-14 flex items-center gap-2">
        <a href="/home.php" class="font-semibold flex items-center gap-2 text-gray-900">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2v7a6 6 0 1 0 12 0V2"/><path d="M8 22h8"/></svg>
            WineCellarHub
        </a>
        <div class="ml-auto flex items-center gap-1">
            <a href="/inventory.php" class="px-3 py-2 rounded-xl hover:bg-gray-100">Inventory</a>
            <a href="/scan.php" class="px-3 py-2 rounded-xl hover:bg-gray-100">Scan</a>
            <a href="/add_bottle.php" class="px-3 py-2 rounded-xl hover:bg-gray-100">Add</a>
            <a href="/expert_lists.php" class="px-3 py-2 rounded-xl hover:bg-gray-100">Expert Lists</a>
            <a href="/app/" class="px-3 py-2 rounded-xl hover:bg-gray-100">For You</a>
            <a href="/account.php" class="px-3 py-2 rounded-xl hover:bg-gray-100">Account</a>
        </div>
    </nav>
</header>