<?php

declare(strict_types=1);

namespace Carl\Qr;

/**
 * A QR symbol as inline SVG: one <svg>, one <path>, no request, no file, no
 * JavaScript (docs/QR-TAGS-SPEC.md Section 4.2).
 *
 * Inline is the whole point. An <img> pointing at a route is a second request
 * per tag on a page that lists twenty of them; a file on disk is a file on a
 * host whose writable directories are few (hosting Section 5.1). Inline SVG
 * is sharp at any zoom, costs the 150 KB client-shell budget nothing because
 * it is not in the shell, and adds no <script>, which is test-enforced
 * (PHASE-4-HANDOFF.md Section 3.2).
 *
 * ONE PATH, NOT 841 RECTS. Symbol::runs() has already merged each row into
 * horizontal runs, so a version 3 symbol is ~150 subpaths instead of 841
 * <rect> elements. The path uses relative moves and a closed box per run,
 * which is the most compact form that still renders identically everywhere.
 */
final class Svg
{
    /** ISO 18004 Section 6.3: four light modules on every side, always. */
    public const QUIET_ZONE = 4;

    /**
     * @param int    $modulePx  the on-screen size of one module; the symbol is
     *                          drawn in module units and scaled by the
     *                          viewBox, so this only sets the CSS size
     * @param string $label     the accessible name. A QR code is an image of
     *                          text, and a screen reader that meets one
     *                          should be told the code, not "QR code".
     */
    public static function render(Symbol $symbol, int $modulePx = 4, string $label = ''): string
    {
        $size = $symbol->size();
        $extent = $size + 2 * self::QUIET_ZONE;
        $pixels = $extent * \max(1, $modulePx);

        $path = '';
        foreach ($symbol->runs() as $run) {
            $x = $run['col'] + self::QUIET_ZONE;
            $y = $run['row'] + self::QUIET_ZONE;
            $path .= 'M' . $x . ' ' . $y . 'h' . $run['length'] . 'v1h-' . $run['length'] . 'z';
        }

        $title = $label === '' ? '' : '<title>' . \htmlspecialchars($label, \ENT_QUOTES, 'UTF-8') . '</title>';

        // The paper rectangle IS the quiet zone. Drawing it explicitly rather
        // than leaving the background to the page is what makes the symbol
        // scannable on a card, in a table row, and on paper without every
        // caller remembering to put white behind it.
        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg"'
            . ' viewBox="0 0 ' . $extent . ' ' . $extent . '"'
            . ' width="' . $pixels . '" height="' . $pixels . '"'
            . ' role="img"' . ($label === '' ? ' aria-hidden="true"' : '')
            . ' shape-rendering="crispEdges">'
            . $title
            . '<rect width="' . $extent . '" height="' . $extent . '" fill="var(--carl-qr-paper, #ffffff)"/>'
            . '<path d="' . $path . '" fill="var(--carl-qr-ink, #000000)"/>'
            . '</svg>';
    }
}
