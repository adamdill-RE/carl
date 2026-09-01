<?php
/**
 * Measures the palette against every foreground/background combination the
 * application actually renders, and writes `reference/contrast-baseline.md`
 * into the handoff package.
 *
 * The same arithmetic runs in `validate/contrast-check.html`, which is what
 * Claude Design uses on the palette they are building. Two implementations of
 * one formula is a duplication worth having: the designer cannot run PHP and
 * this repository should not depend on a browser. They are checked against
 * each other -- the numbers below are the ones the HTML validator produces.
 *
 * Handoff Section 13.5. Run through `design/build-handoff-zip.sh`, never by
 * hand -- the point is that the baseline cannot go stale while `tokens.css`
 * changes underneath it.
 *
 * Usage: php design/handoff/contrast.php <path-to-tokens.css>
 */

declare(strict_types=1);

/** Every pair the app renders: [foreground, background, where, minimum, advisory?] */
const PAIRS = [
    ['--carl-text', '--carl-bg', 'Body text on the page', 4.5],
    ['--carl-text', '--carl-surface', 'Text on a card', 4.5],
    ['--carl-text-muted', '--carl-surface', 'Help text, meta lines, citations', 4.5],
    ['--carl-text-muted', '--carl-bg', 'Muted text on the page', 4.5],
    ['--carl-text-inverse', '--carl-primary', 'Topbar brand + primary button label', 4.5],
    ['--carl-primary', '--carl-surface', '`.btn-link`, timeline dot', 4.5],
    ['--carl-primary-dark', '--carl-surface', 'In-content links', 4.5],
    ['--carl-primary-dark', '--carl-primary-soft', '`.badge`', 4.5],
    ['--carl-text-muted', '--carl-surface-sunk', '`.badge-muted`, `.tier-skip`', 4.5],
    ['--carl-warn', '--carl-warn-soft', '`.tier-water`, `.notice-warn`', 4.5],
    ['--carl-info', '--carl-info-soft', '`.tier-likely`, `.notice-info`', 4.5],
    ['--carl-ok', '--carl-ok-soft', '`.notice-ok`', 4.5],
    ['--carl-error', '--carl-error-soft', '`.notice-error`', 4.5],
    ['--carl-verified', '--carl-surface', 'Confidence: verified', 4.5],
    ['--carl-approx', '--carl-surface', 'Confidence: approximate', 4.5],
    ['--carl-generic', '--carl-surface', 'Confidence: traditional', 4.5],
    ['--carl-accent', '--carl-surface', 'Focus ring on a card', 3.0],
    ['--carl-accent', '--carl-bg', 'Focus ring on the page', 3.0],
    ['--carl-border-strong', '--carl-surface', '**Input / select / textarea border**', 3.0],
    ['--carl-border', '--carl-surface', 'Card border, table rule (decorative)', 3.0, true],
];

$tokensPath = $argv[1] ?? (\dirname(__DIR__, 2) . '/public/assets/css/tokens.css');
if (!\is_file($tokensPath)) {
    \fwrite(\STDERR, "No tokens.css at {$tokensPath}\n");
    exit(1);
}

// The exact regex from Carl\Support\Tokens: if a value does not match this,
// the PDF writer silently falls back to grey.
\preg_match_all(
    '/(--carl-[a-z0-9-]+)\s*:\s*#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})\s*;/',
    (string) \file_get_contents($tokensPath),
    $matches,
    \PREG_SET_ORDER
);

$colours = [];
foreach ($matches as $match) {
    $hex = $match[2];
    if (\strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $colours[$match[1]] = [
        (int) \hexdec(\substr($hex, 0, 2)),
        (int) \hexdec(\substr($hex, 2, 2)),
        (int) \hexdec(\substr($hex, 4, 2)),
    ];
}

/** @param array{0:int,1:int,2:int} $rgb */
function luminance(array $rgb): float
{
    $channels = [];
    foreach ($rgb as $value) {
        $v = $value / 255;
        $channels[] = $v <= 0.03928 ? $v / 12.92 : \pow(($v + 0.055) / 1.055, 2.4);
    }
    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * @param array{0:int,1:int,2:int} $a
 * @param array{0:int,1:int,2:int} $b
 */
function contrast(array $a, array $b): float
{
    $l1 = luminance($a);
    $l2 = luminance($b);
    if ($l1 < $l2) {
        [$l1, $l2] = [$l2, $l1];
    }
    return ($l1 + 0.05) / ($l2 + 0.05);
}

/** @param array{0:int,1:int,2:int} $rgb */
function hex(array $rgb): string
{
    return \sprintf('#%02x%02x%02x', ...$rgb);
}

$rows = [];
$failures = 0;
foreach (PAIRS as $pair) {
    [$fg, $bg, $where, $need] = $pair;
    $advisory = $pair[4] ?? false;
    if (!isset($colours[$fg], $colours[$bg])) {
        \fwrite(\STDERR, "Missing token for {$fg} / {$bg}\n");
        exit(1);
    }
    $ratio = contrast($colours[$fg], $colours[$bg]);
    if ($ratio >= $need) {
        $verdict = 'pass';
    } elseif ($advisory) {
        $verdict = '_advisory_';
    } else {
        $verdict = '**FAIL**';
        $failures++;
    }
    $rows[] = \sprintf(
        '| `%s` %s | `%s` %s | %s | %.2f:1 | %.1f:1 | %s |',
        $fg,
        hex($colours[$fg]),
        $bg,
        hex($colours[$bg]),
        $where,
        $ratio,
        $need,
        $verdict
    );
}

echo <<<MD
# Contrast baseline — the palette as it stands today, measured

Computed with the WCAG 2.1 relative-luminance formula against the actual
foreground/background combinations the application renders. Reproduce it on
your own palette with `validate/contrast-check.html`, which runs the same
arithmetic.

Match or beat these.

| Foreground | Background | Where it renders | Ratio | Needs | |
|---|---|---|---:|---:|---|

MD;

echo \implode("\n", $rows), "\n";

echo <<<'MD'

## What "advisory" means

`--carl-border` is decorative: card edges, table rules, the timeline spine.
WCAG 2.1 SC 1.4.11 does not require 3:1 for a purely decorative boundary, and
forcing it there makes every card on a dense screen shout. The validator
reports it but does not block on it.

`--carl-border-strong` is different. It is the visual boundary of every text
input, select and textarea, which SC 1.4.11 *does* cover — so if it reads
**FAIL** above, that is a real defect and the palette should fix it.

## Notes on the method

- Ratios use `(L1 + 0.05) / (L2 + 0.05)` with sRGB relative luminance.
- 4.5:1 is AA for normal text; 3:1 is AA for UI components and large text.
- Carl is used outdoors in direct sun, so treat AA as the floor rather than the
  goal. The placeholder mostly sits between 5:1 and 9:1 and that has worked well
  in the field.
- Chart data series (if you add `--carl-chart-*`) are measured against
  `--carl-surface` at 3:1, and additionally need to be distinguishable **from
  each other** under deuteranopia and protanopia — which contrast arithmetic
  does not test. Check that separately.

MD;

\fwrite(\STDERR, $failures > 0
    ? "{$failures} required pair(s) below target.\n"
    : "All required pairs pass.\n");
