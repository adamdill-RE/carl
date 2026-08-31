<?php
/**
 * Local development router.
 *
 * Hosting Section 10: mirror the server layout rather than the repository's,
 * so a constraint that will bite on the server bites here first. That means
 * public/ mounted at the app's subpath, application code at a sibling, and a
 * php.ini reproducing production's limits.
 *
 *   php -S 127.0.0.1:8088 -t public dev-router.php -c dev/php.ini
 *
 * The mount point is read from config, not written here, so the base_path
 * rule (hosting Section 5.2) still holds: the value lives in exactly one file.
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli-server') {
    \http_response_code(404);
    return true;
}

// The CLI SAPI hard-codes memory_limit = -1 and applies it AFTER both php.ini
// and -d, so the one limit most likely to bite -- a photo resize against
// production's 128M (hosting Section 4) -- is the one limit the flags cannot
// reproduce. Setting it at runtime is the only way to make it bite locally.
if (\ini_get('memory_limit') === '-1') {
    \ini_set('memory_limit', '128M');
}

$config = require __DIR__ . '/config/app.php';
$base = \rtrim((string) ($config['base_path'] ?? '/'), '/') . '/';

$path = (string) \parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

if ($base !== '/' && $path === \rtrim($base, '/')) {
    \header('Location: ' . $base);
    return true;
}

if ($base !== '/' && !\str_starts_with($path, $base)) {
    \http_response_code(404);
    \header('Content-Type: text/plain; charset=utf-8');
    echo "Carl is mounted at {$base} on this dev server, mirroring the live layout.\n";
    return true;
}

$relative = \substr($path, \strlen($base));

// Real files (assets, sw.js) are served directly, exactly as the .htaccess
// rewrite does on the server. `return false` cannot do it here: the built-in
// server would look for public/<base>/<relative>, and the mount point is not
// a real directory.
if ($relative !== '') {
    $file = \realpath(__DIR__ . '/public/' . $relative);
    $publicRoot = \realpath(__DIR__ . '/public');
    if ($file !== false && $publicRoot !== false
        && \str_starts_with($file, $publicRoot . \DIRECTORY_SEPARATOR) && \is_file($file)) {
        $types = [
            'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'ico' => 'image/x-icon',
            'pdf' => 'application/pdf', 'woff2' => 'font/woff2',
        ];
        $extension = \strtolower(\pathinfo($file, \PATHINFO_EXTENSION));
        \header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
        \header('Content-Length: ' . \filesize($file));
        \readfile($file);
        return true;
    }
}

require __DIR__ . '/public/index.php';
return true;
