<?php // partials/header.php
$current = basename($_SERVER['PHP_SELF']);

function navClass(string $file, string $current): string {
    $base = "px-3 py-2 rounded-xl hover:bg-gray-100";
    return $file === $current
        ? $base . " font-semibold underline decoration-blue-600 decoration-2"
        : $base;
}?>
<header class="sticky top-0 z-50 bg-white/80 backdrop-blur border-b">
    <nav class="mx-auto max-w-6xl px-4 h-14 flex items-center gap-2">
        <a href="/home.php" class="font-semibold flex items-center gap-2 text-gray-900">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2v7a6 6 0 1 0 12 0V2"/><path d="M8 22h8"/></svg>
            WineCellarHub
        </a>
        <div class="ml-auto flex items-center gap-1">
            <a href="/inventory.php" class="<?= navClass('inventory.php',$current)?>">Inventory</a>
            <a href="/scan.php" class=""<?= navClass('scan.php',$current)?>"">Scan</a>
            <a href="/add_bottle.php" class=""<?= navClass('add_bottle.php',$current)?>"">Add</a>
            <a href="/expert_lists.php" class=""<?= navClass('expert_lists.php',$current)?>"">Expert Lists</a>
            <a href="/app/" class=""<?= navClass('/app/',$current)?>"">For You</a>
        </div>
        <?php
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $loggedIn    = !empty($_SESSION['user_id']);
        $displayName = $_SESSION['username'] ?? 'User';
        ?>
        <div class="ml-auto flex items-center gap-3">
            <?php if ($loggedIn): ?>
                <a href="/account.php" class="inline-flex items-center gap-2 header-link">
                    <!-- person icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M5.121 17.804A9 9 0 1118.88 17.804M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                </a>

                <a href="/logout.php" class="inline-flex items-center gap-2 header-link">
                    <!-- logout icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1"/>
                    </svg>
                    <span>Logout</span>
                </a>
            <?php else: ?>
                <a href="/login.php" class="header-link">Login</a>
                <a href="/register.php" class="header-link">Register</a>
            <?php endif; ?>
        </div>

    </nav>
</header>