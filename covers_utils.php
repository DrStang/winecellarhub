<?php
// Put these helper functions somewhere included by expert_lists.php (e.g., a small utils file)

// Priority if multiple files exist (rare but possible: 123.jpg AND 123.png)
const COVERS_EXT_PRIORITY = ['webp', 'jpg', 'jpeg', 'png'];

/**
 * Build (or fetch) a manifest mapping wine_id => ext
 * Scans once, caches in APCu for 5 minutes. Also memoized per-request in a static var.
 */
function covers_manifest(string $coversRel = '/covers', int $ttl = 300): array {
    static $LOCAL_CACHE = null;
    if ($LOCAL_CACHE !== null) return $LOCAL_CACHE;

    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__FILE__), '/');
    $dir     = $docroot . $coversRel;

    // try APCu first
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch('covers_manifest:v2', $ok);
        if ($ok && is_array($cached)) {
            $LOCAL_CACHE = $cached;
            return $LOCAL_CACHE;
        }
    }

    $map = [];
    if (is_dir($dir)) {
        // Fast scan
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            // Expect filenames like 12345.jpg / 12345.png / 12345.webp
            if (!preg_match('/^(\d+)\.(jpe?g|png|webp)$/i', $f, $m)) continue;
            $id  = (int)$m[1];
            $ext = strtolower($m[2]);

            // If multiple files for same ID, keep highest-priority ext
            if (!isset($map[$id])) {
                $map[$id] = $ext;
            } else {
                $existing = $map[$id];
                if (array_search($ext, COVERS_EXT_PRIORITY, true) < array_search($existing, COVERS_EXT_PRIORITY, true)) {
                    $map[$id] = $ext;
                }
            }
        }
    }

    if (function_exists('apcu_store')) {
        apcu_store('covers_manifest:v2', $map, $ttl);
    }
    $LOCAL_CACHE = $map;
    return $LOCAL_CACHE;
}

/** Force-refresh endpoint/CLI can call this if you want an immediate rebuild. */
function covers_manifest_refresh(string $coversRel = '/covers'): array {
    if (function_exists('apcu_delete')) apcu_delete('covers_manifest:v2');
    // next call will rebuild
    return covers_manifest($coversRel, 300);
}
