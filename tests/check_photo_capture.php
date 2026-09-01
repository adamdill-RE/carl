<?php
/**
 * The camera and the camera roll, checked against the one way this breaks
 * without telling anybody.
 *
 * `capture="environment"` reads like a capability flag and is not one. It is
 * an instruction: SKIP the file picker and open the camera. An input carrying
 * it offers the camera INSTEAD of the roll on a phone, and on a desktop it is
 * ignored entirely -- so the attribute's whole effect is invisible on the
 * machine anybody develops on, and its cost is paid in a field by somebody
 * who cannot find their photos any more. That is why there are two inputs,
 * and why the split is worth a test rather than a comment:
 *
 *   - the ROLL input is `multiple` and must never gain `capture`
 *   - the CAMERA input carries `capture` and must never gain `multiple`,
 *     because a capture returns one photo and browsers that see both
 *     attributes disagree about which one wins
 *
 * Merging them back into one input is a one-line edit that looks like tidying
 * up, passes every other test in the repository, and renders perfectly on a
 * laptop.
 *
 * The FOCUS RING is here for the same reason. The inputs are `.sr-only` so
 * their labels can be the buttons, which puts the ring on an element a pixel
 * wide and off the page; `carl.css` borrows it onto the label with an
 * adjacent-sibling rule, so the label has to STAY adjacent. Reorder the two
 * and the control is still perfectly usable with a mouse and has no visible
 * focus at all with a keyboard.
 *
 * Runs in CI's static job: no database, no network, no browser.
 */

declare(strict_types=1);

$root = \dirname(__DIR__);
$problems = [];

$partial = $root . '/app/views/partials/photo_uploader.php';
if (!\is_file($partial)) {
    \fwrite(\STDERR, "app/views/partials/photo_uploader.php is missing.\n");
    exit(1);
}

$source = (string) \file_get_contents($partial);

// The docblock above that partial discusses `capture` and `multiple` at
// length, which is exactly the trap PHASE-10-HANDOFF Section 7 records: a
// naive grep over a .php partial matches the prose describing the thing being
// checked. Only the markup is examined.
$at = \strpos($source, '?>');
$markup = $at === false ? $source : \substr($source, $at + 2);

// ------------------------------------------------------- the two inputs ----

\preg_match_all('/<input\b[^>]*type="file"[^>]*>/i', $markup, $matches);
$fileInputs = $matches[0];

if (\count($fileInputs) !== 2) {
    $problems[] = \sprintf(
        'photo_uploader.php declares %d file input(s); it needs exactly two, '
        . 'one for the camera roll and one for the camera.',
        \count($fileInputs)
    );
}

$roll = null;
$camera = null;

foreach ($fileInputs as $tag) {
    if (\preg_match('/\bcapture\b/i', $tag) === 1) {
        $camera = $tag;
    } else {
        $roll = $tag;
    }
}

if ($camera === null) {
    $problems[] = 'No input carries `capture`, so there is no way to take a photo: '
        . 'the camera roll is the only source again.';
}
if ($roll === null) {
    $problems[] = 'Every file input carries `capture`, so the camera roll is unreachable '
        . 'on a phone -- `capture` replaces the picker, it does not add to it.';
}

if ($camera !== null) {
    if (\preg_match('/capture="environment"/i', $camera) !== 1) {
        $problems[] = 'The camera input must be capture="environment" (the rear camera). '
            . 'A bare `capture` or "user" points a plant photo at the selfie lens.';
    }
    if (\preg_match('/\bmultiple\b/i', $camera) === 1) {
        $problems[] = 'The camera input carries `multiple`. A capture returns one photo, '
            . 'and browsers seeing both attributes disagree about which one wins.';
    }
}

if ($roll !== null && \preg_match('/\bmultiple\b/i', $roll) !== 1) {
    $problems[] = 'The camera-roll input has lost `multiple`, so a morning of photos '
        . 'has to be attached one at a time.';
}

