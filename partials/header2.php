<?php // partials/header.php
$current = basename($_SERVER['PHP_SELF']);
?>

<header class="bg-white shadow">
    <nav class="max-w-6xl mx-auto px-4 py-3 flex space-x-8">
        <a href="/home.php"
           class="pb-1 <?php echo $current === 'home.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-blue-500'; ?>">
            Home
        </a>
        <a href="/inventory.php"
           class="pb-1 <?php echo $current === 'inventory.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-blue-500'; ?>">
            Inventory
        </a>
        <a href="/add_bottle.php"
           class="pb-1 <?php echo $current === 'add_bottle.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-blue-500'; ?>">
            Add Bottle
        </a>
        <a href="/portfolio.php"
           class="pb-1 <?php echo $current === 'portfolio.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-blue-500'; ?>">
            Portfolio
        </a>
        <a href="/app/"
           class="pb-1 <?php echo str_starts_with($_SERVER['REQUEST_URI'],'/app') ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-blue-500'; ?>">
            For You
        </a>

<?php
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $loggedIn    = !empty($_SESSION['user_id']);
        $displayName = $_SESSION['username'] ?? 'User';
        ?>
        <div class="flex items-center space-x-3">
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