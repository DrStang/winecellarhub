<?php
// Absolute path to the blog directory
define('BLOG_ROOT', __DIR__);
define('POSTS_DIR', BLOG_ROOT . '/posts');

// Base URL of your site (no trailing slash)
$BASE_URL = getenv('BASE_URL') ?: 'https://winecellarhub.com';

// Blog base path relative to site root
define('BLOG_BASE', '/blog');

// Site name
define('SITE_NAME', 'WineCellarHub Blog');
