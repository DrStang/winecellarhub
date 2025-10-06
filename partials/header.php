<?php // partials/header.php
$current = basename($_SERVER['PHP_SELF']);
require __DIR__ . '/../head.php';
?>

<header class="bg-[var(--surface)]">
    <nav class="max-w-6xl mx-auto px-4 py-3 flex justify-between items-center">
        <a href="/home.php" class="font-semibold flex items-center gap-2 text-gray-900">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M6 2v7a6 6 0 1 0 12 0V2"/><path d="M8 22h8"/></svg>
            WineCellarHub
        </a>
    <div class ="flex space-x-8">
        <a href="/inventory.php"
           class="pb-1 <?php echo $current === 'inventory.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-[var(--primary-600)]'; ?>">
            Inventory
        </a>
        <a href="/wantlist.php"
           class="pb-1 <?php echo $current === 'wantlist.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-[var(--primary-600)]'; ?>">
            Wantlist
        </a>
        <!-- Wrap just the one nav item -->
        <div class="relative">
            <button id="addNavBtn"
                    class="inline-flex items-center gap-1 text-[var(--text)] hover:text-[var(--primary)] px-2 py-1 md:px-0 md:py-0"
                    aria-haspopup="true" aria-expanded="false">
                Add a bottle
                <svg class="w-4 h-4 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M6 9l6 6 6-6"/>
                </svg>
            </button>

            <!-- Desktop dropdown anchored to THIS wrapper -->
            <div id="addDropdown"
                 class="invisible opacity-0 scale-95 transition
              absolute top-full right-0 mt-2 w-72
              rounded-xl border border-black/10 bg-[var(--surface)] shadow-lg py-2 z-50">
                <a id="addScanLink" href="/scan.php"
                   class="block px-4 py-2 text-sm text-[var(--text)] hover:bg-black/5">📷 Scan label</a>
                <a id="addManualLink" href="/add_bottle.php?mode=manual"
                   class="block px-4 py-2 text-sm text-[var(--text)] hover:bg-black/5">🔎 Search catalog / manual entry</a>
            </div>
        </div>
        <div id="addSheet" class="fixed inset-0 z-[100] md:hidden hidden">
            <div class="absolute inset-0 bg-black/50" data-addsheet-close></div>
            <div class="absolute left-1/2 -translate-x-1/2 bottom-0 w-full max-w-lg rounded-t-2xl
              bg-[var(--surface)] text-[var(--text)] shadow-2xl p-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-semibold">Add a bottle</h3>
                    <button class="p-2 rounded-lg hover:bg-black/5" data-addsheet-close aria-label="Close">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>
                <div class="mt-3">
                    <a id="addScanLinkM" href="/scan.php" class="block -mx-4 px-4 py-3 hover:bg-black/5">
                        📷 Scan label
                    </a>
                    <a id="addManualLinkM" href="/add_bottle.php?mode=manual" class="block -mx-4 px-4 py-3 hover:bg-black/5">
                        🔎 Search catalog / manual entry
                    </a>
                </div>
            </div>
        </div>


        <a href="/expert_lists.php"
           class="pb-1 <?php echo $current === 'expert_lists.php' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-[var(--primary-600)]'; ?>">
            Expert Lists
        </a>
        <a href="/blog/"
           class="pb-1 <?php echo str_starts_with($_SERVER['REQUEST_URI'],'/blog') ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-[var(--primary-600)]'; ?>">
            Blog
        </a>
        <a href="/app/"
           class="pb-1 <?php echo str_starts_with($_SERVER['REQUEST_URI'],'/app') ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-700 hover:text-[var(--primary-600)]'; ?>">
            Wine-AI
        </a>
    </div>

        <?php
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $loggedIn    = !empty($_SESSION['user_id']);
        $displayName = $_SESSION['username'] ?? 'User';
        ?>
        <div class="flex space-x-6">
            <?php if ($loggedIn): ?>
                <a href="/account.php" class="inline-flex items-center gap-2 header-link text-[var(--text)] hover:text-[var(--primary-600)]">
                    <!-- person icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M5.121 17.804A9 9 0 1118.88 17.804M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                </a>

                <a href="/logout.php" class="inline-flex items-center gap-2 header-link text-[var(--text)] hover:text-[var(--primary-600)]">
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
        <script>
            (function(){
                const btn = document.getElementById('addNavBtn');
                const dd  = document.getElementById('addDropdown');
                let hideTimer;

                // Anim helpers
                function showDD(){
                    if(!dd) return;
                    dd.classList.remove('invisible','opacity-0','scale-95');
                    btn?.setAttribute('aria-expanded','true');
                }
                function hideDD(){
                    if(!dd) return;
                    dd.classList.add('opacity-0','scale-95');
                    btn?.setAttribute('aria-expanded','false');
                    // let transition finish before hiding pointer events
                    setTimeout(()=> dd.classList.add('invisible'), 120);
                }

                // Click toggles (desktop & touch-safe)
                btn?.addEventListener('click', (e)=>{
                    e.stopPropagation();
                    if (dd.classList.contains('invisible')) showDD(); else hideDD();
                });

                // Hover stability for desktop
                btn?.addEventListener('mouseenter', ()=>{
                    clearTimeout(hideTimer); showDD();
                });
                btn?.addEventListener('mouseleave', ()=>{
                    hideTimer = setTimeout(hideDD, 200);
                });
                dd?.addEventListener('mouseenter', ()=> clearTimeout(hideTimer));
                dd?.addEventListener('mouseleave', ()=> hideTimer = setTimeout(hideDD, 200));

                // Click outside to close
                document.addEventListener('click', (e)=>{
                    if (!dd || dd.classList.contains('invisible')) return;
                    if (!dd.contains(e.target) && e.target !== btn) hideDD();
                });
                // Esc to close
                window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') hideDD(); });
            })();
        </script>

    </nav>
</header>