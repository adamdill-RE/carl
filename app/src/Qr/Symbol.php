<?php

declare(strict_types=1);

namespace Carl\Qr;

/**
 * An encoded QR symbol: the module matrix, and the two shapes anything that
 * draws it actually wants.
 *
 * The matrix is square and row-major, `true` meaning a dark module. It does
 * NOT include the quiet zone -- ISO 18004 requires four light modules on
 * every side, and both renderers add it, because a quiet zone baked into the
 * matrix would be four rows of `false` that every penalty score, every test
 * fixture and every comparison against another implementation would have to
 * know about.
 *
 * runs() is the whole reason this is a class and not an array. A version 3
 * symbol is 841 modules; drawn one FPDF rectangle each that is 841 calls per
 * label and 20,184 on a sheet of 24. Merged along each row first it is
 * 150-250 per label (docs/QR-TAGS-SPEC.md Section 4.3), and the same merge
 * gives the inline SVG one `<path>` instead of 841 `<rect>`s.
 */
final class Symbol
{
    /** @param list<list<bool>> $modules */
    public function __construct(
        private array $modules,
        public readonly int $version,
        public readonly string $ecLevel,
        public readonly string $mode,
        public readonly int $mask,
    ) {
    }

    public function size(): int
    {
        return \count($this->modules);
    }

    public function dark(int $row, int $col): bool
    {
        return $this->modules[$row][$col] ?? false;
    }

    /** @return list<list<bool>> */
    public function modules(): array
    {
        return $this->modules;
    }

    /**
     * Every horizontal run of dark modules, merged.
     *
     * @return list<array{row:int,col:int,length:int}>
     */
    public function runs(): array
    {
        $runs = [];
        foreach ($this->modules as $row => $cells) {
            $start = null;
            $count = \count($cells);
            for ($col = 0; $col <= $count; $col++) {
                $dark = $col < $count && $cells[$col];
                if ($dark && $start === null) {
                    $start = $col;
                } elseif (!$dark && $start !== null) {
                    $runs[] = ['row' => $row, 'col' => $start, 'length' => $col - $start];
                    $start = null;
                }
            }
        }
        return $runs;
    }

    /**
     * The matrix as one string per row, '#' dark and '.' light.
     *
     * The fixture format (tests/fixtures/qr), because it is diffable: a
     * failure prints the two grids and the wrong module is visible, where two
     * base64 blobs or two JSON arrays of booleans are not.
     *
     * @return list<string>
     */
    public function toRows(): array
    {
        $out = [];
        foreach ($this->modules as $cells) {
            $line = '';
            foreach ($cells as $dark) {
                $line .= $dark ? '#' : '.';
            }
            $out[] = $line;
        }
        return $out;
    }
}
