<?php // partials/footer.php ?>
<footer class="bg-[var(--surface)] border-t border-[var(--border)] mt-12">
    <div class="max-w-6xl mx-auto px-4 py-8 grid gap-6 md:grid-cols-3 text-[var(--text)]">

        <!-- Branding -->
        <div>
            <h2 class="font-semibold text-lg">WineCellarHub</h2>
            <p class="text-sm text-[var(--muted)] mt-2">
                Your cellar. Organized. Smart.
            </p>
        </div>

        <!-- Quick links -->
        <div>
            <h3 class="font-semibold text-sm mb-2">Quick Links</h3>
            <ul class="space-y-1 text-sm">
                <li><a href="/account.php" class="hover:underline">Account</a></li>
                <li><a href="/inventory.php" class="hover:underline">Inventory</a></li>
                <li><a href="/expert_lists.php" class="hover:underline">Expert Lists</a></li>
                <li><a href="/add_bottle.php" class="hover:underline">Add Bottle</a></li>
            </ul>
        </div>

        <!-- Legal & Contact -->
        <div>
            <h3 class="font-semibold text-sm mb-2">Info</h3>
            <ul class="space-y-1 text-sm">
                <li><a href="/privacy.php" class="hover:underline">Privacy Policy</a></li>
                <li><a href="/terms.php" class="hover:underline">Terms of Service</a></li>
                <li>
                    <a href="mailto:admin@winecellarhub.com" class="hover:underline">
                        admin@winecellarhub.com
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Copyright -->
    <div class="border-t border-[var(--border)] mt-6 py-4 text-center text-xs text-[var(--muted)]">
        © <?= date('Y') ?> WineCellarHub. All rights reserved.
    </div>
</footer>
