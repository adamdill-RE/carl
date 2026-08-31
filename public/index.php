<?php
/**
 * Front controller. The ONLY file the web can reach that is not an asset.
 *
 * Hosting Section 5.1: the application root is found by probing, so this same
 * file works locally (repo layout) and on the server (public_html/carl beside
 * a sibling carl-app/) with nothing to configure.
 */

declare(strict_types=1);

$candidates = [];

$override = \getenv('CARL_APP_ROOT');
if (\is_string($override) && $override !== '') {
    $candidates[] = $override;
}
$candidates[] = \dirname(__DIR__);                   // <repo>/            (local)
$candidates[] = \dirname(__DIR__, 2) . '/carl-app';  // ~/carl-app/        (server)
$candidates[] = \dirname(__DIR__, 3) . '/carl-app';  // one level deeper, just in case

$root = null;
foreach ($candidates as $candidate) {
    if (\is_file($candidate . '/app/bootstrap.php')) {
        $root = $candidate;
        break;
    }
}

if ($root === null) {
    \http_response_code(500);
    \header('Content-Type: text/plain; charset=utf-8');
    echo "Carl cannot find its application directory.\n\n";
    echo "Looked for app/bootstrap.php in:\n";
    foreach ($candidates as $candidate) {
        echo '  ' . $candidate . "\n";
    }
    echo "\nOn the server the application code belongs in /home/reshiftmanager/carl-app,\n";
    echo "outside public_html. Check that the last deploy finished.\n";
    exit(1);
}

/** @var Carl\Core\App $app */
$app = require $root . '/app/bootstrap.php';

$request = Carl\Core\Request::fromGlobals($app->basePath());
$app->send($app->handle($request));
