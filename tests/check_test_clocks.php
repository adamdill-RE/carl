<?php
/**
 * A test whose fixture lives in America/Chicago must not take its "today"
 * from the server clock.
 *
 * Handoff Section 6: event dates are the user's local calendar day, and
 * `Carl\Support\Clock::todayFor()` is the only thing that computes one.
 * `gmdate('Y-m-d')` is the server's day. Between UTC midnight and local
 * midnight -- 00:00 to 05:00 UTC for Chicago, six hours every night -- the
 * two are DIFFERENT DAYS.
 *
 * A test that mixes them is green all afternoon and red all night. That is
 * worse than a test that is simply wrong: it looks like a flake, it gets
 * re-run rather than read, and the timezone boundary it was meant to protect
 * is the one case it never exercises. Four assertions were lost to this
 * (commit c97dee9) and a fifth was still latent in the calendar test.
 *
 * So: a file that onboards with ZIP 76692 may not assign `$today` from
 * `gmdate()`. If a particular one genuinely wants the server's day -- a
 * fixture whose location really is UTC, or a value only ever used to backdate
 * relative to itself -- say so on the line and say why:
 *
 *     $today = \gmdate('Y-m-d');  // utc-ok: only backdates, never compared
 *
 * The marker is the point. It makes the choice explicit and reviewable
 * instead of accidental.
 *
 * Runs in CI's static job: no database, no network.
 */

declare(strict_types=1);

$root = \dirname(__DIR__);
$caseDir = $root . '/tests/cases';

/** The ZIP the fixtures onboard with; it resolves to Hill County, Texas. */
const CHICAGO_ZIP = '76692';

/** `$today = gmdate('Y-m-d')` / `date('Y-m-d')`, however it is escaped. */
const SERVER_TODAY = '/\$today\s*=\s*\\\\?(gm)?date\s*\(/';

$problems = [];
$checked = 0;

foreach ((array) \glob($caseDir . '/*.php') as $file) {
    $path = (string) $file;
    $source = (string) \file_get_contents($path);

    // Only files whose fixtures are actually in a non-UTC zone can hit this.
    if (!\str_contains($source, CHICAGO_ZIP)) {
        continue;
    }
    $checked++;

    foreach (\explode("\n", $source) as $index => $line) {
        if (\preg_match(SERVER_TODAY, $line) !== 1) {
            continue;
        }
        if (\str_contains($line, 'utc-ok:')) {
            continue;
        }
        $problems[] = \sprintf(
            '  %s:%d
    %s',
            'tests/cases/' . \basename($path),
            $index + 1,
            \trim($line)
        );
    }
}

if ($problems !== []) {
    \fwrite(\STDERR, "A fixture in America/Chicago is taking its \"today\" from the server clock.\n");
    \fwrite(\STDERR, "This is green all afternoon and red from 00:00 to 05:00 UTC.\n\n");
    \fwrite(\STDERR, \implode("\n", $problems) . "\n\n");
    \fwrite(\STDERR, "Use the user's own day:\n");
    \fwrite(\STDERR, "    \$today = \$app->clock()->todayFor(\n");
    \fwrite(\STDERR, "        (string) \$db->value('SELECT timezone FROM `user` WHERE id = :i', ['i' => \$owner['id']])\n");
    \fwrite(\STDERR, "    );\n\n");
    \fwrite(\STDERR, "Or, if the server's day is genuinely what this one wants, say why on the line:\n");
    \fwrite(\STDERR, "    \$today = \\gmdate('Y-m-d');  // utc-ok: <reason>\n");
    exit(1);
}

\printf("test clocks ok: %d Chicago-fixture case file(s), none taking today from the server\n", $checked);
