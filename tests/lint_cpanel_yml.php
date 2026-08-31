<?php
/**
 * .cpanel.yml is parsed by the host, and a file it rejects DISABLES
 * DEPLOYMENT ENTIRELY rather than failing loudly (hosting Section 6.2). So
 * the rules that bite are asserted here, in CI, where the failure is visible.
 */

declare(strict_types=1);

$path = \dirname(__DIR__) . '/.cpanel.yml';
$errors = [];

if (!\is_file($path)) {
    \fwrite(\STDERR, ".cpanel.yml is missing; deployment would do nothing.\n");
    exit(1);
}

$raw = (string) \file_get_contents($path);
$lines = \explode("\n", \str_replace("\r\n", "\n", $raw));

foreach ($lines as $i => $line) {
    $number = $i + 1;

    // ASCII only.
    if (\preg_match('/[^\x00-\x7F]/', $line) === 1) {
        $errors[] = "line {$number}: non-ASCII character";
    }
    // No tab characters.
    if (\str_contains($line, "\t")) {
        $errors[] = "line {$number}: tab character";
    }
    // No braces anywhere in a task -- they are YAML flow indicators, which
    // rules out find -exec ... {} \;
    if (\str_contains($line, '{') || \str_contains($line, '}')) {
        $errors[] = "line {$number}: brace";
    }
}

// Tasks must be plain strings: a list of "- " entries, nothing nested.
$inTasks = false;
$taskCount = 0;
foreach ($lines as $i => $line) {
    $number = $i + 1;
    if (\preg_match('/^\s*tasks:\s*$/', $line) === 1) {
        $inTasks = true;
        continue;
    }
    if (!$inTasks) {
        continue;
    }
    if (\trim($line) === '') {
        continue;
    }
    if (\preg_match('/^\s+-\s+\S/', $line) !== 1) {
        $errors[] = "line {$number}: task list entry is not a plain '- string'";
        continue;
    }
    if (\preg_match('/^\s+-\s+[\[\{]/', $line) === 1) {
        $errors[] = "line {$number}: task is a flow collection, not a plain string";
    }
    $taskCount++;
}

if ($taskCount === 0) {
    $errors[] = 'no deployment tasks found';
}

// The deploy must copy config/app.php, and must never copy over the
// credentials file. Hosting Section 6.2 step 6 does require *tightening* it
// to 0600 when it exists, so chmod and test lines are expected -- it is a cp
// or an rm touching local.php that would destroy the one file the deploy does
// not own (hosting Section 6.4).
foreach ($lines as $i => $line) {
    if (!\str_contains($line, 'local.php')) {
        continue;
    }
    if (\preg_match('/\b(cp|mv|rm|install|tee)\b/', $line) === 1) {
        $errors[] = 'line ' . ($i + 1) . ': the deploy copies or removes config/local.php';
    }
}
if (!\str_contains($raw, 'config/app.php')) {
    $errors[] = 'the deploy never copies config/app.php';
}
if (\preg_match('/chmod -R[^\n]*\$APPDIR\/config\b/', $raw) === 1) {
    // Section 6.2 step 6: config/ is handled NON-recursively, because a
    // recursive chmod would loosen the credentials file to 0644.
    $errors[] = 'config/ is chmod-ed recursively; that would loosen config/local.php';
}
if (\preg_match('/chmod 0600[^\n]*local\.php/', $raw) !== 1) {
    $errors[] = 'the deploy never tightens config/local.php to 0600 (hosting Section 6.2 step 6)';
}
// A failing task fails the whole deployment, and on a first deploy local.php
// does not exist yet -- so the chmod must be guarded.
if (\preg_match('/local\.php[^\n]*\|\|/', $raw) !== 1
    && \preg_match('/test -f[^\n]*local\.php/', $raw) !== 1) {
    $errors[] = 'the config/local.php chmod is not guarded; it would fail the first deploy';
}

// A file deleted in git must be deleted on the server, so the code
// directories are removed before the copy (hosting Section 6.2 step 2).
foreach (['app', 'db', 'bin'] as $directory) {
    if (\preg_match('/rm -rf \$APPDIR\/' . $directory . '\b/', $raw) !== 1) {
        $errors[] = "the deploy does not rm -rf {$directory} before copying it";
    }
}

// Every path the deploy copies must exist in a clean checkout. Git does not
// track empty directories, so a source directory holding only gitignored
// files is present locally and absent on the server -- where the cp fails,
// and a failing task fails the WHOLE deployment (hosting Section 6.2).
$tracked = [];
$listing = [];
\exec('git -C ' . \escapeshellarg(\dirname(__DIR__)) . ' ls-files', $listing);
foreach ($listing as $file) {
    $tracked[$file] = true;
    $parts = \explode('/', $file);
    \array_pop($parts);
    $prefix = '';
    foreach ($parts as $part) {
        $prefix = $prefix === '' ? $part : $prefix . '/' . $part;
        $tracked[$prefix] = true;
    }
}

\preg_match_all('/cp -R ([^\s]+)/', $raw, $copies);
foreach ($copies[1] as $source) {
    $path = \rtrim(\str_replace('/.', '', $source), '/');
    if ($path === '' || \str_starts_with($path, '$')) {
        continue;
    }
    if (!isset($tracked[$path])) {
        $errors[] = 'the deploy copies "' . $source . '", which no tracked file lives under; '
            . 'it will not exist in a fresh clone and the task will fail the deployment';
    }
}

if ($errors !== []) {
    \fwrite(\STDERR, ".cpanel.yml would be rejected by the host parser:\n");
    foreach ($errors as $error) {
        \fwrite(\STDERR, '  - ' . $error . "\n");
    }
    exit(1);
}

\printf(".cpanel.yml ok: %d tasks, ASCII, no tabs, no braces, plain strings\n", $taskCount);
