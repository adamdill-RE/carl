<?php
/**
 * Carl bootstrap. Included by public/index.php and by every bin/ script.
 *
 * Returns a fully wired Carl\Core\App. Nothing here emits output.
 */

declare(strict_types=1);

if (\PHP_VERSION_ID < 80200) {
    \header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "Carl requires PHP 8.2 or newer; this is " . \PHP_VERSION . ".\n";
    exit(1);
}

// Server and database are UTC; only display converts (hosting Section 4).
\date_default_timezone_set('UTC');

\define('CARL_ROOT', \dirname(__DIR__));
\define('CARL_START', \microtime(true));

/**
 * Own autoloader over a PSR-4-ish layout. No Composer on this host
 * (hosting Section 3).
 */
\spl_autoload_register(static function (string $class): void {
    $prefix = 'Carl\\';
    if (\strncmp($class, $prefix, \strlen($prefix)) !== 0) {
        return;
    }
    $relative = \substr($class, \strlen($prefix));
    $path = CARL_ROOT . '/app/src/' . \str_replace('\\', '/', $relative) . '.php';
    if (\is_file($path)) {
        require $path;
    }
});

/**
 * Hosting Section 6.4: config/local.php is hand-edited on a server with no
 * shell to lint it. A missing comma there blanks every page with a 500 and
 * the administrator cannot read the log. Catch that one class of error and
 * say which file and which line to fix -- never echoing a value from it.
 */
try {
    $config = Carl\Core\Config::load(CARL_ROOT);
} catch (\ParseError $e) {
    $file = \basename($e->getFile());
    \header('Content-Type: text/plain; charset=utf-8', true, 500);
    echo "Carl cannot start: configuration file has a PHP syntax error.\n\n";
    echo "  File: config/{$file}\n";
    echo "  Line: {$e->getLine()}\n";
    echo "  Problem: {$e->getMessage()}\n\n";
    echo "Edit that line in cPanel File Manager and reload. A missing comma at\n";
    echo "the end of a line is the usual cause. No values from the file are\n";
    echo "shown here on purpose.\n";
    exit(1);
}

return new Carl\Core\App($config, CARL_ROOT);
