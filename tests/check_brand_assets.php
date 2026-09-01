<?php
/**
 * The brand assets, checked against the two things that break them silently.
 *
 * 1. THE CSP. `style-src 'self'` with no `'unsafe-inline'` (hosting Section
 *    8.5), so a `style=""` attribute is refused without an error anybody
 *    would notice -- including one inside an SVG. The mark is inline in a
 *    view, which is markup rather than a resource load and so is allowed, but
 *    that is exactly what makes the trap easy to fall into: paste artwork
 *    from anywhere that uses `style=` and it renders perfectly in the design
 *    tool and wrong in the app. Presentation attributes only.
 *
 *    `<text>` is refused for a different reason: the server has no fonts to
 *    resolve one against, so a wordmark must be paths.
 *
 * 2. THE MANIFEST POINTING AT NOTHING. A missing icon in a web manifest is
 *    not an error on any page; it is an install that quietly has no icon, or
 *    a home-screen launcher showing a default glyph. Nothing reports it, and
 *    nobody looks at a manifest twice.
 *
 * Runs in CI's static job: no database, no network, no browser.
 */

declare(strict_types=1);

$root = \dirname(__DIR__);
$problems = [];

// ---------------------------------------------------------------- SVG ----

/** Inline marks, plus anything shipped as a file. */
$svgs = \array_merge(
    (array) \glob($root . '/app/views/partials/logo*.php'),
    (array) \glob($root . '/public/assets/img/*.svg')
);

foreach ($svgs as $file) {
    $path = (string) $file;
    $label = \str_replace($root . '/', '', $path);
    $source = (string) \file_get_contents($path);

    // A .php partial carries a docblock that may legitimately discuss the
    // very things we are banning, so only the markup is examined.
    if (\str_ends_with($path, '.php')) {
        $at = \strpos($source, '?>');
        $source = $at === false ? $source : \substr($source, $at + 2);
    }

    if (\preg_match('/\sstyle\s*=/i', $source) === 1) {
        $problems[] = $label . ': has a style attribute, which the CSP drops silently';
    }
    if (\stripos($source, '<style') !== false) {
        $problems[] = $label . ': has a <style> block, which the CSP drops silently';
    }
    if (\stripos($source, '<script') !== false) {
        $problems[] = $label . ': has a <script>';
    }
    if (\stripos($source, '<text') !== false) {
        $problems[] = $label . ': has a <text> element; the server has no font to resolve it';
    }
    if (\preg_match('/<svg[^>]*\sxlink:href/i', $source) === 1
        || \preg_match('/(src|href)\s*=\s*"https?:/i', $source) === 1) {
        $problems[] = $label . ': references something off-origin';
    }
    if (\preg_match('/<svg[^>]*viewBox=/i', $source) !== 1) {
        $problems[] = $label . ': has no viewBox, so it cannot be sized by CSS';
    }
}

// ----------------------------------------------------------- manifest ----

$manifestPath = $root . '/public/manifest.webmanifest';
if (!\is_file($manifestPath)) {
    $problems[] = 'public/manifest.webmanifest is missing';
} else {
    $manifest = \json_decode((string) \file_get_contents($manifestPath), true);
    if (!\is_array($manifest)) {
        $problems[] = 'public/manifest.webmanifest is not valid JSON';
    } else {
        foreach (['name', 'short_name', 'start_url', 'scope', 'display',
                  'theme_color', 'background_color', 'icons'] as $key) {
            if (!isset($manifest[$key])) {
                $problems[] = 'manifest is missing "' . $key . '"';
            }
        }

        $purposes = [];
        foreach ((array) ($manifest['icons'] ?? []) as $icon) {
            $src = (string) ($icon['src'] ?? '');
            $purposes[(string) ($icon['purpose'] ?? '')] = true;

            // Every URL in the manifest is resolved against the manifest's own
            // location, which is what lets this file know nothing about
            // base_path (hosting Section 5.1). So a leading slash is a bug:
            // it would resolve to the domain root and miss /carl/ entirely.
            if (\str_starts_with($src, '/') || \str_contains($src, '://')) {
                $problems[] = 'manifest icon "' . $src . '" is not relative to the manifest';
                continue;
            }
            if (!\is_file($root . '/public/' . $src)) {
                $problems[] = 'manifest icon "' . $src . '" does not exist';
            }
        }

        // A manifest with only maskable icons gets the maskable one used as
        // the plain icon too, and its safe-area padding then reads as a small
        // mark adrift in a coloured square.
        foreach (['any', 'maskable'] as $purpose) {
            if (!isset($purposes[$purpose])) {
                $problems[] = 'manifest has no icon with purpose "' . $purpose . '"';
            }
        }

        foreach (['start_url', 'scope'] as $key) {
            $value = (string) ($manifest[$key] ?? '');
            if (\str_starts_with($value, '/')) {
                $problems[] = $key . ' "' . $value . '" is absolute; it must be relative to the manifest';
            }
        }
    }
}

// ------------------------------------------------------------- tokens ----

$tokens = (string) @\file_get_contents($root . '/public/assets/css/tokens.css');
foreach (['--carl-qr-ink', '--carl-qr-paper', 'DO NOT THEME'] as $needle) {
    if (!\str_contains($tokens, $needle)) {
        $problems[] = 'tokens.css no longer contains "' . $needle . '" (QR-TAGS-SPEC Section 4.2)';
    }
}

// ------------------------------------------------------------- report ----

if ($problems !== []) {
    \fwrite(\STDERR, "Brand assets:\n");
    foreach ($problems as $problem) {
        \fwrite(\STDERR, '  ' . $problem . "\n");
    }
    exit(1);
}

\printf(
    "brand assets ok: %d SVG source(s), manifest icons all present and relative\n",
    \count($svgs)
);
