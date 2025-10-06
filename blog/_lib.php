<?php
require_once __DIR__ . '/_config.php';

/**
 * Load all posts (markdown files) and return structured array.
 */
function load_all_posts(): array {
    $files = glob(POSTS_DIR . '/*.md');
    $posts = [];
    foreach ($files as $path) {
        $post = parse_post_file($path);
        if ($post) $posts[] = $post;
    }
    // Newest first
    usort($posts, fn($a,$b) => strtotime($b['date']) <=> strtotime($a['date']));
    return $posts;
}

/**
 * Parse a single markdown file with YAML front matter.
 */
function parse_post_file(string $path): ?array {
    $raw = file_get_contents($path);
    if ($raw === false) return null;

    $front = [];
    $body = $raw;

    if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $m)) {
        $front = parse_yaml_like($m[1]);
        $body  = $m[2];
    }

    // fallback from filename
    $fn = basename($path, '.md');
    // expected format: YYYY-MM-DD-slug
    if (preg_match('/^\d{4}-\d{2}-\d{2}-(.+)$/', $fn, $mm)) {
        $slug = $front['slug'] ?? $mm[1];
        $date = $front['date'] ?? substr($fn, 0, 10);
    } else {
        $slug = $front['slug'] ?? $fn;
        $date = $front['date'] ?? date('Y-m-d');
    }

    $title = $front['title'] ?? humanize_slug($slug);
    $desc  = $front['description'] ?? '';
    $cover = $front['cover'] ?? null;

    $html  = markdown_to_html($body);
    $excerpt = $desc ?: trim(strip_tags(excerpt_from_html($html, 200)));

    return [
        'slug' => $slug,
        'date' => $date,
        'title' => $title,
        'description' => $desc,
        'cover' => $cover,
        'html' => $html,
        'excerpt' => $excerpt,
        'path' => $path,
        'url'  => BLOG_BASE . '/' . $slug
    ];
}

/** Minimal YAML parser (key: value per line). */
function parse_yaml_like(string $text): array {
    $out = [];
    foreach (preg_split("/\R/", $text) as $line) {
        if (!trim($line) || str_starts_with(trim($line), '#')) continue;
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $out[trim($k)] = trim(trim($v), " \t\"'");
        }
    }
    return $out;
}

function humanize_slug(string $slug): string {
    $s = preg_replace('/[-_]+/',' ', $slug);
    return mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
}

function excerpt_from_html(string $html, int $len=200): string {
    $text = preg_replace('/\s+/', ' ', strip_tags($html));
    if (mb_strlen($text) <= $len) return $text;
    return mb_substr($text, 0, $len-1).'…';
}

/**
 * Very small Markdown → HTML (headings, bold/italic, links, lists, paragraphs)
 * This is intentionally simple; swap for Parsedown later if you want richer MD.
 */
function markdown_to_html(string $md): string {
    $lines = preg_split("/\R/", $md);
    $html = '';
    $inList = false;

    foreach ($lines as $line) {
        $trim = rtrim($line);

        // Headings
        if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
            $level = strlen($m[1]);
            $content = inline_md($m[2]);
            if ($inList) { $html .= "</ul>"; $inList=false; }
            $html .= "<h$level>$content</h$level>";
            continue;
        }

        // Unordered list
        if (preg_match('/^\s*[-*+]\s+(.*)$/', $trim, $m)) {
            if (!$inList) { $html .= "<ul>"; $inList = true; }
            $html .= "<li>".inline_md($m[1])."</li>";
            continue;
        } else {
            if ($inList && $trim==='') { $html .= "</ul>"; $inList=false; }
        }

        // Paragraph
        if ($trim === '') {
            $html .= "";
        } else {
            $html .= "<p>".inline_md($trim)."</p>";
        }
    }
    if ($inList) $html .= "</ul>";
    return $html;
}

function inline_md(string $s): string {
    // bold **text**
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    // italic *text*
    $s = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $s);
    // links [text](url)
    $s = preg_replace('/\[(.+?)\]\((https?:\/\/[^)]+)\)/', '<a href="$2">$1</a>', $s);
    return htmlspecialchars_decode($s, ENT_QUOTES);
}