foreach (['roll' => $roll, 'camera' => $camera] as $which => $tag) {
    if ($tag === null) {
        continue;
    }
    if (\preg_match('/accept="image\/\*"/i', $tag) !== 1) {
        $problems[] = 'The ' . $which . ' input has lost accept="image/*", which is what '
            . 'keeps a file browser on photos.';
    }
}

// ------------------------------------- every input is labelled, in order ----

// The labels ARE the controls -- the inputs are .sr-only -- so an input
// without one is a button nobody can see or press, and a label that is not
// the input's next sibling loses the focus ring in carl.css.
foreach ($fileInputs as $tag) {
    if (\preg_match('/\bid="([^"]+)"/', $tag, $m) !== 1) {
        $problems[] = 'A file input has no id, so no label can point at it: ' . $tag;
        continue;
    }
    $id = $m[1];

    $quoted = \preg_quote($id, '/');
    if (\preg_match('/<label\b[^>]*\bfor="' . $quoted . '"/i', $markup) !== 1) {
        $problems[] = 'Nothing labels #' . $id . '. The inputs are .sr-only, so the label '
            . 'is the only visible control.';
        continue;
    }

    // Adjacent, and in that order: carl.css uses `input:focus-visible + label`.
    $adjacent = '/' . \preg_quote($tag, '/') . '\s*<label\b[^>]*\bfor="' . $quoted . '"/i';
    if (\preg_match($adjacent, $markup) !== 1) {
        $problems[] = 'The label for #' . $id . ' is not the input\'s next sibling. '
            . 'carl.css borrows the focus ring with `input:focus-visible + label`, '
            . 'so reordering them leaves the control with no visible focus.';
    }
}

$css = (string) \file_get_contents($root . '/public/assets/css/carl.css');
if (!\str_contains($css, '.photo-actions input:focus-visible + label')) {
    $problems[] = 'carl.css no longer borrows the focus ring onto the label. '
        . 'The inputs are .sr-only, so without it keyboard focus is invisible.';
}

// ------------------------------------- one partial, every upload surface ----

// "Ensure it affects all areas we allow uploads of photos" is only true for
// as long as there is one place that declares a photo input. A second one
// added anywhere else gets neither the camera nor any of this file's checks.
// Recursive rather than a glob: `app/views/*` and `app/views/*/*` are both
// real, and a shallow pattern that silently skips eight files is the same
// class of mistake this whole file exists to catch.
$views = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/views', FilesystemIterator::SKIP_DOTS)
);

$strays = [];
$includes = 0;
$scanned = 0;

foreach ($views as $entry) {
    /** @var SplFileInfo $entry */
    if (!$entry->isFile() || $entry->getExtension() !== 'php') {
        continue;
    }
    $scanned++;
    $path = (string) $entry->getPathname();
    $body = (string) \file_get_contents($path);

    if (\str_contains($body, 'partials/photo_uploader')) {
        $includes++;
    }
    if ($path === $partial) {
        continue;
    }

    $mark = \strpos($body, '?>');
    $body = $mark === false ? $body : \substr($body, $mark + 2);

    if (\preg_match('/<input\b[^>]*accept="image\/\*"/i', $body) === 1) {
        $strays[] = \str_replace($root . '/', '', $path);
    }
}

if ($strays !== []) {
    $problems[] = 'These views declare a photo input of their own instead of including '
        . 'partials/photo_uploader.php, so they get no camera button: '
        . \implode(', ', $strays);
}

if ($includes === 0) {
    $problems[] = 'No view includes partials/photo_uploader.php any more.';
}

// ------------------------------------------------------------------------

if ($problems !== []) {
    \fwrite(\STDERR, "Photo capture problems:\n");
    foreach ($problems as $problem) {
        \fwrite(\STDERR, '  - ' . $problem . "\n");
    }
    exit(1);
}

\printf(
    "photo capture ok: roll and camera are separate inputs, both labelled, "
    . "across %d upload screen(s) of %d view(s) scanned\n",
    $includes,
    $scanned
);
